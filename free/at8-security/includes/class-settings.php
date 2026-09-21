<?php
/**
 * Settings schema, storage and sanitisation.
 *
 * @package At8\Security
 */

namespace At8\Security;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Declarative settings registry.
 *
 * Every option is described once, with a type and a default. Sanitising then
 * follows the schema instead of guessing from the submitted value, which is
 * what made the previous generation of this plugin silently reset string and
 * number fields to 0 whenever a key was missing from $_POST.
 */
class Settings {

	const BOOL = 'bool';
	const INT  = 'int';
	const TEXT = 'text';
	const EMAIL = 'email';
	const SLUG  = 'slug';
	const LIST  = 'list';
	const CSP   = 'csp';

	/**
	 * Full option schema.
	 *
	 * @return array<string, array{type:string, default:mixed}>
	 */
	public static function schema() {
		return array(
			/* 1. Hardening */
			'hide_wp_version'        => array(
				'type'    => self::BOOL,
				'default' => 1,
			),
			'disable_xmlrpc'         => array(
				'type'    => self::BOOL,
				'default' => 1,
			),
			'disable_rest_user_enum' => array(
				'type'    => self::BOOL,
				'default' => 1,
			),
			'uniform_login_errors'   => array(
				'type'    => self::BOOL,
				'default' => 1,
			),
			'disable_file_editor'    => array(
				'type'    => self::BOOL,
				'default' => 1,
			),
			'remove_rsd_wlw'         => array(
				'type'    => self::BOOL,
				'default' => 1,
			),
			'disable_author_enum'    => array(
				'type'    => self::BOOL,
				'default' => 1,
			),
			'disable_dir_listing'    => array(
				'type'    => self::BOOL,
				'default' => 0,
			),
			'uploads_disable_php'    => array(
				'type'    => self::BOOL,
				'default' => 0,
			),

			/* 2. Security headers */
			'security_headers'       => array(
				'type'    => self::BOOL,
				'default' => 1,
			),
			'enable_csp'             => array(
				'type'    => self::BOOL,
				'default' => 1,
			),
			'csp_report_only'        => array(
				'type'    => self::BOOL,
				'default' => 1,
			),
			'csp_policy'             => array(
				'type'    => self::CSP,
				'default' => "default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data: https:; font-src 'self' data:; connect-src 'self'; frame-ancestors 'self'; base-uri 'self'; form-action 'self'",
			),

			/* 3. HTTPS */
			'force_https'            => array(
				'type'    => self::BOOL,
				'default' => 1,
			),
			'hsts'                   => array(
				'type'    => self::BOOL,
				'default' => 0,
			),

			/* 4. Login protection */
			'login_throttle'         => array(
				'type'    => self::BOOL,
				'default' => 1,
			),
			'login_max_retries'      => array(
				'type'    => self::INT,
				'default' => 5,
				'min'     => 1,
				'max'     => 100,
			),
			'login_lockout_minutes'  => array(
				'type'    => self::INT,
				'default' => 15,
				'min'     => 1,
				'max'     => 1440,
			),
			'change_login_url'       => array(
				'type'    => self::BOOL,
				'default' => 0,
			),
			'login_slug'             => array(
				'type'    => self::SLUG,
				'default' => '',
			),
			'login_email_alert'      => array(
				'type'    => self::BOOL,
				'default' => 1,
			),
			'login_alert_email'      => array(
				'type'    => self::EMAIL,
				'default' => '',
			),
			'honeypot'               => array(
				'type'    => self::BOOL,
				'default' => 1,
			),
			'login_captcha'          => array(
				'type'    => self::BOOL,
				'default' => 1,
			),

			/* 5. Password policy */
			'pwd_policy'             => array(
				'type'    => self::BOOL,
				'default' => 1,
			),
			'pwd_min_length'         => array(
				'type'    => self::INT,
				'default' => 12,
				'min'     => 8,
				'max'     => 128,
			),
			'weak_pwd_scan'          => array(
				'type'    => self::BOOL,
				'default' => 1,
			),

			/* 6. Firewall and rate limiting */
			'waf_basic'              => array(
				'type'    => self::BOOL,
				'default' => 1,
			),
			'waf_sql'                => array(
				'type'    => self::BOOL,
				'default' => 1,
			),
			'waf_xss'                => array(
				'type'    => self::BOOL,
				'default' => 1,
			),
			'waf_traversal'          => array(
				'type'    => self::BOOL,
				'default' => 1,
			),
			'waf_rce'                => array(
				'type'    => self::BOOL,
				'default' => 1,
			),
			'ip_blacklist'           => array(
				'type'    => self::LIST,
				'default' => '',
			),
			'ip_whitelist'           => array(
				'type'    => self::LIST,
				'default' => '',
			),
			'trusted_proxies'        => array(
				'type'    => self::LIST,
				'default' => '',
			),
			'rate_limit_global'      => array(
				'type'    => self::BOOL,
				'default' => 1,
			),
			'rate_limit_max'         => array(
				'type'    => self::INT,
				'default' => 60,
				'min'     => 5,
				'max'     => 5000,
			),
			'rate_limit_window'      => array(
				'type'    => self::INT,
				'default' => 60,
				'min'     => 1,
				'max'     => 3600,
			),
			'block_bad_ua'           => array(
				'type'    => self::BOOL,
				'default' => 1,
			),
			'error_404_throttle'     => array(
				'type'    => self::BOOL,
				'default' => 1,
			),
			'error_404_threshold'    => array(
				'type'    => self::INT,
				'default' => 20,
				'min'     => 5,
				'max'     => 1000,
			),

			/* 7. Comments */
			'comment_spam'           => array(
				'type'    => self::BOOL,
				'default' => 1,
			),
			'disable_comments'       => array(
				'type'    => self::BOOL,
				'default' => 0,
			),

			/* 8. Uploads */
			'upload_restrict'        => array(
				'type'    => self::BOOL,
				'default' => 1,
			),

			/* 9. REST API */
			'rest_lockdown'          => array(
				'type'    => self::BOOL,
				'default' => 0,
			),

			/* 10. Audit log */
			'audit_log'              => array(
				'type'    => self::BOOL,
				'default' => 1,
			),
			'audit_log_days'         => array(
				'type'    => self::INT,
				'default' => 30,
				'min'     => 1,
				'max'     => 365,
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
		$saved = get_option( AT8SEC_OPTION, array() );
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
			$type = $spec['type'];

			/*
			 * An unchecked checkbox is simply absent from the request, so a
			 * missing boolean means "off". Every other type is always rendered
			 * in the form; if it is missing anyway (an add-on removing a field,
			 * a partial POST) we keep the previous/default value instead of
			 * destroying it.
			 */
			if ( ! array_key_exists( $key, $input ) ) {
				$out[ $key ] = self::BOOL === $type ? 0 : $spec['default'];
				continue;
			}

			$value = $input[ $key ];

			switch ( $type ) {
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

				case self::EMAIL:
					$out[ $key ] = sanitize_email( (string) $value );
					break;

				case self::SLUG:
					$out[ $key ] = self::sanitize_slug( $value );
					break;

				case self::LIST:
					$out[ $key ] = implode( "\n", parse_ip_list( $value ) );
					break;

				case self::CSP:
					$out[ $key ] = self::sanitize_csp( $value );
					break;

				case self::TEXT:
				default:
					$out[ $key ] = sanitize_text_field( (string) $value );
					break;
			}
		}

		$out = self::validate_login_slug( $out, $input );

		return $out;
	}

	/**
	 * Reduce a value to a safe URL path segment.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	private static function sanitize_slug( $value ) {
		$slug = strtolower( trim( (string) $value ) );
		$slug = preg_replace( '#[^a-z0-9_-]#', '', $slug );

		return trim( (string) $slug, '-' );
	}

	/**
	 * Keep a CSP string on one line and free of header-injection characters.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	private static function sanitize_csp( $value ) {
		$policy = (string) $value;
		$policy = preg_replace( '/[\r\n]+/', ' ', $policy );
		$policy = preg_replace( '/[^A-Za-z0-9 \-:\/\.\*\'_;\?&=,]/', '', (string) $policy );
		$policy = preg_replace( '/\s+/', ' ', (string) $policy );

		return trim( (string) $policy );
	}

	/**
	 * Refuse a custom login path that would break the site, and confirm the
	 * exact address when it is accepted.
	 *
	 * A slug colliding with a real page, or with a reserved system path, would
	 * make either the login page or that page unreachable — so the option is
	 * switched back off and the admin is told why. When the path is accepted,
	 * the admin is told the address to use, because saving may normalise what
	 * was typed (uppercase lowered, unsupported characters removed) and the
	 * typed form would otherwise answer 404.
	 *
	 * @param array<string, mixed> $out       Sanitised settings.
	 * @param array<string, mixed> $raw_input Unsanitised settings as submitted.
	 * @return array<string, mixed>
	 */
	private static function validate_login_slug( $out, $raw_input = array() ) {
		if ( empty( $out['change_login_url'] ) ) {
			return $out;
		}

		$raw      = isset( $raw_input['login_slug'] ) ? (string) $raw_input['login_slug'] : '';
		$slug     = isset( $out['login_slug'] ) ? (string) $out['login_slug'] : '';
		$reserved = array(
			'wp-login',
			'wp-admin',
			'wp-content',
			'wp-includes',
			'wp-json',
			'admin',
			'login',
			'xmlrpc',
			'feed',
			'embed',
		);

		if ( '' === $slug || in_array( $slug, $reserved, true ) ) {
			$out['change_login_url'] = 0;
			self::queue_notice(
				__( 'The login page stayed at wp-login.php: the path you entered is empty, or it collides with a reserved WordPress path (such as admin or login). Please choose another one, for example my-login.', 'at8-security' )
			);

			return $out;
		}

		$collision = get_posts(
			array(
				'name'             => $slug,
				'post_type'        => array( 'post', 'page' ),
				'post_status'      => 'publish',
				'fields'           => 'ids',
				'numberposts'      => 1,
				'no_found_rows'    => true,
				'suppress_filters' => false,
			)
		);

		if ( ! empty( $collision ) ) {
			$out['change_login_url'] = 0;
			self::queue_notice(
				sprintf(
					/* translators: %s: the login path the user typed. */
					__( 'The custom login path was not enabled: "%s" is already used by an existing post or page. Please choose another one.', 'at8-security' ),
					$slug
				)
			);

			return $out;
		}

		$url = trailingslashit( home_url() ) . $slug;

		/*
		 * The typed form may only match the saved one after normalisation;
		 * say so explicitly, because the typed variant is not the address.
		 */
		$typed = rawurldecode( trim( $raw, "/ \t\n\r" ) );

		if ( '' !== $typed && strtolower( $typed ) !== strtolower( $slug ) ) {
			self::queue_notice(
				sprintf(
					/* translators: 1: the saved path, 2: the full login address that is now active. */
					__( 'The path you typed was adjusted to "%1$s" while saving. Your login address is now: %2$s — open it and bookmark it.', 'at8-security' ),
					$slug,
					$url
				)
			);

			return $out;
		}

		self::queue_notice(
			sprintf(
				/* translators: %s: the full login address that is now active. */
				__( 'Your login address is now: %s — the default wp-login.php no longer works. Open the address and bookmark it.', 'at8-security' ),
				$url
			)
		);

		return $out;
	}

	/**
	 * Store a one-off admin message.
	 *
	 * @param string $message Message text.
	 * @return void
	 */
	public static function queue_notice( $message ) {
		set_transient( 'at8sec_notice_' . get_current_user_id(), $message, 60 );
	}

	/**
	 * Pull and clear the pending admin message.
	 *
	 * @return string
	 */
	public static function take_notice() {
		$key     = 'at8sec_notice_' . get_current_user_id();
		$message = get_transient( $key );

		if ( $message ) {
			delete_transient( $key );

			return (string) $message;
		}

		return '';
	}
}
