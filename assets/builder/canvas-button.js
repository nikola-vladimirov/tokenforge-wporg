/* TokenForge: a TokenForge button in the builder canvas while editing
 * (Options, TokenForge window, "Show in builder canvas while editing").
 *
 * The canvas is a frame inside the builder. The button opens the one
 * TokenForge window of the builder around it, so there is never a second
 * copy of the dashboard. It lives in a shadow root, so the page's own CSS
 * can't restyle it, and it is only printed for administrators.
 */
( function () {
	'use strict';
	if ( window.top === window || window.__tfCanvasButton ) { return; }
	window.__tfCanvasButton = true;
	var cfg = window.TokenForgeCanvas || {};

	function builderWindow() {
		try { return window.parent && window.parent.TokenForgeWindow; } catch ( e ) { return null; }
	}

	function mount() {
		if ( document.getElementById( 'tf-canvas-button' ) ) { return; }
		var host = document.createElement( 'div' );
		host.id = 'tf-canvas-button';
		host.setAttribute( 'style', 'all:initial;position:fixed;right:16px;bottom:16px;z-index:2147482000' );
		var root = host.attachShadow ? host.attachShadow( { mode: 'open' } ) : host;
		var style = document.createElement( 'style' );
		style.textContent = 'button{display:inline-flex;align-items:center;gap:7px;padding:8px 14px;border:1px solid #343d46;border-radius:4px;background:#23292f;color:#e2e6e9;font:600 12px/1.2 system-ui,-apple-system,"Segoe UI",sans-serif;cursor:pointer;box-shadow:0 4px 12px rgba(0,0,0,.35)}'
			+ 'button:hover{border-color:#ffd54d}button:focus-visible{outline:2px solid #ffd54d;outline-offset:2px}svg{width:15px;height:15px}';
		var b = document.createElement( 'button' );
		b.type = 'button';
		b.title = cfg.title || 'Open TokenForge';
		b.setAttribute( 'aria-label', cfg.title || 'Open TokenForge' );
		b.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="4" width="7" height="7" rx="1"/><rect x="14" y="4" width="7" height="7" rx="1"/><rect x="3" y="13" width="7" height="7" rx="1"/><rect x="14" y="13" width="7" height="7" rx="1"/></svg><span>TokenForge</span>';
		// The builder selects whatever is clicked in the canvas: keep this click to ourselves.
		[ 'mousedown', 'pointerdown', 'mouseup' ].forEach( function ( type ) {
			b.addEventListener( type, function ( e ) { e.stopPropagation(); } );
		} );
		b.addEventListener( 'click', function ( e ) {
			e.preventDefault();
			e.stopPropagation();
			var w = builderWindow();
			if ( w && w.toggle ) { w.toggle(); return; }
			if ( cfg.adminUrl ) { window.open( cfg.adminUrl, '_blank', 'noopener' ); }
		} );
		root.appendChild( style );
		root.appendChild( b );
		document.body.appendChild( host );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', mount );
	} else {
		mount();
	}
} )();
