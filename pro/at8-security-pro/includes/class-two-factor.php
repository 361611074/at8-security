<?php
/**
 * Two-factor authentication: a time-based code, plus recovery codes.
 *
 * Four decisions shape this file, and they are all about not locking an owner
 * out of their own site:
 *
 * 1. The code is requested *after* the password has been accepted, never
 *    before. Asking first would answer the question "does this username exist
 *    and does it use two-factor?" to anyone who asks.
 * 2. The role list decides who is asked to set it up — it does not, on its
 *    own, keep anyone out. A user whose role is listed but who never finished
 *    enrolment still signs in with their password, and sees a reminder. If the
 *    list were enforced as a gate, enabling it on a site where nobody had
 *    enrolled yet would lock every administrator out at once.
 * 3. "Remember this device" is a signed value rather than a stored row, and
 *    its signature is keyed with the account's own secret. Resetting the
 *    account's two-factor therefore invalidates every remembered device
 *    immediately, with nothing to clean up.
 * 4. Nothing here touches application passwords or WP-CLI. Those carry a
 *    credential of their own and are how an owner recovers access if the
 *    authenticator app is lost.
 *
 * @package At8\Security\Pro
 */

namespace At8\Security\Pro;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * TOTP two-factor authentication.
 */
class Two_Factor {

	const META_SECRET    = 'at8sec_pro_totp_secret';
	const META_ENABLED   = 'at8sec_pro_totp_enabled';
	const META_CODES     = 'at8sec_pro_recovery_codes';
	const META_PENDING   = 'at8sec_pro_totp_pending';
	const COOKIE_PREFIX  = 'at8sec_pro_2fa_';

	const FIELD_CODE     = 'at8sec_pro_code';
	const FIELD_REMEMBER = 'at8sec_pro_remember';
	const FIELD_ACTION   = 'at8sec_pro_2fa_action';

	/** How many recovery codes are issued at a time. */
	const RECOVERY_COUNT = 8;

	/** Seconds in one time step. */
	const STEP = 30;

	/**
	 * Register hooks.
	 */
	public function __construct() {
		add_filter( 'authenticate', array( $this, 'require_code' ), 50, 3 );
		add_action( 'login_form', array( $this, 'render_login_field' ) );
		add_action( 'login_enqueue_scripts', __NAMESPACE__ . '\\enqueue_front_style' );

		add_action( 'show_user_profile', array( $this, 'render_profile_section' ) );
		add_action( 'admin_init', array( $this, 'handle_profile_action' ) );
		add_action( 'admin_notices', array( $this, 'enrolment_notice' ) );
	}

	/* ---------------------------------------------------------------------
	 * Enrolment state
	 * ------------------------------------------------------------------ */

	/**
	 * Does this account have a confirmed secret?
	 *
	 * @param int $user_id User ID.
	 * @return bool
	 */
	public static function is_enrolled( $user_id ) {
		return '' !== (string) get_user_meta( $user_id, self::META_SECRET, true )
			&& '1' === (string) get_user_meta( $user_id, self::META_ENABLED, true );
	}

	/**
	 * Is the site-wide feature switched on?
	 *
	 * @return bool
	 */
	public static function active() {
		return Settings::on( 'two_factor_enabled' );
	}

	/**
	 * Roles that are asked to use it.
	 *
	 * @return string[]
	 */
	public static function roles() {
		$roles = Settings::get( 'two_factor_roles', array( 'administrator' ) );

		return array_values( array_filter( array_map( 'strval', (array) $roles ) ) );
	}

	/**
	 * Does this account belong to one of those roles?
	 *
	 * @param \WP_User $user User.
	 * @return bool
	 */
	public static function role_applies( $user ) {
		if ( ! $user instanceof \WP_User ) {
			return false;
		}

		return (bool) array_intersect( self::roles(), (array) $user->roles );
	}

	/**
	 * Must this sign-in be completed with a code?
	 *
	 * Only ever true for an account that finished enrolment.
	 *
	 * @param \WP_User $user User.
	 * @return bool
	 */
	public static function applies_to( $user ) {
		return self::active()
			&& self::role_applies( $user )
			&& self::is_enrolled( $user->ID );
	}

	/**
	 * How long a remembered device lasts, in days.
	 *
	 * @return int
	 */
	public static function remember_days() {
		return (int) Settings::get( 'two_factor_remember', 30 );
	}

	/* ---------------------------------------------------------------------
	 * Sign-in
	 * ------------------------------------------------------------------ */

