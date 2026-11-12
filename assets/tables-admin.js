/* Förderverein – Tabellen-Editor im Dashboard */
(function () {
	'use strict';
	var root = document.getElementById('fvbb-te');
	var input = document.getElementById('fvbb-table-data');
	if (!root || !input) { return; }

	var grid = root.querySelector('.fvbb-te__grid');
	var sizeEl = root.querySelector('.fvbb-te__size');
	var undoBtn = root.querySelector('[data-cmd="undo"]');
	var state;
	try { state = JSON.parse(input.value); } catch (e) { state = null; }
	if (!state || !Array.isArray(state.rows) || !state.rows.length) {
		state = { rows: [['', '', ''], ['', '', ''], ['', '', '']], head: true, foot: false, hidden_rows: [], hidden_cols: [], show_title: false, note: '' };
	}
	state.hidden_rows = state.hidden_rows || [];
	state.hidden_cols = state.hidden_cols || [];

	var history = [], dirty = false, lastField = null, typingTimer = null;

	function cols() { return state.rows[0] ? state.rows[0].length : 0; }
	function snapshot() {
		history.push(JSON.stringify(state));
		if (history.length > 60) { history.shift(); }
		undoBtn.disabled = false;
	}
	function changed() { dirty = true; }
	function colName(i) {
		var s = ''; i++;
		while (i > 0) { var m = (i - 1) % 26; s = String.fromCharCode(65 + m) + s; i = Math.floor((i - 1) / 26); }
		return s;
	}
	function has(list, i) { return list.indexOf(i) !== -1; }
	function shiftIndexes(list, at, delta, removed) {
		var out = [];
		list.forEach(function (i) {
			if (removed !== undefined && i === removed) { return; }
			out.push(i >= at ? i + delta : i);
		});
		return out;
	}

	/* ---------- Darstellung ---------- */
	function render() {
		var n = cols(), html = '<thead><tr><th class="corner"></th>';
		for (var c = 0; c < n; c++) {
			html += '<th class="colhead' + (has(state.hidden_cols, c) ? ' is-hidden' : '') + '"><button type="button" class="hbtn" data-col="' + c + '" aria-haspopup="true" title="Spalte ' + colName(c) + ' – Aktionen">' + colName(c) + '</button></th>';
		}
		html += '</tr></thead><tbody>';
		state.rows.forEach(function (row, r) {
			var cls = [];
			if (has(state.hidden_rows, r)) { cls.push('is-hidden'); }
			if (state.head && r === 0) { cls.push('is-head'); }
			if (state.foot && r === state.rows.length - 1 && state.rows.length > 1) { cls.push('is-foot'); }
			html += '<tr class="' + cls.join(' ') + '"><th class="rowhead"><button type="button" class="hbtn" data-row="' + r + '" aria-haspopup="true" title="Zeile ' + (r + 1) + ' – Aktionen">' + (r + 1) + '</button></th>';
			row.forEach(function (cell, c) {
				html += '<td class="' + (has(state.hidden_cols, c) ? 'is-hidden' : '') + '"><textarea rows="1" data-r="' + r + '" data-c="' + c + '" aria-label="Zelle ' + colName(c) + (r + 1) + '">' + escapeHtml(cell) + '</textarea></td>';
			});
			html += '</tr>';
		});
		html += '</tbody>';
		grid.innerHTML = html;
		grid.querySelectorAll('textarea').forEach(autosize);
		sizeEl.textContent = state.rows.length + ' Zeilen × ' + n + ' Spalten' + (state.hidden_rows.length || state.hidden_cols.length ? ' (' + (state.hidden_rows.length + state.hidden_cols.length) + ' ausgeblendet)' : '');
	}
	function escapeHtml(s) {
		return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
	}
	function autosize(t) { t.style.height = 'auto'; t.style.height = Math.max(34, t.scrollHeight + 2) + 'px'; }

	/* ---------- Zellen ---------- */
	grid.addEventListener('input', function (e) {
		var t = e.target;
		if (t.tagName !== 'TEXTAREA') { return; }
		if (!typingTimer) { snapshot(); }
		clearTimeout(typingTimer);
		typingTimer = setTimeout(function () { typingTimer = null; }, 800);
		state.rows[+t.dataset.r][+t.dataset.c] = t.value;
		autosize(t);
		changed();
	});
	grid.addEventListener('focusin', function (e) { if (e.target.tagName === 'TEXTAREA') { lastField = e.target; } });

	// Mehrere Zellen aus Excel/Google Sheets einfügen
	grid.addEventListener('paste', function (e) {
		var t = e.target;
		if (t.tagName !== 'TEXTAREA') { return; }
		var text = (e.clipboardData || window.clipboardData).getData('text');
		if (!text || (text.indexOf('\t') === -1 && text.replace(/\r?\n$/, '').indexOf('\n') === -1)) { return; }
		e.preventDefault();
		snapshot();
		var lines = text.replace(/\r\n/g, '\n').replace(/\n$/, '').split('\n').map(function (l) { return l.split('\t'); });
		var r0 = +t.dataset.r, c0 = +t.dataset.c;
		lines.forEach(function (cells, i) {
			var r = r0 + i;
			while (r >= state.rows.length) { state.rows.push(new Array(cols()).fill('')); }
			cells.forEach(function (val, j) {
				var c = c0 + j;
				while (c >= cols()) { state.rows.forEach(function (row) { row.push(''); }); }
				state.rows[r][c] = val;
			});
		});
		changed(); render();
		focusCell(r0, c0);
	});

	function focusCell(r, c) {
		var t = grid.querySelector('textarea[data-r="' + r + '"][data-c="' + c + '"]');
		if (t) { t.focus(); }
	}

	/* ---------- Zeilen/Spalten-Aktionen ---------- */
	var menu = document.createElement('div');
	menu.className = 'fvbb-te__menu';
	menu.hidden = true;
	document.body.appendChild(menu);

	function openMenu(btn, kind, idx) {
		var hidden = kind === 'row' ? has(state.hidden_rows, idx) : has(state.hidden_cols, idx);
		var items = kind === 'row'
			? [['row-above', 'Zeile darüber einfügen'], ['row-below', 'Zeile darunter einfügen'], ['row-up', 'Nach oben'], ['row-down', 'Nach unten'], ['row-dup', 'Zeile duplizieren'], ['row-hide', hidden ? 'Wieder einblenden' : 'Ausblenden (bleibt gespeichert)'], ['row-del', 'Zeile löschen']]
			: [['col-left', 'Spalte links einfügen'], ['col-right', 'Spalte rechts einfügen'], ['col-left-move', 'Nach links'], ['col-right-move', 'Nach rechts'], ['col-hide', hidden ? 'Wieder einblenden' : 'Ausblenden (bleibt gespeichert)'], ['col-del', 'Spalte löschen']];
		menu.innerHTML = items.map(function (it) {
			return '<button type="button" data-act="' + it[0] + '" data-idx="' + idx + '"' + (/del$/.test(it[0]) ? ' class="is-danger"' : '') + '>' + it[1] + '</button>';
		}).join('');
		var rect = btn.getBoundingClientRect();
		menu.style.top = (window.scrollY + rect.bottom + 4) + 'px';
		menu.style.left = (window.scrollX + rect.left) + 'px';
		menu.hidden = false;
		menu.querySelector('button').focus();
	}
	function closeMenu() { menu.hidden = true; }

	grid.addEventListener('click', function (e) {
		var b = e.target.closest('.hbtn');
		if (!b) { return; }
		if (b.dataset.row !== undefined) { openMenu(b, 'row', +b.dataset.row); }
		else { openMenu(b, 'col', +b.dataset.col); }
	});
	document.addEventListener('click', function (e) {
		if (!menu.hidden && !menu.contains(e.target) && !e.target.closest('.hbtn')) { closeMenu(); }
	});
	document.addEventListener('keydown', function (e) { if (e.key === 'Escape') { closeMenu(); } });

	menu.addEventListener('click', function (e) {
		var b = e.target.closest('button');
		if (!b) { return; }
		var i = +b.dataset.idx, act = b.dataset.act, n = cols(), tmp;
		if (/del$/.test(act)) {
			var what = act === 'row-del' ? 'Zeile ' + (i + 1) : 'Spalte ' + colName(i);
			if (!window.confirm(what + ' wirklich löschen?')) { return; }
		}
		snapshot();
		switch (act) {
			case 'row-above': state.rows.splice(i, 0, new Array(n).fill('')); state.hidden_rows = shiftIndexes(state.hidden_rows, i, 1); break;
			case 'row-below': state.rows.splice(i + 1, 0, new Array(n).fill('')); state.hidden_rows = shiftIndexes(state.hidden_rows, i + 1, 1); break;
			case 'row-dup': state.rows.splice(i + 1, 0, state.rows[i].slice()); state.hidden_rows = shiftIndexes(state.hidden_rows, i + 1, 1); break;
			case 'row-up': if (i > 0) { tmp = state.rows[i - 1]; state.rows[i - 1] = state.rows[i]; state.rows[i] = tmp; swapFlag(state.hidden_rows, i, i - 1); } break;
			case 'row-down': if (i < state.rows.length - 1) { tmp = state.rows[i + 1]; state.rows[i + 1] = state.rows[i]; state.rows[i] = tmp; swapFlag(state.hidden_rows, i, i + 1); } break;
			case 'row-hide': toggle(state.hidden_rows, i); break;
			case 'row-del':
				if (state.rows.length > 1) { state.rows.splice(i, 1); state.hidden_rows = shiftIndexes(state.hidden_rows, i + 1, -1, i); }
				break;
			case 'col-left': state.rows.forEach(function (r) { r.splice(i, 0, ''); }); state.hidden_cols = shiftIndexes(state.hidden_cols, i, 1); break;
			case 'col-right': state.rows.forEach(function (r) { r.splice(i + 1, 0, ''); }); state.hidden_cols = shiftIndexes(state.hidden_cols, i + 1, 1); break;
			case 'col-left-move': if (i > 0) { state.rows.forEach(function (r) { var t = r[i - 1]; r[i - 1] = r[i]; r[i] = t; }); swapFlag(state.hidden_cols, i, i - 1); } break;
			case 'col-right-move': if (i < n - 1) { state.rows.forEach(function (r) { var t = r[i + 1]; r[i + 1] = r[i]; r[i] = t; }); swapFlag(state.hidden_cols, i, i + 1); } break;
			case 'col-hide': toggle(state.hidden_cols, i); break;
			case 'col-del':
				if (n > 1) { state.rows.forEach(function (r) { r.splice(i, 1); }); state.hidden_cols = shiftIndexes(state.hidden_cols, i + 1, -1, i); }
				break;
		}
		closeMenu(); changed(); render();
	});
	function toggle(list, i) { var p = list.indexOf(i); if (p === -1) { list.push(i); } else { list.splice(p, 1); } }
	function swapFlag(list, a, b) {
		var ha = has(list, a), hb = has(list, b);
		if (ha !== hb) { toggle(list, a); toggle(list, b); }
	}

	/* ---------- Werkzeugleiste ---------- */
	root.querySelector('.fvbb-te__toolbar').addEventListener('click', function (e) {
		var b = e.target.closest('[data-cmd]');
		if (!b) { return; }
		var cmd = b.dataset.cmd;
		if (cmd === 'row-add') { snapshot(); state.rows.push(new Array(cols()).fill('')); changed(); render(); focusCell(state.rows.length - 1, 0); }
		if (cmd === 'col-add') { snapshot(); state.rows.forEach(function (r) { r.push(''); }); changed(); render(); focusCell(0, cols() - 1); }
		if (cmd === 'undo' && history.length) {
			state = JSON.parse(history.pop());
			undoBtn.disabled = !history.length;
			syncOptions(); changed(); render();
		}
		if ((cmd === 'bold' || cmd === 'link') && lastField) {
			var t = lastField, s = t.selectionStart, en = t.selectionEnd, sel = t.value.slice(s, en);
			var wrapped;
			if (cmd === 'bold') { wrapped = '<strong>' + (sel || 'Text') + '</strong>'; }
			else {
				var url = window.prompt('Link-Adresse (https://… oder mailto:…)', 'https://');
				if (!url) { return; }
				wrapped = '<a href="' + url.replace(/"/g, '&quot;') + '">' + (sel || url) + '</a>';
			}
			snapshot();
			t.value = t.value.slice(0, s) + wrapped + t.value.slice(en);
			state.rows[+t.dataset.r][+t.dataset.c] = t.value;
			autosize(t); t.focus(); changed();
		}
	});

	/* ---------- Einstellungen (Seitenleiste) ---------- */
	var opts = {
		head: document.getElementById('fvbb-opt-head'),
		foot: document.getElementById('fvbb-opt-foot'),
		show_title: document.getElementById('fvbb-opt-title'),
		note: document.getElementById('fvbb-opt-note')
	};
	function syncOptions() {
		if (opts.head) { opts.head.checked = !!state.head; }
		if (opts.foot) { opts.foot.checked = !!state.foot; }
		if (opts.show_title) { opts.show_title.checked = !!state.show_title; }
		if (opts.note) { opts.note.value = state.note || ''; }
	}
	['head', 'foot', 'show_title'].forEach(function (k) {
		if (opts[k]) { opts[k].addEventListener('change', function () { state[k] = opts[k].checked; changed(); render(); }); }
	});
	if (opts.note) { opts.note.addEventListener('input', function () { state.note = opts.note.value; changed(); }); }

	/* ---------- Speichern ---------- */
	var form = document.getElementById('post');
	if (form) {
		form.addEventListener('submit', function () {
			input.value = JSON.stringify(state);
			dirty = false;
		});
	}
	window.addEventListener('beforeunload', function (e) {
		if (dirty) { e.preventDefault(); e.returnValue = ''; }
	});

	syncOptions();
	render();
})();
