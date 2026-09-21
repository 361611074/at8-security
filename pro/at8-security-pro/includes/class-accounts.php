<?php
/**
 * Inactive-account review.
 *
 * An account that nobody signs in to is an unlocked door that nobody is
 * watching. WordPress does not record when an account was last used, so this
 * keeps a note of its own — once a day at most, so the note costs nothing on a
 * busy site.
 *
 * The report only reports. Downgrading a role or deleting an account from a
 * button on a security screen is not something this plugin is willing to do:
 * the account that looks abandoned may be the one the owner uses twice a year,
 * and the decision of what to do about it belongs to a person.
 *
 * @package At8\Security\Pro
 */

namespace At8\Security\Pro;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Last-seen tracking and the idle-account report.
 */
class Accounts {

	/**
	 * User meta key holding the last time the account was used.
	 */
	const META_LAST_SEEN = 'at8sec_pro_last_seen';

	/**
	 * Register hooks.
	 */
	public function __construct() {
		add_action( 'wp_login', array( $this, 'touch_on_login' ), 10, 2 );
		add_action( 'admin_init', array( $this, 'touch_current_user' ), 20 );
	}

	/**
	 * Record a sign-in.
	 *
	 * @param string   $user_login Login name.
	 * @param \WP_User $user       User, on WordPress versions that pass it.
	 * @return void
	 */
	public function touch_on_login( $user_login, $user = null ) {
		unset( $user_login );

		if ( $user instanceof \WP_User ) {
			update_user_meta( $user->ID, self::META_LAST_SEEN, time() );
		}
	}

	/**
	 * Record ordinary use of the dashboard.
	 *
	 * A person who stays signed in for months still "uses" their account, and
	 * a report that calls them idle would be wrong. The write happens at most
	 * once a day per account; on every other request this only reads.
	 *
	 * @return void
	 */
	public function touch_current_user() {
		$user_id = get_current_user_id();

		if ( ! $user_id ) {
			return;
		}

		$stored = (int) get_user_meta( $user_id, self::META_LAST_SEEN, true );

		if ( $stored > ( time() - DAY_IN_SECONDS ) ) {
			return;
		}

		update_user_meta( $user_id, self::META_LAST_SEEN, time() );
	}

	/**
	 * How many days without use counts as inactive.
	 *
	 * @return int
	 */
	public static function threshold_days() {
		return max( 1, (int) Settings::get( 'idle_days', 90 ) );
	}

	/**
	 * When an account was last used.
	 *
	 * @param int $user_id User ID.
	 * @return int Unix time, or 0 when nothing has been recorded.
	 */
	public static function last_seen( $user_id ) {
		return (int) get_user_meta( $user_id, self::META_LAST_SEEN, true );
	}

	/**
	 * Days since an account was last used.
	 *
	 * @param int $user_id User ID.
	 * @return int|null Null when nothing has been recorded.
	 */
	public static function idle_days( $user_id ) {
		$last = self::last_seen( $user_id );

		if ( $last < 1 ) {
			return null;
		}

		return (int) floor( ( time() - $last ) / DAY_IN_SECONDS );
	}

	/**
	 * Look through every account and sort out which ones deserve a second look.
	 *
	 * Two lists come back, and the difference matters:
	 *
	 * idle  — a recorded last use that is further back than the threshold, so
	 *         the account is known to have gone quiet;
	 * never — nothing recorded. That means the account has not been used since
	 *         this plugin started counting, which is worth a look but is not
	 *         the same claim as "unused for a year".
	 *
	 * @param int $limit Most accounts to return per list.
	 * @return array{idle:array<int, array{user:\WP_User, last:int, days:int}>, never:\WP_User[], total:int, threshold:int}
	 */
	public static function report( $limit = 100 ) {
		$limit     = max( 1, (int) $limit );
		$threshold = self::threshold_days();
		$seen      = get_users(
			array(
				'meta_key' => self::META_LAST_SEEN, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- deliberate: a report run on demand.
				'orderby'  => 'meta_value_num',
				'order'    => 'ASC',
				'number'   => $limit,
				'fields'   => 'all',
			)
		);

		$idle = array();

		foreach ( (array) $seen as $user ) {
			$days = self::idle_days( $user->ID );

			if ( null === $days || $days < $threshold ) {
				continue;
			}

			$idle[] = array(
				'user' => $user,
				'last' => self::last_seen( $user->ID ),
				'days' => $days,
			);
		}

		$never = get_users(
			array(
				'number'     => $limit,
				'fields'     => 'all',
				'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- deliberate: a report run on demand.
					array(
						'key'     => self::META_LAST_SEEN,
						'compare' => 'NOT EXISTS',
					),
				),
			)
		);

		$counts = count_users();

		return array(
			'idle'      => $idle,
			'never'     => array_values( (array) $never ),
			'total'     => isset( $counts['total_users'] ) ? (int) $counts['total_users'] : 0,
			'threshold' => $threshold,
		);
	}

	/**
	 * Roles an account holds, as a readable list.
	 *
	 * @param \WP_User $user User.
	 * @return string
	 */
	public static function role_label( $user ) {
		if ( ! $user instanceof \WP_User ) {
			return '';
		}

		$names = array();
		$all   = wp_roles()->get_names();

		foreach ( (array) $user->roles as $role ) {
			$names[] = isset( $all[ $role ] ) ? translate_user_role( $all[ $role ] ) : $role;
		}

		return $names ? implode( ', ', $names ) : __( 'No role', 'at8-security-pro' );
	}
}
