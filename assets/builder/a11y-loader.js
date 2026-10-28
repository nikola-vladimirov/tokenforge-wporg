/**
 * On-demand axe-core loader for the builder.
 *
 * TokenForge.a11y.loadAxe( win )          -> Promise<axe>   injects axe into win once, cached per window.
 * TokenForge.a11y.runAxe( win, ctx, opt ) -> Promise<results>  loadAxe + win.axe.run( ctx || win.document, opt ).
 * Nothing runs until one of these is called. Keys come from TokenForgePanel.axeUrl / axeVersion.
 */
( function () {
	'use strict';
	var cache = typeof WeakMap === 'function' ? new WeakMap() : null;

	function url() {
		var c = window.TokenForgePanel || {};
		return c.axeUrl || '';
	}

	function loadAxe( win ) {
		win = win || window;
		if ( cache && cache.has( win ) ) {
			return cache.get( win );
		}
		var p = new Promise( function ( resolve, reject ) {
			var src = url();
			if ( ! src ) {
				reject( new Error( 'TokenForge: the axe-core URL is missing (TokenForgePanel.axeUrl).' ) );
				return;
			}
			if ( win.axe && win.axe.run ) {
				resolve( win.axe );
				return;
			}
			var doc = win.document;
			var s = doc.createElement( 'script' );
			s.src = src;
			s.async = true;
			s.onload = function () {
				win.axe && win.axe.run ? resolve( win.axe ) : reject( new Error( 'TokenForge: axe-core loaded but axe is not defined in that window.' ) );
			};
			s.onerror = function () {
				reject( new Error( 'TokenForge: could not load axe-core from ' + src ) );
			};
			( doc.head || doc.documentElement ).appendChild( s );
		} );
		// A failed load is not cached, so a later call can retry.
		p.catch( function () {
			cache && cache.delete( win );
		} );
		cache && cache.set( win, p );
		return p;
	}

	function runAxe( win, context, options ) {
		win = win || window;
		return loadAxe( win ).then( function ( axe ) {
			return axe.run( context || win.document, options || {} );
		} );
	}

	window.TokenForge = window.TokenForge || {};
	window.TokenForge.a11y = { loadAxe: loadAxe, runAxe: runAxe };
}() );
