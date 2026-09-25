<?php
/**
 * The admin screens: issue keys, watch the sites using them, publish builds.
 *
 * @package At8\License
 */

namespace At8\License;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Menu, forms and tables.
 */
class Admin {

	const SLUG     = 'at8-license-server';
	const ACTION   = 'at8ls_action';
	const CAP      = 'manage_options';
	const PAGE_QS  = 'page=' . self::SLUG;

	/**
	 * How long a freshly minted key stays available to be read once.
	 *
	 * The key is shown on the screen that follows the create form, so the
	 * window only has to survive a redirect plus the time it takes to copy
	 * five groups of characters. Five minutes is generous for that and short
	 * enough that a forgotten tab is not a copy of the key sitting in the
	 * options table for the rest of the week.
	 */
	const NEW_KEY_TTL = 300;

	/**
	 * Register hooks.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle' ) );
	}

	/**
	 * Add the menu entry.
	 *
	 * @return void
	 */
	public function menu() {
		add_menu_page(
			__( 'At8 Licences', 'at8-license-server' ),
			__( 'At8 Licences', 'at8-license-server' ),
			self::CAP,
			self::SLUG,
			array( $this, 'render' ),
			'dashicons-lock',
			58
		);
	}

	/**
	 * Load the stylesheet, and only on this plugin's screen.
	 *
	 * @param string $hook Current admin page.
	 * @return void
	 */
	public function assets( $hook ) {
		if ( 'toplevel_page_' . self::SLUG !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'at8ls-admin',
			AT8LS_URL . 'assets/admin.css',
			array(),
			AT8LS_VERSION
		);
	}

	/**
	 * Which tab is open.
	 *
	 * @return string
	 */
	private function tab() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only navigation.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'licenses';

