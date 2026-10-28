/* TokenForge BEM generator for the Bricks builder.
 *
 * Hover a Structure row to reveal a "BEM" affordance; click it to open a dialog
 * that reads the element's subtree, derives block__element class names from each
 * element's label, and applies them as real Bricks global classes on Apply.
 *
 * Uses the Bricks builder's own Vue store (discovered API, Bricks 2.x):
 *   app.config.globalProperties.$_state.content   (flat element array)
 *   app.config.globalProperties.$_globalClasses (global class list; the same list as $_state.globalClasses)
 *   $_getElementLabel, $_setActiveElement, $_savePost, $_forceRenderElementSubtree
 */
( function () {
	'use strict';

	// ---- Bricks builder API access ----
	function getApi() {
		var nodes = document.querySelectorAll( '*' );
		for ( var i = 0; i < nodes.length; i++ ) {
			var app = nodes[ i ].__vue_app__;
			if ( app && app.config && app.config.globalProperties && app.config.globalProperties.$_state ) {
				return app.config.globalProperties;
			}
		}
		return null;
	}
	function unref( v ) { return ( v && v.__v_isRef ) ? v.value : v; }
	function content( g ) { var s = unref( g.$_state ); return ( s && s.content ) || []; }
	function classes( g ) { var s = unref( g.$_state ); return unref( g.$_globalClasses ) || ( s && unref( s.globalClasses ) ) || []; } // $_globalClasses and $_state.globalClasses are the same list
	function kebab( s ) { return ( s || '' ).toString().trim().toLowerCase().replace( /[^a-z0-9]+/g, '-' ).replace( /^-|-$/g, '' ); }
	function rid() { var s = ''; while ( s.length < 6 ) { s += Math.random().toString( 36 ).slice( 2 ); } return s.slice( 0, 6 ); }
	function userId() { try { return ( window.bricksData && bricksData.user && bricksData.user.id ) || 0; } catch ( e ) { return 0; } }

	function elById( g, id ) { return content( g ).find( function ( e ) { return e.id === id; } ); }
	function labelOf( g, el ) { try { return g.$_getElementLabel ? g.$_getElementLabel( el ) : ( el.label || el.name ); } catch ( e ) { return el.label || el.name; } }

	// Depth-first descendants (flattened), excluding the root.
	function descendants( g, root ) {
		var out = [];
		( function walk( ids ) {
			( ids || [] ).forEach( function ( id ) {
				var el = elById( g, id );
				if ( ! el ) { return; }
				out.push( el );
				walk( el.children );
			} );
		} )( root.children );
		return out;
	}

	function ensureClass( g, name, created ) {
		var gc = classes( g );
		var existing = gc.find( function ( c ) { return c.name === name; } );
		if ( existing ) { return existing.id; }
		var id = rid();
		gc.push( { id: id, name: name, settings: {}, _mappedId: id, modified: Math.floor( Date.now() / 1000 ), user_id: userId() } );
		if ( created ) { created.push( { id: id, name: name } ); }
		return id;
	}
	function applyClass( g, el, name, created ) {
		var id = ensureClass( g, name, created );
		if ( ! el.settings ) { el.settings = {}; }
		if ( ! Array.isArray( el.settings._cssGlobalClasses ) ) { el.settings._cssGlobalClasses = []; }
		if ( el.settings._cssGlobalClasses.indexOf( id ) === -1 ) { el.settings._cssGlobalClasses.push( id ); }
		return name;
	}

	// Persist newly-created global classes server-side (Bricks stores them
	// separately from page content, so $_savePost alone may not save them).
	function persistClasses( created ) {
		var cfg = window.TokenForgeBem || {};
		if ( ! cfg.restUrl || ! created.length ) { return; }
		try {
			fetch( cfg.restUrl + '/register-classes', {
				method: 'POST',
				headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce },
				body: JSON.stringify( { items: created } )
			} );
		} catch ( e ) {}
	}

	// ---- Dialog ----
	function el( tag, cls, html ) { var e = document.createElement( tag ); if ( cls ) { e.className = cls; } if ( html != null ) { e.innerHTML = html; } return e; }
	function esc( s ) { return String( s == null ? '' : s ).replace( /[&<>"']/g, function ( c ) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ c ]; } ); }

	function openDialog( g, rootId ) {
		var root = elById( g, rootId );
		if ( ! root ) { return; }
		closeDialog();

		var block = kebab( labelOf( g, root ) ) || 'block';
		var kids = descendants( g, root );

		var overlay = el( 'div', 'tf-bem-overlay' );
		overlay.innerHTML =
			'<div class="tf-bem-modal">' +
				'<div class="tf-bem-mhead"><strong>TokenForge: BEM classes</strong><button class="tf-bem-x" title="Close">×</button></div>' +
				'<div class="tf-bem-mbody">' +
					'<label class="tf-bem-lbl">Block name</label>' +
					'<input class="tf-bem-block" type="text" value="' + esc( block ) + '">' +
					'<div class="tf-bem-hint">' + ( kids.length ) + ' descendant element(s). Each gets <code>block__&lt;label&gt;</code>. Edit any class below.</div>' +
					'<div class="tf-bem-rows"></div>' +
				'</div>' +
				'<div class="tf-bem-mfoot"><span class="tf-bem-note"></span><button class="tf-bem-apply">Apply Classes</button></div>' +
			'</div>';
		document.body.appendChild( overlay );

		var blockInput = overlay.querySelector( '.tf-bem-block' );
		var rowsEl = overlay.querySelector( '.tf-bem-rows' );
		var note = overlay.querySelector( '.tf-bem-note' );

		// row model: {id, label, custom:false, cls}
		var rows = [ { id: root.id, label: labelOf( g, root ), isRoot: true } ].concat(
			kids.map( function ( k ) { return { id: k.id, label: labelOf( g, k ), isRoot: false }; } )
		);

		function classFor( r ) {
			if ( r.custom != null ) { return r.custom; }
			return r.isRoot ? blockInput.value.trim() : ( blockInput.value.trim() + '__' + kebab( r.label ) );
		}
		function renderRows() {
			rowsEl.innerHTML = '';
			rows.forEach( function ( r, i ) {
				var row = el( 'div', 'tf-bem-row' + ( r.isRoot ? ' is-root' : '' ) );
				row.innerHTML =
					'<span class="tf-bem-elabel" title="' + esc( r.label ) + '">' + ( r.isRoot ? '◆ ' : '' ) + esc( r.label ) + '</span>' +
					'<input class="tf-bem-cls" data-i="' + i + '" type="text" value="' + esc( classFor( r ) ) + '">';
				rowsEl.appendChild( row );
			} );
		}
		renderRows();

		blockInput.addEventListener( 'input', function () {
			// recompute non-custom rows
			rows.forEach( function ( r ) { if ( r.custom == null ) { /* recompute via renderRows */ } } );
			renderRows();
		} );
		rowsEl.addEventListener( 'input', function ( e2 ) {
			var i = e2.target.getAttribute( 'data-i' );
			if ( i != null ) { rows[ parseInt( i, 10 ) ].custom = e2.target.value; }
		} );
		overlay.querySelector( '.tf-bem-x' ).addEventListener( 'click', closeDialog );
		overlay.addEventListener( 'click', function ( e2 ) { if ( e2.target === overlay ) { closeDialog(); } } );

		overlay.querySelector( '.tf-bem-apply' ).addEventListener( 'click', function () {
			var applied = 0;
			var created = [];
			rows.forEach( function ( r ) {
				var name = ( r.custom != null ? r.custom : classFor( r ) ).trim();
				name = name.replace( /[^a-z0-9_\-]/gi, '' );
				if ( ! name ) { return; }
				var target = elById( g, r.id );
				if ( target ) { applyClass( g, target, name, created ); applied++; }
			} );
			persistClasses( created );
			try { g.$_forceRenderElementSubtree && g.$_forceRenderElementSubtree( root.id ); } catch ( e3 ) {}
			try { g.$_savePost && g.$_savePost(); } catch ( e4 ) {}
			note.textContent = 'Applied ' + applied + ' class(es) and saved.';
			note.className = 'tf-bem-note is-ok';
			setTimeout( closeDialog, 1200 );
		} );

		blockInput.focus();
		blockInput.select();
	}
	function closeDialog() { var o = document.querySelector( '.tf-bem-overlay' ); if ( o ) { o.remove(); } }

	// ---- Structure-row action (native-looking) ----
	// Inject a BEM item into each row's own ul.actions (Duplicate/Delete bar),
	// which Bricks reveals on row hover, so it inherits native styling + behavior.
	function decorateRows() {
		document.querySelectorAll( 'li[data-id] > .structure-item' ).forEach( function ( si ) {
			var actions = si.querySelector( 'ul.actions' );
			if ( ! actions || actions.querySelector( '.tf-bem-action' ) ) { return; }
			var li = si.closest ? si.closest( 'li[data-id]' ) : null;
			var id = li && li.getAttribute( 'data-id' );
			if ( ! id ) { return; }
			var action = el( 'li', 'action tf-bem-action' );
			action.title = 'Generate BEM classes';
			// Native-style monochrome icon (code brackets) to match Bricks' own action icons.
			action.innerHTML = '<span class="bricks-svg-wrapper" data-name="tf-bem"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 14 14" fill="none" class="bricks-svg"><path stroke="currentColor" stroke-width="1" stroke-linecap="round" stroke-linejoin="round" d="M4.6 2.8 1 7l3.6 4.2M9.4 2.8 13 7l-3.6 4.2"/></svg></span>';
			action.addEventListener( 'click', function ( e2 ) {
				e2.stopPropagation();
				e2.preventDefault();
				var g = getApi();
				if ( g ) { openDialog( g, id ); }
			} );
			actions.insertBefore( action, actions.firstChild );
		} );
	}

	function init() {
		decorateRows();
		// Re-decorate as the structure panel updates, debounced (the builder mutates a lot).
		var t = null;
		var obs = new MutationObserver( function () {
			if ( t ) { return; }
			t = setTimeout( function () { t = null; decorateRows(); }, 400 );
		} );
		obs.observe( document.body, { childList: true, subtree: true } );
	}

	if ( document.readyState === 'loading' ) { document.addEventListener( 'DOMContentLoaded', function () { setTimeout( init, 1500 ); } ); }
	else { setTimeout( init, 1500 ); }
} )();
