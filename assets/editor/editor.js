/* TokenForge: block editor interface (class field, interface switches, canvas tools, hidden blocks). */
( function () {
	'use strict';
	var wp = window.wp || {};
	var cfg = window.TokenForgeEditor = window.TokenForgeEditor || {};
	var opts = cfg.opts || {};
	var hooks = wp.hooks;
	var __ = wp.i18n && wp.i18n.__ ? wp.i18n.__ : function ( s ) { return s; };
	var sprintf = wp.i18n && wp.i18n.sprintf ? wp.i18n.sprintf : function ( f, a ) { return f.replace( '%s', a ); };
	var uid = 0;

	function applyFilters( name, value ) { return hooks && hooks.applyFilters ? hooks.applyFilters( name, value ) : value; }
	function doAction( name ) { if ( hooks && hooks.doAction ) { hooks.doAction( name ); } }
	// A store or an action can be missing (another editor screen, an older WordPress): never throw.
	function store( kind, name ) {
		try { return ( wp.data && wp.data[ kind ] && wp.data[ kind ]( name ) ) || null; } catch ( e ) { return null; }
	}
	function make( tag, cls, text ) {
		var n = document.createElement( tag );
		if ( cls ) { n.className = cls; }
		if ( text ) { n.textContent = text; }
		return n;
	}

	// ---- Hidden blocks: out of the inserter, still registered, so existing content keeps working.
	// Added while the script loads: on DOM ready every editor passes the blocks registered before this
	// script through the filter again (reapplyBlockTypeFilters), then registers the core blocks.
	var hide = cfg.hide || [];
	if ( hide.length && hooks && hooks.addFilter ) {
		hooks.addFilter( 'blocks.registerBlockType', 'tokenforge/hide', function ( settings, name ) {
			if ( ! settings || -1 === hide.indexOf( name ) ) { return settings; }
			return Object.assign( {}, settings, { supports: Object.assign( {}, settings.supports, { inserter: false } ) } );
		} );
	}

	// ---- Canvas
	cfg.canvas = function () {
		var frame = document.querySelector( 'iframe[name="editor-canvas"]' );
		if ( ! frame ) { return document.querySelector( '.editor-styles-wrapper' ) ? document : null; }
		try { return frame.contentDocument || null; } catch ( e ) { return null; }
	};
	var canvasClasses = [ [ 'outlines', 'tf-outlines' ], [ 'gridOutlines', 'tf-grid-outlines' ], [ 'darkCanvas', 'tf-dark-canvas' ] ].filter( function ( c ) { return opts[ c[0] ]; } );
	function syncCanvas() {
		var doc = cfg.canvas();
		if ( ! doc || ! doc.body ) { return; }
		// No iframe: the canvas shares the admin page, so only its wrapper gets the classes.
		// In the iframe both html and body: the editor rewrites the body class list.
		var targets = doc === document ? [ doc.querySelector( '.editor-styles-wrapper' ) ] : [ doc.documentElement, doc.body ];
		targets.forEach( function ( t ) {
			if ( ! t ) { return; }
			canvasClasses.forEach( function ( c ) { t.classList.add( c[1] ); } );
		} );
	}

	// ---- Window button in the editor header
	function syncWindowButton() {
		var bar = document.querySelector( '.editor-header__settings' );
		if ( ! bar || bar.querySelector( '.tf-be-window' ) ) { return; }
		var b = make( 'button', 'components-button is-compact tf-be-window', 'TokenForge' );
		b.type = 'button';
		b.title = __( 'Open or close the TokenForge window', 'tokenforge' );
		b.addEventListener( 'click', function () {
			if ( window.TokenForgeWindow && window.TokenForgeWindow.toggle ) { window.TokenForgeWindow.toggle(); }
		} );
		bar.insertBefore( b, bar.firstChild );
	}

	// ---- Class field
	var refocus = false;
	function classesOf( block ) {
		var c = block && block.attributes && block.attributes.className;
		return 'string' === typeof c ? c.split( /\s+/ ).filter( Boolean ) : [];
	}
	function commit( block, list, said ) {
		var d = store( 'dispatch', 'core/block-editor' );
		if ( ! d || ! d.updateBlockAttributes ) { return; }
		refocus = true;
		// The chips change without a page reload: tell screen readers.
		if ( said && wp.a11y && wp.a11y.speak ) { wp.a11y.speak( said ); }
		// Like the core field: no classes means no attribute.
		d.updateBlockAttributes( block.clientId, { className: list.length ? list.join( ' ' ) : undefined } );
	}
	function removed( name ) {
		/* translators: %s: a CSS class name. */
		return sprintf( __( 'Removed %s', 'tokenforge' ), name );
	}
	function buildField( block, key ) {
		var id = 'tf-class-' + ( ++uid );
		var current = classesOf( block );
		var field = make( 'div', 'tf-class-field' );
		var label = make( 'label', 'tf-class-label', __( 'Classes', 'tokenforge' ) );
		var box = make( 'div', 'tf-class-box' );
		var chips = make( 'ul', 'tf-class-chips' );
		var input = make( 'input', 'tf-class-input' );
		var list = make( 'ul', 'tf-class-list' );
		var shown = [], active = -1;

		field.setAttribute( 'data-tf-key', key );
		label.htmlFor = id;
		current.forEach( function ( name ) {
			var chip = make( 'li', 'tf-class-chip' );
			var x = make( 'button', 'tf-class-chip-x', '×' );
			chip.appendChild( make( 'span', '', name ) );
			x.type = 'button';
			/* translators: %s: a CSS class name. */
			x.setAttribute( 'aria-label', sprintf( __( 'Remove %s', 'tokenforge' ), name ) );
			x.addEventListener( 'click', function () {
				commit( block, current.filter( function ( c ) { return c !== name; } ), removed( name ) );
			} );
			chip.appendChild( x );
			chips.appendChild( chip );
		} );

		input.type = 'text';
		input.id = id;
		input.autocomplete = 'off';
		input.spellcheck = false;
		input.placeholder = __( 'Add a class', 'tokenforge' );
		input.setAttribute( 'role', 'combobox' );
		input.setAttribute( 'aria-autocomplete', 'list' );
		input.setAttribute( 'aria-expanded', 'false' );
		input.setAttribute( 'aria-controls', id + '-list' );
		list.id = id + '-list';
		list.setAttribute( 'role', 'listbox' );
		list.setAttribute( 'aria-label', __( 'Class suggestions', 'tokenforge' ) );
		list.hidden = true;

		function highlight( i ) {
			active = i;
			Array.prototype.forEach.call( list.children, function ( li, n ) {
				li.setAttribute( 'aria-selected', n === i ? 'true' : 'false' );
				if ( n === i && li.scrollIntoView ) { li.scrollIntoView( { block: 'nearest' } ); }
			} );
			if ( i >= 0 && list.children[ i ] ) {
				input.setAttribute( 'aria-activedescendant', list.children[ i ].id );
			} else {
				input.removeAttribute( 'aria-activedescendant' );
			}
		}
		function close() {
			shown = [];
			list.textContent = '';
			list.hidden = true;
			input.setAttribute( 'aria-expanded', 'false' );
			highlight( -1 );
		}
		function suggest() {
			var q = input.value.trim().toLowerCase();
			var rows = applyFilters( 'tokenforge.editor.classes', cfg.classes || [] );
			var first = [], rest = [];
			close();
			if ( ! q || ! Array.isArray( rows ) ) { return; }
			rows.forEach( function ( row ) {
				var name = Array.isArray( row ) ? String( row[0] || '' ) : '';
				var at = name.toLowerCase().indexOf( q );
				if ( ! name || -1 === at || -1 !== current.indexOf( name ) ) { return; }
				// The class that was typed in full comes first.
				( 0 === at ? first : rest )[ name.length === q.length ? 'unshift' : 'push' ]( [ name, String( row[1] || '' ) ] );
			} );
			shown = first.concat( rest ).slice( 0, 30 );
			if ( ! shown.length ) { return; }
			shown.forEach( function ( row, i ) {
				var li = make( 'li', 'tf-class-option' );
				li.id = id + '-o' + i;
				li.setAttribute( 'role', 'option' );
				li.appendChild( make( 'span', 'tf-class-name', row[0] ) );
				if ( row[1] ) { li.appendChild( make( 'span', 'tf-class-preview', ' ' + row[1] ) ); }
				// mousedown, not click: the input must not lose focus first.
				li.addEventListener( 'mousedown', function ( ev ) { ev.preventDefault(); add( row[0] ); } );
				list.appendChild( li );
			} );
			list.hidden = false;
			// Nothing is highlighted until an arrow key: Enter adds what was typed, not a longer match.
			input.setAttribute( 'aria-expanded', 'true' );
		}
		function add( text ) {
			var next = current.slice();
			String( text ).split( /\s+/ ).forEach( function ( c ) {
				if ( c && -1 === next.indexOf( c ) ) { next.push( c ); }
			} );
			input.value = '';
			close();
			/* translators: %s: one or more CSS class names. */
			if ( next.length !== current.length ) { commit( block, next, sprintf( __( 'Added %s', 'tokenforge' ), next.slice( current.length ).join( ' ' ) ) ); }
		}

		input.addEventListener( 'input', suggest );
		input.addEventListener( 'blur', close );
		input.addEventListener( 'keydown', function ( ev ) {
			var open = shown.length > 0, pick;
			if ( 'ArrowDown' === ev.key || 'ArrowUp' === ev.key ) {
				if ( ! open ) { return; }
				ev.preventDefault();
				highlight( 'ArrowDown' === ev.key ? ( active + 1 ) % shown.length : ( active > 0 ? active : shown.length ) - 1 );
			} else if ( 'Enter' === ev.key || ( 'Tab' === ev.key && open && ! ev.shiftKey ) ) {
				if ( open || input.value.trim() ) {
					ev.preventDefault();
					// Tab completes: with no highlight it takes the first match.
					pick = shown[ 'Tab' === ev.key ? Math.max( active, 0 ) : active ];
					add( pick ? pick[0] : input.value );
				}
			} else if ( 'Escape' === ev.key ) {
				if ( open ) {
					// Only the list closes: the editor must not also act on this Escape.
					ev.preventDefault();
					ev.stopPropagation();
					close();
				}
			} else if ( 'Backspace' === ev.key && ! input.value && current.length ) {
				commit( block, current.slice( 0, -1 ), removed( current[ current.length - 1 ] ) );
			}
		} );

		box.appendChild( chips );
		box.appendChild( input );
		field.appendChild( label );
		field.appendChild( box );
		field.appendChild( list );
		return field;
	}
	function syncClassField() {
		var host = document.querySelector( '.block-editor-block-inspector' );
		var old = document.querySelector( '.tf-class-field' );
		var sel = store( 'select', 'core/block-editor' );
		var block = null, cards, card, key, field;
		if ( host && sel && sel.getSelectedBlock && ! ( sel.getMultiSelectedBlockClientIds && sel.getMultiSelectedBlockClientIds().length ) ) {
			block = sel.getSelectedBlock();
		}
		if ( block && wp.blocks && wp.blocks.hasBlockSupport && ! wp.blocks.hasBlockSupport( block.name, 'customClassName', true ) ) {
			block = null;
		}
		cards = block ? host.querySelectorAll( '.block-editor-block-card' ) : [];
		// A child block shows its parent's card first: ours is the last one.
		card = cards.length ? cards[ cards.length - 1 ] : null;
		if ( ! card ) {
			if ( old ) { old.remove(); }
			return;
		}
		key = block.clientId + '|' + classesOf( block ).join( ' ' );
		if ( old && old.getAttribute( 'data-tf-key' ) === key && old.previousElementSibling === card ) { return; }
		if ( old ) { old.remove(); }
		field = buildField( block, key );
		card.parentNode.insertBefore( field, card.nextSibling );
		if ( refocus ) { field.querySelector( '.tf-class-input' ).focus(); }
		refocus = false;
	}

	// ---- Open panels and the Styles tab, once per selected block (the user can change both afterwards)
	var lastId = null;
	function openPanels() {
		Array.prototype.forEach.call( document.querySelectorAll( '.block-editor-block-inspector .components-panel__body:not(.is-opened) > .components-panel__body-title > .components-panel__body-toggle' ), function ( b ) { b.click(); } );
	}
	function onSelection() {
		var sel = store( 'select', 'core/block-editor' );
		var id = sel && sel.getSelectedBlockClientId ? sel.getSelectedBlockClientId() : null;
		var done = false;
		if ( id === lastId ) { return; }
		lastId = id;
		if ( ! id ) { return; }
		// The inspector is drawn after the selection changes; the second pass is for a slow one only.
		[ 80, 500 ].forEach( function ( ms ) {
			setTimeout( function () {
				var tab;
				if ( done || lastId !== id ) { return; }
				if ( ! document.querySelector( '.block-editor-block-inspector__tabs, .block-editor-block-inspector .components-panel__body' ) ) { return; }
				done = true;
				tab = opts.stylesFirst && document.querySelector( '.block-editor-block-inspector__tabs [role="tab"][id$="-styles"]' );
				if ( tab && 'true' !== tab.getAttribute( 'aria-selected' ) ) { tab.click(); }
				if ( opts.openPanels ) { setTimeout( openPanels, 30 ); }
			}, ms );
		} );
	}

	// ---- Resizable sidebars: a handle on the inner edge of each sidebar (drag, or arrow keys when focused).
	// Widths are kept per browser; double-click goes back to the editor's own width.
	var SIDES = [
		[ 'side', '.interface-interface-skeleton__sidebar', __( 'Resize the block settings sidebar', 'tokenforge' ) ],
		[ 'list', '.interface-interface-skeleton__secondary-sidebar', __( 'Resize the List View and inserter sidebar', 'tokenforge' ) ]
	];
	var MIN_W = 220, MAX_W = 720;
	function setWidth( id, w, save ) {
		var b = document.body;
		if ( null === w ) {
			b.style.removeProperty( '--tf-be-' + id + '-w' );
			b.classList.remove( 'tf-be-sized-' + id );
		} else {
			// At most 40% of the window, so two wide sidebars always leave room for the canvas (also for a width kept from a bigger screen).
			w = Math.max( MIN_W, Math.min( MAX_W, Math.round( window.innerWidth * 0.4 ), Math.round( w ) ) );
			b.style.setProperty( '--tf-be-' + id + '-w', w + 'px' );
			b.classList.add( 'tf-be-sized-' + id );
		}
		if ( save ) {
			try {
				if ( null === w ) { window.localStorage.removeItem( 'tokenforge-be-' + id + '-w' ); } else { window.localStorage.setItem( 'tokenforge-be-' + id + '-w', String( w ) ); }
			} catch ( e ) {} // Storage blocked: the width lasts until the page reloads.
		}
		return w;
	}
	// The sidebar's panel (its width is what we change), or null while the sidebar is closed.
	function sidePanel( region ) {
		return region.querySelector( '.interface-complementary-area, .editor-list-view-sidebar, .editor-inserter-sidebar' );
	}
	function syncResizers() {
		SIDES.forEach( function ( s ) {
			var region = document.querySelector( s[1] ), h, panel;
			if ( ! region ) { return; }
			h = region.querySelector( ':scope > .tf-be-resizer' );
			panel = sidePanel( region );
			if ( ! panel ) { if ( h ) { h.remove(); } return; }
			if ( h ) { h.setAttribute( 'aria-valuenow', String( panel.offsetWidth ) ); return; }
			h = make( 'div', 'tf-be-resizer' );
			h.setAttribute( 'role', 'separator' );
			h.setAttribute( 'aria-orientation', 'vertical' );
			h.setAttribute( 'aria-label', s[2] );
			h.setAttribute( 'aria-valuemin', String( MIN_W ) );
			h.setAttribute( 'aria-valuemax', String( MAX_W ) );
			h.setAttribute( 'aria-valuenow', String( panel.offsetWidth ) );
			h.tabIndex = 0;
			h.title = s[2];
			// The handle is on the edge next to the canvas: dragging toward the canvas widens.
			var onRight = function () { var r = region.getBoundingClientRect(); return r.left + r.width / 2 > window.innerWidth / 2; };
			h.addEventListener( 'pointerdown', function ( ev ) {
				var p = sidePanel( region ), right = onRight(), startX = ev.clientX, startW = p ? p.offsetWidth : 0, last = startW;
				if ( ! p || 0 !== ev.button ) { return; }
				ev.preventDefault();
				h.setPointerCapture( ev.pointerId );
				document.body.classList.add( 'tf-be-resizing' );
				var move = function ( e ) { last = setWidth( s[0], startW + ( right ? startX - e.clientX : e.clientX - startX ), false ); h.setAttribute( 'aria-valuenow', String( last ) ); };
				// lostpointercapture follows pointerup and pointercancel, and also fires when the handle is removed mid-drag
				// (the sidebar closed or redrawn): the canvas never stays unclickable.
				var up = function () {
					h.removeEventListener( 'pointermove', move );
					h.removeEventListener( 'lostpointercapture', up );
					document.body.classList.remove( 'tf-be-resizing' );
					if ( last !== startW ) { setWidth( s[0], last, true ); }
				};
				h.addEventListener( 'pointermove', move );
				h.addEventListener( 'lostpointercapture', up );
			} );
			h.addEventListener( 'keydown', function ( ev ) {
				var p = sidePanel( region ), step = ev.shiftKey ? 64 : 16, grow = onRight() ? 'ArrowLeft' : 'ArrowRight', shrink = onRight() ? 'ArrowRight' : 'ArrowLeft', w;
				if ( ! p || ( ev.key !== grow && ev.key !== shrink ) ) { return; }
				ev.preventDefault();
				w = setWidth( s[0], p.offsetWidth + ( ev.key === grow ? step : -step ), true );
				h.setAttribute( 'aria-valuenow', String( w ) );
			} );
			h.addEventListener( 'dblclick', function () { setWidth( s[0], null, true ); } );
			region.appendChild( h );
		} );
	}

	// ---- Text recipes in the canvas: "?ipsum;" or "?ipsum " (the space stays) becomes the placeholder copy.
	var TEXT = cfg.text || {};
	var hasText = Object.keys( TEXT ).length > 0;
	function syncRecipes() {
		var doc = cfg.canvas();
		if ( ! doc || doc.__tfRecipes ) { return; }
		doc.__tfRecipes = true;
		doc.addEventListener( 'input', function ( e ) {
			var el = e.target, sel, rng, node, before, m, r;
			if ( ! el || ! ( el.isContentEditable || ( el.closest && el.closest( '[contenteditable="true"]' ) ) ) ) { return; }
			// No iframe: the canvas shares the admin page, so only text inside it counts.
			if ( doc === document && ! el.closest( '.editor-styles-wrapper' ) ) { return; }
			sel = doc.getSelection();
			if ( ! sel || ! sel.rangeCount || ! sel.isCollapsed ) { return; }
			rng = sel.getRangeAt( 0 );
			node = rng.startContainer;
			if ( ! node || 3 !== node.nodeType ) { return; }
			before = node.data.slice( 0, rng.startOffset );
			m = /\?([a-z0-9-]+)(;|[  ])$/.exec( before );
			if ( ! m || ! Object.prototype.hasOwnProperty.call( TEXT, m[1] ) ) { return; }
			r = doc.createRange();
			r.setStart( node, rng.startOffset - m[0].length );
			r.setEnd( node, rng.startOffset );
			// After the editor has read this keystroke: then typed in as text, which the editor reads as a normal edit.
			setTimeout( function () {
				var be = store( 'dispatch', 'core/block-editor' );
				if ( ! r.startContainer.isConnected || r.toString() !== m[0] ) { return; }
				// The typed "?name " becomes its own undo step first, as the editor's own text shortcuts do.
				if ( be && 'function' === typeof be.__unstableMarkLastChangeAsPersistent ) { be.__unstableMarkLastChangeAsPersistent(); }
				sel.removeAllRanges();
				sel.addRange( r );
				doc.execCommand( 'insertText', false, TEXT[ m[1] ] + ( ';' === m[2] ? '' : ' ' ) );
				// Like the editor's own shortcuts: Backspace right after undoes the expansion. Marked once the editor has
				// stored the inserted text (the next tick); missing in an older editor: skipped.
				setTimeout( function () {
					if ( be && 'function' === typeof be.__unstableMarkAutomaticChange ) { be.__unstableMarkAutomaticChange(); }
				}, 0 );
			}, 0 );
		}, true );
	}

	function sync() {
		try {
			if ( opts.resizePanels ) { syncResizers(); }
			if ( hasText ) { syncRecipes(); }
			if ( opts.classField ) { syncClassField(); }
			if ( opts.window ) { syncWindowButton(); }
			if ( canvasClasses.length ) { syncCanvas(); }
			if ( opts.openPanels || opts.stylesFirst ) { onSelection(); }
		} catch ( e ) {} // The editor redraws under us; the next pass repairs it.
	}

	function init() {
		var pending = false, listView;
		[ [ 'compact', 'tf-be-compact' ], [ 'darkUi', 'tf-be-dark' ], [ 'inserterList', 'tf-be-inserter-list' ], [ 'swapPanels', 'tf-be-swap' ], [ 'resizePanels', 'tf-be-resize' ] ].forEach( function ( c ) {
			if ( opts[ c[0] ] ) { document.body.classList.add( c[1] ); }
		} );
		if ( opts.resizePanels ) {
			SIDES.forEach( function ( s ) {
				var w = null;
				try { w = parseInt( window.localStorage.getItem( 'tokenforge-be-' + s[0] + '-w' ), 10 ); } catch ( e ) {}
				if ( w > 0 ) { setWidth( s[0], w, false ); }
			} );
		}
		if ( opts.openPanels ) {
			// Another inspector tab shows other panels: open those too.
			document.addEventListener( 'click', function ( ev ) {
				if ( ev.target && ev.target.closest && ev.target.closest( '.block-editor-block-inspector__tabs [role="tab"]' ) ) { setTimeout( openPanels, 80 ); }
			} );
		}
		if ( opts.listView ) {
			// After the editor has started (it starts on DOM ready too, after this script).
			setTimeout( function () {
				try {
					listView = store( 'dispatch', 'core/editor' );
					if ( listView && listView.setIsListViewOpened ) { listView.setIsListViewOpened( true ); }
				} catch ( e ) {}
			}, 0 );
		}
		if ( opts.classField || opts.window || canvasClasses.length || opts.openPanels || opts.stylesFirst || opts.resizePanels || hasText ) {
			if ( wp.data && wp.data.subscribe ) {
				wp.data.subscribe( function () {
					if ( pending ) { return; }
					pending = true;
					setTimeout( function () { pending = false; sync(); }, 0 );
				} );
			}
			// shortcut: a slow timer catches what no store change announces (a reloaded canvas iframe, a redrawn header or sidebar); upgrade to observers if it ever shows as a delay.
			setInterval( sync, 500 );
			sync();
		}
		doAction( 'tokenforge.editor.ready' );
	}

	if ( wp.domReady ) {
		wp.domReady( init );
	} else if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );
