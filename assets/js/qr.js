/* Minimal QR Code generator (byte mode, error correction L, versions 1-6, mask 0). ChatKart. */
(function () {
	'use strict';
	var EXP = [], LOG = [], i, x = 1;
	for (i = 0; i < 255; i++) { EXP[i] = x; LOG[x] = i; x <<= 1; if (x & 256) { x ^= 0x11d; } }
	for (i = 255; i < 512; i++) { EXP[i] = EXP[i - 255]; }
	function gmul(a, b) { return (a === 0 || b === 0) ? 0 : EXP[LOG[a] + LOG[b]]; }
	function rsGen(n) {
		var g = [1], j, ng;
		for (var k = 0; k < n; k++) {
			ng = []; for (j = 0; j <= g.length; j++) { ng[j] = 0; }
			for (j = 0; j < g.length; j++) { ng[j] ^= g[j]; ng[j + 1] ^= gmul(g[j], EXP[k]); }
			g = ng;
		}
		return g;
	}
	function rsEnc(data, n) {
		var g = rsGen(n), res = [], j, f;
		for (j = 0; j < n; j++) { res[j] = 0; }
		for (var k = 0; k < data.length; k++) {
			f = data[k] ^ res[0];
			res.shift(); res.push(0);
			for (j = 0; j < n; j++) { res[j] ^= gmul(g[j + 1], f); }
		}
		return res;
	}
	/* total data codewords, EC codewords per block, blocks (level L) */
	var VER = { 1: [19, 7, 1], 2: [34, 10, 1], 3: [55, 15, 1], 4: [80, 20, 1], 5: [108, 26, 1], 6: [136, 18, 2] };

	function utf8(str) {
		if (typeof TextEncoder !== 'undefined') { return Array.prototype.slice.call(new TextEncoder().encode(str)); }
		var s = unescape(encodeURIComponent(str)), out = [];
		for (var k = 0; k < s.length; k++) { out.push(s.charCodeAt(k)); }
		return out;
	}

	function matrix(text) {
		var data = utf8(String(text)), v = 0, k;
		for (k = 1; k <= 6; k++) { if (data.length <= VER[k][0] - 2) { v = k; break; } }
		if (!v) { return null; }
		var dc = VER[v][0], ec = VER[v][1], nb = VER[v][2], size = 17 + 4 * v;

		// bit stream
		var bits = [];
		function put(val, len) { for (var b = len - 1; b >= 0; b--) { bits.push((val >>> b) & 1); } }
		put(4, 4); put(data.length, 8);
		for (k = 0; k < data.length; k++) { put(data[k], 8); }
		var cap = dc * 8;
		for (k = 0; k < 4 && bits.length < cap; k++) { bits.push(0); }
		while (bits.length % 8) { bits.push(0); }
		var cw = [];
		for (k = 0; k < bits.length; k += 8) {
			var byte = 0; for (var b = 0; b < 8; b++) { byte = (byte << 1) | bits[k + b]; }
			cw.push(byte);
		}
		for (k = 0; cw.length < dc; k++) { cw.push(k % 2 ? 0x11 : 0xec); }

		// blocks + interleave
		var per = dc / nb, blocks = [], ecs = [];
		for (k = 0; k < nb; k++) { blocks.push(cw.slice(k * per, (k + 1) * per)); ecs.push(rsEnc(blocks[k], ec)); }
		var all = [];
		for (k = 0; k < per; k++) { for (var q = 0; q < nb; q++) { all.push(blocks[q][k]); } }
		for (k = 0; k < ec; k++) { for (q = 0; q < nb; q++) { all.push(ecs[q][k]); } }
		var stream = [];
		for (k = 0; k < all.length; k++) { for (b = 7; b >= 0; b--) { stream.push((all[k] >>> b) & 1); } }
		if (v >= 2) { for (k = 0; k < 7; k++) { stream.push(0); } }

		// matrix
		var m = [], fn = [], r, c;
		for (r = 0; r < size; r++) { m[r] = []; fn[r] = []; for (c = 0; c < size; c++) { m[r][c] = 0; fn[r][c] = false; } }
		function set(rr, cc, dark) { if (rr >= 0 && rr < size && cc >= 0 && cc < size) { m[rr][cc] = dark ? 1 : 0; fn[rr][cc] = true; } }
		function finder(r0, c0) {
			for (var dr = -1; dr <= 7; dr++) {
				for (var dc2 = -1; dc2 <= 7; dc2++) {
					var dark = (dr >= 0 && dr <= 6 && (dc2 === 0 || dc2 === 6)) || (dc2 >= 0 && dc2 <= 6 && (dr === 0 || dr === 6)) || (dr >= 2 && dr <= 4 && dc2 >= 2 && dc2 <= 4);
					set(r0 + dr, c0 + dc2, dark);
				}
			}
		}
		finder(0, 0); finder(0, size - 7); finder(size - 7, 0);
		for (k = 8; k < size - 8; k++) { set(6, k, k % 2 === 0); set(k, 6, k % 2 === 0); }
		if (v >= 2) {
			for (var ar = -2; ar <= 2; ar++) { for (var ac = -2; ac <= 2; ac++) { set(size - 7 + ar, size - 7 + ac, Math.max(Math.abs(ar), Math.abs(ac)) !== 1); } }
		}
		// reserve format areas (written below)
		for (k = 0; k < 9; k++) { fn[8][k] = true; fn[k][8] = true; }
		for (k = 0; k < 8; k++) { fn[8][size - 1 - k] = true; fn[size - 1 - k][8] = true; }

		// data placement (mask 0)
		var idx = 0, up = true;
		for (var col = size - 1; col > 0; col -= 2) {
			if (col === 6) { col--; }
			for (var n = 0; n < size; n++) {
				r = up ? size - 1 - n : n;
				for (var d = 0; d < 2; d++) {
					c = col - d;
					if (!fn[r][c]) {
						var bit = idx < stream.length ? stream[idx] : 0; idx++;
						m[r][c] = bit ^ (((r + c) % 2 === 0) ? 1 : 0);
					}
				}
			}
			up = !up;
		}

		// format bits: level L (01), mask 0 (000)
		var fd = 8 << 10, g = 0x537;
		for (k = 14; k >= 10; k--) { if ((fd >>> k) & 1) { fd ^= g << (k - 10); } }
		var fbits = (((8 << 10) | fd) ^ 0x5412);
		function fb(n) { return ((fbits >>> n) & 1) === 1; }
		for (k = 0; k <= 5; k++) { set(k, 8, fb(k)); }
		set(7, 8, fb(6)); set(8, 8, fb(7)); set(8, 7, fb(8));
		for (k = 9; k < 15; k++) { set(8, 14 - k, fb(k)); }
		for (k = 0; k < 8; k++) { set(8, size - 1 - k, fb(k)); }
		for (k = 8; k < 15; k++) { set(size - 15 + k, 8, fb(k)); }
		set(size - 8, 8, true);
		return m;
	}

	function svg(text) {
		var m = matrix(text);
		if (!m) { return ''; }
		var n = m.length, q = 4, d = '';
		for (var r = 0; r < n; r++) { for (var c = 0; c < n; c++) { if (m[r][c]) { d += 'M' + (c + q) + ' ' + (r + q) + 'h1v1h-1z'; } } }
		var s = n + q * 2;
		return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' + s + ' ' + s + '" shape-rendering="crispEdges" role="img" aria-label="QR code"><rect width="' + s + '" height="' + s + '" fill="#fff"/><path d="' + d + '" fill="#000"/></svg>';
	}

	window.chatkartQR = svg;
	window.chatkartQR.matrix = matrix;
})();
