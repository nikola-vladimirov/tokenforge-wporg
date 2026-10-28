/* TokenForge: contextual menus + CSS autocomplete for the Bricks builder.
 *
 * Right-click a Bricks control input  → searchable menu of TokenForge
 *   variables. The group that fits the control (Colors for a colour field,
 *   Spacing for padding/margin/gap, Typography for font size …) is pre-selected;
 *   "All" is one click away and typing searches everything. Recently used
 *   variables come first. Picking inserts var(--name) (replacing the selection,
 *   or the whole value when nothing is selected).
 * Right-click the element class selector → TokenForge classes with the CSS each
 *   one applies; pick to add/remove on the active element (menu stays open).
 * CSS editor (CodeMirror) → as-you-type suggestions: variables after `--` /
 *   `var(`, classes after `.` in a selector. ↑↓ to move, Enter/Tab to insert.
 * Recipes → type `?name` in a CSS editor and pick it (or finish with `;`): it
 *   expands into the recipe's CSS (%root% = the element). In control inputs,
 *   `?name;` expands value recipes such as `?primary-clr;`.
 * Shift + right-click always opens the browser's own menu.
 *
 * Uses the Bricks 2.x Vue store (see bem-generator.js for the same API).
 */
( function () {
	'use strict';
	if ( window.__tokenforgeCtx ) { return; } // load once
	window.__tokenforgeCtx = true;

	var cfg = window.TokenForgeCtx || {};
	var VARS = cfg.vars || [];      // [ [name, group, value], … ]
	var CLASSES = cfg.classes || []; // [ name, … ]
	var RECIPES = ( cfg.recipes && cfg.recipes.recipes ) || []; // [ { n, c, code, inline }, … ]
	var RECIPE = {};
	RECIPES.forEach( function ( r ) { RECIPE[ r.n ] = r; } );
	var MAX = 250;

	var GROUP_LABEL = { space: 'Spacing', width: 'Width', typography: 'Typography', color: 'Colors', radius: 'Radius', shadow: 'Shadows', breakpoint: 'Breakpoints', other: 'Other' };
	var GROUP_ORDER = [ 'space', 'width', 'typography', 'color', 'radius', 'shadow', 'other', 'breakpoint' ];

	// ---- Bricks store helpers ----
	function unref( v ) { return ( v && v.__v_isRef ) ? v.value : v; }
	function gp() { var r = document.querySelector( 'div.brx-body.main' ); return r && r.__vue_app__ ? r.__vue_app__.config.globalProperties : null; }
	function activeEl( g ) { return unref( g.$_activeElement ); }
	function globalClasses( g ) { var s = unref( g.$_state ); return unref( g.$_globalClasses ) || ( s && unref( s.globalClasses ) ) || []; }
	function rid() { var s = ''; while ( s.length < 6 ) { s += Math.random().toString( 36 ).slice( 2 ); } return s.slice( 0, 6 ); }
	function userId() { try { return unref( gp().$_state ).currentUserId || 0; } catch ( e ) { return 0; } }
	function esc( s ) { return String( s ).replace( /[&<>"]/g, function ( c ) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[ c ]; } ); }

	// ---- Variable model: resolved value, colour swatch, effective group ----
	var byName = {}, META = {};
	function resolve( val, depth ) {
		if ( depth > 6 || typeof val !== 'string' ) { return val; }
		return val.replace( /var\(\s*--([a-z0-9\-_]+)\s*(?:,([^()]*))?\)/gi, function ( m, n, fb ) {
			return byName[ n ] !== undefined ? resolve( byName[ n ], depth + 1 ) : ( fb ? fb.trim() : m );
		} );
	}
	function isColor( c ) { return !! ( c && window.CSS && CSS.supports && CSS.supports( 'color', c ) ); }
	function index() {
		byName = {}; META = {};
		VARS.forEach( function ( v ) { byName[ v[0] ] = v[2]; } );
		VARS.forEach( function ( v ) {
			var res = resolve( v[2], 0 ), color = isColor( res );
			var group = /^(width-|content-width)/.test( v[0] ) ? 'width' : ( color ? 'color' : v[1] );
			META[ v[0] ] = { res: res, color: color ? res : '', group: GROUP_LABEL[ group ] ? group : 'other' };
		} );
	}
	index();

	// ---- Recently used (per browser) ----
	function recent( kind ) { try { return JSON.parse( localStorage.getItem( 'tf-recent-' + kind ) || '[]' ); } catch ( e ) { return []; } }
	function remember( kind, name ) {
		var list = recent( kind ).filter( function ( n ) { return n !== name; } );
		list.unshift( name );
		try { localStorage.setItem( 'tf-recent-' + kind, JSON.stringify( list.slice( 0, 8 ) ) ); } catch ( e ) {}
	}

	// ---- Class CSS preview (read from the canvas stylesheet) ----
	var classCss = null;
	function readClassCss() {
		classCss = {};
		var ifr = document.getElementById( 'bricks-builder-iframe' ), doc;
		try { doc = ifr && ifr.contentDocument; } catch ( e ) { doc = null; }
		if ( ! doc ) { return; }
		var node = doc.getElementById( 'tokenforge-inline-css' ) || doc.getElementById( 'tokenforge-css' );
		var sheet = node && node.sheet;
		if ( ! sheet ) { return; }
		var walk = function ( rules ) {
			for ( var i = 0; i < rules.length; i++ ) {
				var r = rules[ i ];
				if ( r.cssRules && ! r.selectorText ) { walk( r.cssRules ); continue; }
				if ( ! r.selectorText ) { continue; }
				r.selectorText.split( ',' ).forEach( function ( sel ) {
					var m = /^\s*\.([a-z0-9_-]+)\s*$/i.exec( sel );
					if ( m && ! classCss[ m[1] ] ) { classCss[ m[1] ] = r.style.cssText; }
				} );
			}
		};
		try { walk( sheet.cssRules ); } catch ( e ) {}
	}

	// ---- Refresh after a dashboard save (hot reload broadcast) ----
	if ( cfg.restUrl && typeof window.BroadcastChannel === 'function' ) {
		try {
			new window.BroadcastChannel( 'tokenforge' ).onmessage = function ( e ) {
				if ( ! e.data || 'tokenforge:css' !== e.data.type ) { return; }
				classCss = null;
				fetch( cfg.restUrl + '/menu-data', { credentials: 'same-origin', cache: 'no-store', headers: { 'X-WP-Nonce': cfg.nonce } } )
					.then( function ( r ) { return r.ok ? r.json() : null; } )
					.then( function ( d ) {
						if ( ! d ) { return; }
						if ( Array.isArray( d.vars ) ) { VARS = d.vars; index(); }
						if ( Array.isArray( d.classes ) ) { CLASSES = d.classes; }
					} )
					.catch( function () {} );
			};
		} catch ( e ) {}
	}

	// ---- Which group fits this control? ----
	function suggested( input ) {
		var ck = input.closest( '[data-controlkey]' );
		var label = ck && ck.querySelector( 'label' );
		var hint = [ input.id, input.placeholder, ck ? ck.getAttribute( 'data-controlkey' ) : '', label ? label.textContent : '' ].join( ' ' ).toLowerCase();
		if ( /colou?r|fill|stroke/.test( hint ) ) { return 'color'; }
		if ( /radius/.test( hint ) ) { return 'radius'; }
		if ( /shadow/.test( hint ) ) { return 'shadow'; }
		if ( /font|line-height|letter|typograph/.test( hint ) ) { return 'typography'; }
		if ( /(^|[\s_-])(width|height|basis|max|min)/.test( hint ) ) { return 'width'; }
		if ( /padding|margin|gap|inset|top|right|bottom|left|space/.test( hint ) ) { return 'space'; }
		return '';
	}
	function propGroup( prop ) {
		prop = ( prop || '' ).toLowerCase();
		if ( /color|background|fill|stroke|outline|border-color|caret|accent/.test( prop ) ) { return 'color'; }
		if ( /radius/.test( prop ) ) { return 'radius'; }
		if ( /shadow/.test( prop ) ) { return 'shadow'; }
		if ( /font|line-height|letter/.test( prop ) ) { return 'typography'; }
		if ( /width|height|basis|inline-size|block-size/.test( prop ) ) { return 'width'; }
		if ( /padding|margin|gap|inset|top|right|bottom|left/.test( prop ) ) { return 'space'; }
		return '';
	}

	// ---- Shared ranking ----
	function rankVars( q, sug, onlyGroup ) {
		var score = function ( n ) { return ! q ? 0 : ( n === q ? 0 : ( 0 === n.indexOf( q ) ? 1 : ( n.indexOf( '-' + q ) > -1 ? 2 : 3 ) ) ); };
		var gRank = function ( g ) { return g === sug ? -1 : GROUP_ORDER.indexOf( g ); };
		return VARS.filter( function ( v ) {
			return ( ! onlyGroup || META[ v[0] ].group === onlyGroup ) && ( ! q || v[0].indexOf( q ) > -1 );
		} ).sort( function ( a, b ) {
			var ga = META[ a[0] ].group, gb = META[ b[0] ].group;
			return ( score( a[0] ) - score( b[0] ) ) || ( gRank( ga ) - gRank( gb ) ) || ( q ? a[0].length - b[0].length : 0 );
		} );
	}
	function rankClasses( q ) {
		return CLASSES.filter( function ( c ) { return ! q || c.indexOf( q ) > -1; } ).sort( function ( a, b ) {
			if ( ! q ) { return 0; }
			var sa = a === q ? 0 : ( 0 === a.indexOf( q ) ? 1 : 2 ), sb = b === q ? 0 : ( 0 === b.indexOf( q ) ? 1 : 2 );
			return ( sa - sb ) || ( a.length - b.length );
		} );
	}
	function varRow( name, i, extraCls ) {
		var m = META[ name ] || { res: byName[ name ], color: '' };
		return '<li data-i="' + i + '" role="option" class="' + ( extraCls || '' ) + '" title="' + esc( '--' + name + ': ' + byName[ name ] ) + '">' +
			( m.color ? '<span class="tf-ctx-sw" style="--sw:' + esc( m.color ) + '"></span>' : '<span class="tf-ctx-sw is-none"></span>' ) +
			'<span class="tf-ctx-name">--' + esc( name ) + '</span><span class="tf-ctx-val">' + esc( m.res ) + '</span></li>';
	}

	// =====================================================================
	// Right-click menu
	// =====================================================================
	var menu = null, list, search, chips, foot, mode = '', target = null, items = [], sel = 0, filterGroup = '', savedRange = null, sugGroup = '';

	function build() {
		menu = document.createElement( 'div' );
		menu.id = 'tf-ctx';
		menu.setAttribute( 'role', 'menu' );
		menu.innerHTML = '<div class="tf-ctx-head"><input type="text" class="tf-ctx-search" autocomplete="off" spellcheck="false"></div>' +
			'<div class="tf-ctx-chips"></div><ul class="tf-ctx-list" role="listbox"></ul><div class="tf-ctx-foot"></div>';
		document.body.appendChild( menu );
		list = menu.querySelector( '.tf-ctx-list' );
		search = menu.querySelector( '.tf-ctx-search' );
		chips = menu.querySelector( '.tf-ctx-chips' );
		foot = menu.querySelector( '.tf-ctx-foot' );

		search.addEventListener( 'input', function () { sel = 0; renderList(); } );
		search.addEventListener( 'keydown', function ( e ) {
			if ( 'ArrowDown' === e.key ) { e.preventDefault(); move( 1 ); }
			else if ( 'ArrowUp' === e.key ) { e.preventDefault(); move( -1 ); }
			else if ( 'Enter' === e.key ) { e.preventDefault(); pick( items[ sel ] ); }
			else if ( 'Escape' === e.key ) { e.preventDefault(); hide( true ); }
			else if ( 'Tab' === e.key && 'vars' === mode ) { e.preventDefault(); cycleChip( e.shiftKey ? -1 : 1 ); }
		} );
		chips.addEventListener( 'click', function ( e ) {
			var c = e.target.closest( '[data-group]' );
			if ( ! c ) { return; }
			filterGroup = c.getAttribute( 'data-group' );
			sel = 0; renderChips(); renderList(); search.focus();
		} );
		list.addEventListener( 'mousedown', function ( e ) { e.preventDefault(); } ); // keep input selection
		list.addEventListener( 'click', function ( e ) {
			var li = e.target.closest( 'li[data-i]' );
			if ( li ) { pick( items[ parseInt( li.getAttribute( 'data-i' ), 10 ) ] ); }
		} );
		list.addEventListener( 'mousemove', function ( e ) {
			var li = e.target.closest( 'li[data-i]' );
			if ( li ) { var i = parseInt( li.getAttribute( 'data-i' ), 10 ); if ( i !== sel ) { sel = i; highlight(); } }
		} );
		document.addEventListener( 'mousedown', function ( e ) { if ( menu.classList.contains( 'is-open' ) && ! menu.contains( e.target ) ) { hide( false ); } }, true );
		window.addEventListener( 'blur', function () { hide( false ); } );
		window.addEventListener( 'resize', function () { hide( false ); } );
	}

	function presentGroups() {
		var present = {};
		VARS.forEach( function ( v ) { present[ META[ v[0] ].group ] = true; } );
		return GROUP_ORDER.filter( function ( g ) { return present[ g ]; } );
	}
	function cycleChip( d ) {
		var gs = [ '' ].concat( presentGroups() ), i = gs.indexOf( filterGroup );
		filterGroup = gs[ ( i + d + gs.length ) % gs.length ];
		sel = 0; renderChips(); renderList();
	}

	function renderChips() {
		chips.style.display = 'vars' === mode ? '' : 'none';
		if ( 'vars' !== mode ) { chips.innerHTML = ''; return; }
		chips.innerHTML = [ '' ].concat( presentGroups() ).map( function ( g ) {
			return '<button type="button" class="tf-ctx-chip' + ( g === filterGroup ? ' is-active' : '' ) + ( g && g === sugGroup ? ' is-suggested' : '' ) + '" data-group="' + g + '">' + ( g ? GROUP_LABEL[ g ] : 'All' ) + '</button>';
		} ).join( '' );
	}

	function renderList() {
		var q = search.value.trim().toLowerCase().replace( /^--|^\./, '' );
		var html = '', seen = {};
		items = [];
		if ( 'vars' === mode ) {
			// Recent first (only when not searching), within the current filter.
			if ( ! q ) {
				var rec = recent( 'vars' ).filter( function ( n ) { return META[ n ] && ( ! filterGroup || META[ n ].group === filterGroup ); } );
				if ( rec.length ) {
					html += '<li class="tf-ctx-sep">Recent</li>';
					rec.forEach( function ( n ) { html += varRow( n, items.length ); items.push( n ); seen[ n ] = true; } );
				}
			}
			// Typing searches every group (the chip only narrows the empty view).
			var pool = rankVars( q, sugGroup, q ? '' : filterGroup ).filter( function ( v ) { return ! seen[ v[0] ]; } );
			var last = null;
			pool.slice( 0, MAX ).forEach( function ( v ) {
				var g = META[ v[0] ].group;
				if ( ! q && ! filterGroup && g !== last ) { html += '<li class="tf-ctx-sep">' + esc( GROUP_LABEL[ g ] ) + '</li>'; last = g; }
				html += varRow( v[0], items.length );
				items.push( v[0] );
			} );
			if ( pool.length > MAX ) { html += '<li class="tf-ctx-more">+' + ( pool.length - MAX ) + ' more, keep typing</li>'; }
		} else {
			if ( ! classCss ) { readClassCss(); }
			var applied = appliedNames();
			var add = function ( c ) {
				var on = applied.indexOf( c ) > -1, decl = classCss[ c ] || '';
				html += '<li data-i="' + items.length + '" role="option" class="' + ( on ? 'is-applied' : '' ) + '" title="' + esc( '.' + c + ( decl ? ' { ' + decl + ' }' : '' ) ) + '">' +
					'<span class="tf-ctx-check">' + ( on ? '&#10003;' : '' ) + '</span><span class="tf-ctx-name">.' + esc( c ) + '</span>' +
					'<span class="tf-ctx-val">' + esc( decl ) + '</span></li>';
				items.push( c );
				seen[ c ] = true;
			};
			if ( ! q ) {
				var recC = recent( 'classes' ).filter( function ( c ) { return CLASSES.indexOf( c ) > -1; } );
				if ( recC.length ) { html += '<li class="tf-ctx-sep">Recent</li>'; recC.forEach( add ); html += '<li class="tf-ctx-sep">All classes</li>'; }
			}
			var pool2 = rankClasses( q ).filter( function ( c ) { return ! seen[ c ]; } );
			pool2.slice( 0, MAX ).forEach( add );
			if ( pool2.length > MAX ) { html += '<li class="tf-ctx-more">+' + ( pool2.length - MAX ) + ' more, keep typing</li>'; }
		}
		if ( ! items.length ) { html = '<li class="tf-ctx-empty">No matches</li>'; }
		list.innerHTML = html;
		sel = Math.min( sel, Math.max( 0, items.length - 1 ) );
		highlight();
	}

	function highlight() {
		list.querySelectorAll( 'li[data-i]' ).forEach( function ( li ) { li.classList.toggle( 'is-sel', parseInt( li.getAttribute( 'data-i' ), 10 ) === sel ); } );
		var cur = list.querySelector( 'li.is-sel' );
		if ( cur && cur.scrollIntoView ) { cur.scrollIntoView( { block: 'nearest' } ); }
	}
	function move( d ) { if ( items.length ) { sel = ( sel + d + items.length ) % items.length; highlight(); } }

	function show( x, y, kind, el ) {
		if ( ! menu ) { build(); }
		closeAc();
		mode = kind; target = el; sel = 0;
		sugGroup = 'vars' === kind ? suggested( el ) : '';
		filterGroup = sugGroup; // pre-select the fitting group
		search.value = '';
		search.placeholder = 'vars' === kind ? 'Search variables…' : 'Search classes…';
		foot.textContent = 'vars' === kind
			? '↵ insert · Tab: next group · select text first to insert inside a value · Shift+right-click: browser menu'
			: '↵ add / remove · hover a row to see its CSS · Shift+right-click: browser menu';
		savedRange = 'vars' === kind ? [ el.selectionStart, el.selectionEnd ] : null;
		renderChips(); renderList();
		menu.classList.add( 'is-open' );
		var r = menu.getBoundingClientRect();
		menu.style.left = Math.max( 8, Math.min( x, window.innerWidth - r.width - 8 ) ) + 'px';
		menu.style.top = Math.max( 8, Math.min( y, window.innerHeight - r.height - 8 ) ) + 'px';
		search.focus();
	}

	function hide( refocus ) {
		if ( ! menu || ! menu.classList.contains( 'is-open' ) ) { return; }
		menu.classList.remove( 'is-open' );
		if ( refocus && target && 'vars' === mode ) { try { target.focus(); } catch ( e ) {} }
	}

	// ---- Actions ----
	function insertVar( name ) {
		var input = target;
		if ( ! input || ! document.contains( input ) ) { return; }
		var token = 'var(--' + name + ')';
		var v = input.value || '';
		var a = savedRange ? savedRange[0] : null, b = savedRange ? savedRange[1] : null;
		var next = ( a !== null && b !== null && b > a ) ? v.slice( 0, a ) + token + v.slice( b ) : token;
		var setter = Object.getOwnPropertyDescriptor( window.HTMLInputElement.prototype, 'value' ).set;
		input.focus();
		setter.call( input, next );
		input.dispatchEvent( new Event( 'input', { bubbles: true } ) );
		input.dispatchEvent( new Event( 'change', { bubbles: true } ) );
		var caret = ( a !== null && b > a ) ? a + token.length : next.length;
		try { input.setSelectionRange( caret, caret ); } catch ( e ) {}
		remember( 'vars', name );
		// Bricks opens its own variable picker when an input gains 'var(--'.
		// It opens async (~50ms) and closes on an outside click, so watch briefly
		// and dismiss it as soon as it opens, keeping focus + caret in the input.
		var ctl = input.closest( '[data-control]' );
		var pb = ctl && ctl.querySelector( '.variable-picker-button' );
		if ( pb && window.MutationObserver ) {
			var dismiss = function () {
				document.body.dispatchEvent( new MouseEvent( 'mousedown', { bubbles: true } ) );
				document.body.dispatchEvent( new MouseEvent( 'click', { bubbles: true } ) );
				try { input.focus(); input.setSelectionRange( caret, caret ); } catch ( e2 ) {}
			};
			// Bricks binds its outside-click listener just after opening, so a
			// dismiss in the same tick is ignored, wait a beat first.
			var later = function () { setTimeout( dismiss, 80 ); };
			var mo = new MutationObserver( function () {
				if ( pb.classList.contains( 'open' ) ) { mo.disconnect(); later(); }
			} );
			mo.observe( pb, { attributes: true, attributeFilter: [ 'class' ] } );
			setTimeout( function () { mo.disconnect(); }, 800 );
			if ( pb.classList.contains( 'open' ) ) { mo.disconnect(); later(); }
		}
	}

	function appliedNames() {
		var g = gp(); if ( ! g ) { return []; }
		var el = activeEl( g ); if ( ! el || ! el.settings || ! Array.isArray( el.settings._cssGlobalClasses ) ) { return []; }
		var gc = globalClasses( g ), out = [];
		el.settings._cssGlobalClasses.forEach( function ( id ) { var c = gc.find( function ( x ) { return x.id === id; } ); if ( c ) { out.push( c.name ); } } );
		return out;
	}

	function toggleClass( name ) {
		var g = gp(); if ( ! g ) { return; }
		var el = activeEl( g ); if ( ! el ) { return; }
		var gc = globalClasses( g );
		var cls = gc.find( function ( c ) { return c.name === name; } );
		if ( ! cls ) {
			// Not registered with Bricks yet (registration off): create it like the BEM tool does.
			var id = rid();
			cls = { id: id, name: name, settings: {}, _mappedId: id, modified: Math.floor( Date.now() / 1000 ), user_id: userId() };
			gc.push( cls );
			if ( cfg.restUrl ) {
				try {
					fetch( cfg.restUrl + '/register-classes', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce }, body: JSON.stringify( { items: [ { id: id, name: name } ] } ) } );
				} catch ( e ) {}
			}
		}
		if ( ! el.settings ) { el.settings = {}; }
		if ( ! Array.isArray( el.settings._cssGlobalClasses ) ) { el.settings._cssGlobalClasses = []; }
		var arr = el.settings._cssGlobalClasses, at = arr.indexOf( cls.id );
		if ( at > -1 ) { arr.splice( at, 1 ); } else { arr.push( cls.id ); remember( 'classes', name ); }
		try { g.$_forceRenderElementSubtree && g.$_forceRenderElementSubtree( el.id ); } catch ( e2 ) {}
		renderList(); // keep menu open so several classes can be toggled
		search.focus();
	}

	function pick( name ) {
		if ( ! name ) { return; }
		if ( 'vars' === mode ) { hide( false ); insertVar( name ); }
		else { toggleClass( name ); }
	}

	// ---- Triggers ----
	function isControlInput( el ) {
		return el && el.tagName === 'INPUT' && ( ! el.type || el.type === 'text' ) &&
			el.closest( '#bricks-panel [data-control], .bricks-control-popup [data-control], .bricks-popup [data-control]' ) &&
			! el.closest( '#bricks-panel-header, #bricks-panel-element-classes' );
	}

	document.addEventListener( 'contextmenu', function ( e ) {
		if ( e.shiftKey ) { return; }
		var t = e.target;
		if ( cfg.vars_menu && VARS.length && isControlInput( t ) ) {
			e.preventDefault();
			show( e.clientX, e.clientY, 'vars', t );
			return;
		}
		if ( cfg.class_menu && CLASSES.length && t.closest && t.closest( '#bricks-panel-element-classes' ) && gp() && activeEl( gp() ) ) {
			e.preventDefault();
			show( e.clientX, e.clientY, 'classes', t );
		}
	}, true );

	// Recipes in builder fields: type "?name;" (with the semicolon).
	// - style fields: value recipes (?primary-clr;);
	// - text fields and text areas: text recipes (?ipsum-short;) and value recipes;
	// - the rich text editor and text edited in place in the canvas: text recipes.
	function recipeText( name, kinds ) {
		var r = RECIPE[ name ];
		return r && kinds.indexOf( recipeKind( r ) ) > -1 ? String( r.code ).replace( '{{cursor}}', '' ) : null;
	}
	function isTextField( el ) {
		return el && ( ( 'INPUT' === el.tagName && ( ! el.type || 'text' === el.type ) ) || 'TEXTAREA' === el.tagName ) &&
			el.closest( '#bricks-panel [data-control], .bricks-control-popup [data-control], .bricks-popup [data-control]' ) &&
			! el.closest( '#bricks-panel-header, #bricks-panel-element-classes, .CodeMirror' );
	}
	// The "?name;" just typed before the caret in a text node: [ recipe name, range to replace ] or null.
	// Text recipes also expand on the space typed after the name ("?ipsum-short "); the space stays.
	function typedRecipe( doc, node, offset ) {
		if ( ! node || 3 !== node.nodeType ) { return null; }
		var m = /\?([a-z0-9-]+)(;|[ \u00a0])$/.exec( node.data.slice( 0, offset ) );
		if ( ! m ) { return null; }
		var r = doc.createRange();
		r.setStart( node, offset - m[0].length );
		r.setEnd( node, offset );
		return [ m[1], r, ';' === m[2] ? '' : ' ' ];
	}
	function hookEditor( ed ) {
		if ( ! ed || ed.__tfRecipes ) { return; }
		ed.__tfRecipes = true;
		ed.on( 'input keyup', function () {
			var rng = ed.selection && ed.selection.getRng();
			if ( ! rng || ! rng.collapsed ) { return; }
			var hit = typedRecipe( ed.getDoc(), rng.startContainer, rng.startOffset ), text = hit && recipeText( hit[0], [ 'text' ] );
			if ( null === text || ! hit ) { return; }
			// Typed in as text by the browser, like the canvas below (inserting HTML would
			// drop the space after it).
			ed.selection.setRng( hit[1] );
			ed.getDoc().execCommand( 'insertText', false, text + hit[2] );
			ed.fire( 'change' ); // the builder's rich text field saves on change
		} );
	}
	function hookCanvas() {
		var f = document.getElementById( 'bricks-builder-iframe' ), doc = null;
		try { doc = f && f.contentDocument; } catch ( e ) {}
		if ( ! doc || doc.__tfRecipes ) { return; }
		doc.__tfRecipes = true;
		doc.addEventListener( 'input', function ( e ) {
			var el = e.target;
			if ( ! el || ! el.isContentEditable ) { return; }
			var sel = doc.getSelection();
			if ( ! sel || ! sel.rangeCount || ! sel.isCollapsed ) { return; }
			var rng = sel.getRangeAt( 0 ), hit = typedRecipe( doc, rng.startContainer, rng.startOffset ), text = hit && recipeText( hit[0], [ 'text' ] );
			if ( null === text || ! hit ) { return; }
			sel.removeAllRanges();
			sel.addRange( hit[1] );
			doc.execCommand( 'insertText', false, text + hit[2] ); // a new input event: the builder saves the text
		}, true );
	}
	if ( RECIPES.length && cfg.recipes.inputs ) {
		document.addEventListener( 'input', function ( e ) {
			var t = e.target;
			if ( ! e.isTrusted || ! isTextField( t ) ) { return; }
			var pos = null == t.selectionStart ? t.value.length : t.selectionStart, before = t.value.slice( 0, pos );
			var m = /\?([a-z0-9-]+)(;|[ \u00a0])$/.exec( before );
			if ( ! m ) { return; }
			// "?name;": value and text recipes; "?name " (a space): text recipes only, the space stays.
			var code = recipeText( m[1], ';' === m[2] ? [ 'value', 'text' ] : [ 'text' ] );
			if ( null === code ) { return; }
			code += ';' === m[2] ? '' : ' ';
			var start = pos - m[0].length, typed = m[0];
			var setter = Object.getOwnPropertyDescriptor( ( 'TEXTAREA' === t.tagName ? window.HTMLTextAreaElement : window.HTMLInputElement ).prototype, 'value' ).set;
			setTimeout( function () {
				// Worked out now, from the field as it is: keys typed meanwhile stay.
				var v = t.value;
				if ( v.substr( start, typed.length ) !== typed ) { return; }
				var caret = null == t.selectionStart ? v.length : t.selectionStart;
				setter.call( t, v.slice( 0, start ) + code + v.slice( start + typed.length ) );
				t.dispatchEvent( new Event( 'input', { bubbles: true } ) );
				t.dispatchEvent( new Event( 'change', { bubbles: true } ) );
				var at = caret >= start + typed.length ? caret + code.length - typed.length : start + code.length;
				try { t.setSelectionRange( at, at ); } catch ( er ) {}
			}, 0 );
		}, true );
		// The rich text editor (TinyMCE) and the canvas come and go: hook them when they appear.
		setInterval( function () {
			hookCanvas();
			var mce = window.tinymce;
			if ( mce && ! mce.__tfRecipes ) {
				mce.__tfRecipes = true;
				if ( mce.on ) { mce.on( 'AddEditor', function ( ev ) { hookEditor( ev.editor ); } ); }
			}
			if ( mce && mce.editors ) { [].forEach.call( mce.editors, hookEditor ); }
		}, 1500 );
	}

	// =====================================================================
	// CSS editor autocomplete (CodeMirror 5, css mode)
	// =====================================================================
	var ac = { el: null, cm: null, items: [], sel: 0, kind: '', from: 0, line: 0, hasVar: false, keys: null };

	function acBuild() {
		ac.el = document.createElement( 'div' );
		ac.el.id = 'tf-ac';
		ac.el.innerHTML = '<ul class="tf-ctx-list"></ul><pre class="tf-ac-preview" hidden></pre><div class="tf-ctx-foot">↑↓ · ↵/Tab insert · Esc</div>';
		document.body.appendChild( ac.el );
		ac.el.addEventListener( 'mousedown', function ( e ) { e.preventDefault(); } ); // keep editor focus
		ac.el.addEventListener( 'click', function ( e ) {
			var li = e.target.closest( 'li[data-i]' );
			if ( li ) { ac.sel = parseInt( li.getAttribute( 'data-i' ), 10 ); acPick(); }
		} );
	}

	function closeAc() {
		if ( ! ac.el || ! ac.el.classList.contains( 'is-open' ) ) { return; }
		ac.el.classList.remove( 'is-open' );
		if ( ac.cm && ac.keys ) { ac.cm.removeKeyMap( ac.keys ); }
		ac.keys = null;
	}

	function acHighlight() {
		ac.el.querySelectorAll( 'li[data-i]' ).forEach( function ( li ) { li.classList.toggle( 'is-sel', parseInt( li.getAttribute( 'data-i' ), 10 ) === ac.sel ); } );
		var cur = ac.el.querySelector( 'li.is-sel' );
		if ( cur && cur.scrollIntoView ) { cur.scrollIntoView( { block: 'nearest' } ); }
		// The highlighted recipe's code, so you see what it adds before you pick it.
		var pv = ac.el.querySelector( '.tf-ac-preview' ), rc = 'recipes' === ac.kind ? RECIPE[ ac.items[ ac.sel ] ] : null;
		if ( pv ) {
			var lines = rc && rc.code ? String( rc.code ).split( '\n' ) : [];
			pv.hidden = ! lines.length;
			pv.textContent = lines.slice( 0, 12 ).join( '\n' ) + ( lines.length > 12 ? '\n…' : '' );
		}
	}

	// Inside a { … } block (declarations) or at selector level?
	function braceDepth( cm, pos ) {
		var text = cm.getRange( { line: 0, ch: 0 }, pos ).replace( /\/\*[\s\S]*?\*\//g, '' );
		var depth = 0;
		for ( var i = 0; i < text.length; i++ ) {
			if ( '{' === text[ i ] ) { depth++; } else if ( '}' === text[ i ] && depth > 0 ) { depth--; }
		}
		return depth;
	}

	function acSuggest( cm ) {
		var cur = cm.getCursor(), before = cm.getLine( cur.line ).slice( 0, cur.ch ), m, q, html = '';
		ac.items = []; ac.sel = 0; ac.cm = cm; ac.line = cur.line;
		if ( RECIPES.length && ( m = /(^|[^\w?])\?([a-z0-9-]*)$/.exec( before ) ) ) {
			ac.kind = 'recipes'; q = m[2]; ac.from = cur.ch - m[2].length - 1;
		} else if ( ! cfg.css_ac ) {
			closeAc(); return;
		} else if ( ( m = /(var\(\s*)?--([\w-]*)$/.exec( before ) ) && ( m[1] || /:[^;{}]*$/.test( before.slice( 0, before.length - m[0].length ) ) ) ) {
			// A value position (after ':' or inside var()), not a custom property being defined.
			ac.kind = 'vars'; q = m[2]; ac.hasVar = !! m[1]; ac.from = cur.ch - m[2].length - 2;
		} else if ( /var\(\s*$/.test( before ) ) {
			ac.kind = 'vars'; q = ''; ac.hasVar = true; ac.from = cur.ch;
		} else if ( ( m = /(^|[\s,>+~(])\.([\w-]*)$/.exec( before ) ) && 0 === braceDepth( cm, cur ) ) {
			ac.kind = 'classes'; q = m[2]; ac.from = cur.ch - m[2].length;
		} else {
			closeAc(); return;
		}
		if ( 'recipes' === ac.kind ) {
			// Only what fits here: values inside "prop: |"; elsewhere rules and
			// declarations first, then values (they expand to "color: …;").
			var spot = recipeSpot( cm, { line: cur.line, ch: ac.from } );
			RECIPES.filter( function ( r ) { var k = recipeKind( r ); return ( 'value' === spot ? 'value' === k : 'text' !== k ) && ( ! q || r.n.indexOf( q ) > -1 || r.c.toLowerCase().indexOf( q ) > -1 ); } )
				.sort( function ( a, b ) { return ( 'block' === spot ? ( 'value' === recipeKind( a ) ? 1 : 0 ) - ( 'value' === recipeKind( b ) ? 1 : 0 ) : 0 ) || ( 0 === a.n.indexOf( q ) ? 0 : 1 ) - ( 0 === b.n.indexOf( q ) ? 0 : 1 ) || a.n.localeCompare( b.n ); } )
				.slice( 0, 80 ).forEach( function ( r ) {
					var asDecl = 'block' === spot && 'value' === recipeKind( r );
					html += '<li data-i="' + ac.items.length + '" role="option"><span class="tf-ctx-check"></span><span class="tf-ctx-name">?' + esc( r.n ) + '</span><span class="tf-ctx-val">' + esc( r.c + ( asDecl ? ' value, adds a declaration' : '' ) ) + '</span></li>';
					ac.items.push( r.n );
				} );
		} else if ( 'vars' === ac.kind ) {
			var pm = /([\w-]+)\s*:[^;:{}]*$/.exec( before );
			rankVars( q.toLowerCase(), propGroup( pm ? pm[1] : '' ), '' ).slice( 0, 60 ).forEach( function ( v ) {
				html += varRow( v[0], ac.items.length ); ac.items.push( v[0] );
			} );
		} else {
			if ( ! classCss ) { readClassCss(); }
			rankClasses( q.toLowerCase() ).slice( 0, 60 ).forEach( function ( c ) {
				html += '<li data-i="' + ac.items.length + '" role="option"><span class="tf-ctx-check"></span><span class="tf-ctx-name">.' + esc( c ) + '</span><span class="tf-ctx-val">' + esc( classCss[ c ] || '' ) + '</span></li>';
				ac.items.push( c );
			} );
		}
		if ( ! ac.items.length || ( 1 === ac.items.length && ac.items[0] === q ) ) { closeAc(); return; }
		if ( ! ac.el ) { acBuild(); }
		ac.el.querySelector( '.tf-ctx-list' ).innerHTML = html;
		var wasOpen = ac.el.classList.contains( 'is-open' );
		ac.el.classList.add( 'is-open' );
		var c = cm.cursorCoords( { line: cur.line, ch: ac.from }, 'window' ), r = ac.el.getBoundingClientRect();
		ac.el.style.left = Math.max( 8, Math.min( c.left - 8, window.innerWidth - r.width - 8 ) ) + 'px';
		ac.el.style.top = ( c.bottom + 4 + r.height > window.innerHeight ? Math.max( 8, c.top - r.height - 4 ) : c.bottom + 4 ) + 'px';
		acHighlight();
		if ( ! wasOpen || ! ac.keys ) {
			ac.keys = {
				Up: function () { ac.sel = ( ac.sel - 1 + ac.items.length ) % ac.items.length; acHighlight(); },
				Down: function () { ac.sel = ( ac.sel + 1 ) % ac.items.length; acHighlight(); },
				Enter: acPick,
				Tab: acPick,
				Esc: closeAc
			};
			cm.addKeyMap( ac.keys );
		}
	}

	function acPick() {
		var cm = ac.cm, name = ac.items[ ac.sel ];
		if ( ! cm || ! name ) { closeAc(); return; }
		var cur = cm.getCursor(), from = { line: ac.line, ch: ac.from }, text;
		if ( 'recipes' === ac.kind ) {
			closeAc();
			cm.replaceRange( '?' + name, from, cur, '+tfcomplete' );
			expandRecipe( cm );
			cm.focus();
			return;
		}
		if ( 'vars' === ac.kind ) {
			var after = cm.getLine( cur.line ).slice( cur.ch );
			text = ac.hasVar ? '--' + name + ( /^\s*\)/.test( after ) ? '' : ')' ) : 'var(--' + name + ')';
			remember( 'vars', name );
		} else {
			text = name;
		}
		closeAc();
		cm.replaceRange( text, from, cur, '+tfcomplete' );
		cm.focus();
	}

	// "?name" (optionally followed by ";") just before the caret → the recipe's code,
	// indented like the current line; the caret goes to {{cursor}}.
	// A value inside a declaration ("color: ?x"), or anything else.
	function recipeSpot( cm, pos ) {
		var before = cm.getRange( { line: 0, ch: 0 }, pos );
		var cut = Math.max( before.lastIndexOf( '{' ), before.lastIndexOf( '}' ), before.lastIndexOf( ';' ) );
		var stmt = before.slice( cut + 1 ).replace( /^\s+/, '' );
		return braceDepth( cm, pos ) > 0 && /^-*[a-z][a-z-]*\s*:/i.test( stmt ) ? 'value' : 'block';
	}
	// 'value' (a colour …), 'block' (declarations / rules), 'text' (not CSS: never in the CSS editor).
	function recipeKind( r ) { return r.kind || ( r.inline ? 'value' : 'block' ); }
	function expandRecipe( cm ) {
		var cur = cm.getCursor(), line = cm.getLine( cur.line ), before = line.slice( 0, cur.ch );
		var m = /\?([a-z0-9-]+)(;?)$/.exec( before ), r = m && RECIPE[ m[1] ];
		if ( ! r ) { return false; }
		var from = { line: cur.line, ch: cur.ch - m[0].length }, kind = recipeKind( r ), spot = recipeSpot( cm, from ), text, at = -1, sel = 0;
		if ( 'text' === kind || ( 'value' === spot && 'value' !== kind ) ) { return false; }
		if ( 'value' === kind ) {
			var v = String( r.code ).replace( '{{cursor}}', '' );
			if ( 'value' === spot ) {
				text = v + ( m[2] || ( /^\s*$/.test( line.slice( cur.ch ) ) ? ';' : '' ) );
			} else {
				var prop = 'Color' === r.c ? 'color' : 'property';
				text = prop + ': ' + v + ';';
				sel = prop.length; // the property is selected, type another one over it
			}
		} else {
			text = String( r.code ).replace( /\n/g, '\n' + /^\s*/.exec( line )[0] );
			at = text.indexOf( '{{cursor}}' );
			text = text.replace( '{{cursor}}', '' );
		}
		cm.replaceRange( text, from, cur, '+tfrecipe' );
		var start = cm.indexFromPos( from );
		if ( sel ) { cm.setSelection( cm.posFromIndex( start ), cm.posFromIndex( start + sel ) ); }
		else if ( at > -1 ) { cm.setCursor( cm.posFromIndex( start + at ) ); }
		return true;
	}

	function attach( cm ) {
		if ( ! cm || cm.__tfAc ) { return; }
		var mode = cm.getOption( 'mode' );
		mode = 'string' === typeof mode ? mode : ( mode && mode.name ) || '';
		if ( ! /css/i.test( mode ) ) { return; }
		cm.__tfAc = true;
		cm.on( 'inputRead', function ( c, change ) {
			if ( RECIPES.length && change && /;$/.test( change.text.join( '' ) ) && expandRecipe( c ) ) { closeAc(); return; }
			if ( change && '+input' === change.origin ) { setTimeout( function () { acSuggest( c ); }, 0 ); }
		} );
		cm.on( 'cursorActivity', function ( c ) {
			if ( ac.el && ac.el.classList.contains( 'is-open' ) && ac.cm === c ) { setTimeout( function () { acSuggest( c ); }, 0 ); }
		} );
		cm.on( 'blur', function () { setTimeout( closeAc, 120 ); } );
	}

	if ( cfg.css_ac || RECIPES.length ) {
		document.addEventListener( 'focusin', function ( e ) {
			var w = e.target && e.target.closest && e.target.closest( '.CodeMirror' );
			if ( w && w.CodeMirror ) { attach( w.CodeMirror ); }
		}, true );
	}
} )();
