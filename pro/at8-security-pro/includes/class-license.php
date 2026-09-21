<?php
/**
 * Licence storage and validation.
 *
 * The rules this file implements — deliberately, because they decide whether a
 * site stays protected:
 *
 * 1. A key is only "valid" after the server has confirmed it at least once.
 * 2. Once confirmed, a later failed *check* does not switch protection off
 *    straight away. Losing the anti-bot challenge because the licence server
 *    had a bad afternoon would be worse than the licence problem.
 * 3. A key the server explicitly rejects gets a short grace period, then off.
 * 4. `AT8SEC_PRO_DEV_MODE` in wp-config.php bypasses all of it for staging and
 *    offline work. Anyone who can edit wp-config.php already owns the site, so
 *    this adds no attack surface.
 *
 * @package At8\Security\Pro
 */

namespace At8\Security\Pro;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Licence manager.
 */
class License {

	const CRON_HOOK = 'at8sec_pro_license_check';

	const STATUS_INACTIVE    = 'inactive';
	const STATUS_ACTIVE      = 'active';
	const STATUS_EXPIRED     = 'expired';
	const STATUS_REJECTED    = 'rejected';
	const STATUS_UNREACHABLE = 'unreachable';

	/**
	 * Register hooks.
	 */
	public function __construct() {
		// Priority 21: the free plugin schedules its own job at 20.
		add_action( 'init', array( __CLASS__, 'sync_schedule' ), 21 );
		add_action( self::CRON_HOOK, array( __CLASS__, 'refresh' ) );
	}

	/**
	 * The stored record, filled out so callers never have to test for keys.
	 *
	 * @return array{key:string, status:string, plan:string, expires:int, last_ok:int, checked_at:int, message:string}
	 */
	public static function get() {
		$stored = get_option( AT8SEC_PRO_LICENSE_OPTION, array() );

		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		$record = wp_parse_args(
			$stored,
			array(
				'key'        => '',
				'status'     => self::STATUS_INACTIVE,
				'plan'       => '',
				'expires'    => 0,
				'last_ok'    => 0,
				'checked_at' => 0,
				'message'    => '',
			)
		);

		$record['key']        = (string) $record['key'];
		$record['status']     = (string) $record['status'];
		$record['plan']       = (string) $record['plan'];
		$record['expires']    = (int) $record['expires'];
		$record['last_ok']    = (int) $record['last_ok'];
		$record['checked_at'] = (int) $record['checked_at'];
		$record['message']    = (string) $record['message'];

		if ( '' === $record['key'] ) {
			$record['status'] = self::STATUS_INACTIVE;
		}

		return $record;
	}

	/**
	 * Save a record, merging over whatever is stored.
	 *
	 * @param array<string, mixed> $values Values to merge.
	 * @return void
	 */
	private static function store( $values ) {
		$record = wp_parse_args( $values, self::get() );

		// The stored record is compared against its own previous value on each
		// request, so it must not be autoloaded with every option read.
		update_option( AT8SEC_PRO_LICENSE_OPTION, $record, false );
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

		$record = self::get();

		if ( '' === $record['key'] ) {
			return false;
		}

		if ( self::STATUS_ACTIVE === $record['status'] ) {
			return true;
		}

		if ( self::STATUS_REJECTED === $record['status'] || self::STATUS_EXPIRED === $record['status'] ) {
			return self::grace_remaining( AT8SEC_PRO_GRACE_DAYS_REJECTED ) > 0;
		}

		// Anything else — unreachable, never confirmed — is decided by how long
		// ago the server last said yes.
		return self::grace_remaining( AT8SEC_PRO_GRACE_DAYS ) > 0;
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

		return max( 0, ( (int) $last_ok + ( (int) $days * DAY_IN_SECONDS ) ) - time() );
	}

	/**
	 * Is this a licence with no end date?
	 *
	 * @return bool
	 */
	public static function is_lifetime() {
		$record = self::get();

		return self::STATUS_ACTIVE === $record['status'] && 0 === $record['expires'];
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

		$response = Api::activate( $key );

		if ( is_wp_error( $response ) ) {
			$previous = self::get();

			/*
			 * Remember the attempt so the screen can say what went wrong. An
			 * unconfirmed key is not a licence, so last_ok is cleared — but only
			 * for a key that was not already confirmed. Re-checking the same key
			 * while the server is having a bad afternoon must not throw away a
			 * licence the server itself granted earlier.
			 */
			self::store(
				array(
					'key'        => $key,
					'status'     => self::STATUS_UNREACHABLE,
					'checked_at' => time(),
					'message'    => $response->get_error_message(),
					'last_ok'    => $key === $previous['key'] ? $previous['last_ok'] : 0,
				)
			);

			return $response;
		}

		return self::apply( $key, $response );
	}