		return in_array( $tab, array( 'licenses', 'sites', 'release', 'audit' ), true ) ? $tab : 'licenses';
	}

	/**
	 * Base URL of a tab.
	 *
	 * @param string $tab Tab slug.
	 * @return string
	 */
	private function tab_url( $tab ) {
		return add_query_arg(
			array(
				'page' => self::SLUG,
				'tab'  => $tab,
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Draw the screen.
	 *
	 * @return void
	 */
	public function render() {
		if ( ! current_user_can( self::CAP ) ) {
			$this->refuse();
		}

		$tab = $this->tab();

		?>
		<div class="wrap at8ls-wrap">
			<h1><?php esc_html_e( 'At8 Licences', 'at8-license-server' ); ?></h1>
			<?php
			$this->notice();
			$this->schema_check();
			?>
			<nav class="nav-tab-wrapper at8ls-tabs">
				<a class="nav-tab <?php echo 'licenses' === $tab ? 'nav-tab-active' : ''; ?>"
					href="<?php echo esc_url( $this->tab_url( 'licenses' ) ); ?>">
					<?php esc_html_e( 'Licence keys', 'at8-license-server' ); ?>
				</a>
				<a class="nav-tab <?php echo 'sites' === $tab ? 'nav-tab-active' : ''; ?>"
					href="<?php echo esc_url( $this->tab_url( 'sites' ) ); ?>">
					<?php esc_html_e( 'Activated sites', 'at8-license-server' ); ?>
				</a>
				<a class="nav-tab <?php echo 'release' === $tab ? 'nav-tab-active' : ''; ?>"
					href="<?php echo esc_url( $this->tab_url( 'release' ) ); ?>">
					<?php esc_html_e( 'Releases', 'at8-license-server' ); ?>
				</a>
				<a class="nav-tab <?php echo 'audit' === $tab ? 'nav-tab-active' : ''; ?>"
					href="<?php echo esc_url( $this->tab_url( 'audit' ) ); ?>">
					<?php esc_html_e( 'Audit log', 'at8-license-server' ); ?>
				</a>
			</nav>
			<?php
			if ( 'sites' === $tab ) {
				$this->sites_tab();
			} elseif ( 'release' === $tab ) {
				$this->release_tab();
			} elseif ( 'audit' === $tab ) {
				$this->audit_tab();
			} else {
				$this->licenses_tab();
			}
			?>
		</div>
		<?php
	}

	/**
	 * Confirmation or error message left by the last action.
	 *
	 * @return void
	 */
	private function notice() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only notice code.
		$code = isset( $_GET['at8ls_notice'] ) ? sanitize_key( wp_unslash( $_GET['at8ls_notice'] ) ) : '';

		if ( '' === $code ) {
			return;
		}

		$messages = array(
			'issued'    => array( 'success', __( 'Licence key created.', 'at8-license-server' ) ),
			'updated'   => array( 'success', __( 'Licence updated.', 'at8-license-server' ) ),
			'deleted'   => array( 'success', __( 'Licence deleted.', 'at8-license-server' ) ),
			'released'  => array( 'success', __( 'The site was released from its key.', 'at8-license-server' ) ),
			'published' => array( 'success', __( 'Release saved.', 'at8-license-server' ) ),
			'forgot'    => array( 'success', __( 'Release withdrawn. The file was left on disk.', 'at8-license-server' ) ),
			'cleared'   => array( 'success', __( 'The audit log has been cleared.', 'at8-license-server' ) ),
			'failed'    => array( 'error', __( 'That did not work. Please check the values and try again.', 'at8-license-server' ) ),
		);

		if ( ! isset( $messages[ $code ] ) ) {
			return;
		}

		printf(
			'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
			esc_attr( $messages[ $code ][0] ),
			esc_html( $messages[ $code ][1] )
		);

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only notice detail.
		if ( 'failed' === $code && isset( $_GET['at8ls_why'] ) ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Recommended -- Escaped on the next line; the sniff cannot follow the nesting.
			$why = sanitize_text_field( rawurldecode( (string) wp_unslash( $_GET['at8ls_why'] ) ) );

			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html( $why )
			);
		}
	}

	/**
	 * Show a key that was just created, once.
	 *
	 * This is the only place a key is ever readable. The table stores an HMAC
	 * and a five-character tail, so if this screen is missed there is nothing
	 * to go back to — the honest answer is a new key, and the notice says so
	 * rather than letting an operator assume it can be looked up later.
	 *
	 * Called from licenses_tab(), so it renders on any visit to that screen
	 * while a key is waiting, not only on the visit that follows the form.
	 *
	 * @return void
	 */
	private function show_new_key() {
		$key = $this->take_new_key();

		if ( '' === $key ) {
			return;
		}
		?>
		<div class="notice notice-success at8ls-newkey">
			<p><strong><?php esc_html_e( 'Copy this key now.', 'at8-license-server' ); ?></strong></p>
			<p><code class="at8ls-key at8ls-key-full"><?php echo esc_html( $key ); ?></code></p>
			<p class="at8ls-desc">
				<?php esc_html_e( 'This is the only time the key is shown. Only a hash of it is stored, so it cannot be looked up again — if it is lost, create a new key and revoke this one.', 'at8-license-server' ); ?>
			</p>
		</div>
		<?php
	}

	/**
	 * Warn — and offer a fix — when the tables are not there.
	 *
	 * The tables are created on activation, but a site restored from a partial
	 * backup can lose them, and the licence endpoint would then answer nothing
	 * but database errors. Saying so here beats diagnosing it from a customer's
	 * screen.
	 *
	 * @return void
	 */
	private function schema_check() {
		if ( Db::ready() ) {
			return;
		}
		?>
		<div class="notice notice-error">
			<p><?php esc_html_e( 'The licence tables are missing, so keys cannot be issued or checked.', 'at8-license-server' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
				<input type="hidden" name="at8ls_task" value="repair">
				<?php wp_nonce_field( self::ACTION . '_repair' ); ?>
				<p>
					<button type="submit" class="button button-primary">
						<?php esc_html_e( 'Create the tables', 'at8-license-server' ); ?>
					</button>
				</p>
			</form>
		</div>
		<?php
	}

	/**
	 * Tab 1: the keys.
	 *
	 * @return void
	 */
	private function licenses_tab() {
		/*
		 * The freshly minted key is shown here, driven by the stored value
		 * rather than by a flag in the address. The redirect that follows the
		 * create form does carry a notice code, but a key that only appears
		 * when the address is right is a key the owner loses the moment they
		 * reload or reopen the screen — and there is no copy of it to go back
		 * to. Reading it is what removes it, so showing it whenever it is
		 * still waiting is both safer and simpler.
		 */
		$this->show_new_key();

		$rows = Keys::all();
		?>
		<h2><?php esc_html_e( 'Create a licence key', 'at8-license-server' ); ?></h2>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="at8ls-form">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
			<input type="hidden" name="at8ls_task" value="issue">
			<?php wp_nonce_field( self::ACTION . '_issue' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="at8ls-product"><?php esc_html_e( 'Product', 'at8-license-server' ); ?></label></th>
					<td>
						<select name="product" id="at8ls-product">
							<?php foreach ( products() as $slug => $product ) : ?>
								<option value="<?php echo esc_attr( $slug ); ?>">
									<?php echo esc_html( $product['label'] ); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="at8ls-days"><?php esc_html_e( 'Validity', 'at8-license-server' ); ?></label></th>
					<td>
						<input type="number" min="0" step="1" value="0" name="days" id="at8ls-days" class="small-text">
						<p class="description">
							<?php esc_html_e( 'Days from now. Leave 0 for a licence with no end date.', 'at8-license-server' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="at8ls-max"><?php esc_html_e( 'Sites allowed', 'at8-license-server' ); ?></label></th>
					<td>
						<input type="number" min="0" step="1" value="1" name="max_sites" id="at8ls-max" class="small-text">
						<p class="description">
							<?php esc_html_e( '0 means no limit.', 'at8-license-server' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="at8ls-note"><?php esc_html_e( 'Note', 'at8-license-server' ); ?></label></th>
					<td>
						<input type="text" name="note" id="at8ls-note" class="regular-text"
							placeholder="<?php esc_attr_e( 'Who this key was sold to', 'at8-license-server' ); ?>">
					</td>
				</tr>
			</table>
			<p class="submit">
				<button type="submit" class="button button-primary">
					<?php esc_html_e( 'Create key', 'at8-license-server' ); ?>
				</button>
			</p>
		</form>

		<h2><?php esc_html_e( 'Issued keys', 'at8-license-server' ); ?></h2>
		<?php if ( ! $rows ) : ?>
			<p><?php esc_html_e( 'No keys yet.', 'at8-license-server' ); ?></p>
		<?php else : ?>
			<table class="wp-list-table widefat fixed striped at8ls-table">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Key', 'at8-license-server' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Product', 'at8-license-server' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Status', 'at8-license-server' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Expires', 'at8-license-server' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Sites', 'at8-license-server' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Note', 'at8-license-server' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Actions', 'at8-license-server' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $rows as $row ) : ?>
					<?php
					$id    = (int) $row['id'];
					$used  = Keys::site_count( $id );
					$max   = (int) $row['max_sites'];
					$state = (string) $row['status'];
					?>
					<tr>
						<td>
							<code class="at8ls-key"><?php echo esc_html( $this->key_cell( $row ) ); ?></code>
						</td>
						<td><?php echo esc_html( product_label( (string) $row['product'] ) ); ?></td>
						<td>
							<span class="at8ls-badge at8ls-<?php echo esc_attr( $state ); ?>">
								<?php echo esc_html( $this->status_label( $state ) ); ?>
							</span>
						</td>
						<td><?php echo esc_html( expiry_label( (int) $row['expires'] ) ); ?></td>
						<td>
							<?php
							echo esc_html(
								$max > 0
									? sprintf( '%1$d / %2$d', $used, $max )
									: sprintf(
										/* translators: %d: number of sites using the key. */
										_n( '%d site', '%d sites', $used, 'at8-license-server' ),
										$used
									)
							);
							?>
						</td>
						<td><?php echo esc_html( (string) $row['note'] ); ?></td>
						<td class="at8ls-actions">
							<?php $this->status_button( $id, $state ); ?>
							<?php $this->suspend_button( $id, $state ); ?>
							<a class="button button-small"
								href="<?php echo esc_url( $this->tab_url( 'sites' ) ); ?>">
								<?php esc_html_e( 'Sites', 'at8-license-server' ); ?>
							</a>
							<?php $this->delete_button( $id ); ?>
						</td>
					</tr>
					<tr class="at8ls-detail">
						<td colspan="7">
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="at8ls-inline">
								<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
								<input type="hidden" name="at8ls_task" value="update">
								<input type="hidden" name="id" value="<?php echo esc_attr( (string) $id ); ?>">
								<?php wp_nonce_field( self::ACTION . '_update_' . $id ); ?>
								<label for="at8ls-max-<?php echo esc_attr( (string) $id ); ?>">
									<?php esc_html_e( 'Sites allowed', 'at8-license-server' ); ?>
								</label>
								<input type="number" min="0" step="1" class="small-text"
									id="at8ls-max-<?php echo esc_attr( (string) $id ); ?>"
									name="max_sites" value="<?php echo esc_attr( (string) $max ); ?>">
								<label for="at8ls-note-<?php echo esc_attr( (string) $id ); ?>">
									<?php esc_html_e( 'Note', 'at8-license-server' ); ?>
								</label>
								<input type="text" class="regular-text"
									id="at8ls-note-<?php echo esc_attr( (string) $id ); ?>"
									name="note" value="<?php echo esc_attr( (string) $row['note'] ); ?>">
								<button type="submit" class="button button-small">
									<?php esc_html_e( 'Save', 'at8-license-server' ); ?>
								</button>
							</form>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
		<?php
	}

	/**
	 * Tab 2: the sites holding keys.
	 *
	 * @return void
	 */
	private function sites_tab() {
		$rows = Keys::all_sites();
		?>
		<h2><?php esc_html_e( 'Sites using a key', 'at8-license-server' ); ?></h2>
		<?php if ( ! $rows ) : ?>
			<p><?php esc_html_e( 'No site has activated a key yet.', 'at8-license-server' ); ?></p>
		<?php else : ?>
			<table class="wp-list-table widefat fixed striped at8ls-table">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Site', 'at8-license-server' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Key', 'at8-license-server' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Activated', 'at8-license-server' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Last check', 'at8-license-server' ); ?></th>
						<th scope="col"><?php esc_html_e( 'WordPress / PHP', 'at8-license-server' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Actions', 'at8-license-server' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $rows as $row ) : ?>
					<tr>
						<td>
							<strong><?php echo esc_html( (string) $row['domain'] ); ?></strong>
							<?php
							$at8ls_env = (string) $row['environment'];

							if ( '' !== $at8ls_env && 'production' !== $at8ls_env ) :
								?>
								<span class="at8ls-badge at8ls-<?php echo esc_attr( sanitize_html_class( $at8ls_env ) ); ?>">
									<?php echo esc_html( $at8ls_env ); ?>
								</span>
							<?php endif; ?>
							<?php if ( '' !== (string) $row['site_url'] ) : ?>
								<br><span class="at8ls-muted"><?php echo esc_html( (string) $row['site_url'] ); ?></span>
							<?php endif; ?>
							<?php if ( '' !== (string) $row['last_ip'] ) : ?>
								<br><span class="at8ls-muted"><?php echo esc_html( (string) $row['last_ip'] ); ?></span>
							<?php endif; ?>
						</td>
						<td>
							<code class="at8ls-key"><?php echo esc_html( $this->key_cell( $row ) ); ?></code>
						</td>
						<td><?php echo esc_html( when( (int) $row['activated_at'] ) ); ?></td>
						<td><?php echo esc_html( when( (int) $row['last_seen'] ) ); ?></td>
						<td>
							<?php
							echo esc_html(
								trim(
									(string) $row['wp_version'] . ( '' !== (string) $row['php_version'] ? ' / ' . (string) $row['php_version'] : '' ),
									' /'
								)
							);
							?>
						</td>
						<td class="at8ls-actions">
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
								<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
								<input type="hidden" name="at8ls_task" value="unbind">
								<input type="hidden" name="site_id" value="<?php echo esc_attr( (string) (int) $row['id'] ); ?>">
								<?php wp_nonce_field( self::ACTION . '_unbind_' . (int) $row['id'] ); ?>
								<button type="submit" class="button button-small at8ls-danger">
									<?php esc_html_e( 'Release', 'at8-license-server' ); ?>
								</button>
							</form>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
		<?php
	}

	/**
	 * Tab 3: what is published to licensed sites.
	 *
	 * @return void
	 */
	private function release_tab() {
		$current = Package::get( 'at8-security-pro' );
		?>
		<h2><?php esc_html_e( 'Publish a build', 'at8-license-server' ); ?></h2>
		<p class="description at8ls-intro">
			<?php esc_html_e( 'Upload the add-on zip and fill in the version. Licensed sites will be offered it on their Plugins screen.', 'at8-license-server' ); ?>
		</p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
			enctype="multipart/form-data" class="at8ls-form">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
			<input type="hidden" name="at8ls_task" value="publish">
			<?php wp_nonce_field( self::ACTION . '_publish' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="at8ls-rel-product"><?php esc_html_e( 'Product', 'at8-license-server' ); ?></label></th>
					<td>
						<select name="product" id="at8ls-rel-product">
							<?php foreach ( products() as $slug => $product ) : ?>
								<option value="<?php echo esc_attr( $slug ); ?>">
									<?php echo esc_html( $product['label'] ); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="at8ls-rel-version"><?php esc_html_e( 'Version', 'at8-license-server' ); ?></label></th>
					<td>
						<input type="text" name="version" id="at8ls-rel-version" class="small-text"
							value="<?php echo esc_attr( isset( $current['version'] ) ? (string) $current['version'] : '' ); ?>"
							placeholder="2.0.0">
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="at8ls-rel-file"><?php esc_html_e( 'Package', 'at8-license-server' ); ?></label></th>
					<td>
						<input type="file" name="package" id="at8ls-rel-file" accept=".zip">
						<p class="description">
							<?php
							if ( ! empty( $current['file'] ) ) {
								printf(
									/* translators: %s: file name of the published package. */
									esc_html__( 'Currently published: %s — leave empty to keep it.', 'at8-license-server' ),
									'<code>' . esc_html( (string) $current['file'] ) . '</code>'
								);
							} else {
								esc_html_e( 'Nothing published yet.', 'at8-license-server' );
							}

							if ( ! empty( $current['sha256'] ) ) {
								echo '<br>';
								printf(
									/* translators: 1: sha256 fingerprint, 2: file size. */
									esc_html__( 'Fingerprint %1$s · %2$s', 'at8-license-server' ),
									'<code>sha256:' . esc_html( substr( (string) $current['sha256'], 0, 16 ) ) . '…</code>',
									esc_html( size_format( (int) $current['package_size'], 1 ) )
								);
							}
							?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="at8ls-rel-status"><?php esc_html_e( 'Status', 'at8-license-server' ); ?></label></th>
					<td>
						<select name="status" id="at8ls-rel-status">
							<?php foreach ( Package::statuses() as $at8ls_status ) : ?>
								<option value="<?php echo esc_attr( $at8ls_status ); ?>"
									<?php echo Package::status( 'at8-security-pro' ) === $at8ls_status ? 'selected' : ''; ?>>
									<?php echo esc_html( $at8ls_status ); ?>
								</option>
							<?php endforeach; ?>
						</select>
						<p class="description">
							<?php esc_html_e( 'Only a published build is offered to licensed sites. Everything else is kept as a record.', 'at8-license-server' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Requirements', 'at8-license-server' ); ?></th>
					<td>
						<label for="at8ls-rel-req"><?php esc_html_e( 'WordPress', 'at8-license-server' ); ?></label>
						<input type="text" name="requires" id="at8ls-rel-req" class="small-text"
							value="<?php echo esc_attr( isset( $current['requires'] ) ? (string) $current['requires'] : '6.4' ); ?>">
						<label for="at8ls-rel-tested"><?php esc_html_e( 'Tested up to', 'at8-license-server' ); ?></label>
						<input type="text" name="tested" id="at8ls-rel-tested" class="small-text"
							value="<?php echo esc_attr( isset( $current['tested'] ) ? (string) $current['tested'] : '' ); ?>">
						<label for="at8ls-rel-php"><?php esc_html_e( 'PHP', 'at8-license-server' ); ?></label>
						<input type="text" name="requires_php" id="at8ls-rel-php" class="small-text"
							value="<?php echo esc_attr( isset( $current['requires_php'] ) ? (string) $current['requires_php'] : '7.4' ); ?>">
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="at8ls-rel-changelog"><?php esc_html_e( 'Changelog', 'at8-license-server' ); ?></label></th>
					<td>
						<textarea name="changelog" id="at8ls-rel-changelog" rows="8" class="large-text code"><?php
							echo esc_textarea( isset( $current['changelog'] ) ? (string) $current['changelog'] : '' );
						?></textarea>
						<p class="description">
							<?php esc_html_e( 'Plain text. Shown in the "View version details" popup on licensed sites.', 'at8-license-server' ); ?>
						</p>
					</td>
				</tr>
			</table>
			<p class="submit">
				<button type="submit" class="button button-primary">
					<?php esc_html_e( 'Save release', 'at8-license-server' ); ?>
				</button>
			</p>
		</form>

		<h2><?php esc_html_e( 'Published builds', 'at8-license-server' ); ?></h2>
		<table class="wp-list-table widefat fixed striped at8ls-table">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Product', 'at8-license-server' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Version', 'at8-license-server' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Status', 'at8-license-server' ); ?></th>
					<th scope="col"><?php esc_html_e( 'File', 'at8-license-server' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Published', 'at8-license-server' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Actions', 'at8-license-server' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( products() as $slug => $product ) : ?>
				<?php
				$release = Package::get( $slug );
				$state   = Package::status( $slug );
				?>
				<tr>
					<td><?php echo esc_html( $product['label'] ); ?></td>
					<td><?php echo esc_html( isset( $release['version'] ) ? (string) $release['version'] : '—' ); ?></td>
					<td>
						<?php if ( '' !== (string) ( $release['version'] ?? '' ) ) : ?>
							<span class="at8ls-badge at8ls-<?php echo esc_attr( sanitize_html_class( $state ) ); ?>">
								<?php echo esc_html( $state ); ?>
							</span>
						<?php else : ?>
							—
						<?php endif; ?>
					</td>
					<td>
						<?php
						$file = isset( $release['file'] ) ? (string) $release['file'] : '';

						if ( '' === $file ) {
							echo '—';
						} elseif ( '' === Package::path( $slug ) ) {
							echo '<span class="at8ls-badge at8ls-revoked">' . esc_html__( 'file missing', 'at8-license-server' ) . '</span> ';
							echo '<code>' . esc_html( $file ) . '</code>';
						} else {
							echo '<code>' . esc_html( $file ) . '</code>';
						}
						?>
					</td>
					<td><?php echo esc_html( when( isset( $release['published_at'] ) ? (int) $release['published_at'] : (int) ( $release['uploaded_at'] ?? 0 ) ) ); ?></td>
					<td class="at8ls-actions">
						<?php if ( '' !== $file ) : ?>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="at8ls-inline-form">
								<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
								<input type="hidden" name="at8ls_task" value="release_status">
								<input type="hidden" name="product" value="<?php echo esc_attr( $slug ); ?>">
								<input type="hidden" name="status" value="<?php echo esc_attr( 'published' === $state ? 'withdrawn' : 'published' ); ?>">
								<?php wp_nonce_field( self::ACTION . '_release_' . $slug ); ?>
								<button type="submit" class="button button-small <?php echo 'published' === $state ? 'at8ls-danger' : ''; ?>">
									<?php echo esc_html( 'published' === $state ? __( 'Withdraw', 'at8-license-server' ) : __( 'Publish', 'at8-license-server' ) ); ?>
								</button>
							</form>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Tab 4: what has been done to these licences.
	 *
	 * @return void
	 */
	private function audit_tab() {
		$rows      = Audit::recent( 100 );
		$retention = Audit::retention_days();
		$purge     = (bool) get_option( 'at8ls_purge_on_uninstall', 0 );
		?>
		<h2><?php esc_html_e( 'Audit log', 'at8-license-server' ); ?></h2>
		<p class="description at8ls-intro">
			<?php esc_html_e( 'Every licence decision, with who made it and from where. Keys themselves are never written here.', 'at8-license-server' ); ?>
		</p>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="at8ls-form">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
			<input type="hidden" name="at8ls_task" value="audit_settings">
			<?php wp_nonce_field( self::ACTION . '_audit_settings' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="at8ls-audit-days"><?php esc_html_e( 'Keep entries for', 'at8-license-server' ); ?></label></th>
					<td>
						<select name="days" id="at8ls-audit-days">
							<?php foreach ( array( 30, 90, 180, 365 ) as $at8ls_days ) : ?>
								<option value="<?php echo esc_attr( (string) $at8ls_days ); ?>"
									<?php echo $retention === $at8ls_days ? 'selected' : ''; ?>>
									<?php
									printf(
										/* translators: %d: number of days. */
										esc_html__( '%d days', 'at8-license-server' ),
										(int) $at8ls_days
									);
									?>
								</option>
							<?php endforeach; ?>
						</select>
						<p class="description">
							<?php esc_html_e( 'Older entries are removed by a daily job.', 'at8-license-server' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Uninstalling', 'at8-license-server' ); ?></th>
					<td>
						<label for="at8ls-purge">
							<input type="checkbox" name="purge" id="at8ls-purge" value="1" <?php checked( $purge ); ?>>
							<?php esc_html_e( 'Delete all licence records when the plugin is deleted', 'at8-license-server' ); ?>
						</label>
						<p class="description">
							<?php esc_html_e( 'Off by default: licences, site bindings and this log are commercial records and outlive the plugin. Deleting the WordPress plugin is a routine act and should not destroy them. Define AT8LS_PURGE_ON_UNINSTALL in wp-config.php to decide it per site.', 'at8-license-server' ); ?>
						</p>
					</td>
				</tr>
			</table>
			<p class="submit">
				<button type="submit" class="button button-primary">
					<?php esc_html_e( 'Save', 'at8-license-server' ); ?>
				</button>
			</p>
		</form>

		<h2><?php esc_html_e( 'Recent activity', 'at8-license-server' ); ?></h2>
		<?php if ( ! $rows ) : ?>
			<p><?php esc_html_e( 'Nothing has been recorded yet.', 'at8-license-server' ); ?></p>
		<?php else : ?>
			<table class="wp-list-table widefat fixed striped at8ls-table">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'When', 'at8-license-server' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Action', 'at8-license-server' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Object', 'at8-license-server' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Who', 'at8-license-server' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Details', 'at8-license-server' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $rows as $row ) : ?>
					<?php $at8ls_meta = $this->audit_details( (string) $row['metadata'] ); ?>
					<tr>
						<td><?php echo esc_html( when( (int) $row['created_at'] ) ); ?></td>
						<td><code><?php echo esc_html( (string) $row['action'] ); ?></code></td>
						<td>
							<?php
							echo esc_html(
								trim(
									(string) $row['object_type'] . ( (int) $row['object_id'] > 0 ? ' #' . (int) $row['object_id'] : '' )
								)
							);
							?>
						</td>
						<td>
							<?php
							$at8ls_actor = (int) $row['actor_id'];

							if ( $at8ls_actor > 0 ) {
								$at8ls_user = get_userdata( $at8ls_actor );

								echo esc_html( $at8ls_user ? $at8ls_user->user_login : '#' . $at8ls_actor );
							} else {
								echo '—';
							}

							if ( '' !== (string) $row['actor_ip'] ) {
								echo '<br><span class="at8ls-muted">' . esc_html( (string) $row['actor_ip'] ) . '</span>';
							}
							?>
						</td>
						<td><?php echo '' === $at8ls_meta ? '—' : esc_html( $at8ls_meta ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="at8ls-form">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
				<input type="hidden" name="at8ls_task" value="audit_clear">
				<?php wp_nonce_field( self::ACTION . '_audit_clear' ); ?>
				<p class="submit">
					<button type="submit" class="button at8ls-danger">
						<?php esc_html_e( 'Clear the log', 'at8-license-server' ); ?>
					</button>
				</p>
			</form>
		<?php endif; ?>
		<?php
	}

	/**
	 * Render stored metadata as one readable line.
	 *
	 * @param string $json Stored JSON.
	 * @return string
	 */
	private function audit_details( $json ) {
		$data = json_decode( (string) $json, true );

		if ( ! is_array( $data ) || ! $data ) {
			return '';
		}

		$parts = array();

		foreach ( $data as $name => $value ) {
			if ( ! is_scalar( $value ) ) {
				continue;
			}

			$parts[] = $name . ': ' . (string) $value;
		}

		return implode( ' · ', $parts );
	}

	/**
	 * The key column of a licence row.
	 *
	 * Normally the masked key. A row can reach the screen with no tail — the
	 * tail is derived from the key, and a row whose key was already hashed
	 * before the tail column existed has none to show — so the id stands in.
	 * A blank cell would leave an operator with no way to say which row they
	 * are looking at.
	 *
	 * @param array<string, mixed> $row Licence row.
	 * @return string
	 */
	private function key_cell( $row ) {
		$masked = masked_key(
			isset( $row['product'] ) ? (string) $row['product'] : '',
			isset( $row['key_hint'] ) ? (string) $row['key_hint'] : ''
		);

		if ( '' !== $masked ) {
			return $masked;
		}

		return sprintf(
			/* translators: %d: licence id, shown when the key cannot be masked. */
			__( 'licence #%d', 'at8-license-server' ),
			isset( $row['id'] ) ? (int) $row['id'] : 0
		);
	}

	/**
	 * Label for a licence status.
	 *
	 * @param string $status Stored status.
	 * @return string
	 */
	private function status_label( $status ) {
		switch ( $status ) {
			case Keys::STATUS_ACTIVE:
				return __( 'Active', 'at8-license-server' );

			case Keys::STATUS_DISABLED:
				return __( 'Disabled', 'at8-license-server' );

			case Keys::STATUS_SUSPENDED:
				return __( 'Suspended', 'at8-license-server' );

			case Keys::STATUS_REVOKED:
				return __( 'Revoked', 'at8-license-server' );

			default:
				return $status;
		}
	}

	/**
	 * A button that flips a licence between active and disabled.
	 *
	 * @param int    $id     Licence id.
	 * @param string $status Current status.
	 * @return void
	 */
	private function status_button( $id, $status ) {
		$next  = Keys::STATUS_ACTIVE === $status ? Keys::STATUS_DISABLED : Keys::STATUS_ACTIVE;
		$label = Keys::STATUS_ACTIVE === $status
			? __( 'Disable', 'at8-license-server' )
			: __( 'Enable', 'at8-license-server' );
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="at8ls-inline-form">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
			<input type="hidden" name="at8ls_task" value="status">
			<input type="hidden" name="id" value="<?php echo esc_attr( (string) $id ); ?>">
			<input type="hidden" name="status" value="<?php echo esc_attr( $next ); ?>">
			<?php wp_nonce_field( self::ACTION . '_status_' . $id ); ?>
			<button type="submit" class="button button-small"><?php echo esc_html( $label ); ?></button>
		</form>
		<?php
	}

	/**
	 * A button that suspends an active licence, or brings a suspended one back.
	 *
	 * Suspension is the reversible middle ground: a failed payment or an open
	 * dispute should stop the key without ending the customer's licence, which
	 * is what revoking would do.
	 *
	 * @param int    $id     Licence id.
	 * @param string $status Current status.
	 * @return void
	 */
	private function suspend_button( $id, $status ) {
		$suspended = Keys::STATUS_SUSPENDED === $status;
		$next      = $suspended ? Keys::STATUS_ACTIVE : Keys::STATUS_SUSPENDED;
		$label     = $suspended
			? __( 'Resume', 'at8-license-server' )
			: __( 'Suspend', 'at8-license-server' );

		/*
		 * Only meaningful while the licence is in play. A revoked or disabled
		 * key is already off, and adding a fourth state on top would only make
		 * the list harder to read.
		 */
		if ( ! $suspended && Keys::STATUS_ACTIVE !== $status ) {
			return;
		}
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="at8ls-inline-form">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
			<input type="hidden" name="at8ls_task" value="status">
			<input type="hidden" name="id" value="<?php echo esc_attr( (string) $id ); ?>">
			<input type="hidden" name="status" value="<?php echo esc_attr( $next ); ?>">
			<?php wp_nonce_field( self::ACTION . '_status_' . $id ); ?>
			<button type="submit" class="button button-small"><?php echo esc_html( $label ); ?></button>
		</form>
		<?php
	}

	/**
	 * A button that revokes a licence and drops its site bindings.
	 *
	 * @param int $id Licence id.
	 * @return void
	 */
	private function delete_button( $id ) {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="at8ls-inline-form"
			onsubmit="return confirm('<?php echo esc_js( __( 'Delete this key and release every site using it?', 'at8-license-server' ) ); ?>');">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
			<input type="hidden" name="at8ls_task" value="delete">
			<input type="hidden" name="id" value="<?php echo esc_attr( (string) $id ); ?>">
			<?php wp_nonce_field( self::ACTION . '_delete_' . $id ); ?>
			<button type="submit" class="button button-small at8ls-danger">
				<?php esc_html_e( 'Delete', 'at8-license-server' ); ?>
			</button>
		</form>
		<?php
	}

	/**
	 * Handle a submitted form.
	 *
	 * @return void
	 */
	public function handle() {
		if ( ! current_user_can( self::CAP ) ) {
			$this->refuse();
		}

		$task = isset( $_POST['at8ls_task'] ) ? sanitize_key( wp_unslash( $_POST['at8ls_task'] ) ) : '';
		$id   = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;

		switch ( $task ) {
			case 'issue':
				$this->do_issue();
				break;

			case 'update':
				check_admin_referer( self::ACTION . '_update_' . $id );
				Keys::set_max_sites( $id, isset( $_POST['max_sites'] ) ? (int) $_POST['max_sites'] : 0 );
				// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitize_text_field() runs inside set_note().
				Keys::set_note( $id, isset( $_POST['note'] ) ? (string) wp_unslash( $_POST['note'] ) : '' );
				$this->back( 'updated' );
				break;

			case 'status':
				check_admin_referer( self::ACTION . '_status_' . $id );
				Keys::set_status( $id, isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : '' );
				$this->back( 'updated' );
				break;

			case 'delete':
				check_admin_referer( self::ACTION . '_delete_' . $id );
				Keys::delete( $id );
				$this->back( 'deleted' );
				break;

			case 'unbind':
				$site_id = isset( $_POST['site_id'] ) ? (int) $_POST['site_id'] : 0;
				check_admin_referer( self::ACTION . '_unbind_' . $site_id );
				Keys::unbind( $site_id );
				$this->back( 'released', 'sites' );
				break;

			case 'publish':
				$this->do_publish();
				break;

			case 'forget':
				$product = isset( $_POST['product'] ) ? sanitize_key( wp_unslash( $_POST['product'] ) ) : '';
				check_admin_referer( self::ACTION . '_forget_' . $product );
				Package::forget( $product );
				$this->back( 'forgot', 'release' );
				break;

			case 'release_status':
				$product = isset( $_POST['product'] ) ? sanitize_key( wp_unslash( $_POST['product'] ) ) : '';
				check_admin_referer( self::ACTION . '_release_' . $product );
				Package::set_status(
					$product,
					isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : ''
				);
				$this->back( 'updated', 'release' );
				break;

			case 'audit_settings':
				check_admin_referer( self::ACTION . '_audit_settings' );

				$days = isset( $_POST['days'] ) ? (int) $_POST['days'] : Audit::DEFAULT_RETENTION;
				$days = in_array( $days, array( 30, 90, 180, 365 ), true ) ? $days : Audit::DEFAULT_RETENTION;
				$purge = isset( $_POST['purge'] ) ? 1 : 0;

				update_option( Audit::OPTION_RETENTION, $days, false );
				update_option( 'at8ls_purge_on_uninstall', $purge, false );

				Audit::record(
					'settings.changed',
					'setting',
					0,
					array(
						'audit_days'         => $days,
						'purge_on_uninstall' => $purge,
					)
				);

				$this->back( 'updated', 'audit' );
				break;

			case 'audit_clear':
				check_admin_referer( self::ACTION . '_audit_clear' );

				/*
				 * The count is taken first and written back afterwards, so the
				 * one durable fact about a cleared log is the clearing itself:
				 * an empty log with no explanation is worse than none.
				 */
				$removed = Audit::count();
				Audit::clear();
				Audit::record( 'settings.audit_cleared', 'setting', 0, array( 'removed' => $removed ) );

				$this->back( 'cleared', 'audit' );
				break;

			case 'repair':
				check_admin_referer( self::ACTION . '_repair' );
				Db::install();
				$this->back( 'updated' );
				break;

			default:
				$this->back( 'failed' );
		}
	}

	/**
	 * Create a key from the form.
	 *
	 * The key is minted here and written to a short-lived transient tied to the
	 * current user, then the screen redirects. Nothing else holds it: the row
	 * has a hash and a five-character tail, so this redirect is the last point
	 * at which it can be read.
	 *
	 * It goes through a transient rather than a query string because a key in a
	 * URL is a key in the web server's access log, in the browser's history and
	 * in any Referer header the next page sends.
	 *
	 * @return void
	 */
	private function do_issue() {
		check_admin_referer( self::ACTION . '_issue' );

		$result = Keys::issue_with_key(
			array(
				'product'   => isset( $_POST['product'] ) ? sanitize_key( wp_unslash( $_POST['product'] ) ) : '',
				'plan'      => 'lifetime',
				'days'      => isset( $_POST['days'] ) ? (int) $_POST['days'] : 0,
				'max_sites' => isset( $_POST['max_sites'] ) ? (int) $_POST['max_sites'] : 1,
				// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitize_text_field() runs inside issue().
				'note'      => isset( $_POST['note'] ) ? (string) wp_unslash( $_POST['note'] ) : '',
			)
		);

		if ( is_wp_error( $result ) ) {
			$this->back( 'failed', 'licenses', $result->get_error_message() );
		}

		set_transient( $this->new_key_transient(), (string) $result['key'], self::NEW_KEY_TTL );

		$this->back( 'issued' );
	}

	/**
	 * Name of the transient holding a key that has not been read yet.
	 *
	 * Per user, so two administrators working at the same time do not read each
	 * other's key — the second create would otherwise overwrite the first.
	 *
	 * @return string
	 */
	private function new_key_transient() {
		return 'at8ls_new_key_' . get_current_user_id();
	}

	/**
	 * Take the freshly minted key, if there is one, and forget it.
	 *
	 * Reading deletes: the screen shows the key exactly once, and a reload of
	 * the page afterwards finds nothing rather than showing it again.
	 *
	 * @return string Empty when there is nothing waiting.
	 */
	private function take_new_key() {
		$name = $this->new_key_transient();
		$key  = get_transient( $name );

		if ( ! is_string( $key ) || '' === $key ) {
			return '';
		}

		delete_transient( $name );

		return $key;
	}

	/**
	 * Save a release from the form.
	 *
	 * @return void
	 */
	private function do_publish() {
		check_admin_referer( self::ACTION . '_publish' );

		$product = isset( $_POST['product'] ) ? sanitize_key( wp_unslash( $_POST['product'] ) ) : '';
		$version = isset( $_POST['version'] ) ? sanitize_text_field( wp_unslash( $_POST['version'] ) ) : '';

		if ( '' === $product || ! isset( products()[ $product ] ) || '' === $version ) {
			$this->back( 'failed', 'release', __( 'A product and a version are both required.', 'at8-license-server' ) );
		}

		$current = Package::get( $product );
		$file    = isset( $current['file'] ) ? (string) $current['file'] : '';
		$sha     = '';
		$size    = 0;

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Passed to wp_handle_upload(), which validates it.
		$upload = isset( $_FILES['package'] ) ? $_FILES['package'] : array();

		if ( isset( $upload['name'] ) && '' !== (string) $upload['name'] ) {
			$stored = Package::store_upload( $upload, $product );

			if ( is_wp_error( $stored ) ) {
				$this->back( 'failed', 'release', $stored->get_error_message() );
			}

			$file = (string) $stored['file'];
			$sha  = (string) $stored['sha256'];
			$size = (int) $stored['package_size'];
		}

		Package::save(
			$product,
			array(
				'version'      => $version,
				'file'         => $file,
				'sha256'       => $sha,
				'package_size' => $size,
				'status'       => isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : 'published',
				'changelog'    => isset( $_POST['changelog'] ) ? sanitize_textarea_field( wp_unslash( $_POST['changelog'] ) ) : '',
				'requires'     => isset( $_POST['requires'] ) ? sanitize_text_field( wp_unslash( $_POST['requires'] ) ) : '',
				'tested'       => isset( $_POST['tested'] ) ? sanitize_text_field( wp_unslash( $_POST['tested'] ) ) : '',
				'requires_php' => isset( $_POST['requires_php'] ) ? sanitize_text_field( wp_unslash( $_POST['requires_php'] ) ) : '',
			)
		);

		$this->back( 'published', 'release' );
	}

	/**
	 * Turn away an account that is not allowed to manage licences.
	 *
	 * wp_die() answers 500 unless it is told otherwise, which reads as "the
	 * server broke" rather than "you are not allowed" — the wrong answer for a
	 * permission refusal, and a misleading one in a log. Answering 403 is what
	 * a caller and a monitor both expect.
	 *
	 * The attempt is recorded as well: a licence action from an account that
	 * cannot manage licences is either a misconfigured role or somebody trying
	 * it on, and both are worth being able to look up afterwards.
	 *
	 * @return void
	 */
	private function refuse() {
		Audit::record(
			'admin.refused',
			'user',
			get_current_user_id(),
			array(
				'user_id'    => get_current_user_id(),
				'capability' => self::CAP,
				'task'       => isset( $_POST['at8ls_task'] )
					? sanitize_key( wp_unslash( $_POST['at8ls_task'] ) )
					: '',
			)
		);

		wp_die(
			esc_html__( 'You are not allowed to manage licences.', 'at8-license-server' ),
			'',
			array( 'response' => 403 )
		);
	}

	/**
	 * Send the user back to a tab, with a notice code and nothing else.
	 *
	 * @param string $notice  Notice code.
	 * @param string $tab     Tab to return to.
	 * @param string $message Extra explanation for a failure.
	 * @return void
	 */
	private function back( $notice, $tab = 'licenses', $message = '' ) {
		$args = array(
			'page'         => self::SLUG,
			'tab'          => $tab,
			'at8ls_notice' => $notice,
		);

		if ( '' !== $message ) {
			$args['at8ls_why'] = rawurlencode( $message );
		}

		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}
}
