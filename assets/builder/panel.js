/* TokenForge window: the full dashboard in the Bricks builder and (for
 * administrators) on the front end.
 * Dock left / dock right / float (drag + resize from any edge), minimize,
 * snap back to an edge by dragging there. Geometry is remembered per browser.
 * A keyboard shortcut (Options → TokenForge window) opens/closes it, in the
 * builder also while the focus is inside the canvas iframe. On the front end the
 * window lives in a shadow root so the site's CSS can't restyle it.
 */
( function () {
	'use strict';
	var cfg = window.TokenForgePanel || {};
	var FRONT = 'front' === cfg.context;
	if ( FRONT && window.top !== window ) { return; } // never inside a preview frame
	var KEY = FRONT ? 'tf-dock-front-v1' : 'tf-dock-v2';
	var MIN_W = 340, MIN_H = 260, SNAP = 14;
	var mounted = false;
	var host, root, dock, panel, shield, hint;

	var state = { mode: FRONT ? 'float' : 'right', w: 520, x: 120, y: 90, fw: 560, fh: 620, open: false, min: false, last: 'right' };
	try { var saved = JSON.parse( window.localStorage.getItem( KEY ) || 'null' ); if ( saved ) { for ( var k in saved ) { state[ k ] = saved[ k ]; } } } catch ( e ) {}
	function persist() { try { window.localStorage.setItem( KEY, JSON.stringify( state ) ); } catch ( e ) {} }

	var ICON = {
		left: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="16" rx="2"/><path d="M9 4v16"/></svg>',
		right: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="16" rx="2"/><path d="M15 4v16"/></svg>',
		float: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="7" width="13" height="13" rx="2"/><path d="M8 7V5a1 1 0 0 1 1-1h10a1 1 0 0 1 1 1v10a1 1 0 0 1-1 1h-3"/></svg>',
		min: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M6 12h12"/></svg>',
		ext: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M14 4h6v6M20 4l-9 9M18 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h5"/></svg>',
		close: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M6 6l12 12M18 6 6 18"/></svg>',
		logo: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="7" height="7" rx="1"/><rect x="14" y="4" width="7" height="7" rx="1"/><rect x="3" y="13" width="7" height="7" rx="1"/><rect x="14" y="13" width="7" height="7" rx="1"/></svg>'
	};

	function clamp( v, lo, hi ) { return Math.max( lo, Math.min( hi, v ) ); }

	// ---- Shortcut ("alt+shift+t", "ctrl+alt+f2" …) ----
	function parseShortcut( s ) {
		if ( ! s ) { return null; }
		var parts = String( s ).toLowerCase().split( '+' ), out = { ctrl: false, alt: false, shift: false, meta: false, key: '' };
		parts.forEach( function ( p ) { if ( p in out && 'key' !== p ) { out[ p ] = true; } else if ( p ) { out.key = p; } } );
		return out.key ? out : null;
	}
	var SC = parseShortcut( cfg.shortcut );
	// A new shortcut saved in the dashboard applies right away.
	window.addEventListener( 'tokenforge:settings', function ( e ) {
		if ( e.detail && 'shortcut' in e.detail ) { SC = parseShortcut( e.detail.shortcut ); relabel(); }
	} );
	function codeKey( e ) {
		// Layout-independent where possible (Alt+letter types other characters on macOS).
		var c = e.code || '';
		if ( /^Key[A-Z]$/.test( c ) ) { return c.slice( 3 ).toLowerCase(); }
		if ( /^Digit\d$/.test( c ) ) { return c.slice( 5 ); }
		if ( /^F\d{1,2}$/.test( c ) ) { return c.toLowerCase(); }
		return String( e.key || '' ).toLowerCase();
	}
	function isShortcut( e ) {
		return !! SC && e.ctrlKey === SC.ctrl && e.altKey === SC.alt && e.shiftKey === SC.shift && e.metaKey === SC.meta && codeKey( e ) === SC.key;
	}
	function onKey( e ) {
		if ( e.repeat || ! isShortcut( e ) ) { return; }
		var t = e.composedPath ? e.composedPath()[0] : e.target;
		if ( t && t.classList && t.classList.contains( 'tf-keyrec' ) ) { return; } // recording a new shortcut
		e.preventDefault(); e.stopPropagation();
		toggle();
	}
	window.addEventListener( 'keydown', onKey, true );
	// Builder: keys pressed while the canvas iframe has focus never reach this window.
	function hookFrame( f ) {
		try {
			var w = f.contentWindow;
			if ( ! w || w.__tfPanelKey ) { return; }
			w.__tfPanelKey = true;
			w.addEventListener( 'keydown', onKey, true );
		} catch ( e ) {} // cross-origin frame: ignore
	}
	function hookFrames() { document.querySelectorAll( 'iframe' ).forEach( function ( f ) { hookFrame( f ); f.addEventListener( 'load', function () { hookFrame( f ); } ); } ); }
	if ( ! FRONT ) { setInterval( hookFrames, 2000 ); }

	function label() {
		if ( ! SC ) { return ''; }
		return [ SC.ctrl && 'Ctrl', SC.alt && 'Alt', SC.shift && 'Shift', SC.meta && 'Cmd', SC.key.toUpperCase() ].filter( Boolean ).join( '+' );
	}
	function relabel() {
		if ( ! dock ) { return; }
		var sc = label(), kbd = dock.querySelector( '.tf-kbd' );
		if ( kbd ) { kbd.textContent = sc; kbd.hidden = ! sc; }
		else if ( sc ) { var t = dock.querySelector( '.tf-dock-title' ); if ( t ) { kbd = document.createElement( 'kbd' ); kbd.className = 'tf-kbd'; kbd.textContent = sc; t.appendChild( kbd ); } }
		dock.querySelector( '.tf-dock-toggle' ).title = 'Open TokenForge' + ( sc ? ' (' + sc + ')' : '' );
		var a = document.querySelector( '#wp-admin-bar-tokenforge > a' );
		if ( a ) { a.title = 'TokenForge' + ( sc ? ' (' + sc + ')' : '' ); }
	}

	// Keep the Bricks toolbar (with its own Save button) / the WP admin bar uncovered.
	function topOffset() {
		var tb = document.getElementById( 'bricks-toolbar-top' ) || document.getElementById( 'bricks-toolbar' ) || document.getElementById( 'wpadminbar' );
		return tb ? Math.max( 0, Math.round( tb.getBoundingClientRect().bottom ) ) : 0;
	}

	function apply() {
		if ( ! dock ) { return; }
		var vw = window.innerWidth, vh = window.innerHeight, top = topOffset();
		dock.classList.toggle( 'is-open', !! state.open );
		dock.classList.toggle( 'is-min', !! state.min && 'float' === state.mode );
		dock.classList.toggle( 'no-launcher', ! cfg.launcher );
		dock.className = dock.className.replace( /\bmode-\w+/g, '' ).trim() + ' mode-' + state.mode;
		var s = panel.style;
		if ( 'float' === state.mode ) {
			state.fw = clamp( state.fw, MIN_W, vw - 16 );
			state.fh = clamp( state.fh, MIN_H, vh - 16 );
			state.x = clamp( state.x, 60 - state.fw, vw - 60 );   // always leave a grab-able strip
			state.y = clamp( state.y, 0, vh - 40 );
			s.left = state.x + 'px'; s.top = state.y + 'px'; s.width = state.fw + 'px';
			s.height = state.min ? 'auto' : state.fh + 'px'; s.right = 'auto'; s.bottom = 'auto';
		} else {
			state.w = clamp( state.w, MIN_W, Math.round( vw * 0.9 ) );
			s.top = top + 'px'; s.bottom = '0px'; s.height = 'auto'; s.width = state.w + 'px';
			if ( 'left' === state.mode ) { s.left = '0px'; s.right = 'auto'; } else { s.right = '0px'; s.left = 'auto'; }
		}
	}

	// Bring a floating window fully on-screen (used when floating via button/dblclick).
	function fitFloat() {
		var vw = window.innerWidth, vh = window.innerHeight, top = topOffset();
		state.fw = clamp( state.fw, MIN_W, vw - 16 );
		state.fh = clamp( state.fh, MIN_H, vh - top - 16 );
		state.x = clamp( state.x, 8, vw - state.fw - 8 );
		state.y = clamp( state.y, top + 8, vh - state.fh - 8 );
	}

	function setMode( mode ) {
		if ( 'float' !== mode ) { state.last = mode; state.min = false; }
		else if ( 'float' !== state.mode ) { fitFloat(); }
		state.mode = mode;
		apply(); persist();
	}

	function open() {
		if ( ! dock ) { mount(); }
		state.open = true; state.min = false;
		if ( 'float' === state.mode ) { fitFloat(); }
		apply(); persist();
		if ( ! mounted && window.TokenForgeApp && window.TokenForgeApp.mount ) {
			mounted = true;
			window.TokenForgeApp.mount( root.querySelector( '#tokenforge-app' ), { restUrl: cfg.restUrl, nonce: cfg.nonce, groups: cfg.groups } );
		} else if ( mounted && window.TokenForgeApp && window.TokenForgeApp.refresh ) {
			window.TokenForgeApp.refresh();
		}
	}
	function close() { state.open = false; apply(); persist(); }
	function toggle() { if ( state.open && dock && ! state.min ) { close(); } else { open(); } }
	window.TokenForgeWindow = { open: open, close: close, toggle: toggle };

	// ---- Pointer interactions (drag / resize) ----
	// A full-screen shield sits over iframes while dragging so they can't swallow
	// pointer events.
	function begin( cursor, onMove, onEnd ) {
		shield.style.cursor = cursor;
		dock.classList.add( 'is-dragging' );
		function move( e ) { e.preventDefault(); onMove( e ); }
		function up( e ) {
			window.removeEventListener( 'pointermove', move );
			window.removeEventListener( 'pointerup', up );
			dock.classList.remove( 'is-dragging' );
			hint.className = 'tf-snap-hint';
			if ( onEnd ) { onEnd( e ); }
			apply(); persist();
		}
		window.addEventListener( 'pointermove', move );
		window.addEventListener( 'pointerup', up );
	}

	function startDrag( e ) {
		if ( e.button !== 0 || e.target.closest( 'button, a' ) ) { return; }
		e.preventDefault();
		var r = panel.getBoundingClientRect();
		var relX = ( e.clientX - r.left ) / r.width;
		var offY = e.clientY - r.top;
		var prev = { x: state.x, y: state.y, fw: state.fw };
		if ( 'float' !== state.mode ) {
			// Tear off: become a floating window under the cursor.
			state.fw = Math.min( state.fw || r.width, r.width );
			state.fh = Math.min( state.fh, window.innerHeight - topOffset() - 16 );
			state.mode = 'float';
			dock.classList.remove( 'mode-left', 'mode-right' );
		}
		var snapTo = '';
		begin( 'grabbing', function ( ev ) {
			state.x = Math.round( ev.clientX - relX * state.fw );
			state.y = Math.round( ev.clientY - Math.min( offY, 30 ) );
			snapTo = ev.clientX <= SNAP ? 'left' : ( ev.clientX >= window.innerWidth - SNAP ? 'right' : '' );
			hint.className = 'tf-snap-hint' + ( snapTo ? ' is-' + snapTo : '' );
			if ( snapTo ) { hint.style.width = state.w + 'px'; hint.style.top = topOffset() + 'px'; }
			apply();
		}, function () {
			if ( snapTo ) {
				// Docking: remember the last sensible floating spot, not the edge.
				state.x = prev.x; state.y = prev.y; state.fw = prev.fw;
				setMode( snapTo );
			}
		} );
	}

	function startResize( e ) {
		if ( e.button !== 0 ) { return; }
		e.preventDefault(); e.stopPropagation();
		var dir = e.currentTarget.getAttribute( 'data-dir' );
		var sx = e.clientX, sy = e.clientY;
		var o = { x: state.x, y: state.y, fw: state.fw, fh: state.fh, w: state.w };
		begin( getComputedStyle( e.currentTarget ).cursor, function ( ev ) {
			var dx = ev.clientX - sx, dy = ev.clientY - sy;
			if ( 'right' === state.mode ) { state.w = o.w - dx; }
			else if ( 'left' === state.mode ) { state.w = o.w + dx; }
			else {
				if ( dir.indexOf( 'e' ) > -1 ) { state.fw = o.fw + dx; }
				if ( dir.indexOf( 's' ) > -1 ) { state.fh = o.fh + dy; }
				if ( dir.indexOf( 'w' ) > -1 ) { var nw = Math.max( MIN_W, o.fw - dx ); state.x = o.x + ( o.fw - nw ); state.fw = nw; }
				if ( dir.indexOf( 'n' ) > -1 ) { var nh = Math.max( MIN_H, o.fh - dy ); state.y = o.y + ( o.fh - nh ); state.fh = nh; }
			}
			apply();
		} );
	}

	function mount() {
		if ( dock ) { return; }
		var sc = label();
		dock = document.createElement( 'div' );
		dock.id = 'tf-dock';
		if ( FRONT && document.body.attachShadow ) {
			// Front end: an isolated shadow root with its own copies of the styles.
			host = document.createElement( 'div' );
			host.id = 'tf-dock-host';
			host.setAttribute( 'style', 'all:initial;position:fixed;inset:0;z-index:2147482000;pointer-events:none' );
			root = host.attachShadow( { mode: 'open' } );
			( cfg.css || [] ).forEach( function ( href ) {
				var l = document.createElement( 'link' ); l.rel = 'stylesheet'; l.href = href; root.appendChild( l );
			} );
			var base = document.createElement( 'style' );
			base.textContent = ':host{all:initial}#tf-dock{font-size:13px;line-height:1.4;color:#e7eaf0;text-align:left;letter-spacing:normal}';
			root.appendChild( base );
			root.appendChild( dock );
			document.body.appendChild( host );
		} else {
			root = document;
			document.body.appendChild( dock );
		}
		var tool = function ( act, icon, title ) { return '<button type="button" class="tf-dock-tool" data-act="' + act + '" title="' + title + '" aria-label="' + title + '">' + icon + '</button>'; };
		var rz = [ 'n', 'e', 's', 'w', 'ne', 'nw', 'se', 'sw' ].map( function ( d ) { return '<div class="tf-rz tf-rz-' + d + '" data-dir="' + d + '"></div>'; } ).join( '' );
		dock.innerHTML =
			'<button type="button" class="tf-dock-toggle" title="Open TokenForge' + ( sc ? ' (' + sc + ')' : '' ) + '">' + ICON.logo + '<span>TokenForge</span></button>' +
			'<div class="tf-snap-hint"></div>' +
			'<div class="tf-shield"></div>' +
			'<div class="tf-dock-panel" role="dialog" aria-label="TokenForge">' +
				'<div class="tf-dock-head" title="Drag to move · double-click to dock/float' + ( sc ? ' · ' + sc + ' opens/closes' : '' ) + '">' +
					'<span class="tf-dock-title"><span class="tf-dot"></span>TokenForge' + ( sc ? '<kbd class="tf-kbd">' + sc + '</kbd>' : '' ) + '</span>' +
					'<span class="tf-dock-tools">' +
						tool( 'left', ICON.left, 'Dock left' ) + tool( 'float', ICON.float, 'Float (detach)' ) + tool( 'right', ICON.right, 'Dock right' ) +
						tool( 'min', ICON.min, 'Minimize' ) +
						'<a class="tf-dock-tool" href="' + ( cfg.adminUrl || '#' ) + '" target="_blank" rel="noopener" title="Open in wp-admin" aria-label="Open in wp-admin">' + ICON.ext + '</a>' +
						tool( 'close', ICON.close, 'Close' + ( sc ? ' (' + sc + ')' : '' ) ) +
					'</span>' +
				'</div>' +
				'<div class="tf-dock-body"><div id="tokenforge-app"></div></div>' +
				rz +
			'</div>';
		panel = dock.querySelector( '.tf-dock-panel' );
		shield = dock.querySelector( '.tf-shield' );
		hint = dock.querySelector( '.tf-snap-hint' );

		dock.querySelector( '.tf-dock-toggle' ).addEventListener( 'click', open );
		dock.querySelector( '.tf-dock-head' ).addEventListener( 'pointerdown', startDrag );
		dock.querySelector( '.tf-dock-head' ).addEventListener( 'dblclick', function ( e ) {
			if ( e.target.closest( 'button, a' ) ) { return; }
			setMode( 'float' === state.mode ? ( state.last || 'right' ) : 'float' );
		} );
		dock.querySelectorAll( '.tf-rz' ).forEach( function ( h ) { h.addEventListener( 'pointerdown', startResize ); } );
		dock.querySelector( '.tf-dock-tools' ).addEventListener( 'click', function ( e ) {
			var b = e.target.closest( 'button[data-act]' );
			if ( ! b ) { return; }
			var act = b.getAttribute( 'data-act' );
			if ( 'close' === act ) { close(); }
			else if ( 'min' === act ) { state.min = ! state.min; apply(); persist(); }
			else { setMode( act ); }
		} );
		window.addEventListener( 'resize', function () { if ( state.open ) { apply(); } } );

		apply();
	}

	// Admin bar "TokenForge" opens the window here (Ctrl/Cmd-click still goes to wp-admin).
	function hookAdminBar() {
		var a = document.querySelector( '#wp-admin-bar-tokenforge > a' );
		if ( ! a || a.__tf ) { return; }
		a.__tf = true;
		if ( label() ) { a.title = 'TokenForge (' + label() + ')'; }
		a.addEventListener( 'click', function ( e ) {
			if ( e.ctrlKey || e.metaKey || e.shiftKey || e.button !== 0 ) { return; }
			e.preventDefault();
			toggle();
		} );
	}

	function boot() {
		hookAdminBar();
		// Builder: the launcher is always there. Front end: build the window lazily
		// (first open) unless it was left open or a launcher is wanted.
		if ( ! FRONT || state.open || cfg.launcher ) { mount(); }
		if ( state.open ) { open(); }
	}
	if ( document.readyState === 'loading' ) { document.addEventListener( 'DOMContentLoaded', function () { setTimeout( boot, FRONT ? 0 : 1500 ); } ); }
	else { setTimeout( boot, FRONT ? 0 : 1500 ); }
} )();
