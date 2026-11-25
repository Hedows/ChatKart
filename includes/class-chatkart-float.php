<?php
/**
 * Site-wide floating WhatsApp button / chat popup with display rules, triggers and per-page control.
 *
 * @package ChatKart
 */

defined( 'ABSPATH' ) || exit;

class ChatKart_Float {

	const META = '_chatkart_float';

	/** @var ChatKart_Float|null */
	private static $instance = null;

	/** @var bool|null */
	private $visible = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'wp_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'wp_footer', array( $this, 'render' ), 30 );
		add_action( 'add_meta_boxes', array( $this, 'meta_box' ) );
		add_action( 'save_post', array( $this, 'save' ), 10, 2 );
	}

	private function current_id() {
		if ( is_singular() ) {
			return (int) get_queried_object_id();
		}
		if ( function_exists( 'is_shop' ) && is_shop() && function_exists( 'wc_get_page_id' ) ) {
			return (int) wc_get_page_id( 'shop' );
		}
		if ( is_home() && ! is_front_page() ) {
			return (int) get_option( 'page_for_posts' );
		}
		return 0;
	}

	private function phone_ok() {
		return ChatKart_Settings::is( 'enabled' ) && strlen( ChatKart_Helpers::digits( ChatKart_Settings::get( 'phone' ) ) ) >= 8;
	}

	/** Server-side rules (content type). URL and login rules run in the browser. */
	private function server_rules_ok() {
		$types = (array) ChatKart_Settings::get( 'rule_posttypes' );
		if ( $types ) {
			$cur = '';
			if ( is_singular() ) {
				$cur = get_post_type();
			} elseif ( function_exists( 'is_shop' ) && is_shop() ) {
				$cur = 'product';
			} elseif ( is_home() ) {
				$cur = 'post';
			}
			if ( '' === $cur || ! in_array( $cur, $types, true ) ) {
				return false;
			}
		}
		return true;
	}

	private function is_visible() {
		if ( null !== $this->visible ) {
			return $this->visible;
		}
		$v = false;
		if ( $this->phone_ok() ) {
			$id       = $this->current_id();
			$override = $id ? (string) get_post_meta( $id, self::META, true ) : '';
			if ( 'hide' === $override ) {
				$v = false;
			} elseif ( 'show' === $override ) {
				$v = true;
			} else {
				$v = ChatKart_Settings::is( 'float_enabled' ) && $this->server_rules_ok();
			}
		}
		$this->visible = (bool) apply_filters( 'chatkart_float_visible', $v );
		return $this->visible;
	}

	/** Rules evaluated by the browser (cache friendly). Null when none are set. */
	private function client_rules() {
		$id = $this->current_id();
		if ( $id && 'show' === (string) get_post_meta( $id, self::META, true ) ) {
			return null;
		}
		$lines = static function ( $key ) {
			return array_values( array_filter( array_map( 'trim', preg_split( '/\r\n|\r|\n/', (string) ChatKart_Settings::get( $key ) ) ) ) );
		};
		$r = array(
			'url_in' => $lines( 'rule_url_include' ),
			'url_ex' => $lines( 'rule_url_exclude' ),
			'login'  => (string) ChatKart_Settings::get( 'rule_login' ),
		);
		$any = $r['url_in'] || $r['url_ex'] || 'all' !== $r['login'];
		return $any ? $r : null;
	}

	/* ------------------------------------------------------------ message / phone for this page */

	private function page_phone() {
		$id = $this->current_id();
		$p  = $id ? ChatKart_Helpers::phone_for_post( $id ) : '';
		return '' !== $p ? $p : ChatKart_Helpers::digits( ChatKart_Settings::get( 'phone' ) );
	}

	private function page_text() {
		global $wp;
		$id   = $this->current_id();
		$tpl  = $id ? (string) get_post_meta( $id, '_chatkart_message', true ) : '';
		$tpl  = '' !== trim( $tpl ) ? $tpl : (string) ChatKart_Settings::get( 'float_msg' );
		$url  = is_singular() ? get_permalink() : home_url( user_trailingslashit( isset( $wp->request ) ? $wp->request : '' ) );
		return ChatKart_Helpers::render(
			$tpl,
			array(
				'site_name'  => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
				'page_title' => wp_specialchars_decode( wp_get_document_title(), ENT_QUOTES ),
				'page_url'   => $url,
			)
		);
	}

	/* ------------------------------------------------------------ agents */

	private function agents() {
		$global = $this->page_phone();
		$line   = trim( (string) strtok( (string) ChatKart_Settings::get( 'widget_agents' ), "\r\n" ) );
		$p      = array_map( 'trim', explode( '|', $line ) );
		if ( '' === $p[0] ) {
			return array();
		}
		$phone = ChatKart_Helpers::digits( isset( $p[2] ) ? $p[2] : '' );
		if ( strlen( $phone ) < 8 ) {
			$phone = $global;
		}
		if ( strlen( $phone ) < 8 ) {
			return array();
		}
		return array(
			array(
				'name'  => function_exists( 'mb_substr' ) ? mb_substr( $p[0], 0, 60 ) : substr( $p[0], 0, 60 ),
				'role'  => function_exists( 'mb_substr' ) ? mb_substr( isset( $p[1] ) ? $p[1] : '', 0, 60 ) : substr( isset( $p[1] ) ? $p[1] : '', 0, 60 ),
				'phone' => $phone,
			),
		);
	}

	private function popup_mode() {
		return 'popup' === ChatKart_Settings::get( 'widget_mode' ) && $this->agents();
	}

	/* ------------------------------------------------------------ assets */

	public function assets() {
		if ( ! $this->phone_ok() ) {
			return;
		}
		wp_enqueue_style( 'chatkart-float', CHATKART_URL . 'assets/css/float.css', array(), CHATKART_VERSION );
		$bg    = sanitize_hex_color( ChatKart_Settings::get( 'float_bg' ) );
		$color = sanitize_hex_color( ChatKart_Settings::get( 'float_color' ) );
		wp_add_inline_style(
			'chatkart-float',
			sprintf(
				':root{--chatkart-f-bg:%1$s;--chatkart-f-color:%2$s;--chatkart-f-size:%3$dpx;--chatkart-f-bottom:%4$dpx}',
				$bg ? $bg : '#25D366',
				$color ? $color : '#ffffff',
				(int) ChatKart_Settings::get( 'float_size' ),
				(int) ChatKart_Settings::get( 'float_bottom' )
			)
		);

		$deps = array();
		if ( $this->is_visible() && $this->popup_mode() && ChatKart_Settings::is( 'float_qr' ) ) {
			wp_register_script( 'chatkart-qr', CHATKART_URL . 'assets/js/qr.js', array(), CHATKART_VERSION, true );
			$deps[] = 'chatkart-qr';
		}
		wp_register_script( 'chatkart-widget', CHATKART_URL . 'assets/js/widget.js', $deps, CHATKART_VERSION, true );
		wp_enqueue_script( 'chatkart-widget' );
		wp_localize_script(
			'chatkart-widget',
			'chatkartWidget',
			array(
					'ajax'  => admin_url( 'admin-ajax.php' ),
					'track' => ChatKart_Settings::is( 'widget_track' ) ? 1 : 0,
					'an'    => array(
						'ga'  => ChatKart_Settings::is( 'ga_events' ) ? 1 : 0,
						'gtm' => ChatKart_Settings::is( 'gtm_events' ) ? 1 : 0,
						'px'  => ChatKart_Settings::is( 'pixel_events' ) ? 1 : 0,
					),
					'phone' => $this->page_phone(),
					'text'  => $this->page_text(),
					'qr'    => ( ChatKart_Settings::is( 'float_qr' ) ? 1 : 0 ),
				)
		);
	}

	/* ------------------------------------------------------------ render */

	public function render() {
		if ( ! $this->is_visible() ) {
			return;
		}
		$label  = (string) ChatKart_Settings::get( 'float_label' );
		$side   = ( 'left' === ChatKart_Settings::get( 'float_pos' ) ) ? 'left' : 'right';
		$dev    = 'chatkart-dev-' . sanitize_html_class( ChatKart_Settings::get( 'float_devices' ) );
		$fx     = sanitize_html_class( ChatKart_Settings::get( 'float_effect' ) );
		$rules  = $this->client_rules();
		$text   = $this->page_text();
		$phone  = $this->page_phone();
		$icon   = ChatKart_Helpers::icon();
		$badge  = ChatKart_Settings::is( 'float_badge' ) ? '<span class="chatkart-badge" aria-hidden="true">1</span>' : '';
		$cta    = (string) ChatKart_Settings::get( 'float_cta' );
		$aria   = esc_attr__( 'Chat with us on WhatsApp', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' );
		$lbl    = '' !== $label ? '<span class="chatkart-float-label" dir="auto">' . esc_html( $label ) . '</span>' : '';
		$launch = ( '' !== $label ? ' has-label' : '' ) . ( 'none' !== $fx && '' !== $fx ? ' chatkart-fx-' . $fx : '' );
		$popup  = $this->popup_mode();
		$agents = $popup ? $this->agents() : array();

		echo '<div id="chatkart-widget" class="chatkart-widget chatkart-float-' . esc_attr( $side ) . ' ' . esc_attr( $dev );
		echo '"';
		if ( $rules ) {
			echo ' data-rules="' . esc_attr( wp_json_encode( $rules ) ) . '" hidden';
		}
		if ( $popup && ChatKart_Settings::is( 'float_qr' ) && $agents ) {
			echo ' data-qr-url="' . esc_url( ChatKart_Helpers::wa_url( $agents[0]['phone'], $text ) ) . '"';
		}
		echo '>';

		if ( '' !== $cta ) {
			echo '<div class="chatkart-cta" hidden data-delay="' . esc_attr( (int) ChatKart_Settings::get( 'float_cta_delay' ) ) . '"><span dir="auto">' . esc_html( $cta ) . '</span><button type="button" class="chatkart-cta-x" aria-label="' . esc_attr__( 'Close', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ) . '">&times;</button></div>';
		}

		if ( ! $popup ) {
			echo '<a class="chatkart-launcher chatkart-launcher-link' . esc_attr( $launch ) . '" href="' . esc_url( ChatKart_Helpers::wa_url( $phone, $text ) ) . '" target="_blank" rel="noopener noreferrer" aria-label="' . $aria . '" data-text="' . esc_attr( $text ) . '" data-phone="' . esc_attr( $phone ) . '">' . $icon . $lbl . $badge . '</a></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- icon/label/badge built from escaped parts.
			return;
		}

		echo '<div class="chatkart-panel" id="chatkart-panel" role="dialog" aria-label="' . esc_attr( ChatKart_Settings::get( 'widget_title' ) ) . '" hidden>';
		echo '<div class="chatkart-panel-head"><div><strong dir="auto">' . esc_html( ChatKart_Settings::get( 'widget_title' ) ) . '</strong><small dir="auto">' . esc_html( ChatKart_Settings::get( 'widget_reply' ) ) . '</small></div><button type="button" class="chatkart-panel-x" aria-label="' . esc_attr__( 'Close', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ) . '">&times;</button></div>';
		echo '<div class="chatkart-panel-body"><div class="chatkart-bubble" dir="auto">' . nl2br( esc_html( ChatKart_Settings::get( 'widget_greeting' ) ) ) . '</div>';
		echo '<ul class="chatkart-agents">';
		foreach ( $agents as $a ) {
			$initial = strtoupper( function_exists( 'mb_substr' ) ? mb_substr( $a['name'], 0, 1 ) : substr( $a['name'], 0, 1 ) );
			echo '<li><a class="chatkart-agent" href="' . esc_url( ChatKart_Helpers::wa_url( $a['phone'], $text ) ) . '" target="_blank" rel="noopener noreferrer" data-agent="' . esc_attr( $a['name'] ) . '"><span class="chatkart-avatar" aria-hidden="true">' . esc_html( $initial ) . '</span><span class="chatkart-ag-text"><strong dir="auto">' . esc_html( $a['name'] ) . '</strong><small dir="auto">' . esc_html( $a['role'] ) . '</small></span></a></li>';
		}
		echo '</ul>';

		if ( ChatKart_Settings::is( 'float_qr' ) ) {
			echo '<div class="chatkart-qr" hidden><p>' . esc_html__( 'Or scan with your phone', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ) . '</p><div class="chatkart-qr-img"></div></div>';
		}
		echo '</div></div>';

		echo '<button type="button" class="chatkart-launcher' . esc_attr( $launch ) . '" aria-expanded="false" aria-controls="chatkart-panel" aria-label="' . esc_attr( ChatKart_Settings::get( 'widget_title' ) ) . '">' . $icon . $lbl . $badge . '</button></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
	}

	/* ------------------------------------------------------------ per-page box */

	public function meta_box() {
		$types = get_post_types( array( 'public' => true ) );
		unset( $types['attachment'] );
		add_meta_box( 'chatkart-float-box', __( 'WhatsApp chat button', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ), array( $this, 'render_box' ), array_values( $types ), 'side', 'low' );
	}

	public function render_box( $post ) {
		$val   = (string) get_post_meta( $post->ID, self::META, true );
		$phone = (string) get_post_meta( $post->ID, '_chatkart_phone', true );
		$msg   = (string) get_post_meta( $post->ID, '_chatkart_message', true );
		wp_nonce_field( 'chatkart_float', 'chatkart_float_nonce' );
		echo '<p><label for="chatkart_float_override" class="screen-reader-text">' . esc_html__( 'WhatsApp chat button', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ) . '</label><select name="chatkart_float_override" id="chatkart_float_override" style="width:100%">';
		foreach ( array( '' => __( 'Use global setting', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ), 'show' => __( 'Always show on this page', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ), 'hide' => __( 'Hide on this page', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ) ) as $k => $label ) {
			echo '<option value="' . esc_attr( $k ) . '"' . selected( $val, $k, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select></p>';
		echo '<p><label for="chatkart_page_phone"><strong>' . esc_html__( 'WhatsApp number for this page (optional)', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ) . '</strong></label><input type="text" id="chatkart_page_phone" name="chatkart_page_phone" value="' . esc_attr( $phone ) . '" placeholder="923001234567" style="width:100%"></p>';
		echo '<p><label for="chatkart_page_msg"><strong>' . esc_html__( 'Prefilled message for this page (optional)', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ) . '</strong></label><textarea id="chatkart_page_msg" name="chatkart_page_msg" rows="3" style="width:100%" placeholder="Hi! I have a question about {page_title}">' . esc_textarea( $msg ) . '</textarea></p>';
	}

	public function save( $post_id, $post ) {
		if ( ! isset( $_POST['chatkart_float_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['chatkart_float_nonce'] ) ), 'chatkart_float' ) ) {
			return;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		$val = isset( $_POST['chatkart_float_override'] ) ? sanitize_key( wp_unslash( $_POST['chatkart_float_override'] ) ) : '';
		if ( in_array( $val, array( 'show', 'hide' ), true ) ) {
			update_post_meta( $post_id, self::META, $val );
		} else {
			delete_post_meta( $post_id, self::META );
		}
		$phone = isset( $_POST['chatkart_page_phone'] ) ? ChatKart_Helpers::digits( sanitize_text_field( wp_unslash( $_POST['chatkart_page_phone'] ) ) ) : '';
		if ( strlen( $phone ) >= 8 ) {
			update_post_meta( $post_id, '_chatkart_phone', $phone );
		} else {
			delete_post_meta( $post_id, '_chatkart_phone' );
		}
		$msg = isset( $_POST['chatkart_page_msg'] ) ? sanitize_textarea_field( wp_unslash( $_POST['chatkart_page_msg'] ) ) : '';
		if ( '' !== trim( $msg ) ) {
			update_post_meta( $post_id, '_chatkart_message', $msg );
		} else {
			delete_post_meta( $post_id, '_chatkart_message' );
		}
	}
}
