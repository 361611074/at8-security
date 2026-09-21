<?php
/**
 * Firewall, rate limiting and 404 flood protection.
 *
 * Design notes
 * ------------
 * This is deliberately a conservative application-layer filter, not a
 * replacement for parameterised queries and output escaping.
 *
 * The pattern set was rebuilt after the previous version produced false
 * positives on ordinary content — the rule for backticks matched any Markdown
 * code span, and `select … from` matched plain English in a comment. Both are
 * gone. What remains are high-confidence signatures, and the XSS pattern is
 * only ever tested against the URL and parameter *names*, never against
 * parameter values, so a blog post or comment containing HTML is unaffected
 * while a request carrying a payload in its query string is still blocked.
 *
 * @package At8\Security
 */

namespace At8\Security;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Module 7 — firewall and rate limiting.
 */
class WAF {

	/**
	 * Register hooks.
	 */
	public function __construct() {
		// Priority 2: run before most plugins have finished booting on init.
		add_action( 'init', array( $this, 'inspect_request' ), 2 );
		add_action( 'template_redirect', array( $this, 'throttle_404' ) );
	}

	/**
	 * High-confidence attack signatures tested against the whole request.
	 *
	 * @return array<string, string>
	 */
	private function patterns() {
		$patterns = array(
			'sql'       => '/(\bunion\b[\s\/*]+(?:all[\s\/*]+)?\bselect\b|\b(?:sleep|benchmark|load_file|updatexml|extractvalue)\s*\(|\binformation_schema\b|@@version\b|\bxp_cmdshell\b|(?:^|[\s\'"()])(?:or|and)\s+[\'"]?\d+[\'"]?\s*=\s*[\'"]?\d+[\'"]?)/i',
			'traversal' => '/(\.\.\/|\.\.\\\\|%2e%2e%2f|%2e%2e%5c|%252e%252e)/i',
			'rce'       => '/(\b(?:system|exec|shell_exec|passthru|popen|proc_open|pcntl_exec)\s*\(|\$\{jndi:|\b(?:eval|assert)\s*\(\s*\$)/i',
		);

		/**
		 * Filter the firewall signatures.
		 *
		 * @param array<string, string> $patterns Regex patterns keyed by rule name.
		 */
		return (array) apply_filters( 'at8sec_waf_patterns', $patterns );
	}

	/**
	 * Signature tested against the URL and parameter names only.
	 *
	 * @return string
	 */
	private function xss_pattern() {
		$pattern = '/(<script[\s>\/]|%3cscript|%3c%2fscript|javascript\s*:|vbscript\s*:|data:text\/html|on(?:error|load|mouseover|click|focus|submit|animationstart)\s*=)/i';

		/**
		 * Filter the XSS signature.
		 *
		 * @param string $pattern Regex pattern.
		 */
		return (string) apply_filters( 'at8sec_waf_xss_pattern', $pattern );
	}

	/**
	 * Run the request through the configured checks.
	 *
	 * @return void
	 */
	public function inspect_request() {
		// The admin area is behind authentication and is not the target of
		// this layer; scanning it would only interfere with legitimate work.
		if ( is_admin() ) {
			return;
		}

		/*
		 * REST and AJAX requests carry their own validation, and reading the
		 * request body there is unsafe (php://input can only be consumed
		 * once). Both are skipped, as before.
		 */
		if ( ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || wp_doing_ajax() ) {
			return;
		}

		$ip = get_client_ip();

		// Whitelisted visitors skip every check below.
		if ( $this->is_whitelisted( $ip ) ) {
			return;
		}

		/*
		 * Address-based rules are skipped when the request comes from a
		 * trusted proxy that did not forward the real client address: the
		 * visible address is then the CDN edge itself, and blocking or
		 * throttling it would cut off every visitor behind that edge.
		 * Content-based checks still run.
		 */
		$address_rules = $this->address_rules_apply( $ip );

		if ( $address_rules ) {
			$this->enforce_blacklist( $ip );
		}

		$is_admin_user = current_user_can( 'manage_options' );

		if ( Settings::on( 'block_bad_ua' ) && ! $is_admin_user ) {
			$agent = isset( $_SERVER['HTTP_USER_AGENT'] )
				? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) )
				: '';

