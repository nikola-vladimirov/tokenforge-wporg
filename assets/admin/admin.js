/* TokenForge admin dashboard (vanilla JS, no build step).
 *
 * Schema-driven: SECTIONS → panels → fields. Every field is bound to a module
 * setting (data-mod / data-k), shows its default as a placeholder with a
 * one-click reset, and colour fields preview the colour they resolve to. The
 * search box finds individual settings across all sections. The same app
 * renders in wp-admin and in the (narrow) Bricks builder window.
 */
( function () {
	'use strict';

	var cfg = window.TokenForgeData || {};
	var state = {
		tokens: [], settings: {}, framework: {}, scales: {}, components: {}, scheme: {}, extras: {}, defaults: {}, content: {}, width: {}, motion: {}, overlays: { overlays: [], surfaces: [] }, enhancements: {},
		tokensRev: '', paletteRev: '',
		cheat: null, cheatLoading: false, cheatQ: '', cheatKind: '', cheatOpen: {}, attributeUsage: {}, attrUnused: false, attrBusy: false,
		scss: {}, recipes: {}, recipeList: [], recipeQ: '',
		defs: {}, families: [], bricksNative: {}, nativeLabels: {}, bricks: false, groups: [], utilCount: 0,
		activeGroup: 'color', search: '', tab: 'home', q: '', dirty: false, navOpen: false, open: readOpen(), expandAll: false, btnFam: 'primary', btnVar: 'solid', importReport: null,
		modules: {}, theme: {}, transferFile: null, transferName: '', transferParts: {}, resetParts: { settings: true, tokens: true }
	};
	var app = null;

	function api( path, method, body ) {
		return fetch( cfg.restUrl + path, {
			method: method || 'GET',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce },
			body: body ? JSON.stringify( body ) : undefined
		} ).then( function ( r ) { if ( ! r.ok ) { return r.json().catch( function () { return {}; } ).then( function ( j ) { throw new Error( j.message || 'HTTP ' + r.status ); } ); } return r.json(); } );
	}

	function esc( s ) {
		return String( s == null ? '' : s ).replace( /[&<>"']/g, function ( c ) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ c ];
		} );
	}
	function strip( s ) { return String( s || '' ).replace( /<[^>]+>/g, '' ); }
	// #abc / #aabbcc / #aabbccdd → #aabbcc (lower case), anything else → ''.
	function normHex( v ) {
		v = String( v || '' ).trim().toLowerCase();
		if ( /^#[0-9a-f]{3}$/.test( v ) ) { return '#' + v[1] + v[1] + v[2] + v[2] + v[3] + v[3]; }
		return /^#[0-9a-f]{6}([0-9a-f]{2})?$/.test( v ) ? v.slice( 0, 7 ) : '';
	}
	function isHex( v ) { return /^#([0-9a-f]{3}|[0-9a-f]{6}|[0-9a-f]{8})$/i.test( ( v || '' ).trim() ); }

	function setStatus( msg, cls ) {
		var el = app && app.querySelector( '.tf-status' );
		if ( el ) { el.textContent = msg || ''; el.className = 'tf-status' + ( cls ? ' ' + cls : '' ); }
	}
	// Panels start closed; the ones you open stay open (per browser).
	function readOpen() {
		try {
			var o = JSON.parse( window.localStorage.getItem( 'tf-open-panels-v1' ) || '{}' );
			if ( ! o || 'object' !== typeof o ) { return {}; }
			// Panels that moved to the Accessibility hub keep their open state.
			var moved = { 'colors:Contrast': 'check:Palette contrast', 'base:Focus': 'check:Focus', 'base:Automatic accessibility attributes': 'check:Automatic accessibility attributes', 'buttons:External-link indicator': 'check:External-link indicator' };
			Object.keys( moved ).forEach( function ( k ) { if ( o[ k ] && ! o[ moved[ k ] ] ) { o[ moved[ k ] ] = true; } } );
			return o;
		} catch ( e ) { return {}; }
	}
	function saveOpen() {
		try { window.localStorage.setItem( 'tf-open-panels-v1', JSON.stringify( state.open ) ); } catch ( e ) {}
	}
	function markDirty() {
		state.dirty = true;
		var d = app && app.querySelector( '.tf-dirty' );
		if ( d ) { d.hidden = false; }
		var dc = app && app.querySelector( '.tf-discard' );
		if ( dc ) { dc.hidden = false; }
		setStatus( 'Unsaved changes' );
	}

	// ---- Colour previews: resolve var(--x) through the stored tokens ----
	var tokenIndex = null;
	function tokenMap() {
		if ( ! tokenIndex ) {
			tokenIndex = {}; resultMemo = {};
			( state.catalog || [] ).forEach( function ( c ) { tokenIndex[ c.name ] = c.value; } );
			state.tokens.forEach( function ( t ) { if ( t.enabled ) { tokenIndex[ t.name ] = t.value; } } );
		}
		return tokenIndex;
	}
	function splitTop( s ) {
		var out = [], depth = 0, cur = '';
		for ( var i = 0; i < s.length; i++ ) {
			var ch = s[ i ];
			if ( ch === '(' ) { depth++; } else if ( ch === ')' ) { depth--; }
			if ( ch === ',' && depth === 0 ) { out.push( cur.trim() ); cur = ''; } else { cur += ch; }
		}
		out.push( cur.trim() );
		return out;
	}
	function resolveColor( v, depth ) {
		v = String( v == null ? '' : v ).trim(); depth = depth || 0;
		if ( ! v || depth > 8 ) { return ''; }
		var ld = /^light-dark\(([\s\S]*)\)$/i.exec( v );
		if ( ld ) { return resolveColor( splitTop( ld[1] )[0], depth + 1 ); }
		var m = /^var\(\s*--([a-z0-9_-]+)\s*(?:,([\s\S]*))?\)$/i.exec( v );
		if ( m ) {
			var tv = tokenMap()[ m[1] ];
			if ( tv ) { return resolveColor( tv, depth + 1 ); }
			return m[2] ? resolveColor( m[2], depth + 1 ) : '';
		}
		if ( /^--[a-z0-9_-]+$/i.test( v ) ) { return resolveColor( 'var(' + v + ')', depth + 1 ); }
		if ( /^(#[0-9a-f]{3,8}|(rgb|rgba|hsl|hsla|oklch|oklab|lab|lch|color)\(.*\))$/i.test( v ) || /^(transparent|white|black|currentcolor|[a-z]+)$/i.test( v ) ) { return v; }
		return '';
	}
	// Every var(--x) inside a value (a gradient, a shadow …) replaced by its token value, for previews.
	function resolveVars( v, depth ) {
		depth = depth || 0;
		v = String( v == null ? '' : v );
		if ( depth > 6 || v.indexOf( 'var(' ) < 0 ) { return v; }
		return resolveVars( v.replace( /var\(\s*--([a-z0-9_-]+)\s*(?:,([^()]*))?\)/gi, function ( m, n, fb ) {
			var tv = tokenMap()[ n ];
			if ( tv ) { var ld = /^light-dark\(([\s\S]*)\)$/i.exec( String( tv ).trim() ); return ld ? splitTop( ld[1] )[0] : tv; }
			return fb ? fb.trim() : 'transparent';
		} ), depth + 1 );
	}
	function swatch( v ) {
		var c = resolveColor( v );
		return '<span class="tf-swatch' + ( c ? '' : ' is-empty' ) + '"' + ( c ? ' style="background:' + esc( c ) + '"' : '' ) + '></span>';
	}

	// ---- Translations ----------------------------------------------------
	// Text goes through __( text, 'tokenforge' ) (WordPress's wp.i18n when loaded).
	// Panel titles also serve as keys, so the English title is kept for those.
	var EN = {};
	function __( s, domain ) {
		var i = window.wp && window.wp.i18n, t = i && i.__ ? i.__( s, domain ) : s;
		if ( t !== s ) { EN[ t ] = s; }
		return t;
	}
	function _n( one, many, n ) {
		var i = window.wp && window.wp.i18n;
		return ( i && i._n ? i._n( one, many, n, 'tokenforge' ) : 1 === n ? one : many ).replace( '%s', String( n ) );
	}
	function en( t ) { return Object.prototype.hasOwnProperty.call( EN, t ) ? EN[ t ] : t; }

	// ---- Schema ----------------------------------------------------------
	function F( t, k, l, x ) { var f = { t: t, k: k, l: l }; if ( x ) { for ( var p in x ) { if ( Object.prototype.hasOwnProperty.call( x, p ) ) { f[ p ] = x[ p ]; } } } return f; }
	function T( k, l, x ) { return F( 't', k, l, x ); }       // text / CSS value
	function C( k, l, x ) { return F( 'c', k, l, x ); }       // colour
	function N( k, l, x ) { return F( 'n', k, l, x ); }       // number
	function B( k, l, x ) { return F( 'b', k, l, x ); }       // switch
	function A( k, l, x ) { return F( 'a', k, l, x ); }       // textarea
	function S( k, l, o, x ) { var f = F( 's', k, l, x ); f.o = o; return f; } // select
	function K( k, l, x ) { return F( 'k', k, l, x ); }       // keyboard shortcut recorder
	var WOO_FAMS = [ 'primary', 'secondary', 'tertiary', 'accent', 'base', 'neutral', 'action', 'success', 'danger', 'warning', 'info' ].map( function ( f ) { return [ f, f.charAt( 0 ).toUpperCase() + f.slice( 1 ) ]; } );
	var WOO_VARIANTS = [ [ 'solid', __( 'Solid', 'tokenforge' ) ], [ 'outline', __( 'Outline', 'tokenforge' ) ], [ 'dark', __( 'Dark', 'tokenforge' ) ], [ 'light', __( 'Light', 'tokenforge' ) ], [ 'outline-dark', __( 'Outline dark', 'tokenforge' ) ], [ 'outline-light', __( 'Outline light', 'tokenforge' ) ] ];
	function P( title, mod, fields, x ) { var p = { title: title, key: en( title ), mod: mod, fields: fields || null }; if ( x ) { for ( var k in x ) { if ( Object.prototype.hasOwnProperty.call( x, k ) ) { p[ k ] = x[ k ]; } } } return p; }

	var SIZES = [ 'xs', 's', 'm', 'l', 'xl', 'xxl' ];
	var CTX = [ 'ultra-light', 'light', 'dark', 'ultra-dark' ];

	var ALL_SECTIONS = [
		{ id: 'home', title: __( 'Home', 'tokenforge' ), render: 'home' },
		{ id: 'cheatsheet', title: __( 'Cheatsheet', 'tokenforge' ), render: 'cheatsheet' },
		{ id: 'check', title: __( 'Accessibility', 'tokenforge' ) }, // the hub: its panels are gathered in NAV_GROUPS; "This page" (assets/builder/check-panel.js) shows in the builder window only
		{ id: 'wizard', title: __( 'Setup wizard', 'tokenforge' ), render: 'wizard' },
		{ id: 'colors', title: __( 'Colors', 'tokenforge' ), panels: [
			P( __( 'Color families', 'tokenforge' ), null, null, { custom: 'palette', d: __( 'Each family is made from its base colour: a hover step and six shades (ultra-light … ultra-dark) in OKLCH, which swap in dark mode, plus <code>-ref</code>, channel and <code>-trans-*</code> variables. Change a colour and Save to regenerate that family; switch a family off to drop its variables.', 'tokenforge' ) } ),
			P( __( 'Shade generation', 'tokenforge' ), 'palette', [ B( 'unify_brand', __( 'Same lightness for brand colours', 'tokenforge' ) ), B( 'unify_status', __( 'Same lightness for status colours', 'tokenforge' ) ), N( 'lightness', __( 'Base lightness (0.3 to 0.9)', 'tokenforge' ), { step: 0.01 } ), B( 'trans', __( 'Transparent steps (<code>-trans-10</code> … <code>-90</code>)', 'tokenforge' ) ), B( 'alt_tokens', __( 'Dark mode values as their own variables (<code>--primary-alt</code> …)', 'tokenforge' ) ) ], { d: __( 'How every family is generated. Changing these regenerates the families they affect on Save.', 'tokenforge' ) } ),
			P( __( 'Palette contrast', 'tokenforge' ), null, null, { custom: 'contrast', d: __( 'Text, links, focus rings and buttons against their backgrounds, in light and dark mode. The WCAG 2 ratio decides: at least 4.5:1 for text and links, 3:1 for focus rings (level AA). APCA (Lc) is shown beside it for information only; it is a draft for WCAG 3. A fix uses the nearest colour token that passes, or press Another colour to pick a token or any colour yourself and see its ratio first. A fix changes only the setting of that pair, never a token. Save applies it, and History can undo it. Only the colours TokenForge sets are measured, not colours a theme or builder style sets on its own.', 'tokenforge' ) } )
		] },
		{ id: 'scheme', title: __( 'Color Scheme', 'tokenforge' ), panels: [
			P( __( 'Dark mode', 'tokenforge' ), 'scheme', [
				S( 'dark_mode', __( 'Dark values', 'tokenforge' ), [ [ 'off', __( 'Off (light only)', 'tokenforge' ) ], [ 'auto', __( 'On (light-dark())', 'tokenforge' ) ] ] ),
				S( 'default_scheme', __( 'Site default', 'tokenforge' ), [ [ 'auto', __( 'Follow the visitor’s OS', 'tokenforge' ) ], [ 'light', __( 'Light', 'tokenforge' ) ], [ 'dark', __( 'Dark', 'tokenforge' ) ] ] ),
				B( 'switcher', __( 'Visitor light/dark switcher', 'tokenforge' ), { h: __( 'Shortcode <code>[tokenforge_scheme_toggle]</code>, the Light/Dark Toggle element or block, or class <code>scheme-toggle</code> on any button.', 'tokenforge' ) } ),
				A( 'force_dark', __( 'Always dark (selectors)', 'tokenforge' ), { rows: 2 } ),
				A( 'force_light', __( 'Always light (selectors)', 'tokenforge' ), { rows: 2 } )
			], { d: __( 'Native <code>color-scheme</code> + <code>light-dark()</code>. <code>.scheme--light</code> / <code>.scheme--dark</code> force a scheme on any subtree.', 'tokenforge' ) } ),
			P( __( 'Color assignments', 'tokenforge' ), 'scheme', [
				C( 'bg_ultra_light', __( 'bg-ultra-light', 'tokenforge' ) ), C( 'bg_light', __( 'bg-light', 'tokenforge' ) ), C( 'bg_dark', __( 'bg-dark', 'tokenforge' ) ), C( 'bg_ultra_dark', __( 'bg-ultra-dark', 'tokenforge' ) ),
				C( 'text_dark', __( 'text-dark', 'tokenforge' ) ), C( 'text_dark_muted', __( 'text-dark-muted', 'tokenforge' ) ), C( 'text_light', __( 'text-light', 'tokenforge' ) ), C( 'text_light_muted', __( 'text-light-muted', 'tokenforge' ) ),
				B( 'assignments', __( 'Background and text classes (.bg--*, .text--*)', 'tokenforge' ) )
			], { d: __( 'These colours are always there: buttons, cards, forms and the shop use them. <code>.bg--*</code> sets a background and a readable text colour; <code>.text--*</code> sets text colour.', 'tokenforge' ) } ),
			P( __( 'Background relationships', 'tokenforge' ), 'scheme', null, { custom: 'relationships', d: __( 'What each <code>.bg--*</code> sets inside it. Button = a colour family (e.g. <code>primary</code>) for <code>btn--*</code> buttons inside; icon = light/dark icon scheme. Add <code>.unrelate</code> to a button to opt out.', 'tokenforge' ) } )
		] },
		{ id: 'typography', title: __( 'Typography', 'tokenforge' ), panels: [
			P( __( 'Viewport', 'tokenforge' ), 'scales', [ N( 'min_vw', __( 'Min viewport (px)', 'tokenforge' ) ), N( 'max_vw', __( 'Max viewport (px)', 'tokenforge' ) ), B( 'enabled', __( 'Generate fluid scales', 'tokenforge' ) ) ], { d: __( 'Every fluid value scales linearly between these widths.', 'tokenforge' ) } ),
			P( __( 'Text scale', 'tokenforge' ), 'scales', [ N( 'text_base_min', __( 'Base @ min (px)', 'tokenforge' ) ), N( 'text_base_max', __( 'Base @ max (px)', 'tokenforge' ) ), N( 'text_ratio_min', __( 'Ratio @ min', 'tokenforge' ), { step: 0.001 } ), N( 'text_ratio_max', __( 'Ratio @ max', 'tokenforge' ), { step: 0.001 } ) ], { d: __( '<code>--text-xs … --text-xxl</code> (m = base).', 'tokenforge' ) } ),
			P( __( 'Heading scale', 'tokenforge' ), 'scales', [ N( 'heading_base_min', __( 'Base @ min (px)', 'tokenforge' ) ), N( 'heading_base_max', __( 'Base @ max (px)', 'tokenforge' ) ), N( 'heading_ratio_min', __( 'Ratio @ min', 'tokenforge' ), { step: 0.001 } ), N( 'heading_ratio_max', __( 'Ratio @ max', 'tokenforge' ), { step: 0.001 } ) ], { d: __( '<code>--h1 … --h6</code> (h4 = base).', 'tokenforge' ) } ),
			P( __( 'Size overrides & transitions', 'tokenforge' ), 'scales', [ A( 'overrides', __( 'Overrides (px, one per line)', 'tokenforge' ), { h: __( 'e.g. <code>h5-min=13</code>, <code>text-s-max=15</code>, <code>space-xs-min=10</code>', 'tokenforge' ), rows: 3 } ), B( 'transition_vars', __( 'Transition variables', 'tokenforge' ), { h: __( '<code>--text-l-to-m</code>, <code>--h1-to-h3</code>, <code>--space-xl-to-m</code>…: the larger step on desktop, the smaller on mobile.', 'tokenforge' ) } ) ] ),
			P( __( 'Self-hosted fonts', 'tokenforge' ), 'fonts', [ B( 'bricks', __( 'List the families in the builder font picker', 'tokenforge' ), { needs: 'tokens_native' } ), B( 'blocks', __( 'List them in the block editor font picker', 'tokenforge' ) ) ], { on: 'enabled', custom: 'fontList', d: __( 'Your own font files as <code>@font-face</code> in the stylesheet: no Google request, GDPR friendly. Then use the family name anywhere, e.g. Body text font family <code>"Inter", sans-serif</code>.', 'tokenforge' ) } ),
			P( __( 'Body text', 'tokenforge' ), 'components', [ T( 'text_font_family', __( 'Font family', 'tokenforge' ) ), T( 'text_font_weight', __( 'Weight', 'tokenforge' ) ), T( 'text_font_style', __( 'Style', 'tokenforge' ) ), C( 'text_color', __( 'Colour', 'tokenforge' ) ), C( 'body_bg', __( 'Page background', 'tokenforge' ) ), T( 'text_line_height', __( 'Line height', 'tokenforge' ) ), T( 'text_letter_spacing', __( 'Letter spacing', 'tokenforge' ) ), T( 'text_transform', __( 'Text transform', 'tokenforge' ) ), T( 'text_wrap', __( 'Text wrap', 'tokenforge' ) ) ], { on: 'base_root', d: __( 'Body and heading defaults (zero-specificity, so classes and builder settings win).', 'tokenforge' ) } ),
			P( __( 'Headings', 'tokenforge' ), 'components', [ T( 'heading_font_family', __( 'Font family', 'tokenforge' ) ), T( 'heading_font_weight', __( 'Weight', 'tokenforge' ) ), T( 'heading_font_style', __( 'Style', 'tokenforge' ) ), C( 'heading_color', __( 'Colour', 'tokenforge' ) ), T( 'heading_line_height', __( 'Line height', 'tokenforge' ) ), T( 'heading_letter_spacing', __( 'Letter spacing', 'tokenforge' ) ), T( 'heading_text_transform', __( 'Text transform', 'tokenforge' ) ), T( 'heading_text_wrap', __( 'Text wrap', 'tokenforge' ) ) ] ),
			P( __( 'Per-heading overrides', 'tokenforge' ), 'components', null, { custom: 'levels', d: __( 'Only fill what differs for that level. Applies to <code>h1…h6</code> and the <code>.h1….h6</code> classes.', 'tokenforge' ) } ),
			P( __( 'Text size classes', 'tokenforge' ), 'components', null, { on: 'text_size_classes', custom: 'sizes', d: __( '<code>.text--xs … .text--xxl</code>: body text at a scale size. Max width applies to text elements only.', 'tokenforge' ) } )
		] },
		{ id: 'layout', title: __( 'Layout', 'tokenforge' ), panels: [
			P( __( 'Content width', 'tokenforge' ), 'width', null, { custom: 'width' } ),
			P( __( 'Sections & gutters', 'tokenforge' ), 'components', [ N( 'gutter_min', __( 'Gutter @ min (px)', 'tokenforge' ) ), N( 'gutter_max', __( 'Gutter @ max (px)', 'tokenforge' ) ), B( 'section_padding', __( 'Default section padding', 'tokenforge' ) ), T( 'section_padding_block', __( 'Section padding', 'tokenforge' ) ), B( 'container_width', __( 'Containers take the content width', 'tokenforge' ) ) ], { on: 'base_layout', d: __( 'Sections get a fluid side gutter. <code>.section--m</code>, <code>.section--l-to-m</code>, <code>.section--none</code>, <code>.header--m</code> set their spacing.', 'tokenforge' ) } ),
			P( __( 'Boxed layout', 'tokenforge' ), 'components', [ S( 'layout_type', __( 'Layout', 'tokenforge' ), [ [ 'full', __( 'Full width', 'tokenforge' ) ], [ 'boxed', __( 'Boxed page', 'tokenforge' ) ] ] ), N( 'boxed_width', __( 'Boxed width (px)', 'tokenforge' ) ), T( 'body_shadow', __( 'Page shadow', 'tokenforge' ), { w: 1 } ), C( 'device_bg', __( 'Background around the page', 'tokenforge' ) ), T( 'body_border_width', __( 'Border width', 'tokenforge' ) ), T( 'body_border_style', __( 'Border style', 'tokenforge' ) ), C( 'body_border_color', __( 'Border colour', 'tokenforge' ) ), T( 'body_border_radius', __( 'Radius', 'tokenforge' ) ), T( 'body_margin_top', __( 'Top margin', 'tokenforge' ) ) ], { d: __( 'Boxed: the whole page is a centred box on a background.', 'tokenforge' ) } ),
			P( __( 'Content grid', 'tokenforge' ), 'components', [ B( 'content_grid_sections', __( 'Every top-level section is a content grid', 'tokenforge' ) ), T( 'feature_width', __( 'Feature column', 'tokenforge' ) ), T( 'feature_max_width', __( 'Feature-max column', 'tokenforge' ) ), T( 'content_grid_exclusions', __( 'Sections to leave alone', 'tokenforge' ), { w: 1 } ) ], { on: 'content_grid', d: __( 'Named columns: children sit in <code>content</code>; <code>.content--feature</code>, <code>.content--feature-max</code>, <code>.content--full</code>, <code>.content--full-safe</code> break out. Opt out with <code>.content-grid--off</code>.', 'tokenforge' ) } ),
			P( __( 'Grids', 'tokenforge' ), 'scales', [ N( 'grid_columns', __( 'Columns', 'tokenforge' ) ), N( 'grid_gap', __( 'Grid gap (px)', 'tokenforge' ) ), N( 'content_gutter', __( '--content-gutter (px)', 'tokenforge' ) ), B( 'auto_grid', __( 'Auto grids (<code>--grid-auto-2…12</code>)', 'tokenforge' ) ), N( 'auto_grid_aggressiveness', __( 'Aggressiveness', 'tokenforge' ), { step: 0.05, h: __( 'A column may shrink to this fraction of its full-width size before wrapping.', 'tokenforge' ) } ), S( 'auto_grid_flow', __( 'Auto flow', 'tokenforge' ), [ 'auto-fit', __( 'auto-fill', 'tokenforge' ) ] ) ], { d: __( 'Variables <code>--grid-1…12</code>, <code>--grid-2-1</code>… and classes <code>.grid-*</code>.', 'tokenforge' ) } )
		] },
		{ id: 'spacing', title: __( 'Spacing', 'tokenforge' ), panels: [
			P( __( 'Space scale', 'tokenforge' ), 'scales', [ N( 'space_base_min', __( 'Base @ min (px)', 'tokenforge' ) ), N( 'space_base_max', __( 'Base @ max (px)', 'tokenforge' ) ), N( 'space_ratio_min', __( 'Ratio @ min', 'tokenforge' ), { step: 0.001 } ), N( 'space_ratio', __( 'Ratio @ max', 'tokenforge' ), { step: 0.001 } ) ], { d: __( '<code>--space-xs … --space-xxl</code>.', 'tokenforge' ) } ),
			P( __( 'Section spacing', 'tokenforge' ), 'scales', [ N( 'section_mult_min', __( 'Multiplier @ min', 'tokenforge' ), { step: 0.1 } ), N( 'section_mult_max', __( 'Multiplier @ max', 'tokenforge' ), { step: 0.1 } ) ], { d: __( '<code>--section-space-*</code> = space × multiplier.', 'tokenforge' ) } ),
			P( __( 'Automatic gaps', 'tokenforge' ), 'components', [ B( 'auto_container_gap', __( 'Between a section’s children', 'tokenforge' ) ), T( 'container_gap', __( 'Container gap', 'tokenforge' ) ), B( 'auto_content_gap', __( 'Inside containers / blocks / divs', 'tokenforge' ) ), T( 'content_gap', __( 'Content gap', 'tokenforge' ) ), B( 'auto_grid_gap', __( 'On <code>grid--*</code> elements', 'tokenforge' ) ), T( 'grid_gap_value', __( 'Grid gap override', 'tokenforge' ) ) ], { d: __( 'Utilities: <code>.container-gap</code>, <code>.content-gap</code>, <code>.grid-gap</code>, <code>.gap--none</code>.', 'tokenforge' ) } ),
			P( __( 'Flow spacing', 'tokenforge' ), 'content', [
				B( 'flow_auto', __( 'Automatically in Rich Text + post content', 'tokenforge' ) ), T( 'flow_selectors', __( 'Also in (selectors)', 'tokenforge' ) ), B( 'smart_spacing', __( 'Remove default margins (p, headings, lists)', 'tokenforge' ) ),
				T( 'paragraph_spacing', __( 'Paragraphs', 'tokenforge' ) ), T( 'heading_spacing', __( 'Above headings', 'tokenforge' ) ), T( 'flow_spacing', __( 'Everything else', 'tokenforge' ) ),
				T( 'list_spacing', __( 'Lists', 'tokenforge' ) ), T( 'list_indent_spacing', __( 'List indent', 'tokenforge' ) ), T( 'list_item_spacing', __( 'List items', 'tokenforge' ) ),
				T( 'nested_list_spacing', __( 'Nested lists', 'tokenforge' ) ), T( 'nested_list_indent_spacing', __( 'Nested indent', 'tokenforge' ) ), T( 'nested_list_item_spacing', __( 'Nested items', 'tokenforge' ) ),
				T( 'figure_spacing', __( 'Figures', 'tokenforge' ) ), T( 'figcaption_spacing', __( 'Captions', 'tokenforge' ) ), T( 'blockquote_spacing', __( 'Quotes', 'tokenforge' ) ),
				T( 'h2_spacing', __( 'Above h2', 'tokenforge' ) ), T( 'h3_spacing', __( 'Above h3', 'tokenforge' ) ), T( 'h4_spacing', __( 'Above h4', 'tokenforge' ) ), T( 'h5_spacing', __( 'Above h5', 'tokenforge' ) ), T( 'h6_spacing', __( 'Above h6', 'tokenforge' ) )
			], { on: 'flow', d: __( 'Vertical rhythm between siblings inside <code>.smart-spacing</code> (and automatically where chosen). <code>.smart-spacing--off</code> opts out.', 'tokenforge' ) } )
		] },
		{ id: 'buttons', title: __( 'Buttons & Links', 'tokenforge' ), panels: [
			P( __( 'Buttons', 'tokenforge' ), 'components', [ T( 'btn_families', __( 'Families (comma separated)', 'tokenforge' ), { w: 1 } ), B( 'btn_shades', __( 'Dark / light variants', 'tokenforge' ) ), B( 'btn_outline', __( 'Outline variants', 'tokenforge' ) ), T( 'btn_family', __( 'Family of the plain .btn', 'tokenforge' ) ), B( 'bricks_button_styles', __( 'Builder button styles use these families', 'tokenforge' ), { needs: 'theme_style' } ), B( 'control_bricks_buttons', __( 'Restyle every button (builder, block editor, plain HTML)', 'tokenforge' ) ) ], { on: 'base_buttons', d: __( 'Any <code>btn--*</code> class makes a button: <code>btn--primary</code>, <code>btn--primary-dark/-light</code>, <code>btn--outline</code>, sizes <code>btn--xs…xxl</code>, <code>btn--none</code>.', 'tokenforge' ) } ),
			P( __( 'Button style', 'tokenforge' ), 'components', [ T( 'btn_padding_block', __( 'Padding block', 'tokenforge' ) ), T( 'btn_padding_inline', __( 'Padding inline', 'tokenforge' ) ), T( 'btn_radius', __( 'Radius', 'tokenforge' ) ), T( 'btn_font_size', __( 'Font size', 'tokenforge' ) ), T( 'btn_font_weight', __( 'Weight', 'tokenforge' ) ), T( 'btn_font_family', __( 'Font family', 'tokenforge' ) ), T( 'btn_font_style', __( 'Style', 'tokenforge' ) ), T( 'btn_line_height', __( 'Line height', 'tokenforge' ) ), T( 'btn_letter_spacing', __( 'Letter spacing', 'tokenforge' ) ), T( 'btn_text_transform', __( 'Text transform', 'tokenforge' ) ), T( 'btn_border_width', __( 'Border width', 'tokenforge' ) ), T( 'btn_border_style', __( 'Border style', 'tokenforge' ) ), T( 'btn_min_width', __( 'Min width', 'tokenforge' ) ), T( 'btn_width', __( 'Width', 'tokenforge' ) ), T( 'btn_text_align', __( 'Text align', 'tokenforge' ) ), T( 'btn_justify', __( 'Justify', 'tokenforge' ) ), T( 'btn_gap', __( 'Icon gap', 'tokenforge' ) ), T( 'btn_text_decoration', __( 'Decoration', 'tokenforge' ) ), T( 'btn_text_decoration_hover', __( 'Decoration (hover)', 'tokenforge' ) ), T( 'btn_transition', __( 'Transition', 'tokenforge' ) ), B( 'btn_mobile_full', __( 'Full width on small screens', 'tokenforge' ) ), N( 'btn_mobile_bp', __( 'Small-screen breakpoint (px)', 'tokenforge' ) ) ] ),
			P( __( 'Button colours per family', 'tokenforge' ), 'components', null, { custom: 'btnOverrides', d: __( 'Change any colour of a family’s buttons; empty = the default shown as placeholder.', 'tokenforge' ) } ),
			P( __( 'Links', 'tokenforge' ), 'components', [ C( 'link_color', __( 'Colour', 'tokenforge' ) ), C( 'link_color_hover', __( 'Hover colour', 'tokenforge' ) ), T( 'link_decoration', __( 'Decoration', 'tokenforge' ) ), T( 'link_decoration_hover', __( 'Decoration (hover)', 'tokenforge' ) ), C( 'link_decoration_color', __( 'Decoration colour', 'tokenforge' ) ), C( 'link_decoration_color_hover', __( 'Decoration colour (hover)', 'tokenforge' ) ), T( 'link_decoration_thickness', __( 'Thickness', 'tokenforge' ) ), T( 'link_underline_offset', __( 'Underline offset', 'tokenforge' ) ), T( 'link_weight', __( 'Weight', 'tokenforge' ) ), T( 'link_transition', __( 'Transition', 'tokenforge' ) ) ], { on: 'base_links', d: __( 'Links in content (buttons excluded).', 'tokenforge' ) } ),
			P( __( 'External-link indicator', 'tokenforge' ), 'components', [ T( 'ext_indicator', __( 'Indicator', 'tokenforge' ), { h: __( 'CSS content, e.g. <code>\\2197\\FE0E</code>', 'tokenforge' ) } ), T( 'ext_label', __( 'Screen-reader label', 'tokenforge' ) ), S( 'ext_position', __( 'Position', 'tokenforge' ), [ [ 'end', __( 'After the text', 'tokenforge' ) ], [ 'start', __( 'Before the text', 'tokenforge' ) ] ] ), S( 'ext_align', __( 'Alignment', 'tokenforge' ), [ 'start', 'center', 'end', 'baseline', 'auto' ] ), T( 'ext_gap', __( 'Gap', 'tokenforge' ) ), T( 'ext_size', __( 'Size', 'tokenforge' ) ), T( 'ext_weight', __( 'Weight', 'tokenforge' ) ), T( 'ext_offset', __( 'Offset (x y)', 'tokenforge' ) ), C( 'ext_color', __( 'Colour', 'tokenforge' ) ), C( 'ext_color_hover', __( 'Hover colour', 'tokenforge' ) ), T( 'ext_not', __( 'Skip links matching', 'tokenforge' ), { w: 1 } ) ], { on: 'ext_links', d: __( 'A glyph on links that open in a new tab, read to screen readers as the label.', 'tokenforge' ) } )
		] },
		{ id: 'overlays', title: __( 'Surfaces & Overlays', 'tokenforge' ), panels: [
			P( __( 'Basic overlay', 'tokenforge' ), 'overlays', [ C( 'basic_color', __( 'Default tint', 'tokenforge' ) ), S( 'basic_pseudo', __( 'Pseudo-element', 'tokenforge' ), [ [ 'before', __( '::before', 'tokenforge' ) ], [ 'after', __( '::after', 'tokenforge' ) ] ] ) ], { on: 'basic', d: __( '<code>.overlay</code> tints the element’s background under its content; change it per element with <code>--overlay-color</code>.', 'tokenforge' ) } ),
			P( __( 'Custom overlays', 'tokenforge' ), 'overlays', null, { custom: 'overlayPresets', d: __( '<code>.overlay-1</code> … (and <code>.overlay-{name}</code>): colour, gradient or image; blur, blend, radius, inset, animation and an extra top layer. Per element, <code>--overlay-opacity</code>, <code>--overlay-bg-color</code> … override the preset.', 'tokenforge' ) } ),
			P( __( 'Surfaces', 'tokenforge' ), 'overlays', null, { custom: 'surfacePresets', d: __( 'Background textures <code>.surface-1</code> … from an image URL, pasted <code>&lt;svg&gt;</code> markup or a CSS gradient; the colour relationship sets light/dark text like <code>.bg--*</code>.', 'tokenforge' ) } )
		] },
		{ id: 'borders', title: __( 'Borders & Dividers', 'tokenforge' ), panels: [
			P( __( 'Radius', 'tokenforge' ), 'extras', [ T( 'radius_xs', __( 'xs', 'tokenforge' ), { h: __( 'empty = derived', 'tokenforge' ) } ), T( 'radius_s', __( 's', 'tokenforge' ) ), T( 'radius_m', __( 'm (= --radius)', 'tokenforge' ) ), T( 'radius_l', __( 'l', 'tokenforge' ) ), T( 'radius_xl', __( 'xl', 'tokenforge' ) ), T( 'radius_xxl', __( 'xxl', 'tokenforge' ), { h: __( 'empty = derived', 'tokenforge' ) } ) ], { on: 'enable_radius', d: __( '<code>--radius-xs…xxl</code>, <code>--radius-circle</code>, <code>--radius-50</code>, <code>--radius-none</code> and <code>.radius-*</code>.', 'tokenforge' ) } ),
			P( __( 'Auto radius', 'tokenforge' ), 'extras', [ T( 'auto_radius_selectors', __( 'Selectors', 'tokenforge' ), { w: 1 } ) ], { on: 'auto_radius', d: __( 'Round these automatically with <code>--radius</code>.', 'tokenforge' ) } ),
			P( __( 'Borders', 'tokenforge' ), 'extras', [ T( 'border_size', __( 'Size', 'tokenforge' ) ), T( 'border_style', __( 'Style', 'tokenforge' ) ), C( 'border_color', __( 'Colour (on light)', 'tokenforge' ) ), C( 'border_color_light', __( 'Colour (on dark)', 'tokenforge' ) ) ], { on: 'enable_border', d: __( '<code>--border</code>, <code>--border-light</code>, <code>--border-dark</code>; <code>.border</code>, <code>.border--light</code>.', 'tokenforge' ) } ),
			P( __( 'Dividers', 'tokenforge' ), 'extras', [ T( 'divider_size', __( 'Size', 'tokenforge' ) ), T( 'divider_style', __( 'Style', 'tokenforge' ) ), C( 'divider_color_dark', __( 'Colour (on light)', 'tokenforge' ) ), C( 'divider_color_light', __( 'Colour (on dark)', 'tokenforge' ) ), T( 'divider_gap', __( 'Spacing', 'tokenforge' ) ) ], { d: __( '<code>--divider</code>, <code>--divider-light/-dark</code>; <code>.divider</code>.', 'tokenforge' ) } ),
			P( __( 'Inverted radius', 'tokenforge' ), 'extras', [], { on: 'inverted_radius', d: __( 'Concave corners on a tab: <code>data-inverted-radius-1-position="bottom-left"</code> + <code>data-inverted-radius-1-slice="top-left"</code> (and <code>-2-</code> for a second corner). Uses <code>--radius</code> and the parent’s <code>--bg</code>.', 'tokenforge' ) } ),
			P( __( 'Corner ribbons', 'tokenforge' ), 'extras', [ C( 'ribbon_background', __( 'Background', 'tokenforge' ) ), C( 'ribbon_text_color', __( 'Text colour', 'tokenforge' ) ), T( 'ribbon_text_size', __( 'Text size', 'tokenforge' ) ), T( 'ribbon_padding', __( 'Padding', 'tokenforge' ) ), T( 'ribbon_width', __( 'Width', 'tokenforge' ) ), T( 'ribbon_offset', __( 'Offset', 'tokenforge' ) ), T( 'ribbon_shadow', __( 'Shadow', 'tokenforge' ), { w: 1 } ) ], { on: 'ribbons', d: __( 'A band across a corner: <code>.ribbon</code> + <code>.ribbon--top-right</code> / <code>.ribbon--top-left</code> (or <code>data-ribbon-position</code>) on a direct child; the parent clips it. Per element: <code>--ribbon-background-color</code>, <code>--ribbon-text-color</code>, <code>--ribbon-width</code>, <code>--ribbon-offset</code> …', 'tokenforge' ) } )
		] },
		{ id: 'shadows', title: __( 'Shadows', 'tokenforge' ), panels: [
			P( __( 'Box shadows', 'tokenforge' ), 'extras', [ T( 'shadow_color', __( 'Shadow colour (r g b)', 'tokenforge' ) ), T( 'shadow_1', __( 'Shadow 1', 'tokenforge' ) ), T( 'shadow_2', __( 'Shadow 2', 'tokenforge' ) ), T( 'shadow_3', __( 'Shadow 3', 'tokenforge' ) ), T( 'shadow_4', __( 'Shadow 4', 'tokenforge' ) ), T( 'shadow_5', __( 'Shadow 5', 'tokenforge' ) ) ], { on: 'enable_shadow', d: __( '<code>--shadow-1…5</code> (empty = generated from the colour) and <code>.shadow-1…5</code>.', 'tokenforge' ) } ),
			P( __( 'Text shadows', 'tokenforge' ), 'extras', [ T( 'text_shadow_1', __( 'Text shadow 1', 'tokenforge' ) ), T( 'text_shadow_2', __( 'Text shadow 2', 'tokenforge' ) ), T( 'text_shadow_3', __( 'Text shadow 3', 'tokenforge' ) ) ], { on: 'enable_text_shadow', d: __( '<code>--text-shadow-1…3</code>, <code>.text-shadow--1…3</code>.', 'tokenforge' ) } ),
			P( __( 'Drop shadows', 'tokenforge' ), 'extras', [ T( 'drop_shadow_1', __( 'Drop shadow 1', 'tokenforge' ) ), T( 'drop_shadow_2', __( 'Drop shadow 2', 'tokenforge' ) ), T( 'drop_shadow_3', __( 'Drop shadow 3', 'tokenforge' ) ) ], { on: 'enable_drop_shadow', d: __( 'Filter shadows: <code>--drop-shadow-1…3</code>, <code>.drop-shadow--1…3</code>.', 'tokenforge' ) } )
		] },
		{ id: 'cards', title: __( 'Cards', 'tokenforge' ), panels: [
			P( __( 'Card', 'tokenforge' ), 'components', [ T( 'card_selectors', __( 'Also style as cards (selectors)', 'tokenforge' ), { w: 1 } ), S( 'card_scheme', __( 'Colour scheme inside', 'tokenforge' ), [ [ '', __( 'Inherit', 'tokenforge' ) ], [ 'light', __( 'Light', 'tokenforge' ) ], [ 'dark', __( 'Dark', 'tokenforge' ) ] ] ), C( 'card_background', __( 'Background', 'tokenforge' ) ), C( 'card_text_color', __( 'Text colour', 'tokenforge' ) ), T( 'card_text_size', __( 'Text size', 'tokenforge' ) ), T( 'card_line_height', __( 'Line height', 'tokenforge' ) ), T( 'card_padding', __( 'Padding', 'tokenforge' ) ), T( 'card_gap', __( 'Gap', 'tokenforge' ) ), T( 'card_radius', __( 'Radius', 'tokenforge' ) ), T( 'card_border_width', __( 'Border width', 'tokenforge' ) ), T( 'card_border_style', __( 'Border style', 'tokenforge' ) ), C( 'card_border_color', __( 'Border colour', 'tokenforge' ) ), T( 'card_shadow', __( 'Shadow', 'tokenforge' ) ), B( 'card_concentric', __( 'Concentric radius (inner = radius − padding)', 'tokenforge' ) ), T( 'card_min_radius', __( 'Min inner radius', 'tokenforge' ) ) ], { on: 'base_cards', d: __( '<code>.card</code>: a flex column. <code>.card--light</code> / <code>.card--dark</code> fix the scheme.', 'tokenforge' ) } ),
			P( __( 'Headings & links', 'tokenforge' ), 'components', [ T( 'card_heading_size', __( 'Heading size', 'tokenforge' ) ), C( 'card_heading_color', __( 'Heading colour', 'tokenforge' ) ), C( 'card_link_color', __( 'Link colour', 'tokenforge' ) ), C( 'card_link_color_hover', __( 'Link hover', 'tokenforge' ) ) ] ),
			P( __( 'Media & avatars', 'tokenforge' ), 'components', [ T( 'card_media_aspect', __( 'Media aspect ratio', 'tokenforge' ) ), T( 'card_media_fit', __( 'Media object-fit', 'tokenforge' ) ), T( 'card_media_radius', __( 'Media radius', 'tokenforge' ) ), T( 'card_avatar_size', __( 'Avatar size', 'tokenforge' ) ), T( 'card_avatar_radius', __( 'Avatar radius', 'tokenforge' ) ), T( 'card_avatar_aspect', __( 'Avatar aspect', 'tokenforge' ) ), T( 'card_avatar_border_width', __( 'Avatar border width', 'tokenforge' ) ), T( 'card_avatar_border_style', __( 'Avatar border style', 'tokenforge' ) ), C( 'card_avatar_border_color', __( 'Avatar border colour', 'tokenforge' ) ) ], { d: __( 'Elements whose class contains <code>__media</code> / <code>__avatar</code>.', 'tokenforge' ) } ),
			P( __( 'Icons & buttons', 'tokenforge' ), 'components', [ B( 'card_boxed_icons', __( 'Boxed icons', 'tokenforge' ) ), T( 'card_icon_size', __( 'Icon size', 'tokenforge' ) ), C( 'card_icon_color', __( 'Icon colour', 'tokenforge' ) ), C( 'card_icon_background', __( 'Icon background', 'tokenforge' ) ), T( 'card_icon_padding', __( 'Icon padding', 'tokenforge' ) ), T( 'card_icon_radius', __( 'Icon radius', 'tokenforge' ) ), T( 'card_icon_border_width', __( 'Icon border width', 'tokenforge' ) ), T( 'card_icon_border_style', __( 'Icon border style', 'tokenforge' ) ), C( 'card_icon_border_color', __( 'Icon border colour', 'tokenforge' ) ), T( 'card_button_size', __( 'Button font size', 'tokenforge' ) ), T( 'card_button_family', __( 'Button family', 'tokenforge' ) ) ] )
		] },
		{ id: 'icons', title: __( 'Icons', 'tokenforge' ), panels: [
			P( __( 'Icon sizes', 'tokenforge' ), 'extras', [ T( 'icon_xs', __( 'xs', 'tokenforge' ) ), T( 'icon_s', __( 's', 'tokenforge' ) ), T( 'icon_m', __( 'm', 'tokenforge' ) ), T( 'icon_l', __( 'l', 'tokenforge' ) ), T( 'icon_xl', __( 'xl', 'tokenforge' ) ), T( 'icon_2xl', __( '2xl', 'tokenforge' ) ), T( 'icon_padding', __( 'Padding', 'tokenforge' ) ), T( 'icon_padding_xs', __( 'Padding xs', 'tokenforge' ) ), T( 'icon_padding_s', __( 'Padding s', 'tokenforge' ) ), T( 'icon_padding_m', __( 'Padding m', 'tokenforge' ) ), T( 'icon_padding_l', __( 'Padding l', 'tokenforge' ) ), T( 'icon_padding_xl', __( 'Padding xl', 'tokenforge' ) ), T( 'icon_padding_2xl', __( 'Padding 2xl', 'tokenforge' ) ) ], { on: 'enable_icon', d: __( 'Put <code>data-icon</code> on an svg/&lt;i&gt; (or use <code>.icon</code>); sizes <code>.icon--xs…2xl</code> or <code>data-icon-size</code>.', 'tokenforge' ) } ),
			P( __( 'Icon style', 'tokenforge' ), 'extras', [ S( 'icon_default_style', __( 'Default style', 'tokenforge' ), [ [ 'plain', __( 'Plain', 'tokenforge' ) ], [ 'boxed', __( 'Boxed', 'tokenforge' ) ] ] ), S( 'icon_scheme', __( 'Colour scheme', 'tokenforge' ), [ [ '', __( 'Inherit', 'tokenforge' ) ], [ 'light', __( 'Light', 'tokenforge' ) ], [ 'dark', __( 'Dark', 'tokenforge' ) ] ] ), C( 'icon_color', __( 'Colour', 'tokenforge' ) ), C( 'icon_color_hover', __( 'Hover colour', 'tokenforge' ) ), C( 'icon_background', __( 'Box background', 'tokenforge' ) ), C( 'icon_background_hover', __( 'Box background (hover)', 'tokenforge' ) ), T( 'icon_border_width', __( 'Border width', 'tokenforge' ) ), T( 'icon_border_style', __( 'Border style', 'tokenforge' ) ), C( 'icon_border_color', __( 'Border colour', 'tokenforge' ) ), C( 'icon_border_color_hover', __( 'Border colour (hover)', 'tokenforge' ) ), T( 'icon_radius', __( 'Radius', 'tokenforge' ) ) ], { d: __( '<code>.icon--boxed</code> / <code>.icon--plain</code> (or <code>data-icon-style</code>).', 'tokenforge' ) } ),
			P( __( 'Icon lists', 'tokenforge' ), 'extras', [ T( 'icon_list_gap', __( 'Gap', 'tokenforge' ) ), T( 'icon_list_icon_size', __( 'Icon size', 'tokenforge' ) ), B( 'boxed_list_icons', __( 'Boxed list icons', 'tokenforge' ) ) ], { d: __( '<code>.icon-list</code> or <code>data-icon-list</code>.', 'tokenforge' ) } )
		] },
		{ id: 'additional', title: __( 'Additional Styling', 'tokenforge' ), panels: [
			P( __( 'Root font size', 'tokenforge' ), 'defaults', null, { custom: 'rootFont' } ),
			P( __( 'Reset', 'tokenforge' ), 'defaults', [ B( 'media_block', __( 'Images, SVG and video as blocks', 'tokenforge' ), { h: __( 'Off = inline (the browser default).', 'tokenforge' ) } ) ], { on: 'reset' } ),
			P( __( 'Focus', 'tokenforge' ), 'defaults', [ C( 'focus_color', __( 'Colour', 'tokenforge' ) ), T( 'focus_width', __( 'Width', 'tokenforge' ) ), T( 'focus_offset', __( 'Offset', 'tokenforge' ) ) ], { on: 'focus_styles', d: __( 'Visible keyboard focus (<code>:focus-visible</code>), and a skip link styled as a button.', 'tokenforge' ) } ),
			P( __( 'Reduced motion', 'tokenforge' ), 'defaults', [ B( 'reduce_motion', __( 'Respect reduced motion', 'tokenforge' ) ) ], { d: __( 'Turns off smooth scrolling and animations for visitors who ask their device for reduced motion.', 'tokenforge' ) } ),
			P( __( 'Selection', 'tokenforge' ), 'defaults', [ C( 'selection_bg', __( 'Background', 'tokenforge' ) ), C( 'selection_text', __( 'Text', 'tokenforge' ) ) ] ),
			P( __( 'Scrolling', 'tokenforge' ), 'defaults', [ B( 'smooth_scroll', __( 'Smooth scrolling', 'tokenforge' ) ), T( 'scroll_margin_min', __( 'Anchor offset @ min', 'tokenforge' ) ), T( 'scroll_margin_max', __( 'Anchor offset @ max', 'tokenforge' ) ), B( 'scroll_offset_header', __( 'Add the header height', 'tokenforge' ) ) ], { d: __( 'In-page anchors stop below a sticky header.', 'tokenforge' ) } ),
			P( __( 'Header height', 'tokenforge' ), 'extras', [ T( 'header_height', __( 'Desktop', 'tokenforge' ) ), T( 'header_height_min', __( 'Mobile (fluid between)', 'tokenforge' ) ) ], { d: __( '<code>--header-height</code> feeds sticky and scroll offsets.', 'tokenforge' ) } ),
			P( __( 'Sticky', 'tokenforge' ), 'defaults', [ B( 'sticky_offset_header', __( 'Stick below the header', 'tokenforge' ) ), T( 'sticky_offset_custom', __( 'Custom offset', 'tokenforge' ) ) ], { on: 'sticky', d: __( '<code>.sticky</code>.', 'tokenforge' ) } ),
			P( __( 'Content offset', 'tokenforge' ), 'defaults', [ T( 'content_offset_exclusions', __( 'Pages to skip (body selector)', 'tokenforge' ) ), T( 'content_offset_header_not', __( 'Headers to ignore', 'tokenforge' ) ) ], { on: 'content_offset', d: __( 'The first section starts below a fixed / overlay header.', 'tokenforge' ) } ),
			P( __( 'Blockquotes', 'tokenforge' ), 'content', null, { on: 'blockquotes', custom: 'blockquotes', d: __( 'Site-wide quote styling for <code>&lt;blockquote&gt;</code>, Gutenberg quote/pullquote and <code>.blockquote</code>; each value is a <code>--blockquote-*</code> variable.', 'tokenforge' ) } ),
			P( __( 'Utilities', 'tokenforge' ), 'defaults', [ B( 'object_fit', __( 'Auto object-fit on images', 'tokenforge' ) ), B( 'is_bg', __( 'Background layers (<code>.is-bg</code>)', 'tokenforge' ) ), B( 'sr_only', __( 'Screen-reader utilities (<code>.hidden-accessible</code>)', 'tokenforge' ) ), B( 'columns', __( 'CSS columns + column variables', 'tokenforge' ) ), T( 'col_width_s', __( 'Column width s', 'tokenforge' ) ), T( 'col_width_m', __( 'Column width m', 'tokenforge' ) ), T( 'col_width_l', __( 'Column width l', 'tokenforge' ) ), T( 'col_rule_width_s', __( 'Rule width s', 'tokenforge' ) ), T( 'col_rule_width_m', __( 'Rule width m', 'tokenforge' ) ), T( 'col_rule_width_l', __( 'Rule width l', 'tokenforge' ) ) ] ),
			P( __( 'Automatic accessibility attributes', 'tokenforge' ), 'settings', [ B( 'a11y_icons', __( 'Hide icons in links and buttons that have text', 'tokenforge' ), { na: 'a11y_auto_na' } ), B( 'a11y_alt', __( 'Alt text from the media library on images with no alt', 'tokenforge' ), { na: 'a11y_auto_na' } ), B( 'a11y_nav_label', __( 'Menu name as the label of unlabelled menus', 'tokenforge' ), { na: 'a11y_auto_na' } ), B( 'a11y_current', __( 'Mark the menu link to the current page', 'tokenforge' ), { na: 'a11y_auto_na' } ), B( 'a11y_new_tab', __( 'Announce links that open a new tab', 'tokenforge' ), { na: 'a11y_auto_na' } ), B( 'a11y_guess_field', __( 'Guess: placeholder as the label of unlabelled fields', 'tokenforge' ), { na: 'a11y_auto_na' } ), B( 'a11y_guess_icon_link', __( 'Guess: a label for links with only an icon', 'tokenforge' ), { na: 'a11y_auto_na' } ) ], { d: __( 'All of these start off. Each one adds an attribute on the page only where it is missing: anything you set yourself, even an empty <code>alt=""</code>, is never changed, and nothing is saved in your pages, so switching one off gives back the same page. The two guesses can be wrong; check them. Put <code>data-tf-a11y="off"</code> on an element to leave it and everything inside it alone.', 'tokenforge' ) } )
		] },
		{ id: 'effects', title: __( 'Effects', 'tokenforge' ), panels: [
			P( __( 'Transitions & easing', 'tokenforge' ), 'motion', [ B( 'transitions', __( 'Transition variables', 'tokenforge' ) ), T( 'transition_duration', __( 'Duration', 'tokenforge' ) ), T( 'transition_timing', __( 'Timing', 'tokenforge' ) ), T( 'transition_delay', __( 'Delay', 'tokenforge' ) ), B( 'transition_class', __( '<code>.transition</code> class', 'tokenforge' ) ), B( 'easing', __( 'Easing presets (<code>--ease-*</code>)', 'tokenforge' ) ), A( 'easings', __( 'Presets, one <code>name=value</code> per line', 'tokenforge' ), { rows: 5 } ) ] ),
			P( __( 'Entrance (scroll)', 'tokenforge' ), 'motion', [ T( 'enter_range_start', __( 'Range start', 'tokenforge' ) ), T( 'enter_range_end', __( 'Range end', 'tokenforge' ) ), T( 'enter_stagger', __( 'Stagger interval', 'tokenforge' ) ), T( 'enter_opacity', __( 'Fade: start opacity', 'tokenforge' ) ), T( 'enter_float', __( 'Float distance', 'tokenforge' ) ), T( 'enter_sink', __( 'Sink distance', 'tokenforge' ) ), T( 'enter_slide', __( 'Slide distance', 'tokenforge' ) ), T( 'enter_grow', __( 'Grow: start scale', 'tokenforge' ) ), T( 'enter_shrink', __( 'Shrink: start scale', 'tokenforge' ) ), T( 'enter_blur', __( 'Blur', 'tokenforge' ) ), T( 'enter_parallax', __( 'Parallax distance', 'tokenforge' ) ), T( 'fallback_duration', __( 'Fallback duration', 'tokenforge' ) ), T( 'fallback_timing', __( 'Fallback timing', 'tokenforge' ) ), T( 'fallback_delay', __( 'Fallback delay', 'tokenforge' ) ) ], { on: 'enter', d: __( '<code>.on-enter--fade / float / sink / slide-left / slide-right / grow / shrink / blur</code>, <code>.on-enter-all--*</code>, <code>--stagger</code>, <code>--parallax</code>.', 'tokenforge' ) } ),
			P( __( 'Exit (scroll)', 'tokenforge' ), 'motion', [ T( 'exit_range_start', __( 'Range start', 'tokenforge' ) ), T( 'exit_range_end', __( 'Range end', 'tokenforge' ) ), T( 'exit_stagger', __( 'Stagger interval', 'tokenforge' ) ), T( 'exit_opacity', __( 'Fade: end opacity', 'tokenforge' ) ), T( 'exit_float', __( 'Float distance', 'tokenforge' ) ), T( 'exit_sink', __( 'Sink distance', 'tokenforge' ) ), T( 'exit_slide', __( 'Slide distance', 'tokenforge' ) ), T( 'exit_grow', __( 'Grow: end scale', 'tokenforge' ) ), T( 'exit_shrink', __( 'Shrink: end scale', 'tokenforge' ) ), T( 'exit_blur', __( 'Blur', 'tokenforge' ) ) ], { on: 'exit', d: __( '<code>.on-exit--*</code> (same set).', 'tokenforge' ) } ),
			P( __( 'Visible (once)', 'tokenforge' ), 'motion', [ T( 'visible_duration', __( 'Duration', 'tokenforge' ) ), T( 'visible_timing', __( 'Timing', 'tokenforge' ) ), T( 'visible_delay', __( 'Delay', 'tokenforge' ) ), T( 'visible_threshold', __( 'Threshold (0 to 1)', 'tokenforge' ) ), T( 'visible_stagger', __( 'Stagger interval', 'tokenforge' ) ), T( 'visible_opacity', __( 'Fade: start opacity', 'tokenforge' ) ), T( 'visible_float', __( 'Float distance', 'tokenforge' ) ), T( 'visible_sink', __( 'Sink distance', 'tokenforge' ) ), T( 'visible_slide', __( 'Slide distance', 'tokenforge' ) ), T( 'visible_grow', __( 'Grow: start scale', 'tokenforge' ) ), T( 'visible_shrink', __( 'Shrink: start scale', 'tokenforge' ) ), T( 'visible_blur', __( 'Blur', 'tokenforge' ) ) ], { on: 'visible', d: __( '<code>.on-visible--*</code>: plays once when scrolled into view. Without JavaScript the content simply shows.', 'tokenforge' ) } ),
			P( __( 'Hover', 'tokenforge' ), 'motion', [ T( 'hover_duration', __( 'Duration', 'tokenforge' ) ), T( 'hover_timing', __( 'Timing', 'tokenforge' ) ), T( 'hover_grow', __( 'Grow scale', 'tokenforge' ) ), T( 'hover_shrink', __( 'Shrink scale', 'tokenforge' ) ), T( 'hover_float', __( 'Float distance', 'tokenforge' ) ), T( 'hover_sink', __( 'Sink distance', 'tokenforge' ) ), T( 'hover_slide_right', __( 'Slide right', 'tokenforge' ) ), T( 'hover_slide_left', __( 'Slide left', 'tokenforge' ) ), T( 'hover_shadow', __( 'Shadow', 'tokenforge' ) ), C( 'hover_glow_color', __( 'Glow colour', 'tokenforge' ) ), T( 'hover_glow_spread', __( 'Glow spread', 'tokenforge' ) ), T( 'hover_glow_opacity', __( 'Glow opacity', 'tokenforge' ) ), T( 'hover_fade', __( 'Fade opacity', 'tokenforge' ) ), T( 'hover_brighten', __( 'Brighten', 'tokenforge' ) ) ], { on: 'hover', d: __( '<code>.on-hover--grow / shrink / float / sink / slide-right / slide-left / shadow / glow / fade / brighten</code>.', 'tokenforge' ) } ),
			P( __( 'Hover borders', 'tokenforge' ), 'motion', [ C( 'ripple_color', __( 'Ripple colour', 'tokenforge' ) ), T( 'ripple_width', __( 'Ripple width', 'tokenforge' ) ), T( 'ripple_duration', __( 'Ripple duration', 'tokenforge' ) ), T( 'ripple_distance', __( 'Ripple distance', 'tokenforge' ) ), C( 'outline_color', __( 'Outline colour', 'tokenforge' ) ), T( 'outline_width', __( 'Outline width', 'tokenforge' ) ), T( 'outline_duration', __( 'Outline duration', 'tokenforge' ) ), C( 'underline_color', __( 'Underline colour', 'tokenforge' ) ), T( 'underline_width', __( 'Underline width', 'tokenforge' ) ), T( 'underline_duration', __( 'Underline duration', 'tokenforge' ) ) ], { on: 'border_fx', d: __( '<code>.on-hover--ripple-out/-in</code>, <code>--outline-out/-in</code>, <code>--underline-left/-center/-right</code>.', 'tokenforge' ) } ),
			P( __( 'Builders & accessibility', 'tokenforge' ), 'motion', [ B( 'disable_in_builder', __( 'Keep scroll & visible effects still in the builder canvas / block editor', 'tokenforge' ) ) ], { d: __( 'Visitors who prefer reduced motion get no scroll or visible animations and no hover movement.', 'tokenforge' ) } )
		] },
		{ id: 'forms', title: __( 'Forms', 'tokenforge' ), panels: [
			P( __( 'Form fields', 'tokenforge' ), 'components', [ T( 'field_padding', __( 'Padding', 'tokenforge' ) ), T( 'field_radius', __( 'Radius', 'tokenforge' ) ), C( 'field_border', __( 'Border colour', 'tokenforge' ) ), T( 'form_gap', __( 'Space between fields', 'tokenforge' ) ), S( 'form_btn_family', __( 'Submit button: family', 'tokenforge' ), WOO_FAMS ), S( 'form_btn_variant', __( 'Submit button: style', 'tokenforge' ), WOO_VARIANTS ), B( 'form_extras', __( 'Steps, conditions and calculations in the builder\u2019s form', 'tokenforge' ), { needs: 'form_extras' } ), B( 'form_layouts', __( 'Ready form layouts (.form--columns, .form--inline, .form--compact, .form--card)', 'tokenforge' ), { needs: 'form_extras' } ), B( 'form_fields', __( 'Slider, star rating, range, input mask and character counter for form fields', 'tokenforge' ), { needs: 'form_extras' } ), B( 'form_dates', __( 'Date rules for form fields (earliest, latest, weekdays, blocked days)', 'tokenforge' ), { needs: 'form_extras' } ), B( 'form_messages', __( 'Your own error message for each rule of a form field', 'tokenforge' ), { needs: 'form_extras' } ), B( 'form_options', __( 'Choice options for form fields taken from your content', 'tokenforge' ), { needs: 'form_extras' } ), B( 'form_live', __( 'Live value tag: show a field\x27s value as the visitor types', 'tokenforge' ), { needs: 'form_extras' } ), B( 'form_limits', __( 'Limits and submit rules for forms (close after N sends, hide the button)', 'tokenforge' ), { needs: 'form_extras' } ), B( 'form_actions', __( 'Form actions: update post meta and add to cart', 'tokenforge' ), { needs: 'form_extras' } ), B( 'form_presets', __( 'Form, field and saved presets in the builder', 'tokenforge' ), { needs: 'form_extras' } ), B( 'form_styles', __( 'Form style presets (.form--underline, .form--rounded, .form--soft)', 'tokenforge' ), { needs: 'form_extras' } ) ], { on: 'base_forms', d: __( 'Form fields, focus rings and labels everywhere. Put <code>.form--light</code> or <code>.form--dark</code> on the builder\u2019s form (or around it) and the whole form follows: field colours, spacing, labels, messages and the submit button in your button style. With steps on, tick New step starts here on a field of the builder\u2019s form to split it into steps (Back, Next and a progress bar), and use Show only when to show a field only for certain answers.', 'tokenforge' ) } ),
			P( __( 'Form submissions', 'tokenforge' ), 'components', [ B( 'subs_status', __( 'Read and unread, a new count in the menu, statuses (new, done, spam) and notes', 'tokenforge' ) ), B( 'subs_columns', __( 'Choose and reorder the columns', 'tokenforge' ) ), B( 'subs_edit', __( 'Edit an entry', 'tokenforge' ) ), B( 'subs_export', __( 'Export selected rows as CSV or JSON', 'tokenforge' ) ), B( 'subs_top_menu', __( 'Show the screen as a top-level menu item', 'tokenforge' ) ), S( 'subs_role', __( 'Who can use these', 'tokenforge' ), [ [ 'administrator', __( 'Administrators (default)', 'tokenforge' ) ], [ 'editor', __( 'Editors and above', 'tokenforge' ) ], [ 'author', __( 'Authors and above', 'tokenforge' ) ], [ 'contributor', __( 'Contributors and above', 'tokenforge' ) ] ] ), B( 'subs_cleanup', __( 'Delete old submissions automatically', 'tokenforge' ) ), T( 'subs_days', __( 'After this many days (0 = only forms with their own number)', 'tokenforge' ) ) ], { on: 'subs_extend', needs: 'form_extras', custom: 'subsCleanup', d: __( 'Adds to the builder’s own Form submissions screen (its table and actions stay as they are): search, one entry laid out, read and unread, statuses, notes, columns, editing and export. People also need access to form submissions in the builder’s settings. Each form can have its own number of days in its Save submission settings.', 'tokenforge' ) } )
		] },
		{ id: 'woo', title: __( 'WooCommerce', 'tokenforge' ), panels: [
			P( __( 'WooCommerce', 'tokenforge' ), 'woo', [ B( 'remove_css', __( 'Remove WooCommerce’s own stylesheets', 'tokenforge' ) ), B( 'override', __( 'Win over element settings made in the builder', 'tokenforge' ) ) ], { custom: 'wooStatus', d: __( 'Your shop in your design system: buttons, notices, fields, prices, sale badges, ratings, product grids, tables, tabs and pagination, on classic shop pages, in the builder’s shop elements and in the Cart, Checkout and product blocks. Switch any part off below.', 'tokenforge' ) } ),
			P( __( 'Shop pages', 'tokenforge' ), 'woo', [ S( 'load', __( 'Load shop page styles', 'tokenforge' ), [ [ 'auto', __( 'Only where the shop appears (lighter pages)', 'tokenforge' ) ], [ 'all', __( 'On every page', 'tokenforge' ) ] ] ), C( 'surface', __( 'Panel background', 'tokenforge' ) ), C( 'line_color', __( 'Line colour', 'tokenforge' ) ), T( 'panel_radius', __( 'Panel radius', 'tokenforge' ) ), T( 'section_space', __( 'Space between sections', 'tokenforge' ) ), T( 'control_height', __( 'Quantity and button height', 'tokenforge' ) ), T( 'field_gap', __( 'Space between fields', 'tokenforge' ) ), C( 'save_color', __( 'Saving text colour', 'tokenforge' ) ), C( 'save_background', __( 'Saving background', 'tokenforge' ) ) ], { on: 'pages', d: __( 'Product lists, product pages, cart, every checkout setup (one page, steps, classic or blocks), order received and every account page: even section spacing, product cards, line items, totals and summary panels, one quantity box, savings tags, checkout steps, payment choices, fields in two columns, account tabs, address cards, variations, swatches, gallery, reviews, the mini cart and header carts (the builder\u2019s mini cart and dynamic fragments). These styles load only on pages that show the shop, so other pages stay lighter. Works with the builder\u2019s shop elements and with WooCommerce\u2019s own pages.', 'tokenforge' ) } ),
			P( __( 'Buttons', 'tokenforge' ), 'woo', [ S( 'btn_main_family', __( 'Main actions: family', 'tokenforge' ), WOO_FAMS ), S( 'btn_main_variant', __( 'Main actions: style', 'tokenforge' ), WOO_VARIANTS ), S( 'btn_family', __( 'Other buttons: family', 'tokenforge' ), WOO_FAMS ), S( 'btn_variant', __( 'Other buttons: style', 'tokenforge' ), WOO_VARIANTS ) ], { on: 'buttons', d: __( 'Main actions are Add to cart on a product page, Proceed to checkout and Place order. They use the TokenForge button settings (Buttons).', 'tokenforge' ) } ),
			P( __( 'Prices', 'tokenforge' ), 'woo', [ C( 'price_color', __( 'Price colour', 'tokenforge' ) ), T( 'price_weight', __( 'Price weight', 'tokenforge' ) ), C( 'sale_color', __( 'Sale price colour', 'tokenforge' ) ), C( 'old_price_color', __( 'Old price colour', 'tokenforge' ) ) ], { on: 'prices', d: __( 'Prices, sale prices and the old (crossed out) price. In stock and out of stock use the success and danger colours.', 'tokenforge' ) } ),
			P( __( 'Sale badge', 'tokenforge' ), 'woo', [ C( 'badge_background', __( 'Background', 'tokenforge' ) ), C( 'badge_color', __( 'Text colour', 'tokenforge' ) ), T( 'badge_radius', __( 'Radius', 'tokenforge' ) ), C( 'new_badge_background', __( 'New badge background', 'tokenforge' ) ) ], { on: 'badges' } ),
			P( __( 'Ratings', 'tokenforge' ), 'woo', [ C( 'star_color', __( 'Star colour', 'tokenforge' ) ) ], { on: 'ratings' } ),
			P( __( 'Look and spacing', 'tokenforge' ), 'woo', [ C( 'text_color', __( 'Text colour', 'tokenforge' ) ), C( 'muted_color', __( 'Soft text colour', 'tokenforge' ) ), C( 'accent', __( 'Accent colour', 'tokenforge' ) ), C( 'accent_soft', __( 'Soft accent background', 'tokenforge' ) ), C( 'border_strong', __( 'Field and box border colour', 'tokenforge' ) ), T( 'radius', __( 'Small radius', 'tokenforge' ) ), T( 'panel_padding', __( 'Panel padding', 'tokenforge' ) ), T( 'gap', __( 'Space inside panels', 'tokenforge' ) ), T( 'gap_small', __( 'Small space', 'tokenforge' ) ) ], { d: __( 'Used by every shop part: cart, checkout, account, product pages and lists. Empty a field to go back to its default.', 'tokenforge' ) } ),
			P( __( 'Notices', 'tokenforge' ), 'woo', [ T( 'notice_radius', __( 'Radius', 'tokenforge' ) ), T( 'notice_padding', __( 'Padding', 'tokenforge' ) ), T( 'notice_border_width', __( 'Side border width', 'tokenforge' ) ), T( 'notice_font_size', __( 'Text size', 'tokenforge' ) ), B( 'notice_icon', __( 'Show the icon', 'tokenforge' ) ), C( 'notice_success_background', __( 'Success: background', 'tokenforge' ) ), C( 'notice_success_text', __( 'Success: text', 'tokenforge' ) ), C( 'notice_success_border', __( 'Success: border and icon', 'tokenforge' ) ), C( 'notice_info_background', __( 'Info: background', 'tokenforge' ) ), C( 'notice_info_text', __( 'Info: text', 'tokenforge' ) ), C( 'notice_info_border', __( 'Info: border and icon', 'tokenforge' ) ), C( 'notice_error_background', __( 'Error: background', 'tokenforge' ) ), C( 'notice_error_text', __( 'Error: text', 'tokenforge' ) ), C( 'notice_error_border', __( 'Error: border and icon', 'tokenforge' ) ), C( 'notice_warning_background', __( 'Warning: background', 'tokenforge' ) ), C( 'notice_warning_text', __( 'Warning: text', 'tokenforge' ) ), C( 'notice_warning_border', __( 'Warning: border and icon', 'tokenforge' ) ) ], { on: 'notices', d: __( 'Success, info and error messages (and warnings in the blocks). An empty colour uses the success, info, danger or warning colour family. The store notice bar uses the primary colour.', 'tokenforge' ) } ),
			P( __( 'Fields', 'tokenforge' ), 'woo', [ T( 'qty_width', __( 'Quantity box width', 'tokenforge' ) ), T( 'field_height', __( 'Field height', 'tokenforge' ) ), T( 'field_padding', __( 'Field padding', 'tokenforge' ) ), T( 'field_font_size', __( 'Text size', 'tokenforge' ) ), C( 'field_background', __( 'Background', 'tokenforge' ) ), C( 'field_text_color', __( 'Text colour', 'tokenforge' ) ), C( 'field_border_color', __( 'Border colour', 'tokenforge' ) ), T( 'field_border_width', __( 'Border width', 'tokenforge' ) ), T( 'field_radius', __( 'Radius', 'tokenforge' ) ), C( 'field_focus_color', __( 'Focus colour', 'tokenforge' ) ), C( 'label_color', __( 'Label colour', 'tokenforge' ) ), T( 'label_size', __( 'Label size', 'tokenforge' ) ) ], { on: 'forms', d: __( 'Checkout, account and coupon fields. An empty setting follows Forms. With floating labels the label sits inside the field: those fields keep their own padding, and Field height sets their height.', 'tokenforge' ) } ),
			P( __( 'Product grid', 'tokenforge' ), 'woo', [ T( 'grid_gap', __( 'Gap', 'tokenforge' ) ), N( 'columns_s', __( 'Columns at the s breakpoint', 'tokenforge' ) ), N( 'columns_xs', __( 'Columns at the xs breakpoint', 'tokenforge' ) ), S( 'image_fit', __( 'Product images', 'tokenforge' ), [ [ 'contain', __( 'Whole image (contain)', 'tokenforge' ) ], [ 'cover', __( 'Fill the frame (cover)', 'tokenforge' ) ] ] ), S( 'image_ratio', __( 'Image shape', 'tokenforge' ), [ [ '1', __( 'Square', 'tokenforge' ) ], [ '4/5', __( 'Portrait 4:5', 'tokenforge' ) ], [ '3/4', __( 'Portrait 3:4', 'tokenforge' ) ], [ '2/3', __( 'Portrait 2:3', 'tokenforge' ) ], [ '4/3', __( 'Landscape 4:3', 'tokenforge' ) ], [ '3/2', __( 'Landscape 3:2', 'tokenforge' ) ], [ '16/9', __( 'Wide 16:9', 'tokenforge' ) ], [ 'auto', __( 'As the image', 'tokenforge' ) ] ] ), C( 'image_background', __( 'Image background', 'tokenforge' ) ), S( 'image_source', __( 'Pictures in product lists', 'tokenforge' ), [ [ 'crop', __( 'WooCommerce thumbnails (cropped)', 'tokenforge' ) ], [ 'whole', __( 'Whole pictures (uncropped)', 'tokenforge' ) ] ] ), B( 'cards', __( 'Show products as cards (the Cards settings)', 'tokenforge' ) ) ], { on: 'grid', d: __( 'Shop and category pages keep the number of columns set in WooCommerce and use a grid with the TokenForge gap. Smaller screens drop to the columns below (breakpoints from Global SCSS).', 'tokenforge' ) } ),
			P( __( 'Product filters', 'tokenforge' ), 'woo', [], { on: 'filters', d: __( 'The builder\u2019s filters (checkboxes, radios, buttons, search, select, range sliders, active filters), its products filter, WooCommerce\u2019s filter widgets and the Product Filters blocks: your colours, chips for chosen options and a slider in your accent colour.', 'tokenforge' ) } ),
			P( __( 'Tables, tabs & pagination', 'tokenforge' ), 'woo', [ B( 'tables', __( 'Cart and order tables', 'tokenforge' ) ), B( 'tabs', __( 'Product tabs', 'tokenforge' ) ), B( 'pagination', __( 'Pagination', 'tokenforge' ) ) ] ),
			P( __( 'Blocks', 'tokenforge' ), 'woo', [ B( 'global_styles', __( 'Block themes: prices, sale badges and ratings through global styles', 'tokenforge' ) ) ], { on: 'blocks', d: __( 'Also style the Cart, Checkout, Mini-Cart and product blocks: their buttons, notices, fields, prices, sale badges and ratings, the sticky checkout bar and the quantity badge. With a block theme, the product price, sale badge and rating blocks can take their look from the theme\u2019s global styles, so you can change them in the Site Editor too.', 'tokenforge' ) } )
		] },
		{ id: 'content', title: __( 'Content', 'tokenforge' ), panels: [
			P( __( 'Prose', 'tokenforge' ), 'content', [ B( 'prose_auto', __( 'Also style post content automatically', 'tokenforge' ) ), B( 'rich_text', __( 'Also style Rich Text elements', 'tokenforge' ) ), T( 'prose_spacing', __( 'Block spacing', 'tokenforge' ) ), T( 'prose_heading_spacing', __( 'Space above headings', 'tokenforge' ) ), T( 'prose_width', __( 'Max text width (e.g. 70ch)', 'tokenforge' ) ), C( 'prose_accent', __( 'Accent (markers)', 'tokenforge' ) ) ], { on: 'prose', d: __( '<code>.prose</code>: long-form styling for lists, figures, code, tables and rules, all zero-specificity.', 'tokenforge' ) } )
		] },
		{ id: 'recipes', title: __( 'Recipes', 'tokenforge' ), panels: [
			P( __( 'Recipes', 'tokenforge' ), 'recipes', [ B( 'inputs', __( 'Also in builder fields: <code>?primary-clr;</code> in style fields, <code>?ipsum-short;</code> in text fields, rich text and text edited in the canvas', 'tokenforge' ), { needs: 'editor_tweaks' } ) ], { on: 'enabled', d: __( 'Type <code>?name</code> in the builder’s CSS editor and pick it from the list (or finish with <code>;</code>): it expands into ready-made CSS. <code>%root%</code> is the element’s selector (in SCSS: <code>&amp;</code>).', 'tokenforge' ) } ),
			P( __( 'Built-in recipes', 'tokenforge' ), null, null, { custom: 'recipeList' } )
		] },
		{ id: 'tokens', title: __( 'Tokens', 'tokenforge' ), render: 'tokens' },
		{ id: 'attributes', title: __( 'Data attributes', 'tokenforge' ), render: 'attributes' },
		{ id: 'builder', title: __( 'Builder', 'tokenforge' ), panels: [
			P( __( 'Add Elements panel', 'tokenforge' ), 'enhancements', [ B( 'stack_add_elements', __( 'One element per row', 'tokenforge' ) ), B( 'pro_mode', __( 'Pro mode (denser)', 'tokenforge' ) ), T( 'hide_elements', __( 'Hide elements (names, comma separated)', 'tokenforge' ), { w: 1, h: __( 'e.g. <code>divider, icon-box, social-icons</code>', 'tokenforge' ) } ) ], { needs: 'hide_elements' } ),
			P( __( 'Canvas', 'tokenforge' ), 'enhancements', [ B( 'grid_overlay', __( 'The builder’s grid overlay on the selected grid', 'tokenforge' ) ), B( 'element_outlines', __( 'Outline containers', 'tokenforge' ) ), B( 'grid_visualizer', __( 'Outline every grid / flex container (debug)', 'tokenforge' ) ), B( 'overflow_detection', __( 'Outline elements that overflow the viewport', 'tokenforge' ) ) ], { needs: 'canvas_tools' } ),
			P( __( 'Canvas badges', 'tokenforge' ), 'enhancements', [ B( 'canvas_headings', __( 'Heading levels', 'tokenforge' ) ), B( 'canvas_focus', __( 'Focus order', 'tokenforge' ) ) ], { needs: 'canvas_tools', d: __( 'Small badges in the builder canvas that only you see: the level of every heading, and the order the Tab key visits links, buttons and fields.', 'tokenforge' ) } ),
			P( __( 'Style tab & code', 'tokenforge' ), 'enhancements', [ B( 'css_group_top', __( 'CSS group first', 'tokenforge' ) ), B( 'bigger_code_editor', __( 'Taller code editor', 'tokenforge' ) ), B( 'css_autocomplete', __( 'Autocomplete TokenForge variables and classes', 'tokenforge' ) ) ], { needs: 'editor_tweaks' } ),
			P( __( 'Contextual menus', 'tokenforge' ), 'enhancements', [ B( 'ctx_vars_menu', __( 'Right-click an input to insert a variable', 'tokenforge' ) ), B( 'ctx_class_menu', __( 'Right-click the class field to add utility classes', 'tokenforge' ) ) ], { needs: 'context_menus', d: __( 'Shift + right-click opens the browser menu.', 'tokenforge' ) } ),
			P( __( 'Templates', 'tokenforge' ), 'enhancements', [ B( 'original_template_classes', __( 'Remote templates keep their own classes', 'tokenforge' ) ), B( 'template_gallery', __( 'Tidier template gallery', 'tokenforge' ) ) ], { needs: 'templates' } ),
			P( __( 'Theme style', 'tokenforge' ), 'settings', [ B( 'bricks_theme_sync', __( 'Keep a theme style in step with TokenForge', 'tokenforge' ) ) ], { custom: 'themeSync', needs: 'theme_style', d: __( 'One theme style (named after the site, for the entire website) follows TokenForge: its fields point at TokenForge variables such as <code>var(--content-width)</code>, <code>var(--text-m)</code> and <code>var(--link-color)</code>. Change a field in the theme style and the matching TokenForge setting follows.', 'tokenforge' ) } ),
			P( __( 'Builder built-in', 'tokenforge' ), null, null, { custom: 'native', needs: 'editor_tweaks', d: __( 'The builder has these built in; the switches change its own settings. Reload the builder after saving.', 'tokenforge' ) } )
		] },
		{ id: 'options', title: __( 'Options', 'tokenforge' ), panels: [
			P( __( 'Dashboard look', 'tokenforge' ), 'settings', [ S( 'ui_theme', __( 'Look', 'tokenforge' ), [ [ 'bricks', __( 'Builder panels (default)', 'tokenforge' ) ], [ 'flat', __( 'Flat', 'tokenforge' ) ], [ 'light', __( 'Light', 'tokenforge' ) ] ] ) ], { d: __( 'How this dashboard looks, in wp-admin, the builder and on the front end. Save to apply.', 'tokenforge' ) } ),
			P( __( 'Builder sync', 'tokenforge' ), 'settings', [ B( 'sync_bricks_vars', __( 'Tokens → builder global variables', 'tokenforge' ) ), B( 'vars_trans', __( 'Variables: transparent steps (-trans-10 … -90)', 'tokenforge' ) ), B( 'vars_channels', __( 'Variables: colour channel values (-hex, -rgb, -h …)', 'tokenforge' ) ), B( 'vars_alt', __( 'Variables: dark mode values (-alt)', 'tokenforge' ) ), B( 'vars_parts', __( 'Variables: buttons, cards, blockquotes and shop', 'tokenforge' ) ), B( 'sync_bricks_colors', __( 'Colours → the builder colour palette (families with their shades)', 'tokenforge' ) ), B( 'enqueue_in_builder', __( 'Load the stylesheet in the builder canvas', 'tokenforge' ) ), T( 'category_name', __( 'Category / palette name', 'tokenforge' ) ), B( 'prune_classes', __( 'Remove switched-off classes from the builder', 'tokenforge' ) ), B( 'prune_variables', __( 'Remove stale variables / colours from the builder', 'tokenforge' ) ) ], { needs: 'tokens_native' } ),
			P( __( 'Block editor', 'tokenforge' ), 'settings', [ S( 'gutenberg_palette', __( 'Colour palette', 'tokenforge' ), [ [ 'off', __( 'Theme palette', 'tokenforge' ) ], [ 'add', __( 'Add TokenForge colours', 'tokenforge' ) ], [ 'replace', __( 'Replace with TokenForge colours', 'tokenforge' ) ] ] ) ] ),
			P( __( 'TokenForge window', 'tokenforge' ), 'settings', [ K( 'panel_shortcut', __( 'Open / close shortcut', 'tokenforge' ), { h: __( 'Click the field, then press the keys (e.g. Alt+Shift+T). Works in the builder (also with the canvas focused) and on the site\u2019s pages.', 'tokenforge' ) } ), B( 'canvas_panel', __( 'Show in builder canvas while editing', 'tokenforge' ), { needs: 'canvas_tools' } ), B( 'frontend_panel', __( 'On the site\u2019s pages (administrators only)', 'tokenforge' ) ), B( 'frontend_launcher', __( 'Floating TokenForge button on the site\u2019s pages', 'tokenforge' ) ) ], { d: __( 'The dashboard as a dockable window. In the builder it opens with the shortcut or the TokenForge button; on the site\u2019s pages with the shortcut or the admin-bar TokenForge link (Ctrl/Cmd-click goes to wp-admin).', 'tokenforge' ) } ),
			P( __( 'AI agents', 'tokenforge' ), 'settings', [ B( 'ai_abilities', __( 'TokenForge abilities for AI agents (MCP tools)', 'tokenforge' ) ), B( 'ai_guidance', __( 'Tell AI agents to build with TokenForge', 'tokenforge' ) ) ], { d: __( 'When an AI builds this site through the WordPress MCP Adapter or the builder’s AI abilities, it gets the tools <code>tokenforge-get-framework</code>, <code>tokenforge-search</code>, <code>tokenforge-check-css</code>, and <code>tokenforge-get-recipe</code>, and is told to use TokenForge classes and variables instead of hard-coded CSS.', 'tokenforge' ) } ),
			P( __( 'Performance', 'tokenforge' ), 'settings', [ B( 'inline_css', __( 'Inline the stylesheet', 'tokenforge' ) ), B( 'hot_reload', __( 'Hot-reload CSS in open tabs after Save', 'tokenforge' ) ), B( 'admin_bar_link', __( 'Admin bar link', 'tokenforge' ) ) ] ),
			P( __( 'Breakpoints', 'tokenforge' ), 'scss', [ N( 'bp_xs', __( 'xs (px)', 'tokenforge' ) ), N( 'bp_s', __( 's (px)', 'tokenforge' ) ), N( 'bp_m', __( 'm (px)', 'tokenforge' ) ), N( 'bp_l', __( 'l (px)', 'tokenforge' ) ), N( 'bp_xl', __( 'xl (px)', 'tokenforge' ) ), N( 'bp_xxl', __( 'xxl (px)', 'tokenforge' ) ) ], { d: __( 'Widths in px for the breakpoint names (xs to xxl) used by the <code>?breakpoint-*</code> recipes and the responsive data attributes.', 'tokenforge' ) } ),
			P( __( 'Cascade layers', 'tokenforge' ), 'settings', [ B( 'layer_defaults', __( 'Defaults & utilities', 'tokenforge' ) ), B( 'layer_base', __( 'Base typography & sections', 'tokenforge' ) ), B( 'layer_buttons', __( 'Buttons & external links', 'tokenforge' ) ), B( 'layer_cards', __( 'Cards', 'tokenforge' ) ), B( 'layer_icons', __( 'Icons', 'tokenforge' ) ), B( 'layer_content', __( 'Content / prose', 'tokenforge' ) ) ], { d: __( 'A layered part loses to <em>any</em> unlayered CSS (theme, builder, custom). Variables and utility classes are never layered.', 'tokenforge' ) } ),
			P( __( 'Utility framework', 'tokenforge' ), 'framework', [ B( 'utilities_color', __( 'Colour utilities', 'tokenforge' ) ), B( 'utilities_spacing', __( 'Spacing utilities', 'tokenforge' ) ), B( 'utilities_typography', __( 'Typography utilities', 'tokenforge' ) ), B( 'utilities_layout', __( 'Layout utilities', 'tokenforge' ) ), B( 'register_with_bricks', __( 'Register classes with the builder', 'tokenforge' ), { needs: 'class_complete' } ), B( 'prune_unused', __( 'Leave out unused utility classes', 'tokenforge' ), { w: 1 } ), A( 'prune_keep', __( 'Always keep these classes', 'tokenforge' ), { rows: 2, h: __( 'Class names added by theme or plugin code, one per line or separated by spaces. End a name with * to keep every class that starts with it (bg-primary*).', 'tokenforge' ) } ) ], { custom: 'utilCount' } )
		] }
	];

	// Grouped navigation: sections in groups, panels gathered by "section:Panel title"
	// ("section:*" = its remaining panels) from the definitions above.
	ALL_SECTIONS.push( { id: 'transfer', title: __( 'Import, export & reset', 'tokenforge' ), panels: [
		P( __( 'Export', 'tokenforge' ), null, null, { custom: 'exportBox', d: __( 'Download every TokenForge setting, token, colour family and font as one file, to move them to another site or keep a backup.', 'tokenforge' ) } ),
		P( __( 'Import', 'tokenforge' ), null, null, { custom: 'importBox', d: __( 'Load a file made with Export. Choose which parts to bring in; everything else stays as it is.', 'tokenforge' ) } ),
		P( __( 'Design token files', 'tokenforge' ), null, null, { custom: 'tokenFiles', d: __( 'Your tokens as W3C design token files (DTCG), for Figma, Tokens Studio and Style Dictionary. Import shows what would change first; changed tokens are saved as your own tokens.', 'tokenforge' ) } ),
		P( __( 'Reset to defaults', 'tokenforge' ), null, null, { custom: 'resetBox', d: __( 'Put TokenForge back to how a new site starts. You can undo it under History.', 'tokenforge' ) } ),
		P( __( 'History', 'tokenforge' ), null, null, { custom: 'historyBox', d: __( 'Before every Save, import, reset and setup, TokenForge keeps a copy of all its settings, tokens, colour families and fonts (the last 20). See what changed since then, or put a copy back. Your pages are never touched.', 'tokenforge' ) } ),
		P( __( 'Settings importer', 'tokenforge' ), null, null, { custom: 'importData' } )
	] } );

	var NAV_GROUPS = [
		[ __( 'Start', 'tokenforge' ), [ { id: 'home' }, { id: 'cheatsheet' }, { id: 'check', from: [ 'colors:Palette contrast', 'additional:Automatic accessibility attributes', 'additional:Focus', 'additional:Reduced motion', 'buttons:External-link indicator', 'builder:Canvas badges' ] }, { id: 'recipes' } ] ],
		[ __( 'Setup', 'tokenforge' ), [
			{ id: 'wizard' },
			{ id: 'options', title: __( 'Options', 'tokenforge' ), from: [ 'options:Dashboard look', 'options:TokenForge window', 'options:AI agents', 'options:Builder sync', 'options:Block editor', 'options:Performance', 'options:Breakpoints', 'options:Cascade layers', 'options:Utility framework' ] },
			{ id: 'builder' },
			{ id: 'transfer' }
		] ],
		[ __( 'Foundations', 'tokenforge' ), [
			{ id: 'colors', title: __( 'Colors', 'tokenforge' ), from: [ 'colors:Color families', 'colors:Shade generation', 'scheme:Dark mode', 'scheme:Color assignments', 'scheme:Background relationships', 'additional:Selection', 'colors:*' ] },
			{ id: 'typography', title: __( 'Typography', 'tokenforge' ), from: [ 'additional:Root font size', 'typography:*' ] },
			{ id: 'spacing', title: __( 'Spacing', 'tokenforge' ), from: [ 'spacing:Space scale', 'spacing:Section spacing', 'spacing:Automatic gaps' ] },
			{ id: 'layout', title: __( 'Layout', 'tokenforge' ), from: [ 'layout:*', 'additional:Header height', 'additional:Sticky', 'additional:Content offset', 'additional:Scrolling' ] },
			{ id: 'borders', title: __( 'Borders & Shadows', 'tokenforge' ), from: [ 'borders:Radius', 'borders:Auto radius', 'borders:Borders', 'borders:Dividers', 'borders:Inverted radius', 'shadows:*' ] },
			{ id: 'tokens' },
			{ id: 'attributes' }
		] ],
		[ __( 'Components', 'tokenforge' ), [
			{ id: 'buttons' },
			{ id: 'cards', title: __( 'Cards & Ribbons', 'tokenforge' ), from: [ 'cards:*', 'borders:Corner ribbons' ] },
			{ id: 'icons' },
			{ id: 'forms' },
			{ id: 'woo' }
		] ],
		[ __( 'Content & Effects', 'tokenforge' ), [
			{ id: 'content', title: __( 'Content', 'tokenforge' ), from: [ 'spacing:Flow spacing', 'content:*', 'additional:Blockquotes' ] },
			{ id: 'overlays' },
			{ id: 'effects' },
			{ id: 'base', title: __( 'Base', 'tokenforge' ), from: [ 'additional:Reset', 'additional:Utilities' ] }
		] ]
	];
	// Sections that are modules (can be switched off as a whole) and what each covers.
	var MODULES = {
		colors: __( 'Colour families, dark mode, background classes', 'tokenforge' ),
		typography: __( 'Fonts, text and heading styles, text classes', 'tokenforge' ),
		spacing: __( 'Automatic gaps, spacing classes', 'tokenforge' ),
		layout: __( 'Sections, content width, grids, sticky header', 'tokenforge' ),
		borders: __( 'Radius, borders, dividers, shadows', 'tokenforge' ),
		buttons: __( 'Buttons and links', 'tokenforge' ),
		cards: __( 'Cards and corner ribbons', 'tokenforge' ),
		icons: __( 'Icon sizes, styles and lists', 'tokenforge' ),
		forms: __( 'Form fields', 'tokenforge' ),
		woo: __( 'Shop buttons, notices, prices, grids and blocks', 'tokenforge' ),
		content: __( 'Prose, flow spacing, blockquotes', 'tokenforge' ),
		overlays: __( 'Overlays and surface textures', 'tokenforge' ),
		effects: __( 'Scroll, visible and hover effects', 'tokenforge' ),
		base: __( 'Reset and helper classes', 'tokenforge' ),
		recipes: __( 'Ready-made snippets typed as ?name', 'tokenforge' ),
		builder: __( 'Builder tools', 'tokenforge' )
	};
	var SECTION_DESC = {
		cheatsheet: __( 'Every class, variable, mixin and recipe, one click to copy', 'tokenforge' ),
		check: __( 'Page check, palette contrast, automatic attributes, focus and motion', 'tokenforge' ),
		wizard: __( 'Set up the builder and connect it to TokenForge in one go', 'tokenforge' ),
		options: __( 'Look, window, AI agents, builder sync, performance', 'tokenforge' ),
		transfer: __( 'Move settings to another site, back up, start over', 'tokenforge' ),
		tokens: __( 'Every CSS variable, edit by hand', 'tokenforge' ),
		attributes: __( 'Where your site uses each data attribute', 'tokenforge' ),
		builder: __( 'The page builders TokenForge works with', 'tokenforge' ) // the Builder integrations view
	};
	var SECTIONS = ( function () {
		var byId = {}, used = {}, out = [];
		ALL_SECTIONS.forEach( function ( sec ) { byId[ sec.id ] = sec; } );
		var take = function ( ref ) {
			var parts = ref.split( ':' ), sec = byId[ parts[0] ], title = parts.slice( 1 ).join( ':' ), got = [];
			( sec && sec.panels || [] ).forEach( function ( p, i ) {
				var key = sec.id + '#' + i;
				if ( used[ key ] || ( '*' !== title && strip( p.key || p.title ) !== title ) ) { return; }
				used[ key ] = true; got.push( p );
			} );
			return got;
		};
		NAV_GROUPS.forEach( function ( grp ) {
			grp[1].forEach( function ( item ) {
				var base = byId[ item.id ] || {}, sec = { id: item.id, title: item.title || base.title, g: grp[0] };
				if ( base.render ) { sec.render = base.render; }
				if ( item.from ) {
					sec.panels = [];
					item.from.forEach( function ( ref ) { sec.panels = sec.panels.concat( take( ref ) ); } );
				} else if ( base.panels ) {
					sec.panels = take( item.id + ':*' );
				}
				out.push( sec );
			} );
		} );
		// Anything not placed (a new panel) still shows up: at the end of its old section id or Options.
		ALL_SECTIONS.forEach( function ( sec ) {
			( sec.panels || [] ).forEach( function ( p, i ) {
				if ( used[ sec.id + '#' + i ] ) { return; }
				var home = out.filter( function ( x ) { return x.id === sec.id; } )[0] || out.filter( function ( x ) { return 'options' === x.id; } )[0];
				home.panels = ( home.panels || [] ).concat( [ p ] );
			} );
		} );
		return out;
	} )();

	var ICONS = {
		woo: '<path d="M4 5h2l2 10h10l2-7H7"/><circle cx="10" cy="19" r="1.3"/><circle cx="17" cy="19" r="1.3"/>',
		colors: '<path d="M12 3a9 9 0 1 0 0 18c1 0 1.5-.8 1.5-1.5 0-1.2-1-1.3-1-2.5 0-.8.7-1.5 1.5-1.5H16a5 5 0 0 0 5-5c0-4-4-7.5-9-7.5z"/><circle cx="7.5" cy="11" r="1.2"/><circle cx="10" cy="7" r="1.2"/><circle cx="15" cy="7.5" r="1.2"/>',
		scheme: '<circle cx="12" cy="12" r="8"/><path d="M12 4a8 8 0 0 0 0 16z" fill="currentColor"/>',
		typography: '<path d="M5 19 11 5l6 14M7.6 14h6.8"/><path d="M16 9h4M18 7v9"/>',
		layout: '<rect x="3" y="4" width="18" height="16" rx="2"/><path d="M3 9h18M9 9v11"/>',
		spacing: '<path d="M4 4v16M20 4v16M8 12h8M10 10l-2 2 2 2M14 10l2 2-2 2"/>',
		buttons: '<rect x="3" y="7" width="18" height="10" rx="5"/><path d="M8 12h8"/>',
		overlays: '<rect x="3" y="3" width="13" height="13" rx="2"/><path d="M8 8h13v13H8z" fill="currentColor" fill-opacity=".25"/>',
		borders: '<path d="M4 9V6a2 2 0 0 1 2-2h3M15 4h3a2 2 0 0 1 2 2v3M20 15v3a2 2 0 0 1-2 2h-3M9 20H6a2 2 0 0 1-2-2v-3"/>',
		shadows: '<rect x="4" y="4" width="12" height="12" rx="2"/><path d="M8 20h10a2 2 0 0 0 2-2V8" opacity=".6"/>',
		cards: '<rect x="4" y="3" width="16" height="18" rx="2"/><path d="M4 10h16M8 14h8M8 17h5"/>',
		icons: '<path d="m12 3 2.6 5.6 6 .7-4.5 4.1 1.2 6L12 16.4 6.7 19.4l1.2-6L3.4 9.3l6-.7z"/>',
		additional: '<path d="M4 6h10M18 6h2M4 12h4M12 12h8M4 18h12M20 18h0"/><circle cx="16" cy="6" r="2"/><circle cx="10" cy="12" r="2"/><circle cx="18" cy="18" r="2"/>',
		effects: '<path d="M3 12h4M5 7h5M5 17h5"/><circle cx="16" cy="12" r="5"/>',
		forms: '<rect x="3" y="5" width="18" height="5" rx="1.5"/><rect x="3" y="14" width="18" height="5" rx="1.5"/><path d="M6 7.5h5"/>',
		content: '<path d="M5 6h14M5 10h14M5 14h9M5 18h11"/>',
		tokens: '<rect x="3" y="4" width="7" height="7" rx="1"/><rect x="14" y="4" width="7" height="7" rx="1"/><rect x="3" y="13" width="7" height="7" rx="1"/><rect x="14" y="13" width="7" height="7" rx="1"/>',
		attributes: '<path d="M9 7 4 12l5 5M15 7l5 5-5 5"/><path d="M12 5v14" stroke-dasharray="2 3"/>',
		base: '<path d="M12 3 4 6v6c0 4.5 3.4 8.3 8 9 4.6-.7 8-4.5 8-9V6z"/><path d="m9 12 2 2 4-4"/>',
		home: '<path d="M4 11 12 4l8 7"/><path d="M6 10v10h12V10"/>',
		transfer: '<path d="M7 4v12M3 8l4-4 4 4M17 20V8M13 16l4 4 4-4"/>',
		check: '<path d="M12 3 4 6v6c0 4.5 3.4 8.3 8 9 4.6-.7 8-4.5 8-9V6z"/><path d="m9 12 2 2 4-4"/>',
		cheatsheet: '<path d="M5 4h11a3 3 0 0 1 3 3v13H8a3 3 0 0 1-3-3z"/><path d="M5 17a3 3 0 0 1 3-3h11M9 8h6M9 11h4"/>',
		recipes: '<path d="M6 3h9l4 4v14H6z"/><path d="M14 3v5h5M9 12h7M9 16h5"/>',
		builder: '<path d="M14.5 6.5l3 3M4 20l2.5-.6L17 8.9 15.1 7 4.6 17.5 4 20z"/>',
		wizard: '<path d="M4 20 15 9M14 4v2M19 9h2M17.5 6.5 19 5M10 6.5 8.5 5M17.5 11.5 19 13"/>',
		options: '<circle cx="12" cy="12" r="3"/><path d="M12 2v3M12 19v3M2 12h3M19 12h3M4.9 4.9l2.1 2.1M17 17l2.1 2.1M19.1 4.9 17 7M7 17l-2.1 2.1"/>'
	};
	function icon( id ) { return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + ( ICONS[ id ] || '' ) + '</svg>'; }
	// Sections that only make sense with a plugin active.
	function shown( sec ) {
		if ( 'wizard' === sec.id ) { return !! ( state.setup && state.setup.available ); }
		return 'woo' !== sec.id || !! ( state.wooInfo || {} ).active;
	}
	// A panel or field marked needs: '<cap>' shows only when the active builder integration
	// has that capability (Platforms::CAPS). Hiding is display only: stored values stay.
	function has( x ) { return ! x.needs || ( state.caps || [] ).indexOf( x.needs ) > -1; }
	function section( id ) { for ( var i = 0; i < SECTIONS.length; i++ ) { if ( SECTIONS[ i ].id === id ) { return SECTIONS[ i ]; } } return SECTIONS[ 0 ]; }

	// ---- Fields ----------------------------------------------------------
	function defOf( mod, k ) { return ( state.defs[ mod ] || {} )[ k ]; }
	function fieldHtml( mod, f ) {
		var st   = state[ mod ] || {};
		var v    = st[ f.k ];
		var def  = defOf( mod, f.k );
		var attr = ' data-mod="' + mod + '" data-k="' + esc( f.k ) + '"';
		var help = f.h ? '<span class="tf-help">' + f.h + '</span>' : '';
		var tip  = tipIcon( tipText( mod, f.k ) );
		if ( f.t === 'b' ) {
			var na = f.na ? String( ( st[ f.na ] || {} )[ f.k ] || '' ) : ''; // f.na: a setting holding { key: why it cannot run here }
			return '<label class="tf-field tf-switchrow' + ( f.w ? ' tf-wide' : '' ) + '"><span class="tf-switch"><input type="checkbox"' + attr + ( v && ! na ? ' checked' : '' ) + ( na ? ' disabled' : '' ) + '><i></i></span><span class="tf-flabel">' + f.l + tip + help + ( na ? '<span class="tf-help">' + esc( na ) + '</span>' : '' ) + '</span></label>';
		}
		if ( f.t === 'k' ) {
			return '<label class="tf-field"><span class="tf-flabel">' + f.l + tip + '</span><span class="tf-keyfield"><input type="text" readonly class="tf-keyrec"' + attr + ' value="' + esc( keyLabel( v ) ) + '" placeholder="Click, then press keys"><button type="button" class="tf-btn tf-small" data-act="keyclear"' + attr + ' title="No shortcut">✕</button></span>' + help + '</label>';
		}
		var shown = v == null ? '' : String( v );
		var reset = ( def !== undefined && typeof def !== 'boolean' && typeof def !== 'object' && shown !== String( def ) )
			? '<button type="button" class="tf-reset" data-act="reset"' + attr + ' title="Reset to ' + esc( def === '' ? '(empty)' : def ) + '">↺</button>' : '';
		var ph   = ( def !== undefined && def !== '' && typeof def !== 'boolean' && typeof def !== 'object' ) ? ' placeholder="' + esc( def ) + '"' : '';
		var ctl;
		if ( f.t === 's' ) {
			ctl = '<select' + attr + '>' + f.o.map( function ( o ) {
				var val = Array.isArray( o ) ? o[0] : o, lab = Array.isArray( o ) ? o[1] : o;
				return '<option value="' + esc( val ) + '"' + ( String( val ) === shown ? ' selected' : '' ) + '>' + esc( lab ) + '</option>';
			} ).join( '' ) + '</select>';
		} else if ( f.t === 'a' ) {
			ctl = '<textarea rows="' + ( f.rows || 3 ) + '"' + attr + ph + '>' + esc( shown ) + '</textarea>';
		} else if ( f.t === 'n' ) {
			ctl = '<input type="number" step="' + ( f.step || 'any' ) + '"' + attr + ' value="' + esc( shown ) + '"' + ph + '>';
		} else {
			ctl = '<input type="text"' + attr + ' value="' + esc( shown ) + '"' + ph + ( /font_family$/.test( f.k ) ? ' list="tf-font-families"' : '' ) + ' spellcheck="false">';
		}
		if ( f.t === 'c' ) { ctl = '<span class="tf-colorfield">' + swatch( shown || def ) + ctl + '</span>'; }
		return '<label class="tf-field' + ( f.w || f.t === 'a' ? ' tf-wide' : '' ) + '"><span class="tf-flabel">' + f.l + tip + reset + '</span>' + ctl + help + '</label>';
	}
	function fieldsHtml( mod, fields ) { fields = ( fields || [] ).filter( has ); return fields.length ? '<div class="tf-fields">' + fields.map( function ( f ) { return fieldHtml( mod, f ); } ).join( '' ) + '</div>' : ''; }
	// A bare input for tables (no label, placeholder = default).
	function cell( mod, k, ph ) {
		var v = ( state[ mod ] || {} )[ k ]; var def = defOf( mod, k );
		var p = ph !== undefined ? ph : ( def || '' );
		var tt = tipText( mod, k );
		return '<input type="text" data-mod="' + mod + '" data-k="' + esc( k ) + '" value="' + esc( v == null ? '' : v ) + '"' + ( p ? ' placeholder="' + esc( p ) + '"' : '' ) + ( tt ? ' title="' + esc( tt ) + '" aria-label="' + esc( tt ) + '"' : '' ) + ' spellcheck="false">';
	}

	// ---- Tooltips: a "?" after every label; text from tips.js ----
	function tipText( mod, k ) { var T = window.TokenForgeTips; return T && mod ? T.get( mod, k ) : ''; }
	function tipRaw( k ) { var T = window.TokenForgeTips; return T ? T.raw( k ) : ''; }
	function tipIcon( text ) { return text ? '<span class="tf-tip" tabindex="0" role="button" aria-label="' + esc( text ) + '" data-tip="' + esc( text ) + '">?</span>' : ''; }
	var tipEl = null;
	function showTip( el ) {
		var text = el && el.getAttribute( 'data-tip' );
		if ( ! text || ! app ) { return; }
		if ( ! tipEl || ! tipEl.isConnected ) { tipEl = document.createElement( 'div' ); tipEl.className = 'tf-tooltip'; tipEl.setAttribute( 'role', 'tooltip' ); app.appendChild( tipEl ); }
		tipEl.textContent = text;
		tipEl.style.display = 'block';
		var r = el.getBoundingClientRect(), w = tipEl.offsetWidth, h = tipEl.offsetHeight;
		var x = Math.max( 8, Math.min( r.left + r.width / 2 - w / 2, window.innerWidth - w - 8 ) );
		var y = r.bottom + 8 + h > window.innerHeight - 8 ? r.top - h - 8 : r.bottom + 8;
		tipEl.style.left = Math.round( x ) + 'px'; tipEl.style.top = Math.round( Math.max( 8, y ) ) + 'px';
	}
	function hideTip() { if ( tipEl ) { tipEl.style.display = 'none'; } }

	// ---- Copy to clipboard (cheatsheet) ----
	function copyText( text, el ) {
		var done = function () {
			setStatus( 'Copied: ' + text, 'tf-ok' );
			var row = el && el.closest( '.tf-cheat-item' );
			if ( row ) { row.classList.add( 'is-copied' ); setTimeout( function () { row.classList.remove( 'is-copied' ); }, 900 ); }
		};
		var fallback = function () {
			var ta = document.createElement( 'textarea' ); ta.value = text; ta.setAttribute( 'readonly', '' );
			ta.style.position = 'fixed'; ta.style.opacity = '0'; ta.style.left = '-9999px'; app.appendChild( ta ); ta.select();
			var ok = false; try { ok = document.execCommand( 'copy' ); } catch ( e ) {}
			ta.remove();
			if ( ok ) { done(); } else { setStatus( 'Copy failed: select and copy by hand.', 'tf-err' ); }
		};
		if ( navigator.clipboard && window.isSecureContext ) { navigator.clipboard.writeText( text ).then( done, fallback ); } else { fallback(); }
	}

	// ---- Cheatsheet ----
	var COPY_ICON = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="9" y="9" width="11" height="11" rx="2"/><path d="M5 15V5a2 2 0 0 1 2-2h10"/></svg>';
	function cheatRow( it ) {
		return '<div class="tf-cheat-item"><button type="button" class="tf-cheat-name" data-act="copy" data-copy="' + esc( it.copy ) + '" title="Copy ' + esc( it.copy ) + '"><code>' + esc( it.n ) + '</code></button>'
			+ '<span class="tf-cheat-desc">' + esc( it.d ) + ( it.css && it.css !== it.copy ? '<code class="tf-cheat-css">' + esc( it.css ) + '</code>' : '' ) + '</span>'
			+ '<button type="button" class="tf-cheat-copy" data-act="copy" data-copy="' + esc( it.copy ) + '" title="Copy ' + esc( it.copy ) + '" aria-label="Copy ' + esc( it.copy ) + '">' + COPY_ICON + '<span class="tf-copied">Copied</span></button></div>';
	}
	// The cheatsheet data (also the list of data attributes with their descriptions), loaded once on demand.
	function ensureCheat() {
		if ( state.cheat || state.cheatLoading ) { return; }
		state.cheatLoading = true;
		api( '/cheatsheet' ).then( function ( r ) { state.cheat = r.groups || []; state.cheatLoading = false; if ( 'cheatsheet' === state.tab && ! state.q ) { render(); } } )
			.catch( function ( e ) { state.cheatLoading = false; state.cheat = []; setStatus( 'Cheatsheet failed: ' + e.message, 'tf-err' ); render(); } );
	}
	function renderCheatsheet() {
		if ( ! state.cheat ) {
			ensureCheat();
			return '<p class="tf-muted">Loading the cheatsheet…</p>';
		}
		var kinds = [ [ '', __( 'All', 'tokenforge' ) ], [ 'class', __( 'Classes', 'tokenforge' ) ], [ 'var', __( 'Variables', 'tokenforge' ) ], [ 'recipe', __( 'Recipes', 'tokenforge' ) ], [ 'attr', __( 'Attributes', 'tokenforge' ) ] ];
		var words = ( state.cheatQ || '' ).toLowerCase().trim().split( /\s+/ ).filter( Boolean ).map( function ( w ) { return w.replace( /^\./, '' ); } );
		var html = '<div class="tf-cheat-bar"><input type="search" class="tf-search tf-cheat-q" placeholder="' + esc( __( 'Search classes, variables, recipes…', 'tokenforge' ) ) + '" value="' + esc( state.cheatQ || '' ) + '" aria-label="Search the cheatsheet">'
			+ '<div class="tf-chips">' + kinds.map( function ( k ) { return '<button type="button" class="tf-chip' + ( ( state.cheatKind || '' ) === k[0] ? ' is-active' : '' ) + '" data-act="cheatkind" data-kind="' + k[0] + '">' + k[1] + '</button>'; } ).join( '' ) + '</div></div>'
			+ '<p class="tf-muted">Click a name or the copy icon: classes copy without the dot, variables as var(--name), recipes as ?name.</p>';
		if ( ! words.length && ! state.cheatKind ) {
			var example = "add_filter( 'tokenforge_global_scss', function ( $scss ) {\n\t$scss .= '.pricing { padding: var(--space-m); }';\n\t$scss .= '.pricing a { @include btn(primary); }';\n\treturn $scss;\n} );";
			html += '<section class="tf-panel tf-cheat-group"><div class="tf-panel-head tf-cheat-head"><h3>' + esc( __( 'Add your own SCSS (developers)', 'tokenforge' ) ) + '</h3></div>'
				+ '<div class="tf-panel-body"><p class="tf-muted tf-desc">' + __( 'The filter <code>tokenforge_global_scss</code> receives an empty string and returns SCSS. TokenForge compiles it with its own library (mixins and functions) and adds it last to the stylesheet. A compile error never breaks the site.', 'tokenforge' ) + '</p>'
				+ '<p class="tf-muted tf-desc">' + __( 'This is code, not a setting. Put it in a child theme’s <code>functions.php</code> or in a mu-plugin, never in TokenForge’s own files, so an update does not remove it.', 'tokenforge' ) + '</p>'
				+ '<pre class="tf-dev-code">' + esc( example ) + '</pre></div></section>';
		}
		var total = 0;
		state.cheat.forEach( function ( g ) {
			var items = g.items.filter( function ( it ) {
				if ( state.cheatKind && it.k !== state.cheatKind ) { return false; }
				if ( ! words.length ) { return true; }
				var hay = ( it.n + ' ' + it.d + ' ' + it.css + ' ' + g.title ).toLowerCase();
				return words.every( function ( w ) { return hay.indexOf( w ) > -1; } );
			} );
			if ( ! items.length ) { return; }
			total += items.length;
			var all = !! state.cheatOpen[ g.id ] || ( words.length > 0 && items.length <= 80 );
			var shown = all ? items : items.slice( 0, 24 );
			html += '<section class="tf-panel tf-cheat-group"><div class="tf-panel-head tf-cheat-head"><h3>' + esc( g.title ) + ' <span class="tf-count">' + items.length + '</span></h3></div>'
				+ '<div class="tf-panel-body"><p class="tf-muted tf-desc">' + esc( g.desc ) + '</p><div class="tf-cheat-list">' + shown.map( cheatRow ).join( '' ) + '</div>'
				+ ( items.length > shown.length ? '<p><button type="button" class="tf-btn tf-small" data-act="cheatmore" data-g="' + esc( g.id ) + '">Show all ' + items.length + '</button></p>' : '' ) + '</div></section>';
		} );
		return html + ( total ? '' : '<p class="tf-muted">Nothing matches.</p>' );
	}

	// ---- Keyboard shortcuts ("alt+shift+t") ----
	function keyLabel( s ) {
		if ( ! s ) { return ''; }
		return String( s ).split( '+' ).map( function ( p ) {
			return { ctrl: 'Ctrl', alt: 'Alt', shift: 'Shift', meta: 'Cmd' }[ p ] || p.toUpperCase();
		} ).join( '+' );
	}
	function comboFromEvent( e ) {
		var c = e.code || '', key;
		if ( /^Key[A-Z]$/.test( c ) ) { key = c.slice( 3 ).toLowerCase(); }
		else if ( /^Digit\d$/.test( c ) ) { key = c.slice( 5 ); }
		else if ( /^F\d{1,2}$/.test( c ) ) { key = c.toLowerCase(); }
		else { key = String( e.key || '' ).toLowerCase(); }
		if ( ! key || /^(control|alt|shift|meta|os|altgraph|dead|unidentified)$/.test( key ) ) { return null; }
		var mods = [ e.ctrlKey && 'ctrl', e.altKey && 'alt', e.shiftKey && 'shift', e.metaKey && 'meta' ].filter( Boolean );
		if ( ! /^f\d/.test( key ) && ! e.ctrlKey && ! e.altKey && ! e.metaKey ) { return ''; } // would fire while typing
		return mods.concat( [ key ] ).join( '+' );
	}

	// ---- Recipes ----
	function recipesOn() { return !! ( state.recipes && state.recipes.enabled ) && false !== ( state.modules || {} ).recipes; }

	// ---- Custom panels ---------------------------------------------------
	// ---- Colour engine for the live preview: the same steps as Color_Engine::family() ----
	var TFC = ( function () {
		var SHADES = { 'ultra-light': 95, 'light': 85, 'semi-light': 65, 'semi-dark': 35, 'dark': 25, 'ultra-dark': 10 };
		var MIRROR = { 'ultra-light': 'ultra-dark', 'light': 'dark', 'semi-light': 'semi-dark', 'semi-dark': 'semi-light', 'dark': 'light', 'ultra-dark': 'ultra-light' };
		var STATUS = [ 'success', 'danger', 'warning', 'info' ];
		var BRAND = [ 'primary', 'secondary', 'tertiary', 'accent', 'base' ];
		function rgb( hex ) {
			hex = String( hex || '' ).trim().replace( /^#/, '' );
			if ( 3 === hex.length ) { hex = hex.replace( /(.)/g, '$1$1' ); }
			return /^[0-9a-f]{6}$/i.test( hex ) ? [ parseInt( hex.slice( 0, 2 ), 16 ), parseInt( hex.slice( 2, 4 ), 16 ), parseInt( hex.slice( 4, 6 ), 16 ) ] : null;
		}
		function hsl( r, g, b ) {
			r /= 255; g /= 255; b /= 255;
			var mx = Math.max( r, g, b ), mn = Math.min( r, g, b ), l = ( mx + mn ) / 2, d = mx - mn, h = 0, s = 0;
			if ( d ) {
				s = d / ( 1 - Math.abs( 2 * l - 1 ) );
				if ( mx === r ) { h = 60 * ( ( ( g - b ) / d ) % 6 ); } else if ( mx === g ) { h = 60 * ( ( b - r ) / d + 2 ); } else { h = 60 * ( ( r - g ) / d + 4 ); }
			}
			if ( h < 0 ) { h += 360; }
			return [ Math.round( h ), Math.round( s * 100 ), Math.round( l * 100 ) ];
		}
		function hslHex( h, s, l ) {
			s /= 100; l /= 100;
			var c = ( 1 - Math.abs( 2 * l - 1 ) ) * s, x = c * ( 1 - Math.abs( ( h / 60 ) % 2 - 1 ) ), m = l - c / 2, p;
			if ( h < 60 ) { p = [ c, x, 0 ]; } else if ( h < 120 ) { p = [ x, c, 0 ]; } else if ( h < 180 ) { p = [ 0, c, x ]; } else if ( h < 240 ) { p = [ 0, x, c ]; } else if ( h < 300 ) { p = [ x, 0, c ]; } else { p = [ c, 0, x ]; }
			return '#' + p.map( function ( v ) { return ( '0' + Math.round( ( v + m ) * 255 ).toString( 16 ) ).slice( -2 ); } ).join( '' );
		}
		function lin( c ) { return c <= 0.04045 ? c / 12.92 : Math.pow( ( c + 0.055 ) / 1.055, 2.4 ); }
		function oklch( r, g, b ) {
			r = lin( r / 255 ); g = lin( g / 255 ); b = lin( b / 255 );
			var l = Math.cbrt( 0.4122214708 * r + 0.5363325363 * g + 0.0514459929 * b );
			var m = Math.cbrt( 0.2119034982 * r + 0.6806995451 * g + 0.1073969566 * b );
			var s = Math.cbrt( 0.0883024619 * r + 0.2817188376 * g + 0.6299787005 * b );
			var L = 0.2104542553 * l + 0.7936177850 * m - 0.0040720468 * s;
			var A = 1.9779984951 * l - 2.4285922050 * m + 0.4505937099 * s;
			var B = 0.0259040371 * l + 0.7827717662 * m - 0.8086757660 * s;
			var C = Math.sqrt( A * A + B * B );
			var H = C < 1e-7 ? 0 : ( Math.atan2( B, A ) * 180 / Math.PI + 360 ) % 360;
			return [ L, C, H ];
		}
		function inP3( L, C, H ) {
			var a = C * Math.cos( H * Math.PI / 180 ), bb = C * Math.sin( H * Math.PI / 180 );
			var l = Math.pow( L + 0.3963377774 * a + 0.2158037573 * bb, 3 );
			var m = Math.pow( L - 0.1055613458 * a - 0.0638541728 * bb, 3 );
			var s = Math.pow( L - 0.0894841775 * a - 1.2914855480 * bb, 3 );
			var r = 4.0767416621 * l - 3.3077115913 * m + 0.2309699292 * s;
			var g = -1.2684380046 * l + 2.6097574011 * m - 0.3413193965 * s;
			var b = -0.0041960863 * l - 0.7034186147 * m + 1.7076147010 * s;
			var p3 = [ 0.8225927332 * r + 0.1775339606 * g, 0.0331995967 * r + 0.9667835426 * g, 0.0170853336 * r + 0.0723957193 * g + 0.9103014471 * b ];
			return p3.every( function ( v ) { return v >= -0.000001 && v <= 1.000001; } );
		}
		function fit( L, C, H ) {
			if ( C <= 0 || inP3( L, C, H ) ) { return Math.max( 0, C ); }
			var lo = 0, hi = C;
			for ( var i = 0; i < 32; i++ ) { var mid = ( lo + hi ) / 2; if ( inP3( L, mid, H ) ) { lo = mid; } else { hi = mid; } }
			return lo;
		}
		function hslL( h, s, l ) { var c = rgb( hslHex( h, s, Math.max( 0, Math.min( 100, l ) ) ) ); return oklch( c[0], c[1], c[2] )[0]; }
		function r3( v ) { return Math.floor( v * 1000 + 0.5 ) / 1000; }
		function rn( v, d ) { var f = Math.pow( 10, d ); return Math.round( v * f ) / f; }
		function css( L, C, H ) {
			var n = function ( v, d ) { var s = Number( v ).toFixed( d ).replace( /0+$/, '' ).replace( /\.$/, '' ); return '-0' === s || '' === s ? '0' : s; };
			return 'oklch(' + n( L, 6 ) + ' ' + n( C, 3 ) + ' ' + n( H, 2 ) + ')';
		}
		function num( v ) { v = String( v == null ? '' : v ).trim(); return '' !== v && isFinite( v ) ? parseFloat( v ) : null; }
		// { light: { '': css, hover, shades… }, dark: { … } } or null.
		function family( name, hex, opt ) {
			var c = rgb( hex );
			if ( ! c ) { return null; }
			opt = opt || {};
			var hs = hsl( c[0], c[1], c[2] ), ok0 = oklch( c[0], c[1], c[2] );
			var H = Math.round( ok0[2] ), C = rn( ok0[1], 3 );
			var shades = {};
			Object.keys( SHADES ).forEach( function ( k ) { shades[ k ] = SHADES[ k ]; } );
			if ( STATUS.indexOf( name ) > -1 ) { shades.dark = 15; }
			var baseL = null == opt.lightness ? rn( ok0[0], 3 ) : opt.lightness;
			var ok = {};
			ok[''] = [ baseL, fit( baseL, C, H ), H ];
			var hl = r3( baseL * 1.15 <= 0.98 ? baseL * 1.15 : baseL * 0.85 );
			ok.hover = [ hl, fit( hl, ok[''][1], H ), H ];
			Object.keys( shades ).forEach( function ( k ) { var L = rn( hslL( hs[0], hs[1], shades[ k ] ), 6 ); ok[ k ] = [ L, fit( L, ok[''][1], H ), H ]; } );
			var light = {};
			Object.keys( ok ).forEach( function ( k ) { light[ k ] = css( ok[ k ][0], ok[ k ][1], ok[ k ][2] ); } );
			var dm = ok[''], dh = ok.hover, a = rgb( opt.alt ), changed = false;
			if ( a ) {
				var ao = oklch( a[0], a[1], a[2] ), aL = rn( ao[0], 3 ), aH = Math.round( ao[2] ), ahl = r3( aL * 1.15 <= 0.98 ? aL * 1.15 : aL * 0.85 );
				dm = [ aL, fit( aL, rn( ao[1], 3 ), aH ), aH ];
				dh = [ ahl, fit( ahl, rn( ao[1], 3 ), aH ), aH ];
				changed = true;
			}
			var al = num( opt.alt_l ), ac = num( opt.alt_c ), hlo = num( opt.hover_l ), hco = num( opt.hover_c );
			if ( null !== al || null !== ac ) {
				var L1 = null !== al ? al : dm[0];
				dm = [ L1, fit( L1, null !== ac ? ac : dm[1], dm[2] ), dm[2] ];
				var hl1 = r3( L1 * 1.15 <= 0.98 ? L1 * 1.15 : L1 * 0.85 );
				dh = [ hl1, fit( hl1, dm[1], dm[2] ), dm[2] ];
				changed = true;
			}
			if ( null !== hlo || null !== hco ) {
				var L2 = null !== hlo ? hlo : dh[0];
				dh = [ L2, fit( L2, null !== hco ? hco : dh[1], dh[2] ), dh[2] ];
				changed = true;
			}
			var dark = { '': changed ? css( dm[0], dm[1], dm[2] ) : light[''], hover: changed ? css( dh[0], dh[1], dh[2] ) : light.hover };
			Object.keys( MIRROR ).forEach( function ( k ) { dark[ k ] = light[ MIRROR[ k ] ]; } );
			return { light: light, dark: dark };
		}
		// Engine options of a family from the palette settings (unified lightness, dark mode).
		function options( name, pal ) {
			var f = ( pal.families || {} )[ name ] || {};
			var unify = ( pal.unify_brand && BRAND.indexOf( name ) > -1 ) || ( pal.unify_status && STATUS.indexOf( name ) > -1 );
			return { lightness: unify ? parseFloat( pal.lightness ) : null, alt: f.alt, alt_l: f.alt_l, alt_c: f.alt_c, hover_l: f.hover_l, hover_c: f.hover_c };
		}
		return { family: family, options: options };
	} )();

	var CUSTOM = {
		recipeList: function () {
			var q = ( state.recipeQ || '' ).toLowerCase().replace( /^\?/, '' ), groups = {};
			( state.recipeList || [] ).forEach( function ( r ) {
				if ( q && r.n.indexOf( q ) === -1 && r.c.toLowerCase().indexOf( q ) === -1 ) { return; }
				( groups[ r.c ] = groups[ r.c ] || [] ).push( r );
			} );
			var html = '<input type="search" class="tf-search tf-recipe-q" placeholder="Filter recipes…" value="' + esc( state.recipeQ || '' ) + '">';
			Object.keys( groups ).forEach( function ( g ) {
				html += '<h4 class="tf-recipe-group">' + esc( g ) + ' <span class="tf-count">' + groups[ g ].length + '</span></h4><div class="tf-recipes">';
				groups[ g ].forEach( function ( r ) {
					html += '<details class="tf-recipe-item"><summary><code>?' + esc( r.n ) + '</code>' + ( r.inline ? '<span class="tf-muted"> value</span>' : '' ) + '</summary><pre>' + esc( String( r.code ).replace( '{{cursor}}', '' ) ) + '</pre>' + '</details>';
				} );
				html += '</div>';
			} );
			return html;
		},
		palette: function () {
			var pal = state.palette || {}, list = pal.families || {}, orig = ( state.paletteOrig || {} ).families || {};
			var order = [ 'ultra-light', 'light', 'semi-light', '', 'hover', 'semi-dark', 'dark', 'ultra-dark' ];
			var out = CUSTOM.newFamily() + '<div class="tf-fams">';
			Object.keys( list ).forEach( function ( k ) {
				var f = list[ k ], o = orig[ k ] || {}, std = ( state.families || [] ).indexOf( k ) > -1;
				var has = !! ( tokenMap()[ k ] || tokenMap()[ k + '-dark' ] );
				var pending = f.hex && ( f.hex !== ( o.hex || '' ) || [ 'alt', 'alt_l', 'alt_c', 'hover_l', 'hover_c' ].some( function ( x ) { return String( f[ x ] || '' ) !== String( o[ x ] || '' ); } ) );
				var pv = f.hex ? TFC.family( k, f.hex, TFC.options( k, pal ) ) : null;
				var pick = function ( key, fallback ) { return '<input type="color" data-pf="' + key + '" value="' + esc( f[ key ] || fallback ) + '" aria-label="' + esc( k + ' ' + key ) + '">'; };
				var cur = resolveColor( 'var(--' + k + '-hex)' ) || '';
				out += '<div class="tf-fam' + ( f.on ? '' : ' is-off' ) + ( pending ? ' is-pending' : '' ) + '" data-fam="' + esc( k ) + '">'
					+ '<span class="tf-switch" title="' + esc( tipRaw( 'palette.fam_on' ) ) + '"><input type="checkbox" data-pf="on"' + ( f.on ? ' checked' : '' ) + ' aria-label="' + esc( k ) + ' on/off"><i></i></span>'
					+ '<strong class="tf-fam-name">' + esc( k ) + '</strong>'
					+ '<div class="tf-shades">' + shadeStrips( k, has, pending, pv, order ) + '</div>'
					+ '<label class="tf-fam-col"><span class="tf-flabel">Base' + tipIcon( tipRaw( 'palette.fam_hex' ) ) + '</span><span class="tf-colorfield">' + pick( 'hex', cur && isHex( cur ) ? cur : '#888888' ) + '<input type="text" data-pf="hex" value="' + esc( f.hex ) + '" placeholder="#rrggbb" spellcheck="false"></span></label>'
					+ '<label class="tf-fam-col"><span class="tf-flabel">Dark mode' + tipIcon( tipRaw( 'palette.fam_alt' ) ) + '</span><span class="tf-colorfield">' + pick( 'alt', f.hex || '#888888' ) + '<input type="text" data-pf="alt" value="' + esc( f.alt ) + '" placeholder="same" spellcheck="false"></span></label>'
					+ '<details class="tf-fam-more"' + ( [ 'alt_l', 'alt_c', 'hover_l', 'hover_c' ].some( function ( x ) { return '' !== String( f[ x ] || '' ); } ) ? ' open' : '' ) + '><summary>Dark mode fine tuning' + tipIcon( tipRaw( 'palette.fam_tune' ) ) + '</summary><div class="tf-fields">'
						+ [ [ 'alt_l', 'Main: lightness', '0 to 1' ], [ 'alt_c', 'Main: chroma', '0 to 0.4' ], [ 'hover_l', 'Hover: lightness', '0 to 1' ], [ 'hover_c', 'Hover: chroma', '0 to 0.4' ] ].map( function ( x ) { return '<label class="tf-field"><span class="tf-flabel">' + x[1] + '</span><input type="text" data-pt="' + x[0] + '" value="' + esc( f[ x[0] ] || '' ) + '" placeholder="' + x[2] + '" spellcheck="false"></label>'; } ).join( '' )
						+ '</div></details>'
					+ '<span class="tf-fam-end"><span class="tf-fam-badge">regenerates on Save</span>' + ( std ? '' : '<button type="button" class="tf-btn tf-small" data-act="rmfam" data-fam="' + esc( k ) + '" title="Remove this family and its variables">Remove</button>' ) + '</span>'
					+ '</div>';
			} );
			out += '</div>';
			return out;
		},
		fontList: function () {
			var list = ( state.fonts || {} ).fonts || [];
			var tipF = function ( k ) { return tipIcon( tipRaw( 'font.' + k ) ); };
			var txt = function ( f, k, label, ph ) { return '<label class="tf-field"><span class="tf-flabel">' + label + tipF( k ) + '</span><input type="text" data-font="' + k + '" value="' + esc( f[ k ] ) + '" placeholder="' + esc( ph || '' ) + '" spellcheck="false"></label>'; };
			var sel = function ( f, k, label, opts ) { return '<label class="tf-field"><span class="tf-flabel">' + label + tipF( k ) + '</span><select data-font="' + k + '">' + opts.map( function ( o ) { return '<option value="' + o + '"' + ( f[ k ] === o ? ' selected' : '' ) + '>' + o + '</option>'; } ).join( '' ) + '</select></label>'; };
			var sw = function ( f, k, label ) { return '<label class="tf-field tf-switchrow"><span class="tf-switch"><input type="checkbox" data-font="' + k + '"' + ( f[ k ] ? ' checked' : '' ) + '><i></i></span><span class="tf-flabel">' + label + tipF( k ) + '</span></label>'; };
			var file = function ( f, k, label ) {
				return '<label class="tf-field tf-wide"><span class="tf-flabel">' + label + tipF( k ) + '</span><span class="tf-fontfile"><input type="text" data-font="' + k + '" value="' + esc( f[ k ] ) + '" placeholder="Font file address ending in .woff2" spellcheck="false">'
					+ '<span class="tf-btn tf-small tf-upload">Upload<input type="file" accept=".woff2,.woff,.ttf,.otf" class="tf-font-file" data-slot="' + k + '"></span></span></label>';
			};
			var g = state.gfont || ( state.gfont = { family: '', weights: '400, 700', italic: false } );
			var html = '<div class="tf-preset tf-gfont"><div class="tf-preset-head"><strong>Download from Google Fonts</strong>' + tipF( 'google' ) + '</div><div class="tf-fields">'
				+ '<label class="tf-field"><span class="tf-flabel">Family</span><input type="text" data-gfont="family" value="' + esc( g.family ) + '" placeholder="Inter" spellcheck="false"></label>'
				+ '<label class="tf-field"><span class="tf-flabel">Weights</span><input type="text" data-gfont="weights" value="' + esc( g.weights ) + '" placeholder="400, 700" spellcheck="false"></label>'
				+ '<label class="tf-field tf-switchrow"><span class="tf-switch"><input type="checkbox" data-gfont="italic"' + ( g.italic ? ' checked' : '' ) + '><i></i></span><span class="tf-flabel">Italic too</span></label>'
				+ '</div><p><button type="button" class="tf-btn" data-act="gfont">Download to this site</button> <span class="tf-muted">The files are copied here once; visitors never contact Google.</span></p></div>';
			html += '<p><button type="button" class="tf-btn" data-act="fontadd">+ Add font</button> <span class="tf-muted">One entry per file (e.g. regular and italic). .woff2 is the smallest; a variable font covers every weight in one file.</span></p>';
			list.forEach( function ( f, i ) {
				html += '<div class="tf-preset tf-font" data-i="' + i + '"><div class="tf-preset-head"><strong class="tf-font-name">' + esc( f.family || 'Font ' + ( i + 1 ) ) + '</strong>'
					+ '<span class="tf-font-sample" style="font-family:&quot;tf-preview-' + i + '&quot;, var(--tf-font, system-ui);font-weight:' + esc( String( f.weight || '400' ).split( ' ' )[0] ) + ';font-style:' + esc( f.style || 'normal' ) + '">' + ( f.src ? 'Aa Bb Cc 0123 The quick brown fox' : 'Upload a file to see a sample' ) + '</span>'
					+ '<button type="button" class="tf-btn tf-small" data-act="fontdel" data-i="' + i + '">Remove</button></div><div class="tf-fields">'
					+ txt( f, 'family', 'Family name', 'Inter' ) + txt( f, 'weight', 'Weight', '400 (variable: 100 900)' ) + sel( f, 'style', 'Style', [ 'normal', 'italic', 'oblique' ] ) + sel( f, 'display', 'Font display', [ 'swap', 'fallback', 'optional', 'block', 'auto' ] )
					+ file( f, 'src', 'Font file' ) + file( f, 'src2', 'Fallback file (optional)' )
					+ sw( f, 'variable', 'Variable font' ) + ( f.variable ? txt( f, 'stretch', 'Stretch', '75% 125%' ) + txt( f, 'variation', 'Variation settings', '"wght" 400' ) : '' )
					+ sw( f, 'preload', 'Preload' ) + txt( f, 'unicode', 'Characters (optional)', 'U+0000-00FF, U+0131' )
					+ '</div></div>';
			} );
			fontPreviews();
			return html;
		},
		// Your own family next to the standard ones (e.g. "brand" → --brand, --brand-light …).
		newFamily: function () {
			var html = '<h4>Add a family</h4>';
			html += '<div class="tf-fields"><label class="tf-field"><span class="tf-flabel">Name</span><input type="text" class="tf-color-name" placeholder="e.g. brand"></label>';
			html += '<label class="tf-field"><span class="tf-flabel">Base colour</span><span class="tf-colorfield"><input type="color" class="tf-color-pick" value="#3267c1"><input type="text" class="tf-color-hex" value="#3267c1"></span></label></div>';
			html += '<p><button type="button" class="tf-btn tf-primary" data-act="gencolor">Add family</button></p>';
			return html;
		},
		// The saved colours measured on the server (Contrast::report()); fails first, the rest folded.
		contrast: function () {
			var c = state.contrast;
			if ( ! c || ! c.rows ) { return ''; }
			var fails = [], oks = [], open = cxOpen(), picked = open.filter( function ( i ) { return c.rows[ i ].sel; } );
			c.rows.forEach( function ( r, i ) { ( r.pass ? oks : fails ).push( i ); } );
			var head = function ( pick ) {
				return '<thead><tr><th scope="col">' + esc( __( 'Where', 'tokenforge' ) ) + '</th><th scope="col">' + esc( __( 'What', 'tokenforge' ) ) + '</th><th scope="col">' + esc( c.dark ? __( 'Light', 'tokenforge' ) : __( 'Contrast', 'tokenforge' ) ) + '</th>' + ( c.dark ? '<th scope="col">' + esc( __( 'Dark', 'tokenforge' ) ) + '</th>' : '' ) + '<th scope="col">'
					+ ( pick && open.length ? '<input type="checkbox" data-cxsel="all"' + ( picked.length === open.length ? ' checked' : '' ) + ' aria-label="' + esc( __( 'Select every fix', 'tokenforge' ) ) + '"> ' : '' ) + esc( __( 'Fix', 'tokenforge' ) ) + '</th></tr></thead>';
			};
			var ratios = function ( modes ) { return modes.map( function ( m ) { return m.ratio + ':1'; } ).join( ' / ' ); };
			var table = function ( list, pick ) {
				return '<div class="tf-tablewrap"><table class="tf-table">' + head( pick ) + '<tbody>' + list.map( function ( i ) {
					var r = c.rows[ i ], fix;
					var other = r.pass || r.applied ? '' : ' <button type="button" class="tf-btn tf-small" data-act="cxcustom" data-i="' + i + '" aria-expanded="' + ( state.cxEdit && state.cxEdit.i === i ? 'true' : 'false' ) + '" aria-label="' + esc( __( 'Choose another colour for %s', 'tokenforge' ).replace( '%s', r.where + ' ' + r.what ) ) + '">' + esc( __( 'Another colour', 'tokenforge' ) ) + '</button>';
					if ( r.pass ) { fix = esc( __( 'Passes', 'tokenforge' ) ); }
					else if ( r.applied ) { fix = '<span tabindex="-1" data-cxdone="' + i + '">' + esc( __( 'Changed. Save to keep it.', 'tokenforge' ) ) + '</span>'; }
					else if ( r.fix ) { fix = '<span style="white-space:nowrap"><input type="checkbox" data-cxsel="' + i + '"' + ( r.sel ? ' checked' : '' ) + ' aria-label="' + esc( __( 'Select the fix for %s', 'tokenforge' ).replace( '%s', r.where + ' ' + r.what ) ) + '"> <button type="button" class="tf-btn tf-small" data-act="cxfix" data-i="' + i + '">' + esc( __( 'Use %s', 'tokenforge' ).replace( '%s', r.fix.value.replace( /^var\(--(.*)\)$/, '$1' ) ) ) + '</button></span> <span class="tf-muted">' + esc( ratios( r.fix.modes ) ) + '</span>' + other; }
					else { fix = '<span class="tf-muted">' + esc( __( 'No colour token passes. Choose a colour by hand.', 'tokenforge' ) ) + '</span>' + other; }
					return '<tr><td style="white-space:nowrap"><code>' + esc( r.where ) + '</code></td><td>' + esc( r.what ) + '<br><span class="tf-muted">' + esc( __( 'needs %s', 'tokenforge' ).replace( '%s', r.min + ':1' ) ) + '</span></td>' + r.modes.map( function ( m ) {
						return '<td style="white-space:nowrap"><span class="tf-cx" style="color:' + esc( m.fg ) + ';background:' + esc( m.bg ) + '" title="' + esc( m.fg + ' / ' + m.bg ) + '" aria-hidden="true">Aa</span> ' + esc( m.ratio + ':1' ) + ' <span class="tf-muted" title="APCA">Lc ' + Math.abs( m.apca ) + '</span></td>';
					} ).join( '' ) + '<td>' + fix + '</td></tr>' + ( ! r.pass && ! r.applied && state.cxEdit && state.cxEdit.i === i ? cxEditor( i, c.dark ? 5 : 4 ) : '' );
				} ).join( '' ) + '</tbody></table></div>';
			};
			var html = state.dirty ? '<p class="tf-notice">' + esc( __( 'These are the saved colours. Save to check your changes.', 'tokenforge' ) ) + '</p>' : '';
			if ( fails.length ) {
				html += '<p><strong>' + esc( __( '%1$d of %2$d pairs are below the minimum:', 'tokenforge' ).replace( '%1$d', fails.length ).replace( '%2$d', c.rows.length ) ) + '</strong></p>';
				if ( open.length ) {
					html += '<p><button type="button" class="tf-btn" data-act="cxfixall">' + esc( __( 'Use all %d fixes', 'tokenforge' ).replace( '%d', open.length ) ) + '</button> <button type="button" class="tf-btn" data-act="cxfixsel"' + ( picked.length ? '' : ' disabled' ) + '>' + esc( __( 'Use the selected fixes (%d)', 'tokenforge' ).replace( '%d', picked.length ) ) + '</button></p>';
				}
				html += table( fails, true );
			} else {
				html += '<p>' + esc( __( 'All %d pairs pass.', 'tokenforge' ).replace( '%d', c.rows.length ) ) + '</p>';
			}
			if ( fails.length && oks.length ) { html += '<details class="tf-report"><summary>' + esc( __( '%d pairs pass', 'tokenforge' ).replace( '%d', oks.length ) ) + '</summary>' + table( oks ) + '</details>'; }
			if ( ! fails.length && oks.length ) { html += table( oks ); }
			if ( c.skipped && c.skipped.length ) { html += '<p class="tf-muted">' + esc( __( 'Not measured (not a plain colour, for example color-mix()): %s', 'tokenforge' ).replace( '%s', c.skipped.join( ', ' ) ) ) + '</p>'; }
			return html;
		},
		relationships: function () {
			var cols = [ [ 'text', __( 'Text', 'tokenforge' ) ], [ 'heading', __( 'Headings', 'tokenforge' ) ], [ 'button', __( 'Button family', 'tokenforge' ) ], [ 'icon', __( 'Icons', 'tokenforge' ) ], [ 'link', __( 'Links', 'tokenforge' ) ], [ 'link_hover', __( 'Link hover', 'tokenforge' ) ], [ 'focus', __( 'Focus', 'tokenforge' ) ] ];
			var html = '<div class="tf-tablewrap"><table class="tf-grid-table"><thead><tr><th></th>' + cols.map( function ( c ) { return '<th>' + c[1] + tipIcon( ( window.TokenForgeTips ? window.TokenForgeTips.rel( c[0] ) : '' ) + ' inside each background.' ) + '</th>'; } ).join( '' ) + '</tr></thead><tbody>';
			CTX.forEach( function ( ctx ) {
				html += '<tr><th><code>.bg--' + ctx + '</code>' + tipIcon( 'What .bg--' + ctx + ' sets for everything inside it.' ) + '</th>' + cols.map( function ( c ) {
					var k = 'ctx_' + ctx.replace( '-', '_' ) + '_' + c[0];
					if ( c[0] === 'icon' ) {
						var v = state.scheme[ k ] || '';
						return '<td><select data-mod="scheme" data-k="' + k + '">' + [ [ '', __( 'default', 'tokenforge' ) ], [ 'light', __( 'light', 'tokenforge' ) ], [ 'dark', __( 'dark', 'tokenforge' ) ] ].map( function ( o ) { return '<option value="' + o[0] + '"' + ( v === o[0] ? ' selected' : '' ) + '>' + o[1] + '</option>'; } ).join( '' ) + '</select></td>';
					}
					return '<td>' + cell( 'scheme', k ) + '</td>';
				} ).join( '' ) + '</tr>';
			} );
			return html + '</tbody></table></div>';
		},
		levels: function () {
			var props = [ [ 'color', __( 'Colour', 'tokenforge' ) ], [ 'font_family', __( 'Family', 'tokenforge' ) ], [ 'font_weight', __( 'Weight', 'tokenforge' ) ], [ 'font_style', __( 'Style', 'tokenforge' ) ], [ 'line_height', __( 'Line height', 'tokenforge' ) ], [ 'letter_spacing', __( 'Spacing', 'tokenforge' ) ], [ 'text_transform', __( 'Transform', 'tokenforge' ) ], [ 'max_width', __( 'Max width', 'tokenforge' ) ] ];
			var html = '<div class="tf-tablewrap"><table class="tf-grid-table"><thead><tr><th></th>' + props.map( function ( p ) { return '<th>' + p[1] + tipIcon( ( window.TokenForgeTips ? window.TokenForgeTips.prop( p[0] ) : '' ) + ' of that heading level only. Empty = the shared heading setting.' ) + '</th>'; } ).join( '' ) + '</tr></thead><tbody>';
			for ( var i = 1; i <= 6; i++ ) {
				html += '<tr><th>H' + i + '</th>' + props.map( function ( p ) { return '<td>' + cell( 'components', 'h' + i + '_' + p[0], '' ) + '</td>'; } ).join( '' ) + '</tr>';
			}
			return html + '</tbody></table></div>';
		},
		sizes: function () {
			var props = [ [ 'font_weight', __( 'Weight', 'tokenforge' ) ], [ 'font_style', __( 'Style', 'tokenforge' ) ], [ 'line_height', __( 'Line height', 'tokenforge' ) ], [ 'letter_spacing', __( 'Spacing', 'tokenforge' ) ], [ 'text_transform', __( 'Transform', 'tokenforge' ) ], [ 'max_width', __( 'Max width', 'tokenforge' ) ] ];
			var html = '<div class="tf-tablewrap"><table class="tf-grid-table"><thead><tr><th></th>' + props.map( function ( p ) { return '<th>' + p[1] + tipIcon( ( window.TokenForgeTips ? window.TokenForgeTips.prop( p[0] ) : '' ) + ' of that text size class only. Empty = the body text setting.' ) + '</th>'; } ).join( '' ) + '</tr></thead><tbody>';
			SIZES.slice().reverse().forEach( function ( sz ) {
				html += '<tr><th><code>.text--' + sz + '</code></th>' + props.map( function ( p ) { return '<td>' + cell( 'components', 'text_' + sz + '_' + p[0], '' ) + '</td>'; } ).join( '' ) + '</tr>';
			} );
			return html + '</tbody></table></div>';
		},
		width: function () {
			var wd = state.width;
			return '<div class="tf-fields">' + fieldHtml( 'width', N( 'content_width', __( 'Content width (px)', 'tokenforge' ) ) )
				+ '<label class="tf-field"><span class="tf-flabel">= in rem' + tipIcon( tipRaw( 'width.content_rem' ) ) + '</span><input type="text" readonly class="tf-width-rem" value="' + esc( wd.content_width ? ( Math.round( wd.content_width / 16 * 10000 ) / 10000 ) + 'rem' : '' ) + '"></label>'
				+ fieldHtml( 'width', B( 'variables', __( 'Width variables (<code>--width-10…90</code>, <code>--width-xs…xxl</code>)', 'tokenforge' ) ) )
				+ fieldHtml( 'width', B( 'utilities', __( 'Width utilities (<code>.width--50</code>, <code>.content-width</code>…)', 'tokenforge' ) ) ) + '</div>'
				+ '<p class="tf-muted">Everything width-related derives from it: <code>--content-width</code>, the gutter-aware <code>--content-width-safe</code> and the width scale.</p>';
		},
		// The chosen family and variant as a real button: as it is, and hovered.
		btnPreview: function () {
			var f = state.btnFam, vr = state.btnVar, c = state.components || {};
			var ov = ( ( c.btn_overrides || {} )[ f ] || {} )[ vr ] || {}, def = btnDefault( f, vr );
			var col = function ( k ) { return resolveColor( ov[ k ] || def[ k ] ) || 'transparent'; };
			var clean = function ( v, d ) { v = resolveVars( String( v == null ? '' : v ).replace( /[;{}<>"]/g, '' ) ).trim(); return v || d; };
			var bw = 0 === vr.indexOf( 'outline' ) ? '2px' : clean( c.btn_border_width, '1px' );
			var base = 'display:inline-flex;align-items:center;justify-content:center;padding:' + clean( c.btn_padding_block, '.75em' ) + ' ' + clean( c.btn_padding_inline, '1.5em' ) + ';border-radius:' + clean( c.btn_radius, '6px' ) + ';font-weight:' + clean( c.btn_font_weight, '600' ) + ';line-height:' + clean( c.btn_line_height, '1.1' ) + ';text-transform:' + clean( c.btn_text_transform, 'none' ) + ';letter-spacing:' + clean( c.btn_letter_spacing, '0' ) + ';border:' + bw + ' solid ';
			var one = function ( label, hover ) { return '<span class="tf-btn-sample" style="' + esc( base + col( hover ? 'hover_border' : 'border' ) + ';background:' + col( hover ? 'hover' : 'bg' ) + ';color:' + col( hover ? 'hover_text' : 'text' ) ) + '">' + label + '</span>'; };
			return '<div class="tf-btn-preview">' + one( 'Button', false ) + one( 'Hovered', true ) + '<span class="tf-btn-sample is-focus" style="' + esc( base + col( 'border' ) + ';background:' + col( 'bg' ) + ';color:' + col( 'text' ) + ';outline:2px solid ' + col( 'focus' ) + ';outline-offset:2px' ) + '">Focused</span></div>';
		},
		btnOverrides: function () {
			var fams = ( state.families && state.families.length ? state.families : [ 'primary' ] );
			var variants = [ [ 'solid', __( 'Solid', 'tokenforge' ) ], [ 'dark', __( 'Dark', 'tokenforge' ) ], [ 'light', __( 'Light', 'tokenforge' ) ], [ 'outline', __( 'Outline', 'tokenforge' ) ], [ 'outline-dark', __( 'Outline dark', 'tokenforge' ) ], [ 'outline-light', __( 'Outline light', 'tokenforge' ) ] ];
			var props = [ [ 'bg', __( 'Background', 'tokenforge' ) ], [ 'text', __( 'Text', 'tokenforge' ) ], [ 'border', __( 'Border', 'tokenforge' ) ], [ 'hover', __( 'Background (hover)', 'tokenforge' ) ], [ 'hover_text', __( 'Text (hover)', 'tokenforge' ) ], [ 'hover_border', __( 'Border (hover)', 'tokenforge' ) ], [ 'focus', __( 'Focus ring', 'tokenforge' ) ] ];
			var f = state.btnFam, vr = state.btnVar;
			var ov = ( ( state.components.btn_overrides || {} )[ f ] || {} )[ vr ] || {};
			var def = btnDefault( f, vr );
			var count = 0; var all = state.components.btn_overrides || {};
			Object.keys( all ).forEach( function ( a ) { Object.keys( all[ a ] || {} ).forEach( function ( b ) { count += Object.keys( all[ a ][ b ] || {} ).length; } ); } );
			var html = '<div class="tf-fields"><label class="tf-field"><span class="tf-flabel">Family' + tipIcon( tipRaw( 'bo.family' ) ) + '</span><select class="tf-bo-fam">' + fams.map( function ( x ) { return '<option' + ( x === f ? ' selected' : '' ) + '>' + esc( x ) + '</option>'; } ).join( '' ) + '</select></label>';
			html += '<label class="tf-field"><span class="tf-flabel">Variant' + tipIcon( tipRaw( 'bo.variant' ) ) + '</span><select class="tf-bo-var">' + variants.map( function ( x ) { return '<option value="' + x[0] + '"' + ( x[0] === vr ? ' selected' : '' ) + '>' + x[1] + '</option>'; } ).join( '' ) + '</select></label></div>';
			html += CUSTOM.btnPreview();
			html += '<div class="tf-fields">' + props.map( function ( p ) {
				return '<label class="tf-field"><span class="tf-flabel">' + p[1] + tipIcon( tipRaw( 'bo.' + p[0] ) ) + '</span><span class="tf-colorfield">' + swatch( ov[ p[0] ] || def[ p[0] ] ) + '<input type="text" data-bo="' + p[0] + '" value="' + esc( ov[ p[0] ] || '' ) + '" placeholder="' + esc( def[ p[0] ] ) + '" spellcheck="false"></span></label>';
			} ).join( '' ) + '</div>';
			return html + '<p class="tf-muted">' + count + ' override' + ( count === 1 ? '' : 's' ) + ' across all families. Class: <code>.btn--' + esc( f ) + ( vr === 'dark' || vr === 'outline-dark' ? '-dark' : vr === 'light' || vr === 'outline-light' ? '-light' : '' ) + ( vr.indexOf( 'outline' ) === 0 ? '.btn--outline' : '' ) + '</code></p>';
		},
		overlayPresets: function () { return presetsHtml( 'overlays' ); },
		surfacePresets: function () { return presetsHtml( 'surfaces' ); },
		rootFont: function () {
			var d = state.defaults;
			return '<div class="tf-fields">' + fieldHtml( 'defaults', N( 'root_font_size', __( 'Root font size (%)', 'tokenforge' ), { step: 0.5 } ) )
				+ '<label class="tf-field"><span class="tf-flabel">1rem =' + tipIcon( tipRaw( 'defaults.root_px' ) ) + '</span><input type="text" readonly class="tf-root-px" value="' + esc( ( Math.round( 16 * ( parseFloat( d.root_font_size ) || 100 ) ) / 100 ) + 'px' ) + '"></label>'
				+ fieldHtml( 'defaults', B( 'output_root_font_size', __( 'Set html font size', 'tokenforge' ) ) ) + '</div>'
				+ '<p class="tf-muted">Every px → rem conversion uses this base.</p>';
		},
		blockquotes: function () {
			var keys = Object.keys( state.defs.content || state.content ).filter( function ( k ) { return k.indexOf( 'bq_' ) === 0 && k !== 'bq_auto' && k !== 'bq_exclude'; } );
			var fields = [ B( 'bq_auto', __( 'Every <code>&lt;blockquote&gt;</code> automatically', 'tokenforge' ) ), T( 'bq_exclude', __( 'Leave alone (selector)', 'tokenforge' ), { w: 1 } ) ].concat( keys.map( function ( k ) {
				var label = k.slice( 3 ).replace( /_/g, ' ' ).replace( 'text text ', 'text ' );
				label = label.charAt( 0 ).toUpperCase() + label.slice( 1 );
				return /color|background/.test( k ) ? C( k, label ) : T( k, label );
			} ) );
			return fieldsHtml( 'content', fields );
		},
		native: function () {
			var keys = Object.keys( state.nativeLabels || {} );
			if ( ! keys.length ) { return '<p class="tf-muted">Not available.</p>'; }
			return '<div class="tf-fields">' + keys.map( function ( k ) {
				return '<label class="tf-field tf-switchrow tf-wide"><span class="tf-switch"><input type="checkbox" data-native="' + esc( k ) + '"' + ( state.bricksNative[ k ] ? ' checked' : '' ) + '><i></i></span><span class="tf-flabel">' + esc( state.nativeLabels[ k ] ) + tipIcon( tipRaw( 'native.' + k ) ) + '</span></label>';
			} ).join( '' ) + '</div>';
		},
		exportBox: function () {
			return '<p><button type="button" class="tf-btn tf-primary" data-act="export">Download export file</button></p>';
		},
		importBox: function () {
			var f = state.transferFile, html = '<p><label class="tf-btn tf-upload">Choose export file<input type="file" accept=".json,application/json" class="tf-import-file"></label> ' + ( f ? '<span class="tf-muted">' + esc( state.transferName ) + ( f.site ? ', from ' + esc( f.site ) : '' ) + ( f.exported ? ', ' + esc( String( f.exported ).slice( 0, 10 ) ) : '' ) + '</span>' : '' ) + '</p>';
			if ( f ) {
				var labels = { tokens: 'Tokens', palette: 'Colour families', settings: 'All settings', modules: 'Module switches', recipes: 'Recipe settings', fonts: 'Fonts' };
				html += '<div class="tf-fields">' + Object.keys( labels ).filter( function ( k ) { return f.data && f.data[ k ]; } ).map( function ( k ) {
					return '<label class="tf-field tf-switchrow"><span class="tf-switch"><input type="checkbox" data-part="import" data-p="' + k + '"' + ( false !== state.transferParts[ k ] ? ' checked' : '' ) + '><i></i></span><span class="tf-flabel">' + labels[ k ] + '</span></label>';
				} ).join( '' ) + '</div><p><button type="button" class="tf-btn tf-primary" data-act="importrun">Import</button> <button type="button" class="tf-btn" data-act="importclear">Cancel</button></p>';
			}
			return html;
		},
		tokenFiles: function () {
			var t = state.tok || {}, p = t.plan, x = t.exported;
			var list = function ( rows, show ) {
				return rows.length ? '<ul>' + rows.slice( 0, 200 ).map( show ).join( '' ) + ( rows.length > 200 ? '<li>…</li>' : '' ) + '</ul>' : '';
			};
			var html = '<p><button type="button" class="tf-btn tf-primary" data-act="tokexport">' + esc( __( 'Download token files', 'tokenforge' ) ) + '</button> ' + tipIcon( tipRaw( 'tokens.export' ) ) + '</p>';
			if ( x ) {
				html += '<details class="tf-report"><summary>' + esc( __( '%1$s tokens exported, %2$s left out', 'tokenforge' ).replace( '%1$s', x.count ).replace( '%2$s', x.skipped.length ) ) + '</summary>' + list( x.skipped, function ( s ) { return '<li><code>' + esc( s[0] ) + '</code>: ' + esc( s[1] ) + '</li>'; } ) + '</details>';
			}
			html += '<p><label class="tf-btn tf-upload">' + esc( __( 'Choose tokens file', 'tokenforge' ) ) + '<input type="file" accept=".json,application/json" class="tf-tokfile" data-which="light"></label> <label class="tf-btn tf-upload">' + esc( __( 'Choose dark file (optional)', 'tokenforge' ) ) + '<input type="file" accept=".json,application/json" class="tf-tokfile" data-which="dark"></label> ' + tipIcon( tipRaw( 'tokens.import' ) )
				+ ( t.light || t.dark ? ' <span class="tf-muted">' + esc( [ t.light && t.light.name, t.dark && t.dark.name ].filter( Boolean ).join( ', ' ) ) + '</span>' : '' ) + '</p>';
			if ( p && ! p.ok ) { html += '<p class="tf-err" role="alert">' + esc( p.error ) + '</p>'; }
			if ( p && p.ok ) {
				var by = function ( s ) { return p.rows.filter( function ( r ) { return r.status === s; } ); };
				var c = p.counts, n = c['new'] + c.changed;
				html += '<div class="tf-report" aria-live="polite"><p><strong>' + esc( __( '%1$s new, %2$s changed, %3$s unchanged, %4$s skipped', 'tokenforge' ).replace( '%1$s', c['new'] ).replace( '%2$s', c.changed ).replace( '%3$s', c.unchanged ).replace( '%4$s', c.skipped ) ) + '</strong></p>'
					+ ( c.changed ? '<h4>' + esc( __( 'Changed (now → file)', 'tokenforge' ) ) + '</h4>' + list( by( 'changed' ), function ( r ) { return '<li><code>--' + esc( r.name ) + '</code>: <code>' + esc( r.old ) + '</code> → <code>' + esc( r['new'] ) + '</code>' + ( r.note ? ' <em>' + esc( r.note ) + '</em>' : '' ) + '</li>'; } ) : '' )
					+ ( c['new'] ? '<h4>' + esc( __( 'New', 'tokenforge' ) ) + '</h4>' + list( by( 'new' ), function ( r ) { return '<li><code>--' + esc( r.name ) + '</code>: <code>' + esc( r['new'] ) + '</code>' + ( r.note ? ' <em>' + esc( r.note ) + '</em>' : '' ) + '</li>'; } ) : '' )
					+ ( c.skipped ? '<h4>' + esc( __( 'Skipped', 'tokenforge' ) ) + '</h4>' + list( by( 'skipped' ), function ( r ) { return '<li><code>' + esc( r.name ) + '</code>: ' + esc( r.note ) + '</li>'; } ) : '' ) + '</div>';
				html += '<p>' + ( n ? '<button type="button" class="tf-btn tf-primary" data-act="tokapply">' + esc( __( 'Apply %s changes', 'tokenforge' ).replace( '%s', n ) ) + '</button> ' : '<span class="tf-muted">' + esc( __( 'Nothing to change.', 'tokenforge' ) ) + '</span> ' ) + '<button type="button" class="tf-btn" data-act="tokclear">' + esc( __( 'Cancel', 'tokenforge' ) ) + '</button></p>';
			}
			return html;
		},
		resetBox: function () {
			var parts = [ [ 'settings', 'All settings', 'every switch and value' ], [ 'tokens', 'Tokens and colour families', 'the starter colours' ], [ 'fonts', 'Font list', 'files stay in uploads' ] ];
			return '<div class="tf-fields">' + parts.map( function ( p ) {
				return '<label class="tf-field tf-switchrow"><span class="tf-switch"><input type="checkbox" data-part="reset" data-p="' + p[0] + '"' + ( state.resetParts[ p[0] ] ? ' checked' : '' ) + '><i></i></span><span class="tf-flabel">' + p[1] + ' <span class="tf-muted">(' + p[2] + ')</span></span></label>';
			} ).join( '' ) + '</div><p><button type="button" class="tf-btn tf-danger" data-act="resetrun">Reset to defaults</button></p>';
		},
		historyBox: function () {
			var list = state.history || [];
			if ( ! list.length ) { return '<p class="tf-muted">' + esc( __( 'Nothing yet. The first copy is taken at your next Save.', 'tokenforge' ) ) + '</p>'; }
			var why = { save: __( 'Before a save', 'tokenforge' ), 'import': __( 'Before an import', 'tokenforge' ), reset: __( 'Before a reset', 'tokenforge' ), setup: __( 'Before the setup wizard', 'tokenforge' ), restore: __( 'Before a restore', 'tokenforge' ) };
			var d = state.historyDiff;
			return '<div class="tf-tablewrap"><table class="tf-table"><thead><tr><th scope="col">' + esc( __( 'When', 'tokenforge' ) ) + '</th><th scope="col">' + esc( __( 'What', 'tokenforge' ) ) + '</th><th scope="col">' + esc( __( 'Who', 'tokenforge' ) ) + '</th><th scope="col"></th></tr></thead><tbody>' + list.map( function ( h ) {
				var row = '<tr><td>' + esc( new Date( h.time * 1000 ).toLocaleString() ) + '</td><td>' + esc( why[ h.trigger ] || h.trigger ) + '</td><td>' + esc( h.user ) + '</td><td style="white-space:nowrap"><button type="button" class="tf-btn tf-small" data-act="histdiff" data-id="' + h.id + '" aria-expanded="' + ( d && d.id === h.id ? 'true' : 'false' ) + '">' + esc( __( 'What changed', 'tokenforge' ) ) + '</button> <button type="button" class="tf-btn tf-small" data-act="histrestore" data-id="' + h.id + '">' + esc( __( 'Restore', 'tokenforge' ) ) + '</button></td></tr>';
				if ( d && d.id === h.id ) {
					row += '<tr><td colspan="4">' + ( ! d.changes ? esc( __( 'Loading…', 'tokenforge' ) ) : ! d.total ? '<span class="tf-muted">' + esc( __( 'Nothing changed since then.', 'tokenforge' ) ) + '</span>'
						: '<div class="tf-report"><p>' + esc( __( 'Changed since then: %s (then → now)', 'tokenforge' ).replace( '%s', d.total ) ) + '</p><ul>' + d.changes.map( function ( c ) {
							return '<li><code>' + esc( c[0] ) + '</code>: ' + ( null === c[1] ? '<em>' + esc( __( 'not set', 'tokenforge' ) ) + '</em>' : '<code>' + esc( c[1] ) + '</code>' ) + ' → ' + ( null === c[2] ? '<em>' + esc( __( 'not set', 'tokenforge' ) ) + '</em>' : '<code>' + esc( c[2] ) + '</code>' ) + '</li>';
						} ).join( '' ) + ( d.total > d.changes.length ? '<li>…</li>' : '' ) + '</ul></div>' ) + '</td></tr>';
				}
				return row;
			} ).join( '' ) + '</tbody></table></div>';
		},
		themeSync: function () {
			var t = state.theme || {};
			if ( ! t.bricks ) { return ''; }
			var html = t.style ? '<p>Linked to the theme style <strong>' + esc( t.label || t.style ) + '</strong>.</p>' : '<p class="tf-muted">No theme style linked yet.</p>';
			return html + '<p><button type="button" class="tf-btn" data-act="themesetup">' + ( t.style ? 'Sync now' : 'Link or create the theme style' ) + '</button></p>';
		},
		importData: function () {
			var html = '<p><button type="button" class="tf-btn tf-primary" data-act="importacss">Import from Automatic.css</button></p>';
			html += '<p class="tf-muted">Brings over that plugin\'s <strong>variables</strong> and <strong>settings</strong> (scales, layout, typography, buttons, cards, icons, colours, effects, builder options…), even after it was deactivated.</p>';
			if ( state.importReport ) {
				html += '<details class="tf-report" open><summary>Last import: ' + state.importReport.mapped + ' settings mapped</summary>';
				Object.keys( state.importReport.report || {} ).forEach( function ( mod ) {
					html += '<h4>' + esc( mod ) + '</h4><ul>' + state.importReport.report[ mod ].map( function ( r ) { return '<li><code>' + esc( r ) + '</code></li>'; } ).join( '' ) + '</ul>';
				} );
				html += '</details>';
			}
			return html;
		},
		wooStatus: function () {
			var w = state.wooInfo || {};
			return '<p>' + ( w.active ? 'WooCommerce ' + esc( w.version || '' ) + ' is active: TokenForge adds the shop styles below.' : 'WooCommerce is not active: nothing is added.' ) + '</p>' + ( state.bricks ? '<p class="tf-muted">The builder already removes WooCommerce’s own stylesheets, so the switch above changes nothing on this site.</p>' : '' );
		},
		subsCleanup: function () {
			var c = state.components || {}, d = state.subsDry, out = '';
			if ( ! c.subs_cleanup ) { return ''; }
			out += '<p class="tf-muted">' + esc( __( 'Nothing is deleted until you have looked at a dry run of the rules and confirmed it. A change to the days (here or on a form) needs a new dry run.', 'tokenforge' ) ) + '</p>';
			out += '<p><button type="button" class="tf-btn" data-act="subsdry">' + esc( __( 'Dry run', 'tokenforge' ) ) + '</button></p>';
			if ( d ) {
				if ( ! d.items.length ) {
					out += '<p>' + esc( __( 'No form has a number of days set, so nothing would be deleted.', 'tokenforge' ) ) + '</p>';
				} else {
					out += '<div class="tf-tablewrap"><table class="tf-table"><thead><tr><th>' + esc( __( 'Form', 'tokenforge' ) ) + '</th><th>' + esc( __( 'Page', 'tokenforge' ) ) + '</th><th>' + esc( __( 'Days', 'tokenforge' ) ) + '</th><th>' + esc( __( 'Would delete now', 'tokenforge' ) ) + '</th><th>' + esc( __( 'Oldest', 'tokenforge' ) ) + '</th></tr></thead><tbody>';
					d.items.forEach( function ( i ) {
						out += '<tr><td>' + esc( i.form_name || i.form_id ) + ' <code>' + esc( i.form_id ) + '</code></td><td>' + esc( i.post_title || '' ) + '</td><td>' + esc( i.days ) + '</td><td>' + esc( i.count ) + '</td><td>' + esc( i.oldest ? i.oldest + ' UTC' : '' ) + '</td></tr>';
					} );
					out += '</tbody></table></div>';
					out += '<p>' + ( d.approved ? esc( __( 'These rules are confirmed: old submissions are deleted once a day.', 'tokenforge' ) ) : esc( __( 'Not confirmed yet: nothing is deleted.', 'tokenforge' ) ) ) + '</p>';
					out += '<p><button type="button" class="tf-btn" data-act="subsconfirm">' + esc( d.total ? _n( 'Delete this %s submission now and keep the rules', 'Delete these %s submissions now and keep the rules', d.total ) : __( 'Confirm the rules', 'tokenforge' ) ) + '</button></p>';
				}
				if ( d.last && d.last.time ) {
					out += '<p class="tf-muted">' + esc( d.last.waiting ? __( 'Last daily run %s: waiting for a confirmed dry run.', 'tokenforge' ).replace( '%s', new Date( d.last.time * 1000 ).toLocaleString() ) : __( 'Last run', 'tokenforge' ) + ' ' + new Date( d.last.time * 1000 ).toLocaleString() + ': ' + _n( '%s submission deleted.', '%s submissions deleted.', d.last.deleted ) ) + '</p>';
				}
			}
			return out;
		},
		utilCount: function () {
			var u = state.classUsage || {}, out = '<p class="tf-muted">' + __( 'Utility classes available: %s.', 'tokenforge' ).replace( '%s', '<strong>' + state.utilCount + '</strong>' ) + '</p>';
			if ( u.time ) {
				out += '<p class="tf-muted">' + esc( __( 'Last scan %1$s: %2$s of them used in %3$s pages, templates and other content.', 'tokenforge' ).replace( '%1$s', new Date( u.time * 1000 ).toLocaleString() ).replace( '%2$s', String( u.used ) ).replace( '%3$s', String( u.posts ) ) );
				if ( u.capped ) { out += ' ' + esc( __( 'The site has more content than one scan reads, so every class stays in.', 'tokenforge' ) ); }
				else if ( u.active ) { out += ' ' + esc( __( 'Utility part of the stylesheet: %1$s KB instead of %2$s KB.', 'tokenforge' ).replace( '%1$s', String( u.kept_kb ) ).replace( '%2$s', String( u.full_kb ) ) ); }
				out += '</p>';
			}
			if ( ( state.framework || {} ).prune_unused || u.time ) {
				out += '<p><button type="button" class="tf-btn" data-act="clsscan" title="' + esc( __( 'Read your pages, templates, menus, widgets and the builder\'s components for the utility classes and data attributes they use. New ones you add later join on their own when you save.', 'tokenforge' ) ) + '">' + esc( __( 'Scan now', 'tokenforge' ) ) + '</button></p>';
			}
			return out;
		}
	};

	// Default colour mapping of a button variant (mirrors Components::btn_default).
	function btnDefault( f, v ) {
		function c( sh ) { return 'var(--' + f + ( sh ? '-' + sh : '' ) + ')'; }
		switch ( v ) {
			case 'dark': return { bg: c( 'dark' ), text: c( 'ultra-light' ), border: c( 'dark' ), hover: c( 'ultra-dark' ), hover_text: c( 'ultra-light' ), hover_border: c( 'ultra-dark' ), focus: c( 'semi-dark' ) };
			case 'light': return { bg: c( 'ultra-light' ), text: c( '' ), border: c( 'ultra-light' ), hover: c( 'light' ), hover_text: c( 'ultra-dark' ), hover_border: c( 'light' ), focus: c( 'light' ) };
			case 'outline': return { bg: 'transparent', text: c( '' ), border: c( '' ), hover: c( 'hover' ), hover_text: c( 'ultra-light' ), hover_border: c( 'hover' ), focus: c( 'light' ) };
			case 'outline-dark': return { bg: 'transparent', text: c( 'dark' ), border: c( 'dark' ), hover: c( 'dark' ), hover_text: c( 'ultra-light' ), hover_border: c( 'dark' ), focus: c( 'light' ) };
			case 'outline-light': return { bg: 'transparent', text: c( 'light' ), border: c( 'light' ), hover: c( 'light' ), hover_text: c( 'dark' ), hover_border: c( 'light' ), focus: c( 'light' ) };
			default: return { bg: c( '' ), text: c( 'ultra-light' ), border: c( '' ), hover: c( 'hover' ), hover_text: c( 'ultra-light' ), hover_border: c( 'hover' ), focus: c( 'light' ) };
		}
	}

	// A small live sample of an overlay (on a sample picture) or a surface, next to its name.
	function presetPreview( kind, it ) {
		var st = function ( pairs ) { return pairs.filter( function ( p ) { return p[1] !== '' && p[1] != null; } ).map( function ( p ) { return p[0] + ':' + p[1]; } ).join( ';' ); };
		var clean = function ( v ) { return resolveVars( String( v == null ? '' : v ).replace( /[;{}<>"]/g, '' ) ).trim(); };
		var color = clean( it.color );
		if ( 'overlay' === kind ) {
			var img = 'gradient' === it.type ? clean( it.gradient ) : ( 'image' === it.type && it.image ? 'url(' + clean( it.image ) + ')' : '' );
			var layer = st( [ [ 'background-color', color ], [ 'background-image', img ], [ 'background-size', clean( it.size ) ], [ 'background-position', clean( it.position ) ], [ 'background-repeat', clean( it.repeat ) ], [ 'opacity', clean( it.opacity ) ], [ 'mix-blend-mode', clean( it.blend ) ], [ 'backdrop-filter', it.blur ? 'blur(' + clean( it.blur ) + ')' : '' ] ] );
			return '<span class="tf-ovl-preview" title="Preview on a sample picture"><span class="tf-ovl-layer" style="' + esc( layer ) + '"></span>' + ( it.layer ? '<span class="tf-ovl-layer" style="' + esc( 'background-image:' + clean( it.layer ) ) + '"></span>' : '' ) + '<span class="tf-ovl-text">Aa</span></span>';
		}
		var asset = String( it.asset || '' ).trim(), bg = '';
		if ( asset ) {
			if ( /^<svg[\s>]/i.test( asset ) ) { bg = 'url("data:image/svg+xml,' + encodeURIComponent( asset ).replace( /'/g, '%27' ).replace( /"/g, '%22' ) + '")'; }
			else if ( it.asset_is_url ) { bg = 'url(' + clean( asset ) + ')'; }
			else { bg = clean( asset ); }
		}
		var dark = /dark/.test( it.relationship || '' );
		var box = st( [ [ 'background-color', color ], [ 'background-image', bg ], [ 'background-size', clean( it.size ) ], [ 'background-position', clean( it.position ) ], [ 'background-repeat', clean( it.repeat ) ], [ 'color', dark ? '#fff' : ( 'none' === ( it.relationship || 'none' ) ? '' : '#111' ) ] ] );
		return '<span class="tf-ovl-preview is-surface" title="Preview" style="' + esc( box ) + '">' + ( it.layer ? '<span class="tf-ovl-layer" style="' + esc( 'background-image:' + clean( it.layer ) ) + '"></span>' : '' ) + '<span class="tf-ovl-text">Aa</span></span>';
	}
	function refreshPreset( box, kind, item ) {
		var pv = box && box.querySelector( '.tf-ovl-preview' );
		if ( pv ) { pv.outerHTML = presetPreview( kind, item ); }
	}

	function presetsHtml( list ) {
		var o = state.overlays;
		var kind = list === 'overlays' ? 'overlay' : 'surface';
		function sel( item, k, label, opts ) { return '<label class="tf-field"><span class="tf-flabel">' + label + tipIcon( tipRaw( kind + '.' + k ) ) + '</span><select data-ovl="' + k + '">' + opts.map( function ( v ) { return '<option value="' + v + '"' + ( item[ k ] === v ? ' selected' : '' ) + '>' + v + '</option>'; } ).join( '' ) + '</select></label>'; }
		function txt( item, k, label, color ) { var ctl = '<input type="text" data-ovl="' + k + '" value="' + esc( item[ k ] ) + '" spellcheck="false">'; return '<label class="tf-field"><span class="tf-flabel">' + label + tipIcon( tipRaw( kind + '.' + k ) ) + '</span>' + ( color ? '<span class="tf-colorfield">' + swatch( item[ k ] ) + ctl + '</span>' : ctl ) + '</label>'; }
		function area( item, k, label ) { return '<label class="tf-field tf-wide"><span class="tf-flabel">' + label + tipIcon( tipRaw( kind + '.' + k ) ) + '</span><textarea rows="2" data-ovl="' + k + '" spellcheck="false">' + esc( item[ k ] ) + '</textarea></label>'; }
		var html = '<p><button type="button" class="tf-btn" data-act="ovadd" data-list="' + list + '">+ Add ' + kind + '</button> <span class="tf-muted">Up to 10; numbers follow the order here.</span></p>';
		if ( list === 'overlays' ) { html += fieldsHtml( 'overlays', [ S( 'custom_pseudo', __( 'Pseudo-element', 'tokenforge' ), [ [ 'before', __( '::before', 'tokenforge' ) ], [ 'after', __( '::after', 'tokenforge' ) ] ] ) ] ); }
		( o[ list ] || [] ).forEach( function ( it, i ) {
			var cls = '.' + kind + '-' + ( i + 1 ) + ( it.name ? ', .' + kind + '-' + it.name : '' );
			html += '<div class="tf-preset" data-list="' + list + '" data-i="' + i + '"><div class="tf-preset-head">' + presetPreview( kind, it ) + '<strong>' + ( kind === 'overlay' ? 'Overlay ' : 'Surface ' ) + ( i + 1 ) + '</strong> <code>' + esc( cls ) + '</code><button type="button" class="tf-btn tf-small" data-act="ovdel" data-list="' + list + '" data-i="' + i + '">Remove</button></div><div class="tf-fields">';
			if ( kind === 'overlay' ) {
				html += txt( it, 'name', 'Name (optional)' ) + txt( it, 'color', 'Colour', true ) + sel( it, 'type', 'Background', [ 'none', 'gradient', 'image' ] ) + txt( it, 'opacity', 'Opacity' )
					+ area( it, 'gradient', 'Gradient' ) + txt( it, 'image', 'Image URL' ) + txt( it, 'size', 'Size' ) + txt( it, 'position', 'Position' ) + sel( it, 'attachment', 'Attachment', [ 'scroll', 'fixed', 'local' ] )
					+ txt( it, 'repeat', 'Repeat' ) + txt( it, 'blur', 'Backdrop blur' ) + txt( it, 'blend', 'Blend mode' ) + txt( it, 'radius', 'Radius (empty = inherit)' ) + txt( it, 'inset', 'Inset' ) + txt( it, 'animation', 'Animation' ) + area( it, 'layer', 'Top layer (gradient)' );
			} else {
				html += txt( it, 'name', 'Name (optional)' ) + txt( it, 'color', 'Background colour', true ) + sel( it, 'relationship', 'Colour relationship', [ 'none', 'ultra-light', 'light', 'dark', 'ultra-dark' ] )
					+ area( it, 'asset', 'Texture (URL, &lt;svg&gt; markup or gradient)' )
					+ '<label class="tf-field tf-switchrow"><span class="tf-switch"><input type="checkbox" data-ovl="asset_is_url"' + ( it.asset_is_url ? ' checked' : '' ) + '><i></i></span><span class="tf-flabel">The texture is a URL' + tipIcon( tipRaw( 'surface.asset_is_url' ) ) + '</span></label>'
					+ txt( it, 'size', 'Size' ) + txt( it, 'position', 'Position' ) + sel( it, 'attachment', 'Attachment', [ 'scroll', 'fixed', 'local' ] ) + txt( it, 'repeat', 'Repeat' ) + txt( it, 'animation', 'Animation' ) + area( it, 'layer', 'Overlay layer (gradient)' );
			}
			html += '</div></div>';
		} );
		return html;
	}

	// ---- Tokens screen ----------------------------------------------------
	// Every variable the settings make (state.catalog, from the server) is listed
	// next to the tokens stored in the list. Row types:
	//   custom    your own token (edit, category, delete)
	//   override  a token that replaces a variable a setting makes, with a different value
	//   repeat    a token with the same value a setting makes (it only stops later changes there)
	//   dead      a stored copy of a variable another part of the stylesheet sets later (no effect)
	//   stale     a colour token that differs from what Colors makes now (usually left over)
	//   generated a variable a setting makes, colours included (read only; Change adds an override)
	//   fixed     a variable another part of the stylesheet sets (read only)
	var catIndex = null;
	function catalogMap() {
		if ( ! catIndex ) { catIndex = {}; ( state.catalog || [] ).forEach( function ( c ) { catIndex[ c.name ] = c; } ); }
		return catIndex;
	}
	// Same value, ignoring spacing, rounding (0.141 vs 0.142, 259.95 vs 260) and two ways of
	// writing the same thing: calc(A vw + B rem) = B rem + A vw, and any hue on a grey (oklch L 0 H).
	function tokNorm( v ) {
		v = String( v == null ? '' : v ).replace( /\s+/g, ' ' ).trim()
			.replace( /calc\((-?[\d.]+)vw \+ (-?[\d.]+)rem\)/g, '$2rem + $1vw' )
			.replace( /oklch\(([\d.]+) 0 [\d.]+/g, 'oklch($1 0 0' );
		// Nothing but zeros (0px, clamp(0rem, calc(0vw + 0rem), 0rem)) is 0.
		var z = v.replace( /(^|[^\d.])0(?:\.0+)?(?:px|rem|em|vw|vh|%|ch|ex)?(?![\d.a-z%])/g, function ( m, p ) { return p + '0'; } );
		return /^(?:clamp|calc|min|max|[0\s,+*\/()-])+$/.test( z ) ? '0' : v;
	}
	// var(--x) replaced by x's value (and so on), so two ways of getting the same result
	// compare equal: 1px and var(--border-size) when --border-size is 1px.
	function followVars( v, map ) {
		v = String( v == null ? '' : v );
		for ( var i = 0; i < 12 && v.indexOf( 'var(' ) > -1; i++ ) {
			var next = v.replace( /var\(\s*--([a-z0-9-]+)\s*(?:,\s*([^()]*))?\)/gi, function ( m, n, fb ) {
				n = n.toLowerCase();
				if ( Object.prototype.hasOwnProperty.call( map, n ) ) { return String( map[ n ] ).indexOf( 'var(--' + n ) > -1 ? m : String( map[ n ] ); }
				return fb && fb.trim() ? fb.trim() : '\u0001' + n + '\u0002';
			} );
			if ( next === v ) { break; }
			v = next;
		}
		return v.replace( /\u0001([a-z0-9-]+)\u0002/g, 'var(--$1)' );
	}
	var resultMemo = {};
	function sameResult( a, b ) {
		if ( sameValue( a, b ) ) { return true; }
		var key = a + '\n' + b;
		if ( ! Object.prototype.hasOwnProperty.call( resultMemo, key ) ) {
			var map = tokenMap();
			resultMemo[ key ] = sameValue( followVars( a, map ), followVars( b, map ) );
		}
		return resultMemo[ key ];
	}
	function sameValue( a, b ) {
		a = tokNorm( a ); b = tokNorm( b );
		if ( a === b ) { return true; }
		var num = /\d*\.?\d+/g, na = a.match( num ) || [], nb = b.match( num ) || [];
		if ( na.length !== nb.length || a.replace( num, '#' ) !== b.replace( num, '#' ) ) { return false; }
		return na.every( function ( x, i ) {
			var p = parseFloat( x ), q = parseFloat( nb[ i ] ), d = Math.abs( p - q );
			return d <= 0.0015 || d <= Math.max( p, q ) * 0.002;
		} );
	}
	function sectionTitle( id ) {
		var sec = SECTIONS.filter( function ( s ) { return s.id === id; } )[0] || ALL_SECTIONS.filter( function ( s ) { return s.id === id; } )[0];
		return sec ? sec.title : id;
	}
	function cleanTokName( v ) { return String( v || '' ).toLowerCase().trim().replace( /^-+/, '' ).replace( /[^a-z0-9-]/g, '-' ).replace( /-+/g, '-' ).replace( /^-|-$/g, '' ); }
	function cleanGroup( v ) {
		v = String( v || '' ).toLowerCase().trim();
		if ( state.groups.indexOf( v ) > -1 ) { return v; }
		return v.replace( /[^a-z0-9]+/g, '-' ).replace( /^-+|-+$/g, '' ).slice( 0, 32 ).replace( /-+$/, '' );
	}
	// Built-in groups first, then your own categories (from the list and made this session).
	function allGroups() {
		var out = state.groups.slice();
		state.tokens.forEach( function ( t ) { if ( t.group && out.indexOf( t.group ) === -1 ) { out.push( t.group ); } } );
		( state.newGroups || [] ).forEach( function ( g ) { if ( out.indexOf( g ) === -1 ) { out.push( g ); } } );
		return out;
	}
	function tokenKind( t ) {
		var c = t.name ? catalogMap()[ t.name ] : null;
		if ( ! c ) { return { type: 'custom', c: null }; }
		if ( 'fixed' === c.kind ) { return { type: 'dead', c: c }; }
		if ( sameResult( t.value, c.value ) ) { return { type: 'repeat', c: c }; }
		return { type: 'colors' === c.kind ? 'stale' : 'override', c: c };
	}
	function tokenRows( group, q ) {
		var stored = {}, rows = [];
		state.tokens.forEach( function ( t, i ) {
			if ( t.name ) { stored[ t.name ] = true; }
			if ( t.group === group ) { var k = tokenKind( t ); rows.push( { t: t, i: i, c: k.c, type: k.type } ); }
		} );
		( state.catalog || [] ).forEach( function ( c ) {
			if ( c.group !== group || stored[ c.name ] ) { return; }
			rows.push( { t: null, i: -1, c: c, type: 'fixed' === c.kind ? 'fixed' : 'generated' } );
		} );
		if ( state.unusedOnly && scanned() ) {
			rows = rows.filter( function ( r ) { return ! usesOf( r.t ? r.t.name : r.c.name ).total; } );
		}
		if ( q ) {
			rows = rows.filter( function ( r ) {
				var n = r.t ? r.t.name : r.c.name, v = r.t ? r.t.value : r.c.value;
				return n.toLowerCase().indexOf( q ) > -1 || String( v || '' ).toLowerCase().indexOf( q ) > -1;
			} );
		}
		return rows;
	}
	// The tokens the Tokens screen shows: the active group, narrowed by the filter.
	function shownTokens() { return tokenRows( state.activeGroup, String( state.search || '' ).toLowerCase() ); }
	function rowOn( r ) { return r.t ? !! r.t.enabled : true; }
	function canToggle( r ) { return 'fixed' !== r.type; }
	// Switch one row on or off. A setting's variable switched off becomes a switched-off
	// token; switched on again, a token that only repeats the setting goes away.
	function setRowOn( r, on ) {
		if ( ! canToggle( r ) ) { return; }
		if ( r.t ) {
			r.t.enabled = on;
			if ( on && 'repeat' === r.type ) { r.t.__drop = true; }
		} else if ( ! on && 'generated' === r.type ) {
			state.tokens.push( { name: r.c.name, value: r.c.value, group: r.c.group, enabled: false } );
		}
	}
	function dropMarked() { state.tokens = state.tokens.filter( function ( t ) { return ! t.__drop; } ); tokenIndex = null; }
	// The on/off box in the column head: checked, unchecked, or in between when some are off.
	function syncTokAll() {
		var box = app && app.querySelector( '.tf-tok-all' );
		if ( ! box ) { return; }
		var rows = shownTokens().filter( canToggle ), on = rows.filter( rowOn ).length;
		box.checked = !! rows.length && on === rows.length;
		box.indeterminate = on > 0 && on < rows.length;
	}
	// Use settings (all) keeps a token that sets its own value for a setting's variable, or
	// switches one off: both are choices, not leftovers. (Colour tokens that differ are
	// nearly always left over from an import, so they go.)
	function keepsOwn( t, type ) { return 'override' === type || ( ! t.enabled && ( 'repeat' === type || 'stale' === type ) ); }
	// What Use settings tidies, in every group (override = kept, only counted).
	function settingsGaps() {
		var n = { override: 0, repeat: 0, dead: 0, stale: 0 };
		state.tokens.forEach( function ( t ) {
			if ( ! t.name ) { return; }
			var k = tokenKind( t );
			if ( keepsOwn( t, k.type ) ) { n.override++; }
			else if ( undefined !== n[ k.type ] ) { n[ k.type ]++; }
		} );
		n.total = n.repeat + n.dead + n.stale;
		return n;
	}
	// Let the settings decide: the token goes and the setting's variable shows again.
	// names = only these (whatever their value); without names, every token that repeats
	// a setting, has no effect or is a left-over colour goes, and tokens with a value of
	// their own or switched off stay.
	function useSettings( names ) {
		var cat = catalogMap();
		state.tokens = state.tokens.filter( function ( t ) {
			var c = t.name ? cat[ t.name ] : null;
			if ( ! c ) { return true; }
			return names ? names.indexOf( t.name ) === -1 : keepsOwn( t, tokenKind( t ).type );
		} );
		tokenIndex = null;
	}
	// Uses of a variable: in TokenForge's stylesheet, and on the site after Find uses.
	function scanned() { return !! ( state.siteUsage && state.siteUsage.time ); }
	function usesOf( name ) {
		var site = ( state.siteUsage && state.siteUsage.uses && state.siteUsage.uses[ name ] ) || { count: 0, where: [] };
		var sheet = ( state.usage || {} )[ name ] || 0;
		return { sheet: sheet, site: site.count || 0, where: site.where || [], total: sheet + ( site.count || 0 ) };
	}
	function usesNote( name ) {
		if ( ! scanned() ) { return ''; }
		var u = usesOf( name );
		if ( ! u.total ) { return '<span class="tf-tokuses is-unused" title="' + esc( __( 'Not used in TokenForge\'s stylesheet or anywhere on the site.', 'tokenforge' ) ) + '">' + esc( __( 'Not used', 'tokenforge' ) ) + '</span>'; }
		var tip = __( 'In the stylesheet: %1$s. On the site: %2$s', 'tokenforge' ).replace( '%1$s', String( u.sheet ) ).replace( '%2$s', String( u.site ) ) + ( u.where.length ? ' (' + u.where.join( ', ' ) + ( u.site > 0 && u.where.length >= 5 ? ', …' : '' ) + ')' : '' );
		return '<span class="tf-tokuses" title="' + esc( tip ) + '">' + esc( __( 'Used %d×', 'tokenforge' ).replace( '%d', u.total ) ) + '</span>';
	}
	// Unused filter: what is used nowhere (after a scan).
	function unusedBar() {
		if ( ! state.unusedOnly || ! scanned() ) { return ''; }
		var own = shownTokens().filter( function ( r ) { return r.t && 'custom' === r.type; } ).length;
		var when = new Date( state.siteUsage.time * 1000 );
		return '<div class="tf-tokinfobar" role="status"><span>' + esc( __( 'Showing variables used nowhere: not in TokenForge\'s stylesheet and not on the site (%s pages and templates checked', 'tokenforge' ).replace( '%s', String( state.siteUsage.posts || 0 ) ) + ( state.siteUsage.capped ? __( ', the first ones only', 'tokenforge' ) : '' ) + ', ' + when.toLocaleString() + '). ' + __( 'Code outside TokenForge and the builder (theme files, other plugins) is not checked.', 'tokenforge' ) ) + '</span>'
			+ ( own ? '<button type="button" class="tf-btn" data-act="tokdelunused">' + esc( __( 'Delete the %d own tokens shown', 'tokenforge' ).replace( '%d', own ) ) + '</button>' : '' ) + '</div>';
	}
	// Switched-off tokens the stylesheet still uses (those places get no value).
	function offUsed() {
		var u = state.usage || {};
		return state.tokens.filter( function ( t ) { return t.name && ! t.enabled && u[ t.name ]; } );
	}
	function offUsedBar() {
		var list = offUsed();
		if ( ! list.length ) { return ''; }
		var names = list.slice( 0, 5 ).map( function ( t ) { return '--' + t.name; } ).join( ', ' ) + ( list.length > 5 ? ', …' : '' );
		return '<div class="tf-tokwarnbar" role="status"><span>' + esc( __( 'Switched off but still used in the stylesheet (%1$s): %2$s. Those places get no value.', 'tokenforge' ).replace( '%1$s', String( list.length ) ).replace( '%2$s', names ) ) + '</span>'
			+ '<button type="button" class="tf-btn" data-act="tokoffon">' + esc( __( 'Switch them on', 'tokenforge' ) ) + '</button></div>';
	}
	function renderKeepScroll() {
		var b = app && app.querySelector( '.tf-body' ), top = b ? b.scrollTop : 0;
		render();
		var nb = app && app.querySelector( '.tf-body' );
		if ( nb ) { nb.scrollTop = top; }
	}
	// The toolbar stays on top while the list scrolls; the column heads stick right under it.
	function stickTokbar() {
		var bar = app && app.querySelector( '.tf-tokbar' ), body = app && app.querySelector( '.tf-body' );
		if ( bar && body ) { body.style.setProperty( '--tf-tokbar-h', bar.offsetHeight + 'px' ); }
	}
	// wp-admin: the dashboard fills the window below the page title (see admin.css).
	function fitAdmin() {
		if ( ! app || ! app.closest || ! app.closest( '.wrap' ) ) { return; }
		app.style.setProperty( '--tf-fit-top', Math.round( app.getBoundingClientRect().top + ( window.pageYOffset || 0 ) + 20 ) + 'px' );
	}
	function groupOptions( sel, withNew ) {
		return allGroups().map( function ( g ) { return '<option value="' + esc( g ) + '"' + ( g === sel ? ' selected' : '' ) + '>' + esc( g ) + '</option>'; } ).join( '' )
			+ ( withNew ? '<option value="__new">' + esc( __( 'New category…', 'tokenforge' ) ) + '</option>' : '' );
	}
	function addForm() {
		var f = state.addForm;
		if ( ! f ) { return ''; }
		var isNew = '__new' === f.group;
		return '<div class="tf-tokadd" role="group" aria-label="' + esc( __( 'Add a token', 'tokenforge' ) ) + '">'
			+ '<label class="tf-tokadd-f"><span>' + esc( __( 'Name', 'tokenforge' ) ) + '</span><span class="tf-tokadd-in"><span class="tf-prefix">--</span><input type="text" class="tf-tokadd-name" value="' + esc( f.name ) + '" placeholder="brand-accent" spellcheck="false"></span></label>'
			+ '<label class="tf-tokadd-f"><span>' + esc( __( 'Value', 'tokenforge' ) ) + '</span><input type="text" class="tf-tokadd-value" value="' + esc( f.value ) + '" placeholder="#ff6600, 2rem, var(--space-m) …" spellcheck="false"></label>'
			+ '<label class="tf-tokadd-f"><span>' + esc( __( 'Category', 'tokenforge' ) ) + '</span><select class="tf-tokadd-group">' + groupOptions( f.group, true ) + '</select></label>'
			+ '<label class="tf-tokadd-f tf-tokadd-newwrap"' + ( isNew ? '' : ' hidden' ) + '><span>' + esc( __( 'New category', 'tokenforge' ) ) + '</span><input type="text" class="tf-tokadd-new" value="' + esc( f.fresh ) + '" placeholder="' + esc( __( 'e.g. brand', 'tokenforge' ) ) + '" spellcheck="false"></label>'
			+ '<span class="tf-tokadd-btns"><button type="button" class="tf-btn tf-primary" data-act="tokadd-save">' + esc( __( 'Add', 'tokenforge' ) ) + '</button><button type="button" class="tf-btn" data-act="tokadd-cancel">' + esc( __( 'Cancel', 'tokenforge' ) ) + '</button></span>'
			+ '<p class="tf-tokadd-msg" role="alert"' + ( f.msg ? '' : ' hidden' ) + '>' + esc( f.msg || '' ) + '</p>'
			+ '</div>';
	}
	function addToken() {
		var f = state.addForm;
		if ( ! f ) { return; }
		var name = cleanTokName( f.name ), group = '__new' === f.group ? cleanGroup( f.fresh ) : f.group, c = catalogMap()[ name ];
		var fail = function ( msg ) { f.msg = msg; var m = app.querySelector( '.tf-tokadd-msg' ); if ( m ) { m.textContent = msg; m.hidden = false; } };
		if ( ! name ) { return fail( __( 'Give the token a name.', 'tokenforge' ) ); }
		if ( state.tokens.some( function ( t ) { return t.name === name; } ) ) { return fail( __( 'There is already a token called --%s.', 'tokenforge' ).replace( '%s', name ) ); }
		if ( c && 'fixed' === c.kind ) { return fail( __( '--%1$s is set by %2$s later in the stylesheet, so a token with this name would have no effect. Change it in %2$s.', 'tokenforge' ).replace( '%1$s', name ).split( '%2$s' ).join( sectionTitle( c.source ) ) ); }
		if ( ! group ) { return fail( __( 'Give the new category a name.', 'tokenforge' ) ); }
		var at = state.tokens.findIndex( function ( x ) { return x.group === group; } );
		state.tokens.splice( at < 0 ? state.tokens.length : at, 0, { name: name, value: cleanTokValue( f.value ), group: group, enabled: true } );
		if ( state.groups.indexOf( group ) === -1 ) { state.newGroups = ( state.newGroups || [] ).concat( [ group ] ); }
		state.activeGroup = group; state.search = ''; state.addForm = null; tokenIndex = null;
		markDirty(); render();
		if ( c && 'setting' === c.kind ) { setStatus( __( 'Added. It replaces what %s makes for this variable.', 'tokenforge' ).replace( '%s', sectionTitle( c.source ) ) ); }
		var row = app.querySelector( '.tf-table tbody tr[data-i="' + ( at < 0 ? state.tokens.length - 1 : at ) + '"] input[data-field="value"]' );
		if ( row ) { row.focus(); }
	}
	function cleanTokValue( v ) { return String( v || '' ).trim().replace( /[{};<>]/g, '' ); }

	function tokenRow( r ) {
		var t = r.t, c = r.c, name = t ? t.name : c.name, value = t ? t.value : c.value, on = rowOn( r );
		var editable = 'custom' === r.type || 'override' === r.type || 'repeat' === r.type;
		var isColor = ( t && 'color' === t.group ) || ( c && 'color' === c.group );
		var h = '<tr class="tf-tokrow is-' + r.type + ( on ? '' : ' tf-row-disabled' ) + '"' + ( t ? ' data-i="' + r.i + '"' : ' data-cat="' + esc( c.name ) + '"' ) + '>';
		h += '<td><input type="checkbox" data-field="enabled"' + ( on ? ' checked' : '' ) + ( canToggle( r ) ? '' : ' disabled' ) + ' aria-label="' + esc( __( 'On', 'tokenforge' ) + ': --' + name ) + '"></td>';
		h += '<td class="tf-name-cell"><span class="tf-prefix">--</span>' + ( editable
			? '<input type="text" data-field="name" value="' + esc( name ) + '" spellcheck="false">'
			: '<code class="tf-tokname">' + esc( name ) + '</code>' ) + '</td>';
		var note = '';
		if ( t && ! t.enabled && 'repeat' === r.type ) { note = esc( __( 'Switched off: this variable is left out of the stylesheet. Switch it on to use what %s makes.', 'tokenforge' ).replace( '%s', sectionTitle( c.source ) ) ); }
		else if ( t && ! t.enabled && 'override' === r.type ) { note = esc( __( 'Switched off: this variable is left out of the stylesheet.', 'tokenforge' ) ); }
		else if ( 'repeat' === r.type ) { note = esc( __( 'Same as what %s makes now, but it stops later changes there from showing.', 'tokenforge' ).replace( '%s', sectionTitle( c.source ) ) ); }
		else if ( 'override' === r.type ) { note = esc( __( 'Replaces what %s makes:', 'tokenforge' ).replace( '%s', sectionTitle( c.source ) ) ) + ' <code>' + esc( c.value ) + '</code>'; }
		else if ( 'dead' === r.type ) { note = esc( __( 'No effect: %s sets this variable later in the stylesheet.', 'tokenforge' ).replace( '%s', sectionTitle( c.source ) ) ); }
		else if ( 'stale' === r.type ) { note = esc( __( 'Left over from an older palette or an import. Colors makes:', 'tokenforge' ) ) + ' <code>' + esc( c.value ) + '</code>'; }
		// Off, but other rules still use it: those places get no value.
		var used = ( state.usage || {} )[ name ] || 0;
		var warn = ! on && used ? esc( __( 'Used in the stylesheet (%d places): they get no value while it is off.', 'tokenforge' ).replace( '%d', used ) ) : '';
		h += '<td><span class="tf-colorfield">' + ( isColor ? swatch( value ) : '' ) + ( editable
			? '<input type="text" data-field="value" value="' + esc( value ) + '" spellcheck="false">'
			: '<span class="tf-tokval" title="' + esc( value ) + '">' + esc( value ) + '</span>' ) + '</span>'
			+ ( note ? '<div class="tf-toknote">' + note + '</div>' : '' ) + ( warn ? '<div class="tf-toknote tf-tokwarn">' + warn + '</div>' : '' ) + '</td>';
		// Where it comes from, or its category.
		h += '<td class="tf-tokcat">' + ( t && editable
			? '<select data-field="group" class="tf-tokgroup" aria-label="' + esc( __( 'Category', 'tokenforge' ) ) + '">' + groupOptions( t.group, true ) + '</select>'
			: '' )
			+ ( c ? '<button type="button" class="tf-srcbadge" data-act="goto" data-go="' + esc( c.source ) + '" title="' + esc( __( 'Made by %s: change it there', 'tokenforge' ).replace( '%s', sectionTitle( c.source ) ) ) + '">' + esc( sectionTitle( c.source ) ) + '</button>' : '' ) + usesNote( name ) + '</td>';
		var act = '';
		if ( 'custom' === r.type ) { act = '<button type="button" class="tf-del" title="' + esc( __( 'Delete', 'tokenforge' ) ) + '" aria-label="' + esc( __( 'Delete', 'tokenforge' ) ) + '" data-act="del">×</button>'; }
		else if ( 'override' === r.type || 'repeat' === r.type || 'dead' === r.type ) { act = '<button type="button" class="tf-tokuse" data-act="tokuse" title="' + esc( __( 'Use the setting (remove this token)', 'tokenforge' ) ) + '" aria-label="' + esc( __( 'Use the setting', 'tokenforge' ) ) + '">↺</button>'; }
		else if ( 'stale' === r.type ) { act = '<button type="button" class="tf-tokuse" data-act="tokuse" title="' + esc( __( 'Use what Colors makes', 'tokenforge' ) ) + '" aria-label="' + esc( __( 'Use what Colors makes', 'tokenforge' ) ) + '">↺</button>'; }
		else if ( 'generated' === r.type ) { act = '<button type="button" class="tf-tokover" data-act="tokover" title="' + esc( __( 'Change: add a token that replaces this value', 'tokenforge' ) ) + '" aria-label="' + esc( __( 'Change', 'tokenforge' ) ) + '">✎</button>'; }
		return h + '<td class="tf-tokact">' + act + '</td></tr>';
	}

	function renderTokens() {
		var groups = allGroups();
		if ( groups.indexOf( state.activeGroup ) === -1 ) { state.activeGroup = groups[0]; }
		var html = '<p class="tf-desc tf-tokintro">' + esc( __( 'Every CSS variable TokenForge writes. Most come from your settings, colours included: each shows where it is made, so change it there. Add your own tokens with + Add token. A token with the same name as a variable a setting makes replaces it.', 'tokenforge' ) ) + '</p>';
		html += '<div class="tf-subtabs tf-tokbar">';
		groups.forEach( function ( g ) {
			html += '<button type="button" class="tf-subtab' + ( state.activeGroup === g ? ' is-active' : '' ) + '" data-group="' + esc( g ) + '">' + esc( g ) + ' <span class="tf-count">' + tokenRows( g, '' ).length + '</span></button>';
			// Your own categories can be renamed or deleted (built-in ones stay).
			if ( state.activeGroup === g && state.groups.indexOf( g ) === -1 ) {
				html += '<span class="tf-catacts"><button type="button" class="tf-catact" data-act="catrename" title="' + esc( __( 'Rename this category', 'tokenforge' ) ) + '" aria-label="' + esc( __( 'Rename this category', 'tokenforge' ) ) + '">✎</button>'
					+ '<button type="button" class="tf-catact" data-act="catdel" title="' + esc( __( 'Delete this category (its tokens move to other)', 'tokenforge' ) ) + '" aria-label="' + esc( __( 'Delete this category', 'tokenforge' ) ) + '">×</button></span>';
			}
		} );
		var gaps = settingsGaps();
		html += '<input type="search" class="tf-search" placeholder="' + esc( __( 'Filter…', 'tokenforge' ) ) + '" aria-label="' + esc( __( 'Filter tokens', 'tokenforge' ) ) + '" value="' + esc( state.search ) + '">'
			+ ( gaps.total ? '<button type="button" class="tf-btn" data-act="tokuseall" title="' + esc( __( 'Let the settings decide: remove tokens that repeat what a setting makes, have no effect, or are colours left over from an older palette. Tokens with a value of their own stay.', 'tokenforge' ) ) + '">' + esc( __( 'Use settings', 'tokenforge' ) ) + ' <span class="tf-count">' + gaps.total + '</span></button>' : '' )
			+ '<button type="button" class="tf-btn" data-act="tokscan" title="' + esc( scanned() ? __( 'Last checked: %s. Check again.', 'tokenforge' ).replace( '%s', new Date( state.siteUsage.time * 1000 ).toLocaleString() ) : __( 'Look through your pages, templates, classes and styles for every var(--…). It only reads.', 'tokenforge' ) ) + '">' + esc( __( 'Find uses', 'tokenforge' ) ) + '</button>'
			+ ( scanned() ? '<button type="button" class="tf-btn' + ( state.unusedOnly ? ' is-active' : '' ) + '" data-act="tokunused" aria-pressed="' + ( state.unusedOnly ? 'true' : 'false' ) + '" title="' + esc( __( 'Show only what is used nowhere', 'tokenforge' ) ) + '">' + esc( __( 'Unused', 'tokenforge' ) ) + '</button>' : '' )
			+ '<button type="button" class="tf-btn tf-primary" data-act="add">' + esc( __( '+ Add token', 'tokenforge' ) ) + '</button></div>';
		html += offUsedBar();
		html += unusedBar();
		html += addForm();
		var q = String( state.search || '' ).toLowerCase();
		var rows = shownTokens(), tog = rows.filter( canToggle ), on = tog.filter( rowOn ).length;
		var all = '<input type="checkbox" class="tf-tok-all" aria-label="' + esc( __( 'Switch every token shown on or off', 'tokenforge' ) ) + '" title="' + esc( __( 'Switch every token shown on or off', 'tokenforge' ) ) + '"' + ( tog.length && on === tog.length ? ' checked' : '' ) + ( tog.length ? '' : ' disabled' ) + '>';
		html += '<div class="tf-tablewrap tf-tokwrap"><table class="tf-table tf-toktable"><thead><tr><th style="width:64px"><label class="tf-th-on">' + all + '<span>' + esc( __( 'On', 'tokenforge' ) ) + '</span></label></th><th style="width:30%">' + esc( __( 'Variable', 'tokenforge' ) ) + '</th><th>' + esc( __( 'Value', 'tokenforge' ) ) + '</th><th style="width:118px">' + esc( __( 'From / category', 'tokenforge' ) ) + '</th><th style="width:40px">'
			+ ( rows.some( function ( r ) { return r.t && r.c; } ) ? '<button type="button" class="tf-tokuse" data-act="tokuseshown" title="' + esc( __( 'Let the settings decide for every token shown', 'tokenforge' ) ) + '" aria-label="' + esc( __( 'Let the settings decide for every token shown', 'tokenforge' ) ) + '">↺</button>' : '' )
			+ '</th></tr></thead><tbody>';
		if ( ! rows.length ) { html += '<tr><td colspan="5" class="tf-muted">' + esc( q ? __( 'No tokens here matching your filter.', 'tokenforge' ) : __( 'No tokens here.', 'tokenforge' ) ) + '</td></tr>'; }
		rows.forEach( function ( r ) { html += tokenRow( r ); } );
		html += '</tbody></table></div>';
		return html;
	}

	// ---- Data attributes: where the site uses each one (Find uses, like the Tokens screen) ----
	function attrScanned() { return !! ( state.attributeUsage && state.attributeUsage.time ); }
	function attrUses( name ) { return ( state.attributeUsage && state.attributeUsage.uses && state.attributeUsage.uses[ name ] ) || { count: 0, where: [] }; }
	// Known attributes: the server's list (data-tf-* and data-scheme*, the names the scan counts), plus any name the scan reports that it lacks.
	function attrList() {
		var seen = {}, out = [];
		( state.attributeList || [] ).forEach( function ( it ) { if ( ! seen[ it.n ] ) { seen[ it.n ] = true; out.push( { n: it.n, d: it.d } ); } } );
		Object.keys( ( state.attributeUsage && state.attributeUsage.uses ) || {} ).forEach( function ( n ) { if ( ! seen[ n ] ) { seen[ n ] = true; out.push( { n: n, d: '' } ); } } );
		return out;
	}
	// A re-render drops focus: give it back to the button that was used.
	function attrFocus( act ) { var b = app && app.querySelector( '[data-act="' + act + '"]' ); if ( b ) { b.focus( { preventScroll: true } ); } }
	function attrWhere( u ) { return u.where && u.where.length ? esc( u.where.join( ', ' ) ) + ( u.where.length >= 5 ? ', …' : '' ) : ''; }
	function attrTh( label, tip ) { return '<th scope="col">' + esc( label ) + tipIcon( tip ) + '</th>'; }
	function renderAttributes() {
		var list = attrList(), scanned = attrScanned(), au = state.attributeUsage || {};
		var rows = scanned && state.attrUnused ? list.filter( function ( a ) { return ! attrUses( a.n ).count; } ) : list;
		var html = '<p class="tf-desc tf-tokintro">' + esc( __( 'Every data attribute TokenForge knows, and where your site uses it. Find uses looks through your pages, templates, classes and styles. It only reads, it never changes anything.', 'tokenforge' ) ) + '</p>';
		html += '<div class="tf-attrbar">'
			+ '<button type="button" class="tf-btn" data-act="attrscan"' + ( state.attrBusy ? ' disabled aria-busy="true"' : '' ) + ' title="' + esc( scanned ? __( 'Last checked: %s. Check again.', 'tokenforge' ).replace( '%s', new Date( au.time * 1000 ).toLocaleString() ) : __( 'Look through your pages, templates, classes and styles for every TokenForge data attribute. It only reads.', 'tokenforge' ) ) + '">' + esc( __( 'Find uses', 'tokenforge' ) ) + '</button>'
			+ ( scanned ? '<button type="button" class="tf-btn' + ( state.attrUnused ? ' is-active' : '' ) + '" data-act="attrunused" aria-pressed="' + ( state.attrUnused ? 'true' : 'false' ) + '" title="' + esc( __( 'Show only the attributes that are used nowhere on the site', 'tokenforge' ) ) + '">' + esc( __( 'Show unused only', 'tokenforge' ) ) + '</button>' : '' )
			+ '</div>';
		if ( scanned ) {
			var used = list.filter( function ( a ) { return attrUses( a.n ).count; } ).length;
			html += '<div class="tf-tokinfobar" role="status"><span>' + esc( __( '%1$s of %2$s attributes are used. Checked %3$s pages and templates', 'tokenforge' ).replace( '%1$s', String( used ) ).replace( '%2$s', String( list.length ) ).replace( '%3$s', String( au.posts || 0 ) ) + ( au.capped ? __( ', the first ones only', 'tokenforge' ) : '' ) + ', ' + new Date( au.time * 1000 ).toLocaleString() + '. ' + __( 'Code outside TokenForge and the builder (theme files, other plugins) is not checked.', 'tokenforge' ) ) + '</span></div>';
		}
		var unknown = au.unknown || {}, uk = Object.keys( unknown );
		if ( uk.length ) {
			html += '<h3 class="tf-attrhead">' + esc( __( 'Names TokenForge does not know', 'tokenforge' ) ) + ' <span class="tf-count">' + uk.length + ( au.unknownCapped ? '+' : '' ) + '</span></h3>'
				+ '<div class="tf-tablewrap"><table class="tf-table tf-attrtable is-unknown" aria-label="' + esc( __( 'Unknown data attributes', 'tokenforge' ) ) + '"><thead><tr>'
				+ attrTh( __( 'Name', 'tokenforge' ), __( 'A data-tf-* name the site uses that TokenForge does not provide. It does nothing, so it is probably a typo.', 'tokenforge' ) )
				+ attrTh( __( 'Used', 'tokenforge' ), __( 'How many times this name appears (text in pages counts too).', 'tokenforge' ) )
				+ attrTh( __( 'Where', 'tokenforge' ), __( 'The first pages, templates or styles that use it.', 'tokenforge' ) )
				+ attrTh( __( 'Did you mean', 'tokenforge' ), __( 'The closest attribute TokenForge does provide.', 'tokenforge' ) ) + '</tr></thead><tbody>'
				+ uk.map( function ( n ) {
					var u = unknown[ n ] || {};
					return '<tr class="is-unknown"><td><code>' + esc( n ) + '</code></td><td>' + esc( String( u.count || 0 ) ) + '</td><td>' + attrWhere( u ) + '</td><td>'
						+ ( u.didYouMean ? '<code>' + esc( u.didYouMean ) + '</code>' :'<span class="tf-muted">' + esc( __( 'No close match', 'tokenforge' ) ) + '</span>' ) + '</td></tr>';
				} ).join( '' ) + '</tbody></table></div>'
				+ '<h3 class="tf-attrhead">' + esc( __( 'Known attributes', 'tokenforge' ) ) + ' <span class="tf-count">' + rows.length + '</span></h3>';
		}
		html += '<div class="tf-tablewrap"><table class="tf-table tf-attrtable" aria-label="' + esc( __( 'Known data attributes', 'tokenforge' ) ) + '"><thead><tr>'
			+ attrTh( __( 'Attribute', 'tokenforge' ), __( 'The attribute name. The ? beside each one explains what it does.', 'tokenforge' ) )
			+ ( scanned ? attrTh( __( 'Used', 'tokenforge' ), __( 'How many times the site uses it (text in pages counts too). Not used means nowhere on the site.', 'tokenforge' ) ) + attrTh( __( 'Where', 'tokenforge' ), __( 'The first pages, templates or styles that use it.', 'tokenforge' ) ) : '' )
			+ '</tr></thead><tbody>';
		if ( ! rows.length ) { html += '<tr><td colspan="' + ( scanned ? 3 : 1 ) + '" class="tf-muted">' + esc( scanned && state.attrUnused ? __( 'Every attribute is used somewhere.', 'tokenforge' ) : __( 'No attributes found.', 'tokenforge' ) ) + '</td></tr>'; }
		rows.forEach( function ( a ) {
			var u = attrUses( a.n );
			html += '<tr><td class="tf-attrname"><code>' + esc( a.n ) + '</code>' + tipIcon( a.d || __( 'A TokenForge data attribute.', 'tokenforge' ) ) + '</td>'
				+ ( scanned ? '<td>' + ( u.count ? esc( String( u.count ) ) : '<span class="tf-attrnone">' + esc( __( 'Not used', 'tokenforge' ) ) + '</span>' ) + '</td><td>' + attrWhere( u ) + '</td>' : '' ) + '</tr>';
		} );
		return html + '</tbody></table></div>';
	}

	// ---- Rendering -------------------------------------------------------
	// ---- Getting started (Home), until you hide it ----
	var GS_KEY = 'tf-getting-started-hidden';
	function gsHidden() { try { return '1' === window.localStorage.getItem( GS_KEY ); } catch ( e ) { return false; } }
	function gettingStarted() {
		if ( gsHidden() ) { return '<p class="tf-gs-show"><button type="button" class="tf-btn tf-small" data-act="gsshow">Show getting started</button></p>'; }
		var steps = [
			[ 'colors', 'Pick your colours', 'Set the base colour of each family; the shades and dark mode are made for you.' ],
			[ 'typography', 'Set your fonts', 'Upload your font files or copy a Google Fonts family to your site, then choose body and heading fonts.' ],
			[ 'spacing', 'Check the fluid scales', 'Text and spacing grow smoothly between the smallest and largest screen you set in Typography.' ],
			[ 'buttons', 'Style buttons and links', 'One look for every button; each colour family gets its own variant.' ],
			[ 'builder', 'Connect the builder', 'Theme style, classes and variables follow TokenForge, and changes made in the builder come back.' ],
			[ 'transfer', 'Keep a backup', 'Export everything to a file before big changes; import it on another site.' ]
		];
		return '<section class="tf-panel tf-gs"><div class="tf-panel-head"><h3>Getting started</h3><span class="tf-grow"></span><button type="button" class="tf-btn tf-small" data-act="gshide">Hide</button></div><div class="tf-panel-body"><ol class="tf-gs-steps">'
			+ steps.filter( function ( s ) { return shown( section( s[0] ) ) && 'integrations' !== section( s[0] ).render; } ).map( function ( s ) {
				return '<li><button type="button" class="tf-gs-step" data-act="goto" data-go="' + s[0] + '"><strong>' + esc( s[1] ) + '</strong><span>' + esc( s[2] ) + '</span></button></li>';
			} ).join( '' ) + '</ol></div></section>';
	}

	// No supported builder active: what TokenForge knows about each builder, no switches.
	function renderIntegrations() {
		var rows = ( state.platforms || [] ).map( function ( p ) {
			var st = p.supported ? ( p.active ? __( 'Active', 'tokenforge' ) : __( 'Supported, not active', 'tokenforge' ) )
				: __( 'Coming soon', 'tokenforge' ) + ( p.active ? ' (' + __( 'active on this site', 'tokenforge' ) + ')' : p.installed ? ' (' + __( 'installed, not active', 'tokenforge' ) + ')' : '' );
			var note = 'blocks' === p.id && p.active ? '<br><span class="tf-muted">' + esc( __( 'Works today: TokenForge CSS inside the editor, TokenForge colours in the palette (Options > Block editor), your self-hosted fonts in the font picker, global styles with block themes, and the colour scheme toggle block.', 'tokenforge' ) ) + '</span>' : '';
			return '<tr><th scope="row">' + esc( p.label ) + '</th><td>' + esc( p.active ? p.version : '' ) + '</td><td>' + esc( st ) + note + '</td></tr>';
		} ).join( '' );
		return '<section class="tf-panel"><div class="tf-panel-body"><p class="tf-muted tf-desc">' + esc( __( 'No page builder that TokenForge has settings for is active on this site, so there are no builder settings to show. Everything else in TokenForge works on any site.', 'tokenforge' ) ) + '</p>'
			+ '<div class="tf-tablewrap"><table class="tf-table"><thead><tr><th scope="col">' + esc( __( 'Builder', 'tokenforge' ) ) + '</th><th scope="col">' + esc( __( 'Version', 'tokenforge' ) ) + '</th><th scope="col">' + esc( __( 'Status', 'tokenforge' ) ) + '</th></tr></thead><tbody>' + rows + '</tbody></table></div></div></section>';
	}

	function panelHtml( sec, p, i ) {
		if ( ! has( p ) ) { return ''; }
		var pid = sec.id + ':' + strip( p.key || p.title );
		var collapsed = ! state.expandAll && ! state.open[ pid ];
		var on = p.on ? !! ( state[ p.mod ] || {} )[ p.on ] : true;
		var head = '<div class="tf-panel-head" data-act="collapse" data-pid="' + esc( pid ) + '" role="button" tabindex="0" aria-expanded="' + ( collapsed ? 'false' : 'true' ) + '"><span class="tf-chev" aria-hidden="true"></span><h3>' + p.title + '</h3>' + ( p.on ? tipIcon( tipText( p.mod, p.on ) ) : '' );
		if ( p.on ) {
			head += '<label class="tf-switch tf-head-switch" title="' + ( on ? 'On' : 'Off' ) + '"><input type="checkbox" data-mod="' + p.mod + '" data-k="' + p.on + '"' + ( on ? ' checked' : '' ) + ' aria-label="' + esc( strip( p.title ) ) + ' on/off"><i></i></label>';
		}
		head += '</div>';
		var body = '';
		if ( ! collapsed ) {
			body = '<div class="tf-panel-body' + ( on ? '' : ' is-off' ) + '">' + ( p.d ? '<p class="tf-muted tf-desc">' + p.d + '</p>' : '' );
			if ( p.fields ) { body += fieldsHtml( p.mod, p.fields ); }
			if ( p.custom && CUSTOM[ p.custom ] ) { body += CUSTOM[ p.custom ]( p ); }
			body += '</div>';
		}
		return '<section class="tf-panel' + ( collapsed ? ' is-collapsed' : '' ) + ( p.on && ! on ? ' is-disabled' : '' ) + '">' + head + body + '</section>';
	}

	function searchHtml() {
		var q = state.q.toLowerCase().trim();
		var html = '', n = 0;
		SECTIONS.forEach( function ( sec ) {
			if ( ! shown( sec ) ) { return; }
			( sec.panels || [] ).forEach( function ( p ) {
				if ( ! p.fields || ! p.mod || ! has( p ) ) { return; }
				var inTitle = strip( p.title ).toLowerCase().indexOf( q ) > -1;
				var hits = p.fields.filter( has ).filter( function ( f ) { return inTitle || ( strip( f.l ) + ' ' + f.k + ' ' + strip( f.h ) + ' ' + tipText( p.mod, f.k ) ).toLowerCase().indexOf( q ) > -1; } );
				if ( ! hits.length ) { return; }
				n += hits.length;
				html += '<section class="tf-panel"><div class="tf-panel-head tf-search-head"><h3>' + esc( sec.title ) + ' <span class="tf-sep">›</span> ' + p.title + '</h3><button type="button" class="tf-btn tf-small" data-act="goto" data-go="' + sec.id + '">Open</button></div><div class="tf-panel-body">' + fieldsHtml( p.mod, hits ) + '</div></section>';
			} );
		} );
		return '<p class="tf-muted">' + n + ( n === 1 ? ' setting matches “' : ' settings match “' ) + esc( state.q ) + '”.</p>' + html;
	}

	// The top of the Accessibility hub: notices for the switched-off modules whose settings moved here, and
	// "This page" (the page check, builder window only).
	function hubTop() {
		var html = '', off = function ( id, text ) { if ( false === state.modules[ id ] ) { html += '<p class="tf-notice">' + esc( text ) + '</p>'; } };
		off( 'base', __( 'The Base module is off, so the focus ring and reduced motion settings below do nothing until you switch it on.', 'tokenforge' ) );
		off( 'buttons', __( 'The Buttons & Links module is off, so the external-link indicator below does nothing until you switch it on.', 'tokenforge' ) );
		if ( window.TokenForgeCheckPanel ) {
			html += '<section class="tf-panel"><div class="tf-panel-body"><p class="tf-muted tf-desc"><strong>' + esc( __( 'This page', 'tokenforge' ) ) + '</strong> ' + esc( __( 'Checks the page you are editing in the builder: Accessibility for accessibility problems, Design for how it follows your design tokens. Nothing changes until you press a fix.', 'tokenforge' ) ) + '</p><div class="tf-chk-host" data-chk-host></div></div></section>';
		}
		return html;
	}
	function sectionContent() {
		if ( state.q.trim() ) { return searchHtml(); }
		var sec = section( state.tab );
		if ( sec.render === 'home' ) { return renderHome(); }
		if ( sec.render === 'tokens' ) { return renderTokens(); }
		if ( sec.render === 'attributes' ) { return renderAttributes(); }
		if ( sec.render === 'cheatsheet' ) { return renderCheatsheet(); }
		if ( sec.render === 'wizard' ) { return renderWizard(); }
		if ( sec.render === 'integrations' ) { return renderIntegrations(); }
		var mod = MODULES[ sec.id ], on = ! mod || false !== state.modules[ sec.id ], panels = sec.panels || [];
		var bar = '<div class="tf-secbar">';
		if ( mod ) {
			bar += '<label class="tf-switchrow tf-modswitch"><span class="tf-switch"><input type="checkbox" data-mod="modules" data-k="' + sec.id + '"' + ( on ? ' checked' : '' ) + '><i></i></span><span>' + ( on ? 'Module on' : 'Module off' ) + tipIcon( tipText( 'modules', sec.id ) ) + '</span></label>';
		}
		bar += '<span class="tf-grow"></span><button type="button" class="tf-btn tf-small" data-act="expandall">Open all</button><button type="button" class="tf-btn tf-small" data-act="collapseall">Close all</button>' + ( panels.some( function ( p ) { return p.mod && ( p.fields || p.on ); } ) ? '<button type="button" class="tf-btn tf-small" data-act="resetsec" title="Put every field of this section back to its default">Reset section</button>' : '' ) + '</div>';
		if ( mod && ! on ) { bar += '<p class="tf-notice">This module is off: TokenForge adds nothing from this section. Your settings here are kept.</p>'; }
		if ( 'check' === sec.id ) { bar += hubTop(); }
		if ( 'colors' === sec.id ) { bar += '<p class="tf-muted">' + esc( __( 'Contrast checks for your palette are in Accessibility.', 'tokenforge' ) ) + ' <button type="button" class="tf-btn tf-small" data-act="goto" data-go="check">' + esc( __( 'Open Accessibility', 'tokenforge' ) ) + '</button></p>'; }
		return bar + '<div class="' + ( on ? '' : 'tf-mod-off' ) + '">' + panels.map( function ( p, i ) { return panelHtml( sec, p, i ); } ).join( '' ) + '</div>';
	}

	// ---- Setup wizard ----------------------------------------------------
	// What is ticked: every group and every part of step 2 that still has
	// something to do, until you change it.
	function wzSel() {
		if ( ! state.wz ) {
			var g = {}, c = {};
			( ( state.setup || {} ).groups || [] ).forEach( function ( x ) { g[ x.id ] = x.changes > 0; } );
			( ( state.setup || {} ).connect || [] ).forEach( function ( x ) { c[ x.id ] = ! x.on; } );
			state.wz = { groups: g, connect: c };
		}
		return state.wz;
	}
	function wizardCard() {
		var su = state.setup;
		if ( ! su || ! su.available || su.done ) { return ''; }
		// Nothing to do (set up by hand already): no card.
		var todo = ( su.groups || [] ).reduce( function ( n, g ) { return n + g.changes; }, 0 ) + ( su.connect || [] ).filter( function ( x ) { return ! x.on; } ).length;
		if ( ! todo ) { return ''; }
		return '<section class="tf-panel tf-wz-card"><div class="tf-panel-body"><div><strong>' + esc( __( 'New site? Set up the builder in one go.', 'tokenforge' ) ) + '</strong><span>' + esc( __( 'The setup wizard sets the builder\'s own settings the way TokenForge works best and connects the two. You choose what changes; Undo puts it back.', 'tokenforge' ) ) + '</span></div><button type="button" class="tf-btn tf-primary" data-act="goto" data-go="wizard">' + esc( __( 'Open the setup wizard', 'tokenforge' ) ) + '</button></div></section>';
	}
	function renderWizard() {
		var su = state.setup || {}, sel = wzSel(), n = 0, c = 0, html = '';
		html += '<p class="tf-muted tf-desc">' + esc( __( 'Two steps: the builder\'s own settings (Builder settings in the dashboard), then TokenForge and the builder working together. Settings that are already right are left alone. Nothing else on the site changes, and Undo puts the builder\'s settings back as they were before the first run.', 'tokenforge' ) ) + '</p>';
		if ( su.done ) { html += '<p class="tf-notice tf-wz-done">' + esc( __( 'The wizard ran on %s. You can run it again at any time.', 'tokenforge' ).replace( '%s', new Date( su.done * 1000 ).toLocaleString() ) ) + '</p>'; }
		html += '<section class="tf-panel"><div class="tf-panel-head"><h3>' + esc( __( 'Step 1: builder settings', 'tokenforge' ) ) + '</h3></div><div class="tf-panel-body"><div class="tf-wz-groups">';
		( su.groups || [] ).forEach( function ( g ) {
			var on = !! sel.groups[ g.id ];
			if ( on ) { n += g.changes; }
			html += '<div class="tf-wz-group' + ( on ? '' : ' is-off' ) + '"><label class="tf-wz-head"><input type="checkbox" data-wz="groups" value="' + esc( g.id ) + '"' + ( on ? ' checked' : '' ) + ( g.changes ? '' : ' disabled' ) + '><strong>' + esc( g.title ) + '</strong><span class="tf-wz-badge' + ( g.changes ? '' : ' is-set' ) + '">' + esc( g.changes ? __( '%d to change', 'tokenforge' ).replace( '%d', g.changes ) : __( 'Already set', 'tokenforge' ) ) + '</span></label>'
				+ '<p class="tf-muted">' + esc( g.desc ) + '</p><ul class="tf-wz-items">' + g.items.map( function ( it ) {
					return '<li class="' + ( it.change ? 'is-change' : 'is-set' ) + '"><span aria-hidden="true">' + ( it.change ? '+' : '✓' ) + '</span>' + esc( it.label ) + '<span class="tf-vh"> (' + esc( it.change ? __( 'will change', 'tokenforge' ) : __( 'already set', 'tokenforge' ) ) + ')</span></li>';
				} ).join( '' ) + '</ul></div>';
		} );
		html += '</div></div></section>';
		html += '<section class="tf-panel"><div class="tf-panel-head"><h3>' + esc( __( 'Step 2: connect TokenForge and the builder', 'tokenforge' ) ) + '</h3></div><div class="tf-panel-body"><ul class="tf-wz-connect">';
		( su.connect || [] ).forEach( function ( x ) {
			var on = !! sel.connect[ x.id ];
			if ( on ) { c++; }
			html += '<li><label><input type="checkbox" data-wz="connect" value="' + esc( x.id ) + '"' + ( on ? ' checked' : '' ) + '><span>' + esc( x.label ) + ( x.on ? ' <span class="tf-wz-badge is-set">' + esc( __( 'Already on', 'tokenforge' ) ) + '</span>' : '' ) + '</span></label></li>';
		} );
		html += '</ul></div></section>';
		html += '<p class="tf-wz-actions"><button type="button" class="tf-btn tf-primary" data-act="wzapply"' + ( n || c ? '' : ' disabled' ) + '>' + esc( n || c ? __( 'Apply: %1$s builder settings, %2$s connections', 'tokenforge' ).replace( '%1$s', String( n ) ).replace( '%2$s', String( c ) ) : __( 'Everything is set', 'tokenforge' ) ) + '</button>'
			+ ( su.undo ? ' <button type="button" class="tf-btn" data-act="wzundo" title="' + esc( __( 'Saved before the first run on %s.', 'tokenforge' ).replace( '%s', new Date( su.undo * 1000 ).toLocaleString() ) ) + '">' + esc( __( 'Undo builder settings', 'tokenforge' ) ) + '</button>' : '' ) + '</p>';
		return html;
	}
	function wizardApply() {
		var sel = wzSel(), pick = function ( o ) { return Object.keys( o ).filter( function ( k ) { return o[ k ]; } ); };
		var body = { groups: pick( sel.groups ), connect: pick( sel.connect ) };
		( state.dirty ? save() : Promise.resolve() ).then( function () { setStatus( __( 'Setting up…', 'tokenforge' ) ); return api( '/setup', 'POST', body ); } ).then( function ( res ) {
			if ( ! res.ok ) { throw new Error( res.error || 'failed' ); }
			state.wz = null;
			return load().then( function () {
				announce( res.css_ver, res.css_url );
				setStatus( __( 'Done: %s builder settings changed. Reload the builder if it is open.', 'tokenforge' ).replace( '%s', String( res.changed ) ), 'tf-ok' );
			} );
		} ).catch( function ( e ) { setStatus( 'Failed: ' + e.message, 'tf-err' ); } );
	}

	// Home: every section as a row (like Bricks' theme style groups), with the
	// module switch for the ones that can be switched off.
	// Contrast rows that fail, have a fix and are not changed yet.
	function cxOpen() {
		var c = state.contrast, out = [];
		( c && c.rows || [] ).forEach( function ( r, i ) { if ( ! r.pass && r.fix && ! r.applied ) { out.push( i ); } } );
		return out;
	}
	// Write a row's fix into the setting it comes from (module, key …). Rows sharing
	// that setting count as changed too (the server checked the fix passes for them).
	// Returns how many rows it changed.
	function cxApply( r, value ) {
		if ( ! r || ! ( value || r.fix ) || r.applied ) { return 0; }
		var o = state, n = 0, key = JSON.stringify( r.set );
		for ( var i = 0; i < r.set.length - 1; i++ ) {
			if ( ! o[ r.set[ i ] ] || 'object' !== typeof o[ r.set[ i ] ] || Array.isArray( o[ r.set[ i ] ] ) ) { o[ r.set[ i ] ] = {}; }
			o = o[ r.set[ i ] ];
		}
		o[ r.set[ r.set.length - 1 ] ] = value || r.fix.value;
		state.contrast.rows.forEach( function ( x ) {
			if ( ! x.pass && ! x.applied && JSON.stringify( x.set ) === key ) { x.applied = true; x.sel = false; n++; }
		} );
		return n;
	}
	// ---- Palette contrast: another colour for one pair ----
	// Colour tokens a pair may use (the transparent steps are left out).
	function colourTokens() {
		return Object.keys( tokenMap() ).filter( function ( n ) { return ! /-trans-\d+$/.test( n ) && /^(#|(rgb|hsl|oklch|oklab|lab|lch)a?\()/i.test( resolveColor( 'var(--' + n + ')' ) ); } ).sort();
	}
	function cxRow() { return state.cxEdit ? state.contrast.rows[ state.cxEdit.i ] : null; }
	// The sentence under the picker: the ratio in every mode the palette has, pass or fail in words for each.
	// One setting serves both modes, so both are measured; Apply is on only when every mode passes.
	function cxResultText( e ) {
		var r = cxRow(), many = r.modes.length > 1;
		if ( e.bad ) { return __( 'Type a colour like #1a2b3c.', 'tokenforge' ); }
		if ( ! e.value ) { return __( 'Choose a token or a colour to see its contrast ratio.', 'tokenforge' ); }
		if ( ! e.res ) { return __( 'Measuring…', 'tokenforge' ); }
		if ( ! e.res.ok ) { return __( 'This colour could not be measured.', 'tokenforge' ); }
		var t = e.res.modes.map( function ( x, k ) {
			return ( many ? ( k ? __( 'Dark mode', 'tokenforge' ) : __( 'Light mode', 'tokenforge' ) ) + ': ' : '' ) + ( x.pass ? __( '%1$s:1. Passes: %2$s:1 is needed.', 'tokenforge' ) : __( '%1$s:1. Fails: %2$s:1 is needed.', 'tokenforge' ) ).replace( '%1$s', x.ratio ).replace( '%2$s', r.min );
		} ).join( ' ' );
		if ( e.res.below ) { t += ' ' + __( 'Other pairs that use this setting and would fall below their minimum: %d.', 'tokenforge' ).replace( '%d', e.res.below ); }
		return e.res.pass ? t : t + ' ' + __( 'Choose a colour that passes to use it.', 'tokenforge' );
	}
	// The mode the swatch shows: the first one that fails, else light.
	function cxSwatch( e ) {
		if ( ! e.res || ! e.res.ok ) { return null; }
		var k = 0;
		e.res.modes.some( function ( x, i ) { k = i; return ! x.pass; } );
		return e.res.modes[ k ];
	}
	// Update the picker's result in place (a full redraw would take the focus from the field being typed in).
	// The polite status line only speaks a finished answer (or the format hint once the field is left); "Measuring" and the
	// hint while typing go to a quiet line beside it, so a screen reader is not read every keystroke.
	function cxShow( loud ) {
		var e = state.cxEdit, out = app && app.querySelector( '[data-cxres]' ), hint = app && app.querySelector( '[data-cxhint]' );
		if ( ! e || ! out ) { return; }
		var done = !! e.res || ( e.bad && loud ), text = cxResultText( e );
		out.textContent = done ? text : '';
		if ( hint ) { hint.textContent = done ? '' : text; }
		out.className = 'tf-cx-result' + ( e.res && e.res.ok ? ( e.res.pass ? ' is-pass' : ' is-fail' ) : '' );
		var sw = app.querySelector( '[data-cxsw]' ), ap = app.querySelector( '[data-act="cxcustomapply"]' ), m = cxSwatch( e );
		if ( sw ) { sw.style.color = m ? m.fg : ''; sw.style.background = m ? m.bg : ''; }
		if ( ap ) { ap.disabled = ! ( e.res && e.res.ok && e.res.pass ); }
	}
	// Ask the server (Contrast::pair) for the ratio of the chosen colour on this pair's background in each mode, and on the
	// background of every other pair that uses the same setting.
	function cxMeasure() {
		var e = state.cxEdit, r = cxRow();
		if ( ! e || ! r || ! e.value ) { return; }
		var key = JSON.stringify( r.set ), list = [ r ].concat( state.contrast.rows.filter( function ( x ) { return x !== r && JSON.stringify( x.set ) === key; } ) ), seq = ++e.seq, nm = r.modes.length, ask = [];
		list.forEach( function ( x ) { for ( var k = 0; k < nm; k++ ) { ask.push( { fg: e.value, bg: x.bg, min: x.min, dark: 1 === k } ); } } ); // row by row, light then dark
		api( '/check', 'POST', { measure: ask } ).then( function ( a ) {
			if ( state.cxEdit !== e || seq !== e.seq ) { return; }
			var m = a && a.measure || [], mine = m.slice( 0, nm ).map( function ( x ) { return x && { ratio: x.ratio, pass: !! x.pass, fg: x.fg, bg: x.bg }; } ), below = 0;
			for ( var j = 1; j < list.length; j++ ) { if ( m.slice( j * nm, ( j + 1 ) * nm ).some( function ( x ) { return x && ! x.pass; } ) ) { below++; } }
			e.res = mine.length === nm && mine.every( Boolean ) ? { ok: true, modes: mine, pass: mine.every( function ( x ) { return x.pass; } ), below: below } : { ok: false };
			cxShow();
		} ).catch( function () { if ( state.cxEdit === e && seq === e.seq ) { e.res = { ok: false }; cxShow(); } } );
	}
	// The value changed: forget the old answer and ask again shortly. loud: the field was left, so a format hint may be spoken.
	function cxChanged( loud ) {
		var e = state.cxEdit;
		e.res = null; ++e.seq;
		clearTimeout( e.timer );
		cxShow( loud );
		if ( e.value ) { e.timer = setTimeout( cxMeasure, 250 ); }
	}
	function cxEditor( i, cols ) {
		var e = state.cxEdit, r = state.contrast.rows[ i ], h = '<tr class="tf-cx-edit"><td colspan="' + cols + '"><div class="tf-cx-editor" role="group" aria-labelledby="tf-cx-h">';
		h += '<p id="tf-cx-h"><strong>' + esc( __( 'Another colour for %s', 'tokenforge' ).replace( '%s', r.where + ' ' + r.what ) ) + '</strong> <span class="tf-muted">' + esc( __( 'Changes only this setting, never a token.', 'tokenforge' ) ) + '</span></p><div class="tf-fields">';
		h += '<label class="tf-field"><span class="tf-flabel">' + esc( __( 'Colour token', 'tokenforge' ) ) + '</span><select data-cxtok><option value="">' + esc( __( 'Choose a token…', 'tokenforge' ) ) + '</option>' + colourTokens().map( function ( n ) { return '<option value="' + esc( n ) + '"' + ( e.token === n ? ' selected' : '' ) + '>' + esc( n ) + '</option>'; } ).join( '' ) + '</select></label>';
		h += '<div class="tf-field"><span class="tf-flabel" id="tf-cx-cl">' + esc( __( 'Or a custom colour', 'tokenforge' ) ) + '</span><span class="tf-colorfield" role="group" aria-labelledby="tf-cx-cl"><input type="color" data-cxcolor value="' + esc( e.color ) + '" aria-label="' + esc( __( 'Colour picker', 'tokenforge' ) ) + '"><input type="text" data-cxhex aria-describedby="tf-cx-hint" value="' + esc( e.hex ) + '" placeholder="#1a2b3c" spellcheck="false" aria-label="' + esc( __( 'Colour as hex', 'tokenforge' ) ) + '"></span></div></div>';
		var sw = cxSwatch( e ), done = !! e.res;
		h += '<p><span class="tf-cx" data-cxsw aria-hidden="true"' + ( sw ? ' style="color:' + esc( sw.fg ) + ';background:' + esc( sw.bg ) + '"' : '' ) + '>Aa</span> <span class="tf-cx-result' + ( e.res && e.res.ok ? ( e.res.pass ? ' is-pass' : ' is-fail' ) : '' ) + '" role="status" aria-live="polite" data-cxres>' + ( done ? esc( cxResultText( e ) ) : '' ) + '</span><span class="tf-muted" id="tf-cx-hint" data-cxhint>' + ( done ? '' : esc( cxResultText( e ) ) ) + '</span></p>';
		return h + '<p><button type="button" class="tf-btn tf-primary tf-small" data-act="cxcustomapply" data-i="' + i + '"' + ( e.res && e.res.ok && e.res.pass ? '' : ' disabled' ) + '>' + esc( __( 'Apply', 'tokenforge' ) ) + '</button> <button type="button" class="tf-btn tf-small" data-act="cxcustomclose" data-i="' + i + '">' + esc( __( 'Cancel', 'tokenforge' ) ) + '</button></p></div></td></tr>';
	}
	// A control of the picker changed. Returns true when the event was the picker's.
	function cxInput( t, loud ) {
		var e = state.cxEdit;
		if ( ! e || ! t.hasAttribute || ! ( t.hasAttribute( 'data-cxtok' ) || t.hasAttribute( 'data-cxcolor' ) || t.hasAttribute( 'data-cxhex' ) ) ) { return false; }
		var q = function ( s ) { return app.querySelector( s ); };
		e.bad = false;
		if ( t.hasAttribute( 'data-cxtok' ) ) { e.token = t.value; e.value = t.value ? 'var(--' + t.value + ')' : ''; }
		else {
			var hx = t.hasAttribute( 'data-cxcolor' ) ? t.value : normHex( t.value );
			e.token = ''; q( '[data-cxtok]' ).value = '';
			if ( t.hasAttribute( 'data-cxcolor' ) ) { e.color = hx; e.hex = hx; q( '[data-cxhex]' ).value = hx; }
			else { e.hex = t.value; e.value = hx; e.bad = ! hx && '' !== t.value.trim(); if ( hx ) { e.color = hx; q( '[data-cxcolor]' ).value = hx; } }
			if ( t.hasAttribute( 'data-cxcolor' ) ) { e.value = hx; }
		}
		cxChanged( loud );
		return true;
	}
	function renderHome() {
		var html = wizardCard() + gettingStarted(), lastGroup = '', cx = state.contrast;
		if ( cx && cx.fail ) {
			html += '<section class="tf-panel tf-wz-card"><div class="tf-panel-body"><div><strong>' + esc( __( '%d colour pairs are below the contrast minimum.', 'tokenforge' ).replace( '%d', cx.fail ) ) + '</strong><span>' + esc( __( 'Some text, links, focus rings or buttons may be hard to read. Accessibility > Palette contrast lists them, with a suggested fix where a colour token passes.', 'tokenforge' ) ) + '</span></div><button type="button" class="tf-btn" data-act="cxgo">' + esc( __( 'Check contrast', 'tokenforge' ) ) + '</button></div></section>';
		}
		SECTIONS.forEach( function ( t ) {
			if ( 'home' === t.id || ! shown( t ) ) { return; }
			if ( t.g !== lastGroup ) { html += ( lastGroup ? '</div>' : '' ) + '<h4 class="tf-home-group">' + esc( t.g ) + '</h4><div class="tf-home-list">'; lastGroup = t.g; }
			var mod = 'integrations' !== t.render && MODULES[ t.id ], on = ! mod || false !== state.modules[ t.id ];
			html += '<div class="tf-home-row' + ( on ? '' : ' is-off' ) + '">'
				+ ( mod ? '<span class="tf-switch" title="' + ( on ? 'Module on' : 'Module off' ) + '"><input type="checkbox" data-mod="modules" data-k="' + t.id + '"' + ( on ? ' checked' : '' ) + ' aria-label="' + esc( t.title ) + ' module on/off"><i></i></span>' : '<span class="tf-home-nosw"></span>' )
				+ '<button type="button" class="tf-home-open" data-act="goto" data-go="' + t.id + '">' + icon( t.id ) + '<span class="tf-home-title">' + esc( t.title ) + '</span><span class="tf-home-desc">' + esc( mod || SECTION_DESC[ t.id ] || '' ) + '</span><span class="tf-home-chev" aria-hidden="true"></span></button>'
				+ '</div>';
		} );
		return html + ( lastGroup ? '</div>' : '' );
	}
	// After adding something: bring the new item into view and focus it.
	function focusNew( sel ) {
		setTimeout( function () {
			var el = app && app.querySelector( sel );
			if ( el ) { try { el.scrollIntoView( { block: 'center' } ); } catch ( e ) {} el.focus(); }
		}, 0 );
	}
	// Download a file made in the browser.
	function download( name, text ) {
		var a = document.createElement( 'a' );
		a.href = URL.createObjectURL( new Blob( [ text ], { type: 'application/json' } ) );
		a.download = name;
		( app.getRootNode && app.getRootNode() !== document ? app : document.body ).appendChild( a );
		a.click();
		setTimeout( function () { URL.revokeObjectURL( a.href ); a.remove(); }, 1000 );
	}
	// Bricks builder: the linked theme style after a save (its copy is saved with the page).
	function builderThemeStyle( ts ) {
		if ( ! ts || ! ts.id ) { return; }
		var root = document.querySelector( 'div.brx-body.main' );
		var g = root && root.__vue_app__ && root.__vue_app__.config && root.__vue_app__.config.globalProperties;
		var st = g && ( g.$_state && g.$_state.__v_isRef ? g.$_state.value : g.$_state );
		if ( ! st || ! st.themeStyles || ! st.themeStyles[ ts.id ] ) { return; }
		st.themeStyles[ ts.id ].settings = JSON.parse( JSON.stringify( ts.settings || {} ) );
	}

	function sizeClasses( w ) { return ( w < 720 ? ' is-narrow' : '' ) + ( w < 460 ? ' is-xnarrow' : '' ); }

	// Keep the layout in step with the container (dock resize, window resize).
	function watchSize() {
		if ( ! app || app.__tfRO || typeof window.ResizeObserver !== 'function' ) { return; }
		app.__tfRO = new window.ResizeObserver( function () {
			var root = app.querySelector( '.tf-app' );
			if ( ! root ) { return; }
			var w = app.getBoundingClientRect().width;
			var narrow = w < 720;
			if ( narrow !== root.classList.contains( 'is-narrow' ) ) { render(); return; }
			root.classList.toggle( 'is-xnarrow', w < 460 );
		} );
		app.__tfRO.observe( app );
	}

	// The view the scroll positions belong to: opening a panel or changing a field
	// keeps the reader where they are; another section or another search starts at the top.
	var lastView = null;
	function render() {
		tokenIndex = null;
		var w = 1000;
		try { w = app.getBoundingClientRect().width || 1000; } catch ( e ) {}
		var narrow = w < 720;
		var sec = section( state.tab );
		var view = state.tab + '|' + String( state.q || '' ).trim();
		var oldBody = app.querySelector( '.tf-body' ), oldNav = app.querySelector( '.tf-nav' );
		var bodyTop = oldBody && view === lastView ? oldBody.scrollTop : 0, navTop = oldNav ? oldNav.scrollTop : 0;
		lastView = view;
		// In a narrow window the sidebar (and its search box) closes while you type,
		// so the search box moves into the top bar.
		var findTop = narrow && ( state.q || state.findTop );
		var html = '<div class="tf-app' + sizeClasses( w ) + ( state.navOpen ? ' nav-open' : '' ) + ' tf-' + ( [ 'flat', 'light' ].indexOf( ( state.settings || {} ).ui_theme ) > -1 ? state.settings.ui_theme : 'bricks' ) + '">';
		html += '<aside class="tf-sidebar">';
		html += '<div class="tf-brand"><span class="tf-dot"></span> TokenForge</div>';
		html += '<input class="tf-find" type="search" placeholder="' + esc( __( 'Search settings…', 'tokenforge' ) ) + '" value="' + esc( state.q ) + '" aria-label="' + esc( __( 'Search settings', 'tokenforge' ) ) + '">';
		html += '<nav class="tf-nav">';
		var lastGroup = '';
		SECTIONS.forEach( function ( t ) {
			if ( ! shown( t ) ) { return; }
			if ( t.g && t.g !== lastGroup ) { html += '<div class="tf-navgroup">' + esc( t.g ) + '</div>'; lastGroup = t.g; }
			html += '<button type="button" class="tf-navitem' + ( ! state.q && state.tab === t.id ? ' is-active' : '' ) + '" data-tab="' + t.id + '" title="' + esc( t.title ) + '">' + icon( t.id ) + '<span>' + esc( t.title ) + '</span></button>';
		} );
		html += '</nav></aside>';
		html += '<main class="tf-main">';
		html += '<div class="tf-topbar">';
		if ( narrow ) { html += '<button type="button" class="tf-btn tf-navtoggle" data-act="nav" aria-label="Sections">' + ( state.navOpen ? '✕' : '☰' ) + '</button>'; }
		if ( findTop ) {
			html += '<div class="tf-title tf-title-find"><input class="tf-find tf-find-top" type="search" placeholder="' + esc( __( 'Search settings…', 'tokenforge' ) ) + '" value="' + esc( state.q ) + '" aria-label="' + esc( __( 'Search settings', 'tokenforge' ) ) + '"></div>';
		} else {
			html += '<div class="tf-title">' + ( state.q.trim() ? esc( __( 'Search', 'tokenforge' ) ) : ( narrow ? icon( sec.id ) : '' ) + esc( sec.title ) ) + '</div>';
		}
		html += '<div class="tf-actions"><span class="tf-dirty" title="Unsaved changes"' + ( state.dirty ? '' : ' hidden' ) + '></span><span class="tf-status">' + ( state.dirty ? 'Unsaved changes' : '' ) + '</span>';
		html += '<button type="button" class="tf-btn tf-discard" data-act="discard" title="' + esc( __( 'Undo every change since the last Save', 'tokenforge' ) ) + '"' + ( state.dirty ? '' : ' hidden' ) + '>' + esc( __( 'Discard changes', 'tokenforge' ) ) + '</button>';
		html += '<button type="button" class="tf-btn" data-act="regen" title="Rebuild the stylesheet">Regenerate</button>';
		html += '<button type="button" class="tf-btn tf-primary" data-act="save" title="' + esc( __( 'Save (Ctrl/Cmd + S)', 'tokenforge' ) ) + '">' + esc( __( 'Save', 'tokenforge' ) ) + '</button></div></div>';
		html += '<div class="tf-body">' + sectionContent() + '</div>';
		html += '</main>' + fontDatalist() + '</div>';
		app.innerHTML = html;
		var ckh = app.querySelector( '[data-chk-host]' );
		if ( ckh && window.TokenForgeCheckPanel ) { window.TokenForgeCheckPanel.attach( ckh, { tokens: colourTokens() } ); }
		var nb = app.querySelector( '.tf-body' ), nn = app.querySelector( '.tf-nav' );
		if ( nb && bodyTop ) { nb.scrollTop = bodyTop; }
		if ( nn && navTop ) { nn.scrollTop = navTop; }
		tipEl = null;
		syncTokAll();
		stickTokbar();
	}

	// Swatch strips of a family: light shades above, dark mode below. While a family
	// is being edited the colour engine draws them, so you see the result before Save.
	function shadeStrips( k, has, pending, pv, order ) {
		var strip = function ( get, cls ) { return '<span class="tf-strip' + cls + '">' + order.map( function ( sh ) { var c = get( sh ); var n = k + ( sh ? '-' + sh : '' ); return c ? '<span class="tf-shade' + ( sh ? '' : ' is-base' ) + '" title="--' + esc( n ) + '" style="background:' + esc( c ) + '"></span>' : ''; } ).join( '' ) + '</span>'; };
		if ( pv && ( pending || ! has ) ) {
			return strip( function ( sh ) { return pv.light[ sh ]; }, '' ) + strip( function ( sh ) { return pv.dark[ sh ]; }, ' is-dark' ) + '<span class="tf-fam-preview">preview</span>';
		}
		if ( has ) {
			return strip( function ( sh ) { return resolveColor( 'var(--' + k + ( sh ? '-' + sh : '' ) + ')' ); }, '' ) + ( pv ? strip( function ( sh ) { return pv.dark[ sh ]; }, ' is-dark' ) : '' );
		}
		return '<span class="tf-muted">pick a base colour</span>';
	}
	function refreshStrips( row, k ) {
		var el = row && row.querySelector( '.tf-shades' ), pal = state.palette || {}, f = ( pal.families || {} )[ k ], o = ( ( state.paletteOrig || {} ).families || {} )[ k ] || {};
		if ( ! el || ! f ) { return; }
		var pending = !! f.hex && ( f.hex !== ( o.hex || '' ) || [ 'alt', 'alt_l', 'alt_c', 'hover_l', 'hover_c' ].some( function ( x ) { return String( f[ x ] || '' ) !== String( o[ x ] || '' ); } ) );
		row.classList.toggle( 'is-pending', pending );
		var has = !! ( tokenMap()[ k ] || tokenMap()[ k + '-dark' ] );
		el.innerHTML = shadeStrips( k, has, pending, f.hex ? TFC.family( k, f.hex, TFC.options( k, pal ) ) : null, [ 'ultra-light', 'light', 'semi-light', '', 'hover', 'semi-dark', 'dark', 'ultra-dark' ] );
	}
	// Suggestions for every font family field: your fonts first (with the fallback you use), then common stacks.
	function fontDatalist() {
		var seen = {}, opts = [];
		var add = function ( v ) { v = String( v || '' ).trim(); if ( v && ! seen[ v ] ) { seen[ v ] = 1; opts.push( v ); } };
		var fams = ( ( state.fonts || {} ).fonts || [] ).map( function ( f ) { return f.family; } ).concat( state.fontChoices || [] );
		fams.forEach( function ( fam ) { fam = String( fam || '' ).replace( /["']/g, '' ).trim(); if ( fam ) { add( '"' + fam + '", sans-serif' ); add( '"' + fam + '", serif' ); } } );
		[ 'inherit', 'system-ui, sans-serif', '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif', 'Georgia, "Times New Roman", serif', 'ui-serif, Georgia, serif', 'ui-monospace, SFMono-Regular, Menlo, Consolas, monospace' ].forEach( add );
		return '<datalist id="tf-font-families">' + opts.map( function ( o ) { return '<option value="' + esc( o ) + '"></option>'; } ).join( '' ) + '</datalist>';
	}
	function fontPreviews() {
		var css = ( ( state.fonts || {} ).fonts || [] ).map( function ( f, i ) {
			var src = [ f.src, f.src2 ].filter( Boolean ).map( function ( u ) { return 'url("' + String( u ).replace( /["()\\]/g, '' ) + '")'; } ).join( ',' );
			return src ? '@font-face{font-family:"tf-preview-' + i + '";src:' + src + ';font-weight:' + ( /^[\d ]+$|^(normal|bold)$/.test( f.weight ) ? f.weight : '400' ) + ';font-style:' + ( f.style || 'normal' ) + ';font-display:swap}' : '';
		} ).join( '\n' );
		try {
			var el = document.getElementById( 'tf-font-previews' );
			if ( ! el && ! css ) { return; }
			if ( ! el ) { el = document.createElement( 'style' ); el.id = 'tf-font-previews'; ( document.head || document.documentElement ).appendChild( el ); }
			el.textContent = css;
		} catch ( e ) {} // previews only
	}
	function fontUpload( input ) {
		var box = input.closest( '.tf-font[data-i]' ), item = box && ( state.fonts.fonts || [] )[ parseInt( box.getAttribute( 'data-i' ), 10 ) ];
		var fileObj = input.files && input.files[0];
		if ( ! item || ! fileObj ) { return; }
		var fd = new FormData();
		fd.append( 'file', fileObj );
		setStatus( 'Uploading ' + fileObj.name + '…' );
		fetch( cfg.restUrl + '/font-upload', { method: 'POST', credentials: 'same-origin', headers: { 'X-WP-Nonce': cfg.nonce }, body: fd } )
			.then( function ( r ) { if ( ! r.ok ) { throw new Error( 'HTTP ' + r.status ); } return r.json(); } )
			.then( function ( res ) {
				if ( ! res.ok ) { throw new Error( res.error || 'failed' ); }
				item[ input.getAttribute( 'data-slot' ) || 'src' ] = res.url;
				if ( ! item.family ) { item.family = res.family || ''; }
				if ( /variable|vf|\[/i.test( fileObj.name ) ) { item.variable = true; if ( '400' === item.weight ) { item.weight = '100 900'; } }
				markDirty(); render();
				setStatus( 'Uploaded. Save to add the font to the stylesheet.', 'tf-ok' );
			} )
			.catch( function ( e ) { setStatus( 'Upload failed: ' + e.message, 'tf-err' ); } );
	}

	// Render again and put the caret back in the field being typed in (the visible
	// one when the same field exists twice, like the search box in a narrow window).
	function rerenderKeepFocus( sel ) {
		var a = document.activeElement, pos = null;
		if ( a && a.matches && a.matches( sel ) ) { try { pos = [ a.selectionStart, a.selectionEnd ]; } catch ( e ) {} }
		var prefer = a && a.classList && a.classList.contains( 'tf-find-top' ) ? '.tf-find-top' : '';
		render();
		if ( ! app.querySelectorAll ) { return; }
		var list = [].slice.call( app.querySelectorAll( sel + prefer ) ).concat( [].slice.call( app.querySelectorAll( sel ) ) );
		var f = list.filter( function ( x ) { return x.getClientRects().length; } )[0];
		if ( ! f ) { return; }
		f.focus();
		try { var p = pos && null !== pos[0] ? pos : [ f.value.length, f.value.length ]; f.setSelectionRange( p[0], p[1] ); } catch ( er ) {}
	}

	// ---- Events ----------------------------------------------------------
	function attachEvents() {
		if ( ! app || app.__tfBound ) { return; }
		app.__tfBound = true;

		app.addEventListener( 'keydown', function ( e ) {
			var t = e.target;
			if ( state.addForm && t && t.closest && t.closest( '.tf-tokadd' ) && ( 'Enter' === e.key || 'Escape' === e.key ) && 'BUTTON' !== t.tagName ) {
				e.preventDefault();
				if ( 'Enter' === e.key ) { addToken(); } else { state.addForm = null; renderKeepScroll(); }
				return;
			}
			// Escape in the settings search: clear it and go back to the section.
			if ( t && t.classList && t.classList.contains( 'tf-find' ) && 'Escape' === e.key ) {
				e.preventDefault();
				state.q = ''; state.findTop = false;
				render();
				var sf = app.querySelectorAll && [].slice.call( app.querySelectorAll( '.tf-find' ) ).filter( function ( x ) { return x.getClientRects().length; } )[0];
				if ( sf ) { sf.focus(); }
				return;
			}
			// Panel headers open and close with Enter or Space too.
			if ( t && t.classList && t.classList.contains( 'tf-panel-head' ) && ( 'Enter' === e.key || ' ' === e.key ) ) {
				e.preventDefault();
				var hp = t.getAttribute( 'data-pid' );
				if ( state.open[ hp ] ) { delete state.open[ hp ]; } else { state.open[ hp ] = true; }
				saveOpen(); render();
				var again = app.querySelector( '.tf-panel-head[data-pid="' + ( window.CSS && CSS.escape ? CSS.escape( hp ) : hp ) + '"]' );
				if ( again ) { again.focus(); }
				return;
			}
			if ( t && t.classList && t.classList.contains( 'tf-keyrec' ) ) {
				if ( 'Tab' === e.key && ! e.ctrlKey && ! e.altKey && ! e.metaKey ) { return; }
				e.preventDefault(); e.stopPropagation();
				var km = t.getAttribute( 'data-mod' ) || 'settings', kk = t.getAttribute( 'data-k' ) || 'panel_shortcut';
				if ( 'Backspace' === e.key || 'Delete' === e.key ) { state[ km ][ kk ] = ''; t.value = ''; markDirty(); return; }
				var combo = comboFromEvent( e );
				if ( null === combo ) { return; } // a modifier on its own: wait for the key
				if ( '' === combo ) { setStatus( 'Use Ctrl, Alt or Cmd with a letter (or an F key).', 'tf-err' ); return; }
				state[ km ][ kk ] = combo; t.value = keyLabel( combo ); markDirty();
				return;
			}
			if ( ( e.ctrlKey || e.metaKey ) && ( e.key === 's' || e.key === 'S' ) ) { e.preventDefault(); e.stopPropagation(); save(); }
		} );

		// Tooltips: hover, keyboard focus, tap.
		app.addEventListener( 'mouseover', function ( e ) { var tp = e.target.closest && e.target.closest( '.tf-tip' ); if ( tp ) { showTip( tp ); } } );
		app.addEventListener( 'mouseout', function ( e ) { if ( e.target.closest && e.target.closest( '.tf-tip' ) ) { hideTip(); } } );
		app.addEventListener( 'focusin', function ( e ) { if ( e.target.classList && e.target.classList.contains( 'tf-tip' ) ) { showTip( e.target ); } } );
		app.addEventListener( 'focusout', function ( e ) { if ( e.target.classList && e.target.classList.contains( 'tf-tip' ) ) { hideTip(); } } );
		app.addEventListener( 'scroll', hideTip, true );

		app.addEventListener( 'click', function ( e ) {
			var t = e.target;
			var tipBtn = t.closest && t.closest( '.tf-tip' );
			if ( tipBtn ) { e.preventDefault(); e.stopPropagation(); showTip( tipBtn ); return; }
			var nav = t.closest( '.tf-navitem[data-tab]' );
			if ( nav ) { state.tab = nav.getAttribute( 'data-tab' ); state.q = ''; state.findTop = false; state.navOpen = false; render(); var b = app.querySelector( '.tf-body' ); if ( b ) { b.scrollTop = 0; } return; }
			var grp = t.closest( '.tf-subtab[data-group]' );
			if ( grp ) { state.activeGroup = grp.getAttribute( 'data-group' ); render(); return; }
			var el = t.closest( '[data-act]' );
			if ( ! el ) { return; }
			var act = el.getAttribute( 'data-act' );
			if ( act === 'collapse' ) {
				if ( t.closest( '.tf-switch' ) ) { return; } // the on/off switch lives in the header
				var pid = el.getAttribute( 'data-pid' ), wasOpen = !! state.open[ pid ];
				if ( wasOpen ) { delete state.open[ pid ]; } else { state.open[ pid ] = true; }
				saveOpen();
				render(); return;
			}
			if ( act === 'save' ) { save(); return; }
			if ( act === 'discard' ) {
				// Back to what was saved: reload everything from the server.
				if ( ! state.dirty || ! window.confirm( __( 'Discard every change since the last Save?', 'tokenforge' ) ) ) { return; }
				state.addForm = null;
				load().then( function () { setStatus( __( 'Changes discarded.', 'tokenforge' ), 'tf-ok' ); } ).catch( function ( e ) { setStatus( 'Failed: ' + e.message, 'tf-err' ); } );
				return;
			}
			if ( act === 'regen' ) { regen(); return; }
			if ( act === 'reseed' ) { reseed(); return; }
			if ( act === 'expandall' || act === 'collapseall' ) {
				var cs = section( state.tab );
				( cs.panels || [] ).forEach( function ( p ) { var id = cs.id + ':' + strip( p.key || p.title ); if ( 'expandall' === act ) { state.open[ id ] = true; } else { delete state.open[ id ]; } } );
				saveOpen(); render(); return;
			}
			if ( act === 'resetsec' ) {
				var rs = section( state.tab );
				if ( ! window.confirm( 'Put every field in "' + rs.title + '" back to its default? Nothing is saved until you press Save.' ) ) { return; }
				( rs.panels || [] ).filter( has ).forEach( function ( p ) {
					if ( p.mod && state[ p.mod ] && p.on && undefined !== defOf( p.mod, p.on ) ) { state[ p.mod ][ p.on ] = defOf( p.mod, p.on ); }
					( p.fields || [] ).filter( has ).forEach( function ( f ) {
						if ( p.mod && state[ p.mod ] && undefined !== defOf( p.mod, f.k ) ) { state[ p.mod ][ f.k ] = JSON.parse( JSON.stringify( defOf( p.mod, f.k ) ) ); }
					} );
				} );
				markDirty(); render(); setStatus( 'Section reset to defaults. Save to apply.', 'tf-ok' ); return;
			}
			if ( act === 'export' ) {
				api( '/export' ).then( function ( data ) {
					var host = String( data.site || '' ).replace( /^https?:\/\//, '' ).replace( /[^a-z0-9.-]+/gi, '-' ).replace( /-+$/, '' );
					download( 'tokenforge-' + ( host || 'site' ) + '-' + new Date().toISOString().slice( 0, 10 ) + '.json', JSON.stringify( data, null, '\t' ) );
					setStatus( 'Export downloaded.', 'tf-ok' );
				} ).catch( function ( er ) { setStatus( 'Export failed: ' + er.message, 'tf-err' ); } );
				return;
			}
			if ( act === 'tokexport' ) {
				api( '/token-files' ).then( function ( d ) {
					download( d.files[0], JSON.stringify( d.light, null, '\t' ) );
					setTimeout( function () { download( d.files[1], JSON.stringify( d.dark, null, '\t' ) ); }, 400 );
					state.tok = state.tok || {}; state.tok.exported = d; render();
					setStatus( __( 'Token files downloaded.', 'tokenforge' ), 'tf-ok' );
				} ).catch( function ( er ) { setStatus( 'Export failed: ' + er.message, 'tf-err' ); } );
				return;
			}
			if ( act === 'tokclear' ) { state.tok = { exported: state.tok && state.tok.exported }; render(); return; }
			if ( act === 'tokapply' ) {
				var tk = state.tok || {};
				if ( ! window.confirm( __( 'Save these token changes? A history copy is taken first, so you can undo it under History.', 'tokenforge' ) ) ) { return; }
				setStatus( 'Importing…' );
				( state.dirty ? save() : Promise.resolve() ).then( function () {
					return api( '/token-files/import', 'POST', { light: tk.light ? tk.light.text : null, dark: tk.dark ? tk.dark.text : null } );
				} ).then( function ( res ) {
					if ( ! res.ok ) { throw new Error( res.error || 'failed' ); }
					state.tok = { exported: tk.exported };
					return load().then( function () { announceState(); setStatus( __( 'Token files imported: %s tokens saved.', 'tokenforge' ).replace( '%s', res.saved ), 'tf-ok' ); } );
				} ).catch( function ( er ) { setStatus( 'Import failed: ' + er.message, 'tf-err' ); } );
				return;
			}
			if ( act === 'importclear' ) { state.transferFile = null; state.transferName = ''; render(); return; }
			if ( act === 'importrun' ) {
				var parts = Object.keys( state.transferFile && state.transferFile.data || {} ).filter( function ( k ) { return false !== state.transferParts[ k ]; } );
				if ( ! parts.length ) { setStatus( 'Choose at least one part.', 'tf-err' ); return; }
				if ( ! window.confirm( 'Import ' + parts.length + ' part(s) from ' + state.transferName + '? The same parts on this site are replaced.' ) ) { return; }
				setStatus( 'Importing…' );
				api( '/import', 'POST', { file: state.transferFile, parts: parts } ).then( function ( res ) {
					if ( ! res.ok ) { throw new Error( res.error || 'failed' ); }
					state.transferFile = null; state.transferName = '';
					return load().then( function () { announceState(); setStatus( 'Imported: ' + res.imported.join( ', ' ) + '.', 'tf-ok' ); } );
				} ).catch( function ( er ) { setStatus( 'Import failed: ' + er.message, 'tf-err' ); } );
				return;
			}
			if ( act === 'resetrun' ) {
				var rp = Object.keys( state.resetParts ).filter( function ( k ) { return state.resetParts[ k ]; } );
				if ( ! rp.length ) { setStatus( 'Choose what to reset.', 'tf-err' ); return; }
				if ( ! window.confirm( 'Reset ' + rp.join( ', ' ) + ' to the defaults? You can undo it under History.' ) ) { return; }
				setStatus( 'Resetting…' );
				api( '/reset', 'POST', { parts: rp } ).then( function ( res ) {
					if ( ! res.ok ) { throw new Error( res.error || 'failed' ); }
					return load().then( function () { announceState(); setStatus( 'Reset done.', 'tf-ok' ); } );
				} ).catch( function ( er ) { setStatus( 'Reset failed: ' + er.message, 'tf-err' ); } );
				return;
			}
			if ( act === 'themesetup' ) {
				( state.dirty ? save() : Promise.resolve() ).then( function () { setStatus( 'Linking the theme style…' ); return api( '/bricks-setup', 'POST', {} ); } ).then( function ( res ) {
					if ( ! res.ok ) { throw new Error( res.error || 'failed' ); }
					builderThemeStyle( res.theme_style );
					state.theme = res.theme || state.theme;
					render();
					setStatus( res.created ? 'Created the theme style ' + ( state.theme.label || res.style ) + '.' : 'Theme style ' + ( state.theme.label || res.style ) + ' is in sync.', 'tf-ok' );
				} ).catch( function ( er ) { setStatus( 'Theme style: ' + er.message, 'tf-err' ); } );
				return;
			}
			if ( act === 'importacss' ) { importAcss(); return; }
			if ( act === 'cxfix' || act === 'cxfixall' || act === 'cxfixsel' ) {
				var cxList = 'cxfix' === act ? [ +el.getAttribute( 'data-i' ) ] : cxOpen().filter( function ( i ) { return 'cxfixall' === act || state.contrast.rows[ i ].sel; } );
				var cxDone = cxList.reduce( function ( n, i ) { return n + cxApply( state.contrast.rows[ i ] ); }, 0 );
				if ( ! cxDone ) { return; }
				markDirty(); render();
				if ( 'cxfix' !== act ) { setStatus( __( '%d fixes used. Save to keep them.', 'tokenforge' ).replace( '%d', cxDone ) ); }
				return;
			}
			if ( act === 'cxgo' ) { state.tab = 'check'; state.q = ''; state.open[ 'check:Palette contrast' ] = true; saveOpen(); render(); return; }
			if ( act === 'cxcustom' ) {
				var cxi = +el.getAttribute( 'data-i' ), cxr = state.contrast.rows[ cxi ];
				if ( state.cxEdit && state.cxEdit.i === cxi ) { state.cxEdit = null; render(); focusNew( '[data-act="cxcustom"][data-i="' + cxi + '"]' ); return; }
				var cxm = Math.max( 0, cxr.modes.findIndex( function ( m ) { return m.ratio < cxr.min; } ) ); // the first mode that fails
				state.cxEdit = { i: cxi, token: '', color: normHex( cxr.modes[ cxm ].fg ) || '#000000', hex: '', value: '', bad: false, res: null, seq: 0, timer: 0 };
				render(); focusNew( '[data-cxtok]' ); return;
			}
			if ( act === 'cxcustomclose' ) { var cxc = +el.getAttribute( 'data-i' ); state.cxEdit = null; render(); focusNew( '[data-act="cxcustom"][data-i="' + cxc + '"]' ); return; }
			if ( act === 'cxcustomapply' ) {
				var cxe = state.cxEdit, cxa = +el.getAttribute( 'data-i' );
				if ( ! cxe || ! cxe.res || ! cxe.res.ok || ! cxApply( state.contrast.rows[ cxa ], cxe.value ) ) { return; }
				state.cxEdit = null; markDirty(); render();
				setStatus( __( 'Changed to %s. Save to keep it.', 'tokenforge' ).replace( '%s', cxe.token || cxe.value ) );
				focusNew( '[data-cxdone="' + cxa + '"]' ); return;
			}
			if ( act === 'histdiff' ) {
				var hid = +el.getAttribute( 'data-id' );
				if ( state.historyDiff && state.historyDiff.id === hid ) { state.historyDiff = null; render(); return; }
				state.historyDiff = { id: hid }; render();
				api( '/history/' + hid ).then( function ( res ) {
					if ( ! res.ok ) { throw new Error( res.error || 'failed' ); }
					if ( state.historyDiff && state.historyDiff.id === hid ) { state.historyDiff = { id: hid, changes: res.changes, total: res.total }; render(); }
				} ).catch( function ( er ) { state.historyDiff = null; render(); setStatus( er.message, 'tf-err' ); } );
				return;
			}
			if ( act === 'histrestore' ) {
				var rid = +el.getAttribute( 'data-id' );
				if ( state.dirty && ! window.confirm( __( 'You have unsaved changes; restoring drops them. Go on?', 'tokenforge' ) ) ) { return; }
				if ( ! window.confirm( __( 'Put this copy back? Settings, tokens, colour families and fonts go back to how they were then. You can undo this too.', 'tokenforge' ) ) ) { return; }
				setStatus( __( 'Restoring…', 'tokenforge' ) );
				api( '/history/' + rid + '/restore', 'POST', {} ).then( function ( res ) {
					if ( ! res.ok ) { throw new Error( res.error || 'failed' ); }
					state.historyDiff = null; state.dirty = false;
					return load().then( function () { announceState(); setStatus( __( 'Restored.', 'tokenforge' ), 'tf-ok' ); } );
				} ).catch( function ( er ) { setStatus( __( 'Restore failed: %s', 'tokenforge' ).replace( '%s', er.message ), 'tf-err' ); } );
				return;
			}
			if ( act === 'gencolor' ) { genColor(); return; }
			if ( act === 'rmfam' ) {
				var rf = el.getAttribute( 'data-fam' );
				if ( ! window.confirm( 'Remove the colour family "' + rf + '" and all its variables?' ) ) { return; }
				( state.dirty ? save() : Promise.resolve() ).then( function () { return api( '/remove-color', 'POST', { name: rf } ); } )
					.then( function ( res ) { if ( ! res.ok ) { throw new Error( res.error || 'failed' ); } return load(); } )
					.then( function () { announceState(); setStatus( 'Family removed.', 'tf-ok' ); } )
					.catch( function ( err ) { setStatus( 'Failed: ' + err.message, 'tf-err' ); } );
				return;
			}
			if ( act === 'nav' ) { state.navOpen = ! state.navOpen; render(); return; }
			if ( act === 'gshide' || act === 'gsshow' ) { try { if ( act === 'gshide' ) { window.localStorage.setItem( GS_KEY, '1' ); } else { window.localStorage.removeItem( GS_KEY ); } } catch ( e ) {} render(); return; }
			if ( act === 'goto' ) { state.tab = el.getAttribute( 'data-go' ); state.q = ''; state.findTop = false; render(); return; }
			if ( act === 'copy' ) { copyText( el.getAttribute( 'data-copy' ), el ); return; }
			if ( act === 'cheatkind' ) { state.cheatKind = el.getAttribute( 'data-kind' ); render(); return; }
			if ( act === 'cheatmore' ) { state.cheatOpen[ el.getAttribute( 'data-g' ) ] = true; render(); return; }
			if ( act === 'keyclear' ) { e.preventDefault(); state[ el.getAttribute( 'data-mod' ) || 'settings' ][ el.getAttribute( 'data-k' ) || 'panel_shortcut' ] = ''; markDirty(); render(); return; }
			if ( act === 'reset' ) {
				e.preventDefault();
				var mod = el.getAttribute( 'data-mod' ), k = el.getAttribute( 'data-k' );
				if ( state[ mod ] ) { state[ mod ][ k ] = defOf( mod, k ); markDirty(); render(); }
				return;
			}
			if ( act === 'add' ) {
				// The add form: name, value and a category (or a new one).
				state.addForm = state.addForm ? null : { name: '', value: '', group: state.activeGroup, fresh: '', msg: '' };
				renderKeepScroll();
				var fin = app.querySelector( '.tf-tokadd-name' );
				if ( fin ) { fin.focus(); }
				return;
			}
			if ( act === 'tokadd-save' ) { addToken(); return; }
			if ( act === 'tokadd-cancel' ) { state.addForm = null; renderKeepScroll(); return; }
			if ( act === 'tokuse' ) {
				var tur = t.closest( 'tr' ), tun = tur && ( tur.getAttribute( 'data-cat' ) || ( state.tokens[ parseInt( tur.getAttribute( 'data-i' ), 10 ) ] || {} ).name );
				if ( tun ) { useSettings( [ tun ] ); markDirty(); renderKeepScroll(); }
				return;
			}
			if ( act === 'tokover' ) {
				// Change a setting's variable: a token with its value, ready to edit.
				var tor = t.closest( 'tr[data-cat]' ), toc = tor && catalogMap()[ tor.getAttribute( 'data-cat' ) ];
				if ( toc ) {
					state.tokens.push( { name: toc.name, value: toc.value, group: toc.group, enabled: true } );
					tokenIndex = null; markDirty(); renderKeepScroll();
					var tov = app.querySelector( 'tr[data-i="' + ( state.tokens.length - 1 ) + '"] input[data-field="value"]' );
					if ( tov ) { tov.focus(); tov.select(); }
				}
				return;
			}
			if ( act === 'wzapply' ) { wizardApply(); return; }
			if ( act === 'wzundo' ) {
				if ( ! window.confirm( __( 'Put the builder\'s settings back as they were before the wizard?', 'tokenforge' ) ) ) { return; }
				setStatus( __( 'Putting the settings back…', 'tokenforge' ) );
				api( '/setup-undo', 'POST', {} ).then( function ( res ) {
					if ( ! res.ok ) { throw new Error( __( 'There is nothing to undo.', 'tokenforge' ) ); }
					state.setup = res.state; state.wz = null; renderKeepScroll();
					setStatus( __( 'The builder\'s settings are back as they were.', 'tokenforge' ), 'tf-ok' );
				} ).catch( function ( e ) { setStatus( 'Failed: ' + e.message, 'tf-err' ); } );
				return;
			}
			if ( act === 'clsscan' ) {
				// A save that scanned already (the switch was just turned on) has shown the result: scan once.
				( state.dirty ? save() : Promise.resolve() ).then( function ( saved ) { return saved && ( saved.scanned || saved.failed ) ? undefined : classScan( saved ); } );
				return;
			}
			if ( act === 'subsdry' ) {
				( state.dirty ? save() : Promise.resolve() ).then( function () { return api( '/submissions/cleanup' ); } ).then( function ( res ) {
					state.subsDry = res; renderKeepScroll();
					setStatus( _n( 'Dry run: %s submission would be deleted now. Nothing was deleted.', 'Dry run: %s submissions would be deleted now. Nothing was deleted.', res.total ), 'tf-ok' );
				} ).catch( function ( e ) { setStatus( 'Failed: ' + e.message, 'tf-err' ); } );
				return;
			}
			if ( act === 'subsconfirm' && state.subsDry ) {
				if ( state.subsDry.total && ! window.confirm( _n( 'Delete %s submission now? This cannot be undone.', 'Delete %s submissions now? This cannot be undone.', state.subsDry.total ) ) ) { return; }
				api( '/submissions/cleanup', 'POST', { hash: state.subsDry.hash, time: state.subsDry.time } ).then( function ( res ) {
					setStatus( _n( 'Deleted %s submission. The rules are confirmed.', 'Deleted %s submissions. The rules are confirmed.', res.deleted ), 'tf-ok' );
					return api( '/submissions/cleanup' );
				} ).then( function ( res ) { state.subsDry = res; renderKeepScroll(); } ).catch( function ( e ) { setStatus( 'Failed: ' + e.message + ' ' + __( 'Run the dry run again.', 'tokenforge' ), 'tf-err' ); } );
				return;
			}
			if ( act === 'tokscan' ) {
				setStatus( __( 'Looking through the site…', 'tokenforge' ) );
				api( '/token-uses', 'POST', {} ).then( function ( res ) {
					state.siteUsage = ( res && res.usage ) || {};
					renderKeepScroll();
					setStatus( __( 'Checked %s pages and templates, and the builder\'s classes and styles.', 'tokenforge' ).replace( '%s', String( state.siteUsage.posts || 0 ) ), 'tf-ok' );
				} ).catch( function ( e ) { setStatus( 'Failed: ' + e.message, 'tf-err' ); } );
				return;
			}
			if ( act === 'tokunused' ) { state.unusedOnly = ! state.unusedOnly; renderKeepScroll(); return; }
			if ( act === 'attrscan' ) {
				if ( state.attrBusy ) { return; }
				state.attrBusy = true;
				renderKeepScroll();
				setStatus( __( 'Looking through the site…', 'tokenforge' ) );
				api( '/attribute-uses', 'POST', {} ).then( function ( res ) {
					state.attributeUsage = ( res && res.usage ) || {};
					state.attrBusy = false;
					renderKeepScroll();
					attrFocus( 'attrscan' );
					setStatus( __( 'Checked %s pages and templates for data attributes.', 'tokenforge' ).replace( '%s', String( state.attributeUsage.posts || 0 ) ), 'tf-ok' );
				} ).catch( function ( e ) { state.attrBusy = false; renderKeepScroll(); attrFocus( 'attrscan' ); setStatus( 'Failed: ' + e.message, 'tf-err' ); } );
				return;
			}
			if ( act === 'attrunused' ) { state.attrUnused = ! state.attrUnused; renderKeepScroll(); attrFocus( 'attrunused' ); return; }
			if ( act === 'tokdelunused' ) {
				var dels = shownTokens().filter( function ( r ) { return r.t && 'custom' === r.type; } ).map( function ( r ) { return r.t; } );
				if ( ! dels.length || ! window.confirm( __( 'Delete the %d own tokens shown? They are used nowhere. Nothing is saved until you press Save.', 'tokenforge' ).replace( '%d', dels.length ) ) ) { return; }
				state.tokens = state.tokens.filter( function ( x ) { return dels.indexOf( x ) === -1; } );
				tokenIndex = null; markDirty(); renderKeepScroll();
				return;
			}
			if ( act === 'tokuseshown' ) {
				// Every token shown (this group, this filter) that replaces something a setting makes.
				var shown = shownTokens().filter( function ( r ) { return r.t && r.c; } ).map( function ( r ) { return r.t.name; } );
				if ( ! shown.length || ! window.confirm( __( 'Let the settings decide for the %d tokens shown? They go, and what the settings make is used instead. Nothing is saved until you press Save.', 'tokenforge' ).replace( '%d', shown.length ) ) ) { return; }
				useSettings( shown ); markDirty(); renderKeepScroll();
				return;
			}
			if ( act === 'tokoffon' ) {
				// Switch back on every switched-off token the stylesheet still uses.
				offUsed().forEach( function ( x ) { var k = tokenKind( x ); setRowOn( { t: x, i: -1, c: k.c, type: k.type }, true ); } );
				dropMarked(); markDirty(); renderKeepScroll();
				setStatus( __( 'Switched back on. Press Save to keep it.', 'tokenforge' ) );
				return;
			}
			if ( act === 'catrename' ) {
				var cfrom = state.activeGroup, cto = cleanGroup( window.prompt( __( 'New name for the category', 'tokenforge' ), cfrom ) || '' );
				if ( ! cto || cto === cfrom ) { return; }
				state.tokens.forEach( function ( x ) { if ( x.group === cfrom ) { x.group = cto; } } );
				state.newGroups = ( state.newGroups || [] ).filter( function ( g ) { return g !== cfrom; } );
				if ( state.groups.indexOf( cto ) === -1 && state.newGroups.indexOf( cto ) === -1 ) { state.newGroups.push( cto ); }
				state.activeGroup = cto; tokenIndex = null; markDirty(); renderKeepScroll();
				return;
			}
			if ( act === 'catdel' ) {
				var cdel = state.activeGroup, cn = state.tokens.filter( function ( x ) { return x.group === cdel; } ).length;
				if ( cn && ! window.confirm( __( 'Delete the category %1$s? The tokens in it (%2$s) move to other.', 'tokenforge' ).replace( '%1$s', cdel ).replace( '%2$s', String( cn ) ) ) ) { return; }
				state.tokens.forEach( function ( x ) { if ( x.group === cdel ) { x.group = 'other'; } } );
				state.newGroups = ( state.newGroups || [] ).filter( function ( g ) { return g !== cdel; } );
				state.activeGroup = 'other'; tokenIndex = null;
				if ( cn ) { markDirty(); }
				renderKeepScroll();
				return;
			}
			if ( act === 'tokuseall' ) {
				var gp = settingsGaps();
				var msg = __( 'Let the settings decide for %d tokens?', 'tokenforge' ).replace( '%d', gp.total ) + '\n\n'
					+ ( gp.repeat ? '• ' + __( '%d repeat what a setting makes: they go, so later changes to that setting show.', 'tokenforge' ).replace( '%d', gp.repeat ) + '\n' : '' )
					+ ( gp.dead ? '• ' + __( '%d have no effect (another part of the stylesheet sets them): they go.', 'tokenforge' ).replace( '%d', gp.dead ) + '\n' : '' )
					+ ( gp.stale ? '• ' + __( '%d colour tokens left over from an older palette or an import go: Colors decides again.', 'tokenforge' ).replace( '%d', gp.stale ) + '\n' : '' )
					+ '\n' + ( gp.override ? __( '%d tokens with a value of their own, or switched off, stay: use ↺ on each to let its setting decide.', 'tokenforge' ).replace( '%d', gp.override ) + '\n' : '' )
					+ __( 'Your own tokens stay. Nothing is saved until you press Save.', 'tokenforge' );
				if ( ! window.confirm( msg ) ) { return; }
				useSettings(); markDirty(); renderKeepScroll();
				setStatus( __( 'The settings decide now. Press Save to keep it.', 'tokenforge' ) );
				return;
			}
			if ( act === 'del' ) {
				var tr = t.closest( 'tr[data-i]' );
				if ( tr ) { state.tokens.splice( parseInt( tr.getAttribute( 'data-i' ), 10 ), 1 ); tokenIndex = null; markDirty(); renderKeepScroll(); }
				return;
			}
			if ( act === 'fontadd' ) {
				state.fonts.fonts = state.fonts.fonts || [];
				state.fonts.fonts.unshift( { family: '', src: '', src2: '', weight: '400', style: 'normal', display: 'swap', variable: false, stretch: '', variation: '', preload: false, unicode: '' } );
				markDirty(); render(); focusNew( '.tf-font[data-i="0"] input[type="text"]' ); return;
			}
			if ( act === 'gfont' ) {
				var gf = state.gfont || {};
				if ( ! String( gf.family || '' ).trim() ) { setStatus( 'Type a family name, for example Inter.', 'tf-err' ); return; }
				setStatus( 'Downloading ' + gf.family + ' from Google Fonts…' );
				api( '/font-google', 'POST', { family: gf.family, weights: gf.weights, italic: !! gf.italic } ).then( function ( res ) {
					if ( ! res || ! res.ok ) { setStatus( ( res && res.error ) || 'Download failed.', 'tf-err' ); return; }
					state.fonts.fonts = ( res.fonts || [] ).concat( state.fonts.fonts || [] );
					state.gfont.family = '';
					markDirty(); render();
					setStatus( 'Downloaded ' + res.fonts.length + ' file(s).' + ( res.error ? ' ' + res.error : '' ) + ' Save to add them to the stylesheet.', 'tf-ok' );
				} ).catch( function ( e ) { setStatus( 'Download failed: ' + e.message, 'tf-err' ); } );
				return;
			}
			if ( act === 'fontdel' ) {
				( state.fonts.fonts || [] ).splice( parseInt( el.getAttribute( 'data-i' ), 10 ), 1 );
				markDirty(); render(); return;
			}
			if ( act === 'ovadd' ) {
				var ln = el.getAttribute( 'data-list' );
				var lst = state.overlays[ ln ] = state.overlays[ ln ] || [];
				if ( lst.length >= 10 ) { setStatus( 'Up to 10 presets.', 'tf-err' ); return; }
				lst.push( ln === 'overlays'
					? { name: '', color: 'rgb(0 0 0 / .5)', type: 'none', gradient: '', image: '', size: 'cover', position: 'center', attachment: 'scroll', repeat: 'no-repeat', blur: '', opacity: '1', blend: '', radius: '', inset: '0', animation: '', layer: '' }
					: { name: '', color: '', asset: '', asset_is_url: true, size: '200px', position: 'center center', attachment: 'scroll', repeat: 'repeat', relationship: 'none', animation: '', layer: '' } );
				markDirty(); render(); focusNew( '.tf-preset[data-list="' + ln + '"][data-i="' + ( lst.length - 1 ) + '"] input[type="text"]' ); return;
			}
			if ( act === 'ovdel' ) {
				var dl = state.overlays[ el.getAttribute( 'data-list' ) ] || [];
				dl.splice( parseInt( el.getAttribute( 'data-i' ), 10 ), 1 );
				markDirty(); render(); return;
			}
		} );

		app.addEventListener( 'change', function ( e ) {
			var t = e.target;
			if ( cxInput( t, true ) ) { return; }
			var wz = t.getAttribute ? t.getAttribute( 'data-wz' ) : null;
			if ( wz ) { wzSel()[ wz ][ t.value ] = t.checked; renderKeepScroll(); return; }
			var cxs = t.getAttribute ? t.getAttribute( 'data-cxsel' ) : null;
			if ( cxs ) {
				( 'all' === cxs ? cxOpen() : [ +cxs ] ).forEach( function ( i ) { state.contrast.rows[ i ].sel = t.checked; } );
				renderKeepScroll();
				var cxAgain = app.querySelector( '[data-cxsel="' + cxs + '"]' ); // keep keyboard focus on the box
				if ( cxAgain ) { cxAgain.focus(); }
				return;
			}
			if ( t.classList.contains( 'tf-font-file' ) ) { fontUpload( t ); return; }
			if ( t.classList.contains( 'tf-tokfile' ) ) {
				var tf = t.files && t.files[0], which = t.getAttribute( 'data-which' );
				if ( ! tf ) { return; }
				if ( tf.size > 2 * 1024 * 1024 ) { setStatus( __( 'That file is larger than 2 MB.', 'tokenforge' ), 'tf-err' ); return; }
				var tr = new FileReader();
				tr.onload = function () {
					var tk = state.tok = state.tok || {};
					tk[ which ] = { name: tf.name, text: String( tr.result ) };
					api( '/token-files/preview', 'POST', { light: tk.light ? tk.light.text : null, dark: tk.dark ? tk.dark.text : null } ).then( function ( res ) {
						tk.plan = res; render();
					} ).catch( function ( er ) { setStatus( 'Preview failed: ' + er.message, 'tf-err' ); } );
				};
				tr.readAsText( tf );
				return;
			}
			if ( t.classList.contains( 'tf-import-file' ) ) {
				var file = t.files && t.files[0];
				if ( ! file ) { return; }
				if ( file.size > 20 * 1024 * 1024 ) { setStatus( 'That file is too big for an export file.', 'tf-err' ); return; }
				var reader = new FileReader();
				reader.onload = function () {
					var data = null;
					try { data = JSON.parse( String( reader.result ) ); } catch ( er ) { data = null; }
					if ( ! data || 'tokenforge' !== data.plugin || ! data.data ) { setStatus( 'This is not a TokenForge export file.', 'tf-err' ); return; }
					state.transferFile = data; state.transferName = file.name; state.transferParts = {};
					render();
				};
				reader.readAsText( file );
				return;
			}
			if ( t.classList.contains( 'tf-bo-fam' ) ) { state.btnFam = t.value; render(); return; }
			if ( t.classList.contains( 'tf-bo-var' ) ) { state.btnVar = t.value; render(); return; }
		} );

		app.addEventListener( 'input', function ( e ) {
			var t = e.target;
			if ( cxInput( t ) ) { return; }
			var tr = t.closest( 'tr[data-i]' );
			if ( tr && t.getAttribute( 'data-field' ) ) {
				var i = parseInt( tr.getAttribute( 'data-i' ), 10 );
				var field = t.getAttribute( 'data-field' );
				if ( field === 'enabled' ) {
					var tk = tokenKind( state.tokens[ i ] );
					setRowOn( { t: state.tokens[ i ], i: i, c: tk.c, type: tk.type }, t.checked );
					if ( state.tokens[ i ].__drop ) { dropMarked(); markDirty(); renderKeepScroll(); return; }
					tr.classList.toggle( 'tf-row-disabled', ! t.checked ); syncTokAll();
				} else if ( field === 'group' ) {
					var ng = t.value;
					if ( '__new' === ng ) { ng = cleanGroup( window.prompt( __( 'Name of the new category', 'tokenforge' ), '' ) || '' ); }
					if ( ng ) {
						state.tokens[ i ].group = ng;
						if ( state.groups.indexOf( ng ) === -1 && ( state.newGroups || [] ).indexOf( ng ) === -1 ) { state.newGroups = ( state.newGroups || [] ).concat( [ ng ] ); }
					}
					markDirty(); renderKeepScroll(); return;
				} else { state.tokens[ i ][ field ] = t.value; }
				tokenIndex = null;
				if ( field === 'value' ) { var sw = tr.querySelector( '.tf-swatch' ); if ( sw ) { sw.outerHTML = swatch( t.value ); } }
				markDirty(); return;
			}
			if ( t.classList.contains( 'tf-tok-all' ) ) {
				// Every token shown (this group, this filter) on or off; the list keeps its place.
				var want = t.checked, tall = shownTokens().filter( canToggle );
				if ( ! want ) {
					var goOff = tall.filter( rowOn ), stillUsed = goOff.filter( function ( r ) { return ( state.usage || {} )[ r.t ? r.t.name : r.c.name ]; } );
					if ( goOff.length > 3 || stillUsed.length ) {
						var ask = __( 'Switch off %d tokens?', 'tokenforge' ).replace( '%d', goOff.length )
							+ ( stillUsed.length ? '\n\n' + __( 'The stylesheet uses some of them (%s): those places get no value while they are off.', 'tokenforge' ).replace( '%s', stillUsed.slice( 0, 4 ).map( function ( r ) { return '--' + ( r.t ? r.t.name : r.c.name ); } ).join( ', ' ) + ( stillUsed.length > 4 ? ', …' : '' ) ) : '' );
						if ( ! window.confirm( ask ) ) { t.checked = true; syncTokAll(); return; }
					}
				}
				tall.forEach( function ( r ) { setRowOn( r, want ); } );
				dropMarked(); markDirty(); renderKeepScroll(); return;
			}
			var tcr = t.closest( 'tr[data-cat]' );
			if ( tcr && 'enabled' === t.getAttribute( 'data-field' ) ) {
				// A setting's variable switched off (a switched-off token keeps it out).
				var tcc = catalogMap()[ tcr.getAttribute( 'data-cat' ) ];
				if ( tcc ) { setRowOn( { t: null, i: -1, c: tcc, type: 'generated' }, t.checked ); tokenIndex = null; markDirty(); renderKeepScroll(); }
				return;
			}
			if ( state.addForm && t.closest( '.tf-tokadd' ) ) {
				if ( t.classList.contains( 'tf-tokadd-name' ) ) { state.addForm.name = t.value; }
				else if ( t.classList.contains( 'tf-tokadd-value' ) ) { state.addForm.value = t.value; }
				else if ( t.classList.contains( 'tf-tokadd-new' ) ) { state.addForm.fresh = t.value; }
				else if ( t.classList.contains( 'tf-tokadd-group' ) ) {
					state.addForm.group = t.value;
					var nw = app.querySelector( '.tf-tokadd-newwrap' );
					if ( nw ) { nw.hidden = '__new' !== t.value; if ( ! nw.hidden ) { var nwi = nw.querySelector( 'input' ); if ( nwi ) { nwi.focus(); } } }
				}
				return;
			}
			if ( t.classList.contains( 'tf-find' ) ) { state.q = t.value; state.navOpen = false; state.findTop = true; rerenderKeepFocus( '.tf-find' ); return; }
			if ( t.classList.contains( 'tf-cheat-q' ) ) { state.cheatQ = t.value; rerenderKeepFocus( '.tf-cheat-q' ); return; }
			if ( t.classList.contains( 'tf-recipe-q' ) ) { state.recipeQ = t.value; rerenderKeepFocus( '.tf-recipe-q' ); return; }
			if ( t.classList.contains( 'tf-search' ) ) { state.search = t.value; rerenderKeepFocus( '.tf-search' ); return; }
			var pt = t.getAttribute( 'data-pt' );
			if ( pt ) {
				var trow = t.closest( '[data-fam]' ), tk = trow && trow.getAttribute( 'data-fam' ), tm = tk && ( state.palette.families || {} )[ tk ];
				if ( tm ) { tm[ pt ] = t.value.trim(); markDirty(); refreshStrips( trow, tk ); }
				return;
			}
			var pf = t.getAttribute( 'data-pf' );
			if ( pf ) {
				var frow = t.closest( '[data-fam]' ), fk = frow && frow.getAttribute( 'data-fam' ), fm = fk && ( state.palette.families || {} )[ fk ];
				if ( ! fm ) { return; }
				if ( 'on' === pf ) { fm.on = t.checked; frow.classList.toggle( 'is-off', ! t.checked ); markDirty(); return; }
				var hv = normHex( t.value );
				if ( 'color' === t.type ) { var tx = frow.querySelector( 'input[type="text"][data-pf="' + pf + '"]' ); if ( tx ) { tx.value = hv; } }
				else if ( hv ) { var pk2 = frow.querySelector( 'input[type="color"][data-pf="' + pf + '"]' ); if ( pk2 ) { pk2.value = hv; } }
				else if ( t.value.trim() ) { return; } // not a colour (yet)
				fm[ pf ] = hv;
				var fo = ( ( state.paletteOrig || {} ).families || {} )[ fk ] || {};
				frow.classList.toggle( 'is-pending', !! fm.hex && ( fm.hex !== ( fo.hex || '' ) || ( fm.alt || '' ) !== ( fo.alt || '' ) ) );
				markDirty(); refreshStrips( frow, fk ); return;
			}
			if ( t.classList.contains( 'tf-color-pick' ) ) { var hx = app.querySelector( '.tf-color-hex' ); if ( hx ) { hx.value = t.value; } return; }
			if ( t.classList.contains( 'tf-color-hex' ) ) { var pk = app.querySelector( '.tf-color-pick' ); if ( pk && isHex( t.value ) ) { pk.value = t.value; } return; }
			if ( t.classList.contains( 'tf-bo-fam' ) || t.classList.contains( 'tf-bo-var' ) ) { return; }
			var bo = t.getAttribute( 'data-bo' );
			if ( bo ) {
				var all = state.components.btn_overrides;
				if ( ! all || Array.isArray( all ) ) { all = state.components.btn_overrides = {}; }
				all[ state.btnFam ] = all[ state.btnFam ] && ! Array.isArray( all[ state.btnFam ] ) ? all[ state.btnFam ] : {};
				var v2 = all[ state.btnFam ][ state.btnVar ] = all[ state.btnFam ][ state.btnVar ] && ! Array.isArray( all[ state.btnFam ][ state.btnVar ] ) ? all[ state.btnFam ][ state.btnVar ] : {};
				if ( t.value.trim() ) { v2[ bo ] = t.value; } else { delete v2[ bo ]; }
				var s2 = t.parentNode.querySelector( '.tf-swatch' ); if ( s2 ) { s2.outerHTML = swatch( t.value || t.placeholder ); }
				var bp = app.querySelector( '.tf-btn-preview' ); if ( bp ) { bp.outerHTML = CUSTOM.btnPreview(); }
				markDirty(); return;
			}
			var nk = t.getAttribute( 'data-native' );
			if ( nk ) { state.bricksNative[ nk ] = t.checked; markDirty(); return; }
			var part = t.getAttribute( 'data-part' );
			if ( part ) {
				if ( 'import' === part ) { state.transferParts[ t.getAttribute( 'data-p' ) ] = t.checked; } else { state.resetParts[ t.getAttribute( 'data-p' ) ] = t.checked; }
				return;
			}
			var gk = t.getAttribute( 'data-gfont' );
			if ( gk ) { state.gfont[ gk ] = 'checkbox' === t.type ? t.checked : t.value; return; }
			var fk = t.getAttribute( 'data-font' );
			if ( fk ) {
				var fbox = t.closest( '.tf-font[data-i]' ), fit = fbox && ( state.fonts.fonts || [] )[ parseInt( fbox.getAttribute( 'data-i' ), 10 ) ];
				if ( fit ) {
					fit[ fk ] = 'checkbox' === t.type ? t.checked : t.value;
					markDirty();
					if ( 'variable' === fk ) { render(); return; }
					if ( 'family' === fk ) { var fn = fbox.querySelector( '.tf-font-name' ); if ( fn ) { fn.textContent = t.value || 'Font'; } }
					if ( 'src' === fk || 'src2' === fk || 'weight' === fk || 'style' === fk ) { fontPreviews(); }
				}
				return;
			}
			var ovl = t.getAttribute( 'data-ovl' );
			if ( ovl ) {
				var box = t.closest( '.tf-preset[data-list]' );
				var item = box && ( state.overlays[ box.getAttribute( 'data-list' ) ] || [] )[ parseInt( box.getAttribute( 'data-i' ), 10 ) ];
				if ( item ) { item[ ovl ] = t.type === 'checkbox' ? t.checked : t.value; markDirty(); refreshPreset( box, 'overlays' === box.getAttribute( 'data-list' ) ? 'overlay' : 'surface', item ); }
				return;
			}
			var mod = t.getAttribute( 'data-mod' ), k = t.getAttribute( 'data-k' );
			if ( mod && k && state[ mod ] ) {
				state[ mod ][ k ] = t.type === 'checkbox' ? t.checked : t.value;
				markDirty();
				if ( 'modules' === mod ) { render(); return; }
				if ( 'components' === mod && 'subs_cleanup' === k ) { state.subsDry = null; renderKeepScroll(); return; } // show or hide the dry run
				// Live bits: panel on/off, colour swatch, readouts.
				if ( t.closest( '.tf-head-switch' ) ) {
					var panel = t.closest( '.tf-panel' );
					if ( panel ) { panel.classList.toggle( 'is-disabled', ! t.checked ); var body = panel.querySelector( '.tf-panel-body' ); if ( body ) { body.classList.toggle( 'is-off', ! t.checked ); } }
				}
				var cf = t.closest( '.tf-colorfield' );
				if ( cf ) { var sw2 = cf.querySelector( '.tf-swatch' ); if ( sw2 ) { sw2.outerHTML = swatch( t.value || t.placeholder ); } }
				if ( mod === 'defaults' && k === 'root_font_size' ) { var rp = app.querySelector( '.tf-root-px' ); if ( rp ) { rp.value = ( Math.round( 16 * ( parseFloat( t.value ) || 100 ) ) / 100 ) + 'px'; } }
				if ( mod === 'width' && k === 'content_width' ) { var wr = app.querySelector( '.tf-width-rem' ); if ( wr ) { wr.value = t.value ? ( Math.round( t.value / 16 * 10000 ) / 10000 ) + 'rem' : ''; } }
			}
		} );
	}

	// Tell every open tab (front end, Bricks canvas, block editor, builder menus)
	// that the stylesheet changed, so they hot-reload it.
	var channel = null;
	try { channel = typeof window.BroadcastChannel === 'function' ? new window.BroadcastChannel( 'tokenforge' ) : null; } catch ( e ) {}
	function announce( ver, url ) {
		if ( ! ver ) { return; }
		var msg = { type: 'tokenforge:css', ver: String( ver ), url: url || '' };
		try { if ( channel ) { channel.postMessage( msg ); } } catch ( e ) {}
		try { window.dispatchEvent( new CustomEvent( 'tokenforge:css', { detail: msg } ) ); } catch ( e2 ) {}
	}
	function announceState() { announce( state.cssVer, state.cssUrl ); }

	function genColor() {
		var name = ( app.querySelector( '.tf-color-name' ) || {} ).value || '';
		var hex = ( app.querySelector( '.tf-color-hex' ) || {} ).value || '';
		if ( ! name || ! isHex( hex ) ) { setStatus( 'Enter a name and a valid hex colour.', 'tf-err' ); return; }
		( state.dirty ? save() : Promise.resolve() ).then( function () { setStatus( 'Generating…' ); return api( '/add-color', 'POST', { name: name, hex: hex } ); } )
			.then( function ( res ) { if ( ! res.ok ) { throw new Error( res.error || 'failed' ); } return load(); } )
			.then( function () { announceState(); setStatus( 'Colour family generated.', 'tf-ok' ); } )
			.catch( function ( err ) { setStatus( 'Failed: ' + err.message, 'tf-err' ); } );
	}

	// Read the site for the utility classes it uses and rebuild the stylesheet (the Scan now button; a save that switched "Leave out unused" on).
	// `saved` is the Save message ({ text, cls }) when a save came first: it is shown again with the scan result, so the scan does not hide it.
	function classScan( saved ) {
		if ( saved ) { saved.scanned = true; }
		setStatus( __( 'Looking through the site…', 'tokenforge' ) );
		return api( '/class-uses', 'POST', {} ).then( function ( res ) {
			state.classUsage = ( res && res.usage ) || {};
			state.attributeUsage = res.attribute_usage || state.attributeUsage;
			announce( res.css_ver, res.css_url );
			renderKeepScroll();
			if ( false === res.css_ok ) { setStatus( ( saved ? saved.text + ' ' : '' ) + __( 'The scan worked, but the stylesheet could not be written (the uploads folder is not writable), so the site keeps its old styles.', 'tokenforge' ), 'tf-err' ); return; }
			setStatus( ( saved ? saved.text + ' ' : '' ) + __( 'Found %1$s utility classes in use (%2$s pages and templates read).', 'tokenforge' ).replace( '%1$s', String( state.classUsage.used || 0 ) ).replace( '%2$s', String( state.classUsage.posts || 0 ) ), saved ? saved.cls : 'tf-ok' );
		} ).catch( function ( e ) {
			setStatus( saved ? saved.text + ' ' + __( 'Only the scan for the utility classes in use failed: %s', 'tokenforge' ).replace( '%s', e.message ) : __( 'The scan for the utility classes in use failed: %s', 'tokenforge' ).replace( '%s', e.message ), 'tf-err' );
		} );
	}

	// One save at a time: a second one waits for the first one's reload, so it sends the new tokens_rev (not the old one) and is not taken for a conflict.
	// It resolves with the Save message (see saveSay).
	var saveBusy = null;
	function save() {
		if ( saveBusy ) { return saveBusy.then( save ); }
		saveBusy = saveNow().then( function ( saved ) { saveBusy = null; return saved; }, function () { saveBusy = null; } );
		return saveBusy;
	}
	function saveSay( text, cls ) { setStatus( text, cls ); return { text: text, cls: cls }; }
	function saveNow() {
		setStatus( __( 'Saving…', 'tokenforge' ) );
		var pruneNew = !! ( state.framework || {} ).prune_unused && ! state.pruneWas;
		return api( '/save', 'POST', { tokens: state.tokens, tokens_rev: state.tokensRev, settings: state.settings, framework: state.framework, scales: state.scales, components: state.components, scheme: state.scheme, extras: state.extras, defaults: state.defaults, content: state.content, width: state.width, motion: state.motion, overlays: state.overlays, enhancements: state.enhancements, bricks_native: state.bricksNative, scss: state.scss, recipes: state.recipes, palette: state.palette, palette_rev: state.paletteRev, fonts: state.fonts, woo: state.woo, modules: state.modules } )
			.then( function ( res ) {
				state.dirty = false; state.cheat = null; announce( res.css_ver, res.css_url );
				builderThemeStyle( res.theme_style );
				try { window.dispatchEvent( new CustomEvent( 'tokenforge:settings', { detail: { shortcut: state.settings.panel_shortcut || '', badges: { headings: !! ( state.enhancements || {} ).canvas_headings, focus: !! ( state.enhancements || {} ).canvas_focus } } } ) ); } catch ( e ) {}
				return load().then( function () {
					if ( res.tokens_conflict && res.palette_conflict ) { return saveSay( __( 'Saved, but the tokens and the colour families had changed meanwhile (in the builder or another window), so the dashboard now shows the current ones. If you edited them here, make the edit again.', 'tokenforge' ), 'tf-err' ); }
					if ( res.tokens_conflict ) { return saveSay( __( 'Saved, but the tokens had changed meanwhile (in the builder or another window), so the dashboard now shows the current ones. If you edited tokens here, make the edit again.', 'tokenforge' ), 'tf-err' ); }
					if ( res.palette_conflict ) { return saveSay( __( 'Saved, but the colour families had changed meanwhile (in the builder or another window), so the dashboard now shows the current ones. If you edited colours here, make the edit again.', 'tokenforge' ), 'tf-err' ); }
					if ( false === res.css_ok ) { return saveSay( __( 'Saved, but the stylesheet could not be written (the uploads folder is not writable), so the site keeps its old styles.', 'tokenforge' ), 'tf-err' ); }
					if ( res.palette && res.palette.length ) { return saveSay( __( 'Saved · colour families updated: %s.', 'tokenforge' ).replace( '%s', res.palette.join( ', ' ) ), 'tf-ok' ); }
					return saveSay( __( 'Saved · CSS updated.', 'tokenforge' ), 'tf-ok' );
				} ).then( function ( saved ) {
					// "Leave out unused classes" is on with no scan yet, or was just switched on (the save no longer scans): do it now, unless a warning is showing.
					// It runs inside the save (saveBusy), so two quick saves never scan at once; its result carries the Save message.
					if ( ( state.framework || {} ).prune_unused && ( pruneNew || ! ( state.classUsage || {} ).time ) && ! res.tokens_conflict && ! res.palette_conflict && false !== res.css_ok ) { return classScan( saved ).then( function () { return saved; } ); }
					return saved;
				} );
			} )
			.catch( function ( err ) { var failed = saveSay( __( 'Save failed: %s', 'tokenforge' ).replace( '%s', err.message ), 'tf-err' ); failed.failed = true; return failed; } );
	}
	function regen() {
		setStatus( __( 'Regenerating...', 'tokenforge' ) );
		api( '/regenerate', 'POST', {} ).then( function ( res ) {
			announce( res.css_ver, res.css_url );
			if ( ! res.ok ) { setStatus( __( 'The stylesheet could not be written (the uploads folder is not writable), so the site keeps its old styles.', 'tokenforge' ), 'tf-err' ); return; }
			setStatus( __( 'Stylesheet regenerated.', 'tokenforge' ), 'tf-ok' );
		} ).catch( function ( e ) { setStatus( __( 'Failed: %s', 'tokenforge' ).replace( '%s', e.message ), 'tf-err' ); } );
	}
	function reseed() {
		if ( ! window.confirm( 'Replace ALL current tokens with the bundled seed set?' ) ) { return; }
		setStatus( 'Importing seed…' );
		api( '/import-seed', 'POST', {} ).then( function () { return load(); } ).then( function () { announceState(); setStatus( 'Seed imported.', 'tf-ok' ); } ).catch( function ( e ) { setStatus( 'Failed: ' + e.message, 'tf-err' ); } );
	}
	function importAcss() {
		if ( ! window.confirm( 'Import now?\n\n• The imported variables are merged into your tokens (same-named tokens are overwritten).\n• The imported settings replace the matching TokenForge settings (typography, layout, buttons, cards, icons, colours, effects, builder options…).' ) ) { return; }
		setStatus( 'Importing…' );
		api( '/import-acss', 'POST', {} )
			.then( function ( res ) {
				if ( ! res.ok ) { throw new Error( res.error || 'failed' ); }
				state.importReport = res.settings && res.settings.ok ? res.settings : null;
				return load().then( function () {
					announceState();
					setStatus( 'Imported ' + res.imported + ' variables' + ( state.importReport ? ' + ' + state.importReport.mapped + ' settings' : '' ) + ( res.removed ? ' (' + res.removed + ' stale removed)' : '' ) + '.' + ( res.tidied ? ' ' + __( '%d of them are made by your settings, so they were not kept as tokens.', 'tokenforge' ).replace( '%d', res.tidied ) : '' ), 'tf-ok' );
				} );
			} )
			.catch( function ( e ) { setStatus( 'Import failed: ' + e.message, 'tf-err' ); } );
	}

	function load() {
		return api( '/state' ).then( function ( s ) {
			state.tokens = s.tokens || [];
			state.tokensRev = s.tokens_rev || '';
			state.paletteRev = s.palette_rev || '';
			[ 'settings', 'framework', 'scales', 'components', 'scheme', 'extras', 'defaults', 'content', 'width', 'motion', 'enhancements' ].forEach( function ( m ) { state[ m ] = s[ m ] || {}; } );
			state.pruneWas = !! state.framework.prune_unused; // a save that switches it on again rescans, since saves skip the scan while it is off
			state.overlays = s.overlays || { overlays: [], surfaces: [] };
			state.fonts = s.fonts || { enabled: true, bricks: true, blocks: true, fonts: [] };
			state.fontChoices = s.font_choices || [];
			if ( ! Array.isArray( state.fonts.fonts ) ) { state.fonts.fonts = []; }
			state.palette = s.palette || {};
			if ( ! state.palette.families || Array.isArray( state.palette.families ) ) { state.palette.families = {}; }
			state.paletteOrig = JSON.parse( JSON.stringify( state.palette ) );
			state.scss = s.scss || {};
			state.recipes = s.recipes || { enabled: true, inputs: true };
			state.recipeList = s.recipe_list || [];
			if ( ! state.components.btn_overrides || Array.isArray( state.components.btn_overrides ) ) { state.components.btn_overrides = {}; }
			state.defs = s.defaults_map || {};
			state.families = s.families || [];
			if ( state.families.length && state.families.indexOf( state.btnFam ) === -1 ) { state.btnFam = state.families[ 0 ]; }
			state.cssUrl = s.css_url || '';
			state.cssVer = s.css_ver || '';
			state.modules = s.modules || {};
			state.theme = s.theme || {};
			state.history = s.history || []; state.historyDiff = null;
			state.contrast = s.contrast || null; state.cxEdit = null;
			state.woo = s.woo || {};
			state.wooInfo = s.woo_info || {};
			state.bricksNative = s.bricks_native || {};
			state.nativeLabels = s.bricks_native_labels || {};
			state.bricks = !! s.bricks;
			state.platforms = s.platforms || [];
			state.caps = s.caps || [];
			// The Builder screen is named after the active builder; with none, it lists them all.
			var bsec = section( 'builder' ), bact = state.platforms.filter( function ( p ) { return p.active && p.supported; } )[0];
			bsec.title = bact ? bact.label : __( 'Builder integrations', 'tokenforge' );
			bsec.render = bact ? null : 'integrations';
			state.utilCount = s.util_count || 0;
			state.classUsage = s.class_usage || {};
			state.setup = s.setup || null;
			state.groups = s.groups || [ 'color', 'space', 'typography', 'breakpoint', 'radius', 'shadow', 'other' ];
			state.catalog = s.token_catalog || [];
			state.usage = s.token_usage || {};
			state.siteUsage = s.site_usage || {};
			state.attributeUsage = s.attribute_usage || {};
			state.attributeList = s.attribute_list || [];
			catIndex = null; tokenIndex = null;
			if ( state.groups.indexOf( state.activeGroup ) === -1 && ! state.tokens.some( function ( x ) { return x.group === state.activeGroup; } ) ) { state.activeGroup = state.groups[ 0 ]; }
			state.newGroups = [];
			state.dirty = false;
			render();
		} );
	}

	// Public mount API so the same dashboard can render in wp-admin and in the
	// Bricks builder panel.
	window.TokenForgeApp = {
		// Reload from the server unless there are unsaved changes (e.g. when the window reopens).
		refresh: function () { if ( app && ! state.dirty ) { return load(); } },
		// Tests and screenshots: show every panel open.
		expandAll: function ( on ) { state.expandAll = !! on; if ( app ) { render(); } },
		mount: function ( rootEl, config ) {
			if ( ! rootEl ) { return; }
			app = rootEl;
			if ( config ) { cfg = config; }
			attachEvents();
			watchSize();
			fitAdmin();
			if ( window.addEventListener ) { window.addEventListener( 'resize', function () { fitAdmin(); stickTokbar(); } ); }
			return load().catch( function ( err ) {
				app.innerHTML = '<div class="notice notice-error"><p>Failed to load TokenForge: ' + esc( err.message ) + '</p></div>';
			} );
		}
	};

	// Auto-mount in wp-admin.
	var _auto = document.getElementById( 'tokenforge-app' );
	if ( _auto && window.TokenForgeData ) { window.TokenForgeApp.mount( _auto, window.TokenForgeData ); }
} )();
