/* TokenForge: suggestions for the builder's own Attributes control.
 *
 * - Type in an attribute Name box: the TokenForge attributes that match, each with
 *   a plain-words description. Pick one and the focus moves to the Value box, which
 *   lists the values that attribute accepts (clamp 1 to 6, the ratios, the states).
 *   Arrow keys move, Enter picks, Escape closes (the list is a listbox for screen readers).
 * - Right-click inside the Attributes control (not on a Value box, that keeps the
 *   variable menu) -> "Add attribute": pick the attribute, then its value; it is added
 *   to the selected element the way the builder's own "add" does, so undo works.
 * Shift + right-click always opens the browser's own menu.
 *
 * The data and the strings come from PHP (window.TokenForgeAttrs), so the attribute
 * list has one source: TokenForge\Attributes::all().
 * Builder facts (Bricks 2.4.3): the control is div[data-controlkey="_attributes"] >
 * div[data-control=repeater] > li.repeater-item > div.repeater-item-inner[data-control-key=name|value]
 * > ... > input; settings._attributes is an array of { id, name, value }.
 */
( function () {
	'use strict';
	if ( window.__tokenforgeAttrs ) { return; } // load once
	window.__tokenforgeAttrs = true;

	var cfg = window.TokenForgeAttrs || {};
	var ATTRS = cfg.attrs || []; // [ { name, values: [], desc }, ... ]
	var DEF = {
		add: 'Add attribute', search: 'Search attributes...', noMatch: 'No matches',
		valueFor: 'Value for %s', listName: 'Attribute suggestions', listValue: 'Attribute value suggestions',
		count: '%d suggestions', added: 'Added %s', noElement: 'Select an element first.',
		hintAc: 'Up/Down move, Enter pick, Esc close', hintMenu: 'Enter pick, Esc back, Shift+right-click: browser menu'
	};
	function tx( key, arg ) {
		var s = ( cfg.i18n && cfg.i18n[ key ] ) || DEF[ key ] || key;
		return arg === undefined ? s : s.replace( /%[sd]/, arg );
	}

	// ---- Builder store (same entry point as context-menu.js) ----
	function unref( v ) { return ( v && v.__v_isRef ) ? v.value : v; }
	function gp() { var r = document.querySelector( 'div.brx-body.main' ); return r && r.__vue_app__ ? r.__vue_app__.config.globalProperties : null; }
	function rid() { var s = ''; while ( s.length < 6 ) { s += Math.random().toString( 36 ).slice( 2 ); } return s.slice( 0, 6 ); }

	// Add name=value to the selected element's Attributes control. Mutating the element in the
	// builder's own store is what its controls do, so the history watcher records it (undo).
	function add( name, value ) {
		var g = gp(), node = g && unref( g.$_activeElement );
		if ( ! node || ! node.id ) { return false; }
		// An element with no settings can carry an empty array instead of an object.
		if ( ! node.settings || Array.isArray( node.settings ) ) { node.settings = {}; }
		if ( ! Array.isArray( node.settings._attributes ) ) { node.settings._attributes = []; }
		var arr = node.settings._attributes, hit = null;
		for ( var i = 0; i < arr.length; i++ ) { if ( arr[ i ] && arr[ i ].name === name ) { hit = arr[ i ]; } }
		if ( hit ) { hit.value = value; } else { arr.push( { id: typeof g.$_generateId === 'function' ? g.$_generateId() : rid(), name: name, value: value } ); }
		try { g.$_forceRenderElementSubtree && g.$_forceRenderElementSubtree( node.id ); } catch ( e ) {}
		return true;
	}

	// ---- Matching ----
	function norm( s ) { return String( s || '' ).trim().toLowerCase(); }
	function find( name ) {
		name = norm( name );
		for ( var i = 0; i < ATTRS.length; i++ ) { if ( ATTRS[ i ].name === name ) { return ATTRS[ i ]; } }
		return null;
	}
	// Starts-with first, then contains; shorter first. Items: { pick, desc }.
	function rank( items, q ) {
		q = norm( q );
		var score = function ( s ) { return s === q ? 0 : ( 0 === s.indexOf( q ) ? 1 : 2 ); };
		return items.filter( function ( it ) { return ! q || it.pick.toLowerCase().indexOf( q ) > -1; } )
			.sort( function ( a, b ) { return ( score( a.pick.toLowerCase() ) - score( b.pick.toLowerCase() ) ) || ( a.pick.length - b.pick.length ); } );
	}
	function nameItems( q ) {
		return rank( ATTRS.map( function ( a ) { return { pick: a.name, desc: a.desc || '' }; } ), q );
	}
	function valueItems( name, q ) {
		var a = find( name );
		return a ? rank( ( a.values || [] ).map( function ( v ) { return { pick: String( v ), desc: '' }; } ), q ) : [];
	}

	// ---- Shared bits ----
	function el( tag, cls, text ) {
		var e = document.createElement( tag );
		if ( cls ) { e.className = cls; }
		if ( text ) { e.textContent = text; }
		return e;
	}
	var live = null;
	function announce( msg ) {
		if ( ! live ) { live = el( 'div', 'tf-attr-live' ); live.setAttribute( 'role', 'status' ); live.setAttribute( 'aria-live', 'polite' ); document.body.appendChild( live ); }
		// Fill after a delay so the freshly inserted live region is already registered.
		live.textContent = '';
		setTimeout( function () { live.textContent = msg; }, 60 );
	}
	// Rows as role=option (built with textContent, never innerHTML).
	function renderRows( ul, items, sel, prefix ) {
		while ( ul.firstChild ) { ul.removeChild( ul.firstChild ); }
		items.forEach( function ( it, i ) {
			var li = el( 'li' );
			li.id = prefix + i;
			li.setAttribute( 'role', 'option' );
			li.setAttribute( 'data-i', String( i ) );
			li.setAttribute( 'aria-selected', i === sel ? 'true' : 'false' );
			if ( i === sel ) { li.className = 'is-sel'; }
			li.appendChild( el( 'span', 'tf-attr-name', it.pick ) );
			if ( it.desc ) { li.appendChild( el( 'span', 'tf-attr-desc', it.desc ) ); }
			ul.appendChild( li );
		} );
		var cur = ul.children && ul.children[ sel ];
		if ( cur && cur.scrollIntoView ) { cur.scrollIntoView( { block: 'nearest' } ); }
	}
	function setValue( input, v ) {
		var proto = window.HTMLInputElement && window.HTMLInputElement.prototype, d = proto && Object.getOwnPropertyDescriptor( proto, 'value' );
		if ( d && d.set ) { d.set.call( input, v ); } else { input.value = v; }
		input.dispatchEvent( new Event( 'input', { bubbles: true } ) ); // the builder's text control saves on input
		input.dispatchEvent( new Event( 'change', { bubbles: true } ) );
		try { input.setSelectionRange( v.length, v.length ); } catch ( e ) {}
	}
	function place( box, anchor, width ) {
		var r = anchor.getBoundingClientRect();
		box.style.width = width + 'px';
		var h = box.getBoundingClientRect().height, w = width;
		box.style.left = Math.max( 8, Math.min( r.left, window.innerWidth - w - 8 ) ) + 'px';
		box.style.top = ( r.bottom + 2 + h > window.innerHeight ? Math.max( 8, r.top - h - 2 ) : r.bottom + 2 ) + 'px';
	}

	// =====================================================================
	// Inline suggestions in the Name and Value boxes
	// =====================================================================
	var ac = { box: null, ul: null, input: null, kind: '', items: [], sel: 0, open: false };

	function kindOf( input ) {
		if ( ! input || 'INPUT' !== input.tagName || ! input.closest || ! input.closest( '[data-controlkey="_attributes"]' ) ) { return ''; }
		var f = input.closest( '[data-control-key]' ), k = f && f.getAttribute( 'data-control-key' );
		return 'name' === k || 'value' === k ? k : '';
	}
	function sibling( input, key ) {
		var row = input.closest( 'li.repeater-item' );
		return row ? row.querySelector( '[data-control-key="' + key + '"] input' ) : null;
	}

	function acBuild() {
		ac.box = el( 'div' );
		ac.box.id = 'tf-attr-ac';
		ac.ul = el( 'ul' );
		ac.ul.id = 'tf-attr-list';
		ac.ul.setAttribute( 'role', 'listbox' );
		var foot = el( 'div', 'tf-attr-foot', tx( 'hintAc' ) );
		foot.setAttribute( 'aria-hidden', 'true' );
		ac.box.appendChild( ac.ul );
		ac.box.appendChild( foot );
		document.body.appendChild( ac.box );
		ac.box.addEventListener( 'mousedown', function ( e ) { e.preventDefault(); } ); // keep focus in the box
		ac.box.addEventListener( 'click', function ( e ) {
			var li = e.target.closest( 'li[data-i]' );
			if ( li ) { ac.sel = parseInt( li.getAttribute( 'data-i' ), 10 ); acPick(); }
		} );
	}

	function acHide() {
		if ( ac.box ) { ac.box.classList.remove( 'is-open' ); }
		ac.open = false;
		if ( ac.input ) {
			ac.input.setAttribute( 'aria-expanded', 'false' );
			ac.input.removeAttribute( 'aria-activedescendant' );
		}
	}

	// force: show even when the only suggestion is exactly what is typed (ArrowDown).
	function acRefresh( force ) {
		var input = ac.input;
		if ( ! input || ! input.isConnected ) { acHide(); return; }
		var q = input.value;
		if ( 'name' === ac.kind ) {
			ac.items = nameItems( q );
		} else {
			var n = sibling( input, 'name' );
			ac.items = valueItems( n ? n.value : '', q );
		}
		if ( ! ac.items.length || ( ! force && 1 === ac.items.length && norm( ac.items[ 0 ].pick ) === norm( q ) ) ) { acHide(); return; }
		if ( ! ac.box ) { acBuild(); }
		ac.sel = Math.min( ac.sel, ac.items.length - 1 );
		ac.ul.setAttribute( 'aria-label', tx( 'name' === ac.kind ? 'listName' : 'listValue' ) );
		renderRows( ac.ul, ac.items, ac.sel, 'tf-attr-opt-' );
		var was = ac.open;
		ac.box.classList.add( 'is-open' );
		ac.open = true;
		place( ac.box, input, Math.max( input.getBoundingClientRect().width, 300 ) );
		input.setAttribute( 'aria-expanded', 'true' );
		input.setAttribute( 'aria-controls', 'tf-attr-list' );
		input.setAttribute( 'aria-activedescendant', 'tf-attr-opt-' + ac.sel );
		if ( ! was ) { announce( tx( 'count', ac.items.length ) ); }
	}

	function acMove( d ) {
		ac.sel = ( ac.sel + d + ac.items.length ) % ac.items.length;
		renderRows( ac.ul, ac.items, ac.sel, 'tf-attr-opt-' );
		ac.input.setAttribute( 'aria-activedescendant', 'tf-attr-opt-' + ac.sel );
	}

	function acPick() {
		var it = ac.items[ ac.sel ], input = ac.input, kind = ac.kind;
		if ( ! it || ! input ) { return; }
		setValue( input, it.pick );
		acHide();
		if ( 'name' === kind ) {
			var v = sibling( input, 'value' );
			if ( v ) { v.focus(); } // its focus handler lists the values
		}
	}

	document.addEventListener( 'focusin', function ( e ) {
		var k = kindOf( e.target );
		if ( ! k ) { return; }
		var input = e.target;
		ac.input = input; ac.kind = k; ac.sel = 0;
		input.setAttribute( 'role', 'combobox' );
		input.setAttribute( 'aria-autocomplete', 'list' );
		input.setAttribute( 'aria-haspopup', 'listbox' );
		input.setAttribute( 'aria-expanded', 'false' );
		acRefresh( false );
	} );
	document.addEventListener( 'focusout', function ( e ) {
		var input = e.target;
		// Later: a click on a row must land first; a hop to the Value box has taken over by then.
		setTimeout( function () { if ( ac.input === input ) { acHide(); ac.input = null; } }, 120 );
	} );
	document.addEventListener( 'input', function ( e ) {
		if ( e.target === ac.input ) { ac.sel = 0; acRefresh( false ); }
	} );
	// Capture: before the builder's own key handlers on the box.
	document.addEventListener( 'keydown', function ( e ) {
		if ( e.target !== ac.input || e.isComposing ) { return; }
		var k = e.key;
		if ( ! ac.open ) {
			if ( 'ArrowDown' === k ) { e.preventDefault(); e.stopPropagation(); ac.sel = 0; acRefresh( true ); }
			return;
		}
		if ( 'ArrowDown' === k ) { e.preventDefault(); e.stopPropagation(); acMove( 1 ); }
		else if ( 'ArrowUp' === k ) { e.preventDefault(); e.stopPropagation(); acMove( -1 ); }
		else if ( 'Enter' === k ) { e.preventDefault(); e.stopPropagation(); acPick(); }
		else if ( 'Escape' === k ) { e.preventDefault(); e.stopPropagation(); acHide(); }
	}, true );
	document.addEventListener( 'scroll', function ( e ) {
		if ( ac.open && ! ( e.target && e.target.closest && e.target.closest( '#tf-attr-ac' ) ) ) { acHide(); }
	}, true );

	// =====================================================================
	// Right-click > Add attribute
	// =====================================================================
	var m = { box: null, head: null, input: null, ul: null, stage: 'names', attr: null, items: [], sel: 0, from: null, open: false };

	function mBuild() {
		m.box = el( 'div' );
		m.box.id = 'tf-attr-menu';
		m.box.setAttribute( 'role', 'dialog' );
		m.box.setAttribute( 'aria-label', tx( 'add' ) );
		m.head = el( 'div', 'tf-attr-head', tx( 'add' ) );
		m.input = el( 'input', 'tf-attr-search' );
		m.input.type = 'text';
		m.input.setAttribute( 'autocomplete', 'off' );
		m.input.setAttribute( 'spellcheck', 'false' );
		m.input.setAttribute( 'role', 'combobox' );
		m.input.setAttribute( 'aria-autocomplete', 'list' );
		m.input.setAttribute( 'aria-expanded', 'true' );
		m.input.setAttribute( 'aria-controls', 'tf-attr-mlist' );
		m.ul = el( 'ul' );
		m.ul.id = 'tf-attr-mlist';
		m.ul.setAttribute( 'role', 'listbox' );
		var foot = el( 'div', 'tf-attr-foot', tx( 'hintMenu' ) );
		foot.setAttribute( 'aria-hidden', 'true' );
		[ m.head, m.input, m.ul, foot ].forEach( function ( n ) { m.box.appendChild( n ); } );
		document.body.appendChild( m.box );

		m.input.addEventListener( 'input', function () { m.sel = 0; mRender(); } );
		m.input.addEventListener( 'keydown', function ( e ) {
			var k = e.key;
			if ( 'ArrowDown' === k || 'ArrowUp' === k ) { e.preventDefault(); if ( m.items.length ) { m.sel = ( m.sel + ( 'ArrowDown' === k ? 1 : -1 ) + m.items.length ) % m.items.length; mRender(); } }
			else if ( 'Enter' === k ) { e.preventDefault(); mPick(); }
			else if ( 'Escape' === k ) {
				e.preventDefault(); e.stopPropagation();
				if ( 'values' === m.stage ) { m.stage = 'names'; m.attr = null; m.sel = 0; m.input.value = ''; mRender(); } else { mHide( true ); }
			}
		} );
		m.input.addEventListener( 'blur', function () { setTimeout( function () { if ( m.open && document.activeElement !== m.input ) { mHide( false ); } }, 120 ); } );
		m.ul.addEventListener( 'mousedown', function ( e ) { e.preventDefault(); } );
		m.ul.addEventListener( 'click', function ( e ) {
			var li = e.target.closest( 'li[data-i]' );
			if ( li ) { m.sel = parseInt( li.getAttribute( 'data-i' ), 10 ); mPick(); }
		} );
		document.addEventListener( 'mousedown', function ( e ) { if ( m.open && ! ( e.target.closest && e.target.closest( '#tf-attr-menu' ) ) ) { mHide( false ); } }, true );
		window.addEventListener( 'resize', function () { mHide( false ); } );
	}

	function mRender() {
		var q = m.input.value, names = 'names' === m.stage;
		m.items = names ? nameItems( q ) : valueItems( m.attr.name, q );
		m.sel = Math.min( m.sel, Math.max( 0, m.items.length - 1 ) );
		m.head.textContent = names ? tx( 'add' ) : tx( 'valueFor', m.attr.name );
		m.input.placeholder = names ? tx( 'search' ) : '';
		m.input.setAttribute( 'aria-label', m.head.textContent );
		if ( m.items.length ) {
			renderRows( m.ul, m.items, m.sel, 'tf-attr-mopt-' );
			m.input.setAttribute( 'aria-activedescendant', 'tf-attr-mopt-' + m.sel );
		} else {
			while ( m.ul.firstChild ) { m.ul.removeChild( m.ul.firstChild ); }
			var none = el( 'li', 'tf-attr-empty', tx( 'noMatch' ) );
			none.setAttribute( 'role', 'presentation' );
			m.ul.appendChild( none );
			m.input.removeAttribute( 'aria-activedescendant' );
		}
	}

	function mShow( x, y, from ) {
		if ( ! m.box ) { mBuild(); }
		m.stage = 'names'; m.attr = null; m.sel = 0; m.from = from; m.input.value = '';
		mRender();
		m.box.classList.add( 'is-open' );
		m.open = true;
		var r = m.box.getBoundingClientRect();
		m.box.style.left = Math.max( 8, Math.min( x, window.innerWidth - r.width - 8 ) ) + 'px';
		m.box.style.top = Math.max( 8, Math.min( y, window.innerHeight - r.height - 8 ) ) + 'px';
		m.input.focus();
	}

	function mHide( refocus ) {
		if ( ! m.box || ! m.open ) { return; }
		m.box.classList.remove( 'is-open' );
		m.open = false;
		if ( refocus && m.from && m.from.isConnected ) { try { m.from.focus(); } catch ( e ) {} }
	}

	function mPick() {
		var it = m.items[ m.sel ];
		if ( ! it ) { return; }
		if ( 'names' === m.stage ) {
			var a = find( it.pick );
			if ( a && a.values && a.values.length ) { m.stage = 'values'; m.attr = a; m.sel = 0; m.input.value = ''; mRender(); return; }
			finish( it.pick, '' );
		} else {
			finish( m.attr.name, it.pick );
		}
	}
	function finish( name, value ) {
		var ok = add( name, value );
		mHide( true );
		announce( ok ? tx( 'added', name + ( value ? '="' + value + '"' : '' ) ) : tx( 'noElement' ) );
	}

	// Window capture runs before context-menu.js (document capture), so this wins on the Attributes control.
	window.addEventListener( 'contextmenu', function ( e ) {
		if ( e.shiftKey || ! ATTRS.length || ! cfg.menu ) { return; }
		var t = e.target;
		if ( ! t || ! t.closest || ! t.closest( '[data-controlkey="_attributes"]' ) ) { return; }
		if ( 'INPUT' === t.tagName && 'value' === kindOf( t ) ) { return; } // the variable menu stays on Value boxes
		e.preventDefault();
		e.stopPropagation();
		mShow( e.clientX, e.clientY, t );
	}, true );

} )();
