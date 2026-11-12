/* Block "Symbol" */
(function (blocks, el, be, comp, SSR) {
	var h = el.createElement;
	var cfg = window.fvbbSymbols || { options: [] };
	blocks.registerBlockType('fvbb/symbol', {
		edit: function (props) {
			var a = props.attributes;
			return h('div', be.useBlockProps(),
				h(be.InspectorControls, null,
					h(comp.PanelBody, { title: 'Symbol', initialOpen: true },
						h(comp.SelectControl, {
							label: 'Symbol', value: a.icon, options: cfg.options, __nextHasNoMarginBottom: true,
							onChange: function (v) { props.setAttributes({ icon: v }); }
						}),
						h('div', { style: { height: '12px' } }),
						h(comp.SelectControl, {
							label: 'Farbe', value: a.color, __nextHasNoMarginBottom: true,
							options: [{ label: 'Vereinsblau', value: 'blue' }, { label: 'Sonnengelb', value: 'sun' }, { label: 'Wellenblau', value: 'sky' }],
							onChange: function (v) { props.setAttributes({ color: v }); }
						}),
						h('div', { style: { height: '12px' } }),
						h(comp.ToggleControl, {
							label: 'Linksbündig', checked: !!a.left, __nextHasNoMarginBottom: true,
							onChange: function (v) { props.setAttributes({ left: v }); }
						}),
						h('div', { style: { height: '12px' } }),
						h(comp.TextControl, {
							label: 'Beschreibung für Screenreader (optional)', value: a.label, __nextHasNoMarginBottom: true,
							onChange: function (v) { props.setAttributes({ label: v }); }
						})
					)
				),
				h(SSR, { block: 'fvbb/symbol', attributes: a })
			);
		},
		save: function () { return null; }
	});
})(window.wp.blocks, window.wp.element, window.wp.blockEditor, window.wp.components, window.wp.serverSideRender);
