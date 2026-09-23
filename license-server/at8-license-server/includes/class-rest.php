<?php
/**
 * The REST endpoints the add-ons talk to.
 *
 * Five routes, all under /wp-json/at8-license/v1/:
 *
 *   POST activate    bind a key to a site and answer whether it is good
 *   POST validate    re-answer that question without binding anything new
 *   POST deactivate  release a site from its key
 *   POST update      say whether a newer build exists, with a signed link
 *   GET  package     stream the package that link points at
 *
 * The first four carry the key in the POST body rather than the query string,
 * so it does not end up in access logs along the way. The package route uses a
 * signature instead of the key, because a URL is all the WordPress upgrader can
 * carry — it is short-lived and issued only to a key that just validated.
 *
 * @package At8\License
 */

namespace At8\License;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Route registration and handlers.
 */
class Rest {

	/*
	 * Two ceilings, because two different things arrive here.
	 *
	 * Most calls are a licensed site saying hello, and those must never be
	 * turned away: sites behind one carrier NAT or one hosting provider share an
	 * egress address, so a shared figure that looks generous for one site can be
	 * reached by a hundred ordinary ones. A rejected key, on the other hand, is
	 * somebody guessing, and guessing needs a tight ceiling.
	 */
	const RATE_LIMIT  = 600;
	const GUESS_LIMIT = 30;
	const RATE_WINDOW = 600;

