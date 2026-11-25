<?php
/**
 * General-purpose chat button: [chatkart_chat] shortcode, Gutenberg block and Elementor widget.
 *
 * @package ChatKart
 */

defined( 'ABSPATH' ) || exit;

final class ChatKart_Blocks {

	/** @var ChatKart_Blocks|null */
	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_shortcode( 'chatkart_chat', array( $this, 'shortcode' ) );
		add_action( 'init', array( $this, 'register_block' ), 20 );
		add_action( 'elementor/widgets/register', array( $this, 'elementor' ) );
		$this->register_block();
	}

	/**
	 * Markup of the inline button.
	 *
	 * @param array $a text, phone, message, icon (yes|no), class.
	 * @return string
	 */
	public static function button_html( $a ) {
		$a     = wp_parse_args( (array) $a, array( 'text' => '', 'phone' => '', 'message' => '', 'icon' => 'yes', 'class' => '' ) );
		$phone = ChatKart_Helpers::digits( $a['phone'] );
		if ( strlen( $phone ) < 8 ) {
			$id    = get_the_ID();
			$phone = $id ? ChatKart_Helpers::phone_for_post( $id ) : '';
		}
		if ( strlen( $phone ) < 8 ) {
			$phone = ChatKart_Helpers::digits( ChatKart_Settings::get( 'phone' ) );
		}
		if ( strlen( $phone ) < 8 || ! ChatKart_Settings::is( 'enabled' ) ) {
			return '';
		}
		$tpl  = '' !== trim( (string) $a['message'] ) ? (string) $a['message'] : (string) ChatKart_Settings::get( 'float_msg' );
		$text = ChatKart_Helpers::render(
			$tpl,
			array(
				'site_name'  => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
				'page_title' => wp_specialchars_decode( wp_get_document_title(), ENT_QUOTES ),
				'page_url'   => is_singular() ? get_permalink() : home_url( '/' ),
			)
		);
		$label = '' !== trim( (string) $a['text'] ) ? (string) $a['text'] : (string) ChatKart_Settings::get( 'btn_text' );
		$bg    = sanitize_hex_color( ChatKart_Settings::get( 'btn_bg' ) );
		$fg    = sanitize_hex_color( ChatKart_Settings::get( 'btn_color' ) );

		wp_enqueue_style( 'chatkart-float', CHATKART_URL . 'assets/css/float.css', array(), CHATKART_VERSION );
		if ( wp_script_is( 'chatkart-widget', 'registered' ) ) {
			wp_enqueue_script( 'chatkart-widget' );
		}
		return '<a class="chatkart-inline-btn ' . esc_attr( implode( ' ', array_filter( array_map( 'sanitize_html_class', preg_split( '/\s+/', (string) $a['class'] ) ) ) ) ) . '" style="--chatkart-f-bg:' . esc_attr( $bg ? $bg : '#25D366' ) . ';--chatkart-f-color:' . esc_attr( $fg ? $fg : '#ffffff' ) . '" href="' . esc_url( ChatKart_Helpers::wa_url( $phone, $text ) ) . '" target="_blank" rel="noopener noreferrer">' . ( 'no' === $a['icon'] ? '' : ChatKart_Helpers::icon() ) . '<span dir="auto">' . esc_html( $label ) . '</span></a>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static icon markup.
	}

	/** [chatkart_chat text="Chat with us" phone="923001234567" message="Hi!" icon="yes" class="my-class"] */
	public function shortcode( $atts ) {
		$atts = shortcode_atts( array( 'text' => '', 'phone' => '', 'message' => '', 'icon' => 'yes', 'class' => '' ), $atts, 'chatkart_chat' );
		return self::button_html( $atts );
	}

	public function register_block() {
		static $done = false;
		if ( $done || ! function_exists( 'register_block_type' ) ) {
			return;
		}
		$done = true;
		register_block_type( CHATKART_DIR . 'blocks/chat-button', array( 'render_callback' => array( $this, 'render_block' ) ) );
	}

	public function render_block( $attrs ) {
		return self::button_html(
			array(
				'text'    => isset( $attrs['text'] ) ? $attrs['text'] : '',
				'phone'   => isset( $attrs['phone'] ) ? $attrs['phone'] : '',
				'message' => isset( $attrs['message'] ) ? $attrs['message'] : '',
				'icon'    => ( isset( $attrs['showIcon'] ) && ! $attrs['showIcon'] ) ? 'no' : 'yes',
			)
		);
	}

	public function elementor( $widgets_manager ) {
		if ( class_exists( '\Elementor\Widget_Base' ) ) {
			require_once CHATKART_DIR . 'includes/class-chatkart-elementor.php';
			$widgets_manager->register( new ChatKart_Elementor_Widget() );
		}
	}
}
