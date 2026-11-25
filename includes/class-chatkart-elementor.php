<?php
/**
 * Elementor widget (loaded only when Elementor is active).
 *
 * @package ChatKart
 */

defined( 'ABSPATH' ) || exit;

class ChatKart_Elementor_Widget extends \Elementor\Widget_Base {

	public function get_name() {
		return 'chatkart_chat_button';
	}

	public function get_title() {
		return __( 'WhatsApp Chat Button', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' );
	}

	public function get_icon() {
		return 'eicon-button';
	}

	public function get_categories() {
		return array( 'general' );
	}

	protected function register_controls() {
		$this->start_controls_section( 'content', array( 'label' => __( 'Button', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ), 'tab' => \Elementor\Controls_Manager::TAB_CONTENT ) );
		$this->add_control( 'text', array( 'label' => __( 'Text', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ), 'type' => \Elementor\Controls_Manager::TEXT, 'default' => '' ) );
		$this->add_control( 'phone', array( 'label' => __( 'WhatsApp number (optional)', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ), 'type' => \Elementor\Controls_Manager::TEXT, 'default' => '' ) );
		$this->add_control( 'message', array( 'label' => __( 'Prefilled message (optional)', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ), 'type' => \Elementor\Controls_Manager::TEXTAREA, 'default' => '' ) );
		$this->add_control( 'icon', array( 'label' => __( 'Show icon', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce' ), 'type' => \Elementor\Controls_Manager::SWITCHER, 'return_value' => 'yes', 'default' => 'yes' ) );
		$this->end_controls_section();
	}

	protected function render() {
		$s = $this->get_settings_for_display();
		echo ChatKart_Blocks::button_html( array( 'text' => $s['text'], 'phone' => $s['phone'], 'message' => $s['message'], 'icon' => ( 'yes' === $s['icon'] ) ? 'yes' : 'no' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
	}
}
