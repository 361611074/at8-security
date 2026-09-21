<?php
/**
 * Comment spam protection and the global "comments off" switch.
 *
 * @package At8\Security
 */

namespace At8\Security;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Module 9 — comments.
 */
class Comment_Guard {

	/**
	 * Register hooks.
	 */
	public function __construct() {
		if ( Settings::on( 'comment_spam' ) ) {
			add_action( 'wp_enqueue_scripts', __NAMESPACE__ . '\\enqueue_front_style' );
			add_action( 'comment_form_after_fields', array( $this, 'render_honeypot' ) );
			add_action( 'comment_form_logged_in_after', array( $this, 'render_honeypot' ) );
			add_filter( 'preprocess_comment', array( $this, 'screen_comment' ), 1 );
		}

		if ( Settings::on( 'disable_comments' ) ) {
			add_filter( 'comments_open', '__return_false', 20 );
			add_filter( 'pings_open', '__return_false', 20 );
			add_filter( 'comments_template', array( $this, 'blank_template' ), 20 );

			// Priority 99 so post types registered late on init are still covered —
			// running at the default priority misses them, which is the same
			// timing trap that previously affected the ID-column plugin.
			add_action( 'init', array( $this, 'drop_comment_support' ), 99 );
			add_action( 'admin_menu', array( $this, 'hide_admin_menu' ) );
			add_action( 'wp_before_admin_bar_render', array( $this, 'hide_admin_bar_node' ) );
		}
	}

	/**
	 * Render the honeypot field in the comment form.
	 *
	 * @return void
	 */
	public function render_honeypot() {
		printf(
			'<p class="at8sec-hp" aria-hidden="true"><label for="at8sec_hp_site">%1$s</label> <input type="text" name="at8sec_hp_site" id="at8sec_hp_site" value="" tabindex="-1" autocomplete="off" /></p>',
			esc_html__( 'Website address', 'at8-security' )
		);
	}

	/**
	 * Inspect an incoming comment.
	 *
	 * @param array $commentdata Comment data.
	 * @return array
	 */
	public function screen_comment( $commentdata ) {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- checked against a server-side rule, no state change.
		$honeypot = isset( $_POST['at8sec_hp_site'] ) ? sanitize_text_field( wp_unslash( $_POST['at8sec_hp_site'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( '' !== $honeypot ) {
			/*
			 * Answer with an empty 200 instead of an error: a bot that filled
			 * in the trap simply gets no signal about what went wrong, while
			 * nothing is written to the database.
			 */
			wp_die( '', '', array( 'response' => 200 ) );
		}

		if ( ! is_user_logged_in() ) {
			$key = 'at8sec_comment_' . ip_hash( get_client_ip() );

			if ( get_transient( $key ) ) {
				wp_die(
					esc_html__( 'You are commenting too quickly. Please wait a moment and try again.', 'at8-security' ),
					'',
					array( 'response' => 429 )
				);
			}

			set_transient( $key, 1, MINUTE_IN_SECONDS );
		}

		return $commentdata;
	}

	/**
	 * Remove comment support from every public post type.
	 *
	 * @return void
	 */
	public function drop_comment_support() {
		foreach ( get_post_types( array(), 'names' ) as $post_type ) {
			if ( post_type_supports( $post_type, 'comments' ) ) {
				remove_post_type_support( $post_type, 'comments' );
			}

			if ( post_type_supports( $post_type, 'trackbacks' ) ) {
				remove_post_type_support( $post_type, 'trackbacks' );
			}
		}
	}

	/**
	 * Hide the Comments admin menu.
	 *
	 * @return void
	 */
	public function hide_admin_menu() {
		remove_menu_page( 'edit-comments.php' );
	}

	/**
	 * Hide the Comments node in the admin bar.
	 *
	 * @return void
	 */
	public function hide_admin_bar_node() {
		global $wp_admin_bar;

		if ( is_object( $wp_admin_bar ) ) {
			$wp_admin_bar->remove_node( 'comments' );
		}
	}

	/**
	 * Swap in an empty template so existing comments are not printed either.
	 *
	 * @param string $template Current template path.
	 * @return string
	 */
	public function blank_template( $template ) {
		$blank = AT8SEC_DIR . 'templates/comments-closed.php';

		return file_exists( $blank ) ? $blank : $template;
	}
}
