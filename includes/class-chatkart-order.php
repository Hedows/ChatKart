<?php
/**
 * AJAX order handling (builds the WhatsApp message) and email notifications.
 *
 * @package ChatKart
 */

defined( 'ABSPATH' ) || exit;

class ChatKart_Order {

	/** @var ChatKart_Order|null */
	private static $instance = null;

	/**
	 * @return ChatKart_Order
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'wp_ajax_chatkart_init', array( $this, 'ajax_init' ) );
		add_action( 'wp_ajax_nopriv_chatkart_init', array( $this, 'ajax_init' ) );
		add_action( 'wp_ajax_chatkart_cart', array( $this, 'ajax_cart' ) );
		add_action( 'wp_ajax_nopriv_chatkart_cart', array( $this, 'ajax_cart' ) );
		add_action( 'wp_ajax_chatkart_cart_update', array( $this, 'ajax_cart_update' ) );
		add_action( 'wp_ajax_nopriv_chatkart_cart_update', array( $this, 'ajax_cart_update' ) );
		add_action( 'wp_ajax_chatkart_submit', array( $this, 'ajax_submit' ) );
		add_action( 'wp_ajax_nopriv_chatkart_submit', array( $this, 'ajax_submit' ) );
	}

	/**
	 * Fresh nonce (works with page caching) and logged-in customer prefill.
	 */
	public function ajax_init() {
		nocache_headers();
		$data = array( 'nonce' => wp_create_nonce( 'chatkart_submit' ), 'prefill' => array() );

		if ( is_user_logged_in() ) {
			$user = wp_get_current_user();
			$cust = new WC_Customer( $user->ID );
			$data['prefill'] = array(
				'name'    => trim( $cust->get_billing_first_name() . ' ' . $cust->get_billing_last_name() ),
				'phone'   => $cust->get_billing_phone(),
				'email'   => $cust->get_billing_email() ? $cust->get_billing_email() : $user->user_email,
				'address' => trim( $cust->get_billing_address_1() . ' ' . $cust->get_billing_city() ),
			);
		}
		wp_send_json_success( $data );
	}

	/**
	 * Fail helper.
	 *
	 * @param string $message Message for the customer.
	 * @param int    $code    HTTP status.
	 */
	private function fail( $message, $code = 400 ) {
		wp_send_json_error( array( 'message' => $message ), $code );
	}