	/**
	 * Ask for the code once the password has been accepted.
	 *
	 * Position matters twice over. This runs at priority 50, after the password
	 * checks and after the free plugin's captcha, so the answer to "is this
	 * code needed?" is only computed for somebody who already passed those.
	 * And it runs before WordPress decides the sign-in succeeded, so a rejection
	 * here never leaves a session behind.
	 *
	 * @param \WP_User|\WP_Error|null $user     Result so far.
	 * @param string                  $username Submitted username.
	 * @param string                  $password Submitted password.
	 * @return \WP_User|\WP_Error|null
	 */
	public function require_code( $user, $username = '', $password = '' ) {
		unset( $username, $password );

		// No accepted password yet, or somebody else already rejected it.
		if ( ! $user instanceof \WP_User ) {
			return $user;
		}

		if ( ! self::applies_to( $user ) ) {
			return $user;
		}

		if ( self::is_remembered_device( $user->ID ) ) {
			return $user;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- part of the sign-in form; the password has already been accepted at this point.
		$code     = isset( $_POST[ self::FIELD_CODE ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::FIELD_CODE ] ) ) : '';
		$remember = ! empty( $_POST[ self::FIELD_REMEMBER ] );
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( '' === $code ) {
			return new \WP_Error(
				'at8sec_pro_2fa_required',
				__( 'This account uses two-factor authentication. Enter the six-digit code from your authenticator app to finish signing in.', 'at8-security-pro' )
			);
		}

		if ( self::verify_code_for_user( $user->ID, $code ) ) {
			if ( $remember ) {
				self::remember_device( $user->ID );
			}

			return $user;
		}

		if ( self::consume_recovery_code( $user->ID, $code ) ) {
			/**
			 * Fires when a recovery code is used to complete a sign-in.
			 *
			 * Worth acting on: a recovery code being used usually means the
			 * authenticator was lost, and one is now spent.
			 *
			 * @param int    $user_id User ID.
			 * @param string $ip      Address the sign-in came from.
			 */
			do_action( 'at8sec_pro_recovery_code_used', $user->ID, \At8\Security\get_client_ip() );

			if ( $remember ) {
				self::remember_device( $user->ID );
			}

			return $user;
		}

		return new \WP_Error(
			'at8sec_pro_2fa_invalid',
			__( 'That code was not accepted. Check the clock on your device, then try the current code — or use one of your recovery codes.', 'at8-security-pro' )
		);
	}

	/**
	 * Print the code field on the login form.
	 *
	 * Shown only once a sign-in has already been attempted. The overwhelming
	 * majority of a site's visitors never see a login form at all, and of the
	 * few who do, only those with two-factor enabled need this — so the field
	 * appears at the moment it is relevant rather than sitting on the form
	 * looking like a requirement for everyone.
	 *
	 * @return void
	 */
	public function render_login_field() {
		if ( ! self::active() ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- deciding what to draw on the sign-in form.
		if ( empty( $_POST['log'] ) ) {
			return;
		}
		?>
		<p class="at8sec-pro-2fa">
			<label for="<?php echo esc_attr( self::FIELD_CODE ); ?>">
				<?php esc_html_e( 'Authenticator code', 'at8-security-pro' ); ?><br />
				<input type="text" name="<?php echo esc_attr( self::FIELD_CODE ); ?>"
					id="<?php echo esc_attr( self::FIELD_CODE ); ?>" class="input"
					inputmode="numeric" autocomplete="one-time-code" />
			</label>
			<span class="at8sec-pro-2fa-hint">
				<?php esc_html_e( 'Six digits, or one of your recovery codes. Leave empty if two-factor is not set up on your account.', 'at8-security-pro' ); ?>
			</span>
		</p>
		<?php
		if ( self::remember_days() > 0 ) :
			?>
			<p class="at8sec-pro-2fa-remember">
				<label>
					<input type="checkbox" name="<?php echo esc_attr( self::FIELD_REMEMBER ); ?>" value="1" />
					<?php
					printf(
						/* translators: %d: number of days a device stays remembered. */
						esc_html__( 'Remember this device for %d days', 'at8-security-pro' ),
						(int) self::remember_days()
					);
					?>
				</label>
			</p>
			<?php
		endif;
	}

	/* ---------------------------------------------------------------------
	 * Time-based codes
	 * ------------------------------------------------------------------ */

	/**
	 * Create a new secret in the form authenticator apps expect.
	 *
	 * Twenty bytes of entropy, Base32 encoded, grouped for typing.
	 *
	 * @param int $bytes Entropy, in bytes.
	 * @return string
	 */
	public static function generate_secret( $bytes = 20 ) {
		return self::base32_encode( random_bytes( max( 10, (int) $bytes ) ) );
	}

	/**
	 * Group a secret in fours, the way it is printed for manual entry.
	 *
	 * @param string $secret Base32 secret.
	 * @return string
	 */
	public static function format_secret( $secret ) {
		$secret = (string) $secret;

		return trim( chunk_split( $secret, 4, ' ' ) );
	}

	/**
	 * The otpauth:// URI an authenticator app scans or accepts.
	 *
	 * @param int    $user_id User ID.
	 * @param string $secret  Base32 secret.
	 * @return string
	 */
	public static function provisioning_uri( $user_id, $secret ) {
		$user = get_userdata( $user_id );
		$name = $user instanceof \WP_User ? $user->user_login : (string) $user_id;
		$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );

		return (string) add_query_arg(
			array(
				'secret'    => rawurlencode( (string) $secret ),
				'issuer'    => rawurlencode( $host ),
				'algorithm' => 'SHA1',
				'digits'    => '6',
				'period'    => (string) self::STEP,
			),
			'otpauth://totp/' . rawurlencode( $host . ':' . $name )
		);
	}

