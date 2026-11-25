(function () {
	'use strict';
	var D = window.chatkartWidget || {};
	var A = D.an || {};

	function ss(k, v) {
		try { if (v === undefined) { return sessionStorage.getItem(k); } sessionStorage.setItem(k, v); } catch (e) { /* storage blocked */ }
		return null;
	}

	function waUrl(phone, text) { return 'https://wa.me/' + String(phone || '').replace(/\D/g, '') + (text ? '?text=' + encodeURIComponent(text) : ''); }

	/* ---------- analytics events: sent to tags that are already on the page (GA4 / GTM / Meta Pixel) ---------- */
	function emit(type, d) {
		d = d || {};
		var name = 'chatkart_whatsapp_' + type;
		var params = { source: d.source || '', agent: d.agent || '', page_url: location.href, page_title: document.title };
		try {
			if (A.ga && typeof window.gtag === 'function') { window.gtag('event', name, params); }
			if (A.gtm) { window.dataLayer = window.dataLayer || []; window.dataLayer.push({ event: name, chatkart_source: params.source, chatkart_agent: params.agent, page_url: params.page_url, page_title: params.page_title }); }
			if (A.px && typeof window.fbq === 'function') { window.fbq('track', { click: 'Contact', order: 'InitiateCheckout' }[type] || 'Contact', params); }
		} catch (e) { /* never break the page */ }
	}

	/* ---------- anonymous click counter (time, agent, page: no cookies, no IP) ---------- */
	function track(source, agent) {
		if (!D.track) { return; }
		var f = new FormData();
		f.append('action', 'chatkart_track'); f.append('source', source); f.append('agent', agent || ''); f.append('page', location.href);
		try { if (navigator.sendBeacon && navigator.sendBeacon(D.ajax, f)) { return; } } catch (e) { /* fall through */ }
		try { fetch(D.ajax, { method: 'POST', body: f, credentials: 'same-origin', keepalive: true }).catch(function () {}); } catch (e2) { /* ignore */ }
	}

	/* ---------- display rules (URL and login) ---------- */
	function wildcard(p) { return new RegExp('^' + String(p).slice(0, 200).replace(/\*{2,}/g, '*').replace(/[.+?^${}()|[\]\\]/g, '\\$&').replace(/\*/g, '.*') + '$'); }
	function trimSlash(s) { return s.length > 1 ? s.replace(/\/(\?|$)/, '$1') : s; }
	function urlMatch(list, path, pathname) {
		return list.some(function (p) {
			p = String(p).trim();
			if (!p) { return false; }
			var re = wildcard(p), re2 = wildcard(trimSlash(p));
			return re.test(path) || re.test(pathname) || re2.test(trimSlash(path)) || re2.test(trimSlash(pathname));
		});
	}
	function rulesPass(r, ctx) {
		if (r.url_in && r.url_in.length && !urlMatch(r.url_in, ctx.path, ctx.pathname)) { return false; }
		if (r.url_ex && r.url_ex.length && urlMatch(r.url_ex, ctx.path, ctx.pathname)) { return false; }
		if (r.login === 'in' && !ctx.loggedIn) { return false; }
		if (r.login === 'out' && ctx.loggedIn) { return false; }
		return true;
	}

	/* ---------- opening a chat ---------- */
	function openChat(phone, text, src, agent) {
		var win = window.open(waUrl(phone || D.phone, text), '_blank', 'noopener');
		if (!win) { window.location.href = waUrl(phone || D.phone, text); }
		track(src, agent); emit('click', { source: src, agent: agent });
	}

	window.chatkart = { emit: emit, track: track, waUrl: waUrl };

	var root = document.getElementById('chatkart-widget');
	var panel = root ? root.querySelector('.chatkart-panel') : null;
	var launcher = root ? root.querySelector('.chatkart-launcher') : null;

	function panelOpen() { return !!panel && !panel.hidden; }
	function openPanel() {
		if (!panel) { return; }
		panel.hidden = false; launcher.setAttribute('aria-expanded', 'true');
		hideBadge(); showQr();
		if (root.querySelector('.chatkart-cta')) { root.querySelector('.chatkart-cta').hidden = true; }
	}
	function closePanel() {
		if (!panel) { return; }
		panel.hidden = true; launcher.setAttribute('aria-expanded', 'false');
	}
	function hideBadge() { var b = root && root.querySelector('.chatkart-badge'); if (b) { b.hidden = true; ss('chatkart_badge', '1'); } }
	window.chatkart.openPanel = openPanel;

	/* ---------- anchor triggers & shortcode buttons (work on every page) ---------- */
	document.addEventListener('click', function (e) {
		var t = e.target;
		if (!t || !t.closest) { return; }
		var open = t.closest('a[href="#chatkart-chat"], .chatkart-open-chat');
		if (open) {
			e.preventDefault();
			if (panel) { openPanel(); } else { openChat(D.phone, D.text, 'widget', ''); }
			return;
		}
		var wa = t.closest('a[href="#whatsapp"], .chatkart-whatsapp, [data-chatkart-whatsapp]');
		if (wa) {
			e.preventDefault();
			openChat(wa.getAttribute('data-phone') || D.phone, wa.getAttribute('data-text') || D.text, 'float', '');
			return;
		}
		if (t.closest('a.chatkart-inline-btn')) {
			track('float', ''); emit('click', { source: 'float' });
			return;
		}
		if (t.closest('a.chatkart-launcher-link')) {
			hideBadge();
			track('float', ''); emit('click', { source: 'float' });
		}
	});

	if (!root) { return; }

	/* ---------- visibility rules ---------- */
	var rulesAttr = root.getAttribute('data-rules');
	function reveal() {
		root.hidden = false;
		var cta = root.querySelector('.chatkart-cta');
		if (cta && !ss('chatkart_cta_closed')) {
			var delay = (parseInt(cta.getAttribute('data-delay'), 10) || 0) * 1000;
			setTimeout(function () { if (!panelOpen() && !root.hidden) { cta.hidden = false; } }, delay);
			var x = cta.querySelector('.chatkart-cta-x');
			if (x) { x.addEventListener('click', function (ev) { ev.stopPropagation(); cta.hidden = true; ss('chatkart_cta_closed', '1'); }); }
		}
	}
	if (rulesAttr) {
		var rules = {};
		try { rules = JSON.parse(rulesAttr) || {}; } catch (e) { rules = {}; }
		var ctx = { path: location.pathname + location.search, pathname: location.pathname, loggedIn: document.body.classList.contains('logged-in') };
		if (rulesPass(rules, ctx)) { reveal(); }
	} else { reveal(); }

	if (ss('chatkart_badge') === '1') { hideBadge(); }

	/* ---------- popup widget ---------- */
	function showQr() {
		var box = root.querySelector('.chatkart-qr');
		if (!box || !D.qr || !window.chatkartQR || window.innerWidth < 783 || box.getAttribute('data-done')) { return; }
		var url = root.getAttribute('data-qr-url') || '';
		var svg = window.chatkartQR(url) || window.chatkartQR(url.split('?')[0]);
		if (svg) { box.querySelector('.chatkart-qr-img').innerHTML = svg; box.hidden = false; box.setAttribute('data-done', '1'); }
	}

	if (panel && launcher) {
		launcher.addEventListener('click', function () { if (panel.hidden) { openPanel(); } else { closePanel(); } });
		var closeBtn = root.querySelector('.chatkart-panel-x');
		if (closeBtn) { closeBtn.addEventListener('click', closePanel); }
		document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !panel.hidden) { closePanel(); launcher.focus(); } });
		document.addEventListener('click', function (e) { if (!panel.hidden && !root.contains(e.target)) { closePanel(); } });

		Array.prototype.forEach.call(root.querySelectorAll('.chatkart-agent'), function (a) {
			a.addEventListener('click', function () {
				var name = a.getAttribute('data-agent') || '';
				track('widget', name); emit('click', { source: 'widget', agent: name });
			});
		});
	}
})();
