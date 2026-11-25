<?php
/**
 * Plugin Name:       ChatKart – Click to Chat Button & Cart Checkout for WooCommerce
 * Plugin URI:        https://hedows.com/chatkart
 * Description:       Floating WhatsApp chat button and chat popup for your whole site, plus WooCommerce ordering on WhatsApp: order buttons, order form popup, cart popup, share button, shortcode and block.
 * Version:           1.0.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * WC requires at least: 6.0
 * Author:            Hedows Technologies
 * Author URI:        https://hedows.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       chatkart-click-to-chat-button-cart-checkout-for-woocommerce
 *
 * @package ChatKart
 */

defined( 'ABSPATH' ) || exit;

define( 'CHATKART_VERSION', '1.0.0' );
define( 'CHATKART_FILE', __FILE__ );
define( 'CHATKART_DIR', plugin_dir_path( __FILE__ ) );
define( 'CHATKART_URL', plugin_dir_url( __FILE__ ) );

require_once CHATKART_DIR . 'includes/class-chatkart-settings.php';

// Declare compatibility with HPOS (custom order tables) and Cart/Checkout blocks.
add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', CHATKART_FILE, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', CHATKART_FILE, true );
		}
	}
);

register_activation_hook(
	__FILE__,
	static function () {
		set_transient( 'chatkart_activated', 1, 60 );
		if ( false === get_option( ChatKart_Settings::OPTION ) ) {
			add_option( ChatKart_Settings::OPTION, array(), '', false );
		}
		require_once CHATKART_DIR . 'includes/class-chatkart-helpers.php';
		require_once CHATKART_DIR . 'includes/class-chatkart-data.php';
		ChatKart_Data::install();
	}
);

register_deactivation_hook(
	__FILE__,
	static function () {
		wp_clear_scheduled_hook( 'chatkart_purge' );
	}
);

add_action( 'init', 'chatkart_boot', 0 );

/**
 * Boot the plugin. The floating button works on any site; the WooCommerce
 * features load only when WooCommerce is active and enabled in the settings.
 */
function chatkart_boot() {
	require_once CHATKART_DIR . 'includes/class-chatkart-helpers.php';
	require_once CHATKART_DIR . 'includes/class-chatkart-data.php';
	require_once CHATKART_DIR . 'includes/class-chatkart-float.php';
	require_once CHATKART_DIR . 'includes/class-chatkart-blocks.php';

	ChatKart_Settings::instance();
	ChatKart_Data::instance();
	ChatKart_Float::instance();
	ChatKart_Blocks::instance();

	// WooCommerce admin menu tidy-up works even when the ordering features are switched off.
	if ( is_admin() && class_exists( 'WooCommerce' ) ) {
		require_once CHATKART_DIR . 'includes/class-chatkart-admin-menu.php';
		ChatKart_Admin_Menu::instance();
	}

	if ( class_exists( 'WooCommerce' ) && ChatKart_Settings::is( 'woo_enabled' ) ) {
		require_once CHATKART_DIR . 'includes/class-chatkart-frontend.php';
		require_once CHATKART_DIR . 'includes/class-chatkart-order.php';
		require_once CHATKART_DIR . 'includes/class-chatkart-admin.php';

		ChatKart_Frontend::instance();
		ChatKart_Order::instance();
		ChatKart_Admin::instance();
	}
}

/**
 * Suggested text for Settings > Privacy > Policy Guide.
 */
add_action(
	'admin_init',
	static function () {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		wp_add_privacy_policy_content(
			'ChatKart',
			wp_kses_post(
				'<p class="privacy-policy-tutorial">' . __( 'ChatKart opens WhatsApp when a visitor clicks a chat or order button. It does not send visitor data to any server of its own.', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ) . '</p>'
				. '<strong class="privacy-policy-tutorial">' . __( 'Suggested text:', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ) . '</strong> '
				. __( 'When you click a WhatsApp button on this site, your browser opens WhatsApp with a prefilled message. The message may contain the page you were viewing and, for orders, the products and the contact details you entered in the order form. WhatsApp (Meta Platforms, Inc.) processes this data under its own privacy policy. We keep an anonymous counter of button clicks (date, page and agent name only). If you place an order through the form, your details may also be emailed to the shop and to you.', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' )
			)
		);
	}
);