	/**
	 * Compute the code for one time slice.
	 *
	 * RFC 6238 over RFC 4226: HMAC-SHA1 of the big-endian slice counter, then
	 * the standard dynamic truncation into six digits.
	 *
	 * @param string $secret Base32 secret.
	 * @param int    $slice  Time step number.
	 * @return string Six digits, or an empty string if the secret is unusable.
	 */
	public static function code_at( $secret, $slice ) {
		$key = self::base32_decode( $secret );

		if ( '' === $key ) {
			return '';
		}

		// 64-bit big-endian counter: high word, low word.
		$counter = pack( 'N*', 0, (int) $slice );
		$hash    = hash_hmac( 'sha1', $counter, $key, true );

		if ( ! is_string( $hash ) || strlen( $hash ) < 20 ) {
			return '';
		}

		$offset = ord( $hash[19] ) & 0x0F;

		$value = ( ( ord( $hash[ $offset ] ) & 0x7F ) << 24 )
			| ( ( ord( $hash[ $offset + 1 ] ) & 0xFF ) << 16 )
			| ( ( ord( $hash[ $offset + 2 ] ) & 0xFF ) << 8 )
			| ( ord( $hash[ $offset + 3 ] ) & 0xFF );

		return str_pad( (string) ( $value % 1000000 ), 6, '0', STR_PAD_LEFT );
	}

	/**
	 * Check a submitted code against an account.
	 *
	 * One step either side of now is accepted, so a device whose clock is up to
	 * half a minute out still works — a common reason a correct code is
	 * refused, and a support request nobody wants.
	 *
	 * @param int    $user_id User ID.
	 * @param string $code    Submitted code.
	 * @return bool
	 */
	public static function verify_code_for_user( $user_id, $code ) {
		$secret = (string) get_user_meta( $user_id, self::META_SECRET, true );

		if ( '' === $secret ) {
			return false;
		}

		return self::verify_secret( $secret, $code );
	}