	/**
	 * Main submit handler.
	 */
	public function ajax_submit() {
		nocache_headers();

		if ( ! ChatKart_Settings::is( 'enabled' ) ) {
			$this->fail( __( 'WhatsApp ordering is currently unavailable.', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ), 403 );
		}
		if ( ! check_ajax_referer( 'chatkart_submit', 'nonce', false ) ) {
			$this->fail( __( 'Your session expired. Please reload the page and try again.', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ), 403 );
		}
		if ( ChatKart_Settings::is( 'logged_in_only' ) && ! is_user_logged_in() ) {
			$this->fail( __( 'Please log in to place an order.', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ), 403 );
		}
		// Honeypot: bots fill hidden fields.
		if ( ! empty( $_POST['chatkart_website'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification -- verified above.
			$this->fail( __( 'Your order could not be sent.', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ), 400 );
		}
		if ( strlen( ChatKart_Helpers::digits( ChatKart_Settings::get( 'phone' ) ) ) < 8 ) {
			$this->fail( __( 'The shop WhatsApp number is not configured yet.', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ), 500 );
		}
		$this->rate_limit();
		if ( ! apply_filters( 'chatkart_spam_check', true, 'order' ) ) {
			$this->fail( __( 'Your order could not be sent.', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ), 400 );
		}

		$mode  = isset( $_POST['mode'] ) && 'cart' === $_POST['mode'] ? 'cart' : 'product'; // phpcs:ignore WordPress.Security.NonceVerification
		$items = ( 'cart' === $mode ) ? $this->items_from_cart() : $this->items_from_request();
		if ( empty( $items ) ) {
			$this->fail( __( 'No valid product found for this order.', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ) );
		}

		$use_form = ChatKart_Settings::is( 'use_form' );
		$cust     = $use_form ? $this->customer_from_request() : array( 'name' => '', 'phone' => '', 'email' => '', 'address' => '', 'note' => '' );

		// Ignore an identical submission repeated within 30 seconds (double clicks, replays).
		if ( $use_form ) {
			$sig = 'chatkart_dd_' . md5( wp_json_encode( array( ChatKart_Helpers::client_ip(), $cust['phone'], $cust['name'], array_map( static function ( $i ) {
				return array( $i['parent']->get_id(), $i['buy']->get_id(), $i['qty'] );
			}, $items ) ) ) );
			if ( get_transient( $sig ) ) {
				$this->fail( __( 'Your order was just received. Please wait a moment before sending it again.', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ), 429 );
			}
			set_transient( $sig, 1, 30 );
		}

		$total = 0.0;
		foreach ( $items as $it ) {
			$total += $it['line_total'];
		}
		$total_text = ChatKart_Helpers::price( $total );

		$data    = $this->placeholders( $items, $cust, $total_text );
		$message        = ChatKart_Helpers::render( ChatKart_Settings::get( 'msg_order' ), $data );
		$message = (string) apply_filters( 'chatkart_order_message', $message, $items, $cust );
		$target  = ( 'product' === $mode ) ? ChatKart_Helpers::phone_for_post( $items[0]['parent']->get_id() ) : '';
		if ( '' === $target ) {
			$target = ChatKart_Helpers::digits( ChatKart_Settings::get( 'phone' ) );
		}
		$url = ChatKart_Helpers::wa_url( $target, $message );

		if ( $use_form ) {
			// The copy sent to the customer never contains the free-text note (prevents using the form to send arbitrary text).
			$data_c  = $data;
			$data_c['customer_note'] = '';
			$message_c = ChatKart_Helpers::render( ChatKart_Settings::get( 'msg_order' ), $data_c );
			$this->send_emails( $message, $message_c, $cust );
		}

		if ( class_exists( 'ChatKart_Data' ) ) {
			ChatKart_Data::log( 'order', '', wp_get_referer() ? wp_get_referer() : '' );
		}

		wp_send_json_success(
			array(
				'url'     => $url,
				'message' => ChatKart_Settings::get( 'confirm_text' ),
			)
		);
	}

	/**
	 * Max 10 submissions per IP per 10 minutes (fixed window).
	 */
	private function rate_limit() {
		if ( ! ChatKart_Helpers::rate_ok( 'order', 10 ) ) {
			$this->fail( __( 'Too many orders from your connection. Please wait a few minutes.', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ), 429 );
		}
	}

	/**
	 * Validate and collect customer fields.
	 *
	 * @return array
	 */
	private function customer_from_request() {
		// phpcs:disable WordPress.Security.NonceVerification -- nonce verified in ajax_submit().
		$get = static function ( $key, $max, $type = 'text' ) {
			$v = isset( $_POST[ $key ] ) && is_string( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : '';
			$v = 'textarea' === $type ? sanitize_textarea_field( $v ) : sanitize_text_field( $v );
			return function_exists( 'mb_substr' ) ? mb_substr( $v, 0, $max ) : substr( $v, 0, $max );
		};
		$c = array(
			'name'    => $get( 'name', 100 ),
			'phone'   => $get( 'phone', 20 ),
			'email'   => sanitize_email( $get( 'email', 100 ) ),
			'address' => $get( 'address', 250 ),
			'note'    => $get( 'note', 500, 'textarea' ),
		);
		// phpcs:enable

		if ( strlen( $c['name'] ) < 2 ) {
			$this->fail( __( 'Please enter your name.', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ) );
		}
		if ( ! preg_match( '/^[+0-9\s\-()]{7,20}$/', $c['phone'] ) || strlen( ChatKart_Helpers::digits( $c['phone'] ) ) < 7 ) {
			$this->fail( __( 'Please enter a valid phone number.', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ) );
		}
		$email_rule = ChatKart_Settings::get( 'field_email' );
		if ( 'hidden' === $email_rule ) {
			$c['email'] = '';
		} elseif ( '' !== $c['email'] && ! is_email( $c['email'] ) ) {
			$this->fail( __( 'Please enter a valid email address.', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ) );
		} elseif ( 'required' === $email_rule && '' === $c['email'] ) {
			$this->fail( __( 'Please enter your email address.', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ) );
		}
		$addr_rule = ChatKart_Settings::get( 'field_address' );
		if ( 'hidden' === $addr_rule ) {
			$c['address'] = '';
		} elseif ( 'required' === $addr_rule && strlen( $c['address'] ) < 3 ) {
			$this->fail( __( 'Please enter your delivery address.', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ) );
		}
		if ( 'hidden' === ChatKart_Settings::get( 'field_note' ) ) {
			$c['note'] = '';
		}
		return $c;
	}

	/**
	 * Build the item list from the posted product.
	 *
	 * @return array
	 */
	private function items_from_request() {
		// phpcs:disable WordPress.Security.NonceVerification -- nonce verified in ajax_submit().
		$pid     = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
		$vid     = isset( $_POST['variation_id'] ) ? absint( $_POST['variation_id'] ) : 0;
		$qty     = isset( $_POST['quantity'] ) ? absint( $_POST['quantity'] ) : 1;
		$posted  = isset( $_POST['attrs'] ) && is_array( $_POST['attrs'] ) ? wp_unslash( $_POST['attrs'] ) : array();
		// phpcs:enable
		$qty     = max( 1, min( $qty, 999 ) );
		$product = $pid ? wc_get_product( $pid ) : false;

		if ( ! $product || 'publish' !== $product->get_status() || ! ChatKart_Helpers::product_allowed( $product ) ) {
			return array();
		}

		$buy   = $product;
		$attrs = array();

		if ( $product->is_type( 'variable' ) ) {
			$resolved = $this->resolve_variation( $product, $vid, $posted );
			if ( ! $resolved ) {
				$this->fail( __( 'Please choose the product options first.', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ) );
			}
			$buy   = $resolved['variation'];
			$attrs = $resolved['attrs'];
		} elseif ( ! $product->is_purchasable() ) {
			return array();
		}

		$max = $buy->get_max_purchase_quantity();
		if ( $max > 0 ) {
			$qty = min( $qty, $max );
		}

		return array( $this->make_item( $product, $buy, $qty, $attrs ) );
	}

	/**
	 * Validate a variation and fill "any" attributes from the request.
	 *
	 * @param WC_Product $parent Variable product.
	 * @param int        $vid    Variation ID.
	 * @param array      $posted Posted attribute values.
	 * @return array|null
	 */
	private function resolve_variation( $parent, $vid, $posted ) {
		$v = $vid ? wc_get_product( $vid ) : false;
		if ( ! $v instanceof WC_Product_Variation || (int) $v->get_parent_id() !== (int) $parent->get_id() || 'publish' !== $v->get_status() || ! $v->is_purchasable() || ! $v->is_in_stock() ) {
			return null;
		}

		$allowed = $parent->get_variation_attributes();
		$final   = array();

		foreach ( $v->get_variation_attributes() as $key => $val ) {
			if ( '' !== $val ) {
				$final[ $key ] = $val;
				continue;
			}
			$choice = ( isset( $posted[ $key ] ) && is_string( $posted[ $key ] ) ) ? wc_clean( $posted[ $key ] ) : '';
			$valid  = false;
			foreach ( $allowed as $attr_name => $options ) {
				if ( 'attribute_' . sanitize_title( $attr_name ) === $key && in_array( $choice, array_map( 'strval', (array) $options ), true ) ) {
					$valid = true;
					break;
				}
			}
			if ( ! $valid ) {
				return null;
			}
			$final[ $key ] = $choice;
		}
		return array( 'variation' => $v, 'attrs' => $final );
	}

	/**
	 * Build the item list from the WooCommerce cart.
	 *
	 * @return array
	 */
	private function items_from_cart() {
		$this->load_cart();
		if ( ! WC()->cart || WC()->cart->is_empty() ) {
			return array();
		}
		$items = array();
		foreach ( WC()->cart->get_cart() as $ci ) {
			$buy = isset( $ci['data'] ) ? $ci['data'] : null;
			if ( ! $buy instanceof WC_Product || ! $buy->is_purchasable() ) {
				continue;
			}
			$parent = $buy->is_type( 'variation' ) ? wc_get_product( $buy->get_parent_id() ) : $buy;
			if ( ! $parent ) {
				continue;
			}
			$attrs = ( ! empty( $ci['variation'] ) && is_array( $ci['variation'] ) ) ? $ci['variation'] : array();
			$items[] = $this->make_item( $parent, $buy, max( 1, absint( $ci['quantity'] ) ), $attrs );
		}
		return $items;
	}

	/**
	 * Make sure the visitor's cart is available inside admin-ajax.php.
	 */
	private function load_cart() {
		if ( function_exists( 'wc_load_cart' ) && ( ! WC()->cart ) ) {
			wc_load_cart();
		}
		// admin-ajax.php runs after wp_loaded, so make sure the session cart is read.
		if ( WC()->cart && WC()->cart->is_empty() && method_exists( WC()->cart, 'get_cart_from_session' ) ) {
			WC()->cart->get_cart_from_session();
		}
	}

	/**
	 * Cart summary for the popup and the floating cart badge.
	 *
	 * @return array
	 */
	private function cart_payload() {
		$this->load_cart();
		$out = array( 'count' => 0, 'total' => '', 'items' => array() );
		if ( ! WC()->cart ) {
			return $out;
		}
		$sum = 0.0;
		foreach ( WC()->cart->get_cart() as $key => $ci ) {
			$buy = isset( $ci['data'] ) ? $ci['data'] : null;
			if ( ! $buy instanceof WC_Product ) {
				continue;
			}
			$parent = $buy->is_type( 'variation' ) ? wc_get_product( $buy->get_parent_id() ) : $buy;
			$qty    = max( 1, absint( $ci['quantity'] ) );
			$line   = (float) wc_get_price_to_display( $buy, array( 'qty' => $qty ) );
			$sum   += $line;
			$out['items'][] = array(
				'key'       => (string) $key,
				'name'      => $parent ? $parent->get_name() : $buy->get_name(),
				'variation' => ( $parent && ! empty( $ci['variation'] ) && is_array( $ci['variation'] ) ) ? $this->attrs_text( $parent, $ci['variation'] ) : '',
				'qty'       => $qty,
				'price'     => ChatKart_Helpers::price( $line ),
			);
			$out['count'] += $qty;
		}
		$out['total'] = ChatKart_Helpers::price( $sum );
		return $out;
	}

	public function ajax_cart() {
		nocache_headers();
		wp_send_json_success( $this->cart_payload() );
	}

	/**
	 * Change a cart line quantity (0 removes it).
	 */
	public function ajax_cart_update() {
		nocache_headers();
		if ( ! check_ajax_referer( 'chatkart_submit', 'nonce', false ) ) {
			$this->fail( __( 'Your session expired. Please reload the page and try again.', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ), 403 );
		}
		$this->load_cart();
		$key = isset( $_POST['key'] ) ? sanitize_text_field( wp_unslash( $_POST['key'] ) ) : '';
		$qty = isset( $_POST['qty'] ) ? min( 999, absint( $_POST['qty'] ) ) : 0;
		$cart = WC()->cart;
		if ( ! $cart || '' === $key || ! isset( $cart->get_cart()[ $key ] ) ) {
			$this->fail( __( 'This item is no longer in your cart.', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ) );
		}
		if ( $qty > 0 ) {
			$buy = $cart->get_cart()[ $key ]['data'];
			$max = ( $buy instanceof WC_Product ) ? $buy->get_max_purchase_quantity() : -1;
			if ( $max > 0 ) {
				$qty = min( $qty, $max );
			}
		}
		$cart->set_quantity( $key, $qty, true );
		wp_send_json_success( $this->cart_payload() );
	}

	/**
	 * Normalised item structure.
	 *
	 * @param WC_Product $parent Parent / simple product.
	 * @param WC_Product $buy    Purchasable product (variation or simple).
	 * @param int        $qty    Quantity.
	 * @param array      $attrs  Variation attributes (attribute_xxx => value).
	 * @return array
	 */
	private function make_item( $parent, $buy, $qty, $attrs ) {
		return array(
			'parent'     => $parent,
			'buy'        => $buy,
			'qty'        => $qty,
			'attrs'      => $attrs,
			'name'       => $parent->get_name(),
			'variation'  => $this->attrs_text( $parent, $attrs ),
			'unit'       => (float) wc_get_price_to_display( $buy ),
			'line_total' => (float) wc_get_price_to_display( $buy, array( 'qty' => $qty ) ),
			'url'        => get_permalink( $parent->get_id() ),
		);
	}

	/**
	 * "Colour: Red, Size: L".
	 *
	 * @param WC_Product $parent Parent product.
	 * @param array      $attrs  Attributes.
	 * @return string
	 */
	private function attrs_text( $parent, $attrs ) {
		$parts = array();
		foreach ( $attrs as $key => $val ) {
			if ( '' === $val ) {
				continue;
			}
			$name  = preg_replace( '/^attribute_/', '', (string) $key );
			$label = wc_attribute_label( $name, $parent );
			if ( taxonomy_exists( $name ) ) {
				$term = get_term_by( 'slug', $val, $name );
				if ( $term && ! is_wp_error( $term ) ) {
					$val = $term->name;
				}
			}
			$parts[] = $label . ': ' . $val;
		}
		return implode( ', ', $parts );
	}

	/**
	 * Placeholder values.
	 *
	 * @param array         $items      Items.
	 * @param array         $cust       Customer.
	 * @param string        $total_text Total as text.
	 * @return array
	 */
	private function placeholders( $items, $cust, $total_text ) {
		$lines = array();
		foreach ( $items as $i => $it ) {
			$line = ( $i + 1 ) . '. ' . $it['name'];
			if ( '' !== $it['variation'] ) {
				$line .= ' (' . $it['variation'] . ')';
			}
			$line   .= ' x ' . $it['qty'] . ' = ' . ChatKart_Helpers::price( $it['line_total'] );
			$lines[] = $line;
		}
		$first = $items[0];
		$names = $first['name'] . ( count( $items ) > 1 ? ' +' . ( count( $items ) - 1 ) : '' );

		return array(
			'site_name'        => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
			'product_name'     => $names,
			'product_url'      => $first['url'],
			'product_price'    => ChatKart_Helpers::price( $first['unit'] ),
			'variation'        => $first['variation'],
			'quantity'         => (string) $first['qty'],
			'order_items'      => implode( "\n", $lines ),
			'order_total'      => $total_text,
			'order_id'         => '',
			'order_status'     => '',
			'customer_name'    => $cust['name'],
			'customer_phone'   => $cust['phone'],
			'customer_email'   => $cust['email'],
			'customer_address' => $cust['address'],
			'customer_note'    => $cust['note'],
			'date'             => wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ),
		);
	}

	/**
	 * Safe plain-text mail.
	 *
	 * @param string|array $to      Recipient(s).
	 * @param string       $subject Subject.
	 * @param string       $body    Body.
	 * @param string       $reply   Optional reply-to email.
	 */
	private function mail( $to, $subject, $body, $reply = '' ) {
		$headers = array( 'Content-Type: text/plain; charset=UTF-8' );
		if ( $reply && is_email( $reply ) ) {
			$headers[] = 'Reply-To: ' . sanitize_email( $reply );
		}
		$subject = str_replace( array( "\r", "\n" ), ' ', wp_specialchars_decode( $subject, ENT_QUOTES ) );
		wp_mail( $to, $subject, $body, $headers );
	}

	/**
	 * Admin and customer emails after an order. Capped so the form cannot be used as a mail relay.
	 *
	 * @param string        $message   Message text for the shop owner (full).
	 * @param string        $message_c Message text for the customer (no free-text note).
	 * @param array         $cust      Customer.
	 */
	private function send_emails( $message, $message_c, $cust ) {
		$site = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$ref  = '';

		if ( ChatKart_Settings::is( 'email_admin' ) && ChatKart_Helpers::rate_global( 'mail_admin', 60, HOUR_IN_SECONDS ) ) {
			$to = ChatKart_Settings::get( 'email_admin_to' );
			$to = $to ? array_map( 'trim', explode( ',', $to ) ) : array( get_option( 'admin_email' ) );
			$body = $message;
			/* translators: 1: site name 2: order reference */
			$this->mail( $to, sprintf( __( '[%1$s] New WhatsApp order%2$s', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ), $site, $ref ), $body, $cust['email'] );
		}

		if ( ChatKart_Settings::is( 'email_confirm' ) && ! empty( $cust['email'] ) && is_email( $cust['email'] )
			&& ChatKart_Helpers::rate_global( 'mail_cust', 40, HOUR_IN_SECONDS )
			&& ChatKart_Helpers::rate_global( 'mail_to_' . md5( strtolower( $cust['email'] ) ), 3, DAY_IN_SECONDS ) ) {
			$body = sprintf( /* translators: %s: customer name */ __( 'Hello %s,', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ), $cust['name'] ) . "\n\n"
				. __( 'We received your order. Here is a copy:', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ) . "\n\n" . $message_c
				. "\n\n" . __( 'We will confirm it with you on WhatsApp shortly.', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ) . "\n" . $site;
			/* translators: 1: site name 2: order reference */
			$this->mail( $cust['email'], sprintf( __( '[%1$s] We received your order%2$s', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ), $site, $ref ), $body );
		}
	}

	/**
	 * Render the status template for an existing order.
	 *
	 * @param WC_Order $order Order.
	 * @return string
	 */
	public static function status_message( $order ) {
		$lines = array();
		$i     = 1;
		foreach ( $order->get_items() as $item ) {
			$lines[] = $i++ . '. ' . $item->get_name() . ' x ' . $item->get_quantity();
		}
		return ChatKart_Helpers::render(
			ChatKart_Settings::get( 'msg_status' ),
			array(
				'site_name'        => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
				'customer_name'    => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
				'customer_phone'   => $order->get_billing_phone(),
				'customer_email'   => $order->get_billing_email(),
				'customer_address' => $order->get_billing_address_1(),
				'customer_note'    => $order->get_customer_note(),
				'order_id'         => (string) $order->get_order_number(),
				'order_status'     => wc_get_order_status_name( $order->get_status() ),
				'order_items'      => implode( "\n", $lines ),
				'order_total'      => ChatKart_Helpers::price( $order->get_total() ),
				'date'             => wp_date( get_option( 'date_format' ) ),
			)
		);
	}
}
