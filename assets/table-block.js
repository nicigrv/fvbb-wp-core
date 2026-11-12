/* Block "Tabelle" – zeigt eine Tabelle aus der Tabellen-Verwaltung */
(function (blocks, el, be, comp, data, SSR) {
	var h = el.createElement, cfg = window.fvbbTableBlock || {};
	blocks.registerBlockType('fvbb/table', {
		edit: function (props) {
			var id = props.attributes.id;
			var tables = data.useSelect(function (select) {
				return select('core').getEntityRecords('postType', 'fvbb_table', { per_page: 100, status: 'publish', orderby: 'title', order: 'asc', _fields: 'id,title' });
			}, []);
			var options = [{ label: tables ? '– Tabelle auswählen –' : 'Lade Tabellen …', value: 0 }].concat((tables || []).map(function (t) {
				return { label: (t.title && (t.title.raw || t.title.rendered)) || ('Tabelle ' + t.id), value: t.id };
			}));
			var blockProps = be.useBlockProps();
			var picker = h(comp.SelectControl, { label: 'Tabelle', value: id, options: options, onChange: function (v) { props.setAttributes({ id: parseInt(v, 10) || 0 }); }, __nextHasNoMarginBottom: true });
			return h('div', blockProps,
				h(be.InspectorControls, null,
					h(comp.PanelBody, { title: 'Tabelle', initialOpen: true },
						picker,
						id ? h('p', { style: { marginTop: 12 } }, h('a', { href: cfg.editUrl + id, target: '_blank', rel: 'noopener' }, 'Diese Tabelle bearbeiten ↗')) : null,
						h('p', null, h('a', { href: cfg.newUrl, target: '_blank', rel: 'noopener' }, 'Neue Tabelle anlegen ↗'))
					)
				),
				id
					? h(el.Fragment, null,
						h(SSR, { block: 'fvbb/table', attributes: { id: id } }),
						h('p', { style: { fontSize: 12, color: '#5B6B83', marginTop: 6 } }, 'Inhalt wird unter Dashboard → Tabellen bearbeitet. ', h('a', { href: cfg.editUrl + id, target: '_blank', rel: 'noopener' }, 'Jetzt bearbeiten ↗')))
					: h(comp.Placeholder, { icon: 'editor-table', label: 'Tabelle', instructions: 'Tabelle aus der Tabellen-Verwaltung auswählen.' },
						h('div', { style: { minWidth: 280 } }, picker),
						h('p', { style: { width: '100%', marginTop: 8 } }, h('a', { href: cfg.newUrl, target: '_blank', rel: 'noopener' }, 'Neue Tabelle anlegen ↗'))
					)
			);
		},
		save: function () { return null; }
	});
})(window.wp.blocks, window.wp.element, window.wp.blockEditor, window.wp.components, window.wp.data, window.wp.serverSideRender);
