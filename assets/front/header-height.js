/* TokenForge: live header height.
 *
 * Measures the site header and sets --header-height on :root, and --admin-bar-height from the
 * WordPress admin bar (0px without one). Both follow the real size as it changes (wrapped menus,
 * sticky headers that shrink, the admin bar switching to its mobile height). Until the first
 * measurement the fixed value from the stylesheet applies, so nothing moves on load.
 * Header selectors come from window.tokenforgeHeader.selectors, tried in order; the first match wins.
 * Original implementation.
 */
( function () {
	'use strict';

	if ( true === window.tokenforgeHeaderOn ) { return; } // loaded twice: the first copy already measures
	window.tokenforgeHeaderOn = true;

	var root = document.documentElement;
	var list = ( window.tokenforgeHeader && window.tokenforgeHeader.selectors ) || [ '[data-tf-header]', '#brx-header', 'header.wp-block-template-part' ];
	var header = null, bar = null;

	for ( var i = 0; i < list.length && ! header; i++ ) {
		try { header = document.querySelector( list[ i ] ); } catch ( e ) {} // a bad selector from a filter: skip it
	}
	bar = document.getElementById( 'wpadminbar' );

	function px( el ) { return Math.round( el.getBoundingClientRect().height * 100 ) / 100 + 'px'; }

	function update() {
		if ( header ) { root.style.setProperty( '--header-height', px( header ) ); }
		root.style.setProperty( '--admin-bar-height', bar ? px( bar ) : '0px' );
	}

	update();
	if ( 'ResizeObserver' in window ) {
		var ro = new ResizeObserver( update );
		if ( header ) { ro.observe( header ); }
		if ( bar ) { ro.observe( bar ); }
	} else {
		window.addEventListener( 'resize', update );
	}
} )();
