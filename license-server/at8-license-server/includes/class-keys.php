<?php
/**
 * Licence records and the decisions made about them.
 *
 * @package At8\License
 */

namespace At8\License;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Keys, site bindings and the four answers the client can receive.
 */
class Keys {

	const STATUS_ACTIVE    = 'active';
	const STATUS_DISABLED  = 'disabled';
	const STATUS_SUSPENDED = 'suspended';
	const STATUS_REVOKED   = 'revoked';

	/**
	 * Every status a licence may hold.
	 *
	 * `suspended` sits between disabled and revoked on purpose: a failed
	 * payment or a support dispute should be reversible, while doing it with
	 * `revoked` reads as permanent and cannot be walked back in the audit
	 * trail. A suspended key answers exactly like a disabled one.
	 *
	 * @return string[]
	 */
	public static function statuses() {
		return array( self::STATUS_ACTIVE, self::STATUS_DISABLED, self::STATUS_SUSPENDED, self::STATUS_REVOKED );
	}

	/**
	 * Every licence, newest first.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function all() {
		global $wpdb;

		$names = tables();

		/*
		 * Table names go through %i, the identifier placeholder added in WP 6.2.
		 * Interpolating them instead is what this plugin did at first and it is
		 * the one thing a static analyser cannot tell apart from a genuine
		 * injection, because it only sees a variable inside the statement.
		 */
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM %i ORDER BY id DESC', $names['licenses'] ),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * One licence by id.
	 *
	 * @param int $id Licence id.
	 * @return array<string, mixed>|null
	 */
	public static function get( $id ) {
		global $wpdb;

		$names = tables();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $names['licenses'], (int) $id ),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * One licence by key, however the key was typed.
	 *
	 * @param string $key Raw key.
	 * @return array<string, mixed>|null
	 */
	public static function find( $key ) {
		global $wpdb;

		$norm = normalize_key( $key );

		if ( '' === $norm ) {
			return null;
		}

		$names = tables();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE key_norm = %s', $names['licenses'], $norm ),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Issue a new licence.
	 *
	 * @param array<string, mixed> $args product, plan, days, max_sites, note.
	 * @return int|\WP_Error New licence id, or an error.
	 */
	public static function issue( $args ) {
		global $wpdb;

		$product = isset( $args['product'] ) ? sanitize_key( (string) $args['product'] ) : '';

		if ( '' === $product || ! isset( products()[ $product ] ) ) {
			return new \WP_Error( 'at8ls_bad_product', __( 'Choose a product for this licence.', 'at8-license-server' ) );
		}

		$days      = isset( $args['days'] ) ? (int) $args['days'] : 0;
		$max_sites = isset( $args['max_sites'] ) ? (int) $args['max_sites'] : 1;
		$note      = isset( $args['note'] ) ? sanitize_text_field( (string) $args['note'] ) : '';
		$names     = tables();
		$now       = time();

		// A duplicate is astronomically unlikely, but the unique index would
		// reject it as a database error rather than a retry, so loop instead.
		for ( $attempt = 0; $attempt < 5; $attempt++ ) {
			$key  = generate_key( $product );
			$norm = normalize_key( $key );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$exists = $wpdb->get_var(
				$wpdb->prepare( 'SELECT id FROM %i WHERE key_norm = %s', $names['licenses'], $norm )
			);

			if ( $exists ) {
				continue;
			}

			$data = array(
				'license_key' => $key,
				'key_norm'    => $norm,
				'product'     => $product,
				'plan'        => isset( $args['plan'] ) && '' !== $args['plan'] ? sanitize_key( (string) $args['plan'] ) : 'lifetime',
				'status'      => self::STATUS_ACTIVE,
				'expires'     => $days > 0 ? $now + ( $days * DAY_IN_SECONDS ) : 0,
				'max_sites'   => max( 0, $max_sites ),
				'note'        => $note,
				'created_at'  => $now,
				'created_by'  => get_current_user_id(),
				'updated_at'  => $now,
			);

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$ok = $wpdb->insert(
				$names['licenses'],
				$data,
				array( '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%d', '%d', '%d' )
			);

			if ( $ok ) {
				$id = (int) $wpdb->insert_id;

				Audit::record(
					'license.created',
					'license',
					$id,
					array(
						'license_id' => $id,
						'product'    => $product,
						'plan'       => (string) $data['plan'],
						'days'       => $days,
						'max_sites'  => (int) $data['max_sites'],
					)
				);

				return $id;
			}
		}

		return new \WP_Error( 'at8ls_insert_failed', __( 'The licence could not be stored.', 'at8-license-server' ) );
	}

	/**
	 * Change a licence's status.
	 *
	 * @param int    $id     Licence id.
	 * @param string $status New status.
	 * @return bool
	 */
	public static function set_status( $id, $status ) {
		global $wpdb;

		if ( ! in_array( $status, self::statuses(), true ) ) {
			return false;
		}

		$before = self::get( $id );

		if ( ! $before ) {
			return false;
		}

		$names = tables();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ok = $wpdb->update(
			$names['licenses'],
			array(
				'status'     => $status,
				'updated_at' => time(),
			),
			array( 'id' => (int) $id ),
			array( '%s', '%d' ),
			array( '%d' )
		);

		if ( false === $ok ) {
			return false;
		}

		/*
		 * A status change is the decision a customer will ask about later, so
		 * it is recorded with both ends of the move.
		 */
		Audit::record(
			'license.' . $status,
			'license',
			(int) $id,
			array(
				'license_id' => (int) $id,
				'product'    => (string) $before['product'],
				'from'       => (string) $before['status'],
				'to'         => (string) $status,
			)
		);

		return true;
	}

	/**
	 * Change how many sites a licence may run on.
	 *
	 * @param int $id   Licence id.
	 * @param int $sites New limit, 0 for unlimited.
	 * @return bool
	 */
	public static function set_max_sites( $id, $sites ) {
		global $wpdb;

		$names = tables();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ok = $wpdb->update(
			$names['licenses'],
			array(
				'max_sites'  => max( 0, (int) $sites ),
				'updated_at' => time(),
			),
			array( 'id' => (int) $id ),
			array( '%d', '%d' ),
			array( '%d' )
		);

		if ( false === $ok ) {
			return false;
		}

		Audit::record(
			'license.sites_changed',
			'license',
			(int) $id,
			array(
				'license_id' => (int) $id,
				'max_sites'  => max( 0, (int) $sites ),
			)
		);

		return true;
	}

	/**
	 * Update the note on a licence.
	 *
	 * @param int    $id   Licence id.
	 * @param string $note New note.
	 * @return bool
	 */
	public static function set_note( $id, $note ) {
		global $wpdb;

		$names = tables();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ok = $wpdb->update(
			$names['licenses'],
			array(
				'note'       => sanitize_text_field( (string) $note ),
				'updated_at' => time(),
			),
			array( 'id' => (int) $id ),
			array( '%s', '%d' ),
			array( '%d' )
		);

		if ( false === $ok ) {
			return false;
		}

		/*
		 * The note itself is not copied into the log. It is free text that
		 * tends to hold a customer's name or order number, and the audit trail
		 * only needs to show that somebody edited it.
		 */
		Audit::record(
			'license.note_changed',
			'license',
			(int) $id,
			array( 'license_id' => (int) $id )
		);

		return true;
	}

	/**
	 * Delete a licence and its site bindings.
	 *
	 * @param int $id Licence id.
	 * @return bool
	 */
	public static function delete( $id ) {
		global $wpdb;

		$names = tables();
		$row   = self::get( $id );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( $names['sites'], array( 'license_id' => (int) $id ), array( '%d' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ok = $wpdb->delete( $names['licenses'], array( 'id' => (int) $id ), array( '%d' ) );

		if ( false === $ok ) {
			return false;
		}

		/*
		 * The log entry outlives the licence: it is the only remaining proof
		 * that this key ever existed, and the log is never deleted with a
		 * licence, only with the plugin.
		 */
		Audit::record(
			'license.deleted',
			'license',
			(int) $id,
			array(
				'license_id' => (int) $id,
				'product'    => $row ? (string) $row['product'] : '',
				'status'     => $row ? (string) $row['status'] : '',
			)
		);

		return true;
	}

	/**
	 * Sites bound to a licence.
	 *
	 * @param int $license_id Licence id.
	 * @return array<int, array<string, mixed>>
	 */
	public static function sites_for( $license_id ) {
		global $wpdb;

		$names = tables();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM %i WHERE license_id = %d ORDER BY id ASC', $names['sites'], (int) $license_id ),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Every binding, with the key it belongs to.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function all_sites() {
		global $wpdb;

		$names = tables();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT s.*, l.license_key, l.product, l.status AS license_status
				FROM %i s
				LEFT JOIN %i l ON l.id = s.license_id
				ORDER BY s.last_seen DESC, s.id DESC',
				$names['sites'],
				$names['licenses']
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Release one site from a licence.
	 *
	 * @param int $site_id Binding id.
	 * @return bool
	 */
	public static function unbind( $site_id ) {
		global $wpdb;

		$names = tables();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $names['sites'], (int) $site_id ),
			ARRAY_A
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ok = $wpdb->delete( $names['sites'], array( 'id' => (int) $site_id ), array( '%d' ) );

		if ( false === $ok ) {
			return false;
		}

		Audit::record(
			'site.removed',
			'license',
			is_array( $row ) ? (int) $row['license_id'] : 0,
			array(
				'license_id'  => is_array( $row ) ? (int) $row['license_id'] : 0,
				'domain'      => is_array( $row ) ? (string) $row['domain'] : '',
				'environment' => is_array( $row ) ? (string) $row['environment'] : '',
				'by'          => 'admin',
			)
		);

		return true;
	}

	/**
	 * Is this site already bound to this licence?
	 *
	 * @param int    $license_id Licence id.
	 * @param string $domain     Normalised domain.
	 * @return array<string, mixed>|null
	 */
	private static function binding( $license_id, $domain ) {
		global $wpdb;

		$names = tables();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE license_id = %d AND domain = %s',
				$names['sites'],
				(int) $license_id,
				$domain
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * How many sites a licence currently runs on.
	 *
	 * @param int $license_id Licence id.
	 * @return int
	 */
	public static function site_count( $license_id ) {
		global $wpdb;

		$names = tables();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$count = $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE license_id = %d', $names['sites'], (int) $license_id )
		);

		return (int) $count;
	}

	/**
	 * Answer an activate or validate call.
	 *
	 * @param string               $key     Raw key from the client.
	 * @param array<string, mixed> $payload Request body.
	 * @param bool                 $bind    Whether to add a site binding.
	 * @return array<string, mixed> Response body.
	 */
	public static function verify( $key, $payload, $bind ) {
		$domain  = normalize_domain( self::domain_from( $payload ) );
		$product = isset( $payload['product'] ) ? sanitize_key( (string) $payload['product'] ) : '';

		if ( '' === $key ) {
			return self::answer( false, 'rejected', __( 'No licence key was sent.', 'at8-license-server' ) );
		}

		if ( '' === $domain ) {
			return self::answer( false, 'rejected', __( 'No site domain was sent.', 'at8-license-server' ) );
		}

		$row = self::find( $key );

		if ( ! $row ) {
			/*
			 * A key nobody issued being presented is either a typo or somebody
			 * guessing. Both are worth seeing in the log; neither is stored
			 * with the key itself.
			 */
			self::record_failure( 'unknown_key', $domain, $product );

			return self::answer( false, 'rejected', __( 'This licence key is not recognised.', 'at8-license-server' ) );
		}

		if ( self::STATUS_ACTIVE !== (string) $row['status'] ) {
			self::record_failure( (string) $row['status'], $domain, $product, (int) $row['id'] );

			$messages = array(
				self::STATUS_REVOKED   => __( 'This licence key has been revoked.', 'at8-license-server' ),
				self::STATUS_SUSPENDED => __( 'This licence key has been suspended. Please contact support.', 'at8-license-server' ),
			);

			return self::answer(
				false,
				'inactive',
				isset( $messages[ (string) $row['status'] ] )
					? $messages[ (string) $row['status'] ]
					: __( 'This licence key has been disabled.', 'at8-license-server' )
			);
		}

		if ( '' !== $product && (string) $row['product'] !== $product ) {
			self::record_failure( 'wrong_product', $domain, $product, (int) $row['id'] );

			return self::answer( false, 'rejected', __( 'This licence key belongs to a different product.', 'at8-license-server' ) );
		}

		$expires = (int) $row['expires'];

		if ( $expires > 0 && $expires < time() ) {
			self::record_failure( 'expired', $domain, $product, (int) $row['id'] );

			return self::answer(
				false,
				'expired',
				__( 'This licence key has expired.', 'at8-license-server' ),
				array( 'expires' => $expires )
			);
		}

		$existing = self::binding( (int) $row['id'], $domain );
		$max      = (int) $row['max_sites'];

		if ( ! $existing && ! $bind ) {
			/*
			 * A validate call from a site that is not bound here means the site
			 * was released from the admin screen, or is a copy of another
			 * install. Say so rather than answering "valid" for a site that was
			 * never activated.
			 */
			self::record_failure( 'not_bound', $domain, $product, (int) $row['id'] );

			return self::answer( false, 'inactive', __( 'This site is not activated with this key.', 'at8-license-server' ) );
		}

		if ( ! $existing && $max > 0 && self::site_count( (int) $row['id'] ) >= $max ) {
			self::record_failure( 'seat_limit', $domain, $product, (int) $row['id'] );

			return self::answer(
				false,
				'rejected',
				sprintf(
					/* translators: 1: number of sites the key allows, 2: current site. */
					__( 'This key is already active on %1$d site(s). Release it there first, or ask for the allowance to be raised.', 'at8-license-server' ),
					$max
				),
				array(
					'expires'   => $expires,
					'max_sites' => $max,
				)
			);
		}

		if ( ! $existing ) {
			self::bind_site( (int) $row['id'], $domain, $payload );

			Audit::record(
				'site.activated',
				'license',
				(int) $row['id'],
				array(
					'license_id'  => (int) $row['id'],
					'product'     => (string) $row['product'],
					'domain'      => $domain,
					'environment' => normalize_environment( isset( $payload['environment'] ) ? (string) $payload['environment'] : '' ),
				)
			);
		} else {
			self::touch_site( (int) $existing['id'], $payload );
		}

		return self::answer(
			true,
			'active',
			__( 'Licence active.', 'at8-license-server' ),
			array(
				'expires' => $expires,
				'plan'    => (string) $row['plan'],
			)
		);
	}

	/**
	 * Record a new site against a licence.
	 *
	 * @param int                  $license_id Licence id.
	 * @param string               $domain     Normalised domain.
	 * @param array<string, mixed> $payload    Request body.
	 * @return bool
	 */
	private static function bind_site( $license_id, $domain, $payload ) {
		global $wpdb;

		$names = tables();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ok = $wpdb->insert(
			$names['sites'],
			array(
				'license_id'   => $license_id,
				'domain'       => $domain,
				'site_url'     => site_url_from( $payload, 'site_url' ),
				'home_url'     => site_url_from( $payload, 'home_url' ),
				'site_hash'    => site_hash( $domain ),
				'environment'  => normalize_environment( isset( $payload['environment'] ) ? (string) $payload['environment'] : '' ),
				'activated_at' => time(),
				'last_seen'    => time(),
				'last_ip'      => client_ip(),
				'wp_version'   => payload_version( $payload, array( 'wp_version', 'wp' ) ),
				'php_version'  => payload_version( $payload, array( 'php_version', 'php' ) ),
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s' )
		);

		return (bool) $ok;
	}

	/**
	 * Note that a bound site asked again.
	 *
	 * @param int                  $site_id Binding id.
	 * @param array<string, mixed> $payload Request body.
	 * @return void
	 */
	private static function touch_site( $site_id, $payload ) {
		global $wpdb;

		$names = tables();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$names['sites'],
			array(
				'last_seen'   => time(),
				'last_ip'     => client_ip(),
				'wp_version'  => payload_version( $payload, array( 'wp_version', 'wp' ) ),
				'php_version' => payload_version( $payload, array( 'php_version', 'php' ) ),
				'environment' => normalize_environment( isset( $payload['environment'] ) ? (string) $payload['environment'] : '' ),
			),
			array( 'id' => (int) $site_id ),
			array( '%d', '%s', '%s', '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Release a site from whichever licence it is bound to.
	 *
	 * @param string               $key     Raw key.
	 * @param array<string, mixed> $payload Request body.
	 * @return array<string, mixed> Response body.
	 */
	public static function release( $key, $payload ) {
		global $wpdb;

		$domain = normalize_domain( self::domain_from( $payload ) );
		$row    = self::find( $key );

		if ( ! $row || '' === $domain ) {
			// Nothing to release. The client drops its own record either way,
			// so there is no reason to make this an error.
			return array(
				'valid'   => true,
				'message' => __( 'Nothing to release.', 'at8-license-server' ),
			);
		}

		$names = tables();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete(
			$names['sites'],
			array(
				'license_id' => (int) $row['id'],
				'domain'     => $domain,
			),
			array( '%d', '%s' )
		);

		Audit::record(
			'site.released',
			'license',
			(int) $row['id'],
			array(
				'license_id' => (int) $row['id'],
				'domain'     => $domain,
				'by'         => 'client',
			)
		);

		return array(
			'valid'   => true,
			'message' => __( 'Released. The key can now be used on another site.', 'at8-license-server' ),
		);
	}

	/**
	 * The site identity from a request, whichever field it arrived in.
	 *
	 * `domain` is what the first client sends; `site_url` is the documented
	 * field. A full URL is fine either way — normalize_domain() reduces it to
	 * a hostname, so https://www.example.com/ and example.com are one site.
	 *
	 * @param array<string, mixed> $payload Request body.
	 * @return string
	 */
	private static function domain_from( $payload ) {
		foreach ( array( 'domain', 'site_url', 'home_url' ) as $field ) {
			if ( isset( $payload[ $field ] ) && '' !== (string) $payload[ $field ] ) {
				return (string) $payload[ $field ];
			}
		}

		return '';
	}

	/**
	 * Write down a call that was turned away.
	 *
	 * Only refusals are logged, never a routine heartbeat: a working licence
	 * checking in twice a day would fill the table with rows nobody reads,
	 * while a refusal is either a customer in trouble or somebody guessing —
	 * both of which are worth being able to look up.
	 *
	 * @param string $reason  Why it was refused.
	 * @param string $domain  Normalised domain.
	 * @param string $product Product slug from the request.
	 * @param int    $license_id Licence id, 0 when the key was unknown.
	 * @return void
	 */
	private static function record_failure( $reason, $domain, $product, $license_id = 0 ) {
		Audit::record(
			'license.refused',
			'license',
			(int) $license_id,
			array(
				'license_id' => (int) $license_id,
				'reason'     => (string) $reason,
				'domain'     => (string) $domain,
				'product'    => (string) $product,
			)
		);
	}

	/**
	 * Shape a response the client understands.
	 *
	 * @param bool                 $valid   Whether the key is good for this site.
	 * @param string               $status  Status the client should store.
	 * @param string               $message Human-readable explanation.
	 * @param array<string, mixed> $extra   expires, plan, max_sites.
	 * @return array<string, mixed>
	 */
	private static function answer( $valid, $status, $message, $extra = array() ) {
		return array_merge(
			array(
				'valid'   => (bool) $valid,
				'status'  => (string) $status,
				'expires' => 0,
				'plan'    => '',
				'message' => (string) $message,
			),
			$extra
		);
	}
}
