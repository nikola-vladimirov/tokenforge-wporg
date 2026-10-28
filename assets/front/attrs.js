/* TokenForge: data-tf-copy, data-tf-tooltip and filter lists (data-tf-filter-group / -filter / -term).
 *
 * Copy: a click copies the value, or the element's own text when the value is empty. The element
 * gets class tf-copied for 2 s with --tf-copied-text (the stylesheet draws it), and one hidden
 * status region says it to screen readers.
 * Tooltip: the stylesheet draws the bubble; this adds the text as the element's description
 * (aria-describedby), lets Esc hide it (class tf-tip-off) until the pointer or focus leaves, and shifts
 * a bubble near the screen edge back into view (--tf-tip-shift; without the script it stays centred).
 * Filter: a button marks itself aria-pressed and this script hides the items that do not match with
 * class tf-filter-off (the stylesheet draws it). Terms are compared as plain words, so any language works.
 *
 * Page text only ever goes in through textContent, an attribute value or a quoted custom property.
 * Original implementation.
 */
( function () {
	'use strict';

	if ( true === window.tokenforgeAttrsOn ) { return; } // loaded twice: the first copy already listens
	window.tokenforgeAttrsOn = true;

	var doc = document;
	var S = window.tokenforgeAttrs || {};
	var HIDE = 'position:absolute;width:1px;height:1px;margin:-1px;padding:0;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0';
	var live = null, tipN = 0;

	function up( el, sel ) { return el && el.closest ? el.closest( sel ) : null; }

	// A control the keyboard already reaches and presses; anything else becomes a button.
	function native( el ) {
		var t = el.tagName;
		return 'BUTTON' === t || 'INPUT' === t || 'SELECT' === t || 'TEXTAREA' === t || 'SUMMARY' === t || ( 'A' === t && el.hasAttribute( 'href' ) );
	}
	function buttonize( el ) {
		if ( native( el ) ) { return; }
		if ( ! el.hasAttribute( 'role' ) ) { el.setAttribute( 'role', 'button' ); }
		if ( ! el.hasAttribute( 'tabindex' ) ) { el.setAttribute( 'tabindex', '0' ); }
	}

	function region() {
		if ( ! live ) {
			live = doc.createElement( 'div' );
			live.setAttribute( 'role', 'status' );
			live.style.cssText = HIDE;
			doc.body.appendChild( live );
		}
		return live;
	}
	function say( text ) {
		var r = region();
		r.textContent = '';
		setTimeout( function () { r.textContent = text; }, 50 ); // a fresh change, so the same words are said again
	}

	// --- Copy ---------------------------------------------------------------
	function fallbackCopy( text ) {
		var ta = doc.createElement( 'textarea' ), was = doc.activeElement, ok = false;
		ta.value = text;
		ta.setAttribute( 'readonly', '' );
		ta.style.cssText = 'position:fixed;top:0;left:0;opacity:0;pointer-events:none';
		doc.body.appendChild( ta );
		ta.focus();
		ta.select();
		try { ok = doc.execCommand( 'copy' ); } catch ( e ) { ok = false; }
		doc.body.removeChild( ta );
		if ( was && was.focus ) { was.focus( { preventScroll: true } ); }
		return ok;
	}
	function writeText( text, done ) {
		if ( navigator.clipboard && navigator.clipboard.writeText ) {
			navigator.clipboard.writeText( text ).then( function () { done( true ); }, function () { done( fallbackCopy( text ) ); } );
		} else {
			done( fallbackCopy( text ) );
		}
	}
	function cssString( s ) { return '"' + String( s ).replace( /[\\"]/g, '\\$&' ).replace( /[\n\r\f]/g, ' ' ) + '"'; }
	function copy( host ) {
		var v = host.getAttribute( 'data-tf-copy' );
		var text = v ? v : ( host.textContent || '' ).replace( /\s+/g, ' ' ).trim();
		writeText( text, function ( ok ) {
			var msg = ok ? ( S.copied || 'Copied' ) : ( S.copyFailed || 'Could not copy' );
			clearTimeout( host.tfCopyTimer );
			host.style.setProperty( '--tf-copied-text', cssString( msg ) );
			host.classList.add( 'tf-copied' );
			place( host );
			host.tfCopyTimer = setTimeout( function () {
				host.classList.remove( 'tf-copied' );
				host.style.removeProperty( '--tf-copied-text' );
				place( host ); // a still hovered tooltip comes back with its own width
			}, 2000 );
			say( msg );
		} );
	}

	// --- Tooltip ------------------------------------------------------------
	function tip( host ) {
		var text = host.getAttribute( 'data-tf-tooltip' ) || '', span = host.tfTip, ids, id;
		if ( ! text && ! span ) { return; } // an empty tooltip draws and says nothing
		if ( ! span ) {
			do { id = 'tf-tip-' + ( ++tipN ); } while ( doc.getElementById( id ) );
			span = doc.createElement( 'span' );
			span.id = id;
			span.hidden = true; // still read as the description through aria-describedby
			doc.body.appendChild( span );
			host.tfTip = span;
		}
		if ( span.textContent !== text ) { span.textContent = text; }
		ids = ( host.getAttribute( 'aria-describedby' ) || '' ).split( /\s+/ ).filter( Boolean );
		if ( ids.indexOf( span.id ) < 0 ) {
			ids.push( span.id );
			host.setAttribute( 'aria-describedby', ids.join( ' ' ) );
		}
	}
	// The stylesheet centres the bubble on the host; this shifts it (--tf-tip-shift) so it stays 8 px inside the viewport.
	function place( host ) {
		var w = parseFloat( window.getComputedStyle( host, '::before' ).width ), r, k, x, vw, s = 0;
		if ( ! ( w > 0 ) ) { return; } // no bubble drawn right now
		r = host.getBoundingClientRect();
		// The width and the shift are in the host's own px, the rect in viewport px (a scaled or zoomed box); offsetWidth is whole px, so under 1 px apart is rounding.
		k = host.offsetWidth && Math.abs( r.width - host.offsetWidth ) >= 1 ? r.width / host.offsetWidth : 1;
		w *= k;
		x = r.left + r.width / 2 - w / 2;
		vw = doc.documentElement.clientWidth;
		if ( x < 8 ) { s = 8 - x; } else if ( x + w > vw - 8 ) { s = vw - 8 - x - w; }
		if ( s ) { host.style.setProperty( '--tf-tip-shift', Math.round( s / k ) + 'px' ); } else { host.style.setProperty( '--tf-tip-shift', '0px' ); } // not removed: a tooltip inside another one would inherit the outer one's shift
	}
	function hovered( el ) { try { return el.matches( ':hover' ); } catch ( e ) { return false; } }
	function tipOff( host ) {
		host.classList.add( 'tf-tip-off' );
		if ( host.tfTipWatch ) { return; }
		host.tfTipWatch = true;
		// Back on once neither the pointer nor the focus is on it.
		host.addEventListener( 'mouseleave', function () {
			if ( ! host.contains( doc.activeElement ) ) { host.classList.remove( 'tf-tip-off' ); }
		} );
		host.addEventListener( 'focusout', function ( e ) {
			if ( ! ( e.relatedTarget && host.contains( e.relatedTarget ) ) && ! hovered( host ) ) { host.classList.remove( 'tf-tip-off' ); }
		} );
	}
	function escape() {
		var seen = [], a = up( doc.activeElement, '[data-tf-tooltip]' ), list;
		while ( a ) { seen.push( a ); a = up( a.parentElement, '[data-tf-tooltip]' ); }
		try { list = doc.querySelectorAll( '[data-tf-tooltip]:hover' ); } catch ( e ) { list = []; }
		Array.prototype.forEach.call( list, function ( el ) { seen.push( el ); } );
		seen.forEach( tipOff );
	}

	// --- Filter -------------------------------------------------------------
	function words( v ) { return String( v || '' ).split( /[ \t\n\f\r]+/ ); } // the ~= rule: whole words, ASCII spaces
	// shortcut: items added after load are filtered on the next click, not at once; render lists on the server.
	function filter( btn ) {
		var group = up( btn, '[data-tf-filter-group]' ), term, reset, own;
		if ( ! group ) { return; }
		term = btn.getAttribute( 'data-tf-filter' ) || '';
		if ( ! term ) { return; } // an empty term would hide every item
		reset = 'all' === term || 'true' === btn.getAttribute( 'aria-pressed' );
		// Only this group's own buttons and items: a group nested inside keeps its own choice (the inner box can be an item here).
		own = function ( el ) { return up( el.parentElement, '[data-tf-filter-group]' ) === group; };
		Array.prototype.forEach.call( group.querySelectorAll( '[data-tf-filter]' ), function ( b ) {
			var t = b.getAttribute( 'data-tf-filter' );
			if ( own( b ) ) { b.setAttribute( 'aria-pressed', ( reset ? 'all' === t : b === btn ) ? 'true' : 'false' ); }
		} );
		Array.prototype.forEach.call( group.querySelectorAll( '[data-tf-term]' ), function ( it ) {
			if ( own( it ) ) { it.classList.toggle( 'tf-filter-off', ! reset && words( it.getAttribute( 'data-tf-term' ) ).indexOf( term ) < 0 ); }
		} );
	}

	// --- Events (delegated, so elements added later work too) ----------------
	function act( el ) {
		var f = up( el, '[data-tf-filter]' ), c = up( el, '[data-tf-copy]' ), inner;
		if ( f && up( f, '[data-tf-filter-group]' ) ) { filter( f ); return f; }
		if ( c ) {
			// A link or button inside the copy host keeps its own job (so a hidden value cannot swallow it).
			inner = up( el, 'a[href],button,input,select,textarea,summary' );
			if ( inner && inner !== c && c.contains( inner ) ) { return null; }
			copy( c );
			return c;
		}
		return null;
	}
	doc.addEventListener( 'click', function ( e ) {
		if ( act( e.target ) ) { e.preventDefault(); } // a copy or filter control does not navigate or submit
	} );
	doc.addEventListener( 'keydown', function ( e ) {
		var k = e.key, el = e.target;
		if ( 'Escape' === k || 'Esc' === k ) { escape(); return; }
		if ( ( 'Enter' === k || ' ' === k || 'Spacebar' === k ) && el && el.hasAttribute && ( el.hasAttribute( 'data-tf-copy' ) || el.hasAttribute( 'data-tf-filter' ) ) && ! native( el ) ) {
			e.preventDefault();
			act( el );
		}
	} );
	function lateTip( e ) {
		var h = up( e.target, '[data-tf-tooltip]' );
		if ( h ) { tip( h ); }
		for ( ; h; h = up( h.parentElement, '[data-tf-tooltip]' ) ) { place( h ); } // an outer host shows its bubble too
	}
	doc.addEventListener( 'mouseover', lateTip );
	doc.addEventListener( 'focusin', lateTip );

	function init() {
		Array.prototype.forEach.call( doc.querySelectorAll( '[data-tf-tooltip]' ), tip );
		Array.prototype.forEach.call( doc.querySelectorAll( '[data-tf-copy]' ), buttonize );
		if ( doc.querySelector( '[data-tf-copy]' ) ) { region(); } // in the page before the first copy, so screen readers pick the change up
		Array.prototype.forEach.call( doc.querySelectorAll( '[data-tf-filter-group]' ), function ( g ) {
			Array.prototype.forEach.call( g.querySelectorAll( '[data-tf-filter]' ), function ( b ) {
				buttonize( b );
				if ( 'A' === b.tagName && ! b.hasAttribute( 'role' ) ) { b.setAttribute( 'role', 'button' ); } // aria-pressed needs a button role
				b.setAttribute( 'aria-pressed', 'all' === b.getAttribute( 'data-tf-filter' ) ? 'true' : 'false' );
			} );
		} );
	}
	if ( 'loading' === doc.readyState ) { doc.addEventListener( 'DOMContentLoaded', init ); } else { init(); }
}() );
