/* TokenForge: preview the builder canvas in light or dark while building.
 *
 * A small button in the builder window, beside TokenForge's own button, switches the canvas between the
 * site's light and dark scheme: it sets scheme--light / scheme--dark and data-scheme on the canvas page's
 * root element, as the front end does. Preview only: nothing goes into the page, the settings or the
 * visitor's stored choice, and a reloaded canvas gets the preview back. Loaded only while dark mode is on
 * (Options: Builder, Dark mode preview). The canvas comes from the builder adapter (Bricks: its frame).
 */
( function () {
	'use strict';
	if ( window.__tfSchemePreview ) { return; }
	window.__tfSchemePreview = true;
	var I18N = window.wp && window.wp.i18n;
	var __ = I18N ? I18N.__ : function ( s ) { return s; };
	var forced = null; // 'light' | 'dark' after a click; null = the canvas as the site shows it.
	var seen = null, host = null, btn = null;

	function canvasDoc() {
		try {
			var B = window.TokenForgeBuilder;
			var d = B && B.canvasDoc ? B.canvasDoc() : document.getElementById( 'bricks-builder-iframe' ).contentDocument;
			return d && d.documentElement ? d : null;
		} catch ( e ) { return null; }
	}

	// The scheme the canvas shows now, read the way the front-end toggle reads it.
	function shown( doc ) {
		var de = doc.documentElement, w = doc.defaultView, a = de.getAttribute( 'data-scheme' );
		if ( 'light' === a || 'dark' === a ) { return a; }
		// "light", "dark only" …: one scheme set; "light dark" (or nothing) follows the system.
		var cs = w && w.getComputedStyle ? String( w.getComputedStyle( de ).colorScheme || '' ) : '';
		var l = /\blight\b/.test( cs ), d = /\bdark\b/.test( cs );
		if ( l !== d ) { return d ? 'dark' : 'light'; }
		return w && w.matchMedia && w.matchMedia( '(prefers-color-scheme: dark)' ).matches ? 'dark' : 'light';
	}

	function apply( doc ) {
		var de = doc.documentElement;
		de.classList.toggle( 'scheme--dark', 'dark' === forced );
		de.classList.toggle( 'scheme--light', 'light' === forced );
		de.setAttribute( 'data-scheme', forced );
	}

	// Beside TokenForge's button (the launcher in this window, or the button in the canvas), else in the canvas corner.
	function place( doc ) {
		var cw = doc.defaultView, f = cw && cw.frameElement, fr = f && f.getBoundingClientRect();
		// A frame with no size yet (the builder still laying out or zooming in): the window instead.
		if ( ! fr || fr.width < 100 || fr.height < 100 ) {
			f = null;
			fr = { left: 0, top: 0, right: window.innerWidth, bottom: window.innerHeight };
		}
		var k = f && cw.innerWidth ? fr.width / cw.innerWidth : 1; // a zoomed canvas is scaled
		var me = btn.getBoundingClientRect(), near = [];
		var x = Math.min( fr.right, window.innerWidth ) - 16, y = Math.min( fr.bottom, window.innerHeight ) - 16;
		var l = document.querySelector( '#tf-dock .tf-dock-toggle' ), c = f && doc.getElementById( 'tf-canvas-button' );
		if ( l ) { near.push( l.getBoundingClientRect() ); }
		if ( c ) {
			var r = c.getBoundingClientRect();
			near.push( { left: fr.left + r.left * k, right: fr.left + r.right * k, top: fr.top + r.top * k, bottom: fr.top + r.bottom * k, width: r.width } );
		}
		near.sort( function ( a, b ) { return b.right - a.right; } ).forEach( function ( r ) {
			if ( r.width && r.right > x - me.width - 8 && r.left < x && r.bottom > y - me.height && r.top < y ) {
				x = r.left - 8;
				y = r.bottom;
			}
		} );
		host.style.right = Math.max( 0, window.innerWidth - x ) + 'px';
		host.style.bottom = Math.max( 0, window.innerHeight - y ) + 'px';
	}

	function sync() {
		var doc = canvasDoc();
		host.style.display = doc ? '' : 'none';
		if ( ! doc ) { return; }
		if ( forced && doc !== seen ) { apply( doc ); } // a reloaded canvas gets the preview back
		seen = doc;
		btn.setAttribute( 'aria-pressed', 'dark' === ( forced || shown( doc ) ) ? 'true' : 'false' );
		place( doc );
	}

	function mount() {
		host = document.createElement( 'div' );
		host.id = 'tf-scheme-preview';
		host.setAttribute( 'style', 'all:initial;position:fixed;right:16px;bottom:16px;z-index:99999' ); // under the TokenForge window (100000)
		var root = host.attachShadow ? host.attachShadow( { mode: 'open' } ) : host;
		var style = document.createElement( 'style' );
		style.textContent = 'button{display:grid;place-items:center;inline-size:34px;block-size:34px;padding:0;border:1px solid #343d46;border-radius:4px;background:#23292f;color:#e2e6e9;cursor:pointer;box-shadow:0 4px 12px rgba(0,0,0,.35)}'
			+ 'button:hover{border-color:#ffd54d}button:focus-visible{outline:2px solid #ffd54d;outline-offset:2px}button[aria-pressed="true"]{border-color:#ffd54d;color:#ffd54d}'
			+ 'svg{inline-size:16px;block-size:16px;grid-area:1/1}button[aria-pressed="true"] .m,button:not([aria-pressed="true"]) .s{display:none}';
		btn = document.createElement( 'button' );
		btn.type = 'button';
		btn.setAttribute( 'aria-label', __( 'Preview dark mode', 'tokenforge' ) );
		btn.title = __( 'Preview dark mode in the canvas (only you see it, nothing is saved)', 'tokenforge' );
		btn.setAttribute( 'aria-pressed', 'false' );
		btn.innerHTML = '<svg class="m" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/></svg>'
			+ '<svg class="s" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/></svg>';
		btn.addEventListener( 'click', function () {
			var doc = canvasDoc();
			if ( ! doc ) { return; }
			forced = 'dark' === ( forced || shown( doc ) ) ? 'light' : 'dark';
			apply( doc );
			seen = doc;
			sync();
		} );
		root.appendChild( style );
		root.appendChild( btn );
		document.body.appendChild( host );
		sync();
		// The canvas loads late and reloads: follow it.
		setInterval( sync, 1000 );
		window.addEventListener( 'resize', sync );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', mount );
	} else {
		mount();
	}
}() );
