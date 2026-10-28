/* TokenForge: Bricks builder enhancements.
 * Shell tweaks via body classes (CSS file); canvas tweaks injected into the
 * builder iframe (same-origin). Re-applied on canvas reload + on a timer so
 * they survive Bricks re-renders.
 */
( function () {
	'use strict';
	var enh = window.TokenForgeEnh || {};
	var body = document.body;

	// ---- Shell-side toggles ----
	if ( enh.stack_add_elements ) { body.classList.add( 'tf-enh-stack' ); }
	if ( enh.pro_mode ) { body.classList.add( 'tf-enh-pro' ); }
	if ( enh.bigger_code_editor ) { body.classList.add( 'tf-enh-bigcode' ); }
	if ( enh.css_group_top ) { body.classList.add( 'tf-enh-cssfirst' ); }
	if ( enh.template_gallery ) { body.classList.add( 'tf-enh-tplgallery' ); }
	if ( ! enh.grid_overlay ) { body.classList.add( 'tf-enh-nogridoverlay' ); }

	// ---- Canvas (iframe) tweaks ----
	function canvasDoc() {
		var ifr = document.getElementById( 'bricks-builder-iframe' );
		try { return ifr && ifr.contentDocument; } catch ( e ) { return null; }
	}

	function ensureStyle( doc, id, css ) {
		var st = doc.getElementById( id );
		if ( ! css ) { if ( st ) { st.remove(); } return; }
		if ( ! st ) { st = doc.createElement( 'style' ); st.id = id; doc.head.appendChild( st ); }
		if ( st.textContent !== css ) { st.textContent = css; }
	}

	function applyCanvas() {
		var doc = canvasDoc();
		if ( ! doc || ! doc.head ) { return; }

		// Element outlines.
		var outlineCss = enh.element_outlines
			? '.brxe-section,.brxe-container,.brxe-block,.brxe-div{outline:1px dashed rgba(120,140,255,.45);outline-offset:-1px}'
			: '';
		ensureStyle( doc, 'tf-enh-outline', outlineCss );

		// Bricks' own grid overlay (drawn for the selected grid element).
		ensureStyle( doc, 'tf-enh-gridoverlay', enh.grid_overlay ? '' : '#bricks-css-grid-layout{display:none!important}' );

		// Grid/flex visualizer (debug aid): colour containers by their computed display.
		if ( enh.grid_visualizer ) {
			ensureStyle( doc, 'tf-enh-viz',
				'.tf-gridviz{outline:1px solid rgba(255,90,140,.75)!important;outline-offset:-1px;background-image:linear-gradient(rgba(255,90,140,.05),rgba(255,90,140,.05))}'
				+ '.tf-flexviz{outline:1px solid rgba(70,200,160,.8)!important;outline-offset:-1px}' );
			var win = doc.defaultView;
			doc.querySelectorAll( '[class*="brxe-"]' ).forEach( function ( el ) {
				var d = '';
				try { d = win.getComputedStyle( el ).display; } catch ( e ) {}
				el.classList.toggle( 'tf-gridviz', d === 'grid' || d === 'inline-grid' );
				el.classList.toggle( 'tf-flexviz', d === 'flex' || d === 'inline-flex' );
			} );
		} else {
			ensureStyle( doc, 'tf-enh-viz', '' );
			doc.querySelectorAll( '.tf-gridviz,.tf-flexviz' ).forEach( function ( el ) {
				el.classList.remove( 'tf-gridviz', 'tf-flexviz' );
			} );
		}

		// Overflow detection: outline elements that spill past the viewport width.
		if ( enh.overflow_detection ) {
			ensureStyle( doc, 'tf-enh-overflow', '.tf-overflow{outline:2px solid rgba(255,40,40,.85)!important;outline-offset:-2px}' );
			var vw = doc.documentElement.clientWidth;
			doc.querySelectorAll( '[class*="brxe-"]' ).forEach( function ( el ) {
				var over = false;
				try { var r = el.getBoundingClientRect(); over = ( r.right > vw + 1 || r.left < -1 ); } catch ( e ) {}
				el.classList.toggle( 'tf-overflow', over );
			} );
		} else {
			ensureStyle( doc, 'tf-enh-overflow', '' );
			doc.querySelectorAll( '.tf-overflow' ).forEach( function ( el ) { el.classList.remove( 'tf-overflow' ); } );
		}
	}

	var needsCanvas = enh.element_outlines || enh.grid_visualizer || enh.overflow_detection || ! enh.grid_overlay;
	if ( needsCanvas ) {
		var ifr = document.getElementById( 'bricks-builder-iframe' );
		if ( ifr ) { ifr.addEventListener( 'load', function () { setTimeout( applyCanvas, 400 ); } ); }
		setTimeout( applyCanvas, 800 );
		setInterval( applyCanvas, 3000 );
	}
} )();
