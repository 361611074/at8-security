<?php
/**
 * Licence manager — AT8 License SDK 接入层（v2.1.0）
 *
 * 授权逻辑全部由统一授权中心（at8-license-server）负责；本文件保留
 * At8\Security\Pro\License 的公开 API（get / activate / refresh / release /
 * is_valid / status_label / sync_schedule …），class-admin、class-updater、
 * class-plugin 无需改动。
 *
 * 状态映射（SDK → 本插件历史语义）：
 *   active → active；grace → unreachable（宽限）；expired → expired；
 *   revoked / suspended / invalid → rejected；server_error → unreachable。
 *
 * @package At8\Security\Pro
 */

namespace At8\Security\Pro;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Licence manager (SDK adapter).
 */
class License {

	const CRON_HOOK = 'at8sec_pro_license_check';

	const STATUS_INACTIVE    = 'inactive';
	const STATUS_ACTIVE      = 'active';
	const STATUS_EXPIRED     = 'expired';
	const STATUS_REJECTED    = 'rejected';
	const STATUS_UNREACHABLE = 'unreachable';

	/** @var bool */
	private static $sdk_ready = false;

	/**
	 * Register hooks.
	 */
	public function __construct() {
		add_action( self::CRON_HOOK, array( __CLASS__, 'refresh' ) );
	}

	/** 初始化 SDK（幂等） */
	private static function ensure_sdk() {
		if ( self::$sdk_ready ) {
			return AT8_License_SDK::license();
		}
		self::$sdk_ready = true;

		if ( ! class_exists( 'AT8_License_SDK' ) ) {
			require_once AT8SEC_PRO_DIR . 'sdk/at8-license-sdk.php';
		}

		AT8_License_SDK::init( array(
			'server_url'  => 'https://www.at8.fun',
			'product'     => 'at8-security-pro',
			'plugin_file' => AT8SEC_PRO_FILE,
			'buy_url'     => 'https://www.at8.fun/store/',
			// 更新仍由本插件自带 Updater 负责（已改走 SDK 签名调用 + sha256 校验）
			'update'      => false,
		) );

		// 兼容旧的 cron 钩子名：转发到 SDK 的每日复查
		add_action( 'init', array( __CLASS__, 'sync_schedule' ), 21 );

		return AT8_License_SDK::license();
	}

	/** SDK 状态 → 本插件历史状态 */
	private static function map_status( $sdk_state ) {
		if ( empty( $sdk_state['key'] ) ) {
			return self::STATUS_INACTIVE;
		}
		switch ( isset( $sdk_state['status'] ) ? $sdk_state['status'] : '' ) {
			case 'active':
				return self::STATUS_ACTIVE;
			case 'grace':
				return self::STATUS_UNREACHABLE;
			case 'expired':
				return self::STATUS_EXPIRED;
			case 'revoked':
			case 'suspended':
			case 'invalid':
				return self::STATUS_REJECTED;
			default:
				return self::STATUS_UNREACHABLE;
		}
	}

	/**
	 * The stored record, filled out so callers never have to test for keys.
	 *
	 * @return array{key:string, status:string, plan:string, expires:int, last_ok:int, checked_at:int, message:string}
	 */
	public static function get() {
		$sdk   = self::ensure_sdk();
		$state = $sdk ? $sdk->state() : array();

		return array(
			'key'        => isset( $state['key'] ) ? (string) $state['key'] : '',
			'status'     => self::map_status( $state ),
			'plan'       => isset( $state['license_type'] ) ? (string) $state['license_type'] : '',
			'expires'    => isset( $state['expires_at'] ) ? (int) $state['expires_at'] : 0,
			'last_ok'    => isset( $state['last_ok'] ) ? (int) $state['last_ok'] : 0,
			'checked_at' => isset( $state['last_ok'] ) ? (int) $state['last_ok'] : 0,
			'message'    => isset( $state['message'] ) ? (string) $state['message'] : '',
		);
	}

	/**
	 * Was the add-on's licence ever confirmed by the server?
	 *
	 * @return bool
	 */
	public static function is_confirmed() {
		return self::get()['last_ok'] > 0;
	}