	/**
	 * Check a submitted code against a secret.
	 *
	 * @param string $secret Base32 secret.
	 * @param string $code   Submitted code.
	 * @return bool
	 */
	public static function verify_secret( $secret, $code ) {
		$code = preg_replace( '/\D/', '', (string) $code );

		if ( ! is_string( $code ) || 6 !== strlen( $code ) ) {
			return false;
		}

		$slice = (int) floor( time() / self::STEP );

		for ( $drift = -1; $drift <= 1; $drift++ ) {
			$expected = self::code_at( $secret, $slice + $drift );

			if ( '' !== $expected && hash_equals( $expected, $code ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Base32, the alphabet authenticator apps use.
	 *
	 * @param string $bytes Raw bytes.
	 * @return string
	 */
	private static function base32_encode( $bytes ) {
		$alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
		$out      = '';
		$buffer   = 0;
		$bits     = 0;
		$length   = strlen( $bytes );

		for ( $i = 0; $i < $length; $i++ ) {
			$buffer = ( $buffer << 8 ) | ord( $bytes[ $i ] );
			$bits  += 8;

			while ( $bits >= 5 ) {
				$out   .= $alphabet[ ( $buffer >> ( $bits - 5 ) ) & 31 ];
				$bits  -= 5;
			}
		}

		if ( $bits > 0 ) {
			$out .= $alphabet[ ( $buffer << ( 5 - $bits ) ) & 31 ];
		}

		return $out;
	}

	/**
	 * Base32 decode, ignoring spacing and case.
	 *
	 * @param string $secret Encoded secret.
	 * @return string Raw bytes, or an empty string if the input is not Base32.
	 */
	private static function base32_decode( $secret ) {
		$alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
		$secret   = strtoupper( preg_replace( '/[^A-Za-z2-7]/', '', (string) $secret ) );

		if ( '' === $secret ) {
			return '';
		}

		$out    = '';
		$buffer = 0;
		$bits   = 0;
		$length = strlen( $secret );

		for ( $i = 0; $i < $length; $i++ ) {
			$index = strpos( $alphabet, $secret[ $i ] );

			if ( false === $index ) {
				return '';
			}

			$buffer = ( $buffer << 5 ) | $index;
			$bits  += 5;

			if ( $bits >= 8 ) {
				$out   .= chr( ( $buffer >> ( $bits - 8 ) ) & 0xFF );
				$bits  -= 8;
			}
		}

		return $out;
	}

	/* ---------------------------------------------------------------------
	 * Recovery codes
	 * ------------------------------------------------------------------ */

	/**
	 * How many recovery codes are left.
	 *
	 * @param int $user_id User ID.
	 * @return int
	 */
	public static function recovery_remaining( $user_id ) {
		$codes = get_user_meta( $user_id, self::META_CODES, true );

		return is_array( $codes ) ? count( $codes ) : 0;
	}

	/**
	 * Issue a fresh set of recovery codes, replacing any previous set.
	 *
	 * The codes are stored as keyed hashes. A fast hash is the right tool here
	 * and not a shortcut: each code carries about sixty bits of randomness,
	 * which puts an offline search out of reach on its own — unlike a password,
	 * where the same choice would be indefensible. Each code is removed as soon
	 * as it is used.
	 *
	 * @param int $user_id User ID.
	 * @return string[] The codes, in the clear, for their one showing.
	 */
	public static function issue_recovery_codes( $user_id ) {
		$plain  = array();
		$stored = array();

		for ( $i = 0; $i < self::RECOVERY_COUNT; $i++ ) {
			$code = strtoupper( wp_generate_password( 4, false, false ) . '-' . wp_generate_password( 6, false, false ) );

			$plain[]  = $code;
			$stored[] = self::hash_recovery_code( $code );
		}

		update_user_meta( $user_id, self::META_CODES, $stored );

		return $plain;
	}

	/**
	 * Hash one recovery code for storage.
	 *
	 * @param string $code Code in the clear.
	 * @return string
	 */
	private static function hash_recovery_code( $code ) {
		return hash_hmac( 'sha256', self::normalise_recovery_code( $code ), wp_salt( 'auth' ) );
	}

	/**
	 * Reduce a recovery code to its comparable form.
	 *
	 * @param string $code Code as typed.
	 * @return string
	 */
	private static function normalise_recovery_code( $code ) {
		return strtoupper( (string) preg_replace( '/[^A-Za-z0-9]/', '', (string) $code ) );
	}

	/**
	 * Spend a recovery code, if it matches one.
	 *
	 * @param int    $user_id User ID.
	 * @param string $code    Submitted code.
	 * @return bool
	 */
	public static function consume_recovery_code( $user_id, $code ) {
		$normalised = self::normalise_recovery_code( $code );

		// Shorter than a code can be, so it is not one.
		if ( strlen( $normalised ) < 8 ) {
			return false;
		}

		$stored = get_user_meta( $user_id, self::META_CODES, true );

		if ( ! is_array( $stored ) || ! $stored ) {
			return false;
		}

		$wanted = self::hash_recovery_code( $code );

		foreach ( $stored as $index => $hash ) {
			if ( ! is_string( $hash ) || ! hash_equals( $hash, $wanted ) ) {
				continue;
			}

			// Single use: it is gone whether the sign-in completes or not.
			unset( $stored[ $index ] );
			update_user_meta( $user_id, self::META_CODES, array_values( $stored ) );

			return true;
		}

		return false;
	}

	/* ---------------------------------------------------------------------
	 * Remembered devices
	 * ------------------------------------------------------------------ */

	/**
	 * Cookie name for one account.
	 *
	 * One cookie per account rather than one shared cookie, so two people using
	 * the same browser do not silently remember each other's devices.
	 *
	 * @param int $user_id User ID.
	 * @return string
	 */
	private static function cookie_name( $user_id ) {
		return self::COOKIE_PREFIX . (int) $user_id;
	}

	/**
	 * Signature for a remembered device, over the account's own secret.
	 *
	 * Keying the signature with the two-factor secret is what makes "reset the
	 * account's two-factor" equivalent to "forget every device": the moment the
	 * secret changes, every outstanding signature stops verifying.
	 *
	 * @param int $user_id User ID.
	 * @param int $expires Unix time the remember period ends.
	 * @return string
	 */
	private static function device_signature( $user_id, $expires ) {
		$secret = (string) get_user_meta( $user_id, self::META_SECRET, true );

		return hash_hmac( 'sha256', (int) $user_id . '|' . (int) $expires, $secret . wp_salt( 'auth' ) );
	}

	/**
	 * Mark this browser as trusted for the configured period.
	 *
	 * @param int $user_id User ID.
	 * @return void
	 */
	public static function remember_device( $user_id ) {
		$days = self::remember_days();

		if ( $days < 1 ) {
			return;
		}

		$expires = time() + ( $days * DAY_IN_SECONDS );

		setcookie(
			self::cookie_name( $user_id ),
			$expires . '|' . self::device_signature( $user_id, $expires ),
			array(
				'expires'  => $expires,
				'path'     => defined( 'COOKIEPATH' ) ? COOKIEPATH : '/',
				'domain'   => defined( 'COOKIE_DOMAIN' ) ? COOKIE_DOMAIN : '',
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Lax',
			)
		);
	}

	/**
	 * Is this browser already trusted for this account?
	 *
	 * @param int $user_id User ID.
	 * @return bool
	 */
	public static function is_remembered_device( $user_id ) {
		if ( self::remember_days() < 1 ) {
			return false;
		}

		$name = self::cookie_name( $user_id );

		if ( empty( $_COOKIE[ $name ] ) ) {
			return false;
		}

		$raw = sanitize_text_field( wp_unslash( $_COOKIE[ $name ] ) );

		if ( ! is_string( $raw ) || false === strpos( $raw, '|' ) ) {
			return false;
		}

		list( $expires, $signature ) = array_pad( explode( '|', $raw, 2 ), 2, '' );

		if ( ! is_numeric( $expires ) || '' === $signature ) {
			return false;
		}

		$expires = (int) $expires;

		if ( $expires < time() ) {
			return false;
		}

		return hash_equals( self::device_signature( $user_id, $expires ), $signature );
	}

	/**
	 * Drop the remembered-device cookie.
	 *
	 * @param int $user_id User ID.
	 * @return void
	 */
	public static function forget_device( $user_id ) {
		setcookie(
			self::cookie_name( $user_id ),
			'',
			array(
				'expires'  => time() - YEAR_IN_SECONDS,
				'path'     => defined( 'COOKIEPATH' ) ? COOKIEPATH : '/',
				'domain'   => defined( 'COOKIE_DOMAIN' ) ? COOKIE_DOMAIN : '',
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Lax',
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * Turning it on and off
	 * ------------------------------------------------------------------ */

	/**
	 * Start enrolment: hold a candidate secret until a code proves it works.
	 *
	 * The secret is not made live yet. An account is only ever marked enrolled
	 * once a code generated from the secret has been accepted, so a mistyped
	 * secret cannot lock anybody out.
	 *
	 * @param int $user_id User ID.
	 * @return string The candidate secret.
	 */
	public static function start_enrolment( $user_id ) {
		$secret = self::generate_secret();

		update_user_meta( $user_id, self::META_PENDING, $secret );

		return $secret;
	}

	/**
	 * The candidate secret waiting to be confirmed, if any.
	 *
	 * @param int $user_id User ID.
	 * @return string
	 */
	public static function pending_secret( $user_id ) {
		return (string) get_user_meta( $user_id, self::META_PENDING, true );
	}

	/**
	 * Confirm enrolment with a code from the candidate secret.
	 *
	 * @param int    $user_id User ID.
	 * @param string $code    Submitted code.
	 * @return true|\WP_Error
	 */
	public static function confirm_enrolment( $user_id, $code ) {
		$secret = self::pending_secret( $user_id );

		if ( '' === $secret ) {
			return new \WP_Error(
				'at8sec_pro_no_pending',
				__( 'There is no two-factor setup in progress for this account.', 'at8-security-pro' )
			);
		}

		if ( ! self::verify_secret( $secret, $code ) ) {
			return new \WP_Error(
				'at8sec_pro_bad_code',
				__( 'That code was not accepted. Check the clock on your device and enter the current code.', 'at8-security-pro' )
			);
		}

		update_user_meta( $user_id, self::META_SECRET, $secret );
		update_user_meta( $user_id, self::META_ENABLED, '1' );
		delete_user_meta( $user_id, self::META_PENDING );

		return true;
	}

	/**
	 * Turn two-factor off and forget everything tied to it.
	 *
	 * @param int $user_id User ID.
	 * @return void
	 */
	public static function disable_for_user( $user_id ) {
		delete_user_meta( $user_id, self::META_SECRET );
		delete_user_meta( $user_id, self::META_ENABLED );
		delete_user_meta( $user_id, self::META_CODES );
		delete_user_meta( $user_id, self::META_PENDING );

		self::forget_device( $user_id );
	}

	/* ---------------------------------------------------------------------
	 * Profile screen
	 * ------------------------------------------------------------------ */

	/**
	 * Handle a submitted action from the profile screen.
	 *
	 * Runs on admin_init, before anything is printed, so an action can finish
	 * by redirecting — which keeps a refresh from repeating it.
	 *
	 * @return void
	 */
	public function handle_profile_action() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- the nonce is checked below.
		if ( empty( $_POST[ self::FIELD_ACTION ] ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- the nonce is checked below.
		$action = sanitize_key( wp_unslash( $_POST[ self::FIELD_ACTION ] ) );

		check_admin_referer( 'at8sec_pro_2fa_' . $action );

		$user_id = get_current_user_id();

		if ( ! $user_id || ! current_user_can( 'read' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'at8-security-pro' ) );
		}

		$notice = '';

		switch ( $action ) {
			case 'start':
				self::forget_device( $user_id );
				self::start_enrolment( $user_id );
				$notice = 'started';
				break;

			case 'confirm':
				// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by check_admin_referer() above.
				$code   = isset( $_POST[ self::FIELD_CODE ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::FIELD_CODE ] ) ) : '';
				$result = self::confirm_enrolment( $user_id, $code );

				if ( is_wp_error( $result ) ) {
					Settings::queue_notice( $result->get_error_message(), 'error' );

					$notice = 'confirm';
					break;
				}

				self::show_recovery_codes_once( $user_id, self::issue_recovery_codes( $user_id ) );
				$notice = 'enabled';
				break;

			case 'codes':
				self::show_recovery_codes_once( $user_id, self::issue_recovery_codes( $user_id ) );
				$notice = 'codes';
				break;

			case 'disable':
				self::disable_for_user( $user_id );
				$notice = 'disabled';
				break;

			case 'forget':
				self::forget_device( $user_id );
				$notice = 'forgotten';
				break;
		}

		wp_safe_redirect( add_query_arg( 'at8sec_pro_2fa', $notice, get_edit_profile_url( $user_id ) ) );
		exit;
	}

	/**
	 * Hold a freshly issued set of codes for their one showing.
	 *
	 * Kept in a transient belonging to this user rather than in the profile
	 * itself: the codes are readable exactly once, then gone.
	 *
	 * @param int      $user_id User ID.
	 * @param string[] $codes   Codes in the clear.
	 * @return void
	 */
	private static function show_recovery_codes_once( $user_id, $codes ) {
		set_transient( 'at8sec_pro_codes_' . (int) $user_id, $codes, 10 * MINUTE_IN_SECONDS );
	}

	/**
	 * Draw the two-factor section on the user's own profile screen.
	 *
	 * @param \WP_User $profile_user User whose profile is being shown.
	 * @return void
	 */
	public function render_profile_section( $profile_user ) {
		if ( ! $profile_user instanceof \WP_User ) {
			return;
		}

		$user_id   = $profile_user->ID;
		$is_self   = get_current_user_id() === $user_id;
		$enrolled  = self::is_enrolled( $user_id );
		$pending   = self::pending_secret( $user_id );
		$remaining = self::recovery_remaining( $user_id );

		?>
		<h2 id="at8sec-pro-2fa"><?php esc_html_e( 'Two-factor authentication', 'at8-security-pro' ); ?></h2>

		<?php $this->render_profile_notice(); ?>

		<?php if ( ! self::active() ) : ?>
			<p class="at8sec-desc">
				<?php esc_html_e( 'Two-factor authentication is switched off for this site.', 'at8-security-pro' ); ?>
			</p>
			<?php
			return;
		endif;
		?>

		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Status', 'at8-security-pro' ); ?></th>
				<td>
					<?php if ( $enrolled ) : ?>
						<p><strong><?php esc_html_e( 'On.', 'at8-security-pro' ); ?></strong>
							<?php
							printf(
								/* translators: %d: number of unused recovery codes. */
								esc_html__( 'Recovery codes left: %d.', 'at8-security-pro' ),
								(int) $remaining
							);
							?>
							<?php if ( 0 === $remaining ) : ?>
								<strong><?php esc_html_e( 'Issue a new set before you need one.', 'at8-security-pro' ); ?></strong>
							<?php endif; ?>
						</p>
						<p class="at8sec-desc">
							<?php esc_html_e( 'You will be asked for a code the next time you sign in on a device you have not marked as remembered.', 'at8-security-pro' ); ?>
						</p>
					<?php else : ?>
						<p><strong><?php esc_html_e( 'Off.', 'at8-security-pro' ); ?></strong>
							<?php if ( self::role_applies( $profile_user ) ) : ?>
								<?php esc_html_e( 'Your role is one this site asks to use two-factor authentication.', 'at8-security-pro' ); ?>
							<?php endif; ?>
						</p>
					<?php endif; ?>
				</td>
			</tr>

			<?php if ( $enrolled ) : ?>
				<tr>
					<th scope="row"><?php esc_html_e( 'Manage', 'at8-security-pro' ); ?></th>
					<td>
						<p>
							<?php
							$this->button(
								'codes',
								__( 'Issue a new set of recovery codes', 'at8-security-pro' ),
								__( 'Replaces the current set. Any unused codes stop working.', 'at8-security-pro' ),
								$is_self
							);
							?>
						</p>
						<p>
							<?php
							$this->button(
								'forget',
								__( 'Forget this browser', 'at8-security-pro' ),
								__( 'The next sign-in here will ask for a code again.', 'at8-security-pro' ),
								$is_self
							);
							?>
						</p>
						<p>
							<?php
							$this->button(
								'disable',
								__( 'Turn two-factor off', 'at8-security-pro' ),
								__( 'Your password alone will sign you in again.', 'at8-security-pro' ),
								$is_self
							);
							?>
						</p>
					</td>
				</tr>
			<?php elseif ( '' !== $pending ) : ?>
				<tr>
					<th scope="row"><?php esc_html_e( 'Finish setup', 'at8-security-pro' ); ?></th>
					<td>
						<?php if ( ! $is_self ) : ?>
							<p class="at8sec-desc">
								<?php esc_html_e( 'This account has started setting up two-factor authentication. Only the account holder can finish it, and only they ever see the key.', 'at8-security-pro' ); ?>
							</p>
						<?php else : ?>
						<p class="at8sec-desc">
							<?php esc_html_e( 'Add this key to your authenticator app — choose "enter a setup key" and type it exactly as shown. Then enter the six-digit code the app produces to prove the key was entered correctly.', 'at8-security-pro' ); ?>
						</p>
						<p>
							<code class="at8sec-pro-secret"><?php echo esc_html( self::format_secret( $pending ) ); ?></code>
						</p>
						<p class="at8sec-desc">
							<?php esc_html_e( 'Account name', 'at8-security-pro' ); ?>:
							<code><?php echo esc_html( $profile_user->user_login ); ?></code>
							&middot; <?php esc_html_e( 'Key type', 'at8-security-pro' ); ?>:
							<code><?php esc_html_e( 'Time based', 'at8-security-pro' ); ?></code>
						</p>
						<form method="post">
							<?php wp_nonce_field( 'at8sec_pro_2fa_confirm' ); ?>
							<input type="hidden" name="<?php echo esc_attr( self::FIELD_ACTION ); ?>" value="confirm" />
							<p>
								<label for="<?php echo esc_attr( self::FIELD_CODE ); ?>">
									<?php esc_html_e( 'Six-digit code from the app', 'at8-security-pro' ); ?>
								</label><br />
								<input type="text" name="<?php echo esc_attr( self::FIELD_CODE ); ?>"
									id="<?php echo esc_attr( self::FIELD_CODE ); ?>" class="regular-text"
									inputmode="numeric" autocomplete="one-time-code" required />
							</p>
							<p>
								<button type="submit" class="button button-primary">
									<?php esc_html_e( 'Turn on two-factor authentication', 'at8-security-pro' ); ?>
								</button>
							</p>
						</form>
						<?php endif; ?>
					</td>
				</tr>
			<?php else : ?>
				<tr>
					<th scope="row"><?php esc_html_e( 'Set up', 'at8-security-pro' ); ?></th>
					<td>
						<p class="at8sec-desc">
							<?php esc_html_e( 'You will need an authenticator app on your phone — any app that supports time-based codes will do. Nothing is sent to a third party; the key stays on this server and in your app.', 'at8-security-pro' ); ?>
						</p>
						<p class="at8sec-desc">
							<?php esc_html_e( 'Keep a recovery code somewhere safe once setup is complete. It is the only way back in if you lose the app.', 'at8-security-pro' ); ?>
						</p>
						<?php if ( $is_self ) : ?>
							<form method="post">
								<?php wp_nonce_field( 'at8sec_pro_2fa_start' ); ?>
								<input type="hidden" name="<?php echo esc_attr( self::FIELD_ACTION ); ?>" value="start" />
								<button type="submit" class="button">
									<?php esc_html_e( 'Begin setup', 'at8-security-pro' ); ?>
								</button>
							</form>
						<?php else : ?>
							<p class="at8sec-desc">
								<?php esc_html_e( 'Only the account holder can set this up.', 'at8-security-pro' ); ?>
							</p>
						<?php endif; ?>
					</td>
				</tr>
			<?php endif; ?>
		</table>
		<?php
	}

	/**
	 * Print the one-off notice on the profile screen.
	 *
	 * @return void
	 */
	private function render_profile_notice() {
		$notice = Settings::take_notice();

		if ( '' !== $notice['message'] ) {
			printf(
				'<div class="notice notice-%1$s inline"><p>%2$s</p></div>',
				'error' === $notice['type'] ? 'error' : 'success',
				esc_html( $notice['message'] )
			);
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only flag.
		$flag = isset( $_GET['at8sec_pro_2fa'] ) ? sanitize_key( wp_unslash( $_GET['at8sec_pro_2fa'] ) ) : '';

		$messages = array(
			'enabled'   => __( 'Two-factor authentication is on. Your recovery codes are below — save them now, they are shown once.', 'at8-security-pro' ),
			'codes'     => __( 'New recovery codes have been issued. The previous set no longer works. Save these now, they are shown once.', 'at8-security-pro' ),
			'disabled'  => __( 'Two-factor authentication is off for your account.', 'at8-security-pro' ),
			'forgotten' => __( 'This browser is no longer remembered.', 'at8-security-pro' ),
		);

		if ( isset( $messages[ $flag ] ) ) {
			printf( '<div class="notice notice-success inline"><p>%s</p></div>', esc_html( $messages[ $flag ] ) );
		}

		$codes = get_transient( 'at8sec_pro_codes_' . get_current_user_id() );

		if ( ! is_array( $codes ) || ! $codes ) {
			return;
		}

		delete_transient( 'at8sec_pro_codes_' . get_current_user_id() );
		?>
		<div class="notice notice-warning inline at8sec-pro-codes">
			<p><strong><?php esc_html_e( 'Recovery codes', 'at8-security-pro' ); ?></strong> —
				<?php esc_html_e( 'each one signs you in a single time if you lose your authenticator. Store them somewhere other than this browser.', 'at8-security-pro' ); ?></p>
			<p><code><?php echo esc_html( implode( '   ', array_map( 'strval', $codes ) ) ); ?></code></p>
		</div>
		<?php
	}

	/**
	 * Print one action button as a small self-contained form.
	 *
	 * A form per button rather than one form around all of them, so the button
	 * that was pressed is the only action that can possibly run.
	 *
	 * @param string $action     Action key.
	 * @param string $label      Button label.
	 * @param string $description Optional help text.
	 * @param bool   $enabled    Whether the button is offered.
	 * @return void
	 */
	private function button( $action, $label, $description, $enabled ) {
		if ( ! $enabled ) {
			printf(
				'<span class="at8sec-desc">%s</span>',
				esc_html( $description )
			);

			return;
		}
		?>
		<form method="post" class="at8sec-pro-inline-form">
			<?php wp_nonce_field( 'at8sec_pro_2fa_' . $action ); ?>
			<input type="hidden" name="<?php echo esc_attr( self::FIELD_ACTION ); ?>" value="<?php echo esc_attr( $action ); ?>" />
			<button type="submit" class="button"><?php echo esc_html( $label ); ?></button>
			<span class="at8sec-desc"><?php echo esc_html( $description ); ?></span>
		</form>
		<?php
	}

	/**
	 * Remind a user whose role is asked to use two-factor, but who has not set
	 * it up yet, to do so.
	 *
	 * This is the whole of the enforcement for a listed role: a reminder on
	 * their own screens, and nothing that can keep them out.
	 *
	 * @return void
	 */
	public function enrolment_notice() {
		if ( ! self::active() || ! current_user_can( 'read' ) ) {
			return;
		}

		$user = wp_get_current_user();

		if ( ! self::role_applies( $user ) || self::is_enrolled( $user->ID ) ) {
			return;
		}

		$url = get_edit_profile_url( $user->ID ) . '#at8sec-pro-2fa';

		printf(
			'<div class="notice notice-warning"><p>%s</p></div>',
			wp_kses_post(
				sprintf(
					/* translators: %s: URL of the user's own profile screen. */
					__( 'This site asks accounts in your role to use two-factor authentication. <a href="%s">Set it up on your profile</a> — a password alone is not enough for the access you have.', 'at8-security-pro' ),
					esc_url( $url )
				)
			)
		);
	}
}
