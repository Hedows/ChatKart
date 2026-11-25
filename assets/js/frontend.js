(function () {
	'use strict';
	var D = window.chatkartData || {};
	var i18n = D.i18n || {};
	var modal, form, doneBox, errBox, submitBtn, summary, cartBox, cartList, cartTotal, lastFocus;
	var ctx = null;
	var nonce = '';
	var prefilled = false;
	var mode = 'product';

	function $(sel, root) { return (root || document).querySelector(sel); }
	function fire(name, detail) {
		try { document.dispatchEvent(new CustomEvent(name, { detail: detail || {} })); } catch (e) {}
	}
	function el(tag, cls, text) {
		var n = document.createElement(tag);
		if (cls) { n.className = cls; }
		if (text !== undefined) { n.textContent = text; }
		return n;
	}

	function post(data) {
		var body = new URLSearchParams();
		Object.keys(data).forEach(function (k) {
			var v = data[k];
			if (v && typeof v === 'object') {
				Object.keys(v).forEach(function (kk) { body.append(k + '[' + kk + ']', v[kk]); });
			} else {
				body.append(k, v);
			}
		});
		return fetch(D.ajax, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: body.toString()
		}).then(function (r) { return r.json(); });
	}

	function loadInit() {
		return post({ action: 'chatkart_init' }).then(function (res) {
			if (res && res.success) {
				nonce = res.data.nonce;
				var p = res.data.prefill || {};
				if (!prefilled && form) {
					['name', 'phone', 'email', 'address'].forEach(function (k) {
						var f = form.elements[k];
						if (f && !f.value && p[k]) { f.value = p[k]; }
					});
					prefilled = true;
				}
			}
		}).catch(function () {});
	}
	function ensureNonce() { return nonce ? Promise.resolve() : loadInit(); }

	/* ---------- cart list ---------- */
	function renderCart(data) {
		if (!cartList) { return; }
		cartList.textContent = '';
		if (!data.items.length) {
			cartList.appendChild(el('li', 'chatkart-cart-empty', i18n.empty));
			if (cartTotal) { cartTotal.textContent = ''; }
			if (form) { form.hidden = true; }
			return;
		}
		if (form && (!doneBox || doneBox.hidden)) { form.hidden = false; }
		data.items.forEach(function (it) {
			var li = el('li', 'chatkart-cart-item');
			li.setAttribute('data-key', it.key);
			var name = el('div', 'chatkart-ci-name', it.name);
			if (it.variation) { name.appendChild(el('small', '', it.variation)); }
			var q = el('div', 'chatkart-qty');
			var minus = el('button', '', '\u2212'); minus.type = 'button'; minus.setAttribute('data-act', 'dec'); minus.setAttribute('aria-label', i18n.less);
			var num = el('span', '', String(it.qty)); num.setAttribute('data-qty', it.qty);
			var plus = el('button', '', '+'); plus.type = 'button'; plus.setAttribute('data-act', 'inc'); plus.setAttribute('aria-label', i18n.more);
			q.appendChild(minus); q.appendChild(num); q.appendChild(plus);
			var price = el('div', 'chatkart-ci-price', it.price);
			var rm = el('button', 'chatkart-ci-rm', '\u00d7'); rm.type = 'button'; rm.setAttribute('data-act', 'rm'); rm.setAttribute('aria-label', i18n.remove);
			li.appendChild(name); li.appendChild(q); li.appendChild(price); li.appendChild(rm);
			cartList.appendChild(li);
		});
		if (cartTotal) { cartTotal.textContent = i18n.total + ': ' + data.total; }
		if (summary) { summary.textContent = data.count + ' ' + i18n.items; }
	}

	function loadCart() {
		return post({ action: 'chatkart_cart' }).then(function (r) { if (r && r.success) { renderCart(r.data); } });
	}

	function onCartClick(e) {
		var b = e.target.closest ? e.target.closest('button[data-act]') : null;
		if (!b) { return; }
		var li = b.closest('.chatkart-cart-item');
		var qty = parseInt(li.querySelector('[data-qty]').getAttribute('data-qty'), 10) || 1;
		var act = b.getAttribute('data-act');
		var next = act === 'inc' ? qty + 1 : (act === 'dec' ? qty - 1 : 0);
		if (errBox) { errBox.textContent = ''; }
		ensureNonce().then(function () {
			return post({ action: 'chatkart_cart_update', nonce: nonce, key: li.getAttribute('data-key'), qty: next });
		}).then(function (r) {
			if (r && r.success) { renderCart(r.data); }
			else if (errBox) { errBox.textContent = (r && r.data && r.data.message) || i18n.error; }
		}).catch(function () { if (errBox) { errBox.textContent = i18n.error; } });
	}

	/* ---------- product form data ---------- */
	function setNotice(btn, msg) {
		var n = btn.closest('.chatkart-wrap');
		n = n && n.querySelector('.chatkart-notice');
		if (n) { n.textContent = msg || ''; }
	}

	function collect(btn) {
		var scope = btn.closest('.product, .wc-block-product, li, article') || document;
		var type = btn.getAttribute('data-product-type');
		var out = { mode: 'product', product_id: btn.getAttribute('data-product-id'), quantity: 1, variation_id: 0, attrs: {} };
		var f = $('form.cart', scope) || $('form.cart', document);

		if (f && btn.closest('.chatkart-ctx-product')) {
			var q = $('input.qty, input[name="quantity"]', f);
			if (q) { out.quantity = Math.max(1, parseInt(q.value, 10) || 1); }
			if (type === 'variable') {
				var v = $('input[name="variation_id"]', f);
				out.variation_id = v ? parseInt(v.value, 10) || 0 : 0;
				Array.prototype.forEach.call(f.querySelectorAll('[name^="attribute_"]'), function (x) {
					if ((x.type === 'radio' || x.type === 'checkbox') && !x.checked) { return; }
					out.attrs[x.name] = x.value;
				});
			}
		}
		return out;
	}

	/* ---------- popup ---------- */
	function openModal(m) {
		mode = m;
		lastFocus = document.activeElement;
		if (doneBox) { doneBox.hidden = true; }
		if (form) { form.hidden = false; }
		if (errBox) { errBox.textContent = ''; }
		if (cartBox) { cartBox.hidden = (m !== 'cart'); }
		modal.hidden = false;
		document.body.classList.add('chatkart-open');
		var first = form ? $('input', form) : null;
		if (first) { first.focus(); } else { $('.chatkart-dialog', modal).focus(); }
		loadInit();
		if (m === 'cart') { loadCart(); }
		fire('chatkart:open', { mode: m });
	}

	function closeModal() {
		modal.hidden = true;
		document.body.classList.remove('chatkart-open');
		if (lastFocus && lastFocus.focus) { lastFocus.focus(); }
		fire('chatkart:close');
	}

	function submit(extra) {
		var data = { action: 'chatkart_submit' };
		Object.keys(ctx.payload).forEach(function (k) { data[k] = ctx.payload[k]; });
		Object.keys(extra || {}).forEach(function (k) { data[k] = extra[k]; });
		return ensureNonce().then(function () {
			data.nonce = nonce;
			return post(data);
		}).then(function (res) {
			if (res && res.success) { return res.data; }
			throw new Error((res && res.data && res.data.message) || i18n.error);
		});
	}

	function finish(result, win) {
		var url = String(result.url || '');
		if (!/^https:\/\/wa\.me\//.test(url)) { if (win) { win.close(); } if (errBox) { errBox.textContent = i18n.error; } return; }
		if (win && !win.closed) { win.location.href = url; }
		if (D.useForm === 'yes' && modal && !modal.hidden && form && doneBox) {
			form.hidden = true;
			if (cartBox) { cartBox.hidden = true; }
			doneBox.hidden = false;
			$('.chatkart-done-msg', doneBox).textContent = result.message || '';
			var a = $('.chatkart-open-wa', doneBox);
			a.href = url;
			a.focus();
		} else if (!win || win.closed) {
			window.location.href = url;
		}
		fire('chatkart:success', { url: url, mode: mode });
		if (window.chatkart && window.chatkart.emit) { window.chatkart.emit('order', { source: 'woocommerce' }); }
	}

	function openBlank() {
		var w = window.open('', '_blank');
		if (w) { try { w.opener = null; } catch (err) {} }
		return w;
	}

	document.addEventListener('click', function (e) {
		var btn = e.target.closest ? e.target.closest('[data-chatkart-open]') : null;
		if (btn) {
			e.preventDefault();
			var redirect = btn.getAttribute('data-redirect');
			if (redirect) { window.location.href = redirect; return; }

			setNotice(btn, '');
			var m = btn.getAttribute('data-chatkart-open');
			var payload = m === 'cart' ? { mode: 'cart' } : collect(btn);

			if (m === 'product' && btn.getAttribute('data-product-type') === 'variable' && !payload.variation_id) {
				setNotice(btn, i18n.chooseOptions);
				return;
			}
			ctx = { payload: payload };

			if (D.useForm === 'yes' && modal && form) {
				if (summary) {
					summary.textContent = m === 'cart' ? '' : (btn.getAttribute('data-product-name') || '') + (payload.quantity > 1 ? ' x ' + payload.quantity : '');
				}
				openModal(m);
			} else {
				mode = m;
				var win = openBlank();
				btn.disabled = true;
				submit({}).then(function (r) { btn.disabled = false; finish(r, win); })
					.catch(function (er) { btn.disabled = false; if (win) { win.close(); } setNotice(btn, er.message || i18n.error); });
			}
			return;
		}
		if (e.target.closest && e.target.closest('[data-chatkart-close]')) {
			e.preventDefault();
			if (modal && !modal.hidden) { closeModal(); }
		}
	});

	document.addEventListener('keydown', function (e) {
		if (!modal || modal.hidden) { return; }
		if (e.key === 'Escape') { closeModal(); }
		if (e.key === 'Tab') {
			var all = modal.querySelectorAll('a[href],button:not([disabled]),input:not([tabindex="-1"]),textarea');
			var vis = Array.prototype.filter.call(all, function (x) { return x.offsetParent !== null; });
			if (!vis.length) { return; }
			var first = vis[0], last = vis[vis.length - 1];
			if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
			else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
		}
	});

	function init() {
		modal = $('#chatkart-modal');
		if (modal) {
			form = $('.chatkart-form', modal);
			doneBox = $('.chatkart-done', modal);
			errBox = $('.chatkart-error', modal);
			submitBtn = $('.chatkart-submit', modal);
			summary = $('.chatkart-summary', modal);
			cartBox = $('.chatkart-cart', modal);
			cartList = $('.chatkart-cart-list', modal);
			cartTotal = $('.chatkart-cart-total', modal);
			if (cartList) { cartList.addEventListener('click', onCartClick); }

			if (form) {
				form.addEventListener('submit', function (e) {
					e.preventDefault();
					if (errBox) { errBox.textContent = ''; }
					var fields = {};
					Array.prototype.forEach.call(form.elements, function (x) { if (x.name) { fields[x.name] = x.value; } });
					var win = openBlank();
					var label = submitBtn ? submitBtn.textContent : '';
					if (submitBtn) { submitBtn.disabled = true; submitBtn.textContent = i18n.sending || '...'; }
					submit(fields).then(function (r) {
						if (submitBtn) { submitBtn.disabled = false; submitBtn.textContent = label; }
						finish(r, win);
					}).catch(function (er) {
						if (submitBtn) { submitBtn.disabled = false; submitBtn.textContent = label; }
						if (win) { win.close(); }
						if (errBox) { errBox.textContent = er.message || i18n.error; }
					});
				});
			}
		}

	}

	if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', init); } else { init(); }
})();
