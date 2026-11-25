<?php
/**
 * Admin: per-product override and order "Message on WhatsApp" box.
 *
 * @package ChatKart
 */

defined( 'ABSPATH' ) || exit;

class ChatKart_Admin {

	/** @var ChatKart_Admin|null */
	private static $instance = null;

	/**
	 * @return ChatKart_Admin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'woocommerce_product_options_general_product_data', array( $this, 'product_field' ) );
		add_action( 'woocommerce_process_product_meta', array( $this, 'save_product' ) );
		add_action( 'add_meta_boxes', array( $this, 'order_box' ) );
	}

	/**
	 * "WhatsApp button" select on the product General tab.
	 */
	public function product_field() {
		wp_nonce_field( 'chatkart_product', 'chatkart_product_nonce' );
		woocommerce_wp_select(
			array(
				'id'          => '_chatkart_override',
				'label'       => __( 'WhatsApp order button', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ),
				'description' => __( 'Override the global rules for this product.', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ),
				'desc_tip'    => true,
				'options'     => array(
					''        => __( 'Use global settings', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ),
					'enable'  => __( 'Always show', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ),
					'disable' => __( 'Never show', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ),
				),
			)
		);
	}

	/**
	 * Save the product override.
	 *
	 * @param int $post_id Product ID.
	 */
	public function save_product( $post_id ) {
		if ( ! isset( $_POST['chatkart_product_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['chatkart_product_nonce'] ) ), 'chatkart_product' ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		$val = isset( $_POST['_chatkart_override'] ) ? sanitize_key( wp_unslash( $_POST['_chatkart_override'] ) ) : '';
		if ( ! in_array( $val, array( '', 'enable', 'disable' ), true ) ) {
			$val = '';
		}
		$product = wc_get_product( $post_id );
		if ( $product ) {
			$product->update_meta_data( '_chatkart_override', $val );
			$product->save_meta_data();
		}
	}

	/**
	 * Register the order meta box (HPOS and legacy screens).
	 */
	public function order_box() {
		$screen = function_exists( 'wc_get_page_screen_id' ) ? wc_get_page_screen_id( 'shop-order' ) : 'shop_order';
		add_meta_box( 'chatkart-order-box', __( 'WhatsApp', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ), array( $this, 'render_order_box' ), $screen, 'side', 'default' );
	}

	/**
	 * @param WP_Post|WC_Order $post_or_order Post or order object.
	 */
	public function render_order_box( $post_or_order ) {
		$order = ( $post_or_order instanceof WP_Post ) ? wc_get_order( $post_or_order->ID ) : $post_or_order;
		if ( ! $order instanceof WC_Order || ! current_user_can( 'edit_shop_orders' ) ) {
			return;
		}
		$phone = ChatKart_Helpers::normalize_phone( $order->get_billing_phone() );
		if ( strlen( $phone ) < 8 ) {
			echo '<p>' . esc_html__( 'This order has no usable phone number.', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ) . '</p>';
			return;
		}
		$text = ChatKart_Order::status_message( $order );
		$url  = ChatKart_Helpers::wa_url( $phone, $text );

		echo '<p>' . esc_html__( 'Send the customer an update on WhatsApp using your “Order status message” template:', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ) . '</p>';
		echo '<p><a class="button button-primary" href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Message customer on WhatsApp', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ) . '</a></p>';
		echo '<p><a class="button" href="' . esc_url( ChatKart_Helpers::wa_url( $phone, '' ) ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Open blank chat', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ) . '</a></p>';
		echo '<p class="description">' . esc_html__( 'Opens WhatsApp with the message ready. You press Send.', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ) . '</p>';
	}
}
