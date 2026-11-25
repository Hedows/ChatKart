<?php
/**
 * Settings schema, storage and the tabbed admin settings page.
 *
 * @package ChatKart
 */

defined( 'ABSPATH' ) || exit;

class ChatKart_Settings {

	const OPTION = 'chatkart_settings';
	const SLUG   = 'chatkart-settings';

	/** Settings that can be translated with WPML / Polylang. */
	const TRANSLATABLE = array( 'float_label', 'float_cta', 'float_msg', 'widget_title', 'widget_reply', 'widget_greeting', 'btn_text', 'share_text', 'confirm_text', 'msg_order', 'msg_share', 'msg_status', 'atc_text' );

	/** @var ChatKart_Settings|null */
	private static $instance = null;

	/** @var array|null */
	private static $cache = null;

	/** @var string Admin page hook suffix. */
	private $hook = '';

	/**
	 * @return ChatKart_Settings
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_init', array( $this, 'register' ) );
		add_filter( 'option_page_capability_chatkart_group', array( __CLASS__, 'cap' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( CHATKART_FILE ), array( $this, 'action_links' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_strings' ) );
		add_action( 'admin_init', array( $this, 'maybe_redirect' ) );
		add_action( 'admin_post_chatkart_export_settings', array( $this, 'export_settings' ) );
		add_action( 'admin_post_chatkart_import_settings', array( $this, 'import_settings' ) );
	}

	/** First screen after activation. */
	public function maybe_redirect() {
		if ( get_transient( 'chatkart_activated' ) && ! wp_doing_ajax() && ! isset( $_GET['activate-multi'] ) && current_user_can( self::cap() ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			delete_transient( 'chatkart_activated' );
			wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG ) );
			exit;
		}
	}

	/**
	 * Capability needed to manage the plugin.
	 *
	 * @return string
	 */
	public static function cap() {
		// Administrators only by default: these settings control where orders go and which URLs receive data.
		// Shop managers can be allowed with: add_filter( 'chatkart_capability', fn() => 'manage_woocommerce' );
		$cap = (string) apply_filters( 'chatkart_capability', 'manage_options' );
		return '' !== $cap ? $cap : 'manage_options';
	}

	/**
	 * Are the WooCommerce settings visible/active?
	 *
	 * @return bool
	 */
	public static function woo_visible() {
		return class_exists( 'WooCommerce' ) && self::is( 'woo_enabled' );
	}

	/**
	 * Field helper.
	 */
	private static function f( $type, $label, $default, $extra = array() ) {
		return array_merge( array( 'type' => $type, 'label' => $label, 'default' => $default ), $extra );
	}

	/**
	 * Schema: list of sections, each belonging to a tab.
	 *
	 * @param bool $full Include term/status lists (admin only).
	 * @return array
	 */
	public static function schema( $full = true ) {
		$shapes   = array(
			'square'  => __( 'Square', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ),
			'rounded' => __( 'Rounded', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ),
			'pill'    => __( 'Pill', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ),
		);
		$req      = array(
			'hidden'   => __( 'Hidden', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ),
			'optional' => __( 'Optional', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ),
			'required' => __( 'Required', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ),
		);
		$side     = array(
			'right' => __( 'Bottom right', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ),
			'left'  => __( 'Bottom left', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ),
		);
		$atc      = array(
			'both'     => __( 'Show Add to cart and WhatsApp order', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ),
			'whatsapp' => __( 'Hide Add to cart (WhatsApp only)', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ),
		);
		$cats     = array();
		if ( $full && taxonomy_exists( 'product_cat' ) ) {
			$terms = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false ) );
			if ( is_array( $terms ) ) {
				foreach ( $terms as $t ) {
					$cats[ $t->term_id ] = $t->name;
				}
			}
		}
		$ptypes = array();
		if ( $full ) {
			foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $pt ) {
				if ( 'attachment' !== $pt->name ) {
					$ptypes[ $pt->name ] = $pt->labels->singular_name;
				}
			}
		}
		$templates = self::templates();

		return array(
			array(
				'tab'    => 'general',
				'title'  => __( 'General', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ),
				'fields' => array(
					'enabled'       => self::f( 'checkbox', __( 'Enable plugin', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ), 'yes', array( 'desc' => __( 'Master switch. Off disables everything (floating button and WooCommerce features) without deactivating the plugin.', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ) ) ),
					'phone'         => self::f( 'text', __( 'WhatsApp number', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ), '', array( 'desc' => __( 'International format with country code, digits only. Example: 923001234567', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ) ) ),
					'country_code'  => self::f( 'text', __( 'Default country calling code', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ), '', array( 'desc' => __( 'Used to turn local customer numbers (like 0300…) into international numbers for admin reply links. Example: 92', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ) ) ),
					'woo_enabled'   => self::f( 'checkbox', __( 'Enable WooCommerce features', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ), 'yes', array( 'desc' => __( 'Shows or hides all WooCommerce settings tabs and turns the WooCommerce ordering features on or off. Turn off if you only want the floating support button.', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ) ) ),
					'menu_location' => self::f( 'select', __( 'Settings menu location', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ), 'settings', array( 'options' => array( 'settings' => __( 'Settings → ChatKart', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ), 'toplevel' => __( 'Own top-level menu', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ), 'woocommerce' => __( 'Under WooCommerce', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ) ) ) ),
				),
			),
			array(
				'tab'    => 'float',
				'title'  => __( 'Floating WhatsApp support button (whole website)', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ),
				'fields' => array(
					'float_enabled' => self::f( 'checkbox', __( 'Show on the whole website by default', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ), 'yes', array( 'desc' => __( 'You can hide or force-show the button on any single page, post, product or other content from its edit screen (“WhatsApp floating button” box).', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ) ) ),
					'float_pos'     => self::f( 'select', __( 'Position', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ), 'right', array( 'options' => $side ) ),
					'float_bottom'  => self::f( 'number', __( 'Distance from bottom (px)', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ), 18, array( 'min' => 0, 'max' => 400 ) ),
					'float_size'    => self::f( 'number', __( 'Button size (px)', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ), 58, array( 'min' => 40, 'max' => 100 ) ),
					'float_bg'      => self::f( 'color', __( 'Background colour', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ), '#25D366' ),
					'float_color'   => self::f( 'color', __( 'Icon / text colour', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ), '#ffffff' ),
					'float_label'   => self::f( 'text', __( 'Label next to the icon (optional)', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ), '', array( 'desc' => __( 'Example: Chat with us. Leave empty for icon only.', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ) ) ),
					'float_devices' => self::f( 'select', __( 'Show on', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ), 'all', array( 'options' => array( 'all' => __( 'All devices', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ), 'mobile' => __( 'Phones and tablets only', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ), 'desktop' => __( 'Desktop only', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ) ) ) ),
					'float_msg'     => self::f( 'textarea', __( 'Prefilled message', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ), "Hello {site_name}, I need help.\n{page_title}\n{page_url}", array( 'rows' => 4, 'desc' => __( 'Placeholders: {site_name} {page_title} {page_url}', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ) ) ),
				),
			),
			array(
				'tab'    => 'float',
				'title'  => __( 'Chat popup', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ),
				'desc'   => __( 'Choose “Chat popup” to open a small chat window instead of jumping straight to WhatsApp. Enter your agent as:  Name | Role | Phone   Example:  Sara | Sales | 923001234567   (Phone empty = your main number.)', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ),
				'fields' => array(
					'widget_mode'    => self::f( 'select', __( 'Floating button style', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ), 'button', array( 'options' => array( 'button' => __( 'Simple button (opens WhatsApp directly)', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ), 'popup' => __( 'Chat popup (greeting + agents)', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ) ) ) ),
					'widget_title'   => self::f( 'text', __( 'Popup title', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ), __( 'Chat with us', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ) ),
					'widget_reply'   => self::f( 'text', __( 'Reply-time text', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ), __( 'Typically replies within a few minutes', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ) ),
					'widget_greeting' => self::f( 'textarea', __( 'Greeting message', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ), __( 'Hi there 👋 How can we help you today?', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ), array( 'rows' => 3 ) ),
					'widget_agents'  => self::f( 'text', __( 'Agent', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ), 'Support | Customer Support', array( 'desc' => __( 'Format: Name | Role | Phone. Leave the phone empty to use your main number.', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ) ) ),
					'widget_track'   => self::f( 'checkbox', __( 'Count clicks (analytics)', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ), 'yes', array( 'desc' => __( 'Stores the click time, agent and page URL. No names, IP addresses or cookies.', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ) ) ),
				),
			),
			array(
				'tab'    => 'float',
				'title'  => __( 'Look & behaviour', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ),
				'fields' => array(
					'float_effect'    => self::f( 'select', __( 'Attention effect', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ), 'none', array( 'options' => array( 'none' => __( 'None', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ), 'pulse' => __( 'Pulse', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ), 'bounce' => __( 'Bounce', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ), 'shake' => __( 'Shake', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ), 'ring' => __( 'Ring', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ) ), 'desc' => __( 'Switched off automatically for visitors who prefer reduced motion.', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ) ) ),
					'float_cta'       => self::f( 'text', __( 'Call-to-action bubble text', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ), '', array( 'desc' => __( 'A small bubble next to the button, for example “Need help? Chat with us”. Empty = no bubble.', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ) ) ),
					'float_cta_delay' => self::f( 'number', __( 'Show the bubble after (seconds)', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ), 3, array( 'min' => 0, 'max' => 120 ) ),
					'float_badge'     => self::f( 'checkbox', __( 'Show a “1” notification badge until opened', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ), 'no' ),
					'float_qr'        => self::f( 'checkbox', __( 'Show a QR code in the chat popup on desktop', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ), 'no', array( 'desc' => __( 'Lets desktop visitors scan the code with their phone. Popup style only.', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ) ) ),
				),
			),
			array(
				'tab'    => 'rules',
				'title'  => __( 'Where the chat button shows', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ),
				'desc'   => __( 'These rules apply to the floating button / chat popup. A page set to “Always show” in its own editor box ignores them. URL and login rules run in the browser, so they work with page caching.', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ),
				'fields' => array(
					'rule_url_include' => self::f( 'textarea', __( 'Show only on these URLs', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ), '', array( 'rows' => 3, 'desc' => __( 'One pattern per line, matched against the path and query. Use * as a wildcard. Examples: /shop/*   /contact/   /product/*?ref=ad   Empty = all pages.', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ) ) ),
					'rule_url_exclude' => self::f( 'textarea', __( 'Hide on these URLs', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ), '', array( 'rows' => 3 ) ),
					'rule_posttypes'   => self::f( 'multi', __( 'Only on these content types', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ), array(), array( 'options' => $ptypes, 'desc' => __( 'None selected = all.', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ) ) ),
					'rule_login'       => self::f( 'select', __( 'Visitors', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ), 'all', array( 'options' => array( 'all' => __( 'Everyone', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ), 'in' => __( 'Logged-in only', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ), 'out' => __( 'Logged-out only', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ) ) ) ),
				),
			),
			array(
				'tab'    => 'tracking',
				'title'  => __( 'Analytics events', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ),
				'desc'   => __( 'Sends an event whenever someone starts a chat or places a WhatsApp order. Events: chatkart_whatsapp_click, chatkart_whatsapp_order. Your analytics tags must already be installed on the site (Site Kit, GTM4WP, Google tag, Meta Pixel plugin …). Nothing is loaded or sent to any third party by this plugin itself.', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ),
				'fields' => array(
					'ga_events'    => self::f( 'checkbox', __( 'Google Analytics 4 (gtag)', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ), 'yes' ),
					'gtm_events'   => self::f( 'checkbox', __( 'Google Tag Manager (dataLayer)', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ), 'yes' ),
					'pixel_events' => self::f( 'checkbox', __( 'Meta Pixel (Contact / Lead / InitiateCheckout)', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ), 'yes' ),
				),
			),
			array(
				'tab'    => 'woo_display',
				'title'  => __( 'Where the order button shows', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ),
				'fields' => array(
					'logged_in_only' => self::f( 'checkbox', __( 'Only for logged-in customers', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ), 'no' ),
					'show_shop'      => self::f( 'checkbox', __( 'Show on shop / archive pages', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ), 'yes' ),
					'show_product'   => self::f( 'checkbox', __( 'Show on single product pages', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ), 'yes' ),
					'show_cart'      => self::f( 'checkbox', __( 'Show on cart page', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ), 'yes' ),
					'show_checkout'  => self::f( 'checkbox', __( 'Show on checkout page', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ), 'no' ),
					'hide_desktop'   => self::f( 'checkbox', __( 'Hide buttons on desktop', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ), 'no', array( 'desc' => __( 'Buttons stay visible on tablets and phones (screens narrower than 783px).', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ) ) ),
				),
			),
			array(
				'tab'    => 'woo_display',
				'title'  => __( 'Add to cart & checkout', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ),
				'fields' => array(
					'atc_product'      => self::f( 'select', __( 'Product pages', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ), 'both', array( 'options' => $atc, 'desc' => __( 'Default keeps the normal Add to cart button, so customers can choose: add to cart, or order this product on WhatsApp.', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ) ) ),
					'atc_shop'         => self::f( 'select', __( 'Shop / archive pages', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ), 'both', array( 'options' => $atc ) ),
					'atc_text'         => self::f( 'text', __( 'Add to cart button text (optional)', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ), '', array( 'desc' => __( 'Leave empty to keep your theme’s text. Fill in (for example “Add to cart”) to force the text on simple products.', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ) ) ),
				),
			),
			array(
				'tab'    => 'woo_display',
				'title'  => __( 'Which products', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ),
				'fields' => array(
					'scope'            => self::f( 'select', __( 'Show the button on', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ), 'all', array( 'options' => array( 'all' => __( 'All products', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ), 'selected' => __( 'Only selected categories / products', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ) ) ) ),
					'include_cats'     => self::f( 'terms', __( 'Selected categories', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ), array(), array( 'options' => $cats, 'desc' => __( 'Used when “Only selected” is chosen. Hold Ctrl/Cmd to select several. Child categories are included.', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ) ) ),
					'include_products' => self::f( 'ids', __( 'Selected product IDs', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ), '', array( 'desc' => __( 'Comma-separated product IDs. Example: 12, 45, 88', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ) ) ),
					'exclude_cats'     => self::f( 'terms', __( 'Excluded categories', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ), array(), array( 'options' => $cats ) ),
					'exclude_products' => self::f( 'ids', __( 'Excluded product IDs', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ), '' ),
				),
			),
			array(
				'tab'    => 'woo_display',
				'title'  => __( 'Button style', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ),
				'fields' => array(
					'btn_text'    => self::f( 'text', __( 'Order button text', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ), __( 'Order on WhatsApp', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ) ),
					'btn_bg'      => self::f( 'color', __( 'Order button background', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ), '#25D366' ),
					'btn_color'   => self::f( 'color', __( 'Order button text colour', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ), '#ffffff' ),
					'btn_shape'   => self::f( 'select', __( 'Order button shape', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ), 'rounded', array( 'options' => $shapes ) ),
					'btn_icon'    => self::f( 'checkbox', __( 'Show WhatsApp icon', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ), 'yes' ),
					'share_on'    => self::f( 'checkbox', __( 'Show “Share on WhatsApp” button', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ), 'yes' ),
					'share_text'  => self::f( 'text', __( 'Share button text', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ), __( 'Share', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ) ),
					'share_bg'    => self::f( 'color', __( 'Share button background', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ), '#128C7E' ),
					'share_color' => self::f( 'color', __( 'Share button text colour', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ), '#ffffff' ),
					'share_shape' => self::f( 'select', __( 'Share button shape', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ), 'rounded', array( 'options' => $shapes ) ),
				),
			),
			array(
				'tab'    => 'woo_order',
				'title'  => __( 'Order form', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ),
				'fields' => array(
					'use_form'      => self::f( 'checkbox', __( 'Show checkout form in a popup', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ), 'yes', array( 'desc' => __( 'If off, the button opens WhatsApp directly with a prefilled message.', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ) ) ),
					'field_email'   => self::f( 'select', __( 'Email field', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ), 'optional', array( 'options' => $req ) ),
					'field_address' => self::f( 'select', __( 'Address field', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ), 'required', array( 'options' => $req ) ),
					'field_note'    => self::f( 'select', __( 'Order note field', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ), 'optional', array( 'options' => array( 'hidden' => $req['hidden'], 'optional' => $req['optional'] ) ) ),
					'confirm_text'  => self::f( 'textarea', __( 'Confirmation text', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ), __( 'Thank you! Your order is ready. Tap the button below to send it to us on WhatsApp so we can confirm it.', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ) ),
				),
			),
			array(
				'tab'    => 'woo_order',
				'title'  => __( 'Messages', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ),
				'fields' => array(
					'msg_order'  => self::f( 'textarea', __( 'WhatsApp order message', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ), "Hello {site_name}, I would like to order:\n\n{order_items}\n\nTotal: {order_total}\nName: {customer_name}\nPhone: {customer_phone}\nEmail: {customer_email}\nAddress: {customer_address}\nNote: {customer_note}", array( 'rows' => 10, 'desc' => __( 'Lines whose placeholders are all empty are removed automatically.', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ) ) ),
					'msg_share'  => self::f( 'textarea', __( 'Share message', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ), "Check out {product_name} ({product_price}) at {site_name}:\n{product_url}", array( 'rows' => 3 ) ),
					'msg_status' => self::f( 'textarea', __( 'Order status message (admin → customer)', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ), "Hello {customer_name}, your order #{order_id} is now: {order_status}.\n\n{order_items}\nTotal: {order_total}\n\nThank you for shopping with {site_name}.", array( 'rows' => 5 ) ),
				),
			),
			array(
				'tab'    => 'woo_order',
				'title'  => __( 'Email notifications', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ),
				'fields' => array(
					'email_admin'    => self::f( 'checkbox', __( 'Email the shop owner for every WhatsApp order', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ), 'yes' ),
					'email_admin_to' => self::f( 'text', __( 'Send to', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ), '', array( 'desc' => __( 'Leave empty to use the site admin email. Separate several addresses with commas.', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ) ) ),
					'email_confirm'  => self::f( 'checkbox', __( 'Email the customer an order confirmation', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ), 'yes', array( 'desc' => __( 'Only when the customer entered an email address.', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ) ) ),
				),
			),
			array(
				'tab'    => 'woo_popup',
				'title'  => __( 'Popup design', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ),
				'fields' => array(
					'popup_template' => self::f( 'select', __( 'Popup template', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ), 'classic', array( 'options' => $templates, 'desc' => __( 'Used for the order form and the cart popup.', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ) ) ),
				),
			),
			array(
				'tab'    => 'woo_menu',
				'title'  => __( 'WooCommerce admin menu', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ),
				'desc'   => __( 'Tidy the WordPress sidebar by hiding WooCommerce menu items you do not need. This only removes menu links; it is not a security restriction (someone who knows a page address can still open it). Use user roles for real restrictions. Your ChatKart settings stay reachable from the Plugins page (Settings link), or at wp-admin/admin.php?page=chatkart-settings.', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ),
				'fields' => array(
					'menu_preset'   => self::f( 'select', __( 'Quick preset', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ), 'custom', array( 'options' => array( 'custom' => __( 'Custom (use the checkboxes below)', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ), 'products_only' => __( 'Products only (hide WooCommerce, Payments, Analytics and Marketing)', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ), 'show_all' => __( 'Show everything', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ) ) ) ),
					'menu_hide_for' => self::f( 'select', __( 'Apply to', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ), 'all', array( 'options' => array( 'all' => __( 'Everyone, including administrators', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ), 'others' => __( 'Everyone except administrators', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ) ) ) ),
				),
			),
			array(
				'tab'    => 'woo_menu',
				'title'  => __( 'Top-level menus (Custom preset)', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ),
				'fields' => array(
					'hide_m_woocommerce' => self::f( 'checkbox', __( 'Hide “WooCommerce” (whole menu and its submenus)', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ), 'no' ),
					'hide_m_products'    => self::f( 'checkbox', __( 'Hide “Products”', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ), 'no' ),
					'hide_m_payments'    => self::f( 'checkbox', __( 'Hide “Payments”', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ), 'no' ),
					'hide_m_analytics'   => self::f( 'checkbox', __( 'Hide “Analytics”', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ), 'no' ),
					'hide_m_marketing'   => self::f( 'checkbox', __( 'Hide “Marketing”', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ), 'no' ),
				),
			),
			array(
				'tab'    => 'woo_menu',
				'title'  => __( 'WooCommerce submenus (Custom preset)', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ),
				'fields' => array(
					'hide_m_home'       => self::f( 'checkbox', __( 'Hide “Home”', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ), 'no' ),
					'hide_m_orders'     => self::f( 'checkbox', __( 'Hide “Orders”', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ), 'no' ),
					'hide_m_customers'  => self::f( 'checkbox', __( 'Hide “Customers”', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ), 'no' ),
					'hide_m_coupons'    => self::f( 'checkbox', __( 'Hide “Coupons”', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ), 'no' ),
					'hide_m_reports'    => self::f( 'checkbox', __( 'Hide “Reports”', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ), 'no' ),
					'hide_m_settings'   => self::f( 'checkbox', __( 'Hide “Settings”', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ), 'no' ),
					'hide_m_status'     => self::f( 'checkbox', __( 'Hide “Status”', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ), 'no' ),
					'hide_m_extensions' => self::f( 'checkbox', __( 'Hide “Extensions”', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ), 'no' ),
				),
			),
		);
	}

	/**
	 * Built-in popup templates.
	 *
	 * @return array
	 */
	public static function templates() {
		return array(
			'classic'      => __( 'Classic – centred card', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ),
			'bottom-sheet' => __( 'Bottom sheet (mobile style)', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ),
			'dark'         => __( 'Dark mode card', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ),
		);
	}

	/**
	 * Default values for all fields.
	 *
	 * @return array
	 */
	public static function defaults() {
		$out = array();
		foreach ( self::schema( false ) as $section ) {
			foreach ( $section['fields'] as $id => $f ) {
				$out[ $id ] = $f['default'];
			}
		}
		return $out;
	}

	/**
	 * All settings merged with defaults.
	 *
	 * @return array
	 */
	public static function all() {
		if ( null === self::$cache ) {
			$saved       = get_option( self::OPTION, array() );
			self::$cache = wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
		}
		return self::$cache;
	}

	/**
	 * @param string $key Setting key.
	 * @return mixed
	 */
	public static function get( $key ) {
		$all = self::all();
		$v   = isset( $all[ $key ] ) ? $all[ $key ] : null;
		if ( is_string( $v ) && '' !== $v && in_array( $key, self::TRANSLATABLE, true ) ) {
			return self::translate( $key, $v );
		}
		return $v;
	}

	/**
	 * Run a setting through WPML / Polylang when present.
	 *
	 * @param string $key Setting key.
	 * @param string $v   Value.
	 * @return string
	 */
	private static function translate( $key, $v ) {
		if ( has_filter( 'wpml_translate_single_string' ) ) {
			return (string) apply_filters( 'wpml_translate_single_string', $v, 'ChatKart', $key );
		}
		if ( function_exists( 'pll__' ) ) {
			return (string) pll__( $v );
		}
		return $v;
	}

	/** Make the texts visible to WPML String Translation and Polylang. */
	public static function register_strings() {
		$all = self::all();
		foreach ( self::TRANSLATABLE as $k ) {
			if ( empty( $all[ $k ] ) || ! is_string( $all[ $k ] ) ) {
				continue;
			}
			if ( has_action( 'wpml_register_single_string' ) ) {
				do_action( 'wpml_register_single_string', 'ChatKart', $k, $all[ $k ] );
			}
			if ( function_exists( 'pll_register_string' ) ) {
				pll_register_string( $k, $all[ $k ], 'ChatKart', false !== strpos( $all[ $k ], "\n" ) );
			}
		}
	}

	/**
	 * @param string $key Setting key.
	 * @return bool
	 */
	public static function is( $key ) {
		return 'yes' === self::get( $key );
	}

	/**
	 * Should a WooCommerce admin menu item be hidden for the current user?
	 *
	 * @param string $key woocommerce|products|payments|analytics|marketing|home|orders|customers|coupons|reports|settings|status|extensions.
	 * @return bool
	 */
	public static function menu_hidden( $key ) {
		if ( ( defined( 'CHATKART_DISABLE_MENU_HIDING' ) && CHATKART_DISABLE_MENU_HIDING ) || ! class_exists( 'WooCommerce' ) ) {
			return false;
		}
		if ( 'others' === self::get( 'menu_hide_for' ) && current_user_can( 'manage_options' ) ) {
			return false;
		}
		$preset = self::get( 'menu_preset' );
		if ( 'show_all' === $preset ) {
			return false;
		}
		if ( 'products_only' === $preset ) {
			return in_array( $key, array( 'woocommerce', 'payments', 'analytics', 'marketing' ), true );
		}
		return self::is( 'hide_m_' . $key );
	}

	/**
	 * Register the settings page in the chosen location.
	 */
	public function menu() {
		$title = __( 'ChatKart', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' );
		$loc   = self::get( 'menu_location' );
		$cap   = self::cap();

		if ( 'toplevel' === $loc ) {
			$this->hook = add_menu_page( $title, $title, $cap, self::SLUG, array( $this, 'render' ), 'dashicons-format-chat', 58 );
		} elseif ( 'woocommerce' === $loc && class_exists( 'WooCommerce' ) && ! self::menu_hidden( 'woocommerce' ) ) {
			$this->hook = add_submenu_page( 'woocommerce', $title, $title, $cap, self::SLUG, array( $this, 'render' ) );
		} else {
			$this->hook = add_options_page( $title, $title, $cap, self::SLUG, array( $this, 'render' ) );
		}
	}

	public function register() {
		register_setting(
			'chatkart_group',
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize' ),
				'default'           => array(),
			)
		);
	}

	public function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ) . '">' . esc_html__( 'Settings', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ) . '</a>' );
		return $links;
	}

	public function assets( $hook ) {
		if ( ! $this->hook || $hook !== $this->hook ) {
			return;
		}
		wp_enqueue_style( 'wp-color-picker' );
		wp_enqueue_script( 'wp-color-picker' );
		wp_enqueue_style( 'chatkart-float', CHATKART_URL . 'assets/css/float.css', array(), CHATKART_VERSION );
		wp_add_inline_script( 'wp-color-picker', 'jQuery(function($){var up=function(){var v=function(id){return $(\'#chatkart_\'+id).val();},p=$(\'#chatkart-prev\');if(!p.length){return;}p.css({\'--chatkart-f-bg\':v(\'float_bg\')||\'#25D366\',\'--chatkart-f-color\':v(\'float_color\')||\'#fff\',\'--chatkart-f-size\':(parseInt(v(\'float_size\'),10)||58)+\'px\',\'text-align\':v(\'float_pos\')===\'left\'?\'left\':\'right\'});var l=v(\'float_label\')||\'\';var b=p.find(\'.chatkart-launcher\');b.toggleClass(\'has-label\',!!l);p.find(\'.chatkart-float-label\').text(l);};$(\'.chatkart-color\').wpColorPicker({change:function(e,ui){$(e.target).val(ui.color.toString()).trigger(\'chatkart-change\');},clear:function(e){$(e.target).trigger(\'chatkart-change\');}});$(document).on(\'input change chatkart-change\',\'#chatkart_float_bg,#chatkart_float_color,#chatkart_float_size,#chatkart_float_label,#chatkart_float_pos\',up);up();});' );
		wp_add_inline_style( 'wp-color-picker', '.chatkart-wrap-admin .form-table th{width:260px}.chatkart-wrap-admin textarea,.chatkart-wrap-admin .large-text{width:100%;max-width:760px}.chatkart-prev{padding:24px;background:#f0f0f1;border-radius:8px;max-width:520px}.chatkart-prev .chatkart-launcher{position:static}' );
	}

	/**
	 * Sanitize the submitted tab; keep the other tabs' values untouched.
	 *
	 * @param mixed $input Raw input.
	 * @return array
	 */
	public function sanitize( $input ) {
		// options.php has already removed WordPress' magic slashes, so do NOT unslash again (it would corrupt backslashes in custom CSS / JS).
		$input   = is_array( $input ) ? $input : array();
		$tab     = isset( $input['_tab'] ) ? sanitize_key( $input['_tab'] ) : '';
		$current = wp_parse_args( (array) get_option( self::OPTION, array() ), self::defaults() );
		$out     = array();

		foreach ( self::schema() as $section ) {
			foreach ( $section['fields'] as $id => $f ) {
				if ( $tab && $section['tab'] !== $tab ) {
					$out[ $id ] = $current[ $id ];
					continue;
				}
				$raw = isset( $input[ $id ] ) ? $input[ $id ] : null;
				switch ( $f['type'] ) {
					case 'checkbox':
						$out[ $id ] = ( 'yes' === $raw ) ? 'yes' : 'no';
						break;
					case 'color':
						$c          = is_string( $raw ) ? sanitize_hex_color( $raw ) : '';
						$out[ $id ] = $c ? $c : $f['default'];
						break;
					case 'select':
						$out[ $id ] = ( is_string( $raw ) && isset( $f['options'][ $raw ] ) ) ? $raw : $f['default'];
						break;
					case 'number':
						$n          = is_numeric( $raw ) ? (int) $raw : (int) $f['default'];
						$out[ $id ] = max( (int) $f['min'], min( (int) $f['max'], $n ) );
						break;
					case 'textarea':
						$val = is_string( $raw ) ? sanitize_textarea_field( $raw ) : $f['default'];
						if ( 'rule_url_include' === $id || 'rule_url_exclude' === $id ) {
							$list = array();
							foreach ( preg_split( '/\r\n|\r|\n/', $val ) as $u ) {
								$u = trim( preg_replace( '/\*{2,}/', '*', substr( $u, 0, 200 ) ) );
								if ( '' !== $u ) {
									$list[] = $u;
								}
							}
							$val = implode( "\n", array_slice( array_unique( $list ), 0, 50 ) );
						}
						$out[ $id ] = $val;
						break;
					case 'ids':
						$ids        = is_string( $raw ) ? array_filter( array_map( 'absint', explode( ',', $raw ) ) ) : array();
						$out[ $id ] = implode( ',', array_unique( $ids ) );
						break;
					case 'terms':
						$ids        = is_array( $raw ) ? array_filter( array_map( 'absint', $raw ) ) : array();
						$out[ $id ] = array_values( array_unique( $ids ) );
						break;
					case 'multi':
						$vals       = is_array( $raw ) ? array_filter( array_map( 'sanitize_key', $raw ) ) : array();
						$out[ $id ] = array_values( array_intersect( $vals, array_keys( $f['options'] ) ) );
						break;
					default: // text.
						$val = is_string( $raw ) ? sanitize_text_field( $raw ) : '';
						if ( 'phone' === $id || 'country_code' === $id ) {
							$val = preg_replace( '/\D+/', '', $val );
							$val = preg_replace( '/^00/', '', (string) $val );
						}
						if ( 'widget_agents' === $id ) {
							$val = substr( $val, 0, 200 );
						}
						if ( 'email_admin_to' === $id ) {
							$list = array_filter( array_map( 'sanitize_email', array_map( 'trim', explode( ',', $val ) ) ) );
							$val  = implode( ', ', $list );
						}
						$out[ $id ] = $val;
				}
			}
		}

		if ( ( ! $tab || 'general' === $tab ) && strlen( $out['phone'] ) < 8 ) {
			add_settings_error( self::OPTION, 'chatkart_phone', __( 'Please enter a valid WhatsApp number with country code (at least 8 digits).', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ), 'error' );
		}

		self::$cache = null;
		return $out;
	}

	/**
	 * Tabs that are currently available.
	 *
	 * @return array
	 */
	public static function tabs() {
		$tabs = array(
			'general'  => __( 'General', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ),
			'float'    => __( 'Floating button', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ),
			'rules'    => __( 'Display rules', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ),
			'tracking' => __( 'Tracking', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ),
		);
		if ( class_exists( 'WooCommerce' ) ) {
			$tabs['woo_menu'] = __( 'WooCommerce: admin menu', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' );
		}
		if ( self::woo_visible() ) {
			$tabs['woo_display'] = __( 'WooCommerce: buttons & cart', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' );
			$tabs['woo_order']   = __( 'WooCommerce: orders & messages', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' );
			$tabs['woo_popup']   = __( 'WooCommerce: popup design', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' );
		}
		$tabs['analytics'] = __( 'Analytics', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' );
		$tabs['tools']     = __( 'Import / export', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' );
		$tabs['pro']       = __( 'ChatKart Pro', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' );
		return $tabs;
	}

	/** Setup checklist shown on the General tab. */
	private function checklist() {
		$url   = static function ( $tab ) {
			return esc_url( add_query_arg( array( 'page' => self::SLUG, 'tab' => $tab ), admin_url( 'admin.php' ) ) );
		};
		$items = array(
			array( strlen( preg_replace( '/\D+/', '', (string) self::get( 'phone' ) ) ) >= 8, __( 'Enter your WhatsApp number', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ), $url( 'general' ) ),
			array( self::is( 'float_enabled' ), __( 'Floating chat button is on', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ), $url( 'float' ) ),
			array( 'popup' === self::get( 'widget_mode' ), __( 'Chat popup with agents (optional)', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ), $url( 'float' ) ),
			array( self::is( 'ga_events' ) || self::is( 'gtm_events' ) || self::is( 'pixel_events' ), __( 'Analytics events are sent to your tags', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ), $url( 'tracking' ) ),
		);
		echo '<div class="notice notice-info inline"><p><strong>' . esc_html__( 'Quick setup', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ) . '</strong></p><ul style="margin:0 0 8px 18px;list-style:none">';
		foreach ( $items as $it ) {
			echo '<li>' . ( $it[0] ? '<span style="color:#00a32a">&#10003;</span>' : '<span style="color:#996800">&#9675;</span>' ) . ' <a href="' . $it[2] . '">' . esc_html( $it[1] ) . '</a></li>'; // phpcs:ignore WordPress.Security.EscapeOutput -- URL escaped above.
		}
		echo '</ul></div>';
	}

	/** Extra content below a tab's form. */
	private function extras( $tab ) {
		if ( 'float' === $tab ) {
			echo '<h2 class="title">' . esc_html__( 'Live preview', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ) . '</h2><p class="description">' . esc_html__( 'Colour, size, label and side update as you edit (save to apply).', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ) . '</p>';
			echo '<div id="chatkart-prev" class="chatkart-prev"><button type="button" class="chatkart-launcher" tabindex="-1">' . ChatKart_Helpers::icon() . '<span class="chatkart-float-label"></span></button></div>'; // phpcs:ignore WordPress.Security.EscapeOutput -- static markup.
		}
	}

	/** Import / export / system info. */
	private function render_tools() {
		echo '<h2 class="title">' . esc_html__( 'Export settings', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ) . '</h2><p>' . esc_html__( 'Download your settings as a JSON file to reuse on another site.', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ) . '</p>';
		echo '<p><a class="button" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=chatkart_export_settings' ), 'chatkart_export_settings' ) ) . '">' . esc_html__( 'Download settings', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ) . '</a></p>';
		echo '<h2 class="title">' . esc_html__( 'Import settings', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ) . '</h2><form method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="chatkart_import_settings">';
		wp_nonce_field( 'chatkart_import_settings' );
		echo '<p><input type="file" name="chatkart_file" accept="application/json,.json" required></p>';
		submit_button( __( 'Import', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ), 'secondary' );
		echo '</form><p class="description">' . esc_html__( 'Imported values replace your current settings (checked and cleaned like normal saves).', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ) . '</p>';

		global $wp_version;
		echo '<h2 class="title">' . esc_html__( 'System info (for support)', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ) . '</h2><table class="widefat striped" style="max-width:520px"><tbody>';
		$rows = array(
			'Plugin'      => CHATKART_VERSION,
			'WordPress'   => $wp_version,
			'PHP'         => PHP_VERSION,
			'WooCommerce' => defined( 'WC_VERSION' ) ? WC_VERSION : '-',
			'Timezone'    => wp_timezone_string(),
			'Language'    => get_locale(),
		);
		foreach ( $rows as $k => $v ) {
			echo '<tr><th>' . esc_html( $k ) . '</th><td>' . esc_html( $v ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}

	public function export_settings() {
		if ( ! current_user_can( self::cap() ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'chatkart_export_settings' );
		$all = self::all();
		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=chatkart-settings-' . gmdate( 'Y-m-d' ) . '.json' );
		echo wp_json_encode( array( 'plugin' => 'chatkart', 'version' => CHATKART_VERSION, 'settings' => $all ), JSON_PRETTY_PRINT );
		exit;
	}

	public function import_settings() {
		if ( ! current_user_can( self::cap() ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'chatkart_import_settings' );
		$msg = __( 'Import failed: please upload a settings file exported from ChatKart.', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' );
		if ( isset( $_FILES['chatkart_file']['tmp_name'], $_FILES['chatkart_file']['size'] ) && is_uploaded_file( $_FILES['chatkart_file']['tmp_name'] ) && (int) $_FILES['chatkart_file']['size'] <= 200000 ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			$j = json_decode( (string) file_get_contents( $_FILES['chatkart_file']['tmp_name'] ), true ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput, WordPress.WP.AlternativeFunctions
			if ( is_array( $j ) && isset( $j['plugin'], $j['settings'] ) && 'chatkart' === $j['plugin'] && is_array( $j['settings'] ) ) {
				if ( ! has_filter( 'sanitize_option_' . self::OPTION ) ) {
					$this->register(); // make sure the sanitize callback is attached (it runs once inside update_option).
				}
				update_option( self::OPTION, $j['settings'] );
				self::$cache = null;
				$msg         = __( 'Settings imported.', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' );
			}
		}
		set_transient( 'chatkart_tool_msg_' . get_current_user_id(), $msg, 120 );
		wp_safe_redirect( add_query_arg( array( 'page' => self::SLUG, 'tab' => 'tools' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/** Short, static information about the paid ChatKart Pro plugin. No nags and no locked features in this plugin. */
	private function render_pro() {
		echo '<h2 class="title">' . esc_html__( 'ChatKart Pro (separate paid plugin)', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ) . '</h2>';
		echo '<p>' . esc_html__( 'Everything on the other tabs is free and fully working. ChatKart Pro is a separate paid plugin from Hedows Technologies that adds:', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ) . '</p><ul style="list-style:disc;margin-left:20px">';
		foreach ( array(
			__( 'Multiple agents with availability hours, offline message and dark / grid chat popup', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ),
			__( 'Lead capture form with leads manager and CSV export, click analytics breakdown', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ),
			__( 'Create WooCommerce orders from WhatsApp orders, WhatsApp-only mode (disable checkout) and a WhatsApp Order payment method', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ),
			__( 'All 10 popup templates, custom popup HTML / CSS / JS, floating cart icon', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ),
			__( 'Advanced display rules and triggers, random / round-robin WhatsApp numbers, icon library', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ),
			__( 'Google Ads conversions, campaign source (UTM) in messages, webhooks for Zapier / Make / n8n', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ),
			__( 'WhatsApp Cloud API automation: order messages, abandoned carts, back-in-stock alerts and review requests', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ),
		) as $line ) {
			echo '<li>' . esc_html( $line ) . '</li>';
		}
		echo '</ul><p><a class="button button-primary" href="https://hedows.com/chatkart" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Learn more about ChatKart Pro', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ) . '</a></p>';
	}

	public function render() {
		if ( ! current_user_can( self::cap() ) ) {
			return;
		}
		$tabs = self::tabs();
		$tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'general'; // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! isset( $tabs[ $tab ] ) ) {
			$tab = 'general';
		}
		$vals = self::all();

		echo '<div class="wrap chatkart-wrap-admin"><h1>' . esc_html__( 'ChatKart', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ) . '</h1>';
		settings_errors();
		if ( self::is( 'woo_enabled' ) && ! class_exists( 'WooCommerce' ) ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'WooCommerce is not active, so the WooCommerce features are paused. The floating button still works.', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ) . '</p></div>';
		}

		echo '<h2 class="nav-tab-wrapper">';
		foreach ( $tabs as $slug => $label ) {
			$url = add_query_arg( array( 'page' => self::SLUG, 'tab' => $slug ), admin_url( 'admin.php' ) );
			echo '<a class="nav-tab' . ( $slug === $tab ? ' nav-tab-active' : '' ) . '" href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';
		}
		echo '</h2>';

		$flash = get_transient( 'chatkart_tool_msg_' . get_current_user_id() );
		if ( $flash ) {
			delete_transient( 'chatkart_tool_msg_' . get_current_user_id() );
			echo '<div class="notice notice-info inline"><p style="white-space:pre-line">' . esc_html( $flash ) . '</p></div>';
		}
		if ( 'general' === $tab ) {
			$this->checklist();
		}
		if ( 'tools' === $tab ) {
			$this->render_tools();
			echo '</div>';
			return;
		}
		if ( 'pro' === $tab ) {
			$this->render_pro();
			echo '</div>';
			return;
		}
		if ( 'analytics' === $tab ) {
			ChatKart_Data::render_analytics();
			echo '</div>';
			return;
		}

		echo '<form method="post" action="options.php">';
		settings_fields( 'chatkart_group' );
		echo '<input type="hidden" name="' . esc_attr( self::OPTION ) . '[_tab]" value="' . esc_attr( $tab ) . '">';

		foreach ( self::schema() as $section ) {
			if ( $section['tab'] !== $tab ) {
				continue;
			}
			echo '<h2 class="title">' . esc_html( $section['title'] ) . '</h2>';
			if ( ! empty( $section['desc'] ) ) {
				echo '<p class="description">' . esc_html( $section['desc'] ) . '</p>';
			}
			echo '<table class="form-table" role="presentation"><tbody>';
			foreach ( $section['fields'] as $id => $f ) {
				$name = self::OPTION . '[' . $id . ']';
				$val  = isset( $vals[ $id ] ) ? $vals[ $id ] : $f['default'];
				$fid  = 'chatkart_' . $id;
				echo '<tr><th scope="row"><label for="' . esc_attr( $fid ) . '">' . esc_html( $f['label'] ) . '</label></th><td>';
				switch ( $f['type'] ) {
					case 'checkbox':
						echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="no"><label><input type="checkbox" id="' . esc_attr( $fid ) . '" name="' . esc_attr( $name ) . '" value="yes"' . checked( 'yes', $val, false ) . '> ' . esc_html__( 'Yes', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ) . '</label>';
						break;
					case 'select':
						echo '<select id="' . esc_attr( $fid ) . '" name="' . esc_attr( $name ) . '">';
						foreach ( $f['options'] as $k => $label ) {
							echo '<option value="' . esc_attr( $k ) . '"' . selected( (string) $val, (string) $k, false ) . '>' . esc_html( $label ) . '</option>';
						}
						echo '</select>';
						break;
					case 'number':
						echo '<input type="number" class="small-text" id="' . esc_attr( $fid ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $val ) . '" min="' . esc_attr( $f['min'] ) . '" max="' . esc_attr( $f['max'] ) . '">';
						break;
					case 'multi':
						echo '<input type="hidden" name="' . esc_attr( $name ) . '[]" value=""><select multiple size="6" id="' . esc_attr( $fid ) . '" name="' . esc_attr( $name ) . '[]">';
						foreach ( $f['options'] as $k => $label ) {
							echo '<option value="' . esc_attr( $k ) . '"' . ( in_array( (string) $k, array_map( 'strval', (array) $val ), true ) ? ' selected="selected"' : '' ) . '>' . esc_html( $label ) . '</option>';
						}
						echo '</select>';
						break;
					case 'terms':
						echo '<input type="hidden" name="' . esc_attr( $name ) . '[]" value=""><select multiple size="6" id="' . esc_attr( $fid ) . '" name="' . esc_attr( $name ) . '[]">';
						$chosen = array_map( 'intval', (array) $val );
						foreach ( $f['options'] as $k => $label ) {
							echo '<option value="' . esc_attr( $k ) . '"' . ( in_array( (int) $k, $chosen, true ) ? ' selected="selected"' : '' ) . '>' . esc_html( $label ) . '</option>';
						}
						echo '</select>';
						break;
					case 'textarea':
						echo '<textarea id="' . esc_attr( $fid ) . '" name="' . esc_attr( $name ) . '" rows="' . esc_attr( isset( $f['rows'] ) ? $f['rows'] : 3 ) . '" class="large-text">' . esc_textarea( $val ) . '</textarea>';
						break;
					case 'color':
						echo '<input type="text" class="chatkart-color" id="' . esc_attr( $fid ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $val ) . '" data-default-color="' . esc_attr( $f['default'] ) . '">';
						break;
					default:
						echo '<input type="text" class="regular-text" id="' . esc_attr( $fid ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $val ) . '">';
				}
				if ( ! empty( $f['desc'] ) ) {
					echo '<p class="description">' . esc_html( $f['desc'] ) . '</p>';
				}
				echo '</td></tr>';
			}
			echo '</tbody></table>';
		}

		if ( 'woo_order' === $tab ) {
			echo '<p class="description">' . esc_html__( 'Placeholders: {site_name} {product_name} {product_url} {product_price} {variation} {quantity} {order_items} {order_total} {order_id} {order_status} {customer_name} {customer_phone} {customer_email} {customer_address} {customer_note} {date}', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ) . '</p>';
		}
		submit_button();
		echo '</form>';
		$this->extras( $tab );
		echo '</div>';
	}
}