	/**
	 * Re-check the stored key. Used by the scheduled job and the "check now" button.
	 *
	 * @return true|\WP_Error
	 */
	public static function refresh() {
		$record = self::get();

		if ( '' === $record['key'] ) {
			return new \WP_Error( 'at8sec_pro_no_key', __( 'No licence key is stored.', 'at8-security-pro' ) );
		}

		$response = Api::validate( $record['key'] );

		if ( is_wp_error( $response ) ) {
			self::store(
				array(
					'status'     => self::STATUS_UNREACHABLE,
					'checked_at' => time(),
					'message'    => $response->get_error_message(),
				)
			);

			return $response;
		}

		return self::apply( $record['key'], $response );
	}

	/**
	 * Give this site up so the key can be used somewhere else.
	 *
	 * @return true|\WP_Error
	 */
	public static function release() {
		$record = self::get();

		if ( '' === $record['key'] ) {
			return new \WP_Error( 'at8sec_pro_no_key', __( 'No licence key is stored.', 'at8-security-pro' ) );
		}

		$response = Api::deactivate( $record['key'] );

		// Even if the server cannot be reached, this site stops using the key.
		self::store(
			array(
				'status'     => self::STATUS_INACTIVE,
				'last_ok'    => 0,
				'checked_at' => time(),
				'message'    => is_wp_error( $response ) ? $response->get_error_message() : '',
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return true;
	}

	/**
	 * Forget the key entirely.
	 *
	 * @return void
	 */
	public static function forget() {
		delete_option( AT8SEC_PRO_LICENSE_OPTION );
	}

	/**
	 * Translate a server answer into stored state.
	 *
	 * @param string               $key      Key the answer belongs to.
	 * @param array<string, mixed> $response Decoded response.
	 * @return true|\WP_Error
	 */
	private static function apply( $key, $response ) {
		$valid   = ! empty( $response['valid'] );
		$expires = isset( $response['expires'] ) ? (int) $response['expires'] : 0;
		$message = isset( $response['message'] ) ? sanitize_text_field( (string) $response['message'] ) : '';
		$plan    = isset( $response['plan'] ) ? sanitize_text_field( (string) $response['plan'] ) : '';

		if ( $valid && $expires > 0 && $expires < time() ) {
			// The server said yes but the expiry has already passed; treat the
			// date as authoritative rather than trusting the flag alone.
			$valid = false;
		}

		if ( $valid ) {
			self::store(
				array(
					'key'        => $key,
					'status'     => self::STATUS_ACTIVE,
					'plan'       => $plan,
					'expires'    => $expires,
					'last_ok'    => time(),
					'checked_at' => time(),
					'message'    => $message,
				)
			);

			return true;
		}

		$status = isset( $response['status'] ) ? sanitize_key( (string) $response['status'] ) : '';

		if ( ! in_array( $status, array( self::STATUS_EXPIRED, self::STATUS_REJECTED, self::STATUS_INACTIVE ), true ) ) {
			$status = $expires > 0 ? self::STATUS_EXPIRED : self::STATUS_REJECTED;
		}

		self::store(
			array(
				'key'        => $key,
				'status'     => $status,
				'plan'       => $plan,
				'expires'    => $expires,
				'checked_at' => time(),
				'message'    => $message,
			)
		);

		return new \WP_Error(
			'at8sec_pro_' . $status,
			'' !== $message
				? $message
				: __( 'The licence server did not accept this key for this site.', 'at8-security-pro' )
		);
	}

	/**
	 * Human-readable status.
	 *
	 * @return string
	 */
	public static function status_label() {
		if ( dev_mode() ) {
			return __( 'Development mode — no licence is checked on this install.', 'at8-security-pro' );
		}

		switch ( self::get()['status'] ) {
			case self::STATUS_ACTIVE:
				return self::is_lifetime()
					? __( 'Active — lifetime licence.', 'at8-security-pro' )
					: __( 'Active.', 'at8-security-pro' );

			case self::STATUS_EXPIRED:
				return __( 'Expired.', 'at8-security-pro' );

			case self::STATUS_REJECTED:
				return __( 'Not valid for this site.', 'at8-security-pro' );

			case self::STATUS_UNREACHABLE:
				return __( 'Could not reach the licence server on the last check.', 'at8-security-pro' );

			case self::STATUS_INACTIVE:
			default:
				return __( 'No licence key stored.', 'at8-security-pro' );
		}
	}

	/**
	 * Schedule — or drop — the twice-daily check.
	 *
	 * @return void
	 */
	public static function sync_schedule() {
		$scheduled = wp_next_scheduled( self::CRON_HOOK );
		$has_key   = '' !== self::get()['key'] && ! dev_mode();

		if ( $has_key ) {
			if ( ! $scheduled ) {
				wp_schedule_event( time() + HOUR_IN_SECONDS, 'twicedaily', self::CRON_HOOK );
			}

			return;
		}

		while ( $scheduled ) {
			wp_unschedule_event( $scheduled, self::CRON_HOOK );
			$scheduled = wp_next_scheduled( self::CRON_HOOK );
		}
	}

	/**
	 * Remove the scheduled check.
	 *
	 * @return void
	 */
	public static function unschedule() {
		$scheduled = wp_next_scheduled( self::CRON_HOOK );

		while ( $scheduled ) {
			wp_unschedule_event( $scheduled, self::CRON_HOOK );
			$scheduled = wp_next_scheduled( self::CRON_HOOK );
		}
	}
}
