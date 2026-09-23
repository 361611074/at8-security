<?php
/**
 * Admin UI.
 *
 * Structure note: the settings form and the tool forms are deliberately kept
 * as siblings rather than nested. The previous version put both inside one
 * form posting to options.php, so pressing "build baseline" redirected before
 * options.php could run — the settings the user had just changed were silently
 * discarded while the page still reported success.
 *
 * @package At8\Security
 */

namespace At8\Security;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Module 12 — settings screen.
 */
class Admin {

	const PAGE        = 'at8-security';
	const OPTION_GROUP = 'at8sec';
	const TOOL_ACTION = 'at8sec_tool';

	/**
	 * Register hooks.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_notices', array( $this, 'show_notices' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_post_' . self::TOOL_ACTION, array( $this, 'handle_tool' ) );
	}

	/**
	 * Add the top-level menu entry.
	 *
	 * @return void
	 */
	public function add_menu() {
		add_menu_page(
			__( 'At8 Security', 'at8-security' ),
			__( 'At8 Security', 'at8-security' ),
			'manage_options',
			self::PAGE,
			array( $this, 'render' ),
			'dashicons-shield-alt',
			81
		);
	}

	/**
	 * Register the settings option.
	 *
	 * @return void
	 */
	public function register_settings() {
		register_setting(
			self::OPTION_GROUP,
			AT8SEC_OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( 'At8\\Security\\Settings', 'sanitize' ),
				'default'           => Settings::defaults(),
				'show_in_rest'      => false,
			)
		);
	}

	/**
	 * Load styles and scripts on our screen only.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public function enqueue_assets( $hook ) {
		if ( 'toplevel_page_' . self::PAGE !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'at8sec-admin',
			AT8SEC_URL . 'assets/admin.css',
			array(),
			AT8SEC_VERSION
		);

		wp_enqueue_script(
			'at8sec-admin',
			AT8SEC_URL . 'assets/admin.js',
			array(),
			AT8SEC_VERSION,
			true
		);
	}

	/**
	 * Show queued messages.
	 *
	 * @return void
	 */
	public function show_notices() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$message = Settings::take_notice();

		if ( '' !== $message ) {
			printf(
				'<div class="notice notice-warning is-dismissible"><p>%s</p></div>',
				esc_html( $message )
			);
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only status flag.
		$status = isset( $_GET['at8sec_status'] ) ? sanitize_key( wp_unslash( $_GET['at8sec_status'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only flag.
		$detail = isset( $_GET['at8sec_detail'] ) ? sanitize_text_field( wp_unslash( $_GET['at8sec_detail'] ) ) : '';

		if ( 'ok' === $status ) {
			printf(
				'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
				esc_html( $detail )
			);
		} elseif ( 'error' === $status ) {
			printf(
				'<div class="notice notice-error is-dismissible"><p>%s</p></div>',
				esc_html( $detail )
			);
		}
	}

	/**
	 * Tab definitions.
	 *
	 * @return array<string, string>
	 */
	private function tabs() {
		$tabs = array(
			'hardening' => __( 'Hardening', 'at8-security' ),
			'login'     => __( 'Login', 'at8-security' ),
			'firewall'  => __( 'Firewall', 'at8-security' ),
			'headers'   => __( 'Headers & CSP', 'at8-security' ),
			'comments'  => __( 'Comments', 'at8-security' ),
			'tools'     => __( 'Tools', 'at8-security' ),
			'pro'       => __( 'Pro', 'at8-security' ),
		);

		/**
		 * Filter the settings screen tabs.
		 *
		 * @param array<string, string> $tabs Slug => label.
		 */
		return (array) apply_filters( 'at8sec_admin_tabs', $tabs );
	}

	/**
	 * Render the screen.
	 *
	 * @return void
	 */
	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			/*
			 * wp_die() answers 500 unless it is told otherwise, which reads as
			 * "the server broke" rather than "you may not". A permission
			 * refusal has to say 403.
			 */
			wp_die(
				esc_html__( 'You are not allowed to access this page.', 'at8-security' ),
				'',
				array( 'response' => 403 )
			);
		}

		$tabs = $this->tabs();
		?>
		<div class="wrap at8sec-wrap">
			<h1><?php esc_html_e( 'At8 Security', 'at8-security' ); ?></h1>
			<p class="at8sec-sub">
				<?php esc_html_e( 'Every protection below is off-by-default only where switching it on could lock you out. Changes are saved with the button at the bottom of each tab.', 'at8-security' ); ?>
			</p>

			<nav class="nav-tab-wrapper at8sec-tabs">
				<?php foreach ( $tabs as $slug => $label ) : ?>
					<a href="#<?php echo esc_attr( $slug ); ?>"
						class="nav-tab<?php echo 'hardening' === $slug ? ' nav-tab-active' : ''; ?>"
						data-at8sec-tab="<?php echo esc_attr( $slug ); ?>"><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</nav>

			<form method="post" action="options.php">
				<?php settings_fields( self::OPTION_GROUP ); ?>

				<?php
				foreach ( array( 'hardening', 'login', 'firewall', 'headers', 'comments' ) as $slug ) :
					if ( ! isset( $tabs[ $slug ] ) ) {
						continue;
					}
					?>
					<div class="at8sec-panel" id="at8sec-panel-<?php echo esc_attr( $slug ); ?>"
						<?php echo 'hardening' === $slug ? '' : 'style="display:none"'; ?>>
						<?php $this->render_settings_tab( $slug ); ?>
					</div>
				<?php endforeach; ?>

				<?php submit_button( __( 'Save changes', 'at8-security' ) ); ?>
			</form>

			<?php if ( isset( $tabs['tools'] ) ) : ?>
				<div class="at8sec-panel" id="at8sec-panel-tools" style="display:none">
					<?php $this->render_tools_tab(); ?>
				</div>
			<?php endif; ?>

			<?php if ( isset( $tabs['pro'] ) ) : ?>
				<div class="at8sec-panel" id="at8sec-panel-pro" style="display:none">
					<?php $this->render_pro_tab(); ?>
				</div>
			<?php endif; ?>

			<?php
			/*
			 * Panels contributed by add-ons. They sit outside the settings form
			 * above on purpose: an add-on keeps its own option and its own
			 * nonce, so it renders — and posts — its own form here.
			 */
			foreach ( $this->addon_tabs() as $slug ) :
				?>
				<div class="at8sec-panel" id="at8sec-panel-<?php echo esc_attr( $slug ); ?>" style="display:none">
					<?php
					/**
					 * Fires inside the panel container for an add-on tab.
					 *
					 * @param string $slug Tab slug.
					 */
					do_action( 'at8sec_admin_panel', $slug );
					?>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * Tabs the free plugin does not render itself.
	 *
	 * @return string[]
	 */
	private function addon_tabs() {
		$builtin = array( 'hardening', 'login', 'firewall', 'headers', 'comments', 'tools', 'pro' );
		$out     = array();

		foreach ( array_keys( $this->tabs() ) as $slug ) {
			if ( ! in_array( $slug, $builtin, true ) ) {
				$out[] = $slug;
			}
		}

		return $out;
	}

	/**
	 * Show the login address that is currently in force, right under the
	 * custom path field.
	 *
	 * @return void
	 */
	private function current_login_url_note() {
		$slug = Login_Protection::login_slug();

		if ( '' === $slug ) {
			return;
		}

		printf(
			'<p class="at8sec-row"><strong>%1$s</strong> <code>%2$s</code></p>',
			esc_html__( 'Your login address:', 'at8-security' ),
			esc_html( trailingslashit( home_url() ) . $slug )
		);
	}

	/**
	 * Render one settings tab.
	 *
	 * @param string $slug Tab slug.
	 * @return void
	 */
	private function render_settings_tab( $slug ) {
		switch ( $slug ) {
			case 'hardening':
				echo '<h2>' . esc_html__( 'Basic hardening', 'at8-security' ) . '</h2>';
				$this->toggle( 'hide_wp_version', __( 'Hide the WordPress version', 'at8-security' ), __( 'Removes the generator tag and the ?ver= query on styles and scripts.', 'at8-security' ) );
				$this->toggle( 'disable_xmlrpc', __( 'Disable XML-RPC', 'at8-security' ), __( 'Blocks brute-force attempts and pingback reflection abuse.', 'at8-security' ) );
				$this->toggle( 'disable_rest_user_enum', __( 'Hide user listings from the REST API', 'at8-security' ), __( 'Anonymous requests to /wp/v2/users return nothing.', 'at8-security' ) );
				$this->toggle( 'uniform_login_errors', __( 'Use a neutral login error', 'at8-security' ), __( 'Stops the form from revealing whether a username exists.', 'at8-security' ) );
				$this->toggle( 'disable_file_editor', __( 'Disable the built-in plugin and theme editor', 'at8-security' ), __( 'An intruder with an admin session cannot edit PHP from the dashboard.', 'at8-security' ) );
				$this->toggle( 'remove_rsd_wlw', __( 'Remove RSD and WLW links', 'at8-security' ), __( 'Drops two rarely used head tags.', 'at8-security' ) );
				$this->toggle( 'disable_author_enum', __( 'Block author enumeration', 'at8-security' ), __( 'Requests such as ?author=1 are redirected to the home page.', 'at8-security' ) );
				$this->toggle( 'disable_dir_listing', __( 'Turn off directory listing', 'at8-security' ), __( 'Writes an .htaccess block to the site root on Apache. If your host does not allow Options overrides there, the site can start answering with a 500 error — check the front page after switching this on. On Nginx, use autoindex off instead.', 'at8-security' ) );
				$this->toggle( 'uploads_disable_php', __( 'Block script execution in the uploads folder', 'at8-security' ), __( 'Writes an .htaccess file inside the uploads folder itself on Apache, so an uploaded file can never be executed. On Nginx, add a location block that denies PHP under uploads.', 'at8-security' ) );
				break;

			case 'login':
				echo '<h2>' . esc_html__( 'Login protection', 'at8-security' ) . '</h2>';
				$this->toggle( 'login_throttle', __( 'Limit failed login attempts', 'at8-security' ), __( 'Repeated failures from one address are temporarily refused.', 'at8-security' ) );
				$this->number( 'login_max_retries', __( 'Attempts allowed', 'at8-security' ) );
				$this->number( 'login_lockout_minutes', __( 'Lockout length (minutes)', 'at8-security' ) );
				$this->toggle( 'honeypot', __( 'Hidden field trap', 'at8-security' ), __( 'A field that people never see but bots happily fill in.', 'at8-security' ) );
				$this->toggle( 'login_captcha', __( 'Human check on the login form', 'at8-security' ), __( 'A short arithmetic question. Nothing is loaded from a third party.', 'at8-security' ) );
				$this->pro_note( __( 'A hosted anti-bot challenge, as an alternative to the arithmetic question,', 'at8-security' ) );
				$this->toggle( 'login_email_alert', __( 'E-mail me about sign-ins from new addresses', 'at8-security' ), __( 'Sent once per address, not on every login.', 'at8-security' ) );
				$this->email( 'login_alert_email', __( 'Alert recipient', 'at8-security' ), __( 'Leave empty to use the site administration address.', 'at8-security' ) );

				echo '<h3>' . esc_html__( 'Custom login path', 'at8-security' ) . '</h3>';
				$this->toggle( 'change_login_url', __( 'Move the login form to a custom path', 'at8-security' ), __( 'The default wp-login.php and a bare /wp-admin/ then answer with a plain 404 instead of advertising the new location.', 'at8-security' ) );
				$this->text( 'login_slug', __( 'Custom path', 'at8-security' ), __( 'Letters, numbers and hyphens only, for example my-login. After saving, the address in use is shown right below. If you lose access, add define( \'AT8SEC_DISABLE_CUSTOM_LOGIN\', true ); to wp-config.php to restore the default form.', 'at8-security' ) );
				$this->current_login_url_note();

				echo '<h3>' . esc_html__( 'Password strength', 'at8-security' ) . '</h3>';
				$this->toggle( 'pwd_policy', __( 'Require strong passwords', 'at8-security' ), __( 'Applies when a password is set or changed.', 'at8-security' ) );
				$this->number( 'pwd_min_length', __( 'Minimum length', 'at8-security' ) );
				$this->toggle( 'weak_pwd_scan', __( 'Enable the weak-password check in Tools', 'at8-security' ), '' );
				break;

			case 'firewall':
				echo '<h2>' . esc_html__( 'Firewall', 'at8-security' ) . '</h2>';
				echo '<p class="at8sec-desc">' . esc_html__( 'These rules inspect the URL and request parameters. They are a second layer, not a substitute for keeping WordPress, themes and plugins up to date.', 'at8-security' ) . '</p>';
				$this->toggle( 'waf_basic', __( 'Enable request filtering', 'at8-security' ), '' );
				$this->toggle( 'waf_sql', __( '　↳ SQL injection signatures', 'at8-security' ), '' );
				$this->toggle( 'waf_xss', __( '　↳ Cross-site scripting signatures', 'at8-security' ), __( 'Tested against the URL only, so content in posts and comments is unaffected.', 'at8-security' ) );
				$this->toggle( 'waf_traversal', __( '　↳ Directory traversal signatures', 'at8-security' ), '' );
				$this->toggle( 'waf_rce', __( '　↳ Command execution signatures', 'at8-security' ), '' );
				$this->toggle( 'block_bad_ua', __( 'Block known scanner user agents', 'at8-security' ), __( 'Security scanners and empty user agents are refused.', 'at8-security' ) );

				echo '<hr class="at8sec-hr" />';
				$this->toggle( 'rate_limit_global', __( 'Limit request rate per address', 'at8-security' ), __( 'Slows down content scraping and simple floods.', 'at8-security' ) );
				$this->number( 'rate_limit_max', __( 'Requests allowed per window', 'at8-security' ) );
				$this->number( 'rate_limit_window', __( 'Window length (seconds)', 'at8-security' ) );
				$this->toggle( 'error_404_throttle', __( 'Limit repeated 404 responses', 'at8-security' ), __( 'Directory scanning produces a burst of 404s.', 'at8-security' ) );
				$this->number( 'error_404_threshold', __( '404s allowed per five minutes', 'at8-security' ) );

				echo '<hr class="at8sec-hr" />';
				echo '<h3>' . esc_html__( 'Allow and block lists', 'at8-security' ) . '</h3>';
				$this->textarea( 'ip_blacklist', __( 'Blocked addresses', 'at8-security' ), __( 'One per line. Single addresses and CIDR ranges are both accepted.', 'at8-security' ) );
				$this->textarea( 'ip_whitelist', __( 'Always allowed addresses', 'at8-security' ), __( 'These visitors skip every check above.', 'at8-security' ) );
				$this->textarea( 'trusted_proxies', __( 'Reverse proxy or CDN addresses', 'at8-security' ), __( 'Only fill this in if your site sits behind a proxy. Forwarded IP headers are trusted from these addresses alone — which is what stops a visitor from pretending to be someone else.', 'at8-security' ) );
				break;

			case 'headers':
				echo '<h2>' . esc_html__( 'Security headers', 'at8-security' ) . '</h2>';
				$this->toggle( 'security_headers', __( 'Send hardening headers', 'at8-security' ), __( 'X-Content-Type-Options, X-Frame-Options, Referrer-Policy and Permissions-Policy.', 'at8-security' ) );
				$this->toggle( 'force_https', __( 'Redirect front-end traffic to HTTPS', 'at8-security' ), '' );
				$this->toggle( 'hsts', __( 'Enable HSTS', 'at8-security' ), __( 'Strong, and hard to undo: the browser remembers it for a year. Only turn this on once HTTPS is stable on every subdomain.', 'at8-security' ) );

				echo '<h3>' . esc_html__( 'Content Security Policy', 'at8-security' ) . '</h3>';
				$this->toggle( 'enable_csp', __( 'Send a CSP header', 'at8-security' ), '' );
				$this->toggle( 'csp_report_only', __( 'Report only (do not block)', 'at8-security' ), __( 'Recommended while you check the browser console for violations.', 'at8-security' ) );
				$this->textarea( 'csp_policy', __( 'Policy', 'at8-security' ), '' );
				break;

			case 'comments':
				echo '<h2>' . esc_html__( 'Comments', 'at8-security' ) . '</h2>';
				$this->toggle( 'comment_spam', __( 'Filter comment spam', 'at8-security' ), __( 'A hidden field plus a one-comment-per-minute limit for visitors who are not signed in.', 'at8-security' ) );
				$this->toggle( 'disable_comments', __( 'Close comments across the site', 'at8-security' ), __( 'Hides the comment form and existing comments, and removes the Comments menu. Turning it back off restores everything.', 'at8-security' ) );
				break;
		}
	}

	/**
	 * Render the Tools tab.
	 *
	 * @return void
	 */
	private function render_tools_tab() {
		/*
		 * Taken from the plugin registry rather than built here: a second
		 * Audit_Log object would re-register the module's hooks, and the
		 * registry always has this module by the time the screen renders.
		 */
		$audit = Plugin::instance()->module( 'audit_log' );
		$rows  = $audit->recent( 50 );
		?>
		<h2><?php esc_html_e( 'Maintenance tools', 'at8-security' ); ?></h2>

		<div class="at8sec-tool">
			<h3><?php esc_html_e( 'Weak-password check', 'at8-security' ); ?></h3>
			<p class="at8sec-desc"><?php esc_html_e( 'Compares stored accounts against a list of the most commonly used passwords. Verification is deliberately slow, so a large user base is checked in batches.', 'at8-security' ); ?></p>
			<?php $this->tool_form( 'weak_password_scan', __( 'Run the check', 'at8-security' ), 'secondary' ); ?>
		</div>

		<div class="at8sec-tool">
			<h3><?php esc_html_e( 'Server rules', 'at8-security' ); ?></h3>
			<p class="at8sec-desc"><?php esc_html_e( 'Re-writes the .htaccess block that backs the directory-listing and uploads settings. Use this after moving the site or editing .htaccess by hand.', 'at8-security' ); ?></p>
			<?php $this->tool_form( 'sync_server_rules', __( 'Re-apply rules', 'at8-security' ), 'secondary' ); ?>
		</div>

		<div class="at8sec-tool">
			<h3><?php esc_html_e( 'Audit log', 'at8-security' ); ?></h3>
			<p class="at8sec-desc">
				<?php
				printf(
					/* translators: %s: number of stored log entries. */
					esc_html__( 'Entries currently stored: %s', 'at8-security' ),
					esc_html( number_format_i18n( $audit->count() ) )
				);
				?>
			</p>

			<?php if ( empty( $rows ) ) : ?>
				<p class="at8sec-desc"><?php esc_html_e( 'Nothing recorded yet.', 'at8-security' ); ?></p>
			<?php else : ?>
				<table class="widefat striped at8sec-log">
					<thead>
						<tr>
							<th><?php esc_html_e( 'When', 'at8-security' ); ?></th>
							<th><?php esc_html_e( 'Event', 'at8-security' ); ?></th>
							<th><?php esc_html_e( 'Account', 'at8-security' ); ?></th>
							<th><?php esc_html_e( 'Address', 'at8-security' ); ?></th>
							<th><?php esc_html_e( 'Detail', 'at8-security' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $rows as $row ) : ?>
						<tr>
							<td><?php echo esc_html( get_date_from_gmt( (string) $row->created_at, 'Y-m-d H:i' ) ); ?></td>
							<td><?php echo esc_html( $this->event_label( (string) $row->event ) ); ?></td>
							<td><?php echo esc_html( (string) $row->user_login ); ?></td>
							<td><?php echo esc_html( (string) $row->ip ); ?></td>
							<td><?php echo esc_html( (string) $row->detail ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<?php $this->tool_form( 'clear_audit_log', __( 'Clear the log', 'at8-security' ), 'delete' ); ?>
			<?php endif; ?>
		</div>

		<div class="at8sec-tool">
			<h3><?php esc_html_e( 'Settings', 'at8-security' ); ?></h3>
			<p class="at8sec-desc"><?php esc_html_e( 'Restores every option on this screen to its default value. Nothing else is removed.', 'at8-security' ); ?></p>
			<?php $this->tool_form( 'reset_settings', __( 'Restore defaults', 'at8-security' ), 'delete' ); ?>
		</div>
		<?php
	}

	/**
	 * Render the Pro tab.
	 *
	 * The free download contains no locked features; this panel only describes
	 * a separately distributed add-on and links to its page.
	 *
	 * @return void
	 */
	private function render_pro_tab() {
		/*
		 * When the add-on is active it draws this panel itself, so the upgrade
		 * information below is skipped entirely — nothing is shown twice and
		 * the screen is not padded with copy the user no longer needs.
		 *
		 * What decides this is whether the add-on is *present*, not whether it
		 * is licensed. This panel is where a licence key is entered, so hiding
		 * it while no key is stored would leave the owner no way to activate:
		 * the gate would depend on the thing it is meant to enable. An earlier
		 * version of this file made exactly that mistake.
		 */
		if ( has_action( 'at8sec_pro_panel' ) ) {
			/**
			 * Fires in place of the upgrade information when the Pro add-on is
			 * active and has registered its own settings screen.
			 */
			do_action( 'at8sec_pro_panel' );

			return;
		}

		/**
		 * Filter the URL the Pro panel links to.
		 *
		 * @param string $url Destination URL.
		 */
		$url = (string) apply_filters( 'at8sec_pro_url', 'https://www.at8.fun/' );
		?>
		<h2><?php esc_html_e( 'At8 Security Pro', 'at8-security' ); ?></h2>

		<?php if ( is_pro() ) : ?>
			<p><?php esc_html_e( 'The Pro add-on is active on this site.', 'at8-security' ); ?></p>
		<?php else : ?>
			<p><?php esc_html_e( 'At8 Security Pro is a separate add-on for sites that need more than the essentials. It installs alongside this plugin and adds its features to these same screens — nothing on this page changes when it is absent.', 'at8-security' ); ?></p>
			<ul class="at8sec-tips">
				<li><?php esc_html_e( 'Two-factor authentication with recovery codes and remembered devices', 'at8-security' ); ?></li>
				<li><?php esc_html_e( 'A hosted anti-bot challenge as an alternative to the arithmetic question', 'at8-security' ); ?></li>
				<li><?php esc_html_e( 'Automatic detection of CDN and proxy address ranges', 'at8-security' ); ?></li>
				<li><?php esc_html_e( 'File integrity monitoring against a stored baseline', 'at8-security' ); ?></li>
				<li><?php esc_html_e( 'A report of accounts that have gone quiet, with the date each was last used', 'at8-security' ); ?></li>
			</ul>
			<p>
				<a class="button button-primary" href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener noreferrer">
					<?php esc_html_e( 'Read more about Pro', 'at8-security' ); ?>
				</a>
			</p>
		<?php endif; ?>
		<?php
	}

	/**
	 * Translate an event key for display.
	 *
	 * @param string $event Event key.
	 * @return string
	 */
	private function event_label( $event ) {
		switch ( $event ) {
			case 'login_failed':
				return __( 'Failed sign-in', 'at8-security' );
			case 'login_success':
				return __( 'Signed in', 'at8-security' );
			case 'login_locked':
				return __( 'Address locked out', 'at8-security' );
			case 'settings_saved':
				return __( 'Settings changed', 'at8-security' );
			default:
				return $event;
		}
	}

	/**
	 * Render a standalone tool form.
	 *
	 * @param string $tool     Tool identifier.
	 * @param string $label    Button label.
	 * @param string $variant  Button style: secondary or delete.
	 * @return void
	 */
	private function tool_form( $tool, $label, $variant = 'secondary' ) {
		$class = 'delete' === $variant ? 'button button-link-delete' : 'button button-' . $variant;
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="at8sec-tool-form">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::TOOL_ACTION ); ?>" />
			<input type="hidden" name="tool" value="<?php echo esc_attr( $tool ); ?>" />
			<?php wp_nonce_field( self::TOOL_ACTION ); ?>
			<button type="submit" class="<?php echo esc_attr( $class ); ?>"><?php echo esc_html( $label ); ?></button>
		</form>
		<?php
	}

	/**
	 * Handle a tool request.
	 *
	 * @return void
	 */
	public function handle_tool() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to perform this action.', 'at8-security' ), 403 );
		}

		check_admin_referer( self::TOOL_ACTION );

		$tool = isset( $_POST['tool'] ) ? sanitize_key( wp_unslash( $_POST['tool'] ) ) : '';

		switch ( $tool ) {
			case 'weak_password_scan':
				$policy = Plugin::instance()->module( 'password_policy' );
				$result = $policy->scan();

				if ( empty( $result['weak'] ) ) {
					$message = sprintf(
						/* translators: %d: number of accounts checked. */
						__( 'Checked %d account(s): none of them use a password from the common-password list.', 'at8-security' ),
						(int) $result['checked']
					);
				} else {
					$message = sprintf(
						/* translators: 1: number of accounts checked, 2: comma-separated usernames. */
						__( 'Checked %1$d account(s). These should be changed: %2$s', 'at8-security' ),
						(int) $result['checked'],
						implode( ', ', array_map( 'sanitize_user', $result['weak'] ) )
					);
				}

				if ( ! empty( $result['truncated'] ) ) {
					$message .= ' ' . __( 'The list was long, so only part of it was checked — run the tool again to continue.', 'at8-security' );
				}
				break;

			case 'sync_server_rules':
				$hardening = Plugin::instance()->module( 'hardening' );
				$hardening->sync_htaccess_rules();

				$message = __( 'Server rules re-applied. They only take effect on Apache — on Nginx, set the equivalent rules in the server block.', 'at8-security' );
				break;

			case 'clear_audit_log':
				$audit = Plugin::instance()->module( 'audit_log' );
				$audit->clear();

				$message = __( 'The audit log has been cleared.', 'at8-security' );
				break;

			case 'reset_settings':
				update_option( AT8SEC_OPTION, Settings::defaults() );

				$message = __( 'All settings have been restored to their defaults.', 'at8-security' );
				break;

			default:
				$message = __( 'Unknown action.', 'at8-security' );
				$this->redirect_back( 'error', $message );

				return;
		}

		$this->redirect_back( 'ok', $message );
	}

	/**
	 * Redirect back to the settings screen with a message.
	 *
	 * @param string $status  ok or error.
	 * @param string $message Message text.
	 * @return void
	 */
	private function redirect_back( $status, $message ) {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'          => self::PAGE,
					'at8sec_status' => $status,
					'at8sec_detail' => rawurlencode( $message ),
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * One restrained line pointing at the related Pro add-on.
	 *
	 * Used only on this plugin's own settings screen, which is the single
	 * place the directory guidelines allow an upgrade mention to live. It
	 * describes a feature that does not exist in this download at all, so
	 * nothing here is locked behind a payment.
	 *
	 * @param string $feature Feature name.
	 * @return void
	 */
	private function pro_note( $feature ) {
		if ( is_pro() ) {
			return;
		}

		printf(
			'<p class="at8sec-desc at8sec-pro-note">%s</p>',
			esc_html(
				sprintf(
					/* translators: %s: name of a feature that exists in the Pro add-on only. */
					__( '%s is available in the At8 Security Pro add-on.', 'at8-security' ),
					$feature
				)
			)
		);
	}

	/**
	 * Render an on/off row.
	 *
	 * @param string $key         Option key.
	 * @param string $label       Row label.
	 * @param string $description Optional help text.
	 * @return void
	 */
	private function toggle( $key, $label, $description = '' ) {
		$value = Settings::get( $key );
		?>
		<label class="at8sec-row">
			<input type="checkbox" name="<?php echo esc_attr( AT8SEC_OPTION ); ?>[<?php echo esc_attr( $key ); ?>]" value="1" <?php checked( (int) $value, 1 ); ?> />
			<strong><?php echo esc_html( $label ); ?></strong>
			<?php if ( '' !== $description ) : ?>
				<span class="at8sec-desc"><?php echo esc_html( $description ); ?></span>
			<?php endif; ?>
		</label>
		<?php
	}

	/**
	 * Render a number row.
	 *
	 * @param string $key         Option key.
	 * @param string $label       Row label.
	 * @param string $description Optional help text.
	 * @return void
	 */
	private function number( $key, $label, $description = '' ) {
		$schema = Settings::schema();
		$min    = isset( $schema[ $key ]['min'] ) ? (int) $schema[ $key ]['min'] : 0;
		$max    = isset( $schema[ $key ]['max'] ) ? (int) $schema[ $key ]['max'] : 0;
		?>
		<label class="at8sec-row">
			<strong><?php echo esc_html( $label ); ?></strong>
			<input type="number" name="<?php echo esc_attr( AT8SEC_OPTION ); ?>[<?php echo esc_attr( $key ); ?>]"
				value="<?php echo esc_attr( Settings::get( $key ) ); ?>"
				<?php if ( $min ) : ?>
					min="<?php echo esc_attr( (string) $min ); ?>"
				<?php endif; ?>
				<?php if ( $max ) : ?>
					max="<?php echo esc_attr( (string) $max ); ?>"
				<?php endif; ?>
				class="small-text" />
			<?php if ( '' !== $description ) : ?>
				<span class="at8sec-desc"><?php echo esc_html( $description ); ?></span>
			<?php endif; ?>
		</label>
		<?php
	}

	/**
	 * Render a text row.
	 *
	 * @param string $key         Option key.
	 * @param string $label       Row label.
	 * @param string $description Optional help text.
	 * @return void
	 */
	private function text( $key, $label, $description = '' ) {
		?>
		<label class="at8sec-row">
			<strong><?php echo esc_html( $label ); ?></strong>
			<input type="text" name="<?php echo esc_attr( AT8SEC_OPTION ); ?>[<?php echo esc_attr( $key ); ?>]"
				value="<?php echo esc_attr( Settings::get( $key ) ); ?>" class="regular-text" autocomplete="off" />
			<?php if ( '' !== $description ) : ?>
				<span class="at8sec-desc"><?php echo esc_html( $description ); ?></span>
			<?php endif; ?>
		</label>
		<?php
	}

	/**
	 * Render an e-mail row.
	 *
	 * @param string $key         Option key.
	 * @param string $label       Row label.
	 * @param string $description Optional help text.
	 * @return void
	 */
	private function email( $key, $label, $description = '' ) {
		?>
		<label class="at8sec-row">
			<strong><?php echo esc_html( $label ); ?></strong>
			<input type="email" name="<?php echo esc_attr( AT8SEC_OPTION ); ?>[<?php echo esc_attr( $key ); ?>]"
				value="<?php echo esc_attr( Settings::get( $key ) ); ?>" class="regular-text" />
			<?php if ( '' !== $description ) : ?>
				<span class="at8sec-desc"><?php echo esc_html( $description ); ?></span>
			<?php endif; ?>
		</label>
		<?php
	}

	/**
	 * Render a textarea row.
	 *
	 * @param string $key         Option key.
	 * @param string $label       Row label.
	 * @param string $description Optional help text.
	 * @return void
	 */
	private function textarea( $key, $label, $description = '' ) {
		?>
		<label class="at8sec-row at8sec-row-block">
			<strong><?php echo esc_html( $label ); ?></strong>
			<textarea name="<?php echo esc_attr( AT8SEC_OPTION ); ?>[<?php echo esc_attr( $key ); ?>]" rows="4" class="large-text code"><?php echo esc_textarea( Settings::get( $key ) ); ?></textarea>
			<?php if ( '' !== $description ) : ?>
				<span class="at8sec-desc"><?php echo esc_html( $description ); ?></span>
			<?php endif; ?>
		</label>
		<?php
	}
}