	/**
	 * Register hooks.
	 */
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register' ) );
	}

	/**
	 * Declare the routes.
	 *
	 * @return void
	 */
	public function register() {
		$public = array( $this, 'allow_any_caller' );

		/*
		 * Two namespaces, one implementation.
		 *
		 * `at8/v1` is the platform-wide address every AT8 product will use.
		 * `at8-license/v1` is what the first build of the client was shipped
		 * with, and it keeps working: a customer's site cannot be updated and
		 * re-pointed in the same breath as the server, and a licence endpoint
		 * that vanishes during an update would lock people out of updates.
		 */
		$namespaces = array( AT8LS_REST_NAMESPACE, AT8LS_REST_CANONICAL );

		foreach ( $namespaces as $namespace ) {
			foreach ( array( 'activate', 'validate', 'deactivate', 'update' ) as $route ) {
				register_rest_route(
					$namespace,
					'/' . $route,
					array(
						'methods'             => 'POST',
						'callback'            => array( $this, 'handle_' . $route ),
						'permission_callback' => $public,
						'args'                => $this->route_args( $route ),
					)
				);
			}

			register_rest_route(
				$namespace,
				'/package',
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'handle_package' ),
					/*
					 * The token in the query string is the credential: it is
					 * signed with a site secret and expires. There is no
					 * WordPress user to check a capability against on a
					 * customer's update run.
					 */
					'permission_callback' => $public,
					'args'                => array(
						'token' => array(
							'type'        => 'string',
							'required'    => true,
							'description' => __( 'Signed download token.', 'at8-license-server' ),
						),
					),
				)
			);
		}
	}

	/**
	 * The accepted body of a licence call.
	 *
	 * Two sets of names are declared for the same values. `domain`, `wp` and
	 * `php` are what the first client sends; `site_url`, `home_url`, `wp_version`,
	 * `php_version` and `environment` are the documented shape. Accepting both
	 * is what lets the server be upgraded first — which is the only order that
	 * does not break live sites.
	 *
	 * @param string $route Route name.
	 * @return array<string, mixed>
	 */
	private function route_args( $route ) {
		return array(
			'license'     => array(
				'type'        => 'string',
				'required'    => 'deactivate' !== $route,
				'description' => __( 'The licence key.', 'at8-license-server' ),
			),
			'domain'      => array(
				'type'        => 'string',
				'required'    => false,
				'description' => __( 'Hostname of the site making the call. site_url is accepted in its place.', 'at8-license-server' ),
			),
			'site_url'    => array(
				'type'        => 'string',
				'required'    => false,
				'description' => __( 'Full URL of the site making the call.', 'at8-license-server' ),
			),
			'home_url'    => array(
				'type'        => 'string',
				'required'    => false,
				'description' => __( 'Home URL, when it differs from the site URL.', 'at8-license-server' ),
			),
			'product'     => array(
				'type'        => 'string',
				'required'    => true,
				'description' => __( 'Product slug the key is for.', 'at8-license-server' ),
			),
			'wp_version'  => array(
				'type'        => 'string',
				'required'    => false,
				'description' => __( 'WordPress version of the calling site.', 'at8-license-server' ),
			),
			'php_version' => array(
				'type'        => 'string',
				'required'    => false,
				'description' => __( 'PHP version of the calling site.', 'at8-license-server' ),
			),
			'wp'          => array(
				'type'        => 'string',
				'required'    => false,
				'description' => __( 'Legacy spelling of wp_version.', 'at8-license-server' ),
			),
			'php'         => array(
				'type'        => 'string',
				'required'    => false,
				'description' => __( 'Legacy spelling of php_version.', 'at8-license-server' ),
			),
			'environment' => array(
				'type'        => 'string',
				'required'    => false,
				'description' => __( 'production, staging or development.', 'at8-license-server' ),
			),
		);
	}

	/**
	 * The body of a call, in the shape the licence layer reads.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array<string, mixed>
	 */
	private function payload( $request ) {
		return array(
			'domain'      => (string) $request->get_param( 'domain' ),
			'site_url'    => (string) $request->get_param( 'site_url' ),
			'home_url'    => (string) $request->get_param( 'home_url' ),
			'product'     => (string) $request->get_param( 'product' ),
			'wp_version'  => (string) $request->get_param( 'wp_version' ),
			'php_version' => (string) $request->get_param( 'php_version' ),
			'wp'          => (string) $request->get_param( 'wp' ),
			'php'         => (string) $request->get_param( 'php' ),
			'environment' => (string) $request->get_param( 'environment' ),
		);
	}

	/**
	 * These endpoints are called by sites that are not logged in here.
	 *
	 * @return bool
	 */
	public function allow_any_caller() {
		return true;
	}

	/**
	 * Bind a key to the calling site.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function handle_activate( $request ) {
		$key = $this->key( $request );

		if ( ! $this->within_rate_limit() ) {
			return $this->too_many();
		}

		if ( ! $this->key_is_known( $key ) && ! $this->within_rate_limit( true ) ) {
			return $this->too_many();
		}

		return rest_ensure_response(
			Keys::verify( $key, $this->payload( $request ), true )
		);
	}

	/**
	 * Re-check a key that is already in use.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function handle_validate( $request ) {
		$key = $this->key( $request );

		if ( ! $this->within_rate_limit() ) {
			return $this->too_many();
		}

		if ( ! $this->key_is_known( $key ) && ! $this->within_rate_limit( true ) ) {
			return $this->too_many();
		}

		return rest_ensure_response(
			Keys::verify( $key, $this->payload( $request ), false )
		);
	}

	/**
	 * Release the calling site from its key.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function handle_deactivate( $request ) {
		return rest_ensure_response(
			Keys::release( $this->key( $request ), $this->payload( $request ) )
		);
	}

	/**
	 * Tell a licensed site whether there is a newer build.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function handle_update( $request ) {
		$key     = $this->key( $request );
		$product = sanitize_key( (string) $request->get_param( 'product' ) );
		$payload = $this->payload( $request );

		$payload['product'] = $product;

		// Re-check the key here: handing a download link to a site whose licence
		// has since been revoked would undo the point of revoking it.
		$check = Keys::verify( $key, $payload, false );

		if ( empty( $check['valid'] ) ) {
			return rest_ensure_response( $check );
		}

		$row     = Keys::find( $key );
		$release = Package::published( $product );

		if ( ! $row || empty( $release['version'] ) ) {
			return rest_ensure_response(
				array(
					'valid'   => false,
					'status'  => 'inactive',
					'message' => __( 'No build has been published for this product yet.', 'at8-license-server' ),
				)
			);
		}

		$url = Package::download_url( $product, (int) $row['id'] );

		if ( '' === $url ) {
			return rest_ensure_response(
				array(
					'valid'   => false,
					'status'  => 'inactive',
					'message' => __( 'The published package is missing from the server.', 'at8-license-server' ),
				)
			);
		}

		return rest_ensure_response(
			array(
				'valid'        => true,
				'version'      => (string) $release['version'],
				'download_url' => $url,
				'homepage'     => home_url( '/' ),
				'requires'     => (string) $release['requires'],
				'tested'       => (string) $release['tested'],
				'requires_php' => (string) $release['requires_php'],
				'changelog'    => (string) $release['changelog'],
				'last_updated' => isset( $release['uploaded_at'] ) ? (string) gmdate( 'Y-m-d H:i:s', (int) $release['uploaded_at'] ) : '',
			)
		);
	}

	/**
	 * Stream a package to a holder of a valid token.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return void
	 */
	public function handle_package( $request ) {
		$payload = Package::read_token( (string) $request->get_param( 'token' ) );

		if ( ! $payload ) {
			status_header( 403 );
			wp_die(
				esc_html__( 'This download link is not valid any more.', 'at8-license-server' ),
				esc_html__( 'Download link expired', 'at8-license-server' ),
				array( 'response' => 403 )
			);
		}

		/*
		 * A link is signed against a licence, so it must stop working when that
		 * licence does. Otherwise revoking a key would still hand out six more
		 * hours of downloads.
		 */
		$licence = Keys::get( (int) $payload['license_id'] );

		if ( ! $licence || Keys::STATUS_ACTIVE !== (string) $licence['status'] ) {
			status_header( 403 );
			wp_die(
				esc_html__( 'This download link is not valid any more.', 'at8-license-server' ),
				esc_html__( 'Download link expired', 'at8-license-server' ),
				array( 'response' => 403 )
			);
		}

		$expires = (int) $licence['expires'];

		if ( $expires > 0 && $expires < time() ) {
			status_header( 403 );
			wp_die(
				esc_html__( 'This download link is not valid any more.', 'at8-license-server' ),
				esc_html__( 'Download link expired', 'at8-license-server' ),
				array( 'response' => 403 )
			);
		}

		$path = Package::path( (string) $payload['product'] );

		if ( '' === $path ) {
			status_header( 404 );
			wp_die(
				esc_html__( 'The package is not available.', 'at8-license-server' ),
				esc_html__( 'Package missing', 'at8-license-server' ),
				array( 'response' => 404 )
			);
		}

		/*
		 * A download is a front-end request, and the admin file helpers are not
		 * loaded for those. Without this the filesystem call below is fatal —
		 * which is how this route answered 500 the first time it was exercised.
		 */
		require_once ABSPATH . 'wp-admin/includes/file.php';

		if ( ! WP_Filesystem() ) {
			status_header( 500 );
			wp_die(
				esc_html__( 'The package could not be read.', 'at8-license-server' ),
				esc_html__( 'Download failed', 'at8-license-server' ),
				array( 'response' => 500 )
			);
		}

		global $wp_filesystem;

		$contents = $wp_filesystem->get_contents( $path );

		if ( false === $contents ) {
			status_header( 500 );
			wp_die(
				esc_html__( 'The package could not be read.', 'at8-license-server' ),
				esc_html__( 'Download failed', 'at8-license-server' ),
				array( 'response' => 500 )
			);
		}

		/*
		 * Compare the bytes against the fingerprint taken when they were
		 * uploaded. A package that was replaced, truncated or partly written
		 * on disk is refused here rather than installed on a customer's site.
		 */
		if ( ! Package::verify_hash( (string) $payload['product'], (string) $contents ) ) {
			status_header( 500 );
			wp_die(
				esc_html__( 'The package could not be read.', 'at8-license-server' ),
				esc_html__( 'Download failed', 'at8-license-server' ),
				array( 'response' => 500 )
			);
		}

		nocache_headers();
		header( 'Content-Type: application/zip' );
		header( 'Content-Disposition: attachment; filename="' . basename( $path ) . '"' );
		header( 'Content-Length: ' . strlen( $contents ) );

		/*
		 * Binary body of a download, not markup for a page — it is the file the
		 * upgrader asked for, byte for byte, so there is nothing to escape.
		 */
		echo $contents; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		exit;
	}

	/**
	 * The key sent with a request.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return string
	 */
	private function key( $request ) {
		return (string) $request->get_param( 'license' );
	}

	/**
	 * Is this caller still inside its attempt budget?
	 *
	 * @param bool $guessing Whether this call carries a key the server knows.
	 * @return bool
	 */
	private function within_rate_limit( $guessing = false ) {
		return $guessing
			? rate_limit( 'guess', self::GUESS_LIMIT, self::RATE_WINDOW )
			: rate_limit( 'rest', self::RATE_LIMIT, self::RATE_WINDOW );
	}

	/**
	 * Does this key exist at all?
	 *
	 * @param string $key Raw key.
	 * @return bool
	 */
	private function key_is_known( $key ) {
		return null !== Keys::find( $key );
	}

	/**
	 * Who is calling, for the "too many" answer.
	 *
	 * @return \WP_REST_Response
	 */
	private function too_many() {
		return new \WP_REST_Response(
			array(
				'valid'   => false,
				'status'  => 'unreachable',
				'message' => __( 'Too many attempts. Please try again later.', 'at8-license-server' ),
			),
			429
		);
	}
}
