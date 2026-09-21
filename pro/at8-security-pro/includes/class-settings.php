<?php
/**
 * Add-on settings: schema, storage and sanitisation.
 *
 * Same shape as the free plugin's registry, kept separate so the two plugins
 * never write to the same option. The free plugin replaces its option wholesale
 * on save, so sharing one row would mean either plugin wiping the other's keys.
 *
 * @package At8\Security\Pro
 */

namespace At8\Security\Pro;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Declarative settings registry.
 */
class Settings {

	const BOOL   = 'bool';
	const INT    = 'int';
	const TEXT   = 'text';
	const SECRET = 'secret';
	const ROLES  = 'roles';

	/**
	 * Full option schema.
	 *
	 * @return array<string, array{type:string, default:mixed}>
	 */
	public static function schema() {
		return array(
			/* Hosted anti-bot challenge */
			'turnstile_enabled'    => array(
				'type'    => self::BOOL,
				'default' => 0,
			),
			'turnstile_site_key'   => array(
				'type'    => self::TEXT,
				'default' => '',
			),
			'turnstile_secret_key' => array(
				'type'    => self::SECRET,
				'default' => '',
			),

			/* Two-factor authentication */
			'two_factor_enabled'   => array(
				'type'    => self::BOOL,
				'default' => 0,
			),
			'two_factor_roles'     => array(
				'type'    => self::ROLES,
				'default' => array( 'administrator' ),
			),
			'two_factor_remember'  => array(
				'type'    => self::INT,
				'default' => 30,
				'min'     => 0,
				'max'     => 365,
			),

			/* CDN address detection */
			'cdn_auto'             => array(
				'type'    => self::BOOL,
				'default' => 0,
			),

			/* Inactive-account review */
			'idle_days'            => array(
				'type'    => self::INT,
				'default' => 90,
				'min'     => 7,
				'max'     => 3650,
			),
		);
	}

	/**
	 * Default value for every option.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults() {
		$out = array();

		foreach ( self::schema() as $key => $spec ) {
			$out[ $key ] = $spec['default'];
		}

		return $out;
	}

	/**
	 * All current settings, merged over the defaults.
	 *
	 * @return array<string, mixed>
	 */
	public static function all() {
		$saved = get_option( AT8SEC_PRO_OPTION, array() );

		if ( ! is_array( $saved ) ) {
			$saved = array();
		}

		return wp_parse_args( $saved, self::defaults() );
	}

	/**
	 * Read a single setting.
	 *
	 * @param string $key      Option key.
	 * @param mixed  $fallback Value returned when the key is unknown.
	 * @return mixed
	 */
	public static function get( $key, $fallback = null ) {
		$all = self::all();

		return array_key_exists( $key, $all ) ? $all[ $key ] : $fallback;
	}

	/**
	 * Is a boolean option switched on?
	 *
	 * @param string $key Option key.
	 * @return bool
	 */
	public static function on( $key ) {
		return (bool) self::get( $key, 0 );
	}

	/**
	 * Sanitise the submitted settings.
	 *
	 * @param mixed $input Raw value from options.php.
	 * @return array<string, mixed>
	 */
	public static function sanitize( $input ) {
		$schema = self::schema();
		$out    = array();

		if ( ! is_array( $input ) ) {
			$input = array();
		}

		foreach ( $schema as $key => $spec ) {
			if ( ! array_key_exists( $key, $input ) ) {
				// An unchecked checkbox is simply absent, so a missing boolean
				// means off. Every other type always renders, so a missing one
				// keeps its default rather than being destroyed.
				$out[ $key ] = self::BOOL === $spec['type'] ? 0 : $spec['default'];
				continue;
			}

			$value = $input[ $key ];

			switch ( $spec['type'] ) {
				case self::BOOL:
					$out[ $key ] = empty( $value ) ? 0 : 1;
					break;

				case self::INT:
					$value = (int) $value;
					if ( isset( $spec['min'] ) ) {
						$value = max( (int) $spec['min'], $value );
					}
					if ( isset( $spec['max'] ) ) {
						$value = min( (int) $spec['max'], $value );
					}
					$out[ $key ] = $value;
					break;

				case self::ROLES:
					$out[ $key ] = self::sanitize_roles( $value );
					break;

				case self::SECRET:
					$out[ $key ] = self::sanitize_secret( $key, $value );
					break;

				case self::TEXT:
				default:
					$out[ $key ] = sanitize_text_field( (string) $value );
					break;
			}
		}

		return $out;
	}

	/**
	 * Store a secret, keeping the existing one when the field is left empty.
	 *
	 * The form never prints a stored secret back into the page, so an empty
	 * field means "not changing it" rather than "delete it" — otherwise every
	 * save of an unrelated setting would wipe the key.
	 *
	 * @param string $key   Option key.
	 * @param mixed  $value Submitted value.
	 * @return string
	 */
	private static function sanitize_secret( $key, $value ) {
		$value = trim( sanitize_text_field( (string) $value ) );

		if ( '' !== $value ) {
			return $value;
		}

		$stored = self::get( $key, '' );

		return is_string( $stored ) ? $stored : '';
	}

	/**
	 * Keep only role names that actually exist.
	 *
	 * A stale role name would silently exclude the wrong people, or nobody at
	 * all, so the list is checked against the site's own roles.
	 *
	 * @param mixed $value Submitted value.
	 * @return string[]
	 */
	private static function sanitize_roles( $value ) {
		$known = array_keys( (array) wp_roles()->get_names() );
		$out   = array();

		foreach ( (array) $value as $role ) {
			$role = sanitize_key( (string) $role );

			if ( in_array( $role, $known, true ) ) {
				$out[] = $role;
			}
		}

		return array_values( array_unique( $out ) );
	}

	/**
	 * Store a one-off admin message, shown on the add-on's own screen.
	 *
	 * @param string $message Message text.
	 * @param string $type    notice-success or notice-error.
	 * @return void
	 */
	public static function queue_notice( $message, $type = 'success' ) {
		set_transient(
			'at8sec_pro_notice_' . get_current_user_id(),
			array(
				'message' => (string) $message,
				'type'    => 'error' === $type ? 'error' : 'success',
			),
			60
		);
	}

	/**
	 * Pull and clear the pending admin message.
	 *
	 * @return array{message:string, type:string}
	 */
	public static function take_notice() {
		$key   = 'at8sec_pro_notice_' . get_current_user_id();
		$value = get_transient( $key );

		if ( ! is_array( $value ) || empty( $value['message'] ) ) {
			return array(
				'message' => '',
				'type'    => 'success',
			);
		}

		delete_transient( $key );

		return array(
			'message' => (string) $value['message'],
			'type'    => 'error' === $value['type'] ? 'error' : 'success',
		);
	}
}
