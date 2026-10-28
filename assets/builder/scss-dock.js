/* TokenForge: Global SCSS dock in the Bricks builder.
 *
 * A panel docked under the canvas (like Bricks' own CSS panel) with the Global
 * SCSS editor, independent of the TokenForge window:
 *  - live preview: while you type, the compiled SCSS replaces the Global SCSS
 *    part of the TokenForge stylesheet in the canvas (nothing is saved);
 *  - live sync with the builder classes of the same name (when the sync is on):
 *    what you type shows in those classes (Style fields, CSS panel, canvas) right
 *    away, and what you change in the builder comes into the SCSS right away;
 *    nothing is stored until you save (Save here, or the builder's save);
 *  - Save (button or Ctrl/Cmd + S in the editor) stores it, syncs the builder
 *    classes and reloads the stylesheet everywhere;
 *  - a cheatsheet drawer: search classes, variables, mixins and recipes, click to
 *    insert at the caret;
 *  - resizable, minimise / maximise, remembered between visits; opens with a
 *    shortcut (also with the canvas focused) or the SCSS tab under the canvas.
 * If the SCSS changed elsewhere (dashboard, a builder class sync) while you have
 * unsaved edits, the dock asks before overwriting.
 */
( function () {
	'use strict';
	var cfg = window.TokenForgeDockCfg || {};
	if ( ! cfg.restUrl || window.TokenForgeDock ) { return; }

	var MARK = cfg.marker || '/* --- Global SCSS --- */';
	var KEY = 'tf-scss-dock-v1', HEAD = 36, MIN_H = 120;
	var st = {
		open: false, min: false, max: false, h: 300, w: 460, side: 'bottom', live: true, cheat: false,
		loaded: false, dirty: false, quiet: false, saving: false, source: '', rev: '',
		enabled: true, sync: false, status: {}, liveErr: null, ref: [], recipes: [], recipesOn: true,
		elsewhere: null, cheatData: null, cheatQ: '', cheatKind: ''
	};
	try { var saved = JSON.parse( window.localStorage.getItem( KEY ) || '{}' ); [ 'open', 'min', 'h', 'w', 'side', 'live', 'cheat' ].forEach( function ( k ) { if ( k in saved ) { st[ k ] = saved[ k ]; } } ); } catch ( e ) {}
	function remember() {
		try { window.localStorage.setItem( KEY, JSON.stringify( { open: st.open, min: st.min, h: st.h, w: st.w, side: st.side, live: st.live, cheat: st.cheat } ) ); } catch ( e ) {}
	}

	function api( path, method, body ) {
		return fetch( cfg.restUrl + path, {
			method: method || 'GET',
			credentials: 'same-origin',
			cache: 'no-store',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce },
			body: body ? JSON.stringify( body ) : undefined
		} ).then( function ( r ) { if ( ! r.ok ) { throw new Error( 'HTTP ' + r.status ); } return r.json(); } );
	}
	function esc( s ) {
		return String( s == null ? '' : s ).replace( /[&<>"']/g, function ( c ) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ c ]; } );
	}

	var ICON = {
		code: '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m16 18 6-6-6-6"/><path d="m8 6-6 6 6 6"/></svg>',
		book: '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 19.5V5a2 2 0 0 1 2-2h14v16H6.5A2.5 2.5 0 0 0 4 21.5"/><path d="M20 19v3H6.5"/><path d="M8 7h8M8 11h6"/></svg>',
		eye: '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/></svg>',
		max: '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 3h6v6M9 21H3v-6M21 3l-7 7M3 21l7-7"/></svg>',
		chev: '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg>',
		target: '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="8"/><circle cx="12" cy="12" r="3"/><path d="M12 1v3M12 20v3M1 12h3M20 12h3"/></svg>',
		side: '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="16" rx="2"/><path d="M15 4v16"/></svg>',
		close: '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M18 6 6 18M6 6l12 12"/></svg>',
		copy: '<svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>'
	};

	// ---- DOM ------------------------------------------------------------------
	var root = null, tab = null, ed = null, preview = null;
	function build() {
		root = document.createElement( 'div' );
		root.id = 'tf-sdock';
		root.className = 'tf-sdock';
		root.setAttribute( 'role', 'region' );
		root.setAttribute( 'aria-label', 'Global SCSS' );
		root.innerHTML =
			'<div class="tf-sdock-resize" title="Drag to resize"></div>' +
			'<div class="tf-sdock-head">' +
				'<button type="button" class="tf-sdock-title" data-a="min" title="Minimise / restore">' + ICON.code + '<span>Global SCSS</span></button>' +
				'<span class="tf-sdock-status" aria-live="polite"></span>' +
				'<div class="tf-sdock-actions">' +
					'<button type="button" class="tf-sdock-btn tf-sdock-live" data-a="live" title="Live preview in the canvas while you type">' + ICON.eye + '<span>Live</span></button>' +
					'<button type="button" class="tf-sdock-btn tf-sdock-cheatbtn" data-a="cheat" title="Cheatsheet: classes, variables, mixins, recipes">' + ICON.book + '<span>Cheatsheet</span></button>' +
					'<button type="button" class="tf-sdock-icon tf-sdock-goto" data-a="goto" title="Go to the SCSS of the class selected in the builder">' + ICON.target + '</button>' +
					'<button type="button" class="tf-sdock-icon" data-a="side" title="Dock at the bottom, right or left">' + ICON.side + '</button>' +
					'<button type="button" class="tf-sdock-btn tf-sdock-save" data-a="save" title="Save (Ctrl/Cmd + S in the editor)">Save</button>' +
					'<button type="button" class="tf-sdock-icon" data-a="max" title="Maximise / restore height">' + ICON.max + '</button>' +
					'<button type="button" class="tf-sdock-icon tf-sdock-minbtn" data-a="min" title="Minimise / restore">' + ICON.chev + '</button>' +
					'<button type="button" class="tf-sdock-icon" data-a="close" title="Close (' + esc( label() || 'SCSS tab' ) + ')">' + ICON.close + '</button>' +
				'</div>' +
			'</div>' +
			'<div class="tf-sdock-notice" hidden></div>' +
			'<div class="tf-sdock-body">' +
				'<div class="tf-sdock-editor"><textarea spellcheck="false" autocomplete="off" autocapitalize="off" aria-label="Global SCSS"></textarea></div>' +
				'<aside class="tf-sdock-cheat" hidden>' +
					'<div class="tf-sdock-cheat-head"><input type="search" class="tf-sdock-cheat-q" placeholder="Search classes, variables, mixins, recipes" aria-label="Search the cheatsheet">' +
					'<div class="tf-sdock-chips">' + [ [ '', 'All' ], [ 'class', 'Classes' ], [ 'var', 'Variables' ], [ 'mixin', 'Mixins' ], [ 'recipe', 'Recipes' ] ].map( function ( c ) { return '<button type="button" data-kind="' + c[0] + '"' + ( c[0] === st.cheatKind ? ' class="is-on"' : '' ) + '>' + c[1] + '</button>'; } ).join( '' ) + '</div></div>' +
					'<ul class="tf-sdock-cheat-list" role="listbox"></ul>' +
				'</aside>' +
			'</div>';
		tab = document.createElement( 'button' );
		tab.type = 'button';
		tab.className = 'tf-sdock-tab';
		tab.innerHTML = ICON.code + '<span>SCSS</span>';
		tab.title = 'Global SCSS' + ( label() ? ' (' + label() + ')' : '' );
		tab.addEventListener( 'click', function () { open(); } );

		// Keys typed here are for the editor, not for Bricks' shortcuts (Delete, Ctrl+Z, Ctrl+S …).
		[ 'keydown', 'keyup', 'keypress' ].forEach( function ( t ) { root.addEventListener( t, function ( e ) { e.stopPropagation(); } ); } );
		root.addEventListener( 'wheel', function ( e ) { e.stopPropagation(); }, { passive: true } );
		root.addEventListener( 'click', onClick );
		root.querySelector( '.tf-sdock-resize' ).addEventListener( 'pointerdown', startResize );
		var q = root.querySelector( '.tf-sdock-cheat-q' );
		q.addEventListener( 'input', function () { st.cheatQ = q.value; renderCheat(); } );
		q.addEventListener( 'keydown', function ( e ) { if ( 'Enter' === e.key ) { e.preventDefault(); var first = root.querySelector( '.tf-sdock-cheat-list li[data-i]' ); if ( first ) { pick( parseInt( first.getAttribute( 'data-i' ), 10 ) ); } } } );

		ed = window.TokenForgeScssEditor.create( root.querySelector( 'textarea' ), {
			api: api,
			height: 'fill',
			data: function () { return { recipes: st.recipes, recipesOn: st.recipesOn, ref: st.ref }; },
			onChange: changed,
			onSave: function () { save( false ); }
		} );
	}

	function mount() {
		var p = document.getElementById( 'bricks-preview' );
		if ( ! p ) { return false; }
		if ( ! root ) { build(); }
		if ( preview && preview !== p ) { unpad( preview ); }
		preview = p;
		if ( root.parentNode !== p ) { p.appendChild( root ); }
		if ( tab.parentNode !== p ) { p.appendChild( tab ); }
		return true;
	}
	function unpad( p ) { p.style.paddingBottom = ''; p.style.paddingLeft = ''; p.style.paddingRight = ''; p.style.boxSizing = ''; }

	function height() {
		if ( st.min ) { return HEAD; }
		var room = preview ? preview.getBoundingClientRect().height : 600;
		var h = st.max ? room * 0.72 : st.h;
		return Math.round( Math.max( MIN_H, Math.min( h, room - 80 ) ) );
	}
	function width() {
		var room = preview ? preview.getBoundingClientRect().width : 1200;
		return Math.round( Math.max( 280, Math.min( st.max ? room * 0.6 : st.w, room - 240 ) ) );
	}
	// Place the dock and push the canvas up by its height.
	function layout() {
		if ( ! mount() ) { return; }
		var hidden = ! st.open || preview.classList.contains( 'is-previewing' );
		root.hidden = hidden;
		tab.hidden = ! hidden || ! cfg.tab || preview.classList.contains( 'is-previewing' );
		if ( hidden ) { unpad( preview ); return; }
		var side = 'left' === st.side || 'right' === st.side ? st.side : 'bottom';
		root.classList.toggle( 'is-min', st.min );
		root.classList.toggle( 'is-max', st.max && ! st.min );
		root.classList.toggle( 'is-left', 'left' === side );
		root.classList.toggle( 'is-right', 'right' === side );
		unpad( preview );
		preview.style.boxSizing = 'border-box';
		if ( 'bottom' === side ) {
			var h = height();
			root.style.width = '';
			root.style.height = h + 'px';
			preview.style.paddingBottom = h + 'px';
		} else {
			// Beside the canvas: full height (just the header when minimised).
			var w = width();
			root.style.width = w + 'px';
			root.style.height = st.min ? 'auto' : '';
			if ( ! st.min ) { preview.style[ 'left' === side ? 'paddingLeft' : 'paddingRight' ] = w + 'px'; }
		}
		root.querySelector( '.tf-sdock-live' ).classList.toggle( 'is-on', st.live );
		root.querySelector( '.tf-sdock-cheatbtn' ).classList.toggle( 'is-on', st.cheat );
		root.querySelector( '.tf-sdock-cheat' ).hidden = ! st.cheat || st.min;
		if ( ed ) { ed.refresh(); }
	}

	// ---- Open / close ---------------------------------------------------------
	function open() {
		st.open = true;
		if ( st.min ) { st.min = false; }
		remember();
		layout();
		if ( ! st.loaded ) { fetchSource( true ).then( function () { if ( st.cheat ) { loadCheat(); } } ); } else if ( ! st.dirty ) { fetchSource( false ); }
		setTimeout( function () { if ( ed ) { ed.focus(); } }, 30 );
	}
	function close() {
		st.open = false;
		remember();
		layout();
	}
	function toggle() { if ( st.open && ! st.min ) { close(); } else { open(); } }

	// ---- Source ---------------------------------------------------------------
	function setQuiet( v ) { st.quiet = true; ed.setValue( v ); st.quiet = false; }
	function fetchSource( force ) {
		return api( '/scss' ).then( function ( r ) {
			st.enabled = !! r.enabled; st.sync = !! r.sync; st.status = r.status || {};
			layout();
			st.ref = r.ref || []; st.recipes = r.recipes || []; st.recipesOn = !! r.recipes_on;
			if ( force || ! st.dirty ) {
				st.source = r.source || ''; st.rev = r.rev || ''; st.dirty = false; st.fromBuilder = false; st.elsewhere = null; st.liveErr = null;
				setQuiet( st.source );
				ed.setError( st.status.error );
			} else if ( r.rev !== st.rev ) {
				st.elsewhere = r;
			}
			st.loaded = true;
			renderStatus();
			renderNotice();
		} ).catch( function ( e ) { status( 'Could not load Global SCSS (' + e.message + ')', 'err' ); } );
	}
	var timer = null;
	function changed( v ) {
		if ( st.quiet ) { return; }
		st.source = v;
		st.fromBuilder = false;
		if ( ! st.dirty ) { st.dirty = true; renderStatus(); }
		clearTimeout( timer );
		timer = setTimeout( compile, 450 );
	}
	var compileSeq = 0;
	// fromBuilder: the text came from a builder change, so it is not sent back to the builder.
	function compile( fromBuilder ) {
		var seq = ++compileSeq, src = st.source;
		return api( '/scss-compile', 'POST', { source: src } ).then( function ( r ) {
			if ( seq !== compileSeq ) { return; }
			st.liveErr = r.error || null;
			ed.setError( st.liveErr );
			renderStatus();
			if ( ! r.error && st.live && st.enabled ) { live( r.css || '' ); }
			if ( ! r.error && true !== fromBuilder ) { livePush( src ); }
		} ).catch( function () {} );
	}

	// ---- Live sync with the builder's classes (nothing is stored; Save stores) ----------
	// SCSS → builder: while you type, the classes with a block show what the block says.
	// Builder → SCSS: a class you change in the builder (Style fields or CSS panel)
	// is written into its block here right away.
	var snaps = {}, pend = {}, liveBusy = false, pushSeq = 0;
	function gp() {
		var el = document.querySelector( 'div.brx-body.main' );
		return el && el.__vue_app__ && el.__vue_app__.config && el.__vue_app__.config.globalProperties;
	}
	// Names of the top-level class blocks (".name {" at the start of a line).
	function blockNames( src ) {
		var out = [], seen = {}, re = /(^|\n)\.(-?[_a-zA-Z][\w-]*)\s*\{/g, m;
		while ( ( m = re.exec( src ) ) ) { if ( ! seen[ m[2] ] ) { seen[ m[2] ] = 1; out.push( m[2] ); } }
		return out;
	}
	function findClass( list, name ) {
		for ( var i = 0; i < list.length; i++ ) { if ( list[ i ] && list[ i ].name === name ) { return list[ i ]; } }
		return null;
	}
	function livePush( src ) {
		var g = st.sync && gp(), list = g && builderClassList( g );
		if ( ! Array.isArray( list ) ) { return; }
		var classes = [];
		blockNames( src ).forEach( function ( n ) { var c = findClass( list, n ); if ( c ) { classes.push( { name: n, settings: JSON.parse( JSON.stringify( c.settings || {} ) ) } ); } } );
		if ( ! classes.length ) { return; }
		var seq = ++pushSeq;
		liveBusy = true;
		api( '/scss-live', 'POST', { mode: 'push', source: src, classes: classes } ).then( function ( r ) {
			liveBusy = false;
			if ( seq === pushSeq && r && r.ok && r.classes && r.classes.length ) { builderClasses( r.classes ); }
		} ).catch( function () { liveBusy = false; } );
	}
	// The classes to watch: those with a block, and the one open in the builder.
	function watched( g ) {
		var names = blockNames( ed ? ed.getValue() : '' ), ac = unref( g.$_activeClass );
		if ( ac && 'object' === typeof ac && ac.name && names.indexOf( ac.name ) < 0 ) { names.push( ac.name ); }
		return names;
	}
	function liveWatch() {
		if ( ! st.sync || ! st.loaded || ! ed || liveBusy || st.saving ) { return; }
		var g = gp(), list = g && builderClassList( g );
		if ( ! Array.isArray( list ) ) { return; }
		var changed = [];
		watched( g ).forEach( function ( n ) {
			var c = findClass( list, n );
			if ( ! c ) { return; }
			var j = JSON.stringify( c.settings || {} );
			if ( ! ( n in snaps ) ) { snaps[ n ] = j; return; }
			if ( j === snaps[ n ] ) { delete pend[ n ]; return; }
			// Wait until the class stays the same for a moment (you may still be typing).
			if ( pend[ n ] !== j ) { pend[ n ] = j; return; }
			changed.push( { name: n, settings: JSON.parse( j ), old: JSON.parse( snaps[ n ] ) } );
			snaps[ n ] = j;
			delete pend[ n ];
		} );
		if ( ! changed.length ) { return; }
		var src = ed.getValue();
		liveBusy = true;
		api( '/scss-live', 'POST', { mode: 'pull', source: src, classes: changed } ).then( function ( r ) {
			liveBusy = false;
			if ( r && r.ok && 'string' === typeof r.source && r.source !== ed.getValue() && src === ed.getValue() ) { fromBuilder( r.source ); }
		} ).catch( function () { liveBusy = false; } );
	}
	// Put SCSS written from a builder change into the editor, where you were.
	function fromBuilder( src ) {
		var cm = ed.cm, cur = cm ? cm.getCursor() : null, info = cm ? cm.getScrollInfo() : null;
		setQuiet( src );
		if ( cm ) { cm.setCursor( cur ); cm.scrollTo( info.left, info.top ); }
		st.source = src;
		st.dirty = true;
		st.fromBuilder = true;
		renderStatus();
		compile( true );
	}

	// ---- Live preview in the canvas ---------------------------------------------
	function canvasDoc() {
		var f = document.getElementById( 'bricks-builder-iframe' );
		try { return f && f.contentDocument; } catch ( e ) { return null; }
	}
	function bust( url, v ) {
		if ( ! v ) { return url; }
		return /[?&]ver=/.test( url ) ? url.replace( /([?&])ver=[^&]*/, '$1ver=' + encodeURIComponent( v ) ) : url + ( url.indexOf( '?' ) > -1 ? '&' : '?' ) + 'ver=' + encodeURIComponent( v );
	}
	// The TokenForge stylesheet in the canvas as an inline <style> (the canvas'
	// hot reload handles that form too), so its Global SCSS part can be swapped.
	var converting = null;
	function canvasStyle() {
		var doc = canvasDoc();
		if ( ! doc ) { return Promise.resolve( null ); }
		var style = doc.getElementById( 'tokenforge-inline-css' );
		if ( style ) { return Promise.resolve( style ); }
		var link = doc.getElementById( 'tokenforge-css' );
		if ( ! link ) { return Promise.resolve( null ); }
		if ( converting ) { return converting; }
		converting = fetch( link.href, { credentials: 'same-origin', cache: 'no-store' } ).then( function ( r ) { return r.text(); } ).then( function ( css ) {
			converting = null;
			if ( ! link.isConnected ) { return doc.getElementById( 'tokenforge-inline-css' ); }
			var s = doc.createElement( 'style' );
			s.id = 'tokenforge-inline-css';
			s.textContent = css;
			link.parentNode.insertBefore( s, link );
			link.remove();
			return s;
		} ).catch( function () { converting = null; return null; } );
		return converting;
	}
	function live( css ) {
		return canvasStyle().then( function ( s ) {
			if ( ! s ) { return; }
			var t = s.textContent, i = t.indexOf( '\n' + MARK );
			var base = i > -1 ? t.slice( 0, i ) : t.replace( /\s+$/, '' );
			s.textContent = base + ( css.trim() ? '\n' + MARK + '\n' + css + '\n' : '\n' );
		} );
	}
	// Put the saved stylesheet back into the canvas.
	function canvasReload( url, ver ) {
		var doc = canvasDoc();
		if ( ! doc ) { return; }
		var s = doc.getElementById( 'tokenforge-inline-css' ), link = doc.getElementById( 'tokenforge-css' );
		var go = function ( u ) {
			// The canvas has the file with every utility class: stay on it.
			if ( link && /\/tokenforge-full\.css/.test( link.href ) ) { u = u.replace( /\/tokenforge\.css/, '/tokenforge-full.css' ); }
			if ( s ) { fetch( u, { credentials: 'same-origin', cache: 'no-store' } ).then( function ( r ) { return r.text(); } ).then( function ( css ) { s.textContent = css; } ).catch( function () {} ); }
			else if ( link ) { link.href = u; }
		};
		if ( url ) { go( bust( url, ver ) ); return; }
		api( '/css-version' ).then( function ( r ) { go( bust( r.url, r.ver ) ); } ).catch( function () {} );
	}

	// ---- Save -----------------------------------------------------------------
	function announce( ver, url ) {
		if ( ! ver ) { return; }
		var msg = { type: 'tokenforge:css', ver: String( ver ), url: url || '', from: 'dock' };
		try { if ( channel ) { channel.postMessage( msg ); } } catch ( e ) {}
		try { window.dispatchEvent( new CustomEvent( 'tokenforge:css', { detail: msg } ) ); } catch ( e2 ) {}
	}
	// Classes the SCSS sync changed: update the builder's own copy right away. The
	// class open in the panels is a separate copy ($_state.activeClass) that the
	// builder writes back when you move on, so it gets the new settings too (the
	// way the builder itself does it), and the panels are drawn again.
	function builderClasses( list ) {
		if ( ! list || ! list.length || ! window.TokenForgeApp ) { return; }
		// Our own change: not sent back into the SCSS.
		list.forEach( function ( c ) { snaps[ c.name ] = JSON.stringify( c.settings || {} ); delete pend[ c.name ]; } );
		window.TokenForgeApp.builderClasses( list );
	}
	function save( force ) {
		if ( st.saving || ! ed ) { return; }
		st.saving = true;
		status( 'Saving…' );
		var src = ed.getValue();
		api( '/scss-save', 'POST', { source: src, rev: st.rev, force: !! force } ).then( function ( r ) {
			st.saving = false;
			if ( r.conflict ) { st.elsewhere = r; renderStatus(); renderNotice(); return; }
			if ( ! r.ok ) { throw new Error( r.error || 'failed' ); }
			st.rev = r.rev; st.status = r.status || {}; st.dirty = false; st.fromBuilder = false; st.elsewhere = null; st.liveErr = null;
			if ( 'string' === typeof r.source ) { st.source = r.source; setQuiet( r.source ); }
			ed.setError( st.status.error );
			builderClasses( r.synced );
			canvasReload( r.css_url, r.css_ver );
			announce( r.css_ver, r.css_url );
			renderNotice();
			renderStatus( syncNote( r ) );
			syncErrors( r );
		} ).catch( function ( e ) { st.saving = false; status( 'Save failed: ' + e.message, 'err' ); } );
	}
	function syncNote( r ) {
		var n = r.synced ? r.synced.length : 0;
		return n ? n + ' builder class' + ( 1 === n ? '' : 'es' ) + ' updated' : '';
	}
	// A class block that does not compile on its own can't fill its builder class.
	function syncErrors( r ) {
		var errs = r.sync_errors && 'object' === typeof r.sync_errors ? Object.keys( r.sync_errors ) : [];
		if ( errs.length ) { status( 'Not synced: .' + errs.join( ', .' ) + ' (' + r.sync_errors[ errs[0] ] + ')', 'warn' ); }
	}

	// ---- Status + notices ---------------------------------------------------------
	var flash = '';
	function status( text, kind ) {
		var el = root && root.querySelector( '.tf-sdock-status' );
		if ( el ) { el.className = 'tf-sdock-status' + ( kind ? ' is-' + kind : '' ); el.textContent = text; }
	}
	function renderStatus( extra ) {
		if ( ! root ) { return; }
		flash = extra || '';
		var err = st.liveErr || ( ! st.dirty && st.status && st.status.error );
		if ( ! st.loaded ) { status( 'Loading…' ); return; }
		if ( ! st.enabled ) { status( 'Global SCSS is switched off (TokenForge, Global SCSS): it is saved but not added to the stylesheet.', 'warn' ); return; }
		if ( err ) { status( 'Line ' + ( err.line || '?' ) + ( err.column ? ':' + err.column : '' ) + ': ' + err.message + ( st.dirty ? ' (the last working CSS stays)' : '' ), 'err' ); return; }
		if ( st.dirty ) { status( 'Unsaved' + ( st.fromBuilder ? ' · changed in the builder, Save keeps it (or save the builder)' : '' ) + ( st.live ? ' · live preview' : '' ) + ( st.sync ? ' · the builder shows it live' : '' ), 'dirty' ); return; }
		status( 'Saved' + ( flash ? ' · ' + flash : '' ) + ( st.sync ? ' · live sync with builder classes' : ' · builder sync off (TokenForge, Global SCSS)' ), 'ok' );
	}
	function renderNotice() {
		if ( ! root ) { return; }
		var n = root.querySelector( '.tf-sdock-notice' );
		if ( ! st.elsewhere ) { n.hidden = true; n.innerHTML = ''; return; }
		n.hidden = false;
		n.innerHTML = '<span>Global SCSS was changed somewhere else (the dashboard or a builder class sync) while you had unsaved edits.</span>' +
			'<button type="button" class="tf-sdock-btn" data-a="theirs">Load that version</button>' +
			'<button type="button" class="tf-sdock-btn" data-a="mine">Overwrite with mine</button>';
	}

	// ---- Cheatsheet drawer -------------------------------------------------------
	var cheatItems = [], shown = [];
	function loadCheat() {
		if ( st.cheatData ) { renderCheat(); return; }
		st.cheatData = 'loading';
		root.querySelector( '.tf-sdock-cheat-list' ).innerHTML = '<li class="tf-sdock-muted">Loading…</li>';
		api( '/cheatsheet' ).then( function ( r ) {
			st.cheatData = r.groups || [];
			cheatItems = [];
			st.cheatData.forEach( function ( g ) { ( g.items || [] ).forEach( function ( it ) { it.g = g.title; cheatItems.push( it ); } ); } );
			renderCheat();
		} ).catch( function () { st.cheatData = null; root.querySelector( '.tf-sdock-cheat-list' ).innerHTML = '<li class="tf-sdock-muted">Could not load the cheatsheet.</li>'; } );
	}
	function kindOf( it ) { return 'function' === it.k ? 'mixin' : it.k; }
	function renderCheat() {
		if ( ! root || ! Array.isArray( st.cheatData ) ) { return; }
		var q = st.cheatQ.trim().toLowerCase(), words = q.split( /\s+/ ).filter( Boolean );
		shown = cheatItems.filter( function ( it ) {
			if ( st.cheatKind && kindOf( it ) !== st.cheatKind ) { return false; }
			if ( ! words.length ) { return true; }
			var hay = ( it.n + ' ' + it.d + ' ' + it.g ).toLowerCase();
			return words.every( function ( w ) { return hay.indexOf( w ) > -1; } );
		} );
		if ( q ) {
			shown.sort( function ( a, b ) { var an = a.n.toLowerCase().indexOf( q ), bn = b.n.toLowerCase().indexOf( q ); return ( an < 0 ? 99 : an ) - ( bn < 0 ? 99 : bn ) || a.n.length - b.n.length; } );
		}
		var total = shown.length;
		shown = shown.slice( 0, 150 );
		root.querySelector( '.tf-sdock-cheat-list' ).innerHTML = shown.length ? shown.map( function ( it, i ) {
			return '<li data-i="' + i + '" title="Click to insert at the caret"><div class="tf-sdock-cheat-row"><code>' + esc( 'class' === it.k ? '.' + it.n : it.n ) + '</code><button type="button" class="tf-sdock-copy" data-copy="' + i + '" title="Copy">' + ICON.copy + '</button></div><span>' + esc( it.d ) + '</span></li>';
		} ).join( '' ) + ( total > shown.length ? '<li class="tf-sdock-muted">' + ( total - shown.length ) + ' more, refine the search</li>' : '' ) : '<li class="tf-sdock-muted">Nothing found.</li>';
	}
	// What a cheatsheet item puts into SCSS ("{{cursor}}" = where the caret lands).
	function scssFor( it ) {
		if ( 'class' === it.k ) { return '.' + it.copy; }
		if ( 'attr' === it.k ) { return '[' + it.copy + ']'; }
		return String( it.copy ).replace( /\{\s*…\s*\}\s*$/, '{\n\t{{cursor}}\n}' );
	}
	function pick( i ) {
		var it = shown[ i ];
		if ( ! it || ! ed ) { return; }
		if ( 'recipe' === it.k ) {
			// Shaped for the caret: a value inside "prop: |", a declaration or rule elsewhere.
			if ( ! ed.insertRecipe( String( it.copy ).replace( /^\?/, '' ) ) ) {
				copy( it.copy );
				status( it.copy + ' does not fit at the caret (a value needs "property:", code and text snippets are for builder elements): copied instead.', 'warn' );
			}
			ed.focus();
			return;
		}
		ed.insert( scssFor( it ) );
		ed.focus();
	}
	function copy( text, btn ) {
		var done = function () { if ( btn ) { btn.classList.add( 'is-done' ); setTimeout( function () { btn.classList.remove( 'is-done' ); }, 900 ); } };
		if ( navigator.clipboard && navigator.clipboard.writeText ) { navigator.clipboard.writeText( text ).then( done, function () {} ); return; }
		var t = document.createElement( 'textarea' ); t.value = text; document.body.appendChild( t ); t.select();
		try { document.execCommand( 'copy' ); done(); } catch ( e ) {}
		t.remove();
	}

	// ---- The class selected in the builder → its block in Global SCSS ---------------------
	function unref( v ) { return v && v.__v_isRef ? v.value : v; }
	// The builder's global class list ($_globalClasses and $_state.globalClasses are the same list).
	function builderClassList( g ) {
		var s = unref( g.$_state );
		return unref( g.$_globalClasses ) || ( s && unref( s.globalClasses ) ) || null;
	}
	function activeClassName() {
		var g = gp();
		if ( ! g ) { return ''; }
		var all = builderClassList( g ) || [];
		var byId = function ( id ) { var c = all.find( function ( x ) { return x.id === id; } ); return c ? c.name : ''; };
		var ac = unref( g.$_activeClass );
		if ( ac && 'object' === typeof ac && ac.name ) { return ac.name; }
		if ( 'string' === typeof ac && ac ) { return byId( ac ) || ac; }
		var act = unref( g.$_activeElement );
		var ids = act && act.settings && Array.isArray( act.settings._cssGlobalClasses ) ? act.settings._cssGlobalClasses : [];
		var names = ids.map( byId ).filter( Boolean );
		var src = ed ? ed.getValue() : '';
		return names.find( function ( nm ) { return blockAt( src, nm ) > -1; } ) || names[0] || '';
	}
	// Where ".name {" starts (a top-level or nested block), or -1.
	function blockAt( src, name ) {
		var safe = name.replace( /[-\/\\^$*+?.()|[\]{}]/g, '\\$&' );
		var m = new RegExp( '(^|\\n)[ \\t]*\\.' + safe + '\\s*\\{' ).exec( src );
		return m ? m.index + m[1].length : -1;
	}
	function gotoClass() {
		if ( ! ed ) { return; }
		var name = activeClassName();
		if ( ! name ) { status( 'Select an element with a class (or a class in the selector) first.', 'warn' ); return; }
		var src = ed.getValue(), at = blockAt( src, name );
		if ( at > -1 ) { ed.goTo( at ); status( '.' + name ); return; }
		// No block yet: start one at the end.
		ed.goTo( src.length );
		ed.insert( ( src.trim() ? '\n\n' : '' ) + '.' + name + ' {\n\t{{cursor}}\n}' );
		status( 'New block for .' + name + ' (Save to keep it)', 'dirty' );
	}

	// ---- Events ---------------------------------------------------------------
	function onClick( e ) {
		var chip = e.target.closest( '[data-kind]' );
		if ( chip ) {
			st.cheatKind = chip.getAttribute( 'data-kind' );
			root.querySelectorAll( '.tf-sdock-chips button' ).forEach( function ( b ) { b.classList.toggle( 'is-on', b === chip ); } );
			renderCheat();
			return;
		}
		var cp = e.target.closest( '[data-copy]' );
		if ( cp ) { e.stopPropagation(); var ci = shown[ parseInt( cp.getAttribute( 'data-copy' ), 10 ) ]; if ( ci ) { copy( ci.copy, cp ); } return; }
		var li = e.target.closest( '.tf-sdock-cheat-list li[data-i]' );
		if ( li ) { pick( parseInt( li.getAttribute( 'data-i' ), 10 ) ); return; }
		var b = e.target.closest( '[data-a]' );
		if ( ! b ) { return; }
		var a = b.getAttribute( 'data-a' );
		if ( 'close' === a ) { close(); }
		else if ( 'side' === a ) { st.side = { bottom: 'right', right: 'left', left: 'bottom' }[ st.side ] || 'bottom'; remember(); layout(); }
		else if ( 'goto' === a ) { gotoClass(); }
		else if ( 'min' === a ) { st.min = ! st.min; remember(); layout(); }
		else if ( 'max' === a ) { st.max = ! st.max; st.min = false; remember(); layout(); }
		else if ( 'save' === a ) { save( false ); }
		else if ( 'live' === a ) {
			st.live = ! st.live; remember(); layout(); renderStatus();
			if ( st.live && st.dirty ) { compile(); } else if ( ! st.live ) { canvasReload(); }
		}
		else if ( 'cheat' === a ) {
			st.cheat = ! st.cheat; st.min = false; remember(); layout();
			if ( st.cheat ) { loadCheat(); setTimeout( function () { var q = root.querySelector( '.tf-sdock-cheat-q' ); if ( q ) { q.focus(); } }, 30 ); }
		}
		else if ( 'theirs' === a ) { fetchSource( true ).then( function () { if ( st.live ) { compile(); } } ); }
		else if ( 'mine' === a ) { save( true ); }
	}

	// Resize by dragging the top edge (a shield keeps the canvas iframe from swallowing the pointer).
	function startResize( e ) {
		if ( st.min ) { return; }
		e.preventDefault();
		var shield = document.createElement( 'div' );
		shield.className = 'tf-sdock-shield';
		document.body.appendChild( shield );
		var box = preview.getBoundingClientRect();
		var move = function ( ev ) {
			if ( 'left' === st.side ) { st.w = Math.round( ev.clientX - box.left ); } else if ( 'right' === st.side ) { st.w = Math.round( box.right - ev.clientX ); } else { st.h = Math.round( box.bottom - ev.clientY ); }
			st.max = false; layout();
		};
		var up = function () {
			window.removeEventListener( 'pointermove', move );
			window.removeEventListener( 'pointerup', up );
			shield.remove();
			if ( 'left' === st.side || 'right' === st.side ) { st.w = width(); } else { st.h = height(); }
			remember();
		};
		window.addEventListener( 'pointermove', move );
		window.addEventListener( 'pointerup', up );
	}

	// ---- Shortcut (also while the canvas iframe has focus) --------------------------
	function parseShortcut( s ) {
		if ( ! s ) { return null; }
		var parts = String( s ).toLowerCase().split( '+' ), out = { ctrl: false, alt: false, shift: false, meta: false, key: '' };
		parts.forEach( function ( p ) { if ( p in out && 'key' !== p ) { out[ p ] = true; } else if ( p ) { out.key = p; } } );
		return out.key ? out : null;
	}
	var SC = parseShortcut( cfg.shortcut );
	function label() {
		if ( ! SC ) { return ''; }
		return [ SC.ctrl && 'Ctrl', SC.alt && 'Alt', SC.shift && 'Shift', SC.meta && 'Cmd', SC.key.toUpperCase() ].filter( Boolean ).join( '+' );
	}
	function codeKey( e ) {
		var c = e.code || '';
		if ( /^Key[A-Z]$/.test( c ) ) { return c.slice( 3 ).toLowerCase(); }
		if ( /^Digit\d$/.test( c ) ) { return c.slice( 5 ); }
		if ( /^F\d{1,2}$/.test( c ) ) { return c.toLowerCase(); }
		return String( e.key || '' ).toLowerCase();
	}
	function onKey( e ) {
		if ( e.repeat || ! SC || e.ctrlKey !== SC.ctrl || e.altKey !== SC.alt || e.shiftKey !== SC.shift || e.metaKey !== SC.meta || codeKey( e ) !== SC.key ) { return; }
		var t = e.composedPath ? e.composedPath()[0] : e.target;
		if ( t && t.classList && t.classList.contains( 'tf-keyrec' ) ) { return; } // recording a new shortcut
		e.preventDefault(); e.stopPropagation();
		toggle();
	}
	window.addEventListener( 'keydown', onKey, true );
	function hookFrames() {
		document.querySelectorAll( 'iframe' ).forEach( function ( f ) {
			try { var w = f.contentWindow; if ( w && ! w.__tfDockKey ) { w.__tfDockKey = true; w.addEventListener( 'keydown', onKey, true ); } } catch ( e ) {}
		} );
	}
	window.addEventListener( 'tokenforge:settings', function ( e ) {
		var d = e.detail || {};
		if ( 'dockShortcut' in d ) { SC = parseShortcut( d.dockShortcut ); if ( tab ) { tab.title = 'Global SCSS' + ( label() ? ' (' + label() + ')' : '' ); } }
		if ( 'dockTab' in d ) { cfg.tab = !! d.dockTab; layout(); }
	} );

	// ---- Saves elsewhere ---------------------------------------------------------
	var channel = null;
	try { channel = typeof window.BroadcastChannel === 'function' ? new window.BroadcastChannel( 'tokenforge' ) : null; } catch ( e ) {}
	function elsewhere( d ) {
		if ( d && 'tokenforge:css' === d.type && 'dock' !== d.from && st.open && st.loaded ) { checkRev(); }
	}
	if ( channel ) { channel.onmessage = function ( e ) { elsewhere( e.data ); }; }
	window.addEventListener( 'tokenforge:css', function ( e ) { elsewhere( e.detail ); } );
	function checkRev() {
		api( '/scss-rev' ).then( function ( r ) {
			if ( ! r || ! r.rev || r.rev === st.rev ) { return; }
			if ( ! st.dirty ) { fetchSource( false ); return; }
			// Saved elsewhere while you have unsaved text: the builder's save of the same
			// change (it wrote the same SCSS) is simply taken as saved.
			api( '/scss' ).then( function ( s ) {
				if ( s && 'string' === typeof s.source && s.source === ed.getValue() ) {
					st.rev = s.rev; st.dirty = false; st.fromBuilder = false; st.elsewhere = null; renderStatus(); renderNotice();
				} else if ( ! st.elsewhere ) { st.elsewhere = r; renderNotice(); }
			} ).catch( function () {} );
		} ).catch( function () {} );
	}
	window.addEventListener( 'beforeunload', function ( e ) { if ( st.dirty ) { e.preventDefault(); e.returnValue = ''; } } );

	// ---- Start ----------------------------------------------------------------
	var ticks = 0;
	setInterval( function () {
		ticks++;
		if ( ! window.TokenForgeScssEditor ) { return; }
		if ( ! root || ! root.isConnected || ! tab.isConnected || ( preview && ! preview.isConnected ) ) { layout(); }
		else if ( st.open ) {
			// Bricks' preview mode hides the dock; keep the padding in step with it.
			var hidden = preview.classList.contains( 'is-previewing' );
			if ( hidden !== root.hidden ) { layout(); }
		}
		if ( 0 === ticks % 2 ) { hookFrames(); }
		if ( 0 === ticks % 4 && st.open && st.loaded && ! st.saving && 'visible' === document.visibilityState ) { checkRev(); }
		liveWatch();
	}, 1000 );
	function start() {
		if ( ! window.TokenForgeScssEditor || ! mount() ) { setTimeout( start, 500 ); return; }
		layout();
		if ( st.open ) { st.open = false; open(); }
	}
	start();

	window.TokenForgeDock = { open: open, close: close, toggle: toggle, isOpen: function () { return st.open; }, save: function () { save( false ); } };
} )();
