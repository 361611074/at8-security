<?php
/**
 * The add-on's screens.
 *
 * These live inside the free plugin's settings screen rather than beside it:
 * the free plugin publishes `at8sec_admin_tabs` for extra tabs, and fires an
 * action inside each one for the add-on to draw. The owner therefore sees one
 * plugin, not two, which is the point of an add-on.
 *
 * Forms come in three kinds and are kept apart on purpose:
 *
 *   settings   -> posts to options.php with its own option group;
 *   licence    -> posts to admin-post.php, because it is an action, not a setting;
 *   tools      -> posts to admin-post.php, because a scan is not a setting either.
 *
 * Nested forms were the cause of a real bug in the free plugin's history: the
 * browser posts the outer form and the inner button's intent is lost. Nothing
 * here is nested.
 *
 * @package At8\Security\Pro
 */

namespace At8\Security\Pro;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin screens for the add-on.
 */
class Admin {

	/**
	 * The free plugin's settings screen slug.
	 */
	const PAGE = 'at8-security';

	/**
	 * Settings group for this add-on's own option.
	 */
	const OPTION_GROUP = 'at8sec_pro';

	/**
	 * admin-post action for every button on the tools and licence panels.
	 */
	const TOOL_ACTION = 'at8sec_pro_tool';

	/**
	 * Register hooks.
	 */
	public function __construct() {
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_filter( 'at8sec_admin_tabs', array( $this, 'tabs' ) );
		add_action( 'at8sec_pro_panel', array( $this, 'render_licence_panel' ) );
		add_action( 'at8sec_admin_panel', array( $this, 'render_addon_panel' ) );
		add_action( 'admin_post_' . self::TOOL_ACTION, array( $this, 'handle_tool' ) );
		add_action( 'admin_notices', array( $this, 'notices' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'edit_user_profile', array( $this, 'user_2fa_row' ) );
	}

	/* ---------------------------------------------------------------------
	 * Registration
	 * ------------------------------------------------------------------ */

	/**
	 * Register this add-on's option.
	 *
	 * A separate option from the free plugin's, and registered under a
	 * separate group. Both plugins replace their whole option on save, so
	 * sharing one row would mean either plugin wiping the other's keys.
	 *
	 * @return void
	 */
	public function register_settings() {
		register_setting(
			self::OPTION_GROUP,
			AT8SEC_PRO_OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( 'At8\\Security\\Pro\\Settings', 'sanitize' ),
				'default'           => Settings::defaults(),
				'show_in_rest'      => false,
			)
		);
	}

	/**
	 * Add the tab this add-on draws itself.
	 *
	 * The licence panel does not need a tab of its own: the free plugin's Pro
	 * tab hands its content over once the add-on is active. What is added here
	 * is the place where actions live.
	 *
	 * @param array<string, string> $tabs Tabs so far.
	 * @return array<string, string>
	 */
	public function tabs( $tabs ) {
		$tabs = (array) $tabs;
		$tabs['at8sec-pro-tools'] = __( 'Pro tools', 'at8-security-pro' );

		return $tabs;
	}

	/* ---------------------------------------------------------------------
	 * Assets
	 * ------------------------------------------------------------------ */

	/**
	 * Load the stylesheet where these screens are shown.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public function assets( $hook ) {
		$screens = array(
			'toplevel_page_' . self::PAGE,
			'profile.php',
			'user-edit.php',
		);

		if ( ! in_array( $hook, $screens, true ) ) {
			return;
		}

		wp_enqueue_style(
			'at8sec-pro-admin',
			AT8SEC_PRO_URL . 'assets/admin.css',
			array(),
			AT8SEC_PRO_VERSION
		);
	}

	/* ---------------------------------------------------------------------
	 * Notices
	 * ------------------------------------------------------------------ */

	/**
	 * Show messages queued by a licence or tool action.
	 *
	 * @return void
	 */
	public function notices() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$notice = Settings::take_notice();

		if ( '' === $notice['message'] ) {
			return;
		}

