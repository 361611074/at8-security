<?php
/**
 * Password policy and the weak-password audit tool.
 *
 * @package At8\Security
 */

namespace At8\Security;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Module 6 — password strength.
 */
class Password_Policy {

	/**
	 * Seconds the manual scan may run before it returns partial results.
	 */
	const SCAN_BUDGET = 20;

	/**
	 * Register hooks.
	 */
	public function __construct() {
		if ( Settings::on( 'pwd_policy' ) ) {
			add_filter( 'user_profile_update_errors', array( $this, 'check_profile_update' ), 10, 3 );
			add_filter( 'registration_errors', array( $this, 'check_registration' ), 10, 3 );
		}
	}

	/**
	 * Small dictionary of passwords that appear at the top of every leak list.
	 *
	 * @return string[]
	 */
	private function dictionary() {
		$words = array(
			'123456',
			'12345678',
			'123456789',
			'password',
			'password1',
			'passw0rd',
			'qwerty',
			'qwerty123',
			'abc123',
			'111111',
			'123123',
			'admin',
			'admin123',
			'letmein',
			'welcome',
			'changeme',
			'iloveyou',
			'monkey',
			'dragon',
			'root',
			'test',
			'test123',
			'1q2w3e4r',
			'wordpress',
			'wordpress123',
		);

		/**
		 * Filter the weak-password dictionary.
		 *
		 * @param string[] $words Candidate passwords.
		 */
		return (array) apply_filters( 'at8sec_weak_password_dictionary', $words );
	}

	/**
	 * Validate a password against the configured policy.
	 *
	 * @param string $password Plain-text password.
	 * @return true|\WP_Error True when acceptable, otherwise an error object.
	 */
	public function validate( $password ) {
		$password = (string) $password;
		$min      = max( 8, (int) Settings::get( 'pwd_min_length', 12 ) );

		if ( strlen( $password ) < $min ) {
			return new \WP_Error(
				'at8sec_password',
				sprintf(
					/* translators: %d: minimum number of characters. */
					__( 'The password must be at least %d characters long.', 'at8-security' ),
					$min
				)
			);
		}

		if ( ! preg_match( '/[a-z]/', $password ) || ! preg_match( '/[A-Z]/', $password ) ) {
			return new \WP_Error( 'at8sec_password', __( 'The password must contain both upper-case and lower-case letters.', 'at8-security' ) );
		}

		if ( ! preg_match( '/\d/', $password ) ) {
			return new \WP_Error( 'at8sec_password', __( 'The password must contain at least one number.', 'at8-security' ) );
		}

		if ( ! preg_match( '/[^a-zA-Z0-9]/', $password ) ) {
			return new \WP_Error( 'at8sec_password', __( 'The password must contain at least one non-alphanumeric character.', 'at8-security' ) );
		}

		if ( in_array( strtolower( $password ), $this->dictionary(), true ) ) {
			return new \WP_Error( 'at8sec_password', __( 'That password appears on lists of commonly used passwords. Please choose another one.', 'at8-security' ) );
		}

		return true;
	}

	/**
	 * Validate the password submitted on a profile screen.
	 *
	 * @param \WP_Error $errors Validation errors, extended in place.
	 * @param bool      $update Whether this is an existing user.
	 * @param \WP_User  $user   User being saved.
	 * @return \WP_Error
	 */
	public function check_profile_update( $errors, $update, $user ) {
		unset( $update );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- core has already checked the profile nonce, and a password must not be sanitised because that would change what the user typed.
		$password = isset( $_POST['pass1'] ) ? (string) wp_unslash( $_POST['pass1'] ) : '';

		if ( '' === $password ) {
			return $errors; // Not changing the password.
		}

		$result = $this->validate( $password );

		if ( is_wp_error( $result ) ) {
			$errors->add( $result->get_error_code(), $result->get_error_message() );
		}

		return $errors;
	}

	/**
	 * Validate the password submitted on a registration form.
	 *
	 * @param \WP_Error $errors               Validation errors, extended in place.
	 * @param string    $sanitized_user_login Sanitised login name.
	 * @param string    $user_email           Submitted e-mail address.
	 * @return \WP_Error
	 */
	public function check_registration( $errors, $sanitized_user_login = '', $user_email = '' ) {
		unset( $sanitized_user_login, $user_email );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- core has already checked the registration nonce, and a password must not be sanitised because that would change what the user typed.
		$password = isset( $_POST['user_pass'] ) ? (string) wp_unslash( $_POST['user_pass'] ) : '';

		if ( '' === $password ) {
			return $errors;
		}

		$result = $this->validate( $password );

		if ( is_wp_error( $result ) ) {
			$errors->add( $result->get_error_code(), $result->get_error_message() );
		}

		return $errors;
	}

	/**
	 * Look for accounts protected by a password from the dictionary.
	 *
	 * Bcrypt verification costs roughly 100 ms per candidate, so this is run
	 * against a bounded list with a wall-clock budget: on a large site the
	 * scan returns what it managed to check rather than timing the request out.
	 *
	 * @return array{checked:int, total:int, weak:string[], truncated:bool}
	 */
	public function scan() {
		$started = time();
		$weak    = array();
		$checked = 0;

		$ids = get_users(
			array(
				'fields' => 'ID',
				'number' => 300,
			)
		);

		$total = count( $ids );

		foreach ( $ids as $id ) {
			if ( time() - $started > self::SCAN_BUDGET ) {
				return array(
					'checked'   => $checked,
					'total'     => $total,
					'weak'      => $weak,
					'truncated' => true,
				);
			}

			$user = get_userdata( $id );
			if ( ! $user instanceof \WP_User || '' === (string) $user->user_pass ) {
				continue;
			}

			++$checked;

			foreach ( $this->dictionary() as $candidate ) {
				if ( wp_check_password( $candidate, $user->user_pass, $user->ID ) ) {
					$weak[] = $user->user_login;
					break;
				}
			}
		}

		return array(
			'checked'   => $checked,
			'total'     => $total,
			'weak'      => $weak,
			'truncated' => false,
		);
	}
}
