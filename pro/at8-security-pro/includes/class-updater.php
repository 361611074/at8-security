<?php
/**
 * Updates for the add-on, served from the author's own site.
 *
 * The plugin header declares an `Update URI`, so WordPress already knows not to
 * look for this plugin in the WordPress.org directory. What is left is to feed
 * the update screen the answer from the licence server, and to cache it: the
 * updates screen runs on a lot of admin requests and nobody needs a fresh
 * answer every time.
 *
 * A plugin-check report will flag this file for both the advisory header and
 * the update transient. That is expected and it is the point: the rule those
 * findings come from exists to keep the WordPress.org directory's own update
 * pipeline honest, and this add-on is distributed from the author's site
 * instead — the licence server is where its updates come from, because there
 * is nowhere else they could come from.
 *
 * @package At8\Security\Pro
 */

namespace At8\Security\Pro;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Self-hosted updater.
 */
class Updater {

	const TRANSIENT       = 'at8sec_pro_update_check';
	const TRANSIENT_FAIL  = 'at8sec_pro_update_failed';
	const CACHE_SECONDS   = 43200; // Twelve hours.
	const FAILURE_SECONDS = 3600;  // Stop hammering an unreachable server.

	/**
	 * Slug WordPress uses for this plugin, i.e. the folder name.
	 *
	 * @return string
	 */
	public static function slug() {
		return (string) dirname( AT8SEC_PRO_BASENAME );
	}

	/**
	 * Register hooks.
	 */
	public function __construct() {
		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'inject_update' ) );
		add_filter( 'plugins_api', array( $this, 'plugin_information' ), 20, 3 );
		add_action( 'upgrader_process_complete', array( $this, 'clear_after_update' ), 10, 2 );
		add_action( 'deleted_site_transient', array( $this, 'clear_on_transient_delete' ), 10, 1 );
	}

	/**
	 * Drop the cached answer.
	 *
	 * @return void
	 */
	public static function clear_cache() {
		delete_transient( self::TRANSIENT );
		delete_transient( self::TRANSIENT_FAIL );
	}

	/**
	 * Clear the cache when the core update transient is dropped.
	 *
	 * @param string $transient Transient name.
	 * @return void
	 */
	public function clear_on_transient_delete( $transient ) {
		if ( 'update_plugins' === $transient ) {
			self::clear_cache();
		}
	}

	/**
	 * Clear the cache once an update has been applied.
	 *
	 * @param \WP_Upgrader $upgrader Upgrader instance.
	 * @param array        $options  Update context.
	 * @return void
	 */
	public function clear_after_update( $upgrader, $options ) {
		unset( $upgrader );

		if ( ! is_array( $options ) || empty( $options['plugins'] ) || ! is_array( $options['plugins'] ) ) {
			return;
		}

		if ( in_array( AT8SEC_PRO_BASENAME, $options['plugins'], true ) ) {
			self::clear_cache();
		}
	}

	/**
	 * Offer the update when the server has a newer build.
	 *
	 * @param mixed $transient The update_plugins transient.
	 * @return mixed
	 */
	public function inject_update( $transient ) {
		if ( ! is_object( $transient ) || empty( $transient->checked ) ) {
			return $transient;
		}

		$remote = $this->remote_info();

		if ( ! $remote ) {
			return $transient;
		}

		$installed = isset( $transient->checked[ AT8SEC_PRO_BASENAME ] )
			? (string) $transient->checked[ AT8SEC_PRO_BASENAME ]
			: AT8SEC_PRO_VERSION;

		if ( ! version_compare( $remote['version'], $installed, '>' ) ) {
			return $transient;
		}

		$transient->response[ AT8SEC_PRO_BASENAME ] = (object) array(
			'id'           => 'at8.fun/at8-security-pro',
			'slug'         => self::slug(),
			'plugin'       => AT8SEC_PRO_BASENAME,
			'new_version'  => $remote['version'],
			'url'          => $remote['homepage'],
			'package'      => $remote['download_url'],
			'requires'     => $remote['requires'],
			'tested'       => $remote['tested'],
			'requires_php' => $remote['requires_php'],
			'icons'        => array(),
			'banners'      => array(),
		);

		return $transient;
	}

	/**
	 * Fill the "View version details" popup.
	 *
	 * @param mixed  $result Result object or false.
	 * @param string $action API action.
	 * @param object $args   Request arguments.
	 * @return mixed
	 */
	public function plugin_information( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || ! is_object( $args ) || empty( $args->slug ) ) {
			return $result;
		}

		if ( self::slug() !== (string) $args->slug ) {
			return $result;
		}

		$remote = $this->remote_info();

		if ( ! $remote ) {
			return $result;
		}

		return (object) array(
			'name'          => 'At8 Security Pro',
			'slug'          => self::slug(),
			'version'       => $remote['version'],
			'author'        => '<a href="https://www.at8.fun/">漫步白月光</a>',
			'author_homepage' => 'https://www.at8.fun/',
			'homepage'      => $remote['homepage'],
			'requires'      => $remote['requires'],
			'tested'        => $remote['tested'],
			'requires_php'  => $remote['requires_php'],
			'last_updated'  => $remote['last_updated'],
			'download_link' => $remote['download_url'],
			'sections'      => array(
				'description' => '<p>' . esc_html__( 'The add-on for At8 Security. Requires the free At8 Security plugin.', 'at8-security-pro' ) . '</p>',
				'changelog'   => wpautop( esc_html( $remote['changelog'] ) ),
			),
		);
	}

	/**
	 * Ask the licence server about the latest build, with caching.
	 *
	 * @return array<string, string> Empty array when there is nothing to offer.
	 */
	private function remote_info() {
		if ( ! License::is_valid() ) {
			return array();
		}

		$cached = get_transient( self::TRANSIENT );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		if ( get_transient( self::TRANSIENT_FAIL ) ) {
			return array();
		}

		$response = Api::update( License::get()['key'] );

		if ( is_wp_error( $response ) ) {
			/*
			 * Back off for an hour. The updates screen is loaded often, and a
			 * server that is down would otherwise be asked on every one of
			 * those requests, delaying the screen each time.
			 */
			set_transient( self::TRANSIENT_FAIL, 1, self::FAILURE_SECONDS );

			return array();
		}

		$info = array(
			'version'      => isset( $response['version'] ) ? (string) $response['version'] : '',
			'download_url' => isset( $response['download_url'] ) ? esc_url_raw( (string) $response['download_url'] ) : '',
			'homepage'     => isset( $response['homepage'] ) ? esc_url_raw( (string) $response['homepage'] ) : 'https://www.at8.fun/',
			'requires'     => isset( $response['requires'] ) ? (string) $response['requires'] : '6.4',
			'tested'       => isset( $response['tested'] ) ? (string) $response['tested'] : '7.1',
			'requires_php' => isset( $response['requires_php'] ) ? (string) $response['requires_php'] : AT8SEC_PRO_MIN_PHP,
			'changelog'    => isset( $response['changelog'] ) ? (string) $response['changelog'] : '',
			'last_updated' => isset( $response['last_updated'] ) ? (string) $response['last_updated'] : '',
		);

		// Without both a version and somewhere to download it from there is
		// nothing to offer, so do not cache a half-answer as if it were one.
		if ( '' === $info['version'] || '' === $info['download_url'] ) {
			set_transient( self::TRANSIENT_FAIL, 1, self::FAILURE_SECONDS );

			return array();
		}

		set_transient( self::TRANSIENT, $info, self::CACHE_SECONDS );

		return $info;
	}
}