			if ( $this->is_bad_agent( $agent ) ) {
				$this->deny( 'bad-user-agent' );
			}
		}

		if ( $address_rules && Settings::on( 'rate_limit_global' ) && ! $is_admin_user ) {
			$this->enforce_rate_limit( $ip );
		}

		if ( Settings::on( 'waf_basic' ) ) {
			$this->scan_request();
		}
	}

	/**
	 * Is this address explicitly whitelisted?
	 *
	 * @param string $ip Resolved client address.
	 * @return bool
	 */
	private function is_whitelisted( $ip ) {
		$whitelist = Settings::get( 'ip_whitelist', '' );

		return (bool) ( $whitelist && ip_in_list( $ip, $whitelist ) );
	}

	/**
	 * May address-based rules be applied to this request?
	 *
	 * @param string $ip Resolved client address.
	 * @return bool
	 */
	private function address_rules_apply( $ip ) {
		$remote  = isset( $_SERVER['REMOTE_ADDR'] )
			? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) )
			: '';
		$trusted = get_trusted_proxies();

		if ( $trusted && '' !== $remote && ip_in_list( $remote, $trusted ) && $ip === $remote ) {
			return false;
		}

		return true;
	}

	/**
	 * Blocklisted addresses are refused outright.
	 *
	 * @param string $ip Resolved client address.
	 * @return void
	 */
	private function enforce_blacklist( $ip ) {
		$blacklist = Settings::get( 'ip_blacklist', '' );

		if ( $blacklist && ip_in_list( $ip, $blacklist ) ) {
			$this->deny( 'ip-blacklist' );
		}
	}

	/**
	 * Recognise automated scanners by their User-Agent string.
	 *
	 * @param string $agent User-Agent header value.
	 * @return bool
	 */
	private function is_bad_agent( $agent ) {
		if ( '' === trim( $agent ) ) {
			return true; // Real browsers always send one.
		}

		$signatures = array(
			'sqlmap',
			'nikto',
			'nmap',
			'masscan',
			'nessus',
			'wpscan',
			'hydra',
			'dirbuster',
			'acunetix',
			'havij',
			'zgrab',
			'xray',
		);

		/**
		 * Filter the blocked User-Agent signatures.
		 *
		 * @param string[] $signatures Lower-case substrings to match.
		 */
		$signatures = (array) apply_filters( 'at8sec_bad_user_agents', $signatures );

		$agent = strtolower( $agent );

		foreach ( $signatures as $signature ) {
			if ( '' !== $signature && false !== strpos( $agent, strtolower( $signature ) ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Apply the global request rate limit.
	 *
	 * The counter carries its own window end instead of leaning on the
	 * transient's expiry, because set_transient() restarts the clock on every
	 * write: a visitor who spread requests out over hours would otherwise
	 * accumulate for ever and eventually be blocked without ever having made
	 * a burst.
	 *
	 * @param string $ip Resolved client address.
	 * @return void
	 */
	private function enforce_rate_limit( $ip ) {
		$max    = max( 5, (int) Settings::get( 'rate_limit_max', 60 ) );
		$window = max( 1, (int) Settings::get( 'rate_limit_window', 60 ) );
		$key    = 'at8sec_rate_' . ip_hash( $ip );

		$count = $this->bump_counter( $key, $window );

		if ( $count > $max ) {
			$this->deny( 'rate-limit' );
		}
	}

	/**
	 * Fixed-window counter held in a transient.
	 *
	 * The stored array keeps the moment its window ends, so the window is
	 * decided by data rather than by how long ago the transient was written.
	 *
	 * @param string $key    Transient key.
	 * @param int    $window Window length in seconds.
	 * @return int Requests seen in the current window, this one included.
	 */
	private function bump_counter( $key, $window ) {
		$now  = time();
		$data = get_transient( $key );

		if ( ! is_array( $data ) || ! isset( $data['count'], $data['until'] ) || (int) $data['until'] <= $now ) {
			$data = array(
				'count' => 0,
				'until' => $now + $window,
			);
		}

		$data['count'] = (int) $data['count'] + 1;

		set_transient( $key, $data, ( (int) $data['until'] - $now ) + 60 );

		return (int) $data['count'];
	}

	/**
	 * Collect the raw request into two strings for pattern matching.
	 *
	 * Parameter values are flattened with recursion so nested arrays from form
	 * fields are covered, while the request body stream is never touched.
	 *
	 * @return array{url:string, values:string}
	 */
	private function scope() {
		/*
		 * The URI is read exactly as it arrives, deliberately without
		 * sanitize_text_field(). That function removes every %xx octet before
		 * the value is decoded, so a correctly encoded payload such as
		 * `?q=%3Cscript%3E` would reach the patterns as `q=script` and the
		 * check would silently miss it — which is precisely how an attacker
		 * sends it. Nothing read here is stored or printed; these strings only
		 * ever reach preg_match().
		 */
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- matching the raw request is the whole point, and the value is never stored or output.
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '';

		$uri   = rawurldecode( $uri );
		$path  = (string) wp_parse_url( $uri, PHP_URL_PATH );
		$query = (string) wp_parse_url( $uri, PHP_URL_QUERY );

		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended -- read-only inspection of the request, nothing is changed or stored.
		$names  = array_keys( (array) $_GET );
		$values = $this->flatten( $_GET ) . ' ' . $this->flatten( $_POST );
		// phpcs:enable WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended

		$url_scope = $path . ' ' . $query . ' ' . implode( ' ', array_map( 'strval', $names ) );

		return array(
			'url'    => $url_scope,
			'values' => $url_scope . ' ' . $values,
		);
	}

	/**
	 * Flatten an array of request parameters into a single searchable string.
	 *
	 * @param array $data Request data.
	 * @param int   $depth Current recursion depth.
	 * @return string
	 */
	private function flatten( $data, $depth = 0 ) {
		if ( ! is_array( $data ) || $depth > 4 ) {
			return '';
		}

		$parts = array();

		foreach ( $data as $key => $value ) {
			$parts[] = (string) $key;

			if ( is_array( $value ) ) {
				$parts[] = $this->flatten( $value, $depth + 1 );
			} elseif ( is_scalar( $value ) ) {
				$parts[] = (string) $value;
			}
		}

		return implode( ' ', $parts );
	}

	/**
	 * Test the request against the enabled signatures.
	 *
	 * @return void
	 */
	private function scan_request() {
		$scope    = $this->scope();
		$patterns = $this->patterns();

		foreach ( array( 'sql', 'traversal', 'rce' ) as $rule ) {
			if ( ! Settings::on( 'waf_' . $rule ) || empty( $patterns[ $rule ] ) ) {
				continue;
			}

			if ( preg_match( $patterns[ $rule ], $scope['values'] ) ) {
				$this->deny( 'waf-' . $rule );
			}
		}

		// XSS payloads only matter where they can turn into markup on the way
		// back out, so this one is scoped to the URL and parameter names.
		if ( Settings::on( 'waf_xss' ) && preg_match( $this->xss_pattern(), $scope['url'] ) ) {
			$this->deny( 'waf-xss' );
		}
	}

	/**
	 * Rate limit repeated 404 responses, which indicate directory scanning.
	 *
	 * @return void
	 */
	public function throttle_404() {
		if ( is_admin() || ! Settings::on( 'error_404_throttle' ) || ! is_404() ) {
			return;
		}

		$ip = get_client_ip();

		if ( $this->is_whitelisted( $ip ) || ! $this->address_rules_apply( $ip ) ) {
			return;
		}

		$threshold = max( 5, (int) Settings::get( 'error_404_threshold', 20 ) );
		$window    = 5 * MINUTE_IN_SECONDS;
		$count     = $this->bump_counter( 'at8sec_404_' . ip_hash( $ip ), $window );

		if ( $count > $threshold ) {
			$this->deny( '404-flood' );
		}
	}

	/**
	 * Refuse the request.
	 *
	 * A short plain-text body is used on purpose: HTML error pages are a
	 * fingerprinting aid for scanners, and this response is mostly seen by
	 * automated traffic.
	 *
	 * @param string $reason Short machine-readable reason.
	 * @return void
	 */
	private function deny( $reason ) {
		$reason = preg_replace( '/[^a-z0-9-]/', '', strtolower( (string) $reason ) );

		if ( ! headers_sent() ) {
			nocache_headers();
			status_header( 403 );
			header( 'Content-Type: text/plain; charset=utf-8' );
			header( 'X-At8-Security: ' . $reason );
		}

		exit( 'Request blocked by the site security policy.' );
	}
}