		printf(
			'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
			'error' === $notice['type'] ? 'error' : 'success',
			esc_html( $notice['message'] )
		);
	}

	/* ---------------------------------------------------------------------
	 * Panel: licence and settings
	 * ------------------------------------------------------------------ */

	/**
	 * Draw the escaped Pro tab.
	 *
	 * @return void
	 */
	public function render_licence_panel() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$this->render_licence_status();
		$this->render_licence_form();
		$this->render_settings_form();
	}

	/**
	 * The licence state, in plain words.
	 *
	 * @return void
	 */
	private function render_licence_status() {
		$record = License::get();
		$valid  = License::is_valid();
		?>
		<h2><?php esc_html_e( 'At8 Security Pro', 'at8-security-pro' ); ?></h2>

		<p class="at8sec-pro-status <?php echo $valid ? 'is-ok' : 'is-warn'; ?>">
			<strong><?php esc_html_e( 'Licence', 'at8-security-pro' ); ?>:</strong>
			<?php echo esc_html( License::status_label() ); ?>

			<?php if ( '' !== $record['key'] ) : ?>
				<span class="at8sec-pro-key"><?php echo esc_html( mask_key( $record['key'] ) ); ?></span>
			<?php endif; ?>
		</p>

		<?php if ( $record['expires'] > 0 ) : ?>
			<p class="at8sec-desc">
				<?php
				printf(
					/* translators: %s: date the licence runs out. */
					esc_html__( 'Runs until %s.', 'at8-security-pro' ),
					esc_html( $this->date( $record['expires'] ) )
				);
				?>
			</p>
		<?php elseif ( License::is_lifetime() ) : ?>
			<p class="at8sec-desc"><?php esc_html_e( 'This licence does not expire.', 'at8-security-pro' ); ?></p>
		<?php endif; ?>

		<?php if ( '' !== $record['message'] ) : ?>
			<p class="at8sec-desc"><?php echo esc_html( $record['message'] ); ?></p>
		<?php endif; ?>

		<?php if ( $record['last_ok'] > 0 ) : ?>
			<p class="at8sec-desc">
				<?php
				printf(
					/* translators: %s: date and time of the last successful check. */
					esc_html__( 'Last confirmed by the licence server: %s.', 'at8-security-pro' ),
					esc_html( $this->date( $record['last_ok'] ) )
				);
				?>
			</p>
		<?php endif; ?>

		<?php if ( dev_mode() ) : ?>
			<p class="at8sec-desc"><?php esc_html_e( 'Development mode is on in wp-config.php: no licence is checked here, and the features run regardless of the key.', 'at8-security-pro' ); ?></p>
		<?php elseif ( ! $valid && License::is_confirmed() ) : ?>
			<p class="at8sec-desc">
				<?php
				$days = (int) ceil( License::grace_remaining( AT8SEC_PRO_GRACE_DAYS ) / DAY_IN_SECONDS );
				$days = max( 0, $days );

				printf(
					/* translators: %d: number of days of protection left. */
					esc_html__( 'The licence server could not be reached, so the features keep running for another %d days. A security feature is not switched off because a licence server had a bad afternoon.', 'at8-security-pro' ),
					(int) $days
				);
				?>
			</p>
		<?php endif; ?>
		<?php
	}

	/**
	 * The licence key form.
	 *
	 * @return void
	 */
	private function render_licence_form() {
		$record = License::get();
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="at8sec-pro-licence-form">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::TOOL_ACTION ); ?>" />
			<?php wp_nonce_field( 'at8sec_pro_licence' ); ?>
			<input type="hidden" name="at8sec_pro_action" value="licence" />

			<p>
				<label for="at8sec-pro-key"><?php esc_html_e( 'Licence key', 'at8-security-pro' ); ?></label><br />
				<input type="text" id="at8sec-pro-key" name="at8sec_pro_key" class="regular-text"
					value="<?php echo esc_attr( '' === $record['key'] ? '' : mask_key( $record['key'] ) ); ?>"
					autocomplete="off" spellcheck="false" />
			</p>
			<p class="at8sec-desc">
				<?php esc_html_e( 'Paste the key you were sent. The stored key is shown shortened; typing a new one replaces it.', 'at8-security-pro' ); ?>
			</p>

			<p>
				<button type="submit" name="at8sec_pro_licence_action" value="activate" class="button button-primary">
					<?php esc_html_e( 'Activate', 'at8-security-pro' ); ?>
				</button>

				<?php if ( '' !== $record['key'] ) : ?>
					<button type="submit" name="at8sec_pro_licence_action" value="refresh" class="button">
						<?php esc_html_e( 'Check now', 'at8-security-pro' ); ?>
					</button>
					<button type="submit" name="at8sec_pro_licence_action" value="release" class="button"
						onclick="return confirm('<?php echo esc_js( __( 'Release this site from the licence? The key can then be used on a different site.', 'at8-security-pro' ) ); ?>');">
						<?php esc_html_e( 'Release this site', 'at8-security-pro' ); ?>
					</button>
				<?php endif; ?>
			</p>
		</form>
		<?php
	}

	/**
	 * The settings form.
	 *
	 * @return void
	 */
	private function render_settings_form() {
		$turnstile = Turnstile::active();
		?>
		<form method="post" action="options.php">
			<?php settings_fields( self::OPTION_GROUP ); ?>

			<h3><?php esc_html_e( 'Anti-bot challenge', 'at8-security-pro' ); ?></h3>
			<p class="at8sec-desc">
				<?php esc_html_e( 'Replaces the arithmetic question on the sign-in form with a hosted challenge. This is the one feature here that talks to a third party — the widget is loaded from Cloudflare and the answer is checked by Cloudflare. Leave it off to keep everything on this server.', 'at8-security-pro' ); ?>
			</p>
			<?php
			$this->toggle(
				'turnstile_enabled',
				__( 'Use the hosted challenge on the sign-in form', 'at8-security-pro' )
			);
			$this->text(
				'turnstile_site_key',
				__( 'Site key', 'at8-security-pro' ),
				__( 'From the Turnstile section of your Cloudflare account.', 'at8-security-pro' )
			);
			$this->secret(
				'turnstile_secret_key',
				__( 'Secret key', 'at8-security-pro' ),
				__( 'Stored on this server and never printed back into this page. Leave empty to keep the one already saved.', 'at8-security-pro' )
			);
			if ( Settings::on( 'turnstile_enabled' ) && ! Turnstile::configured() ) : ?>
				<p class="at8sec-desc at8sec-pro-status is-warn">
					<?php esc_html_e( 'Both keys are needed. Until they are filled in, the built-in arithmetic question stays in place rather than the form going without a check.', 'at8-security-pro' ); ?>
				</p>
			<?php elseif ( $turnstile ) : ?>
				<p class="at8sec-desc">
					<?php esc_html_e( 'The hosted challenge is in use on the sign-in form.', 'at8-security-pro' ); ?>
				</p>
			<?php endif; ?>

			<h3><?php esc_html_e( 'Two-factor authentication', 'at8-security-pro' ); ?></h3>
			<p class="at8sec-desc">
				<?php esc_html_e( 'Each person sets this up on their own profile screen; nothing here forces it on an account that has not been set up. The roles chosen below are the ones this site asks to use it — the account holder sees a reminder until they do.', 'at8-security-pro' ); ?>
			</p>
			<?php
			$this->toggle(
				'two_factor_enabled',
				__( 'Offer two-factor authentication on this site', 'at8-security-pro' )
			);
			$this->roles(
				'two_factor_roles',
				__( 'Roles asked to use it', 'at8-security-pro' ),
				__( 'Nobody is locked out by this list. It decides who is asked, and who is reminded.', 'at8-security-pro' )
			);
			$this->number(
				'two_factor_remember',
				__( 'Remember a browser for (days)', 'at8-security-pro' ),
				__( 'Zero switches the "remember this device" option off entirely.', 'at8-security-pro' )
			);
			?>

			<h3><?php esc_html_e( 'Address detection', 'at8-security-pro' ); ?></h3>
			<p class="at8sec-desc">
				<?php esc_html_e( 'Behind a CDN or a reverse proxy every request arrives from the same few addresses, so the rate limiter would count every visitor in the world as one person. Switching this on lets the plugin work out which network is in front of the site and read the visitor address from the header that network controls.', 'at8-security-pro' ); ?>
			</p>
			<?php
			$this->toggle(
				'cdn_auto',
				__( 'Detect CDN and proxy address ranges automatically', 'at8-security-pro' )
			);

			$hint = Cdn::untrusted_hint();

			if ( ! Settings::on( 'cdn_auto' ) && '' !== $hint ) {
				printf(
					'<p class="at8sec-desc at8sec-pro-status is-warn">%s</p>',
					esc_html(
						sprintf(
							/* translators: %s: name of the CDN detected in front of the site. */
							__( 'This site looks like it is behind %s, but the detection above is off. Until it is switched on, every visitor is seen as coming from the same address.', 'at8-security-pro' ),
							$hint
						)
					)
				);
			}
			?>

			<h3><?php esc_html_e( 'Inactive accounts', 'at8-security-pro' ); ?></h3>
			<?php
			$this->number(
				'idle_days',
				__( 'Count an account as inactive after (days)', 'at8-security-pro' ),
				__( 'Used by the report on the Pro tools tab. Nothing happens to an account automatically.', 'at8-security-pro' )
			);

			submit_button( __( 'Save changes', 'at8-security-pro' ) );
			?>
		</form>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Panel: tools
	 * ------------------------------------------------------------------ */

	/**
	 * Draw a panel contributed by this add-on.
	 *
	 * @param string $slug Tab slug.
	 * @return void
	 */
	public function render_addon_panel( $slug ) {
		if ( 'at8sec-pro-tools' !== $slug || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$this->render_cdn_panel();
		$this->render_integrity_panel();
		$this->render_accounts_panel();
	}

	/**
	 * What the address detection currently sees.
	 *
	 * @return void
	 */
	private function render_cdn_panel() {
		$state   = Cdn::state();
		$enabled = Settings::on( 'cdn_auto' );
		$live    = Cdn::detect();
		?>
		<h2><?php esc_html_e( 'Address detection', 'at8-security-pro' ); ?></h2>

		<table class="widefat striped at8sec-pro-table">
			<tbody>
				<tr>
					<th scope="row"><?php esc_html_e( 'Detection', 'at8-security-pro' ); ?></th>
					<td><?php echo $enabled ? esc_html__( 'On', 'at8-security-pro' ) : esc_html__( 'Off', 'at8-security-pro' ); ?></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'This request', 'at8-security-pro' ); ?></th>
					<td>
						<?php if ( '' !== $live ) : ?>
							<?php
							printf(
								/* translators: %s: name of the detected CDN. */
								esc_html__( 'Arrived through %s.', 'at8-security-pro' ),
								esc_html( Cdn::label( $live ) )
							);
							?>
						<?php else : ?>
							<?php esc_html_e( 'No known CDN network in front of this request.', 'at8-security-pro' ); ?>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Last recorded', 'at8-security-pro' ); ?></th>
					<td>
						<?php if ( '' !== $state['provider'] ) : ?>
							<?php
							echo esc_html( $state['label'] );

							if ( $state['at'] > 0 ) {
								echo ' &middot; ' . esc_html( $this->date( $state['at'] ) );
							}

							if ( false !== strpos( $state['provider'], '|v6' ) ) {
								echo ' &middot; ' . esc_html__( 'over IPv6', 'at8-security-pro' );
							}
							?>
						<?php else : ?>
							<?php esc_html_e( 'Nothing yet.', 'at8-security-pro' ); ?>
						<?php endif; ?>
					</td>
				</tr>
			</tbody>
		</table>

		<p class="at8sec-desc">
			<?php esc_html_e( 'The decision is made again on every request, so a site that stops using a CDN stops trusting it straight away. Only the note above is stored.', 'at8-security-pro' ); ?>
		</p>

		<p class="at8sec-desc">
			<?php
			printf(
				/* translators: %s: comma-separated list of the networks the plugin knows about. */
				esc_html__( 'Networks recognised: %s. More can be added with the at8sec_pro_cdn_providers filter.', 'at8-security-pro' ),
				esc_html( $this->provider_list() )
			);
			?>
		</p>
		<?php
	}

	/**
	 * Readable list of the known networks.
	 *
	 * @return string
	 */
	private function provider_list() {
		$labels = array();

		foreach ( Cdn::providers() as $key => $provider ) {
			$labels[] = isset( $provider['label'] ) ? (string) $provider['label'] : (string) $key;
		}

		return $labels ? implode( ', ', $labels ) : __( 'none', 'at8-security-pro' );
	}

	/**
	 * The file integrity panel.
	 *
	 * @return void
	 */
	private function render_integrity_panel() {
		$baseline = Integrity::baseline();
		$progress = Integrity::progress();
		$result   = Integrity::last_result();
		$partial  = (bool) $baseline['hashes'] && ! $baseline['complete'];
		?>
		<h2><?php esc_html_e( 'File integrity', 'at8-security-pro' ); ?></h2>

		<p class="at8sec-desc">
			<?php esc_html_e( 'A fingerprint of every core, plugin and theme file is recorded while the site is known good. Comparing against it shows what has changed since. Nothing is ever deleted or changed by this: it reports, and you decide.', 'at8-security-pro' ); ?>
		</p>
		<p class="at8sec-desc">
			<?php esc_html_e( 'Uploads, caches and backup folders are left out, because those are supposed to change.', 'at8-security-pro' ); ?>
		</p>

		<table class="widefat striped at8sec-pro-table">
			<tbody>
				<tr>
					<th scope="row"><?php esc_html_e( 'Baseline', 'at8-security-pro' ); ?></th>
					<td>
						<?php if ( $baseline['hashes'] ) : ?>
							<?php
							printf(
								/* translators: 1: number of files, 2: date the baseline was taken. */
								esc_html__( '%1$s files, recorded %2$s.', 'at8-security-pro' ),
								esc_html( number_format_i18n( count( $baseline['hashes'] ) ) ),
								esc_html( $this->date( $baseline['built'] ) )
							);
							?>
						<?php else : ?>
							<?php esc_html_e( 'None yet.', 'at8-security-pro' ); ?>
						<?php endif; ?>
					</td>
				</tr>

				<?php if ( $partial && $progress['total'] > 0 ) : ?>
					<tr>
						<th scope="row"><?php esc_html_e( 'Progress', 'at8-security-pro' ); ?></th>
						<td>
							<?php
							printf(
								/* translators: 1: files recorded so far, 2: total files. */
								esc_html__( '%1$s of %2$s files. This site has more files than one run reads, so press the button again to carry on — it resumes rather than starting over.', 'at8-security-pro' ),
								esc_html( number_format_i18n( $progress['next'] ) ),
								esc_html( number_format_i18n( $progress['total'] ) )
							);
							?>
						</td>
					</tr>
				<?php endif; ?>
			</tbody>
		</table>

		<?php if ( $partial ) : ?>
			<p class="at8sec-pro-status is-warn">
				<?php esc_html_e( 'A partial baseline cannot be compared with. Every file it has not reached would look like a file that has been deleted, so comparing is held back until the baseline is complete.', 'at8-security-pro' ); ?>
			</p>
		<?php endif; ?>

		<div class="at8sec-pro-actions">
			<?php
			$this->tool_form(
				'integrity_baseline',
				$partial
					? __( 'Carry on recording the baseline?', 'at8-security-pro' )
					: __( 'Record the site as it is now? A baseline that already exists is replaced.', 'at8-security-pro' ),
				$partial
					? __( 'Continues from where the last run stopped.', 'at8-security-pro' )
					: __( 'Do this when you are satisfied the site is clean.', 'at8-security-pro' ),
				$partial
					? __( 'Carry on', 'at8-security-pro' )
					: ( $baseline['hashes'] ? __( 'Build again', 'at8-security-pro' ) : __( 'Build baseline', 'at8-security-pro' ) ),
				'button-primary'
			);

			if ( $baseline['hashes'] && $baseline['complete'] ) {
				$this->tool_form(
					'integrity_compare',
					__( 'Read every file again and list what differs?', 'at8-security-pro' ),
					__( 'Read every file again and list what differs.', 'at8-security-pro' ),
					__( 'Compare now', 'at8-security-pro' ),
					'button'
				);
			}

			if ( $baseline['hashes'] ) {
				$this->tool_form(
					'integrity_forget',
					__( 'Remove the stored fingerprints and the last result?', 'at8-security-pro' ),
					__( 'Removes the stored fingerprints and the last result.', 'at8-security-pro' ),
					__( 'Delete baseline', 'at8-security-pro' ),
					'button'
				);
			}
			?>
		</div>

		<?php if ( $result['at'] > 0 ) : ?>
			<h3><?php esc_html_e( 'Last comparison', 'at8-security-pro' ); ?></h3>

			<p class="at8sec-desc">
				<?php
				printf(
					/* translators: %s: date and time of the comparison. */
					esc_html__( 'Run %s.', 'at8-security-pro' ),
					esc_html( $this->date( $result['at'] ) )
				);
				?>
			</p>

			<?php if ( ! $result['complete'] ) : ?>
				<p class="at8sec-pro-status is-warn">
					<?php esc_html_e( 'The scan was capped before it finished, so some files were not examined. The lists below are a lower bound.', 'at8-security-pro' ); ?>
				</p>
			<?php endif; ?>

			<?php
			$added   = count( $result['added'] );
			$changed = count( $result['changed'] );
			$removed = count( $result['removed'] );

			if ( 0 === $added && 0 === $changed && 0 === $removed ) :
				?>
				<p class="at8sec-pro-status is-ok">
					<?php esc_html_e( 'Every file matches the baseline.', 'at8-security-pro' ); ?>
				</p>
				<?php
			else :
				?>
				<table class="widefat striped at8sec-pro-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'What', 'at8-security-pro' ); ?></th>
							<th><?php esc_html_e( 'How many', 'at8-security-pro' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<tr>
							<td><?php esc_html_e( 'Files that appeared', 'at8-security-pro' ); ?></td>
							<td><?php echo esc_html( number_format_i18n( $added ) ); ?></td>
						</tr>
						<tr>
							<td><?php esc_html_e( 'Files whose contents differ', 'at8-security-pro' ); ?></td>
							<td><?php echo esc_html( number_format_i18n( $changed ) ); ?></td>
						</tr>
						<tr>
							<td><?php esc_html_e( 'Files that have gone', 'at8-security-pro' ); ?></td>
							<td><?php echo esc_html( number_format_i18n( $removed ) ); ?></td>
						</tr>
					</tbody>
				</table>

				<p class="at8sec-desc">
					<?php esc_html_e( 'Changes under Plugins or Themes usually mean an update was installed — check the plugin list against what you last installed before reading anything into them. Changes under WordPress core are only expected after a core update. A file that appeared in a folder that nothing writes to is the one worth opening.', 'at8-security-pro' ); ?>
				</p>

				<?php
				$this->render_file_list( __( 'Files that appeared', 'at8-security-pro' ), $result['added'] );
				$this->render_file_list( __( 'Files whose contents differ', 'at8-security-pro' ), $result['changed'] );
				$this->render_file_list( __( 'Files that have gone', 'at8-security-pro' ), $result['removed'] );
			endif;
			?>
		<?php endif; ?>
		<?php
	}

	/**
	 * Print one capped, grouped list of paths.
	 *
	 * @param string   $title Heading.
	 * @param string[] $paths Relative paths.
	 * @return void
	 */
	private function render_file_list( $title, $paths ) {
		if ( ! $paths ) {
			return;
		}

		$limit = 50;
		$shown = array_slice( $paths, 0, $limit );

		$groups = array();

		foreach ( $shown as $path ) {
			$groups[ Integrity::area( $path ) ][] = $path;
		}

		$names = array(
			'core'    => __( 'WordPress core', 'at8-security-pro' ),
			'plugins' => __( 'Plugins', 'at8-security-pro' ),
			'themes'  => __( 'Themes', 'at8-security-pro' ),
		);

		printf( '<h4>%s</h4>', esc_html( $title ) );

		foreach ( $groups as $area => $files ) {
			printf(
				'<p class="at8sec-desc"><strong>%s</strong></p><ul class="at8sec-pro-files">',
				esc_html( isset( $names[ $area ] ) ? $names[ $area ] : $area )
			);

			foreach ( $files as $file ) {
				printf( '<li><code>%s</code></li>', esc_html( $file ) );
			}

			echo '</ul>';
		}

		if ( count( $paths ) > $limit ) {
			printf(
				'<p class="at8sec-desc">%s</p>',
				esc_html(
					sprintf(
						/* translators: 1: number shown, 2: total number. */
						__( 'Showing the first %1$s of %2$s.', 'at8-security-pro' ),
						number_format_i18n( $limit ),
						number_format_i18n( count( $paths ) )
					)
				)
			);
		}
	}

	/**
	 * The inactive-account panel.
	 *
	 * @return void
	 */
	private function render_accounts_panel() {
		$report = Accounts::report();
		?>
		<h2><?php esc_html_e( 'Inactive accounts', 'at8-security-pro' ); ?></h2>

		<p class="at8sec-desc">
			<?php
			printf(
				/* translators: 1: number of days, 2: total number of accounts. */
				esc_html__( 'Accounts not used for %1$s days or more, out of %2$s on this site. Nothing is changed automatically: a quiet account may be one you use twice a year, and only you know which.', 'at8-security-pro' ),
				esc_html( number_format_i18n( $report['threshold'] ) ),
				esc_html( number_format_i18n( $report['total'] ) )
			);
			?>
		</p>

		<p class="at8sec-desc">
			<?php esc_html_e( 'When an account was last used is recorded by this plugin, so the first list can only go back as far as the add-on has been installed. The second list is accounts nothing has been recorded for at all.', 'at8-security-pro' ); ?>
		</p>

		<h3><?php esc_html_e( 'Quiet for longer than the threshold', 'at8-security-pro' ); ?></h3>

		<?php if ( ! $report['idle'] ) : ?>
			<p class="at8sec-pro-status is-ok"><?php esc_html_e( 'None.', 'at8-security-pro' ); ?></p>
		<?php else : ?>
			<table class="widefat striped at8sec-pro-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Account', 'at8-security-pro' ); ?></th>
						<th><?php esc_html_e( 'Role', 'at8-security-pro' ); ?></th>
						<th><?php esc_html_e( 'Last used', 'at8-security-pro' ); ?></th>
						<th><?php esc_html_e( 'Days', 'at8-security-pro' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $report['idle'] as $row ) : ?>
						<tr>
							<td>
								<a href="<?php echo esc_url( get_edit_user_link( $row['user']->ID ) ); ?>">
									<?php echo esc_html( $row['user']->user_login ); ?>
								</a>
								<?php if ( $row['user']->display_name !== $row['user']->user_login ) : ?>
									<br /><span class="at8sec-desc"><?php echo esc_html( $row['user']->display_name ); ?></span>
								<?php endif; ?>
							</td>
							<td><?php echo esc_html( Accounts::role_label( $row['user'] ) ); ?></td>
							<td><?php echo esc_html( $this->date( $row['last'] ) ); ?></td>
							<td><?php echo esc_html( number_format_i18n( $row['days'] ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>

		<h3><?php esc_html_e( 'Nothing recorded', 'at8-security-pro' ); ?></h3>

		<?php if ( ! $report['never'] ) : ?>
			<p class="at8sec-pro-status is-ok"><?php esc_html_e( 'None.', 'at8-security-pro' ); ?></p>
		<?php else : ?>
			<table class="widefat striped at8sec-pro-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Account', 'at8-security-pro' ); ?></th>
						<th><?php esc_html_e( 'Role', 'at8-security-pro' ); ?></th>
						<th><?php esc_html_e( 'Registered', 'at8-security-pro' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $report['never'] as $user ) : ?>
						<tr>
							<td>
								<a href="<?php echo esc_url( get_edit_user_link( $user->ID ) ); ?>">
									<?php echo esc_html( $user->user_login ); ?>
								</a>
							</td>
							<td><?php echo esc_html( Accounts::role_label( $user ) ); ?></td>
							<td>
								<?php
								echo esc_html(
									mysql2date( get_option( 'date_format' ), $user->user_registered )
								);
								?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Actions
	 * ------------------------------------------------------------------ */

	/**
	 * Print one button as its own tiny form.
	 *
	 * @param string $action      Action key.
	 * @param string $confirm     Confirmation question.
	 * @param string $description Help text.
	 * @param string $label       Button label.
	 * @param string $class       Button classes.
	 * @return void
	 */
	private function tool_form( $action, $confirm, $description, $label, $class ) {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="at8sec-pro-inline-form">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::TOOL_ACTION ); ?>" />
			<input type="hidden" name="at8sec_pro_action" value="<?php echo esc_attr( $action ); ?>" />
			<?php wp_nonce_field( 'at8sec_pro_' . $action ); ?>
			<button type="submit" class="<?php echo esc_attr( $class ); ?>"
				onclick="return confirm('<?php echo esc_js( $confirm ); ?>');">
				<?php echo esc_html( $label ); ?>
			</button>
			<span class="at8sec-desc"><?php echo esc_html( $description ); ?></span>
		</form>
		<?php
	}

	/**
	 * Run whichever action was posted.
	 *
	 * @return void
	 */
	public function handle_tool() {
		if ( ! current_user_can( 'manage_options' ) ) {
			// 403, not the 500 wp_die() answers with by default: this is a
			// refusal, not a broken server.
			wp_die(
				esc_html__( 'You are not allowed to do that.', 'at8-security-pro' ),
				'',
				array( 'response' => 403 )
			);
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked on the next line.
		$action = isset( $_POST['at8sec_pro_action'] ) ? sanitize_key( wp_unslash( $_POST['at8sec_pro_action'] ) ) : '';

		if ( '' === $action ) {
			wp_die(
				esc_html__( 'No action was requested.', 'at8-security-pro' ),
				'',
				array( 'response' => 400 )
			);
		}

		check_admin_referer( 'at8sec_pro_' . $action );

		switch ( $action ) {
			case 'licence':
				$this->handle_licence();
				break;

			case 'integrity_baseline':
				$step = Integrity::step();

				if ( $step['complete'] ) {
					Settings::queue_notice(
						sprintf(
							/* translators: %s: number of files recorded. */
							__( 'Baseline recorded: %s files.', 'at8-security-pro' ),
							number_format_i18n( $step['stored'] )
						)
					);
				} else {
					Settings::queue_notice(
						sprintf(
							/* translators: 1: files recorded so far, 2: total files, 3: files recorded in this run. */
							__( '%1$s of %2$s files recorded. Press the button again and it will carry on from where it stopped — nothing already recorded is read twice.', 'at8-security-pro' ),
							number_format_i18n( $step['next'] ),
							number_format_i18n( $step['total'] ),
							number_format_i18n( $step['hashed'] )
						),
						'error'
					);
				}
				break;

			case 'integrity_compare':
				$result = Integrity::compare();

				Settings::queue_notice(
					$result['complete']
						? sprintf(
							/* translators: 1: files that appeared, 2: files that differ, 3: files that have gone. */
							__( 'Comparison finished: %1$s appeared, %2$s differ, %3$s have gone.', 'at8-security-pro' ),
							number_format_i18n( count( $result['added'] ) ),
							number_format_i18n( count( $result['changed'] ) ),
							number_format_i18n( count( $result['removed'] ) )
						)
						: __( 'The comparison was capped before it finished, so the lists below are a lower bound.', 'at8-security-pro' ),
					$result['complete'] ? 'success' : 'error'
				);
				break;

			case 'integrity_forget':
				Integrity::forget();
				Settings::queue_notice( __( 'The baseline and the last comparison have been removed.', 'at8-security-pro' ) );
				break;

			case 'two_factor_reset':
				$user_id = isset( $_POST['at8sec_pro_user'] ) ? (int) $_POST['at8sec_pro_user'] : 0;

				if ( $user_id && get_userdata( $user_id ) ) {
					Two_Factor::disable_for_user( $user_id );
					Settings::queue_notice( __( 'Two-factor authentication has been turned off for that account, and its recovery codes have been discarded.', 'at8-security-pro' ) );
				}
				break;
		}

		wp_safe_redirect( $this->return_url( $action ) );
		exit;
	}

	/**
	 * Where to send the browser back to after an action.
	 *
	 * The tab is named in the URL fragment, which is the mechanism the free
	 * plugin's screen already uses to reopen a tab — so a button pressed on the
	 * tools tab comes back to the tools tab rather than dumping the owner at
	 * the top of the first one.
	 *
	 * @param string $action Action key.
	 * @return string
	 */
	private function return_url( $action ) {
		if ( 'two_factor_reset' === $action ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- reading the screen we came from.
			$user_id = isset( $_POST['at8sec_pro_user'] ) ? (int) $_POST['at8sec_pro_user'] : 0;

			return $user_id ? get_edit_user_link( $user_id ) : admin_url( 'users.php' );
		}

		$tab = 'licence' === $action ? 'pro' : 'at8sec-pro-tools';

		return admin_url( 'admin.php?page=' . self::PAGE ) . '#' . $tab;
	}

	/**
	 * Activate, re-check or release the licence.
	 *
	 * @return void
	 */
	private function handle_licence() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked by the caller.
		$what = isset( $_POST['at8sec_pro_licence_action'] ) ? sanitize_key( wp_unslash( $_POST['at8sec_pro_licence_action'] ) ) : 'activate';

		if ( 'release' === $what ) {
			$result = License::release();
			Updater::clear_cache();

			Settings::queue_notice(
				is_wp_error( $result )
					? $result->get_error_message()
					: __( 'This site has been released from the licence. The key can now be used elsewhere.', 'at8-security-pro' ),
				is_wp_error( $result ) ? 'error' : 'success'
			);

			return;
		}

		if ( 'refresh' === $what ) {
			$result = License::refresh();

			Settings::queue_notice(
				is_wp_error( $result )
					? $result->get_error_message()
					: __( 'The licence server confirmed this key.', 'at8-security-pro' ),
				is_wp_error( $result ) ? 'error' : 'success'
			);

			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked by the caller.
		$submitted = isset( $_POST['at8sec_pro_key'] ) ? sanitize_text_field( wp_unslash( $_POST['at8sec_pro_key'] ) ) : '';

		/*
		 * The form prints the key shortened, so a submission that still looks
		 * like the shortened form means the owner pressed Activate without
		 * typing a new key. Re-activating the stored one is the right reading.
		 */
		if ( '' !== $submitted && false !== strpos( $submitted, '*' ) ) {
			$submitted = License::get()['key'];
		}

		$result = License::activate( $submitted );
		Updater::clear_cache();

		Settings::queue_notice(
			is_wp_error( $result )
				? $result->get_error_message()
				: __( 'The licence server accepted this key for this site.', 'at8-security-pro' ),
			is_wp_error( $result ) ? 'error' : 'success'
		);
	}

	/* ---------------------------------------------------------------------
	 * Per-user screen
	 * ------------------------------------------------------------------ */

	/**
	 * Offer an administrator a way to reset another account's two-factor.
	 *
	 * This exists for the ordinary case: somebody loses their phone and their
	 * recovery codes together. Without it the only route back in is through
	 * the database, which is a much worse thing to ask an owner to do.
	 *
	 * @param \WP_User $user User being edited.
	 * @return void
	 */
	public function user_2fa_row( $user ) {
		if ( ! current_user_can( 'edit_user', $user->ID ) || ! Settings::on( 'two_factor_enabled' ) ) {
			return;
		}

		if ( get_current_user_id() === $user->ID ) {
			// Your own account is managed on your own profile, where the
			// enrolment steps live.
			return;
		}
		?>
		<h2><?php esc_html_e( 'Two-factor authentication', 'at8-security-pro' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Status', 'at8-security-pro' ); ?></th>
				<td>
					<?php
					printf(
						'<p>%s</p>',
						esc_html(
							Two_Factor::is_enrolled( $user->ID )
								? sprintf(
									/* translators: %d: number of unused recovery codes. */
									__( 'On. Recovery codes left: %d.', 'at8-security-pro' ),
									Two_Factor::recovery_remaining( $user->ID )
								)
								: __( 'Off.', 'at8-security-pro' )
						)
					);
					?>

					<?php if ( Two_Factor::is_enrolled( $user->ID ) ) : ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<input type="hidden" name="action" value="<?php echo esc_attr( self::TOOL_ACTION ); ?>" />
							<input type="hidden" name="at8sec_pro_action" value="two_factor_reset" />
							<input type="hidden" name="at8sec_pro_user" value="<?php echo esc_attr( (string) $user->ID ); ?>" />
							<?php wp_nonce_field( 'at8sec_pro_two_factor_reset' ); ?>
							<button type="submit" class="button"
								onclick="return confirm('<?php echo esc_js( __( 'Turn two-factor authentication off for this account? Their recovery codes are discarded as well.', 'at8-security-pro' ) ); ?>');">
								<?php esc_html_e( 'Reset two-factor for this account', 'at8-security-pro' ); ?>
							</button>
						</form>
						<p class="at8sec-desc">
							<?php esc_html_e( 'Use this when the account holder has lost both their authenticator and their recovery codes — for example when their phone is gone. It leaves the account protected by its password alone until they set two-factor up again.', 'at8-security-pro' ); ?>
						</p>
					<?php endif; ?>
				</td>
			</tr>
		</table>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Field helpers
	 * ------------------------------------------------------------------ */

	/**
	 * Print a timestamp in the site's own format.
	 *
	 * @param int $timestamp Unix time.
	 * @return string
	 */
	private function date( $timestamp ) {
		$timestamp = (int) $timestamp;

		if ( $timestamp < 1 ) {
			return __( 'never', 'at8-security-pro' );
		}

		return (string) wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp );
	}

	/**
	 * Render an on/off row.
	 *
	 * @param string $key   Option key.
	 * @param string $label Row label.
	 * @return void
	 */
	private function toggle( $key, $label ) {
		?>
		<label class="at8sec-row">
			<input type="checkbox" name="<?php echo esc_attr( AT8SEC_PRO_OPTION ); ?>[<?php echo esc_attr( $key ); ?>]"
				value="1" <?php checked( Settings::on( $key ) ); ?> />
			<strong><?php echo esc_html( $label ); ?></strong>
		</label>
		<?php
	}

	/**
	 * Render a text row.
	 *
	 * @param string $key         Option key.
	 * @param string $label       Row label.
	 * @param string $description Help text.
	 * @return void
	 */
	private function text( $key, $label, $description = '' ) {
		?>
		<p class="at8sec-row">
			<label for="at8sec-<?php echo esc_attr( $key ); ?>"><strong><?php echo esc_html( $label ); ?></strong></label><br />
			<input type="text" class="regular-text" id="at8sec-<?php echo esc_attr( $key ); ?>"
				name="<?php echo esc_attr( AT8SEC_PRO_OPTION ); ?>[<?php echo esc_attr( $key ); ?>]"
				value="<?php echo esc_attr( (string) Settings::get( $key, '' ) ); ?>" autocomplete="off" spellcheck="false" />
			<?php if ( '' !== $description ) : ?>
				<span class="at8sec-desc"><?php echo esc_html( $description ); ?></span>
			<?php endif; ?>
		</p>
		<?php
	}

	/**
	 * Render a write-only secret row.
	 *
	 * The stored value is never printed back, so leaving the field empty means
	 * "keep what is saved" rather than "erase it".
	 *
	 * @param string $key         Option key.
	 * @param string $label       Row label.
	 * @param string $description Help text.
	 * @return void
	 */
	private function secret( $key, $label, $description = '' ) {
		$stored = (string) Settings::get( $key, '' );
		?>
		<p class="at8sec-row">
			<label for="at8sec-<?php echo esc_attr( $key ); ?>"><strong><?php echo esc_html( $label ); ?></strong></label><br />
			<input type="password" class="regular-text" id="at8sec-<?php echo esc_attr( $key ); ?>"
				name="<?php echo esc_attr( AT8SEC_PRO_OPTION ); ?>[<?php echo esc_attr( $key ); ?>]"
				value="" autocomplete="new-password" spellcheck="false" />
			<?php if ( '' !== $stored ) : ?>
				<span class="at8sec-desc"><?php esc_html_e( 'Saved.', 'at8-security-pro' ); ?></span>
			<?php endif; ?>
			<?php if ( '' !== $description ) : ?>
				<span class="at8sec-desc"><?php echo esc_html( $description ); ?></span>
			<?php endif; ?>
		</p>
		<?php
	}

	/**
	 * Render a number row.
	 *
	 * @param string $key         Option key.
	 * @param string $label       Row label.
	 * @param string $description Help text.
	 * @return void
	 */
	private function number( $key, $label, $description = '' ) {
		$schema = Settings::schema();
		$min    = isset( $schema[ $key ]['min'] ) ? (int) $schema[ $key ]['min'] : 0;
		$max    = isset( $schema[ $key ]['max'] ) ? (int) $schema[ $key ]['max'] : 0;
		?>
		<p class="at8sec-row">
			<label for="at8sec-<?php echo esc_attr( $key ); ?>"><strong><?php echo esc_html( $label ); ?></strong></label><br />
			<input type="number" id="at8sec-<?php echo esc_attr( $key ); ?>"
				name="<?php echo esc_attr( AT8SEC_PRO_OPTION ); ?>[<?php echo esc_attr( $key ); ?>]"
				value="<?php echo esc_attr( (string) (int) Settings::get( $key, 0 ) ); ?>"
				<?php if ( $min ) : ?>min="<?php echo esc_attr( (string) $min ); ?>"<?php endif; ?>
				<?php if ( $max ) : ?>max="<?php echo esc_attr( (string) $max ); ?>"<?php endif; ?>
				class="small-text" />
			<?php if ( '' !== $description ) : ?>
				<span class="at8sec-desc"><?php echo esc_html( $description ); ?></span>
			<?php endif; ?>
		</p>
		<?php
	}

	/**
	 * Render a role checklist.
	 *
	 * @param string $key         Option key.
	 * @param string $label       Row label.
	 * @param string $description Help text.
	 * @return void
	 */
	private function roles( $key, $label, $description = '' ) {
		$selected = (array) Settings::get( $key, array() );
		$names    = wp_roles()->get_names();
		?>
		<fieldset class="at8sec-row">
			<legend><strong><?php echo esc_html( $label ); ?></strong></legend>
			<?php foreach ( $names as $role => $name ) : ?>
				<label class="at8sec-role-line">
					<input type="checkbox"
						name="<?php echo esc_attr( AT8SEC_PRO_OPTION ); ?>[<?php echo esc_attr( $key ); ?>][]"
						value="<?php echo esc_attr( $role ); ?>"
						<?php checked( in_array( $role, $selected, true ) ); ?> />
					<?php echo esc_html( translate_user_role( $name ) ); ?>
				</label>
			<?php endforeach; ?>
			<?php if ( '' !== $description ) : ?>
				<p class="at8sec-desc"><?php echo esc_html( $description ); ?></p>
			<?php endif; ?>
		</fieldset>
		<?php
	}
}
