(function (wp) {
	'use strict';
	var el = wp.element.createElement, __ = wp.i18n.__, C = wp.components;
	var SSR = wp.serverSideRender;
	wp.blocks.registerBlockType('chatkart/chat-button', {
		edit: function (props) {
			var a = props.attributes, set = props.setAttributes;
			return el('div', wp.blockEditor.useBlockProps(), [
				el(wp.blockEditor.InspectorControls, { key: 'inspector' },
					el(C.PanelBody, { title: __('WhatsApp button', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce'), initialOpen: true }, [
						el(C.TextControl, { key: 't', label: __('Button text', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce'), value: a.text, onChange: function (v) { set({ text: v }); } }),
						el(C.TextControl, { key: 'p', label: __('WhatsApp number (optional)', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce'), help: __('With country code. Empty = number set for this page, or your main number.', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce'), value: a.phone, onChange: function (v) { set({ phone: v }); } }),
						el(C.TextareaControl, { key: 'm', label: __('Prefilled message (optional)', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce'), value: a.message, onChange: function (v) { set({ message: v }); } }),
						el(C.ToggleControl, { key: 's', label: __('Show icon', 'chatkart-click-to-chat-button-cart-checkout-for-woocommerce'), checked: a.showIcon, onChange: function (v) { set({ showIcon: v }); } })
					])
				),
				el(SSR, { key: 'render', block: 'chatkart/chat-button', attributes: a })
			]);
		},
		save: function () { return null; }
	});
})(window.wp);
