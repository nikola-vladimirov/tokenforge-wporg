/* TokenForge: builder input helper (opt-in, Bricks).
 * On blur or Enter in a builder style input: a TokenForge variable typed by name (space-m or --space-m)
 * becomes var(--space-m), and maths with a variable (var(--space-m) * 2) gets calc(). Anything else is left
 * alone. The value goes back through the input's own input event, the same path as typing, so the builder's
 * state and its undo history see it.
 */
( function () {
	'use strict';
	if ( window.__tokenforgeInput ) { return; } // load once
	window.__tokenforgeInput = true;

	var known = {};
	( ( window.TokenForgeInput || {} ).vars || [] ).forEach( function ( n ) { known[ n ] = true; } );

	// Style controls when the builder's control definition is out of reach.
	var STYLE_TYPES = [ 'number', 'dimensions', 'spacing', 'typography', 'border', 'box-shadow', 'text-shadow' ];

	function unref( v ) { return ( v && v.__v_isRef ) ? v.value : v; }
	function gp() { var r = document.querySelector( 'div.brx-body.main' ); return r && r.__vue_app__ ? r.__vue_app__.config.globalProperties : null; }

	// Maths at the top level: * or /, or + and - with spaces around them (as calc() needs), between plain operands.
	function isMath( s ) {
		var depth = 0, ops = 0, tok = '', toks = [];
		for ( var i = 0; i < s.length; i++ ) {
			var c = s[ i ];
			if ( '(' === c ) { depth++; } else if ( ')' === c ) { depth--; }
			if ( 0 === depth && ( '*' === c || '/' === c || ( ( '+' === c || '-' === c ) && /\s/.test( s[ i - 1 ] || '' ) && /\s/.test( s[ i + 1 ] || '' ) ) ) ) {
				toks.push( tok ); tok = ''; ops++;
				continue;
			}
			tok += c;
		}
		toks.push( tok );
		return 0 === depth && ops > 0 && toks.every( function ( t ) { return /^(-?[\d.]+[a-z%]*|[a-z-]+\(.*\))$/i.test( t.trim() ); } );
	}

	function normalize( raw, key ) {
		var v = String( raw ).trim();
		if ( /^--[\w-]+$/.test( v ) ) { return known[ v.slice( 2 ) ] ? 'var(' + v + ')' : raw; }
		// A bare name needs a dash (space-m), so plain words such as auto or white stay as typed.
		if ( /^[a-z][\w]*(-[\w]+)+$/i.test( v ) ) { return known[ v ] ? 'var(--' + v + ')' : raw; }
		var out = v.replace( /(^|[^\w-])--([\w-]+)/g, function ( all, pre, n, off, str ) {
			return known[ n ] && ! /var\(\s*$/.test( str.slice( 0, off + pre.length ) ) ? pre + 'var(--' + n + ')' : all;
		} );
		// Grid fields use '/' between lines (1 / var(--x)), which calc() would break.
		if ( /var\(--/.test( out ) && isMath( out ) && ! /grid/i.test( key || '' ) ) { return 'calc(' + out + ')'; }
		return out === v ? raw : out;
	}

	// The builder's own rule: a control takes variables when it says so, or when it writes CSS.
	function isStyleInput( input ) {
		if ( ! input || 'INPUT' !== input.tagName || ( input.type && 'text' !== input.type ) ) { return false; }
		var ctl = input.closest( '#bricks-panel [data-control], .bricks-control-popup [data-control], .bricks-popup [data-control]' );
		if ( ! ctl || input.closest( '#bricks-panel-header, #bricks-panel-element-classes' ) ) { return false; }
		var ck = input.closest( '[data-controlkey]' ), key = ck && ck.getAttribute( 'data-controlkey' );
		var g = gp(), el = g && unref( g.$_activeElement ), data = window.bricksData || {};
		var def = el && key && data.elements && data.elements[ el.name ] && data.elements[ el.name ].controls && data.elements[ el.name ].controls[ key ];
		if ( def ) { return Object.prototype.hasOwnProperty.call( def, 'hasVariables' ) ? !! def.hasVariables : !! def.css; }
		return STYLE_TYPES.indexOf( ctl.getAttribute( 'data-control' ) ) > -1;
	}

	function apply( input ) {
		var ck = input.closest( '[data-controlkey]' );
		var next = normalize( input.value, ck && ck.getAttribute( 'data-controlkey' ) );
		if ( next === input.value ) { return; }
		Object.getOwnPropertyDescriptor( window.HTMLInputElement.prototype, 'value' ).set.call( input, next );
		input.dispatchEvent( new Event( 'input', { bubbles: true } ) );
		input.dispatchEvent( new Event( 'change', { bubbles: true } ) );
	}

	// Capture phase: runs before the builder's own Enter and blur handlers, so they see the finished value.
	document.addEventListener( 'keydown', function ( e ) {
		if ( 'Enter' !== e.key || e.isComposing || e.shiftKey || e.ctrlKey || e.metaKey || e.altKey || ! isStyleInput( e.target ) ) { return; }
		var ctl = e.target.closest( '[data-control]' );
		if ( ctl && ctl.querySelector( '.variable-picker-button.open' ) ) { return; } // Enter picks from the builder's variable list.
		apply( e.target );
	}, true );
	document.addEventListener( 'focusout', function ( e ) {
		if ( isStyleInput( e.target ) ) { apply( e.target ); }
	}, true );
} )();
