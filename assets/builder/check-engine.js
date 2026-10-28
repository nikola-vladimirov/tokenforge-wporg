/**
 * TokenForge page check engine for the builder (no UI: the panel and the canvas badges use it).
 *
 * TokenForge.check.run( opts )            -> Promise<result>  axe on the canvas + TokenForge's own checks.
 * TokenForge.check.fix( item, fixId, v )  -> { ok, ... }      changes the builder's unsaved state; never saves.
 * TokenForge.check.show( item )           -> bool             selects the element in the builder.
 * TokenForge.check.elementTree()          -> { headings, focus }  heading outline and Tab order of the canvas.
 * TokenForge.check.audit( opts )          -> Promise<audit>   design check [B8]: raw values, contrast in light and dark, font sizes.
 *
 * Builder facts used (Bricks 2.4): the store is the Vue app's globalProperties ($_state.header,
 * .content, .footer, .components); canvas nodes carry data-id = element id (iframe.min.js); the
 * edited area is templateType header/footer, else content; history records a change when
 * $_state.messageOrigin is "main" (main.min.js), so the builder's Undo takes a fix back.
 */
( function () {
	'use strict';

	var W = window;
	var I18N = W.wp && W.wp.i18n;
	var __ = I18N ? I18N.__ : function ( s ) { return s; };
	function fmt( s ) { var a = [].slice.call( arguments, 1 ), k = 0; return s.replace( /%(?:(\d+)\$)?[sd]/g, function ( m, p ) { return String( a[ p ? p - 1 : k++ ] ); } ); }

	var CARD_CLASS = 'is-clickable'; // lane B4 (notes/B4.md); the one place the name is written.
	var SKIP = '#tf-canvas-button, [data-tf-ui], #wp-link-backdrop, #wp-link-wrap, #bricks-builder-contenteditable-toolbar, #bricks-builder-context-menu, #bricks-draggable-placeholder, #bricks-highlight-id, script, style'; // TokenForge UI and the builder's own nodes in the canvas document: never checked.
	var ARIA_EL = [ 'nav-nested', 'nav-menu', 'dropdown', 'offcanvas', 'search', 'toggle', 'toggle-mode', 'back-to-top' ];
	var LAYOUT = [ 'container', 'section', 'block', 'div' ];
	var AXE_OPTS = { elementRef: true, ancestry: true, iframes: false, resultTypes: [ 'violations', 'incomplete' ], rules: { 'target-size': { enabled: true }, 'duplicate-id': { enabled: true }, region: { enabled: false } } };
	var SEV = { critical: 'error', serious: 'error', moderate: 'warning', minor: 'notice' };
	var RANK = { error: 0, warning: 1, notice: 2 };

	var GROUPS = [
		[ 'headings', __( 'Headings', 'tokenforge' ) ], [ 'images', __( 'Images', 'tokenforge' ) ], [ 'names', __( 'Links and buttons without a name', 'tokenforge' ) ],
		[ 'links', __( 'Links around cards', 'tokenforge' ) ], [ 'forms', __( 'Form fields', 'tokenforge' ) ], [ 'contrast', __( 'Contrast', 'tokenforge' ) ],
		[ 'landmarks', __( 'Page regions', 'tokenforge' ) ], [ 'keyboard', __( 'Keyboard and ids', 'tokenforge' ) ], [ 'targets', __( 'Click targets', 'tokenforge' ) ],
		[ 'attributes', __( 'TokenForge attributes', 'tokenforge' ) ], [ 'other', __( 'Other', 'tokenforge' ) ]
	];
	function R( group, msg, help ) { return { group: group, msg: msg, help: help }; }
	var DUP_HELP = __( 'Keep this tag on one element only; set the others to div or section.', 'tokenforge' );
	var RULES = {
		'page-has-heading-one': R( 'headings', __( 'The page has no main heading (h1).', 'tokenforge' ), __( 'Give the page one h1, usually its title.', 'tokenforge' ) ),
		'tf-multiple-h1': R( 'headings', __( 'The page has more than one h1.', 'tokenforge' ), __( 'Keep one h1 for the page title and make the others h2.', 'tokenforge' ) ),
		'heading-order': R( 'headings', __( 'A heading level is skipped.', 'tokenforge' ), __( 'Go down one level at a time: an h2 is followed by an h3, not an h4.', 'tokenforge' ) ),
		'empty-heading': R( 'headings', __( 'A heading has no text.', 'tokenforge' ), __( 'Add text, or use a paragraph tag if it is not a heading.', 'tokenforge' ) ),
		'image-alt': R( 'images', __( 'An image has no alt text.', 'tokenforge' ), __( 'Describe the image in its alt text, or mark it decorative if it adds nothing.', 'tokenforge' ) ),
		'role-img-alt': R( 'images', __( 'An element shown as an image has no text alternative.', 'tokenforge' ), __( 'Give it an aria-label that describes it.', 'tokenforge' ) ),
		'input-image-alt': R( 'images', __( 'An image button has no alt text.', 'tokenforge' ), __( 'Say what the button does in its alt text.', 'tokenforge' ) ),
		'tf-image-alt-empty': R( 'images', __( 'This image has empty alt text, so screen readers skip it.', 'tokenforge' ), __( 'If it shows something meaningful, write alt text (or add it in the media library). If it is only decoration, mark it decorative.', 'tokenforge' ) ),
		'tf-image-role-hidden': R( 'images', __( 'This image has alt text, but its role hides it from screen readers.', 'tokenforge' ), __( 'The role presentation or none makes screen readers skip the image, so its alt text is never read. Remove the role. If the image is only decoration, add data-tf-decorative to it instead.', 'tokenforge' ) ),
		'link-name': R( 'names', __( 'A link has no name.', 'tokenforge' ), __( 'Icon-only and empty links need a name: add an aria-label that says where the link goes.', 'tokenforge' ) ),
		'button-name': R( 'names', __( 'A button has no name.', 'tokenforge' ), __( 'Add an aria-label that says what the button does.', 'tokenforge' ) ),
		'tf-card-link': R( 'links', __( 'A whole card is wrapped in one link.', 'tokenforge' ), fmt( __( 'Screen readers read the whole card as one long link. Put the link on the heading only, set the card tag to div and add the class %s: the whole card stays clickable.', 'tokenforge' ), CARD_CLASS ) ),
		'tf-nested-link': R( 'links', __( 'A link or button is inside another link.', 'tokenforge' ), fmt( __( 'A link cannot hold other links or buttons. Keep the link on the heading only and add the class %s to the card.', 'tokenforge' ), CARD_CLASS ) ),
		'nested-interactive': R( 'links', __( 'Something clickable is inside a button or another control.', 'tokenforge' ), __( 'Move the inner link or button out of it.', 'tokenforge' ) ),
		label: R( 'forms', __( 'A form field has no label.', 'tokenforge' ), __( 'Show the field labels in the form settings, or give the field a label hidden with the sr-only class.', 'tokenforge' ) ),
		'select-name': R( 'forms', __( 'A select field has no label.', 'tokenforge' ), __( 'Show the field labels in the form settings, or give the field a label hidden with the sr-only class.', 'tokenforge' ) ),
		'aria-input-field-name': R( 'forms', __( 'A field has no name.', 'tokenforge' ), __( 'Give it an aria-label or a visible label.', 'tokenforge' ) ),
		'color-contrast': R( 'contrast', __( 'Text does not stand out enough from its background.', 'tokenforge' ), __( 'Use a darker or lighter text colour; the nearest colour token that passes is suggested when there is one.', 'tokenforge' ) ),
		'landmark-one-main': R( 'landmarks', __( 'The page has no main region.', 'tokenforge' ), __( 'The page content should sit inside one main element.', 'tokenforge' ) ),
		'landmark-no-duplicate-banner': R( 'landmarks', __( 'There is more than one page header (header tag).', 'tokenforge' ), DUP_HELP ),
		'landmark-no-duplicate-contentinfo': R( 'landmarks', __( 'There is more than one page footer (footer tag).', 'tokenforge' ), DUP_HELP ),
		'landmark-no-duplicate-main': R( 'landmarks', __( 'There is more than one main region (main tag).', 'tokenforge' ), DUP_HELP ),
		'landmark-unique': R( 'landmarks', __( 'Two regions of the same kind have the same name.', 'tokenforge' ), __( 'Give each one its own aria-label, for example "Main menu" and "Footer menu".', 'tokenforge' ) ),
		'landmark-banner-is-top-level': R( 'landmarks', __( 'A header region sits inside another region.', 'tokenforge' ), __( 'Use the header tag only for the site header.', 'tokenforge' ) ),
		'landmark-main-is-top-level': R( 'landmarks', __( 'A main region sits inside another region.', 'tokenforge' ), __( 'The page already has its main region; set this element to div or section.', 'tokenforge' ) ),
		'landmark-contentinfo-is-top-level': R( 'landmarks', __( 'A footer region sits inside another region.', 'tokenforge' ), __( 'Use the footer tag only for the site footer.', 'tokenforge' ) ),
		region: R( 'landmarks', __( 'Some content is outside the page regions.', 'tokenforge' ), __( 'Put it inside the header, main or footer.', 'tokenforge' ) ),
		'duplicate-id': R( 'keyboard', __( 'Two elements share the same id.', 'tokenforge' ), __( 'Give each element its own CSS id in its settings.', 'tokenforge' ) ),
		'duplicate-id-aria': R( 'keyboard', __( 'An id used by a label or description is not unique.', 'tokenforge' ), __( 'Give each element its own CSS id in its settings.', 'tokenforge' ) ),
		tabindex: R( 'keyboard', __( 'An element has a tabindex above 0.', 'tokenforge' ), __( 'Remove it: a tabindex above 0 changes the Tab order for everyone.', 'tokenforge' ) ),
		'target-size': R( 'targets', __( 'A click target is smaller than 24 by 24 px.', 'tokenforge' ), __( 'Give it more padding or size, or more space around it.', 'tokenforge' ) ),
		'tf-unknown-attr': R( 'attributes', __( 'Unknown TokenForge attribute.', 'tokenforge' ), __( 'Check the spelling of the attribute name.', 'tokenforge' ) )
	};
	var REPORT_ONLY = __( 'Report only: TokenForge does not change this for you.', 'tokenforge' );
	var INNER_HELP = __( 'This link or button is made by the menu or similar element around it, so a label on that whole element would not name it. Name it where it is made: in that element\'s own settings (for a menu, the menu item label in Appearance > Menus).', 'tokenforge' );
	var LINKED_HELP = __( 'This image is the only content of a link or button, so its alt text is the name of that link. Describe where the link goes; do not mark it decorative.', 'tokenforge' );
	var DECO_62 = __( 'Screen readers will skip the image on WordPress 6.2 or newer.', 'tokenforge' );

	// ---- builder access ----
	function u( v ) { return v && v.__v_isRef ? v.value : v; }
	function props() {
		var el = document.querySelector( 'div.brx-body.main' );
		var gp = el && el.__vue_app__ && el.__vue_app__.config && el.__vue_app__.config.globalProperties;
		return gp && gp.$_state ? gp : null;
	}
	function canvas() {
		var f = document.getElementById( 'bricks-builder-iframe' );
		try { return f && f.contentWindow && f.contentWindow.document ? f.contentWindow : null; } catch ( e ) { return null; }
	}
	function edited( st ) { return 'header' === st.templateType || 'footer' === st.templateType ? st.templateType : 'content'; }
	// Element id -> { el, part }. Header and footer hold the templates in use while a page is edited.
	function index( st ) {
		var map = {};
		function put( list, part ) { ( u( list ) || [] ).forEach( function ( e ) { if ( e && e.id && ! map[ e.id ] ) { map[ e.id ] = { el: e, part: part }; } } ); }
		put( st.header, 'header' ); put( st.content, 'content' ); put( st.footer, 'footer' );
		( u( st.components ) || [] ).forEach( function ( c ) { put( c && c.elements, 'component' ); } );
		return map;
	}
	function settingsOf( el ) { return el && el.settings && ! Array.isArray( el.settings ) ? el.settings : {}; }
	function rid() { var s = ''; while ( s.length < 6 ) { s += Math.random().toString( 36 ).slice( 2 ); } return s.slice( 0, 6 ); }
	function qsa( root, sel ) { try { return [].slice.call( root.querySelectorAll( sel ) ); } catch ( e ) { return []; } }
	function err( code, msg ) { var e = new Error( msg ); e.code = code; return e; }
	function text( n ) { return ( ( n && ( n.getAttribute( 'aria-label' ) || n.textContent || n.getAttribute( 'alt' ) ) ) || '' ).replace( /\s+/g, ' ' ).trim().slice( 0, 80 ); }

	// ---- mapping a canvas node to its builder element ----
	function skipped( n ) { return !! ( n && n.closest && n.closest( SKIP ) ); }
	// Header and footer templates are drawn static (no data-id), but every element keeps id="brxe-<id>".
	function host( n ) { return n && n.closest ? n.closest( '[data-id], [id^="brxe-"]' ) : null; }
	function eid( h ) { return h ? ( h.getAttribute( 'data-id' ) || h.id.replace( /^brxe-/, '' ) ) : null; }
	function nodeOf( nd, doc ) {
		if ( nd.element && 1 === nd.element.nodeType ) { return nd.element; }
		var sels = [].concat( nd.ancestry || [], nd.target || [] );
		for ( var i = 0; i < sels.length; i++ ) {
			if ( 'string' === typeof sels[ i ] ) {
				try { var n = doc.querySelector( sels[ i ] ); if ( n ) { return n; } } catch ( e ) {}
			}
		}
		return null;
	}
	function target( ctx, id ) {
		var hit = ctx.map[ id ], label = null;
		try { label = ctx.g.$_getElementLabel ? ctx.g.$_getElementLabel( hit.el ) : null; } catch ( e ) {}
		return { elementId: id, name: hit.el.name, label: label || hit.el.label || hit.el.name, selector: '[data-id="' + id + '"]', part: hit.part, editable: hit.part === ctx.part, count: 1 };
	}
	function where( ctx, n, sel ) {
		var h = host( n ), id = eid( h ), hit = id ? ctx.map[ id ] : null, g = ctx.g; // this exact line is a mutant anchor in tf-check-engine.js
		var t = hit && g ? target( ctx, id ) : { elementId: null, name: null, label: null, selector: n && n.tagName ? n.tagName.toLowerCase() : 'html', part: 'page', editable: false, count: 1 };
		if ( sel ) { t.selector = sel; }
		return t;
	}
	function wcag( tags ) {
		var out = { wcag: null, level: null };
		if ( ( tags || [] ).some( function ( t ) { return /-obsolete$/.test( t ); } ) ) { return out; } // WCAG 4.1.1 Parsing was removed in WCAG 2.2: no criterion to show.
		( tags || [] ).forEach( function ( t ) {
			var m = /^wcag(\d)(\d)(\d+)$/.exec( t ), l = /^wcag2\d?(a{1,3})$/.exec( t );
			if ( m ) { out.wcag = m[ 1 ] + '.' + m[ 2 ] + '.' + m[ 3 ]; }
			if ( l ) { out.level = l[ 1 ].toUpperCase(); }
		} );
		return out;
	}
	// One item per rule per element; repeats (loops) only raise the count.
	function add( ctx, source, rule, status, n, extra ) {
		extra = extra || {};
		var t = where( ctx, n, extra.selector ), sub = extra.key ? ':' + extra.key : '', key = rule + '|' + ( t.elementId || t.selector ) + sub;
		if ( ctx.seen[ key ] ) { ctx.seen[ key ].target.count++; return null; }
		var def = RULES[ rule ] || R( 'other', extra.msg || rule, extra.help || '' ), w = wcag( extra.tags );
		var it = {
			id: ( 'axe' === source ? 'axe:' : 'tf:' ) + rule + ':' + ( t.elementId || 'page:' + ( ctx.page[ rule ] = ( ctx.page[ rule ] || 0 ) + 1 ) ) + sub,
			source: source, rule: rule, group: def.group, status: status,
			severity: extra.severity || SEV[ extra.impact ] || 'warning', impact: extra.impact || null,
			wcag: w.wcag, level: w.level, message: extra.message || def.msg, help: def.help || extra.help || '',
			helpUrl: extra.helpUrl || '', target: t, data: extra.data || {}, fixes: [], reportOnly: true
		};
		ctx.seen[ key ] = it;
		ctx.items.push( it );
		ctx.priv[ it.id ] = { n: n, el: t.elementId ? ctx.map[ t.elementId ].el : null };
		return it;
	}
	function blocked( it ) {
		if ( it.target.editable ) { return ''; }
		if ( 'header' === it.target.part ) { return __( 'In the header template: open that template to fix it.', 'tokenforge' ); }
		if ( 'footer' === it.target.part ) { return __( 'In the footer template: open that template to fix it.', 'tokenforge' ); }
		if ( 'component' === it.target.part ) { return __( 'Inside a component: edit the component to fix it.', 'tokenforge' ); }
		return __( 'Not part of what you are editing.', 'tokenforge' );
	}
	function offer( it, f ) {
		f.input = f.input || null; f.oneClick = ! f.input; f.note = f.note || ''; f.blocked = blocked( it );
		it.fixes.push( f );
	}

	// ---- fixes offered per rule (builder-aware, safe set only) ----
	function headingFix( ctx, it, level, id ) {
		var el = ctx.priv[ it.id ].el;
		if ( ! el || 'heading' !== el.name || level < 1 || level > 6 ) { return; }
		offer( it, { id: id, label: fmt( __( 'Change to h%d', 'tokenforge' ), level ), kind: 'setHeadingLevel', change: { key: 'settings.tag', value: 'h' + level }, note: __( 'May change how it looks: heading styles follow the level.', 'tokenforge' ) } );
	}
	function altFixes( ctx, it ) {
		var el = ctx.priv[ it.id ].el;
		if ( ! el || 'image' !== el.name ) { return; }
		var img = settingsOf( el ).image, id = img && parseInt( img.id, 10 );
		it.data.imageId = id > 0 ? id : null;
		it.data.libraryAlt = null;
		if ( id > 0 ) { ctx.ask.images.push( { it: it, id: id } ); }
		offer( it, { id: 'altText', label: __( 'Write alt text', 'tokenforge' ), kind: 'setAlt', input: 'text', change: { key: 'settings.altText', value: null } } );
		var n = ctx.priv[ it.id ].n;
		if ( linkOnly( el, n ) ) { it.data.linked = true; it.help = LINKED_HELP; return; } // decorative would leave the link with no name
		// The role works before WordPress 6.2, but Bricks puts attributes on the root (the img only when plain, gate M1) and axe fails it with alt text (lookup() too).
		var own = !! n && n === host( n ) && '' === n.getAttribute( 'alt' ) && ! n.hasAttribute( 'role' );
		offer( it, { id: 'decorative', label: __( 'Mark as decorative', 'tokenforge' ), kind: 'markDecorative', change: { key: 'settings._attributes', value: { name: 'data-tf-decorative', value: '' }, role: own }, note: own ? __( 'Screen readers will skip the image.', 'tokenforge' ) : DECO_62 } );
	}
	// The role is removed only where the img is the element root, so it is the element's own attribute.
	function roleFix( ctx, it ) {
		var p = ctx.priv[ it.id ], s = settingsOf( p.el ), i = attrIndex( s, 'role' );
		if ( i > -1 && hides( s._attributes[ i ].value ) && p.n === host( p.n ) ) {
			offer( it, { id: 'role', label: __( 'Remove the role', 'tokenforge' ), kind: 'removeRole', change: { key: 'settings._attributes', value: { remove: 'role' } } } );
		}
	}
	// True when the image is all a link or button holds (no text, no label of its own): then its alt text is the link's name.
	function linkOnly( el, n ) {
		var l = settingsOf( el ).link, c = n && n.closest ? n.closest( 'a[href], button' ) : null;
		if ( l && 'object' === typeof l && ( l.url || l.postId || l.type ) ) { return ! l.ariaLabel; }
		return !! c && ! c.getAttribute( 'aria-label' ) && ! ( c.textContent || '' ).trim();
	}
	function ariaFix( ctx, it ) {
		var p = ctx.priv[ it.id ], el = p.el, n = p.n, key = '';
		if ( ! el || ! n ) { return; }
		var s = settingsOf( el ), root = n === host( n ), inner = ARIA_EL.indexOf( el.name ) > -1;
		// A link or button inside a menu-like element is not the element: its label setting would rename the container.
		if ( inner ) { if ( root ) { key = 'settings.ariaLabel'; } } else if ( s.link && 'object' === typeof s.link && 'A' === n.tagName ) { key = 'settings.link.ariaLabel'; } else if ( root ) { key = 'settings._attributes'; }
		if ( inner && ! key ) { it.help = INNER_HELP; }
		if ( key ) { offer( it, { id: 'ariaLabel', label: __( 'Add an aria-label', 'tokenforge' ), kind: 'setAriaLabel', input: 'text', change: { key: key, value: null } } ); }
	}
	function tagFix( ctx, it ) {
		var p = ctx.priv[ it.id ];
		if ( p.el && LAYOUT.indexOf( p.el.name ) > -1 && p.n && p.n === host( p.n ) ) {
			offer( it, { id: 'tag', label: __( 'Change the HTML tag', 'tokenforge' ), kind: 'setTag', input: { choices: [ 'div', 'section' ] }, change: { key: 'settings.tag', value: null } } );
		}
	}
	function attrIndex( s, name ) {
		var list = Array.isArray( s._attributes ) ? s._attributes : [];
		for ( var i = 0; i < list.length; i++ ) { if ( list[ i ] && String( list[ i ].name || '' ).trim().toLowerCase() === name ) { return i; } }
		return -1;
	}
	function hides( role ) { return /^(presentation|none)$/.test( String( role || '' ).trim().toLowerCase() ); }
	function tabFix( ctx, it ) {
		var p = ctx.priv[ it.id ], s = settingsOf( p.el ), i = attrIndex( s, 'tabindex' );
		it.data.value = p.n ? parseInt( p.n.getAttribute( 'tabindex' ), 10 ) : null;
		if ( i > -1 && parseInt( s._attributes[ i ].value, 10 ) > 0 && p.n === host( p.n ) ) {
			offer( it, { id: 'tabindex', label: __( 'Remove the tabindex', 'tokenforge' ), kind: 'removeTabindex', change: { key: 'settings._attributes', value: { remove: 'tabindex' } } } );
		}
	}

	// ---- heading outline and Tab order (also used by the badges) ----
	// Rendered and exposed: not display none (no box), visibility hidden, the hidden attribute or aria-hidden (self or ancestor).
	function shown( n ) {
		if ( n.closest && n.closest( '[hidden], [aria-hidden="true"]' ) ) { return false; }
		if ( n.getClientRects && ! n.getClientRects().length ) { return false; }
		try { var v = n.ownerDocument && n.ownerDocument.defaultView, cs = v && v.getComputedStyle ? v.getComputedStyle( n ) : null; if ( cs && 'hidden' === cs.visibility ) { return false; } } catch ( e ) {}
		return true;
	}
	function outline( doc, map ) {
		return qsa( doc, 'h1, h2, h3, h4, h5, h6, [role="heading"]' ).filter( function ( n ) { return ! skipped( n ) && shown( n ); } ).map( function ( n ) {
			var lv = /^H[1-6]$/.test( n.tagName ) && ! n.getAttribute( 'aria-level' ) ? +n.tagName.charAt( 1 ) : ( parseInt( n.getAttribute( 'aria-level' ), 10 ) || 2 );
			var h = host( n ), id = eid( h ), hit = id && map[ id ];
			return { elementId: hit ? id : null, part: hit ? hit.part : 'page', level: lv, text: text( n ), node: n };
		} );
	}
	// The canvas does not change during a run: read the outline once and reuse it.
	function outlineOf( ctx ) { return ctx.outline || ( ctx.outline = outline( ctx.doc, ctx.map ) ); }
	function tabOrder( doc, map ) {
		var list = qsa( doc, 'a[href], button, input, select, textarea, summary, iframe, video[controls], audio[controls], area[href], [tabindex]' ).filter( function ( n ) {
			var ti = parseInt( n.getAttribute( 'tabindex' ), 10 );
			return ! skipped( n ) && ! n.hasAttribute( 'disabled' ) && ! n.hasAttribute( 'contenteditable' ) && ! ( ti < 0 ) &&
				! ( 'INPUT' === n.tagName && 'hidden' === ( n.getAttribute( 'type' ) || '' ).toLowerCase() ) &&
				( ! n.getClientRects || n.getClientRects().length > 0 );
		} );
		var ti = function ( n ) { return parseInt( n.getAttribute( 'tabindex' ), 10 ) || 0; };
		list = list.map( function ( n, i ) { return { n: n, i: i, t: ti( n ) }; } ).sort( function ( a, b ) {
			return ( a.t > 0 ) !== ( b.t > 0 ) ? ( a.t > 0 ? -1 : 1 ) : ( a.t > 0 && a.t !== b.t ? a.t - b.t : a.i - b.i );
		} );
		return list.map( function ( x, k ) {
			var h = host( x.n ), id = eid( h ), hit = id && map[ id ];
			return { order: k + 1, elementId: hit ? id : null, part: hit ? hit.part : 'page', tag: x.n.tagName.toLowerCase(), tabindex: x.t, text: text( x.n ), node: x.n };
		} );
	}
	function elementTree() {
		var w = canvas(), g = props();
		if ( ! w ) { return { ok: false, headings: [], focus: [] }; }
		var map = g ? index( u( g.$_state ) ) : {};
		return { ok: true, part: g ? edited( u( g.$_state ) ) : 'content', headings: outline( w.document, map ), focus: tabOrder( w.document, map ) };
	}

	// ---- axe results -> items ----
	// The canvas does not draw a link's aria-label setting (the front end does, set_link_attributes in the builder), so a link labelled there is not reported again.
	function labelled( ctx, n ) {
		var h = host( n ), hit = h && ctx.map[ eid( h ) ], l = hit && settingsOf( hit.el ).link;
		return !! ( l && 'object' === typeof l && l.ariaLabel );
	}
	function mapAxe( ctx, res ) {
		[ [ 'violations', 'fail' ], [ 'incomplete', 'review' ] ].forEach( function ( kind ) {
			if ( 'review' === kind[ 1 ] && false === ctx.opts.review ) { return; }
			( res[ kind[ 0 ] ] || [] ).forEach( function ( rule ) {
				( rule.nodes || [] ).forEach( function ( nd ) {
					var n = nodeOf( nd, ctx.doc );
					if ( skipped( n ) || ( ( 'link-name' === rule.id || 'button-name' === rule.id ) && labelled( ctx, n ) ) ) { return; }
					var data = ( ( nd.any || [] ).concat( nd.all || [], nd.none || [] )[ 0 ] || {} ).data || null;
					var it = add( ctx, 'axe', rule.id, kind[ 1 ], n, { impact: nd.impact || rule.impact, tags: rule.tags, helpUrl: rule.helpUrl, msg: rule.help, help: rule.description, selector: ( nd.target || [] ).join( ' ' ) || null } );
					if ( it && ON[ rule.id ] ) { ON[ rule.id ]( ctx, it, n, data ); }
				} );
			} );
		} );
	}
	var ON = {
		'page-has-heading-one': function ( ctx, it ) {
			var first = outlineOf( ctx ).filter( function ( h ) { return h.elementId && 'heading' === ctx.map[ h.elementId ].el.name && ctx.map[ h.elementId ].part === ctx.part; } )[ 0 ];
			if ( ! first ) { return; }
			it.target = where( ctx, first.node );
			ctx.priv[ it.id ] = { n: first.node, el: ctx.map[ first.elementId ].el };
			it.data = { level: first.level, expected: 1 };
			headingFix( ctx, it, 1, 'h1' );
		},
		'heading-order': function ( ctx, it, n ) {
			var hs = outlineOf( ctx ), prev = null;
			for ( var i = 0; i < hs.length && hs[ i ].node !== n; i++ ) { prev = hs[ i ]; }
			var lv = i < hs.length ? hs[ i ].level : null, exp = prev ? Math.min( prev.level + 1, 6 ) : 1;
			it.data = { level: lv, expected: exp };
			headingFix( ctx, it, exp, 'level' );
		},
		'image-alt': altFixes, 'role-img-alt': ariaFix, 'link-name': ariaFix, 'button-name': ariaFix, 'landmark-unique': ariaFix,
		'landmark-no-duplicate-banner': tagFix, 'landmark-no-duplicate-contentinfo': tagFix, 'landmark-no-duplicate-main': tagFix,
		'landmark-banner-is-top-level': tagFix, 'landmark-main-is-top-level': tagFix, 'landmark-contentinfo-is-top-level': tagFix,
		tabindex: tabFix,
		'color-contrast': function ( ctx, it, n, d ) {
			d = d || {};
			var c = settingsOf( ctx.priv[ it.id ].el )._typography, col = c && c.color, set = col ? ( col.raw || col.hex || col.rgb || null ) : null;
			it.data = { fg: d.fgColor || null, bg: d.bgColor || null, ratio: d.contrastRatio || null, needed: parseFloat( d.expectedContrastRatio ) || null, fontSize: d.fontSize || null, fontWeight: d.fontWeight || null, setting: set, suggestion: null, dark: ctx.dark };
			// The text colour is the element's own setting: any token or colour can be tried (the panel shows the ratio first).
			if ( ctx.priv[ it.id ].el && it.data.bg && it.data.needed ) { offer( it, { id: 'textColour', label: __( 'Choose another text colour', 'tokenforge' ), kind: 'setTextColor', input: 'colour', change: { key: 'settings._typography.color', value: null }, note: __( 'Changes the text colour of this element only. Undo in the builder takes it back.', 'tokenforge' ) } ); }
			if ( it.data.bg && ( set || it.data.fg ) && it.data.needed ) { ctx.ask.contrast.push( { it: it, fg: set || it.data.fg, bg: it.data.bg, min: it.data.needed } ); }
		}
	};

	// ---- TokenForge's own checks ----
	// An ancestor builder element carries data-tf-decorative in its attributes: the front end gives every image inside alt="".
	function markedAbove( ctx, el ) {
		for ( var i = 0, p = el && ctx.map[ el.parent ]; p && i < 50; i++, p = ctx.map[ p.el.parent ] ) {
			if ( attrIndex( settingsOf( p.el ), 'data-tf-decorative' ) > -1 ) { return true; }
		}
		return false;
	}
	function ours( ctx ) {
		var doc = ctx.doc;
		var h1 = outlineOf( ctx ).filter( function ( h ) { return 1 === h.level; } );
		h1.slice( 1 ).forEach( function ( h ) {
			var it = add( ctx, 'tokenforge', 'tf-multiple-h1', 'fail', h.node, { severity: 'warning' } );
			if ( it ) { it.data = { level: 1, expected: 2 }; headingFix( ctx, it, 2, 'level' ); }
		} );
		qsa( doc, 'img' ).forEach( function ( img ) {
			var h = host( img ), hit = h && ctx.map[ eid( h ) ], it = null;
			if ( skipped( img ) ) { return; }
			// A role that hides an image with a text alternative (the mark taken off, the role left behind), unless it is still marked decorative.
			if ( hides( img.getAttribute( 'role' ) ) && ( img.getAttribute( 'alt' ) || '' ).trim() && ! img.closest( '[data-tf-decorative], [aria-hidden="true"]' ) && ! ( hit && markedAbove( ctx, hit.el ) ) ) {
				it = add( ctx, 'tokenforge', 'tf-image-role-hidden', 'fail', img, { severity: 'warning', tags: [ 'wcag2a', 'wcag111' ] } );
				if ( it ) { roleFix( ctx, it ); }
			}
			if ( ! hit || 'image' !== hit.el.name || '' !== img.getAttribute( 'alt' ) || settingsOf( hit.el ).altText ) { return; }
			var deco = [ img, h ].some( function ( n ) { return hides( n.getAttribute( 'role' ) ) || 'true' === n.getAttribute( 'aria-hidden' ) || n.hasAttribute( 'data-tf-decorative' ); } );
			if ( deco || img.closest( '[data-tf-decorative], [aria-hidden="true"]' ) || markedAbove( ctx, hit.el ) ) { return; }
			// alt="" is the right technique for decoration (WCAG H67) and the engine cannot tell: a person decides.
			it = add( ctx, 'tokenforge', 'tf-image-alt-empty', 'review', img, { severity: 'warning', tags: [ 'wcag2a', 'wcag111' ] } );
			if ( it ) { altFixes( ctx, it ); }
		} );
		qsa( doc, 'a' ).forEach( function ( a ) {
			if ( skipped( a ) ) { return; }
			var rule = a.querySelector( 'a, button, input, select, textarea' ) ? 'tf-nested-link' : ( a.querySelector( 'h1, h2, h3, h4, h5, h6' ) && a.querySelector( 'img, picture, video, p' ) ? 'tf-card-link' : '' );
			if ( rule ) {
				// A card in one link is valid HTML (verbose, not a failure); a control inside a link is WCAG 4.1.2 (A).
				var it = add( ctx, 'tokenforge', rule, 'fail', a, { severity: 'tf-nested-link' === rule ? 'error' : 'warning', tags: 'tf-nested-link' === rule ? [ 'wcag2a', 'wcag412' ] : [] } );
				if ( it ) { it.data = { className: CARD_CLASS }; }
			}
		} );
		var names = {};
		qsa( doc, '*' ).forEach( function ( n ) {
			if ( skipped( n ) ) { return; }
			[].slice.call( n.attributes || [] ).forEach( function ( a ) { if ( 0 === a.name.indexOf( 'data-tf-' ) ) { ( names[ a.name ] = names[ a.name ] || [] ).push( n ); } } );
		} );
		ctx.ask.attrs = names;
	}

	// ---- one read-only server request: library alt, nearest token, attribute names ----
	function lookup( ctx ) {
		var cfg = W.TokenForgePanel || {}, ask = ctx.ask, names = Object.keys( ask.attrs );
		if ( ! ask.images.length && ! ask.contrast.length && ! names.length ) { return Promise.resolve(); }
		var body = {
			images: ask.images.map( function ( x ) { return x.id; } ),
			contrast: ask.contrast.map( function ( x ) { return { fg: x.fg, bg: x.bg, min: x.min }; } ),
			attrs: names, dark: ctx.dark
		};
		return Promise.resolve().then( function () {
			return W.fetch( cfg.restUrl + '/check', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce }, body: JSON.stringify( body ) } );
		} ).then( function ( r ) {
			if ( ! r.ok ) { throw new Error( 'HTTP ' + r.status ); }
			return r.json();
		} ).then( function ( a ) {
			ask.images.forEach( function ( x ) {
				var alt = a.alts && a.alts[ x.id ];
				x.it.data.libraryAlt = alt || null;
				if ( alt ) {
					offer( x.it, { id: 'altLibrary', label: __( 'Use the media library alt text', 'tokenforge' ), kind: 'setAlt', change: { key: 'settings.altText', value: alt } } );
					x.it.fixes.unshift( x.it.fixes.pop() );
					// Without its own alt text the image shows the library alt, which the role must not hide.
					x.it.fixes.forEach( function ( f ) { if ( f.change.role ) { f.change.role = false; f.note = DECO_62; } } );
				}
			} );
			ask.contrast.forEach( function ( x, i ) { var s = a.contrast && a.contrast[ i ]; x.it.data.suggestion = s ? { token: s.value, ratio: s.ratio } : null; } );
			( a.attrs || [] ).forEach( function ( p ) {
				( ask.attrs[ p.attribute ] || [] ).forEach( function ( n ) {
					var it = add( ctx, 'tokenforge', 'tf-unknown-attr', 'fail', n, { severity: 'notice', key: p.attribute, message: fmt( __( 'Unknown TokenForge attribute: %s.', 'tokenforge' ), p.attribute ) } );
					if ( it ) {
						it.data = { attribute: p.attribute, didYouMean: p.didYouMean || '' };
						if ( p.didYouMean ) { it.help = fmt( __( 'Check the spelling. Did you mean %s?', 'tokenforge' ), p.didYouMean ); }
					}
				} );
			} );
		} ).catch( function () { ctx.warnings.push( 'server' ); } );
	}

	// The ratio of colours the person is trying, from the server (Contrast::pair, the engine behind the Palette contrast table):
	// [ { fg, bg, min, dark? } ] in (dark: the pair's own mode, else the dark argument), a promise of [ { ratio, pass, fg, bg } | null ] out. Read-only.
	function measure( pairs, dark ) {
		var cfg = W.TokenForgePanel || {};
		return Promise.resolve().then( function () {
			return W.fetch( cfg.restUrl + '/check', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce }, body: JSON.stringify( { measure: pairs.map( function ( x ) { return { fg: x.fg, bg: x.bg, min: x.min, dark: null != x.dark ? !! x.dark : !! dark }; } ) } ) } );
		} ).then( function ( r ) {
			if ( ! r.ok ) { throw new Error( 'HTTP ' + r.status ); }
			return r.json();
		} ).then( function ( a ) { return a.measure || []; } );
	}

	function finish( ctx, t0 ) {
		var items = ctx.items.slice().sort( function ( a, b ) {
			var oa = a.target.elementId in ctx.order ? ctx.order[ a.target.elementId ] : -1, ob = b.target.elementId in ctx.order ? ctx.order[ b.target.elementId ] : -1;
			return ( a.status === b.status ? 0 : ( 'fail' === a.status ? -1 : 1 ) ) || RANK[ a.severity ] - RANK[ b.severity ] || oa - ob;
		} );
		var sum = { total: items.length, fail: 0, review: 0, error: 0, warning: 0, notice: 0, groups: [] };
		items.forEach( function ( it ) {
			it.reportOnly = ! it.fixes.length;
			if ( it.reportOnly ) { it.help = ( it.help ? it.help + ' ' : '' ) + REPORT_ONLY; }
			sum[ it.status ]++; sum[ it.severity ]++;
		} );
		GROUPS.forEach( function ( g ) {
			var ids = items.filter( function ( it ) { return it.group === g[ 0 ]; } ).map( function ( it ) { return it.id; } );
			if ( ids.length ) { sum.groups.push( { id: g[ 0 ], label: g[ 1 ], count: ids.length, items: ids } ); }
		} );
		var cfg = W.TokenForgePanel || {};
		return { ok: true, ranAt: t0, ms: Date.now() - t0, axeVersion: cfg.axeVersion || null, part: ctx.part, items: items, summary: sum, warnings: ctx.warnings };
	}

	var busy = null, lastResult = null, TIMEOUT = 30000; // ms a run may take
	// axe skips text below the canvas frame's height and reads colours mid-fade after a scheme switch:
	// while it runs, the frame is as tall as the page and transitions are off; done() puts both back.
	// The frame's viewport takes the new height a frame later: wait for it with a timer (a hidden canvas never
	// paints, so no animation frame), at most about a second.
	function settle( w ) {
		var f = w.frameElement, h = f ? f.style.height : '', d = w.document, css = d.createElement && d.head ? d.createElement( 'style' ) : null, want = 0;
		if ( css ) { css.textContent = '*, *::before, *::after { transition: none !important; }'; d.head.appendChild( css ); }
		if ( f && d.documentElement ) { want = d.documentElement.scrollHeight; f.style.height = want + 'px'; }
		return {
			ready: new Promise( function ( r ) { var n = 0; ( function tick() { if ( ! want || w.innerHeight >= want - 1 || ++n > 20 ) { r(); } else { W.setTimeout( tick, 50 ); } } )(); } ),
			done: function () { if ( css && css.parentNode ) { css.parentNode.removeChild( css ); } if ( f ) { f.style.height = h; } }
		};
	}
	function run( opts ) {
		if ( busy ) { return busy; }
		opts = opts || {};
		var g = props(), w = canvas(), a11y = W.TokenForge && W.TokenForge.a11y;
		if ( ! g || ! w ) { return Promise.reject( err( 'no-builder', __( 'Open the page in the builder to check it.', 'tokenforge' ) ) ); }
		var t0 = Date.now(), st = u( g.$_state ), doc = w.document, de = doc.documentElement;
		var ctx = {
			g: g, st: st, w: w, doc: doc, opts: opts, map: index( st ), part: edited( st ), items: [], seen: {}, page: {}, priv: {}, order: {}, warnings: [],
			ask: { images: [], contrast: [], attrs: {} },
			dark: /dark/.test( ( de.getAttribute( 'data-scheme' ) || '' ) + ( de.getAttribute( 'data-brx-theme' ) || '' ) )
		};
		qsa( doc, '[data-id], [id^="brxe-"]' ).forEach( function ( n, i ) { var id = eid( n ); if ( ! ( id in ctx.order ) ) { ctx.order[ id ] = i; } } );
		var s = null, tm = 0;
		var axeRun = false === opts.axe ? Promise.resolve( null ) : ( a11y ? a11y.loadAxe( w ) : Promise.reject( new Error( 'TokenForge: the axe loader is missing.' ) ) ).then( function ( axe ) {
			s = settle( w );
			return s.ready.then( function () { return axe.run( { exclude: SKIP.split( ', ' ).map( function ( s ) { return [ s ]; } ) }, opts.rules ? Object.assign( {}, AXE_OPTS, { runOnly: { type: 'rule', values: opts.rules } } ) : AXE_OPTS ); } ).then( function ( r ) { s.done(); return r; }, function ( e ) { s.done(); throw err( 'axe-failed', e && e.message ? e.message : String( e ) ); } );
		}, function ( e ) { throw err( 'no-axe', e && e.message ? e.message : String( e ) ); } );
		// A run that never answers (axe hangs, the canvas reloads) ends after TIMEOUT: the canvas is put back and the next run can start.
		var hung = new Promise( function ( ok, no ) { tm = W.setTimeout( function () { if ( s ) { s.done(); } no( err( 'axe-failed', __( 'The check did not finish in time.', 'tokenforge' ) ) ); }, TIMEOUT ); } );
		busy = Promise.race( [ axeRun.then( function ( res ) {
			if ( res ) { mapAxe( ctx, res ); }
			if ( false !== opts.tokenforge ) { ours( ctx ); }
			return lookup( ctx );
		} ).then( function () { return finish( ctx, t0 ); } ), hung ] ).then( function ( r ) {
			W.clearTimeout( tm ); busy = null;
			return ( lastResult = r );
		}, function ( e ) { W.clearTimeout( tm ); busy = null; throw e; } );
		return busy;
	}

	// ---- design audit [B8]: does the page follow the design tokens? ----
	// Raw values are read from the settings (element, global class, theme style), so the place to fix is known; token
	// values, font sizes and contrast come from the rendered canvas at the current breakpoint. Read-only: fixes go through fix().
	var TYPE = [ 'text-xs', 'text-s', 'text-m', 'text-l', 'text-xl', 'text-xxl', 'h6', 'h5', 'h4', 'h3', 'h2', 'h1' ];
	var MIN_PX = 12; // text smaller than this is flagged as too small
	var NEAR_RGB = 60; // redmean colour distance (black to white is 765) under which a token counts as near
	var NEAR_SIZE = 0.1; // a size within 10% (and at least 1px) of a type step counts as near
	var SETTING = { '_typography.color': __( 'Text colour', 'tokenforge' ), '_typography.font-size': __( 'Font size', 'tokenforge' ), '_background.color': __( 'Background colour', 'tokenforge' ), '_border.color': __( 'Border colour', 'tokenforge' ) };
	var EYE_HELP = __( 'The background is an image, a gradient or an overlay, or the text is partly covered, so the ratio cannot be measured. Check it by eye: it is not counted as passed.', 'tokenforge' );

	// Typed colours and font sizes in a settings object, at the keys in effect at breakpoint bp: "key" and "key:<bp>"
	// (not other breakpoints, not :hover or other states). A chosen variable or palette colour is not typed.
	function typed( s, bp, base, path, out ) {
		Object.keys( s && 'object' === typeof s && ! Array.isArray( s ) ? s : {} ).forEach( function ( k ) {
			var p = k.split( ':' ), v = s[ k ], at = path.concat( k ), c;
			if ( p.length > 2 || ( 2 === p.length && ( p[ 1 ] !== bp || bp === base ) ) ) { return; }
			if ( v && 'object' === typeof v && ! Array.isArray( v ) ) {
				c = v.id || /var\(/.test( v.raw || '' ) ? '' : String( v.hex || v.rgb || v.hsl || v.raw || '' ).trim();
				if ( /^(#[0-9a-f]{3,8}|(rgb|hsl)a?\([^()]*\))$/i.test( c ) ) { out.push( { kind: 'colour', typed: c, path: at, raw: v } ); } else if ( ! c ) { typed( v, bp, base, at, out ); }
			} else if ( 'font-size' === p[ 0 ] && /^\d*\.?\d+(px|rem)?$/i.test( String( v ).trim() ) ) {
				c = String( v ).trim();
				out.push( { kind: 'size', typed: /\d$/.test( c ) ? c + 'px' : c, path: at, raw: v } ); // a bare number is px in the builder
			}
		} );
		return out;
	}
	function settingName( path ) { var k = path.map( function ( p ) { return p.split( ':' )[ 0 ]; } ).join( '.' ); return SETTING[ k ] || path.join( ' > ' ); }
	// Values as the canvas computes them, through one hidden probe (TokenForge UI, removed at the end).
	function probe( w ) {
		var doc = w.document, de = doc.documentElement, p = doc.createElement( 'span' ), cs = function ( n ) { return w.getComputedStyle( n ); };
		p.setAttribute( 'data-tf-ui', '' ); p.setAttribute( 'aria-hidden', 'true' );
		p.style.cssText = 'position:absolute;visibility:hidden;pointer-events:none';
		( doc.body || de ).appendChild( p );
		// Colours the browser keeps as oklch() and other spaces: paint one pixel and read it back as sRGB.
		var cv = null, cx = null;
		var srgb = function ( s ) {
			if ( ! cv ) { cv = doc.createElement( 'canvas' ); try { cx = cv.getContext( '2d', { willReadFrequently: true } ); } catch ( e ) { cx = null; } }
			if ( ! cx ) { return null; }
			cv.width = cv.height = 1; cx.clearRect( 0, 0, 1, 1 ); cx.fillStyle = '#000'; cx.fillStyle = s; cx.fillRect( 0, 0, 1, 1 );
			var d = cx.getImageData( 0, 0, 1, 1 ).data;
			return [ d[ 0 ], d[ 1 ], d[ 2 ], Math.round( d[ 3 ] / 255 * 1000 ) / 1000 ];
		};
		return {
			has: function ( n ) { return !! String( cs( de ).getPropertyValue( '--' + n ) || '' ).trim(); },
			colour: function ( v ) { p.style.color = ''; p.style.color = v; if ( ! p.style.color ) { return null; } var c = cs( p ).color; return rgb( c ) || srgb( c ); },
			size: function ( v ) { p.style.fontSize = ''; p.style.fontSize = v; return p.style.fontSize ? parseFloat( cs( p ).fontSize ) || null : null; },
			done: function () { if ( p.parentNode ) { p.parentNode.removeChild( p ); } }
		};
	}
	// rgb()/rgba() computed values; probe().colour() reads every other colour space through a canvas pixel.
	function rgb( s ) {
		var m = /^rgba?\(\s*([\d.]+)[,\s]+([\d.]+)[,\s]+([\d.]+)\s*(?:[,/]\s*([\d.]+)(%?))?\s*\)$/.exec( String( s || '' ).trim() );
		return m ? [ +m[ 1 ], +m[ 2 ], +m[ 3 ], null == m[ 4 ] ? 1 : ( m[ 5 ] ? m[ 4 ] / 100 : +m[ 4 ] ) ] : null;
	}
	function dist( a, b ) {
		var r = ( a[ 0 ] + b[ 0 ] ) / 2, dr = a[ 0 ] - b[ 0 ], dg = a[ 1 ] - b[ 1 ], db = a[ 2 ] - b[ 2 ];
		return Math.sqrt( ( 2 + r / 256 ) * dr * dr + 4 * dg * dg + ( 2 + ( 255 - r ) / 256 ) * db * db ) + Math.abs( a[ 3 ] - b[ 3 ] ) * 255;
	}
	function nearest( kind, v, toks ) {
		var best = null;
		toks.forEach( function ( t ) { var d = 'colour' === kind ? dist( v, t.v ) : Math.abs( v - t.v ); if ( ! best || d < best.d ) { best = { name: t.name, value: t.v, d: d }; } } );
		if ( best ) { best.near = 'colour' === kind ? best.d <= NEAR_RGB : best.d <= Math.max( 1, NEAR_SIZE * best.value ); best.exact = best.d < ( 'colour' === kind ? 1 : 0.5 ); }
		return best;
	}
	function rawItem( ctx, gr, x, locked ) {
		var name = settingName( x.path ), first = x.uses.filter( function ( id ) { return ctx.map[ id ].part === ctx.part; } )[ 0 ] || x.uses[ 0 ];
		var it = {
			id: gr.id + ':' + gr.items.length, source: 'tokenforge', rule: 'tf-raw-' + x.kind, group: gr.id, status: 'fail', severity: 'notice', wcag: null, level: null, helpUrl: '', help: '',
			target: first ? target( ctx, first ) : { elementId: null, name: null, label: null, selector: 'html', part: x.where, editable: false, count: 1 },
			data: { where: x.where, setting: name, typed: x.typed, token: gr.token, path: x.path }, fixes: [], canShow: !! first
		};
		var change = { kind: x.kind, path: x.path, was: JSON.stringify( x.raw ), typed: x.typed, value: 'var(--' + gr.token + ')' }, label = fmt( __( 'Use token %s', 'tokenforge' ), gr.token );
		if ( 'element' === x.where ) {
			it.message = fmt( __( '%1$s %2$s is typed in this element\'s settings.', 'tokenforge' ), name, x.typed );
			change.key = 'settings.' + x.path.join( '.' );
			offer( it, { id: 'token', label: label, kind: 'useToken', change: change, note: __( 'Changes this setting on this element only. Undo in the builder takes it back.', 'tokenforge' ) } );
		} else if ( 'class' === x.where ) {
			it.message = fmt( __( '%1$s %2$s is typed in the class %3$s, used by %4$d elements on this page.', 'tokenforge' ), name, x.typed, '.' + x.cls.name, x.uses.length );
			it.help = __( 'Show selects the first element that uses this class.', 'tokenforge' );
			change.key = 'class.' + x.path.join( '.' ); change.classId = x.cls.id;
			offer( it, { id: 'token', label: label, kind: 'useTokenClass', change: change, note: fmt( __( 'Changes the class %s, so every element that uses it changes, on this page and on others. Undo in the builder takes it back.', 'tokenforge' ), '.' + x.cls.name ) } );
			it.fixes[ 0 ].blocked = locked.indexOf( x.cls.id ) > -1 ? __( 'This class is locked in the builder: unlock it to change it.', 'tokenforge' ) : ''; // a class is global, not part of the page or a template
		} else {
			it.message = fmt( __( '%1$s %2$s is typed in the theme style %3$s.', 'tokenforge' ), name, x.typed, x.theme );
			it.help = __( 'Change it in the theme style settings: TokenForge does not change theme styles.', 'tokenforge' );
		}
		return it;
	}
	function rawValues( ctx, pr, toks ) {
		var st = ctx.st, g = ctx.g, places = [], used = {}, groups = {}, out = [], bp = ctx.bp.key, base = ctx.bp.base;
		var classes = u( g.$_globalClasses ) || u( st.globalClasses ), locked = u( st.globalClassesLocked ) || [];
		var themes = u( st.themeStyles ) || {}, active = [].concat( u( st.themeStyleActiveIds ) || [], st.themeStyleActiveId || [] );
		Object.keys( ctx.map ).forEach( function ( id ) {
			var s = settingsOf( ctx.map[ id ].el );
			typed( s, bp, base, [], [] ).forEach( function ( x ) { x.where = 'element'; x.uses = [ id ]; places.push( x ); } );
			( Array.isArray( s._cssGlobalClasses ) ? s._cssGlobalClasses : [] ).forEach( function ( c ) { ( used[ c ] = used[ c ] || [] ).push( id ); } );
		} );
		( Array.isArray( classes ) ? classes : [] ).forEach( function ( c ) {
			if ( c && used[ c.id ] ) { typed( c.settings, bp, base, [], [] ).forEach( function ( x ) { x.where = 'class'; x.cls = c; x.uses = used[ c.id ]; places.push( x ); } ); }
		} );
		active.forEach( function ( id, i ) {
			if ( ! themes[ id ] || active.indexOf( id ) !== i ) { return; }
			typed( themes[ id ].settings, bp, base, [], [] ).forEach( function ( x ) { x.where = 'theme'; x.theme = themes[ id ].label || id; x.uses = []; places.push( x ); } );
		} );
		places.forEach( function ( x ) {
			var v = 'colour' === x.kind ? pr.colour( x.typed ) : pr.size( x.typed );
			if ( ! v ) { return; }
			var key = x.kind + ':' + ( 'colour' === x.kind ? v.join( ',' ) : Math.round( v * 100 ) / 100 ), gr = groups[ key ], near;
			if ( ! gr ) {
				near = nearest( x.kind, v, toks[ x.kind ] );
				if ( ! near || ! near.near ) { return; }
				gr = groups[ key ] = { id: 'd:raw:' + key, kind: x.kind, value: x.typed, token: near.name, exact: near.exact, count: 0, items: [] };
				out.push( gr );
			}
			gr.count += Math.max( 1, x.uses.length );
			gr.items.push( rawItem( ctx, gr, x, locked ) );
		} );
		return out;
	}
	function ownText( n ) { return [].some.call( n.childNodes || [], function ( c ) { return 3 === c.nodeType && /\S/.test( c.nodeValue || '' ); } ); }
	// Every element with its own text, by its computed size: off the type scale at this breakpoint, or too small.
	function fontSizes( ctx, toks ) {
		var groups = {}, out = [], seen = {};
		qsa( ctx.doc, 'body *' ).forEach( function ( n ) {
			if ( skipped( n ) || ! ownText( n ) || ! shown( n ) ) { return; }
			var px = Math.round( parseFloat( ctx.w.getComputedStyle( n ).fontSize ) * 10 ) / 10, step = nearest( 'size', px, toks ), small = px < MIN_PX;
			if ( ! ( px > 0 ) || ( ! small && ( ! step || step.exact ) ) ) { return; }
			var key = ( small ? 'small:' : 'off:' ) + px, t = where( ctx, n ), one = key + '|' + ( t.elementId || t.selector );
			if ( seen[ one ] ) { seen[ one ].target.count++; return; }
			var gr = groups[ key ];
			if ( ! gr ) { gr = groups[ key ] = { id: 'd:size:' + key, kind: small ? 'small' : 'off', px: px, min: MIN_PX, token: step ? step.name : null, tokenPx: step ? Math.round( step.value * 10 ) / 10 : null, items: [] }; out.push( gr ); }
			gr.items.push( seen[ one ] = {
				id: gr.id + ':' + gr.items.length, source: 'tokenforge', rule: small ? 'tf-font-small' : 'tf-font-off-scale', group: gr.id, status: 'fail', severity: small ? 'warning' : 'notice', wcag: null, level: null, helpUrl: '',
				message: small ? fmt( __( 'Text at %1$spx is smaller than %2$spx.', 'tokenforge' ), px, MIN_PX ) : fmt( __( 'Text at %spx is not a step of the type scale.', 'tokenforge' ), px ),
				help: step ? fmt( __( 'The nearest step is %1$s (%2$spx). Use that token where the size is set: the element, its class or the theme style.', 'tokenforge' ), step.name, gr.tokenPx ) : '',
				target: t, data: { px: px }, fixes: [], canShow: !! t.elementId
			} );
		} );
		return out;
	}
	function audit( opts ) {
		opts = opts || {};
		if ( busy ) { return busy.then( function () { return audit( opts ); }, function () { return audit( opts ); } ); }
		var g = props(), w = canvas();
		if ( ! g || ! w ) { return Promise.reject( err( 'no-builder', __( 'Open the page in the builder to check it.', 'tokenforge' ) ) ); }
		var st = u( g.$_state ), de = w.document.documentElement, t0 = Date.now(), keep = lastResult, was = [ de.getAttribute( 'data-scheme' ), de.getAttribute( 'data-brx-theme' ) ];
		var bps = u( st.breakpoints ) || [], base = ( g.$_getBaseBreakpointKey && g.$_getBaseBreakpointKey() ) || ( bps.filter( function ( b ) { return b && b.base; } )[ 0 ] || {} ).key || 'desktop';
		var key = st.breakpointActive || base, bpo = bps.filter( function ( b ) { return b && b.key === key; } )[ 0 ];
		var ctx = { g: g, st: st, w: w, doc: w.document, map: index( st ), part: edited( st ), bp: { key: key, base: base, label: ( bpo && bpo.label ) || key } };
		var out = { ok: true, ranAt: t0, part: ctx.part, breakpoint: { key: key, label: ctx.bp.label }, modes: [ 'light', 'dark' ], raw: [], sizes: [], contrast: [], warnings: [] };
		// Light, then dark, the way the page switches: data-scheme for TokenForge, data-brx-theme for the builder's palette; then as it was.
		var scheme = function ( m ) { [ 'data-scheme', 'data-brx-theme' ].forEach( function ( a, i ) { var v = m || was[ i ]; if ( null == v ) { de.removeAttribute( a ); } else { de.setAttribute( a, v ); } } ); };
		var take = function ( m ) {
			return function ( res ) {
				( res.items || [] ).forEach( function ( it ) {
					if ( 'contrast' !== it.group ) { return; }
					it.id = 'd:' + m + ':' + it.id; it.mode = m;
					if ( 'review' === it.status ) { it.help = EYE_HELP; }
					out.contrast.push( it );
				} );
				( res.warnings || [] ).forEach( function ( x ) { if ( out.warnings.indexOf( x ) < 0 ) { out.warnings.push( x ); } } );
			};
		};
		scheme( 'light' );
		var pr = probe( w ), toks = { colour: [], size: [] };
		try {
			( opts.colours || [] ).forEach( function ( n ) { var v = pr.has( n ) && pr.colour( 'var(--' + n + ')' ); if ( v ) { toks.colour.push( { name: n, v: v } ); } } );
			TYPE.forEach( function ( n ) { var v = pr.has( n ) && pr.size( 'var(--' + n + ')' ); if ( v ) { toks.size.push( { name: n, v: v } ); } } );
			out.raw = rawValues( ctx, pr, toks );
			out.sizes = fontSizes( ctx, toks.size );
		} catch ( e ) { scheme( null ); return Promise.reject( e ); } finally { pr.done(); }
		var one = { rules: [ 'color-contrast' ], tokenforge: false };
		return run( one ).then( take( 'light' ) ).then( function () { scheme( 'dark' ); return run( one ); } ).then( take( 'dark' ) ).then( function () {
			scheme( null ); lastResult = keep;
			out.raw.concat( out.sizes ).forEach( function ( gr ) {
				gr.items.forEach( function ( it ) { it.reportOnly = ! it.fixes.length; if ( it.reportOnly ) { it.help = ( it.help ? it.help + ' ' : '' ) + REPORT_ONLY; } } );
			} );
			out.ms = Date.now() - t0;
			return out;
		}, function ( e ) { scheme( null ); lastResult = keep; throw e; } );
	}

	// ---- fixes: change the builder's unsaved state, the builder's own way ----
	// The object and key a setting path ends in, while the value there is still the one the audit read.
	function slot( s, c ) {
		for ( var i = 0; i < c.path.length - 1 && s && 'object' === typeof s; i++ ) { s = s[ c.path[ i ] ]; }
		var k = c.path[ c.path.length - 1 ];
		return s && 'object' === typeof s && JSON.stringify( s[ k ] ) === c.was ? { o: s, k: k } : null;
	}
	function tokenValue( c ) { return 'colour' === c.kind ? { raw: c.value } : c.value; } // the builder's own shapes: { raw } for a colour variable, a string for a size
	// A global class: change the builder's list entry the way the builder does (REFERENCE, "Open class = a copy"); saved with the page by the user.
	function classFix( g, f ) {
		var st = u( g.$_state ), c = f.change, list = u( g.$_globalClasses ) || u( st.globalClasses ) || [];
		var cls = ( Array.isArray( list ) ? list : [] ).filter( function ( x ) { return x && x.id === c.classId; } )[ 0 ], at = cls && slot( cls.settings, c );
		if ( ! cls ) { return { ok: false, error: 'gone' }; }
		if ( ! at ) { return { ok: false, error: 'changed' }; }
		st.messageOrigin = 'main';
		at.o[ at.k ] = tokenValue( c );
		if ( st.activeClass && st.activeClass.id === cls.id ) { st.activeClass = g.$_clone ? g.$_clone( cls ) : JSON.parse( JSON.stringify( cls ) ); }
		if ( st.globalChanges && Array.isArray( st.globalChanges.modified ) && st.globalChanges.modified.indexOf( cls.id ) < 0 ) { st.globalChanges.modified.push( cls.id ); }
		try { if ( g.$_rerenderControls ) { g.$_rerenderControls(); } } catch ( e ) {}
		return { ok: true, classId: cls.id, key: c.key, before: c.typed, after: c.value };
	}
	function setAttr( s, name, value ) {
		if ( ! Array.isArray( s._attributes ) ) { s._attributes = []; }
		var i = attrIndex( s, name ), before = i > -1 ? s._attributes[ i ].value : null;
		if ( i > -1 ) { s._attributes[ i ].value = value; } else { s._attributes.push( { id: rid(), name: name, value: value } ); }
		return before;
	}
	function fix( item, fixId, value ) {
		var g = props();
		if ( ! g ) { return { ok: false, error: 'no-builder' }; }
		var f = ( ( item && item.fixes ) || [] ).filter( function ( x ) { return x.id === fixId; } )[ 0 ];
		if ( ! f ) { return { ok: false, error: 'unknown-fix' }; }
		if ( f.blocked ) { return { ok: false, error: 'blocked' }; }
		if ( 'useTokenClass' === f.kind ) { return classFix( g, f ); }
		var st = u( g.$_state ), hit = item.target && item.target.elementId ? index( st )[ item.target.elementId ] : null;
		if ( ! hit ) { return { ok: false, error: 'gone' }; }
		if ( hit.part !== edited( st ) ) { return { ok: false, error: 'blocked' }; }
		var v = f.change.value;
		if ( 'text' === f.input ) {
			v = String( null == value ? '' : value ).trim();
			if ( ! v ) { return { ok: false, error: 'needs-value' }; }
		} else if ( f.input && f.input.choices ) {
			if ( f.input.choices.indexOf( value ) < 0 ) { return { ok: false, error: 'needs-value' }; }
			v = value;
		} else if ( 'colour' === f.input ) {
			v = String( null == value ? '' : value ).trim();
			if ( ! /^(#[0-9a-f]{6}|var\(--[a-z0-9_-]+\))$/i.test( v ) ) { return { ok: false, error: 'needs-value' }; }
		}
		var el = hit.el, cur = settingsOf( el ), key = f.change.key, before = null, at = null;
		if ( 'removeTabindex' === f.kind && attrIndex( cur, 'tabindex' ) < 0 ) { return { ok: false, error: 'changed' }; }
		if ( 'removeRole' === f.kind && ! ( attrIndex( cur, 'role' ) > -1 && hides( cur._attributes[ attrIndex( cur, 'role' ) ].value ) ) ) { return { ok: false, error: 'changed' }; }
		if ( 'useToken' === f.kind && ! ( at = slot( cur, f.change ) ) ) { return { ok: false, error: 'changed' }; }
		// The base colour would not change what the canvas shows when this breakpoint sets its own.
		if ( 'setTextColor' === f.kind && st.breakpointActive && ( cur[ '_typography:' + st.breakpointActive ] || {} ).color ) { return { ok: false, error: 'breakpoint' }; }
		if ( 'settings.link.ariaLabel' === key && ! ( cur.link && 'object' === typeof cur.link ) ) { return { ok: false, error: 'changed' }; }
		st.messageOrigin = 'main'; // the builder's history records changes made from the main window
		if ( ! el.settings || Array.isArray( el.settings ) ) { el.settings = {}; }
		var s = el.settings;
		switch ( f.kind ) {
			case 'setHeadingLevel':
			case 'setTag':
				before = s.tag || null; s.tag = v;
				if ( 'setTag' === f.kind ) { delete s.customTag; }
				break;
			case 'setTextColor':
				if ( ! s._typography || 'object' !== typeof s._typography || Array.isArray( s._typography ) ) { s._typography = {}; }
				before = s._typography.color ? ( s._typography.color.raw || s._typography.color.hex || s._typography.color.rgb || null ) : null;
				s._typography.color = 0 === v.indexOf( '#' ) ? { hex: v } : { raw: v }; // the builder's own colour value: hex, or raw for a variable
				break;
			case 'useToken':
				before = f.change.typed; at.o[ at.k ] = tokenValue( f.change ); v = f.change.value;
				break;
			case 'setAlt':
				before = s.altText || null; s.altText = v;
				break;
			case 'markDecorative':
				before = s.altText || null; delete s.altText; setAttr( s, 'data-tf-decorative', '' ); v = 'data-tf-decorative';
				if ( f.change.role && attrIndex( s, 'role' ) < 0 ) { setAttr( s, 'role', 'presentation' ); }
				break;
			case 'removeRole':
				before = s._attributes.splice( attrIndex( s, 'role' ), 1 )[ 0 ].value; v = null;
				break;
			case 'setAriaLabel':
				if ( 'settings.ariaLabel' === key ) { before = s.ariaLabel || null; s.ariaLabel = v; } else if ( 'settings.link.ariaLabel' === key ) { before = s.link.ariaLabel || null; s.link.ariaLabel = v; } else { before = setAttr( s, 'aria-label', v ); }
				break;
			case 'removeTabindex':
				before = s._attributes.splice( attrIndex( s, 'tabindex' ), 1 )[ 0 ].value; v = null;
				break;
			default:
				return { ok: false, error: 'unknown-fix' };
		}
		try { if ( g.$_forceRenderElementSubtree ) { g.$_forceRenderElementSubtree( el.id ); } else if ( g.$_forceRender ) { g.$_forceRender( 0, el.id ); } } catch ( e ) {}
		return { ok: true, elementId: el.id, key: key, before: before, after: v };
	}

	function show( item ) {
		var w = canvas(), id = item && item.target && item.target.elementId, n = null;
		if ( w && item && item.target ) {
			try { n = w.document.querySelector( item.target.selector ) || ( id ? w.document.querySelector( '[data-id="' + id + '"]' ) : null ); } catch ( e ) {}
			if ( n && n.scrollIntoView ) { n.scrollIntoView( { block: 'center' } ); }
		}
		var g = props();
		if ( ! g || ! id ) { return false; }
		var st = u( g.$_state ), hit = index( st )[ id ];
		if ( ! hit || hit.part !== edited( st ) ) { return false; }
		st.activeId = id; st.activePanel = 'element';
		try { if ( g.$_setActiveElement ) { g.$_setActiveElement(); } } catch ( e ) {}
		st.scrollToElementId = id;
		return true;
	}

	W.TokenForge = W.TokenForge || {};
	W.TokenForge.check = { run: run, audit: audit, fix: fix, measure: measure, show: show, elementTree: elementTree, last: function () { return lastResult; }, RULES: RULES, CARD_CLASS: CARD_CLASS };
}() );