	/**
	 * Should the paid features run right now?
	 *
	 * @return bool
	 */
	public static function is_valid() {
		if ( dev_mode() ) {
			return true;
		}

		$sdk = self::ensure_sdk();
		return $sdk ? $sdk->is_valid() : false;
	}

	/**
	 * Seconds left before the protections stop for good.
	 *
	 * @param int $days Grace period length.
	 * @return int Zero when there is no grace, or none left.
	 */
	public static function grace_remaining( $days ) {
		$last_ok = self::get()['last_ok'];

		if ( $last_ok <= 0 ) {
			return 0;
		}

		return max( 0, ( ( (int) $last_ok + ( (int) $days * DAY_IN_SECONDS ) ) - time() ) );
	}

	/**
	 * Is this a licence with no end date?
	 *
	 * @return bool
	 */
	public static function is_lifetime() {
		$sdk = self::ensure_sdk();
		return $sdk ? $sdk->is_lifetime() : false;
	}

	/**
	 * Activate a key on this site.
	 *
	 * @param string $key Raw key from the form.
	 * @return true|\WP_Error
	 */
	public static function activate( $key ) {
		$key = trim( sanitize_text_field( (string) $key ) );

		if ( '' === $key ) {
			return new \WP_Error( 'at8sec_pro_empty_key', __( 'Enter a licence key first.', 'at8-security-pro' ) );
		}

		$sdk = self::ensure_sdk();
		if ( ! $sdk ) {
			return new \WP_Error( 'at8sec_pro_sdk', 'SDK 未初始化' );
		}

		$result = $sdk->activate( $key );
		if ( true !== $result ) {
			return new \WP_Error( 'at8sec_pro_activate', (string) $result );
		}

		return true;
	}

	/**
	 * Re-check the stored key. Used by the scheduled job and the "check now" button.
	 *
	 * @return true|\WP_Error
	 */
	public static function refresh() {
		$sdk = self::ensure_sdk();

		if ( ! $sdk || '' === $sdk->get_key() ) {
			return new \WP_Error( 'at8sec_pro_no_key', __( 'No licence key is stored.', 'at8-security-pro' ) );
		}

		$sdk->verify();
		$state = $sdk->state();

		if ( isset( $state['status'] ) && in_array( $state['status'], array( 'active', 'grace' ), true ) ) {
			return true;
		}

		return new \WP_Error(
			'at8sec_pro_refresh',
			isset( $state['message'] ) && '' !== $state['message'] ? $state['message'] : '验证未通过'
		);
	}

	/**
	 * Give this site up so the key can be used somewhere else.
	 *
	 * @return true|\WP_Error
	 */
	public static function release() {
		$sdk = self::ensure_sdk();

		if ( ! $sdk || '' === $sdk->get_key() ) {
			return new \WP_Error( 'at8sec_pro_no_key', __( 'No licence key is stored.', 'at8-security-pro' ) );
		}

		$result = $sdk->deactivate();
		if ( true !== $result ) {
			return new \WP_Error( 'at8sec_pro_release', (string) $result );
		}

		return true;
	}

	/**
	 * Forget the key entirely.
	 *
	 * @return void
	 */
	public static function forget() {
		delete_option( 'at8lic_sdk_at8-security-pro_state' );
	}

	/**
	 * Human readable status for the settings screen.
	 *
	 * @return string
	 */
	public static function status_label() {
		$sdk = self::ensure_sdk();
		return $sdk ? $sdk->status_label() : __( '未激活', 'at8-security-pro' );
	}

	/**
	 * Schedule the daily re-check (SDK 已内置调度，这里保留旧钩子兼容).
	 *
	 * @return void
	 */
	public static function sync_schedule() {
		$sdk = self::ensure_sdk();
		if ( $sdk && '' !== $sdk->get_key() && ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'twicedaily', self::CRON_HOOK );
		}
	}

	/**
	 * Drop the scheduled job.
	 *
	 * @return void
	 */
	public static function unschedule() {
		$ts = wp_next_scheduled( self::CRON_HOOK );
		while ( $ts ) {
			wp_unschedule_event( $ts, self::CRON_HOOK );
			$ts = wp_next_scheduled( self::CRON_HOOK );
		}
	}
}
