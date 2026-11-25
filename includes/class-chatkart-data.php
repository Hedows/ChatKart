<?php
/**
 * Anonymous click statistics (time, agent and page only: no names, IP addresses or cookies).
 *
 * @package ChatKart
 */

defined( 'ABSPATH' ) || exit;

final class ChatKart_Data {

	const DB_VERSION = '1';

	/** @var ChatKart_Data|null */
	private static $instance = null;

	/**
	 * @return ChatKart_Data
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		if ( get_option( 'chatkart_db_version' ) !== self::DB_VERSION && ! get_transient( 'chatkart_db_lock' ) ) {
			set_transient( 'chatkart_db_lock', 1, HOUR_IN_SECONDS ); // do not retry on every request if the database user cannot create tables.
			self::install();
		}
		add_action( 'chatkart_purge', array( $this, 'purge' ) );
		if ( ! wp_next_scheduled( 'chatkart_purge' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'chatkart_purge' );
		}
		add_action( 'wp_ajax_chatkart_track', array( $this, 'ajax_track' ) );
		add_action( 'wp_ajax_nopriv_chatkart_track', array( $this, 'ajax_track' ) );
	}

	/** Create / update the statistics table. */
	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		dbDelta( "CREATE TABLE {$wpdb->prefix}chatkart_events (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  created datetime NOT NULL,
  source varchar(20) NOT NULL DEFAULT '',
  agent varchar(80) NOT NULL DEFAULT '',
  page varchar(255) NOT NULL DEFAULT '',
  PRIMARY KEY  (id),
  KEY created (created)
) $charset;" );
		update_option( 'chatkart_db_version', self::DB_VERSION, false );
	}

	/** Delete statistics older than 400 days. */
	public function purge() {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}chatkart_events WHERE created < %s", gmdate( 'Y-m-d H:i:s', time() - 400 * DAY_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Write one analytics event.
	 *
	 * @param string $source float|widget|order.
	 * @param string $agent  Agent name.
	 * @param string $page   Page URL.
	 */
	public static function log( $source, $agent = '', $page = '' ) {
		global $wpdb;
		if ( ! ChatKart_Settings::is( 'widget_track' ) ) {
			return;
		}
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prefix . 'chatkart_events',
			array(
				'created' => gmdate( 'Y-m-d H:i:s' ),
				'source'  => substr( sanitize_key( $source ), 0, 20 ),
				'agent'   => function_exists( 'mb_substr' ) ? mb_substr( (string) $agent, 0, 80 ) : substr( (string) $agent, 0, 80 ),
				'page'    => ChatKart_Helpers::own_url( $page ),
			),
			array( '%s', '%s', '%s', '%s' )
		);
	}

	/* ---------------------------------------------------------------- public AJAX */

	public function ajax_track() {
		nocache_headers();
		if ( ChatKart_Settings::is( 'enabled' ) && ChatKart_Helpers::rate_ok( 'track', 60 ) ) {
			$source = isset( $_POST['source'] ) ? sanitize_key( wp_unslash( $_POST['source'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification -- public analytics beacon, rate limited.
			if ( in_array( $source, array( 'float', 'widget' ), true ) ) {
				$agent = isset( $_POST['agent'] ) ? substr( sanitize_text_field( wp_unslash( $_POST['agent'] ) ), 0, 80 ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
				$page  = isset( $_POST['page'] ) ? esc_url_raw( wp_unslash( $_POST['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
				self::log( $source, $agent, $page );
			}
		}
		wp_send_json_success();
	}

	/* ---------------------------------------------------------------- admin: analytics */

	public static function render_analytics() {
		global $wpdb;
		$t     = $wpdb->prefix . 'chatkart_events';
		$since = static function ( $days ) {
			return gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
		};
		echo '<h2 class="title">' . esc_html__( 'Click analytics', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ) . '</h2>';
		if ( ! ChatKart_Settings::is( 'widget_track' ) ) {
			echo '<div class="notice notice-info inline"><p>' . esc_html__( 'Click counting is switched off (Floating button tab → Chat popup → Count clicks).', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ) . '</p></div>';
		}
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL -- table name is built from the prefix only.
		$d7  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE created >= %s", $since( 7 ) ) );
		$d30 = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE created >= %s", $since( 30 ) ) );
		$all = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t}" );
		// phpcs:enable
		echo '<table class="widefat" style="max-width:520px"><tbody>';
		echo '<tr><th>' . esc_html__( 'Chats started, last 7 days', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ) . '</th><td>' . (int) $d7 . '</td></tr>';
		echo '<tr><th>' . esc_html__( 'Last 30 days', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ) . '</th><td>' . (int) $d30 . '</td></tr>';
		echo '<tr><th>' . esc_html__( 'All time', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ) . '</th><td>' . (int) $all . '</td></tr>';
		echo '</tbody></table>';
		echo '<p class="description">' . esc_html__( 'Counts chat button clicks and WhatsApp order submissions. Statistics older than 400 days are deleted automatically.', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ) . '</p>';
	}
}
