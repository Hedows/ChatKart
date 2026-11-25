<?php
/**
 * Front-end (WooCommerce): order buttons and the order / cart popup.
 *
 * @package ChatKart
 */

defined( 'ABSPATH' ) || exit;

class ChatKart_Frontend {

	/** @var ChatKart_Frontend|null */
	private static $instance = null;

	/** @var bool Print the popup in the footer. */
	private $needs_modal = false;

	/**
	 * @return ChatKart_Frontend
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'wp_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'wp_footer', array( $this, 'modal' ), 20 );
		add_filter( 'post_class', array( $this, 'post_class' ), 20, 3 );

		add_action( 'woocommerce_after_shop_loop_item', array( $this, 'loop_button' ), 15 );
		add_action( 'woocommerce_single_product_summary', array( $this, 'single_button' ), 35 );
		add_action( 'woocommerce_proceed_to_checkout', array( $this, 'cart_button' ), 25 );
		add_action( 'woocommerce_after_checkout_form', array( $this, 'checkout_button' ), 20 );

		add_filter( 'render_block_woocommerce/product-button', array( $this, 'block_product_button' ), 10, 3 );
		add_filter( 'render_block_woocommerce/cart', array( $this, 'block_cart' ), 10, 1 );
		add_filter( 'render_block_woocommerce/checkout', array( $this, 'block_checkout' ), 10, 1 );
		add_shortcode( 'chatkart_button', array( $this, 'shortcode' ) );

		add_filter( 'woocommerce_product_single_add_to_cart_text', array( $this, 'atc_single' ), 20, 2 );
		add_filter( 'woocommerce_product_add_to_cart_text', array( $this, 'atc_loop' ), 20, 2 );

	}

	/* ---------------------------------------------------------------- Add to cart text */

	public function atc_single( $text, $product ) {
		$custom = (string) ChatKart_Settings::get( 'atc_text' );
		return ( '' !== $custom && $product instanceof WC_Product && $product->is_type( array( 'simple', 'variable' ) ) ) ? $custom : $text;
	}

	public function atc_loop( $text, $product ) {
		$custom = (string) ChatKart_Settings::get( 'atc_text' );
		return ( '' !== $custom && $product instanceof WC_Product && $product->is_type( 'simple' ) && $product->is_purchasable() && $product->is_in_stock() ) ? $custom : $text;
	}

	/* ---------------------------------------------------------------- assets */

	public function assets() {
		if ( ! ChatKart_Settings::is( 'enabled' ) ) {
			return;
		}
		wp_enqueue_style( 'chatkart-frontend', CHATKART_URL . 'assets/css/frontend.css', array(), CHATKART_VERSION );

		$radius = array( 'square' => '0', 'rounded' => '8px', 'pill' => '999px' );
		$hex    = static function ( $key, $fallback ) {
			$c = sanitize_hex_color( ChatKart_Settings::get( $key ) );
			return $c ? $c : $fallback;
		};
		$bshape = ChatKart_Settings::get( 'btn_shape' );
		$sshape = ChatKart_Settings::get( 'share_shape' );
		$css    = sprintf(
			':root{--chatkart-bg:%1$s;--chatkart-color:%2$s;--chatkart-radius:%3$s;--chatkart-sbg:%4$s;--chatkart-scolor:%5$s;--chatkart-sradius:%6$s}',
			$hex( 'btn_bg', '#25D366' ),
			$hex( 'btn_color', '#ffffff' ),
			isset( $radius[ $bshape ] ) ? $radius[ $bshape ] : '8px',
			$hex( 'share_bg', '#128C7E' ),
			$hex( 'share_color', '#ffffff' ),
			isset( $radius[ $sshape ] ) ? $radius[ $sshape ] : '8px'
		);
		wp_add_inline_style( 'chatkart-frontend', $css );

		wp_enqueue_script( 'chatkart-frontend', CHATKART_URL . 'assets/js/frontend.js', wp_script_is( 'chatkart-widget', 'registered' ) ? array( 'chatkart-widget' ) : array(), CHATKART_VERSION, true );
		wp_localize_script(
			'chatkart-frontend',
			'chatkartData',
			array(
				'ajax'    => admin_url( 'admin-ajax.php' ),
				'useForm' => ChatKart_Settings::get( 'use_form' ),
				'i18n'    => array(
					'chooseOptions' => __( 'Please choose the product options first.', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ),
					'sending'       => __( 'Please wait…', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ),
					'error'         => __( 'Something went wrong. Please try again.', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ),
					'empty'         => __( 'Your cart is empty.', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ),
					'remove'        => __( 'Remove', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ),
					'more'          => __( 'Increase quantity', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ),
					'less'          => __( 'Decrease quantity', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ),
					'total'         => __( 'Total', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ),
					'items'         => __( 'items', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ),
				),
			)
		);
	}

