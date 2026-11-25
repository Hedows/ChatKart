<?php
/**
 * Hide / show WooCommerce admin menu items (sidebar) from this plugin's settings.
 *
 * This only removes menu entries. It is a tidy-up tool, not an access-control tool:
 * a user who knows a page URL can still open it. Use roles/capabilities for real restrictions.
 *
 * @package ChatKart
 */

defined( 'ABSPATH' ) || exit;

class ChatKart_Admin_Menu {

	/** @var ChatKart_Admin_Menu|null */
	private static $instance = null;

	/**
	 * @return ChatKart_Admin_Menu
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// Late, so we run after WooCommerce and WooCommerce Admin have added their items.
		add_action( 'admin_menu', array( $this, 'apply' ), PHP_INT_MAX );
		add_action( 'admin_head', array( $this, 'apply' ), 1 );
	}

	/**
	 * Which of our keys does a top-level menu entry correspond to?
	 *
	 * @param array $item Entry from global $menu.
	 * @return string Key or empty string.
	 */
	private function top_key( $item ) {
		$slug  = isset( $item[2] ) ? (string) $item[2] : '';
		$title = isset( $item[0] ) ? strtolower( trim( wp_strip_all_tags( preg_replace( '#<span.*?</span>#is', '', (string) $item[0] ) ) ) ) : '';

		if ( 'woocommerce' === $slug ) {
			return 'woocommerce';
		}
		if ( 'edit.php?post_type=product' === $slug ) {
			return 'products';
		}
		if ( false !== strpos( $slug, '/payments' ) || 'payments' === $title ) {
			return 'payments';
		}
		if ( false !== strpos( $slug, '/analytics' ) || 'analytics' === $title ) {
			return 'analytics';
		}
		if ( 'woocommerce-marketing' === $slug || false !== strpos( $slug, '/marketing' ) || 'marketing' === $title ) {
			return 'marketing';
		}
		return '';
	}

	/**
	 * Which of our keys does a WooCommerce submenu entry correspond to?
	 *
	 * @param string $slug Submenu slug.
	 * @return string
	 */
	private function sub_key( $slug ) {
		$slug = (string) $slug;
		if ( 'wc-admin' === $slug ) {
			return 'home';
		}
		if ( 0 === strpos( $slug, 'wc-orders' ) || false !== strpos( $slug, 'post_type=shop_order' ) ) {
			return 'orders';
		}
		if ( false !== strpos( $slug, '/customers' ) ) {
			return 'customers';
		}
		if ( false !== strpos( $slug, 'post_type=shop_coupon' ) ) {
			return 'coupons';
		}
		if ( 0 === strpos( $slug, 'wc-reports' ) ) {
			return 'reports';
		}
		if ( 0 === strpos( $slug, 'wc-settings' ) ) {
			return 'settings';
		}
		if ( 0 === strpos( $slug, 'wc-status' ) ) {
			return 'status';
		}
		if ( false !== strpos( $slug, '/extensions' ) || 0 === strpos( $slug, 'wc-addons' ) ) {
			return 'extensions';
		}
		return '';
	}

	public function apply() {
		global $menu, $submenu;

		if ( ! is_admin() || ! current_user_can( 'edit_posts' ) ) {
			return;
		}

		// Top-level items.
		if ( is_array( $menu ) ) {
			$remove = array();
			foreach ( $menu as $item ) {
				$key = $this->top_key( $item );
				if ( '' !== $key && ChatKart_Settings::menu_hidden( $key ) ) {
					$remove[] = $item[2];
				}
			}
			foreach ( $remove as $slug ) {
				remove_menu_page( $slug );
			}
		}

		// WooCommerce submenus (only matter while the WooCommerce menu is visible).
		if ( ! ChatKart_Settings::menu_hidden( 'woocommerce' ) && isset( $submenu['woocommerce'] ) && is_array( $submenu['woocommerce'] ) ) {
			$remove = array();
			foreach ( $submenu['woocommerce'] as $sub ) {
				$key = isset( $sub[2] ) ? $this->sub_key( $sub[2] ) : '';
				if ( '' !== $key && ChatKart_Settings::menu_hidden( $key ) ) {
					$remove[] = $sub[2];
				}
			}
			foreach ( $remove as $slug ) {
				remove_submenu_page( 'woocommerce', $slug );
			}
		}
	}
}
