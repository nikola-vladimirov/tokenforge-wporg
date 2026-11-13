/**
 * TokenForge: default unit for the block editor's size fields.
 *
 * WordPress offers units in a fixed order (px first) and an empty field takes the
 * first one. This moves the chosen unit (rem or em) to the front of every list the
 * editor builds with wp.components.__experimentalUseCustomUnits. It runs after
 * wp-components and before wp-block-editor, which reads wp.components once when it loads.
 * Values already saved keep their own unit.
 */
( function () {
	var wp = window.wp;
	var unit = window.TokenForgeUnits && window.TokenForgeUnits.unit;
	var c = wp && wp.components;
	if ( ! unit || ! c || 'function' !== typeof c.__experimentalUseCustomUnits ) {
		return;
	}
	var orig = c.__experimentalUseCustomUnits;
	function first( list ) {
		var i = Array.isArray( list ) ? list.findIndex( function ( u ) { return u && u.value === unit; } ) : -1;
		if ( i < 1 ) {
			return list;
		}
		var out = list.slice();
		out.unshift( out.splice( i, 1 )[ 0 ] );
		return out;
	}
	function useCustomUnits( args ) {
		// A unit the theme's list leaves out (ch, dvh...) is added, so the chosen one is always on offer.
		if ( args && Array.isArray( args.availableUnits ) && args.availableUnits.indexOf( unit ) < 0 ) {
			args = Object.assign( {}, args, { availableUnits: args.availableUnits.concat( unit ) } );
		}
		return first( orig( args ) );
	}
	// The package's exports are fixed getters, so a proxy hands out the wrapped function and everything else unchanged.
	wp.components = new Proxy( c, {
		get: function ( t, k ) {
			return '__experimentalUseCustomUnits' === k ? useCustomUnits : t[ k ];
		},
	} );
	window.TokenForgeUnits.first = first; // for the harness

	// Some fields hard-code px when empty (border width, radius). The first number typed into an
	// empty field still on px switches it to the chosen unit; a unit the user picks is kept.
	var fresh = new WeakMap();
	function unitSelect( el ) {
		for ( var i = 0, e = el; i < 5 && e; i++, e = e.parentElement ) {
			var s = e.querySelector && e.querySelector( 'select.components-unit-control__select' );
			if ( s ) {
				return s;
			}
		}
		return null;
	}
	document.addEventListener( 'focusin', function ( ev ) {
		var inp = ev.target;
		if ( ! ( inp instanceof HTMLInputElement ) || ! inp.closest( '.components-unit-control' ) ) {
			return;
		}
		var sel = unitSelect( inp );
		if ( sel && '' === inp.value && sel.value !== unit ) {
			fresh.set( inp, sel );
		} else {
			fresh.delete( inp );
		}
	}, true );
	document.addEventListener( 'change', function ( ev ) {
		var s = ev.target;
		if ( ev.isTrusted && s && s.classList && s.classList.contains( 'components-unit-control__select' ) ) {
			var w = s.closest( '.components-unit-control' );
			var inp = w && w.querySelector( 'input' );
			if ( inp ) {
				fresh.delete( inp );
			}
		}
	}, true );
	document.addEventListener( 'input', function ( ev ) {
		var inp = ev.target, sel = fresh.get( inp );
		if ( ! sel || '' === inp.value ) {
			return;
		}
		fresh.delete( inp );
		setTimeout( function () {
			var has = sel.isConnected && Array.prototype.some.call( sel.options, function ( o ) { return o.value === unit; } );
			if ( has && sel.value !== unit ) {
				sel.value = unit;
				sel.dispatchEvent( new Event( 'change', { bubbles: true } ) );
			}
		} );
	}, true );
}() );
