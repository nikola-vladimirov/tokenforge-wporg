/* TokenForge: canvas badges (builder shell).
 *
 * Two switches in the Canvas panel, both off by default:
 *   canvas_headings  a badge with the level (h1 to h6) on every heading
 *   canvas_focus     a number on every element Tab reaches, in tab order
 *
 * The data comes from TokenForge.check.elementTree() (check-engine.js). Badges are
 * decoration for sighted users: aria-hidden, data-tf-ui (the engine and axe skip
 * them), pointer-events none. They live in ONE layer appended to the canvas body,
 * placed from each node's rectangle, with inline styles only: no wrapper, no
 * inserted node inside the user's elements, no style tag, nothing on the user's
 * elements. Switching both off deletes the layer and disconnects every observer.
 */
( function () {
	'use strict';
	if ( window.__tfBadges ) { return; }
	window.__tfBadges = true;

	var enh = window.TokenForgeEnh || {};
	var want = { headings: !! enh.canvas_headings, focus: !! enh.canvas_focus };
	var H = 16;      // badge height in px
	var DELAY = 80;  // ms: many changes give one redraw
	var MAX = 400;   // badges per kind, so a huge page stays cheap
	var s = { doc: null, win: null, layer: null, mo: null, ro: null, timer: 0, sig: '', ifr: null };

	function canvasIframe() { return document.getElementById( 'bricks-builder-iframe' ); }
	function canvasDoc() {
		var f = canvasIframe();
		try { return f && f.contentDocument; } catch ( e ) { return null; }
	}
	function engine() {
		var c = window.TokenForge && window.TokenForge.check;
		return c && 'function' === typeof c.elementTree ? c : null;
	}

	function schedule() {
		if ( s.timer ) { return; }
		s.timer = setTimeout( function () { s.timer = 0; draw(); }, DELAY );
	}

	// Ignore what our own layer does to the canvas.
	function mine( r ) {
		var L = s.layer;
		if ( ! L ) { return false; }
		if ( r.target === L || L.contains( r.target ) ) { return true; }
		return 'childList' === r.type && 1 === r.addedNodes.length && r.addedNodes[ 0 ] === L && ! r.removedNodes.length;
	}
	function onMutations( list ) {
		for ( var i = 0; i < list.length; i++ ) {
			if ( ! mine( list[ i ] ) ) { schedule(); return; }
		}
	}

	function bind( doc ) {
		var win = doc.defaultView;
		s.doc = doc;
		s.win = win;
		win.addEventListener( 'resize', schedule );
		doc.addEventListener( 'scroll', schedule, { capture: true, passive: true } );
		doc.addEventListener( 'load', schedule, true ); // images and frames finishing
		var MO = win.MutationObserver || window.MutationObserver;
		if ( MO ) {
			s.mo = new MO( onMutations );
			s.mo.observe( doc.documentElement, { childList: true, subtree: true, characterData: true, attributes: true, attributeFilter: [ 'class', 'style', 'hidden', 'tabindex', 'href', 'disabled', 'role', 'aria-level' ] } );
		}
		if ( win.ResizeObserver ) {
			s.ro = new win.ResizeObserver( schedule );
			s.ro.observe( doc.documentElement );
		}
	}
	function unbind() {
		if ( s.mo ) { s.mo.disconnect(); s.mo = null; }
		if ( s.ro ) { s.ro.disconnect(); s.ro = null; }
		if ( s.doc ) {
			try {
				s.win.removeEventListener( 'resize', schedule );
				s.doc.removeEventListener( 'scroll', schedule, true );
				s.doc.removeEventListener( 'load', schedule, true );
			} catch ( e ) {}
		}
		s.doc = s.win = null;
	}
	function clearLayer() {
		if ( s.layer ) {
			try { if ( s.layer.parentNode ) { s.layer.parentNode.removeChild( s.layer ); } } catch ( e ) {}
		}
		s.layer = null;
		s.sig = '';
	}
	function teardown() {
		if ( s.timer ) { clearTimeout( s.timer ); s.timer = 0; }
		clearLayer();
		unbind();
		if ( s.ifr ) { s.ifr.removeEventListener( 'load', onFrameLoad ); s.ifr = null; }
	}
	function onFrameLoad() { unbind(); clearLayer(); schedule(); }

	function ensureLayer( doc ) {
		if ( s.layer && s.layer.parentNode === doc.body ) { return s.layer; }
		clearLayer();
		var L = doc.createElement( 'div' );
		L.id = 'tf-canvas-badges';
		L.setAttribute( 'data-tf-ui', '' );
		L.setAttribute( 'aria-hidden', 'true' );
		L.style.cssText = 'position:absolute;left:0;top:0;width:0;height:0;margin:0;padding:0;border:0;overflow:visible;display:block;pointer-events:none;z-index:2147481000;background:none';
		doc.body.appendChild( L );
		s.layer = L;
		return L;
	}

	var LOOK = {
		heading: 'background:#23292f;color:#ffd54d;box-shadow:0 0 0 1px #ffd54d,0 0 0 2px #23292f',
		warn: 'background:#b3261e;color:#fff;box-shadow:0 0 0 1px #fff,0 0 0 2px #23292f',
		focus: 'background:#ffd54d;color:#23292f;box-shadow:0 0 0 1px #23292f,0 0 0 2px #fff'
	};

	function box( node ) {
		try {
			var r = node.getBoundingClientRect();
			return r.width || r.height ? r : null;
		} catch ( e ) { return null; }
	}

	// Collect badges: { kind, text, look, x, y, right }. x/y are relative to the layer.
	function collect( tree, o ) {
		var out = [], placed = [];
		function put( kind, text, look, r, right ) {
			var w = 8 + 7 * text.length;
			var x = Math.round( ( right ? r.right : r.left ) - o.left );
			var y = Math.round( r.top - o.top - H );
			if ( y < 0 ) { y = Math.round( r.top - o.top ); } // at the very top: inside the corner
			for ( var n = 0; n < 8; n++ ) { // a collision moves the later badge down
				var x0 = right ? x - w : x, hit = false;
				for ( var i = 0; i < placed.length; i++ ) {
					var p = placed[ i ];
					if ( x0 < p[ 2 ] && x0 + w > p[ 0 ] && y < p[ 3 ] && y + H > p[ 1 ] ) { hit = true; break; }
				}
				if ( ! hit ) { break; }
				y += H + 1;
			}
			placed.push( [ right ? x - w : x, y, ( right ? x : x + w ), y + H ] );
			out.push( { kind: kind, text: text, look: look, x: x, y: y, right: right } );
		}
		var prev = 0, h1 = 0;
		( want.headings ? tree.headings || [] : [] ).slice( 0, MAX ).forEach( function ( h ) {
			var warn = ( 1 === h.level && ++h1 > 1 ) || ( prev && h.level > prev + 1 );
			prev = h.level;
			var r = h.node && box( h.node );
			if ( r ) { put( 'heading', 'h' + h.level + ( warn ? ' !' : '' ), warn ? LOOK.warn : LOOK.heading, r, false ); }
		} );
		( want.focus ? tree.focus || [] : [] ).slice( 0, MAX ).forEach( function ( f ) {
			var r = f.node && box( f.node );
			if ( r ) { put( 'focus', String( f.order ), LOOK.focus, r, true ); }
		} );
		return out;
	}

	function draw() {
		if ( ! want.headings && ! want.focus ) { teardown(); return; }
		var doc = canvasDoc();
		if ( ! doc || ! doc.body ) { unbind(); clearLayer(); return; }
		if ( doc !== s.doc ) { unbind(); clearLayer(); bind( doc ); }
		var eng = engine();
		var tree = eng && eng.elementTree();
		if ( ! tree || ! tree.ok ) { clearLayer(); return; }
		var L = ensureLayer( doc );
		var o = L.getBoundingClientRect(); // the layer sits at the page origin, whatever the body's position
		var items = collect( tree, o );
		var sig = items.map( function ( b ) { return b.kind + b.text + ',' + b.x + ',' + b.y; } ).join( '|' );
		if ( sig === s.sig && L.childNodes.length === items.length ) { return; }
		s.sig = sig;
		while ( L.firstChild ) { L.removeChild( L.firstChild ); }
		items.forEach( function ( b ) {
			var e = doc.createElement( 'span' );
			e.setAttribute( 'data-tf-ui', '' );
			e.setAttribute( 'data-tf-badge', b.kind );
			e.setAttribute( 'aria-hidden', 'true' );
			e.textContent = b.text;
			e.style.cssText = 'position:absolute;left:' + b.x + 'px;top:' + b.y + 'px;' + ( b.right ? 'transform:translateX(-100%);' : '' )
				+ 'margin:0;padding:0 4px;box-sizing:border-box;height:' + H + 'px;border:0;border-radius:3px;outline:1px solid transparent;'
				+ 'font:700 11px/' + H + 'px ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;letter-spacing:0;text-transform:none;white-space:nowrap;'
				+ 'user-select:none;pointer-events:none;' + b.look;
			L.appendChild( e );
		} );
	}

	function apply() {
		if ( ! want.headings && ! want.focus ) { teardown(); return; }
		var f = canvasIframe();
		if ( f && f !== s.ifr ) {
			if ( s.ifr ) { s.ifr.removeEventListener( 'load', onFrameLoad ); }
			s.ifr = f;
			f.addEventListener( 'load', onFrameLoad );
		}
		draw();
	}

	var api = {
		set: function ( o ) {
			if ( o && 'headings' in o ) { want.headings = !! o.headings; }
			if ( o && 'focus' in o ) { want.focus = !! o.focus; }
			s.sig = '';
			apply();
		},
		refresh: schedule,
		state: function () { return { headings: want.headings, focus: want.focus, drawn: s.layer ? s.layer.childNodes.length : 0 }; }
	};
	window.TokenForge = window.TokenForge || {};
	window.TokenForge.badges = api;

	// A switch saved in the dashboard applies right away, no reload.
	window.addEventListener( 'tokenforge:settings', function ( e ) {
		if ( e.detail && e.detail.badges ) { api.set( e.detail.badges ); }
	} );

	apply();
} )();
