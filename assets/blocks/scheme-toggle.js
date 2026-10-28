/* TokenForge: Light/Dark Toggle block (editor side; rendered on the server). */
( function ( wp ) {
	'use strict';
	if ( ! wp || ! wp.blocks || ! wp.element || ! wp.blockEditor ) { return; }
	var el = wp.element.createElement;
	var __ = wp.i18n && wp.i18n.__ ? wp.i18n.__ : function ( s ) { return s; };
	var be = wp.blockEditor, co = wp.components || {};
	var cfg = window.TokenForgeBlocks || {};

	wp.blocks.registerBlockType( 'tokenforge/scheme-toggle', {
		apiVersion: 3,
		title: __( 'Light/Dark Toggle', 'tokenforge' ),
		description: __( 'Lets visitors switch between light and dark (remembered per visitor).', 'tokenforge' ),
		category: 'design',
		icon: 'lightbulb',
		attributes: { label: { type: 'string', default: '' } },
		supports: { html: false, color: { text: true, background: true }, typography: { fontSize: true }, spacing: { margin: true } },

		edit: function ( props ) {
			var label = props.attributes.label;
			var blockProps = be.useBlockProps( {
				className: 'scheme-toggle',
				type: 'button',
				'aria-label': label || __( 'Toggle dark mode', 'tokenforge' ),
				dangerouslySetInnerHTML: { __html: cfg.icons || '' }
			} );
			var panel = el( co.PanelBody, { title: __( 'Toggle', 'tokenforge' ) },
				el( co.TextControl, {
					label: __( 'Accessible label', 'tokenforge' ),
					placeholder: __( 'Toggle dark mode', 'tokenforge' ),
					value: label,
					onChange: function ( v ) { props.setAttributes( { label: v } ); }
				} ),
				! cfg.switcher && el( 'p', { style: { color: '#b32d2e' } },
					__( 'The visitor switcher is off, so this renders nothing on the site. Enable it in TokenForge → Colors (Dark mode: Auto + Visitor light/dark switcher).', 'tokenforge' ) )
			);
			return el( wp.element.Fragment, null,
				el( be.InspectorControls, null, panel ),
				el( 'button', blockProps )
			);
		},

		save: function () { return null; } // dynamic block
	} );
} )( window.wp );
