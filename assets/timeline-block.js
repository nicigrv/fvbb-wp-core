/* Block "Zeitleiste" – Einträge direkt im Editor bearbeiten */
(function (blocks, el, be, comp) {
	var h = el.createElement;

	function move(list, i, d) {
		var j = i + d;
		if (j < 0 || j >= list.length) { return list; }
		var copy = list.slice();
		var t = copy[i]; copy[i] = copy[j]; copy[j] = t;
		return copy;
	}

	blocks.registerBlockType('fvbb/zeitleiste', {
		edit: function (props) {
			var a = props.attributes;
			var items = Array.isArray(a.items) ? a.items : [];
			function set(list) { props.setAttributes({ items: list }); }
			function update(i, key, val) {
				var copy = items.map(function (it, k) { if (k !== i) { return it; } var n = Object.assign({}, it); n[key] = val; return n; });
				set(copy);
			}
			function addTop() { set([{ date: '', title: '', text: '' }].concat(items)); }

			return h('div', be.useBlockProps({ className: 'fvbb-timeline-editor' }),
				h(be.InspectorControls, null,
					h(comp.PanelBody, { title: 'Zeitleiste', initialOpen: true },
						h(comp.ToggleControl, {
							label: 'Nach Jahren gruppieren', checked: a.years !== false, __nextHasNoMarginBottom: true,
							onChange: function (v) { props.setAttributes({ years: v }); }
						}),
						h('div', { style: { height: '12px' } }),
						h(comp.ToggleControl, {
							label: 'Automatisch nach Datum sortieren (neueste zuerst)', checked: a.sorted !== false, __nextHasNoMarginBottom: true,
							help: 'Das Datum darf frei geschrieben werden, z. B. „26. Oktober 2025“ oder „Mai 2024“.',
							onChange: function (v) { props.setAttributes({ sorted: v }); }
						})
					)
				),
				h('div', { className: 'fvbb-te-head' },
					h('strong', null, 'Zeitleiste – ' + items.length + (items.length === 1 ? ' Eintrag' : ' Einträge')),
					h(comp.Button, { variant: 'primary', onClick: addTop }, '+ Neuer Eintrag')
				),
				items.length ? null : h('p', { className: 'fvbb-te-empty' }, 'Noch keine Einträge. Oben auf „+ Neuer Eintrag“ klicken.'),
				items.map(function (it, i) {
					return h('div', { className: 'fvbb-te-item', key: i },
						h('div', { className: 'fvbb-te-row' },
							h(comp.TextControl, {
								label: 'Datum', value: it.date || '', placeholder: 'z. B. 26. Oktober 2025', __nextHasNoMarginBottom: true,
								onChange: function (v) { update(i, 'date', v); }
							}),
							h(comp.TextControl, {
								label: 'Überschrift (optional)', value: it.title || '', __nextHasNoMarginBottom: true,
								onChange: function (v) { update(i, 'title', v); }
							})
						),
						h(comp.TextareaControl, {
							label: 'Text', value: it.text || '', rows: 3, __nextHasNoMarginBottom: true,
							onChange: function (v) { update(i, 'text', v); }
						}),
						h('div', { className: 'fvbb-te-actions' },
							h(comp.Button, { size: 'small', variant: 'tertiary', disabled: i === 0, onClick: function () { set(move(items, i, -1)); } }, '↑ nach oben'),
							h(comp.Button, { size: 'small', variant: 'tertiary', disabled: i === items.length - 1, onClick: function () { set(move(items, i, 1)); } }, '↓ nach unten'),
							h(comp.Button, {
								size: 'small', variant: 'tertiary', isDestructive: true,
								onClick: function () { if (window.confirm('Diesen Eintrag löschen?')) { set(items.filter(function (_, k) { return k !== i; })); } }
							}, 'Löschen')
						)
					);
				})
			);
		},
		save: function () { return null; }
	});
})(window.wp.blocks, window.wp.element, window.wp.blockEditor, window.wp.components);