	/* ---------------------------------------------------------------- classes */

	public function post_class( $classes, $class, $post_id ) {
		if ( ! ChatKart_Settings::is( 'enabled' ) || 'product' !== get_post_type( $post_id ) ) {
			return $classes;
		}
		$product = wc_get_product( $post_id );
		if ( ! ChatKart_Helpers::product_allowed( $product ) ) {
			return $classes;
		}
		$single = function_exists( 'is_product' ) && is_product() && (int) get_queried_object_id() === (int) $post_id;

		if ( $single && ChatKart_Settings::is( 'show_product' ) && 'whatsapp' === ChatKart_Settings::get( 'atc_product' ) ) {
			$classes[] = 'chatkart-hide-atc';
		} elseif ( ! $single && ChatKart_Settings::is( 'show_shop' ) && 'whatsapp' === ChatKart_Settings::get( 'atc_shop' ) ) {
			$classes[] = 'chatkart-hide-atc';
		}
		return $classes;
	}

	/* ---------------------------------------------------------------- buttons */

	public function loop_button() {
		if ( ChatKart_Settings::is( 'show_shop' ) ) {
			global $product;
			echo $this->button_html( $product, 'shop' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
		}
	}

	public function single_button() {
		if ( ChatKart_Settings::is( 'show_product' ) ) {
			global $product;
			echo $this->button_html( $product, 'product' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
	}

	/**
	 * The cart always gets the WhatsApp button when checkout is disabled.
	 */
	private function show_on_cart() {
		return ChatKart_Settings::is( 'show_cart' );
	}

	public function cart_button() {
		if ( $this->show_on_cart() ) {
			echo $this->cart_html( 'cart' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
	}

	public function checkout_button() {
		if ( ChatKart_Settings::is( 'show_checkout' ) ) {
			echo $this->cart_html( 'checkout' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
	}

	public function block_product_button( $content, $block = array(), $instance = null ) {
		if ( ! ChatKart_Settings::is( 'show_shop' ) || ! $instance instanceof WP_Block ) {
			return $content;
		}
		$pid     = isset( $instance->context['postId'] ) ? absint( $instance->context['postId'] ) : 0;
		$product = $pid ? wc_get_product( $pid ) : false;
		if ( ! ChatKart_Helpers::product_allowed( $product ) ) {
			return $content;
		}
		if ( 'whatsapp' === ChatKart_Settings::get( 'atc_shop' ) ) {
			$content = '';
		}
		return $content . $this->button_html( $product, 'shop' );
	}

	public function block_cart( $content ) {
		return $this->show_on_cart() ? $content . $this->cart_html( 'cart' ) : $content;
	}

	public function block_checkout( $content ) {
		return ChatKart_Settings::is( 'show_checkout' ) ? $content . $this->cart_html( 'checkout' ) : $content;
	}

	public function shortcode( $atts ) {
		$atts    = shortcode_atts( array( 'id' => 0 ), $atts, 'chatkart_button' );
		$id      = absint( $atts['id'] ) ? absint( $atts['id'] ) : get_the_ID();
		$product = $id ? wc_get_product( $id ) : false;
		return $this->button_html( $product, 'product' );
	}

	private function wrap_classes( $context ) {
		$c = 'chatkart-wrap chatkart-ctx-' . sanitize_html_class( $context );
		if ( ChatKart_Settings::is( 'hide_desktop' ) ) {
			$c .= ' chatkart-hide-desktop';
		}
		return $c;
	}

	private function label() {
		$icon = ChatKart_Settings::is( 'btn_icon' ) ? ChatKart_Helpers::icon() : '';
		return $icon . '<span>' . esc_html( ChatKart_Settings::get( 'btn_text' ) ) . '</span>';
	}

	/**
	 * Product button (order + share).
	 *
	 * @param WC_Product|false|null $product Product.
	 * @param string                $context shop|product.
	 * @return string
	 */
	public function button_html( $product, $context ) {
		if ( ! ChatKart_Helpers::product_allowed( $product ) ) {
			return '';
		}
		$this->needs_modal = true;

		$redirect = ( 'shop' === $context && $product->is_type( 'variable' ) ) ? get_permalink( $product->get_id() ) : '';

		$html  = '<div class="' . esc_attr( $this->wrap_classes( $context ) ) . '">';
		$html .= '<button type="button" class="chatkart-btn chatkart-order-btn" data-chatkart-open="product"'
			. ' data-product-id="' . esc_attr( $product->get_id() ) . '"'
			. ' data-product-type="' . esc_attr( $product->get_type() ) . '"'
			. ' data-product-name="' . esc_attr( $product->get_name() ) . '"'
			. ( $redirect ? ' data-redirect="' . esc_url( $redirect ) . '"' : '' )
			. '>' . $this->label() . '</button>';

		if ( ChatKart_Settings::is( 'share_on' ) ) {
			$share = ChatKart_Helpers::render(
				ChatKart_Settings::get( 'msg_share' ),
				array(
					'site_name'     => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
					'product_name'  => $product->get_name(),
					'product_url'   => get_permalink( $product->get_id() ),
					'product_price' => ChatKart_Helpers::price( wc_get_price_to_display( $product ) ),
				)
			);
			$html .= '<a class="chatkart-btn chatkart-share-btn" href="' . esc_url( ChatKart_Helpers::wa_url( '', $share ) ) . '" target="_blank" rel="noopener noreferrer">'
				. ( ChatKart_Settings::is( 'btn_icon' ) ? ChatKart_Helpers::icon() : '' )
				. '<span>' . esc_html( ChatKart_Settings::get( 'share_text' ) ) . '</span></a>';
		}

		return $html . '<span class="chatkart-notice" role="alert" aria-live="polite"></span></div>';
	}

	private function cart_html( $context ) {
		if ( ! ChatKart_Settings::is( 'enabled' ) || ( ChatKart_Settings::is( 'logged_in_only' ) && ! is_user_logged_in() ) ) {
			return '';
		}
		if ( ! function_exists( 'WC' ) || ! WC()->cart || WC()->cart->is_empty() ) {
			return '';
		}
		$this->needs_modal = true;
		return '<div class="' . esc_attr( $this->wrap_classes( $context ) ) . '"><button type="button" class="chatkart-btn chatkart-order-btn" data-chatkart-open="cart">' . $this->label() . '</button><span class="chatkart-notice" role="alert" aria-live="polite"></span></div>';
	}

	/* ---------------------------------------------------------------- popup */

	/**
	 * HTML pieces of the popup, keyed by token.
	 *
	 * @return array
	 */
	private function modal_parts() {
		$email   = ChatKart_Settings::get( 'field_email' );
		$address = ChatKart_Settings::get( 'field_address' );
		$note    = ChatKart_Settings::get( 'field_note' );

		$form  = '<form class="chatkart-form" novalidate>';
		$form .= '<label for="chatkart-name">' . esc_html__( 'Your name', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ) . ' *</label><input type="text" id="chatkart-name" name="name" maxlength="100" autocomplete="name" required>';
		$form .= '<label for="chatkart-phone">' . esc_html__( 'Phone / WhatsApp number', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ) . ' *</label><input type="tel" id="chatkart-phone" name="phone" maxlength="20" autocomplete="tel" required>';
		if ( 'hidden' !== $email ) {
			$form .= '<label for="chatkart-email">' . esc_html__( 'Email', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ) . ( 'required' === $email ? ' *' : '' ) . '</label><input type="email" id="chatkart-email" name="email" maxlength="100" autocomplete="email"' . ( 'required' === $email ? ' required' : '' ) . '>';
		}
		if ( 'hidden' !== $address ) {
			$form .= '<label for="chatkart-address">' . esc_html__( 'City / delivery address', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ) . ( 'required' === $address ? ' *' : '' ) . '</label><input type="text" id="chatkart-address" name="address" maxlength="250" autocomplete="street-address"' . ( 'required' === $address ? ' required' : '' ) . '>';
		}
		if ( 'hidden' !== $note ) {
			$form .= '<label for="chatkart-note">' . esc_html__( 'Note (optional)', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ) . '</label><textarea id="chatkart-note" name="note" rows="2" maxlength="500"></textarea>';
		}
		$form .= '<div class="chatkart-hp" aria-hidden="true"><label for="chatkart-website">Website</label><input type="text" id="chatkart-website" name="chatkart_website" tabindex="-1" autocomplete="off"></div>';
		$form .= '<div class="chatkart-error" role="alert"></div>';
		$form .= '<button type="submit" class="chatkart-btn chatkart-submit">' . esc_html( ChatKart_Settings::get( 'btn_text' ) ) . '</button></form>';

		return array(
			'{chatkart_close}'   => '<button type="button" class="chatkart-x" data-chatkart-close aria-label="' . esc_attr__( 'Close', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ) . '">&times;</button>',
			'{chatkart_title}'   => '<h3 id="chatkart-title">' . esc_html( ChatKart_Settings::get( 'btn_text' ) ) . '</h3>',
			'{chatkart_summary}' => '<p class="chatkart-summary"></p>',
			'{chatkart_cart}'    => '<div class="chatkart-cart" hidden><ul class="chatkart-cart-list"></ul><div class="chatkart-cart-total"></div></div>',
			'{chatkart_form}'    => $form,
			'{chatkart_done}'    => '<div class="chatkart-done" hidden><p class="chatkart-done-msg"></p><a class="chatkart-btn chatkart-open-wa" href="#" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Continue to WhatsApp', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ) . '</a></div>',
		);
	}

	public function modal() {
		if ( ! $this->needs_modal || ! ChatKart_Settings::is( 'enabled' ) ) {
			return;
		}
		$parts    = $this->modal_parts();
		$template = (string) ChatKart_Settings::get( 'popup_template' );
		if ( ! isset( ChatKart_Settings::templates()[ $template ] ) ) {
			$template = 'classic';
		}
		$inner = $parts['{chatkart_close}'] . $parts['{chatkart_title}'] . $parts['{chatkart_summary}'] . $parts['{chatkart_cart}'] . $parts['{chatkart_form}'] . $parts['{chatkart_done}'];

		echo '<div id="chatkart-modal" class="chatkart-modal chatkart-tpl-' . esc_attr( $template ) . '" data-template="' . esc_attr( $template ) . '" hidden><div class="chatkart-overlay" data-chatkart-close></div><div class="chatkart-dialog" role="dialog" aria-modal="true" aria-labelledby="chatkart-title" tabindex="-1">' . $inner . '</div></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- every part is escaped when it is built.
	}
}
