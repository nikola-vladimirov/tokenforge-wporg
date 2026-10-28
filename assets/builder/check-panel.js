/* TokenForge window, "This page" in the Accessibility hub: the page-check panel (lane B1b), with the Design view [B8].
 *
 * UI only. It draws what window.TokenForge.check (check-engine.js) returns and calls
 * check.run / fix / show / elementTree on the user's click; nothing runs by itself.
 * A fix changes the builder's unsaved state only; the builder's Undo and Save stay the user's.
 * The dashboard (admin.js) calls TokenForgeCheckPanel.attach( placeholder ) every time it
 * redraws the Accessibility hub ("This page"); the panel keeps its own root node and state, so a redraw loses nothing.
 * Nothing here is placed in the canvas iframe.
 */
( function () {
	'use strict';

	// ---- Text (wp.i18n, domain tokenforge) ----
	function __( s, d ) { var i = window.wp && window.wp.i18n; return i && i.__ ? i.__( s, d ) : s; }
	function _n( a, b, n, d ) { var i = window.wp && window.wp.i18n; return i && i._n ? i._n( a, b, n, d ) : ( 1 === n ? a : b ); }
	// sprintf( '%1$s of %2$d', a, b ); also plain %s and %d in order.
	function sp( s ) {
		var a = [].slice.call( arguments, 1 ), k = 0;
		return String( s ).replace( /%(?:(\d+)\$)?[sd]/g, function ( m, p ) { return String( a[ p ? p - 1 : k++ ] ); } );
	}
	function esc( v ) {
		return String( null == v ? '' : v ).replace( /[&<>"']/g, function ( c ) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ c ]; } );
	}

	var RANK = { error: 0, warning: 1, notice: 2 };
	var S = { phase: 'idle', res: null, err: null, fixed: {}, note: {}, vals: {}, cin: {}, tokens: [], shut: {}, tree: null, stale: false, ids: {}, p: null, view: 'a11y',
		d: { phase: 'idle', res: null, err: null, stale: false, p: null, all: [], open: {}, ign: {} } }; // d: the Design view [B8]; its item ids start with "d:"
	var root, view, live;

	function eng() { var t = window.TokenForge; return ( t && t.check ) || null; }
	// A new child each time, so the same text twice is announced twice.
	function announce( msg ) { if ( live ) { live.innerHTML = '<span>' + esc( msg ) + '</span>'; } }

	// ---- Plain-words pieces ----
	function partWords( p ) {
		if ( 'header' === p ) { return __( 'the header template', 'tokenforge' ); }
		if ( 'footer' === p ) { return __( 'the footer template', 'tokenforge' ); }
		return __( 'the page content', 'tokenforge' );
	}
	function sevWord( s ) {
		if ( 'error' === s ) { return __( 'Error', 'tokenforge' ); }
		if ( 'warning' === s ) { return __( 'Warning', 'tokenforge' ); }
		return __( 'Notice', 'tokenforge' );
	}
	function problemsText( n ) { return sp( _n( '%d problem', '%d problems', n, 'tokenforge' ), n ); }
	function reviewText( n ) { return sp( __( '%d to check by eye', 'tokenforge' ), n ); }
	function sevBreakdown( items ) {
		var c = { error: 0, warning: 0, notice: 0 }, out = [];
		items.forEach( function ( i ) { c[ i.severity in c ? i.severity : 'notice' ]++; } );
		if ( c.error ) { out.push( sp( _n( '%d error', '%d errors', c.error, 'tokenforge' ), c.error ) ); }
		if ( c.warning ) { out.push( sp( _n( '%d warning', '%d warnings', c.warning, 'tokenforge' ), c.warning ) ); }
		if ( c.notice ) { out.push( sp( _n( '%d notice', '%d notices', c.notice, 'tokenforge' ), c.notice ) ); }
		return out.join( ', ' );
	}
	function split( res ) {
		var f = [], r = [];
		( res.items || [] ).forEach( function ( i ) { ( 'review' === i.status ? r : f ).push( i ); } );
		return { fail: f, review: r };
	}
	function doneText( res ) {
		var s = split( res );
		return sp( __( 'Check finished: %1$s, %2$s', 'tokenforge' ), problemsText( s.fail.length ), reviewText( s.review.length ) );
	}
	function errorText( code ) {
		if ( 'no-builder' === code ) { return __( 'The builder is not ready, so the page cannot be checked. Open a page in the builder, wait until it has loaded, then try again.', 'tokenforge' ); }
		if ( 'no-axe' === code ) { return __( 'The checking tool could not be loaded. Check your connection, then try again.', 'tokenforge' ); }
		if ( 'axe-failed' === code ) { return __( 'The check stopped because of an unexpected error. Try again; if it keeps happening, reload the builder.', 'tokenforge' ); }
		return __( 'The page check is not available in this window. Reload the builder and try again.', 'tokenforge' );
	}
	function fixError( code, f ) {
		if ( 'gone' === code ) { return __( 'This element is gone, so nothing was changed. Check again.', 'tokenforge' ); }
		if ( 'changed' === code ) { return __( 'This element changed since the check, so nothing was changed. Check again.', 'tokenforge' ); }
		if ( 'breakpoint' === code ) { return __( 'The text colour of this element is set for the breakpoint you are on, so a change here would not show. Nothing was changed. Change it in the builder at this breakpoint.', 'tokenforge' ); }
		if ( 'blocked' === code ) { return ( f && f.blocked ) || __( 'This cannot be fixed from here. Open the header or footer template, or the component, to fix it.', 'tokenforge' ); }
		if ( 'needs-value' === code ) { return f && 'colour' === f.input ? __( 'Choose a colour first, then press Apply.', 'tokenforge' ) : f && f.input && f.input.choices ? __( 'Pick a value first, then press Apply.', 'tokenforge' ) : __( 'Type a value first, then press Apply.', 'tokenforge' ); }
		if ( 'no-builder' === code ) { return __( 'The builder is not ready. Open a page in the builder and try again.', 'tokenforge' ); }
		if ( 'unknown-fix' === code ) { return __( 'This fix is not available any more. Check again.', 'tokenforge' ); }
		return __( 'The fix did not work. Check again.', 'tokenforge' );
	}
	function showFailText( item ) {
		var t = item.target || {};
		if ( ! t.elementId ) { return __( 'This item is not one builder element, so there is nothing to select.', 'tokenforge' ); }
		if ( false === t.editable ) {
			if ( 'header' === t.part ) { return __( 'This element is not editable here: it is in the header template. Open that template to select it.', 'tokenforge' ); }
			if ( 'footer' === t.part ) { return __( 'This element is not editable here: it is in the footer template. Open that template to select it.', 'tokenforge' ); }
			if ( 'content' === t.part ) { return __( 'This element is not editable here: it is in the page content. Open the page to select it.', 'tokenforge' ); }
			return __( 'This element is not editable here. Open its template or component to select it.', 'tokenforge' );
		}
		return __( 'This element is gone. Check again.', 'tokenforge' );
	}
	function elementLine( t ) {
		t = t || {};
		var where = '';
		if ( 'header' === t.part ) { where = __( 'In the header template', 'tokenforge' ); }
		else if ( 'footer' === t.part ) { where = __( 'In the footer template', 'tokenforge' ); }
		else if ( 'component' === t.part ) { where = __( 'In a component', 'tokenforge' ); }
		var h = '<span class="tf-chk-k">' + esc( __( 'Element:', 'tokenforge' ) ) + '</span> ';
		h += t.label || t.name ? '<strong>' + esc( t.label || t.name ) + '</strong>' + ( t.name && t.label && t.name !== t.label ? ' <code>' + esc( t.name ) + '</code>' : '' ) : '<strong>' + esc( __( 'Whole page', 'tokenforge' ) ) + '</strong>';
		if ( where ) { h += ' <span class="tf-muted">(' + esc( where ) + ')</span>'; }
		return h;
	}

	// ---- Groups ----
	function groupsFor( res, status ) {
		var by = {}, seen = {}, out = [];
		( res.items || [] ).forEach( function ( i ) { by[ i.id ] = i; } );
		var pick = function ( i ) { return 'review' === status ? 'review' === i.status : 'review' !== i.status; };
		var order = function ( list ) {
			return list.map( function ( x, n ) { return [ x, n ]; } ).sort( function ( a, b ) {
				return ( RANK[ a[ 0 ].severity ] - RANK[ b[ 0 ].severity ] ) || a[ 1 ] - b[ 1 ];
			} ).map( function ( x ) { return x[ 0 ]; } );
		};
		( ( res.summary && res.summary.groups ) || [] ).forEach( function ( g ) {
			var all = ( g.items || [] ).map( function ( id ) { return by[ id ]; } ).filter( Boolean );
			all.forEach( function ( i ) { seen[ i.id ] = 1; } );
			var mine = order( all.filter( pick ) );
			if ( mine.length ) { out.push( { id: g.id, label: g.label, items: mine } ); }
		} );
		var rest = order( ( res.items || [] ).filter( function ( i ) { return ! seen[ i.id ] && pick( i ); } ) );
		if ( rest.length ) { out.push( { id: 'rest', label: __( 'Other', 'tokenforge' ), items: rest } ); }
		return out;
	}

	// ---- Another text colour (colour-contrast items) ----
	// A token from the dashboard's list or any colour; the server measures it on the item's background before Apply.
	function colourState( it, key ) {
		return S.cin[ key ] || ( S.cin[ key ] = { token: '', color: ( it.data && /^#[0-9a-f]{6}$/i.test( it.data.fg || '' ) ? it.data.fg : '#000000' ), hex: '', value: '', bad: false, res: null, seq: 0, timer: 0 } );
	}
	function colourText( c, it ) {
		if ( c.bad ) { return __( 'Type a colour like #1a2b3c.', 'tokenforge' ); }
		if ( ! c.value ) { return __( 'Choose a token or a colour to see its contrast ratio.', 'tokenforge' ); }
		if ( ! c.res ) { return __( 'Measuring...', 'tokenforge' ); }
		if ( ! c.res.ok ) { return __( 'This colour could not be measured.', 'tokenforge' ); }
		var t = c.res.modes.map( function ( x ) {
			return modeWord( x.mode ) + ': ' + sp( x.pass ? __( '%1$s:1. Passes: %2$s:1 is needed.', 'tokenforge' ) : __( '%1$s:1. Fails: %2$s:1 is needed.', 'tokenforge' ), x.ratio, x.needed );
		} ).join( ' ' );
		// One colour setting serves both modes: say so when only one background is known.
		if ( 1 === c.res.modes.length && ( it.mode || it.data.dark || ( window.TokenForgePanel || {} ).darkMode ) ) {
			t += ' ' + ( it.mode ? __( 'This colour applies in both light and dark mode. The other mode has no problem listed for this element, so it was not measured: run the design check again after applying.', 'tokenforge' ) : __( 'This colour applies in both light and dark mode, but only this mode was measured. Run the check again in the other mode.', 'tokenforge' ) );
		}
		return c.res.pass ? t : t + ' ' + __( 'Choose a colour that passes to use it.', 'tokenforge' );
	}
	function colourFixHtml( it, f, fid, key, btn ) {
		var c = colourState( it, key ), sg = it.data && it.data.suggestion && it.data.suggestion.token ? String( it.data.suggestion.token ).replace( /^var\(--(.*)\)$/, '$1' ) : '';
		var at = ' data-chk="cin" data-id="' + esc( it.id ) + '" data-fix="' + esc( f.id ) + '"';
		var h = '<div class="tf-chk-colour" role="group" aria-labelledby="' + fid + '-l"><p class="tf-chk-lbl" id="' + fid + '-l">' + esc( f.label ) + '</p>';
		if ( sg ) { h += '<p class="tf-chk-fnote">' + esc( sp( __( 'The nearest token that passes: %s', 'tokenforge' ), sg ) ) + '</p>'; }
		h += '<label class="tf-chk-lbl" for="' + fid + '-t">' + esc( __( 'Colour token', 'tokenforge' ) ) + '</label><select id="' + fid + '-t"' + at + ' data-kind="tok" data-fk="val:' + esc( key ) + '"><option value="">' + esc( __( 'Choose a token...', 'tokenforge' ) ) + '</option>';
		S.tokens.forEach( function ( n ) { h += '<option value="' + esc( n ) + '"' + ( c.token === n ? ' selected' : '' ) + '>' + esc( n ) + '</option>'; } );
		h += '</select><label class="tf-chk-lbl" for="' + fid + '-c">' + esc( __( 'Or a custom colour', 'tokenforge' ) ) + '</label><span class="tf-chk-row"><input type="color" id="' + fid + '-c"' + at + ' data-kind="col" value="' + esc( c.color ) + '"><input type="text" class="tf-chk-in"' + at + ' data-kind="hex" value="' + esc( c.hex ) + '" placeholder="#1a2b3c" spellcheck="false" autocomplete="off" aria-label="' + esc( __( 'Custom colour as hex', 'tokenforge' ) ) + '"></span>';
		h += '<p><span class="tf-chk-cres' + ( c.res && c.res.ok ? ( c.res.pass ? ' is-pass' : ' is-fail' ) : '' ) + '" id="' + fid + '-r" role="status" aria-live="polite" data-cres="' + esc( key ) + '">' + ( c.res ? esc( colourText( c, it ) ) : '' ) + '</span><span class="tf-muted" data-chint="' + esc( key ) + '">' + ( c.res ? '' : esc( colourText( c, it ) ) ) + '</span></p>';
		return h + btn( __( 'Apply', 'tokenforge' ), ! ( c.res && c.res.ok && c.res.pass ) ) + '</div>';
	}
	// Update the result and the Apply button in place (a redraw would take the focus from the field being typed in).
	// The polite status line only speaks a finished answer (or the format hint once the field is left); "Measuring" and the
	// hint while typing go to a quiet line beside it, so a screen reader is not read every keystroke.
	function colourShow( key, it, loud ) {
		var c = S.cin[ key ], out = null, hint = null, all = root.querySelectorAll( '[data-cres]' ), ap = null;
		for ( var i = 0; i < all.length; i++ ) { if ( all[ i ].getAttribute( 'data-cres' ) === key ) { out = all[ i ]; } }
		if ( ! c || ! out ) { return; }
		hint = out.parentNode.querySelector( '[data-chint]' );
		var done = !! c.res || ( c.bad && loud ), text = colourText( c, it );
		out.textContent = done ? text : '';
		if ( hint ) { hint.textContent = done ? '' : text; }
		out.className = 'tf-chk-cres' + ( c.res && c.res.ok ? ( c.res.pass ? ' is-pass' : ' is-fail' ) : '' );
		ap = out.parentNode.parentNode.querySelector( '.tf-chk-apply' );
		if ( ap ) { ap.disabled = ! ( c.res && c.res.ok && c.res.pass ); }
	}
	// The same element's problem in the other mode, when the design run found one: its background is known too.
	function colourPeer( it ) {
		var t = it.target || {};
		return it.mode ? S.d.all.filter( function ( x ) { return x.mode && x.mode !== it.mode && x.target && x.target.selector === t.selector && x.data && x.data.bg && x.data.needed; } )[ 0 ] : null;
	}
	function colourMeasure( key, it ) {
		var c = S.cin[ key ], e = eng(), seq = ++c.seq;
		if ( ! c.value || ! e || ! e.measure ) { return; }
		var list = [ it ], pe = colourPeer( it );
		if ( pe ) { list.push( pe ); }
		e.measure( list.map( function ( x ) { return { fg: c.value, bg: x.data.bg, min: x.data.needed, dark: !! ( x.mode ? 'dark' === x.mode : x.data.dark ) }; } ) ).then( function ( m ) {
			if ( seq !== c.seq ) { return; }
			var modes = list.map( function ( x, i ) { return m[ i ] && { mode: x.mode || ( x.data.dark ? 'dark' : 'light' ), ratio: m[ i ].ratio, pass: !! m[ i ].pass, needed: x.data.needed }; } );
			c.res = modes.every( Boolean ) ? { ok: true, modes: modes, pass: modes.every( function ( x ) { return x.pass; } ) } : { ok: false };
			colourShow( key, it );
		}, function () { if ( seq === c.seq ) { c.res = { ok: false }; colourShow( key, it ); } } );
	}
	function onColour( t, loud ) {
		var it = find( t.getAttribute( 'data-id' ) ), fixId = t.getAttribute( 'data-fix' );
		if ( ! it ) { return; }
		var key = it.id + '|' + fixId, c = colourState( it, key ), kind = t.getAttribute( 'data-kind' ), sel = function ( k ) { return root.querySelector( '[data-chk="cin"][data-id="' + t.getAttribute( 'data-id' ) + '"][data-fix="' + fixId + '"][data-kind="' + k + '"]' ); };
		c.bad = false;
		if ( 'tok' === kind ) { c.token = t.value; c.value = t.value ? 'var(--' + t.value + ')' : ''; }
		else {
			var hx = 'col' === kind ? t.value : ( /^#([0-9a-f]{3}|[0-9a-f]{6})$/i.test( t.value.trim() ) ? t.value.trim().toLowerCase().replace( /^#(.)(.)(.)$/, '#$1$1$2$2$3$3' ) : '' );
			c.token = ''; sel( 'tok' ).value = '';
			if ( 'col' === kind ) { c.color = hx; c.hex = hx; sel( 'hex' ).value = hx; }
			else { c.hex = t.value; c.bad = ! hx && '' !== t.value.trim(); if ( hx ) { c.color = hx; sel( 'col' ).value = hx; } }
			c.value = hx;
		}
		S.vals[ key ] = c.value;
		c.res = null; ++c.seq; clearTimeout( c.timer );
		colourShow( key, it, loud );
		if ( c.value ) { c.timer = setTimeout( function () { colourMeasure( key, it ); }, 250 ); }
	}

	// ---- Items ----
	function fixHtml( it, f, n, mid ) {
		var key = it.id + '|' + f.id, fid = 'tf-chk-f' + n + '-' + esc( f.id ), h = '<div class="tf-chk-fix">';
		if ( f.blocked ) { return h + '<p class="tf-chk-blocked"><strong>' + esc( f.label ) + ':</strong> ' + esc( f.blocked ) + '</p></div>'; }
		if ( f.note ) { h += '<p class="tf-chk-fnote" id="' + fid + '-n">' + esc( f.note ) + '</p>'; }
		var btn = function ( text, off ) {
			return '<button type="button" class="tf-btn tf-small tf-chk-apply" data-chk="fix" data-id="' + esc( it.id ) + '" data-fix="' + esc( f.id ) + '" data-fk="fix:' + esc( key ) + '"' + ( off ? ' disabled' : '' ) + ' aria-describedby="' + mid + ( f.note ? ' ' + fid + '-n' : '' ) + '">' + esc( text ) + '</button>';
		};
		var val = S.vals[ key ] || '';
		if ( 'colour' === f.input ) {
			h += colourFixHtml( it, f, fid, key, btn );
		} else if ( 'text' === f.input ) {
			h += '<label class="tf-chk-lbl" for="' + fid + '">' + esc( f.label ) + '</label><span class="tf-chk-row"><input type="text" id="' + fid + '" class="tf-chk-in" data-chk="val" data-id="' + esc( it.id ) + '" data-fix="' + esc( f.id ) + '" data-fk="val:' + esc( key ) + '" value="' + esc( val ) + '" autocomplete="off">' + btn( __( 'Apply', 'tokenforge' ) ) + '</span>';
		} else if ( f.input && f.input.choices ) {
			h += '<label class="tf-chk-lbl" for="' + fid + '">' + esc( f.label ) + '</label><span class="tf-chk-row"><select id="' + fid + '" class="tf-chk-in" data-chk="val" data-id="' + esc( it.id ) + '" data-fix="' + esc( f.id ) + '" data-fk="val:' + esc( key ) + '"><option value="">' + esc( __( 'Choose...', 'tokenforge' ) ) + '</option>';
			f.input.choices.forEach( function ( c ) { h += '<option value="' + esc( c ) + '"' + ( c === val ? ' selected' : '' ) + '>' + esc( c ) + '</option>'; } );
			h += '</select>' + btn( __( 'Apply', 'tokenforge' ) ) + '</span>';
		} else {
			h += btn( f.label );
		}
		return h + '</div>';
	}
	function itemHtml( it ) {
		var n = S.ids[ it.id ], mid = 'tf-chk-m' + n, t = it.target || {}, fx = S.fixed[ it.id ], note = S.note[ it.id ];
		var h = '<li class="tf-chk-item' + ( fx ? ' is-fixed' : '' ) + '" data-sev="' + esc( 'review' === it.status ? 'review' : it.severity ) + '">';
		h += '<div class="tf-chk-top"><span class="tf-chk-tag tf-chk-sev">' + esc( 'review' === it.status ? __( 'Check by eye', 'tokenforge' ) : sevWord( it.severity ) ) + '</span>';
		if ( it.reportOnly ) { h += '<span class="tf-chk-tag">' + esc( __( 'Report only', 'tokenforge' ) ) + '</span>'; }
		if ( fx ) { h += '<span class="tf-chk-tag tf-chk-done" tabindex="-1" data-fk="fixed:' + esc( it.id ) + '">' + esc( __( 'Fixed', 'tokenforge' ) ) + '</span>'; }
		h += '</div><p class="tf-chk-msg" id="' + mid + '">' + esc( it.message ) + '</p>';
		h += '<p class="tf-chk-el">' + elementLine( t ) + '</p>';
		if ( t.count > 1 ) { h += '<p class="tf-chk-el tf-muted">' + esc( sp( __( 'Found %d times: a loop or repeated block uses this element, and this one item stands for all of them.', 'tokenforge' ), t.count ) ) + '</p>'; }
		if ( it.help ) { h += '<p class="tf-chk-help">' + esc( it.help ) + '</p>'; }
		var meta = [];
		if ( it.wcag ) { meta.push( esc( it.level ? sp( __( 'WCAG %1$s, level %2$s', 'tokenforge' ), it.wcag, it.level ) : sp( __( 'WCAG %s', 'tokenforge' ), it.wcag ) ) ); }
		if ( it.helpUrl && /^https?:\/\//i.test( it.helpUrl ) ) { meta.push( '<a href="' + esc( it.helpUrl ) + '" target="_blank" rel="noopener noreferrer">' + esc( __( 'More about this rule', 'tokenforge' ) ) + '<span class="tf-chk-sr"> ' + esc( __( '(opens in a new tab)', 'tokenforge' ) ) + '</span></a>' ); }
		if ( meta.length ) { h += '<p class="tf-chk-meta tf-muted">' + meta.join( ' &middot; ' ) + '</p>'; }
		if ( fx ) {
			h += '<p class="tf-chk-fixed">' + esc( sp( __( 'Fixed: %s', 'tokenforge' ), fx.label ) ) + ( fx.before && fx.after && 'object' !== typeof fx.before && 'object' !== typeof fx.after ? ' ' + esc( sp( __( '(changed from %1$s to %2$s)', 'tokenforge' ), fx.before, fx.after ) ) : '' ) + '</p>';
		}
		if ( false !== it.canShow ) { h += '<div class="tf-chk-acts"><button type="button" class="tf-btn tf-small" data-chk="show" data-id="' + esc( it.id ) + '" data-fk="show:' + esc( it.id ) + '" aria-describedby="' + mid + '">' + esc( __( 'Show', 'tokenforge' ) ) + '</button></div>'; }
		if ( ! fx ) { ( it.fixes || [] ).forEach( function ( f ) { h += fixHtml( it, f, n, mid ); } ); }
		if ( note ) { h += '<p class="tf-chk-err"><strong>' + esc( __( 'Not done:', 'tokenforge' ) ) + '</strong> ' + esc( note.text ) + '</p>'; }
		return h + '</li>';
	}
	function groupHtml( sec, g, review ) {
		var key = sec + ':' + g.id, open = ! S.shut[ key ], uid = 'tf-chk-g-' + sec + '-' + esc( g.id );
		var count = review ? reviewText( g.items.length ) : problemsText( g.items.length ) + ' (' + sevBreakdown( g.items ) + ')';
		var h = '<div class="tf-chk-group"><h3 class="tf-chk-gh"><button type="button" class="tf-chk-gt" data-chk="toggle" data-key="' + esc( key ) + '" data-fk="g:' + esc( key ) + '" aria-expanded="' + ( open ? 'true' : 'false' ) + '" aria-controls="' + uid + '">';
		h += '<span class="tf-chk-caret" aria-hidden="true"></span><span class="tf-chk-gn">' + esc( g.label ) + '</span><span class="tf-chk-gc">' + esc( count ) + '</span></button></h3>';
		h += '<ul class="tf-chk-items" id="' + uid + '"' + ( open ? '' : ' hidden' ) + '>' + g.items.map( itemHtml ).join( '' ) + '</ul></div>';
		return h;
	}

	// ---- Outline and automatic attributes ----
	function outlineHtml() {
		var t = S.tree, h = '<section class="tf-chk-sec" aria-labelledby="tf-chk-h-tree"><h2 id="tf-chk-h-tree">' + esc( __( 'Heading outline', 'tokenforge' ) ) + '</h2>';
		h += '<p class="tf-muted">' + esc( __( 'The headings on the page, in order. A level should go down one step at a time.', 'tokenforge' ) ) + '</p>';
		h += '<p><button type="button" class="tf-btn tf-small" data-chk="tree" data-fk="tree" title="' + esc( __( 'Read the headings from the page open in the builder. Nothing is changed.', 'tokenforge' ) ) + '">' + esc( __( 'Update outline', 'tokenforge' ) ) + '</button></p>';
		if ( ! t ) { return h + '<p class="tf-muted">' + esc( __( 'Press Update outline to list the headings.', 'tokenforge' ) ) + '</p></section>'; }
		if ( ! t.ok ) { return h + '<p class="tf-chk-err">' + esc( __( 'The page is not open in the builder, so there is no outline.', 'tokenforge' ) ) + '</p></section>'; }
		var list = t.headings || [];
		if ( ! list.length ) { return h + '<p>' + esc( __( 'No headings found on this page.', 'tokenforge' ) ) + '</p></section>'; }
		var h1 = list.filter( function ( x ) { return 1 === x.level; } ).length;
		h += '<p class="tf-chk-h1">' + esc( 0 === h1 ? __( 'There is no h1.', 'tokenforge' ) : ( 1 === h1 ? __( 'There is one h1.', 'tokenforge' ) : sp( __( 'There are %d h1 headings: use only one.', 'tokenforge' ), h1 ) ) ) + '</p>';
		h += '<ul class="tf-chk-tree">';
		var prev = 0;
		list.forEach( function ( x, i ) {
			var lv = Math.min( 6, Math.max( 1, x.level | 0 ) ), skip = '';
			if ( prev && lv > prev + 1 ) {
				var miss = [];
				for ( var k = prev + 1; k < lv; k++ ) { miss.push( 'h' + k ); }
				skip = sp( __( 'h%1$d to h%2$d: %3$s skipped', 'tokenforge' ), prev, lv, miss.join( ', ' ) );
			} else if ( ! prev && lv > 1 ) {
				skip = sp( __( 'Starts at h%d: the first heading should be h1.', 'tokenforge' ), lv );
			}
			prev = lv;
			var id = 'tf-chk-o' + i;
			h += '<li class="tf-chk-lv tf-lv-' + lv + '"><span class="tf-chk-lvn">h' + lv + '</span> <span class="tf-chk-ot" id="' + id + '">' + ( x.text ? esc( x.text ) : '<em>' + esc( __( '(no text)', 'tokenforge' ) ) + '</em>' ) + '</span>';
			if ( x.part && 'content' !== x.part ) { h += ' <span class="tf-muted">(' + esc( 'header' === x.part ? __( 'header', 'tokenforge' ) : ( 'footer' === x.part ? __( 'footer', 'tokenforge' ) : x.part ) ) + ')</span>'; }
			if ( skip ) { h += ' <strong class="tf-chk-skip">' + esc( skip ) + '</strong>'; }
			h += ' <button type="button" class="tf-btn tf-small" data-chk="showh" data-i="' + i + '" data-fk="showh:' + i + '" aria-describedby="' + id + '">' + esc( __( 'Show', 'tokenforge' ) ) + '</button></li>';
		} );
		return h + '</ul></section>';
	}

	// ---- The whole view ----
	function statusText() {
		if ( 'running' === S.phase ) { return __( 'Checking the page...', 'tokenforge' ); }
		if ( 'error' === S.phase ) { return __( 'The last run did not finish.', 'tokenforge' ); }
		if ( S.res ) {
			var d = new Date( S.res.ranAt || Date.now() );
			return sp( __( 'Last run: %1$s. Checked: %2$s.', 'tokenforge' ), d.toLocaleTimeString(), partWords( S.res.part ) );
		}
		return __( 'Not run yet. Press Run check to check what you are editing now (the page content, or the header or footer template).', 'tokenforge' );
	}
	function resultHtml( res ) {
		var s = split( res ), h = '';
		h += '<section class="tf-chk-sec" aria-labelledby="tf-chk-h-sum"><h2 id="tf-chk-h-sum">' + esc( __( 'Summary', 'tokenforge' ) ) + '</h2>';
		if ( ! s.fail.length ) {
			h += '<p class="tf-chk-sum"><strong>' + esc( __( 'No problems found.', 'tokenforge' ) ) + '</strong> ';
			var tool = res.axeVersion ? sp( __( 'axe-core %s', 'tokenforge' ), res.axeVersion ) : __( 'axe-core', 'tokenforge' );
			var rules = 'number' === typeof res.rulesRun ? ' ' + sp( _n( '(%d rule)', '(%d rules)', res.rulesRun, 'tokenforge' ), res.rulesRun ) : '';
			h += esc( sp( __( 'Checked %1$s with %2$s%3$s and TokenForge\'s own checks.', 'tokenforge' ), partWords( res.part ), tool, rules ) ) + '</p>';
			if ( s.review.length ) { h += '<p>' + esc( sp( _n( '%d item needs a look by eye, see below.', '%d items need a look by eye, see below.', s.review.length, 'tokenforge' ), s.review.length ) ) + '</p>'; }
			h += '<p class="tf-muted">' + esc( __( 'Automatic checks cannot find every problem. Also try the page with the keyboard only.', 'tokenforge' ) ) + '</p>';
		} else {
			h += '<p class="tf-chk-sum"><strong>' + esc( problemsText( s.fail.length ) ) + '</strong> (' + esc( sevBreakdown( s.fail ) ) + ')' + ( s.review.length ? ', ' + esc( reviewText( s.review.length ) ) : '' ) + '.</p>';
		}
		h += '</section>';
		var pg = groupsFor( res, 'fail' );
		if ( pg.length ) { h += '<section class="tf-chk-sec" aria-labelledby="tf-chk-h-prob"><h2 id="tf-chk-h-prob">' + esc( __( 'Problems', 'tokenforge' ) ) + '</h2>' + pg.map( function ( g ) { return groupHtml( 'p', g, false ); } ).join( '' ) + '</section>'; }
		var rg = groupsFor( res, 'review' );
		if ( rg.length ) {
			h += '<section class="tf-chk-sec" aria-labelledby="tf-chk-h-eye"><h2 id="tf-chk-h-eye">' + esc( __( 'Check by eye', 'tokenforge' ) ) + '</h2>';
			h += '<p class="tf-muted">' + esc( __( 'The checker could not decide these. Look at each one yourself. They are not counted as passed.', 'tokenforge' ) ) + '</p>' + rg.map( function ( g ) { return groupHtml( 'r', g, true ); } ).join( '' ) + '</section>';
		}
		return h;
	}
	function a11yHtml() {
		var res = S.res, busy = 'running' === S.phase, h = '';
		S.ids = {};
		( res && res.items || [] ).forEach( function ( i, n ) { S.ids[ i.id ] = n; } );
		h += '<div class="tf-chk-run"><button type="button" class="tf-btn tf-primary" data-chk="run" data-fk="run"' + ( busy ? ' aria-disabled="true"' : '' ) + ' title="' + esc( __( 'Check the page you are editing now. Nothing is changed or saved.', 'tokenforge' ) ) + '">' + esc( __( 'Run check', 'tokenforge' ) ) + '</button>';
		h += '<p class="tf-chk-status" id="tf-chk-status">' + esc( statusText() ) + '</p></div>';
		if ( 'error' === S.phase && S.err ) {
			h += '<div class="tf-chk-box"><p class="tf-chk-err"><strong>' + esc( __( 'The check could not run.', 'tokenforge' ) ) + '</strong> ' + esc( errorText( S.err.code ) ) + '</p>';
			if ( S.err.message && ( 'no-axe' === S.err.code || 'axe-failed' === S.err.code ) ) { h += '<p class="tf-muted">' + esc( __( 'Details:', 'tokenforge' ) ) + ' <code>' + esc( S.err.message ) + '</code></p>'; }
			h += '<button type="button" class="tf-btn tf-small" data-chk="run" data-fk="retry">' + esc( __( 'Try again', 'tokenforge' ) ) + '</button></div>';
		}
		if ( S.stale && ! busy ) {
			h += '<div class="tf-chk-box tf-chk-stale"><p>' + esc( __( 'You changed the page, so this list is out of date until you check again. The builder\'s Undo takes a fix back.', 'tokenforge' ) ) + '</p><button type="button" class="tf-btn tf-small" data-chk="run" data-fk="again">' + esc( __( 'Check again', 'tokenforge' ) ) + '</button></div>';
		}
		if ( res && res.warnings && res.warnings.indexOf( 'server' ) > -1 ) {
			h += '<p class="tf-chk-warn"><strong>' + esc( __( 'Note:', 'tokenforge' ) ) + '</strong> ' + esc( __( 'The server could not be reached in this run, so image alt text from the media library, color token suggestions and the check for unknown data-tf names are missing.', 'tokenforge' ) ) + '</p>';
		}
		if ( res ) { h += '<div class="tf-chk-results' + ( busy ? ' is-busy' : '' ) + '">' + resultHtml( res ) + '</div>'; }
		h += outlineHtml();
		return h;
	}

	// ---- Design view [B8]: raw values, contrast in light and dark, font sizes ----
	function serverNote( res ) {
		return res && res.warnings && res.warnings.indexOf( 'server' ) > -1 ? '<p class="tf-chk-warn"><strong>' + esc( __( 'Note:', 'tokenforge' ) ) + '</strong> ' + esc( __( 'The server could not be reached in this run, so image alt text from the media library, color token suggestions and the check for unknown data-tf names are missing.', 'tokenforge' ) ) + '</p>' : '';
	}
	function modeWord( m ) { return 'dark' === m ? __( 'Dark mode', 'tokenforge' ) : __( 'Light mode', 'tokenforge' ); }
	// Every group of the last design run, in order: { sec, key, title, items, head }.
	function dGroups( r ) {
		var out = [];
		( r.raw || [] ).forEach( function ( g ) {
			var tok = g.exact ? sp( __( 'same as token %s', 'tokenforge' ), g.token ) : sp( __( 'nearest token %s', 'tokenforge' ), g.token );
			out.push( { sec: 'raw', key: g.id, title: sp( _n( '%1$s used %2$d time, %3$s', '%1$s used %2$d times, %3$s', g.count, 'tokenforge' ), g.value, g.count, tok ), items: g.items, raw: g } );
		} );
		( r.modes || [] ).forEach( function ( m ) {
			[ 'fail', 'review' ].forEach( function ( st ) {
				var list = ( r.contrast || [] ).filter( function ( i ) { return i.mode === m && ( 'review' === st ) === ( 'review' === i.status ); } );
				if ( list.length ) { out.push( { sec: 'contrast', key: 'd:c:' + m + ':' + st, title: 'review' === st ? sp( __( '%1$s: %2$s', 'tokenforge' ), modeWord( m ), reviewText( list.length ) ) : sp( __( '%1$s: %2$s (%3$s)', 'tokenforge' ), modeWord( m ), problemsText( list.length ), sevBreakdown( list ) ), items: list } ); }
			} );
		} );
		( r.sizes || [] ).forEach( function ( g ) {
			var n = g.items.length;
			out.push( { sec: 'size', key: g.id, items: g.items, title: 'small' === g.kind ? sp( _n( '%1$spx on %2$d element: too small, under %3$dpx', '%1$spx on %2$d elements: too small, under %3$dpx', n, 'tokenforge' ), g.px, n, g.min ) : sp( _n( '%1$spx on %2$d element: not on the type scale, nearest step %3$s (%4$spx)', '%1$spx on %2$d elements: not on the type scale, nearest step %3$s (%4$spx)', n, 'tokenforge' ), g.px, n, g.token, g.tokenPx ) } );
		} );
		return out;
	}
	function dSumText( r ) {
		var gs = dGroups( r ).filter( function ( g ) { return ! S.d.ign[ g.key ]; } ), c = { raw: 0, fail: 0, review: 0, size: 0 };
		gs.forEach( function ( g ) {
			if ( 'contrast' !== g.sec ) { c[ g.sec ]++; return; }
			g.items.forEach( function ( i ) { c[ 'review' === i.status ? 'review' : 'fail' ]++; } );
		} );
		return sp( __( 'At the %1$s breakpoint, in light and dark mode: %2$s; %3$s, %4$s; %5$s.', 'tokenforge' ), r.breakpoint.label,
			sp( _n( '%d typed value near a token', '%d typed values near a token', c.raw, 'tokenforge' ), c.raw ),
			sp( _n( '%d contrast problem', '%d contrast problems', c.fail, 'tokenforge' ), c.fail ), reviewText( c.review ),
			sp( _n( '%d font size off the scale or too small', '%d font sizes off the scale or too small', c.size, 'tokenforge' ), c.size ) );
	}
	// Show and Use token for a whole group. A fix changes one element or one class, so Use token is here only when the value lives in one place.
	function rawHead( g, tid ) {
		var first = g.items.filter( function ( i ) { return false !== i.canShow; } )[ 0 ], one = 1 === g.items.length ? g.items[ 0 ] : null, f = one && one.fixes[ 0 ], h = '<div class="tf-chk-acts">';
		if ( first ) { h += '<button type="button" class="tf-btn tf-small" data-chk="show" data-id="' + esc( first.id ) + '" data-fk="gshow:' + esc( g.key ) + '" aria-describedby="' + tid + '">' + esc( __( 'Show', 'tokenforge' ) ) + '</button> '; }
		if ( f && ! f.blocked && ! S.fixed[ one.id ] ) { h += '<button type="button" class="tf-btn tf-small" data-chk="fix" data-id="' + esc( one.id ) + '" data-fix="' + esc( f.id ) + '" data-fk="gfix:' + esc( g.key ) + '" aria-describedby="' + tid + '">' + esc( f.label ) + '</button>'; }
		h += '</div>';
		if ( ! one ) { h += '<p class="tf-chk-fnote">' + esc( sp( __( 'This value is typed in %d places. A fix changes one element or one class at a time: use the token on each row below.', 'tokenforge' ), g.items.length ) ) + '</p>'; }
		return h;
	}
	function dGroupHtml( g, n ) {
		var open = !! S.d.open[ g.key ], uid = 'tf-chk-dg' + n;
		var h = '<div class="tf-chk-group"><div class="tf-chk-ghr"><h3 class="tf-chk-gh"><button type="button" class="tf-chk-gt" data-chk="dtoggle" data-key="' + esc( g.key ) + '" data-fk="dg:' + esc( g.key ) + '" aria-expanded="' + ( open ? 'true' : 'false' ) + '" aria-controls="' + uid + '">';
		h += '<span class="tf-chk-caret" aria-hidden="true"></span><span class="tf-chk-gn" id="' + uid + '-t">' + esc( g.title ) + '</span></button></h3>';
		h += '<button type="button" class="tf-btn tf-small tf-chk-ign" data-chk="ignore" data-key="' + esc( g.key ) + '" data-fk="ign:' + esc( g.key ) + '" aria-label="' + esc( sp( __( 'Ignore for this session: %s', 'tokenforge' ), g.title ) ) + '">' + esc( __( 'Ignore', 'tokenforge' ) ) + '</button></div>';
		h += '<div class="tf-chk-gb" id="' + uid + '"' + ( open ? '' : ' hidden' ) + '>' + ( g.raw ? rawHead( g, uid + '-t' ) : '' ) + '<ul class="tf-chk-items">' + g.items.map( itemHtml ).join( '' ) + '</ul></div></div>';
		return h;
	}
	function dResultHtml( r ) {
		var all = dGroups( r ), gs = all.filter( function ( g ) { return ! S.d.ign[ g.key ]; } ), ign = all.length - gs.length, h = '', n = 0;
		h += '<section class="tf-chk-sec" aria-labelledby="tf-chk-h-dsum"><h2 id="tf-chk-h-dsum">' + esc( __( 'Summary', 'tokenforge' ) ) + '</h2>';
		h += '<p class="tf-chk-sum" tabindex="-1" data-fk="dsum"><strong>' + esc( gs.length ? dSumText( r ) : ( ign ? __( 'Every group is ignored for this session.', 'tokenforge' ) : __( 'Nothing found: the page follows your design tokens at this breakpoint, in light and dark mode.', 'tokenforge' ) ) ) + '</strong></p>';
		if ( ign ) { h += '<p>' + esc( sp( _n( '%d group is ignored for this session.', '%d groups are ignored for this session.', ign, 'tokenforge' ), ign ) ) + ' <button type="button" class="tf-btn tf-small" data-chk="unignore" data-fk="unign">' + esc( __( 'Show ignored groups', 'tokenforge' ) ) + '</button></p>'; }
		h += '<p class="tf-muted">' + esc( __( 'Groups are closed: open one to see where each value is. Hover, focus and the other breakpoints are not checked.', 'tokenforge' ) ) + '</p></section>';
		[
			[ 'raw', __( 'Typed values instead of tokens', 'tokenforge' ), __( 'Colours and font sizes typed in the settings where a token is near. Use token changes one element, or the one class the value is typed in.', 'tokenforge' ) ],
			[ 'contrast', __( 'Contrast', 'tokenforge' ), __( 'Text against what it is drawn on, measured on the page in light mode and again in dark mode.', 'tokenforge' ) ],
			[ 'size', __( 'Font sizes', 'tokenforge' ), __( 'Text sizes as drawn at this breakpoint that are not a step of the type scale, or are too small to read well.', 'tokenforge' ) ]
		].forEach( function ( s ) {
			var mine = gs.filter( function ( g ) { return g.sec === s[ 0 ]; } );
			if ( ! mine.length ) { return; }
			h += '<section class="tf-chk-sec" aria-labelledby="tf-chk-h-d' + s[ 0 ] + '"><h2 id="tf-chk-h-d' + s[ 0 ] + '">' + esc( s[ 1 ] ) + '</h2><p class="tf-muted">' + esc( s[ 2 ] ) + '</p>';
			h += mine.map( function ( g ) { return dGroupHtml( g, n++ ); } ).join( '' ) + '</section>';
		} );
		return h;
	}
	function dStatusText() {
		var r = S.d.res;
		if ( 'running' === S.d.phase ) { return __( 'Checking the design of the page...', 'tokenforge' ); }
		if ( 'error' === S.d.phase ) { return __( 'The last run did not finish.', 'tokenforge' ); }
		if ( r ) { return sp( __( 'Last run: %1$s. Checked: %2$s at the %3$s breakpoint, in light and dark mode.', 'tokenforge' ), new Date( r.ranAt || Date.now() ).toLocaleTimeString(), partWords( r.part ), r.breakpoint.label ); }
		return __( 'Not run yet. Press Run design check to compare the page with your design tokens: typed colours and sizes, contrast in light and dark mode, and font sizes. It checks the breakpoint the builder shows now.', 'tokenforge' );
	}
	function designHtml() {
		var d = S.d, busy = 'running' === d.phase, h = '';
		S.ids = {};
		d.all.forEach( function ( i, n ) { S.ids[ i.id ] = n; } );
		h += '<div class="tf-chk-run"><button type="button" class="tf-btn tf-primary" data-chk="audit" data-fk="audit"' + ( busy ? ' aria-disabled="true"' : '' ) + ' title="' + esc( __( 'Compare the page you are editing with your design tokens. Nothing is changed or saved.', 'tokenforge' ) ) + '">' + esc( __( 'Run design check', 'tokenforge' ) ) + '</button>';
		h += '<p class="tf-chk-status">' + esc( dStatusText() ) + '</p></div>';
		if ( 'error' === d.phase && d.err ) {
			h += '<div class="tf-chk-box"><p class="tf-chk-err"><strong>' + esc( __( 'The design check could not run.', 'tokenforge' ) ) + '</strong> ' + esc( errorText( d.err.code ) ) + '</p>';
			h += '<button type="button" class="tf-btn tf-small" data-chk="audit" data-fk="dretry">' + esc( __( 'Try again', 'tokenforge' ) ) + '</button></div>';
		}
		if ( d.stale && ! busy ) {
			h += '<div class="tf-chk-box tf-chk-stale"><p>' + esc( __( 'You changed the page, so this list is out of date until you check again. The builder\'s Undo takes a fix back.', 'tokenforge' ) ) + '</p><button type="button" class="tf-btn tf-small" data-chk="audit" data-fk="dagain">' + esc( __( 'Check again', 'tokenforge' ) ) + '</button></div>';
		}
		h += serverNote( d.res );
		if ( d.res ) { h += '<div class="tf-chk-results' + ( busy ? ' is-busy' : '' ) + '">' + dResultHtml( d.res ) + '</div>'; }
		return h;
	}
	function tabsHtml() {
		var t = function ( v, label ) {
			var on = S.view === v;
			return '<button type="button" role="tab" class="tf-chk-tab" id="tf-chk-tab-' + v + '" aria-selected="' + ( on ? 'true' : 'false' ) + '" aria-controls="tf-chk-pane" tabindex="' + ( on ? '0' : '-1' ) + '" data-chk="tab" data-v="' + v + '" data-fk="tab:' + v + '">' + esc( label ) + '</button>';
		};
		return '<div class="tf-chk-tabs" role="tablist" aria-label="' + esc( __( 'Page check', 'tokenforge' ) ) + '">' + t( 'a11y', __( 'Accessibility', 'tokenforge' ) ) + t( 'design', __( 'Design', 'tokenforge' ) ) + '</div>';
	}
	function viewHtml() {
		return tabsHtml() + '<div id="tf-chk-pane" role="tabpanel" aria-labelledby="tf-chk-tab-' + S.view + '">' + ( 'design' === S.view ? designHtml() : a11yHtml() ) + '</div>';
	}

	function focusKey( k ) {
		var all = root.querySelectorAll( '[data-fk]' ), el = null;
		for ( var i = 0; i < all.length; i++ ) { if ( all[ i ].getAttribute( 'data-fk' ) === k ) { el = all[ i ]; break; } }
		if ( el && el.focus ) { el.focus(); }
		return !! el;
	}
	function render( keep ) {
		if ( ! view ) { return; }
		var a = document.activeElement, fk = keep || ( a && root.contains( a ) && a.getAttribute ? a.getAttribute( 'data-fk' ) : null );
		view.innerHTML = viewHtml();
		view.setAttribute( 'aria-busy', 'running' === S.phase || 'running' === S.d.phase ? 'true' : 'false' );
		if ( fk ) { focusKey( fk ); }
	}

	// ---- Actions ----
	function refreshTree() {
		var c = eng();
		try { S.tree = c ? c.elementTree() : { ok: false, headings: [], focus: [] }; } catch ( e ) { S.tree = { ok: false, headings: [], focus: [] }; }
	}
	function frame() {
		return new Promise( function ( resolve ) {
			var fired = false, go = function () { if ( ! fired ) { fired = true; setTimeout( resolve, 0 ); } };
			if ( window.requestAnimationFrame ) { window.requestAnimationFrame( go ); }
			setTimeout( go, 100 ); // a hidden window gets no frames
		} );
	}
	function run() {
		if ( 'running' === S.phase && S.p ) { return S.p; }
		if ( 'running' === S.d.phase && S.d.p ) { return S.d.p; } // one run at a time: both use the canvas
		var c = eng();
		S.phase = 'running'; S.err = null;
		render( 'run' );
		announce( __( 'Checking the page...', 'tokenforge' ) );
		// The check blocks the builder's main thread: let the browser paint "Checking" first.
		S.p = frame().then( function () {
			if ( ! c ) { var e = new Error( 'no engine' ); e.code = 'no-engine'; throw e; }
			return c.run();
		} ).then( function ( res ) {
			S.res = res; S.phase = 'done'; drop( false ); S.stale = false;
			refreshTree();
			announce( doneText( res ) );
		}, function ( e ) {
			S.phase = 'error'; S.err = { code: e && e.code ? e.code : 'unknown', message: e && e.message ? e.message : '' };
			announce( sp( __( 'The check could not run. %s', 'tokenforge' ), errorText( S.err.code ) ) );
		} ).then( function () { S.p = null; render( 'run' ); } );
		return S.p;
	}
	// Forget the fix state of one view's items (Design ids start with "d:"), so a new run starts clean.
	function drop( design ) {
		[ S.fixed, S.note, S.vals, S.cin ].forEach( function ( m ) { Object.keys( m ).forEach( function ( k ) { if ( ( 0 === k.indexOf( 'd:' ) ) === design ) { delete m[ k ]; } } ); } );
	}
	function audit() {
		var d = S.d, c = eng();
		if ( 'running' === d.phase && d.p ) { return d.p; }
		if ( 'running' === S.phase && S.p ) { return S.p; }
		d.phase = 'running'; d.err = null;
		render( 'audit' );
		announce( __( 'Checking the design of the page...', 'tokenforge' ) );
		d.p = frame().then( function () {
			if ( ! c || ! c.audit ) { var e = new Error( 'no engine' ); e.code = 'no-engine'; throw e; }
			return c.audit( { colours: S.tokens } );
		} ).then( function ( res ) {
			d.res = res; d.phase = 'done'; d.stale = false; drop( true );
			d.all = [].concat.apply( res.contrast || [], ( res.raw || [] ).concat( res.sizes || [] ).map( function ( g ) { return g.items; } ) );
			announce( sp( __( 'Design check finished. %s', 'tokenforge' ), dGroups( res ).length ? dSumText( res ) : __( 'Nothing found.', 'tokenforge' ) ) );
		}, function ( e ) {
			d.phase = 'error'; d.err = { code: e && e.code ? e.code : 'unknown', message: e && e.message ? e.message : '' };
			announce( sp( __( 'The design check could not run. %s', 'tokenforge' ), errorText( d.err.code ) ) );
		} ).then( function () { d.p = null; render( 'audit' ); } );
		return d.p;
	}
	function tab( v ) { S.view = 'design' === v ? 'design' : 'a11y'; render( 'tab:' + S.view ); }
	function find( id ) { var l = ( ( S.res && S.res.items ) || [] ).concat( S.d.all ); for ( var i = 0; i < l.length; i++ ) { if ( l[ i ].id === id ) { return l[ i ]; } } return null; }
	function doShow( item, label, fk ) {
		var c = eng(), ok = false;
		try { ok = !! ( c && c.show( item ) ); } catch ( e ) { ok = false; }
		var had = !! S.note[ item.id ];
		if ( ok ) {
			delete S.note[ item.id ];
			announce( sp( __( 'Selected in the builder: %s', 'tokenforge' ), label ) );
		} else {
			var text = showFailText( item );
			S.note[ item.id ] = { text: text };
			if ( item.target && item.target.elementId && false !== item.target.editable ) { S.stale = true; }
			announce( text );
		}
		if ( ! ok || had ) { render( fk ); }
		// The builder may take focus when it selects the element: keep it in the panel.
		setTimeout( function () { if ( ! root.contains( document.activeElement ) ) { focusKey( fk ); } }, 0 );
	}
	function doFix( item, fixId ) {
		if ( 'running' === S.phase || 'running' === S.d.phase ) { return; }
		var f = ( item.fixes || [] ).filter( function ( x ) { return x.id === fixId; } )[ 0 ], key = item.id + '|' + fixId, c = eng(), r;
		var hasInput = !! ( f && f.input );
		try { r = c ? ( hasInput ? c.fix( item, fixId, S.vals[ key ] ) : c.fix( item, fixId ) ) : { ok: false, error: 'no-builder' }; } catch ( e ) { r = { ok: false, error: 'failed' }; }
		if ( r && r.ok ) {
			S.fixed[ item.id ] = { fixId: fixId, label: f ? f.label : '', before: r.before, after: r.after };
			delete S.note[ item.id ];
			S.stale = S.d.stale = true;
			announce( sp( __( 'Fixed: %s. Check again to update the list.', 'tokenforge' ), f ? f.label : '' ) );
			render( 'fixed:' + item.id );
			return;
		}
		var code = r && r.error ? r.error : 'failed', text = fixError( code, f );
		S.note[ item.id ] = { text: text, code: code };
		if ( 'gone' === code || 'changed' === code ) { S.stale = S.d.stale = true; }
		announce( text );
		render( 'needs-value' === code && hasInput ? 'val:' + key : 'fix:' + key );
	}

	function onClick( e ) {
		var b = e.target && e.target.closest ? e.target.closest( '[data-chk]' ) : null;
		if ( ! b || ! root.contains( b ) ) { return; }
		var a = b.getAttribute( 'data-chk' ), id = b.getAttribute( 'data-id' ), item = id ? find( id ) : null, fk = b.getAttribute( 'data-fk' );
		if ( 'run' === a ) { run(); }
		else if ( 'toggle' === a ) { var k = b.getAttribute( 'data-key' ); S.shut[ k ] = ! S.shut[ k ]; render( fk ); }
		else if ( 'tab' === a ) { tab( b.getAttribute( 'data-v' ) ); }
		else if ( 'audit' === a ) { audit(); }
		else if ( 'dtoggle' === a ) { var dk = b.getAttribute( 'data-key' ); S.d.open[ dk ] = ! S.d.open[ dk ]; render( fk ); }
		else if ( 'ignore' === a ) { S.d.ign[ b.getAttribute( 'data-key' ) ] = true; render( 'dsum' ); announce( __( 'Ignored for this session. The summary is updated.', 'tokenforge' ) ); }
		else if ( 'unignore' === a ) { S.d.ign = {}; render( 'dsum' ); announce( __( 'Ignored groups are shown again.', 'tokenforge' ) ); }
		else if ( 'tree' === a ) { refreshTree(); render( fk ); announce( S.tree && S.tree.ok ? sp( _n( 'Outline updated: %d heading', 'Outline updated: %d headings', S.tree.headings.length, 'tokenforge' ), S.tree.headings.length ) : __( 'The page is not open in the builder, so there is no outline.', 'tokenforge' ) ); }
		else if ( 'show' === a && item ) { doShow( item, ( item.target && ( item.target.label || item.target.name ) ) || __( 'the page', 'tokenforge' ), fk ); }
		else if ( 'showh' === a ) {
			var hd = S.tree && S.tree.headings && S.tree.headings[ +b.getAttribute( 'data-i' ) ];
			if ( hd ) { doShow( { id: 'outline:' + b.getAttribute( 'data-i' ), target: { elementId: hd.elementId, selector: '[data-id="' + hd.elementId + '"]', part: hd.part, editable: hd.part === ( S.tree.part || ( S.res && S.res.part ) || 'content' ) } }, 'h' + hd.level, fk ); }
		} else if ( 'fix' === a && item ) { doFix( item, b.getAttribute( 'data-fix' ) ); }
	}
	function onValue( e ) {
		var t = e.target;
		if ( t && t.getAttribute && 'cin' === t.getAttribute( 'data-chk' ) ) { onColour( t, 'change' === e.type ); return; }
		if ( t && t.getAttribute && 'val' === t.getAttribute( 'data-chk' ) ) { S.vals[ t.getAttribute( 'data-id' ) + '|' + t.getAttribute( 'data-fix' ) ] = t.value; }
	}
	function onKey( e ) {
		var t = e.target;
		// Tabs: arrow keys, Home and End move between the two views (the selected tab is the one Tab stop).
		if ( t && t.getAttribute && 'tab' === t.getAttribute( 'data-chk' ) && /^(ArrowLeft|ArrowRight|Home|End)$/.test( e.key ) ) {
			e.preventDefault();
			tab( 'Home' === e.key ? 'a11y' : ( 'End' === e.key ? 'design' : ( 'design' === S.view ? 'a11y' : 'design' ) ) );
			return;
		}
		if ( 'Enter' === e.key && t && t.getAttribute && 'val' === t.getAttribute( 'data-chk' ) && 'INPUT' === t.tagName ) {
			e.preventDefault();
			var item = find( t.getAttribute( 'data-id' ) );
			if ( item ) { onValue( e ); doFix( item, t.getAttribute( 'data-fix' ) ); }
		}
	}

	function build() {
		if ( root ) { return; }
		root = document.createElement( 'div' );
		root.className = 'tf-chk';
		live = document.createElement( 'div' );
		live.className = 'tf-chk-sr';
		live.setAttribute( 'role', 'status' );
		live.setAttribute( 'aria-live', 'polite' );
		live.setAttribute( 'aria-atomic', 'true' );
		view = document.createElement( 'div' );
		view.className = 'tf-chk-view';
		root.appendChild( live );
		root.appendChild( view );
		root.addEventListener( 'click', onClick );
		root.addEventListener( 'input', onValue );
		root.addEventListener( 'change', onValue );
		root.addEventListener( 'keydown', onKey );
		render();
	}
	// The dashboard calls this after every redraw of the Accessibility hub. opts.tokens: the colour token names the picker lists.
	function attach( host, opts ) {
		if ( ! host ) { return; }
		if ( opts && Array.isArray( opts.tokens ) ) { S.tokens = opts.tokens; }
		build();
		if ( root.parentNode !== host ) { host.appendChild( root ); }
	}

	window.TokenForgeCheckPanel = { attach: attach, run: run, audit: audit };
}() );
