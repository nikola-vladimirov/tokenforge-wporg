/* TokenForge: the Global SCSS editor, shared by the dashboard and the builder dock.
 *
 * TokenForgeScssEditor.create( textarea, options ) turns a textarea into the
 * editor: CodeMirror when the page has a full CodeMirror (wp-admin, some
 * builders), otherwise the textarea itself with a suggestion list at the caret.
 * Both modes get the same behaviour:
 *  - suggestions: properties, values, TokenForge variables (sorted by what fits
 *    the property), your site's classes and TokenForge classes, mixins,
 *    at-rules, SCSS variables and recipes (?name + Tab or ;);
 *  - brackets and quotes close themselves, typing a closer steps over it,
 *    Backspace between an empty pair removes both;
 *  - accepting a value finishes the declaration with ";", and Tab at the end of
 *    an unfinished declaration adds the ";";
 *  - Enter keeps the indentation and opens "{}" onto its own lines;
 *  - Ctrl/Cmd + S calls options.onSave;
 *  - an outline bar above the editor (scss-outline.js): "Jump to" lists the
 *    "/* # Title *\/" section comments (Ctrl/Cmd + Shift + O), Add section
 *    (Ctrl/Cmd + Enter) and Add sub-section (Ctrl/Cmd + Shift + Enter) insert
 *    one, and "#" then Tab turns into "/* # | *\/".
 *
 * Options: api( path, method, body ) → Promise, data() → { recipes: [], recipesOn,
 * ref: [] }, codeEditor (wp.codeEditor settings), height (px | 'fill'),
 * onChange( value ), onSave().
 */
( function () {
	'use strict';
	if ( window.TokenForgeScssEditor ) { return; }

	function esc( s ) {
		return String( s == null ? '' : s ).replace( /[&<>"']/g, function ( c ) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ c ];
		} );
	}

	function __( s, d ) { return window.wp && window.wp.i18n ? window.wp.i18n.__( s, d ) : s; }
	function _n( a, b, n, d ) { return window.wp && window.wp.i18n && window.wp.i18n._n ? window.wp.i18n._n( a, b, n, d ) : ( 1 === n ? a : b ); }
	// sprintf( '%1$s of %2$d', a, b ); also plain %s and %d in order.
	function sp( s ) { var a = [].slice.call( arguments, 1 ), k = 0; return String( s ).replace( /%(?:(\d+)\$)?[sd]/g, function ( m, p ) { return String( a[ p ? p - 1 : k++ ] ); } ); }
	var olUid = 0;

	// ---- Suggestion data ------------------------------------------------------
	var AC_PROPS = ( 'align-content align-items align-self all animation animation-delay animation-duration animation-name animation-timing-function aspect-ratio backdrop-filter background background-attachment background-blend-mode background-clip background-color background-image background-position background-repeat background-size block-size border border-block border-block-end border-block-start border-bottom border-color border-inline border-inline-end border-inline-start border-left border-radius border-right border-style border-top border-width bottom box-shadow box-sizing clip-path color column-count column-gap columns container container-name container-type content cursor display filter flex flex-basis flex-direction flex-flow flex-grow flex-shrink flex-wrap float font font-family font-size font-style font-weight gap grid grid-area grid-auto-columns grid-auto-flow grid-auto-rows grid-column grid-row grid-template grid-template-areas grid-template-columns grid-template-rows height hyphens inline-size inset inset-block inset-inline isolation justify-content justify-items justify-self left letter-spacing line-height list-style margin margin-block margin-block-end margin-block-start margin-bottom margin-inline margin-inline-end margin-inline-start margin-left margin-right margin-top mask-image max-block-size max-height max-inline-size max-width min-block-size min-height min-inline-size min-width mix-blend-mode object-fit object-position opacity order outline outline-color outline-offset outline-style outline-width overflow overflow-wrap overflow-x overflow-y padding padding-block padding-block-end padding-block-start padding-bottom padding-inline padding-inline-end padding-inline-start padding-left padding-right padding-top place-content place-items place-self pointer-events position right rotate row-gap scale scroll-behavior scroll-margin-top scroll-snap-align scroll-snap-type text-align text-decoration text-decoration-color text-decoration-thickness text-overflow text-shadow text-transform text-underline-offset text-wrap top transform transform-origin transition transition-delay transition-duration transition-property transition-timing-function translate user-select vertical-align visibility white-space width will-change word-break z-index' ).split( ' ' );
	var AC_PROP_SET = {};
	AC_PROPS.forEach( function ( p ) { AC_PROP_SET[ p ] = true; } );
	var AC_ALIGN = 'start end center stretch baseline flex-start flex-end';
	var AC_VALUES = {
		display: 'block inline inline-block flex inline-flex grid inline-grid contents none', position: 'static relative absolute fixed sticky',
		'flex-direction': 'row row-reverse column column-reverse', 'flex-wrap': 'nowrap wrap wrap-reverse',
		'justify-content': 'flex-start flex-end center space-between space-around space-evenly start end stretch', 'align-items': AC_ALIGN, 'align-self': 'auto ' + AC_ALIGN,
		'align-content': 'flex-start flex-end center space-between space-around stretch', 'justify-items': AC_ALIGN, 'justify-self': 'auto ' + AC_ALIGN,
		'place-items': AC_ALIGN, 'place-content': 'center start end space-between stretch', 'text-align': 'left right center justify start end',
		'text-transform': 'none uppercase lowercase capitalize', 'font-weight': '100 200 300 400 500 600 700 800 900 normal bold', 'font-style': 'normal italic',
		'text-decoration': 'none underline line-through', overflow: 'visible hidden clip scroll auto', 'overflow-x': 'visible hidden clip scroll auto', 'overflow-y': 'visible hidden clip scroll auto',
		visibility: 'visible hidden', cursor: 'pointer default text move not-allowed grab', 'border-style': 'none solid dashed dotted double', 'outline-style': 'none solid dashed dotted',
		'box-sizing': 'border-box content-box', 'object-fit': 'cover contain fill none scale-down', 'white-space': 'normal nowrap pre pre-wrap pre-line',
		'text-wrap': 'wrap nowrap balance pretty', 'pointer-events': 'auto none', isolation: 'auto isolate', 'aspect-ratio': 'auto 1 16/9 4/3 3/2 21/9',
		'container-type': 'inline-size size normal', 'grid-auto-flow': 'row column dense', 'user-select': 'none auto text all', 'word-break': 'normal break-word break-all keep-all',
		'overflow-wrap': 'normal break-word anywhere', 'scroll-behavior': 'auto smooth', 'list-style': 'none', 'vertical-align': 'baseline top middle bottom text-top text-bottom',
		'mix-blend-mode': 'normal multiply screen overlay darken lighten', 'transform-origin': 'center top left right bottom', float: 'none left right', margin: 'auto 0', 'margin-inline': 'auto 0', 'margin-block': 'auto 0', padding: '0', gap: '0', width: 'auto 100% fit-content max-content min-content', height: 'auto 100%', 'max-width': 'none 100%', 'min-height': '100vh 100dvh', 'inline-size': 'auto 100%', 'max-inline-size': 'none 100%', 'z-index': 'auto 0 1 10 100', opacity: '0 0.5 1', content: '""'
	};
	var AC_KEYWORDS = [ 'inherit', 'initial', 'unset', 'revert', 'currentColor', 'transparent' ];
	var AC_AT = [ [ '@include ', 'a mixin' ], [ '@media ', 'media query' ], [ '@container ', 'container query' ], [ '@supports ', 'feature query' ], [ '@extend ', 'extend a selector' ], [ '@keyframes ', 'animation' ], [ '@if ', 'condition' ], [ '@else ', 'otherwise' ], [ '@each ', 'loop over a list' ], [ '@for ', 'counted loop' ], [ '@mixin ', 'define a mixin' ], [ '@function ', 'define a function' ], [ '@return ', 'function result' ] ];
	var AC_SCSS_VARS = [ [ '$breakpoints', 'map of all breakpoints' ], [ '$breakpoint-xs', 'px' ], [ '$breakpoint-s', 'px' ], [ '$breakpoint-m', 'px' ], [ '$breakpoint-l', 'px' ], [ '$breakpoint-xl', 'px' ], [ '$breakpoint-xxl', 'px' ], [ '$vp-min', 'narrowest fluid viewport' ], [ '$vp-max', 'widest fluid viewport' ], [ '$root-font-size', 'html font size %' ] ];

	// Variables and classes, loaded once per page from /menu-data.
	var acData = null, acLoading = false;
	function acLoad( api ) {
		if ( acData || acLoading || ! api ) { return; }
		acLoading = true;
		api( '/menu-data' ).then( function ( r ) {
			acData = { vars: r.vars || [], classes: r.classes || [], site: r.site_classes || [] };
			acLoading = false;
		} ).catch( function () { acLoading = false; acData = { vars: [], classes: [], site: [] }; } );
	}

	// Which variables fit a property: a test on [ name, group, value ], or null.
	function acFits( prop ) {
		if ( ! prop ) { return null; }
		if ( /radius/.test( prop ) ) { return function ( v ) { return 'radius' === v[1] || /^radius/.test( v[0] ); }; }
		if ( /shadow/.test( prop ) ) { return function ( v ) { return 'shadow' === v[1] || /shadow/.test( v[0] ); }; }
		if ( /^(border|outline)(-(top|right|bottom|left|block|inline)(-(start|end))?)?$/.test( prop ) ) { return function ( v ) { return /^(border|divider)/.test( v[0] ); }; }
		if ( /color|background|fill|stroke|caret|accent/.test( prop ) ) { return function ( v ) { return 'color' === v[1]; }; }
		if ( /^(padding|margin|gap|row-gap|column-gap|inset|top|right|bottom|left|scroll-margin)/.test( prop ) ) { return function ( v ) { return ! /^(grid-|width|content-width|boxed-width|breakpoint)/.test( v[0] ) && ( 'space' === v[1] || /^(space|section-space|gutter|content-gap|container-gap|grid-gap)/.test( v[0] ) ); }; }
		if ( 'font-size' === prop ) { return function ( v ) { return /^(text-|h[1-6])/.test( v[0] ) && 'typography' === v[1]; }; }
		if ( /^(font|line-height|letter-spacing)/.test( prop ) ) { return function ( v ) { return 'typography' === v[1]; }; }
		if ( /^(max-|min-)?(width|inline-size)$/.test( prop ) ) { return function ( v ) { return /^(content-width|width-|gutter|boxed-width)/.test( v[0] ); }; }
		if ( /^grid-template/.test( prop ) ) { return function ( v ) { return /^grid-/.test( v[0] ); }; }
		if ( /^(transition|animation)/.test( prop ) ) { return function ( v ) { return /^(transition|ease)/.test( v[0] ); }; }
		return null;
	}
	// Filter + rank: prefix matches first, then word-part matches, then contains.
	function acRank( list, q, name ) {
		q = String( q || '' ).toLowerCase();
		var out = [];
		list.forEach( function ( x ) {
			var n = name( x ).toLowerCase(), i = q ? n.indexOf( q ) : 0;
			if ( i < 0 ) { return; }
			out.push( { x: x, s: 0 === i ? 0 : ( /[-_.]/.test( n.charAt( i - 1 ) ) ? 1 : 2 ), n: n } );
		} );
		out.sort( function ( a, b ) { return a.s - b.s || a.n.length - b.n.length || ( a.n < b.n ? -1 : 1 ); } );
		return out.map( function ( o ) { return o.x; } );
	}
	function acDepth( text ) {
		var d = 0, t = text.replace( /\/\*[\s\S]*?(\*\/|$)/g, '' ).replace( /\/\/[^\n]*/g, '' );
		for ( var i = 0; i < t.length; i++ ) { if ( '{' === t[ i ] ) { d++; } else if ( '}' === t[ i ] && d > 0 ) { d--; } }
		return d;
	}

	// ---- Recipes: where they go ------------------------------------------------
	// 'value' = inside a declaration value ("color: |"), 'block' = anywhere else.
	function recipeSpot( textBefore ) {
		var cut = Math.max( textBefore.lastIndexOf( '{' ), textBefore.lastIndexOf( '}' ), textBefore.lastIndexOf( ';' ) );
		var stmt = textBefore.slice( cut + 1 ).replace( /^\s+/, '' );
		return acDepth( textBefore ) > 0 && /^-*[a-z][a-z-]*\s*:/i.test( stmt ) ? 'value' : 'block';
	}
	// 'value' (a colour …), 'block' (declarations / rules) or 'text' (not CSS: never here).
	function recipeKind( r ) { return r.kind || ( r.inline ? 'value' : 'block' ); }
	function rName( r ) { return r.n; }
	// What a recipe puts at a spot: { text, caret (offset | -1), select (chars from the start), semi }
	// or null when it doesn't belong there. A value on its own line becomes a
	// declaration with the property selected, so it can be typed over.
	function recipeText( r, spot, indent, typedSemi ) {
		var k = recipeKind( r ), code = String( r.code );
		if ( 'text' === k ) { return null; }
		if ( 'value' === k ) {
			var v = code.replace( '{{cursor}}', '' );
			if ( 'value' === spot ) { return { text: v + ( typedSemi ? ';' : '' ), caret: -1, select: 0, semi: ! typedSemi }; }
			var prop = 'Color' === r.c ? 'color' : 'property';
			return { text: prop + ': ' + v + ';', caret: -1, select: prop.length, semi: false };
		}
		if ( 'value' === spot ) { return null; }
		var t = ( r.scss || code.replace( /%root%/g, '&' ) ).replace( /\n/g, '\n' + ( indent || '' ) ), i = t.indexOf( '{{cursor}}' );
		return { text: t.replace( '{{cursor}}', '' ), caret: i, select: 0, semi: false };
	}

	// What to suggest at the caret. textBefore = everything before the caret.
	// Returns { from: chars to replace, items: [ { label, detail, insert, after, select, semi } ] } or null.
	function acContext( textBefore, force, data ) {
		var line = textBefore.slice( textBefore.lastIndexOf( '\n' ) + 1 );
		var cut = Math.max( textBefore.lastIndexOf( '{' ), textBefore.lastIndexOf( '}' ), textBefore.lastIndexOf( ';' ) );
		var stmt = textBefore.slice( cut + 1 ).replace( /^\s+/, '' );
		var m, items = [], d = acData || { vars: [], classes: [], site: [] };
		if ( /\/\/[^\n]*$/.test( line ) ) { return null; } // in a comment
		if ( data.recipesOn && ( m = /(^|[^\w?])\?([a-z0-9-]*)$/.exec( line ) ) ) {
			// Only recipes that fit here: values inside a value; elsewhere declarations
			// and rules first, then values (they expand to "color: …;").
			var spot = recipeSpot( textBefore.slice( 0, textBefore.length - m[2].length - 1 ) );
			var fits = function ( k ) { return data.recipes.filter( function ( r ) { return recipeKind( r ) === k; } ); };
			var pool = 'value' === spot ? acRank( fits( 'value' ), m[2], rName ) : acRank( fits( 'block' ), m[2], rName ).concat( acRank( fits( 'value' ), m[2], rName ) );
			pool.slice( 0, 60 ).forEach( function ( r ) {
				var asDecl = 'block' === spot && 'value' === recipeKind( r );
				items.push( { label: '?' + r.n, detail: r.c + ( asDecl ? ' value (adds a declaration)' : ' recipe' ), insert: '?' + r.n, after: 'recipe' } );
			} );
			return items.length ? { from: m[2].length + 1, items: items } : null;
		}
		if ( ( m = /@include\s+([\w-]*)$/.exec( line ) ) ) {
			acRank( ( data.ref || [] ).filter( function ( r ) { return 'mixin' === r[2]; } ), m[1], function ( r ) { return r[0]; } ).forEach( function ( r ) {
				var sig = r[1].replace( /^@include\s+/, '' ), block = /\{\s*…?\s*\}\s*$/.test( sig );
				items.push( { label: r[0], detail: r[3], insert: block ? sig.replace( /\s*\{[\s\S]*$/, '' ) : sig, select: 'args', block: block } );
			} );
			return items.length ? { from: m[1].length, items: items } : null;
		}
		if ( ( m = /(^|\s)@([\w-]*)$/.exec( line ) ) ) {
			acRank( AC_AT, m[2], function ( a ) { return a[0].slice( 1 ); } ).forEach( function ( a ) { items.push( { label: a[0].trim(), detail: a[1], insert: a[0], after: '@include ' === a[0] ? 'reopen' : '' } ); } );
			return items.length ? { from: m[2].length + 1, items: items } : null;
		}
		if ( ( m = /\$([\w-]*)$/.exec( line ) ) ) {
			acRank( AC_SCSS_VARS, m[1], function ( v ) { return v[0].slice( 1 ); } ).forEach( function ( v ) { items.push( { label: v[0], detail: v[1], insert: v[0] } ); } );
			return items.length ? { from: m[1].length + 1, items: items } : null;
		}
		var inDecl = /^([a-z-]+)\s*:\s*([^;{}]*)$/i.exec( stmt );
		var prop = inDecl && AC_PROP_SET[ inDecl[1].toLowerCase() ] ? inDecl[1].toLowerCase() : '';
		// Variables: after "--" in a value, or inside var(.
		if ( ( m = /(var\(\s*)?--([\w-]*)$/.exec( line ) ) && ( m[1] || prop ) ) {
			var fit = acFits( prop );
			var vars = acRank( d.vars, m[2], function ( v ) { return v[0]; } );
			if ( fit ) { vars = vars.filter( fit ).concat( vars.filter( function ( v ) { return ! fit( v ); } ) ); }
			vars.slice( 0, 80 ).forEach( function ( v ) {
				items.push( { label: '--' + v[0], detail: v[2], insert: m[1] ? '--' + v[0] + ')' : 'var(--' + v[0] + ')', close: !! m[1], semi: !! prop } );
			} );
			return items.length ? { from: m[2].length + 2, items: items } : null;
		}
		if ( prop ) {
			var vq = /([a-z0-9-]*)$/i.exec( inDecl[2] )[1];
			if ( ! vq && ! force && ! /:\s$/.test( stmt ) ) { return null; }
			var own = String( AC_VALUES[ prop ] || '' ).split( ' ' ).filter( Boolean );
			var generic = /color|background|fill|stroke|border|outline|caret|accent/.test( prop ) ? AC_KEYWORDS : AC_KEYWORDS.slice( 0, 4 );
			acRank( own, vq, function ( k ) { return k; } ).slice( 0, 20 ).forEach( function ( k ) { items.push( { label: k, detail: 'value', insert: k, semi: true } ); } );
			var fit2 = acFits( prop );
			if ( vq || fit2 ) {
				var pool = acRank( d.vars, vq, function ( v ) { return v[0]; } );
				if ( fit2 ) { pool = pool.filter( fit2 ).concat( vq ? pool.filter( function ( v ) { return ! fit2( v ); } ) : [] ); }
				pool.slice( 0, 60 ).forEach( function ( v ) { items.push( { label: 'var(--' + v[0] + ')', detail: v[2], insert: 'var(--' + v[0] + ')', semi: true } ); } );
			}
			acRank( generic, vq, function ( k ) { return k; } ).forEach( function ( k ) { items.push( { label: k, detail: 'value', insert: k, semi: true } ); } );
			if ( items.length === 1 && items[0].insert === vq ) { return null; }
			return items.length ? { from: vq.length, items: items } : null;
		}
		// Classes in a selector: your site's (Bricks global classes) first, then TokenForge's.
		if ( ( m = /(^|[\s,>+~(&:])\.([\w-]*)$/.exec( stmt ) ) && ! /:\s*[^\s]/.test( stmt.replace( /&?:[a-z-]+(\([^)]*\))?/gi, '' ) ) ) {
			acRank( d.site, m[2], function ( c ) { return c; } ).slice( 0, 60 ).forEach( function ( c ) { items.push( { label: '.' + c, detail: 'your class', insert: c } ); } );
			acRank( d.classes, m[2], function ( c ) { return c; } ).slice( 0, 60 ).forEach( function ( c ) { items.push( { label: '.' + c, detail: 'TokenForge', insert: c } ); } );
			return items.length ? { from: m[2].length, items: items } : null;
		}
		// Property names inside a rule.
		if ( ( m = /^([a-z-]+)$/i.exec( stmt ) ) && acDepth( textBefore ) > 0 && ( force || m[1].length >= 1 ) ) {
			acRank( AC_PROPS, m[1], function ( p ) { return p; } ).slice( 0, 40 ).forEach( function ( p ) { items.push( { label: p, detail: 'property', insert: p + ': ', after: 'reopen' } ); } );
			if ( items.length === 1 && items[0].label === m[1] ) { return null; }
			return items.length ? { from: m[1].length, items: items } : null;
		}
		return null;
	}

	// After a value was put in: add ";" when the declaration is complete (no open
	// parenthesis in the value) and nothing but whitespace follows on the line.
	function wantsSemi( textBefore, rest ) {
		if ( ! /^\s*$/.test( rest ) ) { return false; }
		var cut = Math.max( textBefore.lastIndexOf( '{' ), textBefore.lastIndexOf( '}' ), textBefore.lastIndexOf( ';' ) );
		var stmt = textBefore.slice( cut + 1 ), c = stmt.indexOf( ':' );
		if ( c < 0 ) { return false; }
		var val = stmt.slice( c + 1 ), depth = 0;
		for ( var i = 0; i < val.length; i++ ) { if ( '(' === val[ i ] ) { depth++; } else if ( ')' === val[ i ] ) { depth--; } }
		return 0 === depth;
	}
	// Tab at the end of an unfinished declaration ("color: red" or "@include x(1)") adds the ";".
	function tabSemi( textBefore, rest ) {
		if ( ! /^[ \t]*$/.test( rest.split( '\n' )[0] ) ) { return false; }
		var line = textBefore.slice( textBefore.lastIndexOf( '\n' ) + 1 );
		if ( /[;{},]\s*$|^\s*$|\/\/|\/\*/.test( line ) || acDepth( textBefore ) < 1 ) { return false; }
		return ( /^\s*[a-z-]+\s*:\s*\S/i.test( line ) && wantsSemi( textBefore, '' ) ) || /^\s*@(include|extend)\s+\S.*\)?\s*$/.test( line ) && ! /\{\s*$/.test( line );
	}

	var PAIRS = { '{': '}', '(': ')', '[': ']', '"': '"', "'": "'" };
	var CLOSERS = { '}': true, ')': true, ']': true };
	// Close a pair only before whitespace, a closer, ";" or "," (like code editors do).
	function closeBefore( next ) { return '' === next || /[\s)\]};,]/.test( next ); }

	// ---- One editor -----------------------------------------------------------
	function create( ta, opts ) {
		opts = opts || {};
		var data = function () { var d = opts.data ? opts.data() : {}; return { recipes: d.recipes || [], recipesOn: !! d.recipesOn, ref: d.ref || [] }; };
		var changed = function ( v ) { if ( opts.onChange ) { opts.onChange( v ); } };
		var ed = { ta: ta, cm: null, errLine: -1 };
		acLoad( opts.api );

		// Recipes.
		function recipeMap() {
			var map = {};
			data().recipes.forEach( function ( r ) { map[ r.n ] = r; } );
			return map;
		}
		// ---- CodeMirror ----
		// Put recipe r between from and to (it replaces "?name" or the selection).
		function cmRecipe( c, r, from, to, typedSemi ) {
			var res = recipeText( r, recipeSpot( c.getRange( { line: 0, ch: 0 }, from ) ), /^\s*/.exec( c.getLine( from.line ) )[0], typedSemi );
			if ( ! res ) { return false; }
			var start = c.indexFromPos( from );
			c.replaceRange( res.text, from, to, '+tfrecipe' );
			if ( res.select ) { c.setSelection( c.posFromIndex( start ), c.posFromIndex( start + res.select ) ); }
			else if ( res.caret > -1 ) { c.setCursor( c.posFromIndex( start + res.caret ) ); }
			else if ( res.semi ) {
				var cur = c.getCursor();
				if ( wantsSemi( c.getRange( { line: 0, ch: 0 }, cur ), c.getLine( cur.line ).slice( cur.ch ) ) ) { c.replaceRange( ';', cur, cur, '+tfrecipe' ); }
			}
			return true;
		}
		function cmExpand( c ) {
			if ( ! data().recipesOn ) { return false; }
			var cur = c.getCursor(), before = c.getLine( cur.line ).slice( 0, cur.ch );
			var m = /\?([a-z0-9-]+)(;?)$/.exec( before ), r = m && recipeMap()[ m[1] ];
			return !! r && cmRecipe( c, r, { line: cur.line, ch: cur.ch - m[0].length }, cur, !! m[2] );
		}
		function cmAccept( cm, it, from, to ) {
			var ins = it.insert, indent = /^\s*/.exec( cm.getLine( from.line ) )[0], skip = false;
			if ( it.close && ')' === cm.getRange( to, { line: to.line, ch: to.ch + 1 } ) ) { ins = ins.replace( /\)$/, '' ); skip = true; }
			if ( it.block ) { ins += ' {\n' + indent + '\t\n' + indent + '}'; }
			cm.replaceRange( ins, from, to, '+tfcomplete' );
			var start = cm.indexFromPos( from );
			if ( skip ) { var at = cm.getCursor(); cm.setCursor( { line: at.line, ch: at.ch + 1 } ); } // past the ) that was already there
			if ( it.semi ) {
				var cur = cm.getCursor(), textBefore = cm.getRange( { line: 0, ch: 0 }, cur ), rest = cm.getLine( cur.line ).slice( cur.ch );
				if ( wantsSemi( textBefore, rest ) ) { cm.replaceRange( ';', cur, cur, '+tfcomplete' ); }
			}
			if ( 'args' === it.select ) {
				var o = ins.indexOf( '(' ), cl = ins.indexOf( ')' );
				if ( o > -1 && cl > o + 1 ) { cm.setSelection( cm.posFromIndex( start + o + 1 ), cm.posFromIndex( start + cl ) ); }
				else if ( it.block ) { cm.setCursor( cm.posFromIndex( start + ins.indexOf( '\t\n' ) + 1 ) ); }
			}
			if ( 'recipe' === it.after ) { cmExpand( cm ); }
			if ( 'reopen' === it.after ) { setTimeout( function () { cm.state.tfForce = true; cm.showHint(); }, 0 ); }
		}
		function cmHint( c ) {
			var cur = c.getCursor();
			var ctx = acContext( c.getRange( { line: 0, ch: 0 }, cur ), c.state.tfForce, data() );
			c.state.tfForce = false;
			if ( ! ctx ) { return null; }
			var from = { line: cur.line, ch: Math.max( 0, cur.ch - ctx.from ) };
			return {
				from: from, to: cur,
				list: ctx.items.map( function ( it ) {
					return {
						text: it.insert, displayText: it.label + ( it.detail ? '   ' + String( it.detail ).slice( 0, 60 ) : '' ),
						hint: function ( cm, d ) { cmAccept( cm, it, d.from, d.to ); }
					};
				} )
			};
		}
		function cmPair( open ) {
			return function ( c ) {
				var Pass = c.constructor.Pass;
				if ( c.getSelections().length > 1 ) { return Pass; }
				var close = PAIRS[ open ], from = c.getCursor( 'from' ), to = c.getCursor( 'to' );
				var next = c.getRange( to, { line: to.line, ch: to.ch + 1 } ), prev = c.getRange( { line: from.line, ch: Math.max( 0, from.ch - 1 ) }, from );
				if ( c.somethingSelected() ) {
					var sel = c.getSelection();
					c.replaceSelection( open + sel + close, 'around' );
					c.setSelection( { line: from.line, ch: from.ch + 1 }, c.posFromIndex( c.indexFromPos( from ) + 1 + sel.length ) );
					return;
				}
				if ( open === close ) { // quote
					if ( next === open ) { c.setCursor( { line: to.line, ch: to.ch + 1 } ); return; }
					if ( /[\w\\]/.test( prev ) ) { return Pass; }
				}
				if ( ! closeBefore( next ) ) { return Pass; }
				c.replaceRange( open + close, from, to, '+input' );
				c.setCursor( { line: from.line, ch: from.ch + 1 } );
				if ( '(' === open && c.showHint ) { setTimeout( function () { c.showHint(); }, 0 ); }
			};
		}
		function cmSkip( close ) {
			return function ( c ) {
				var cur = c.getCursor();
				if ( c.somethingSelected() || c.getRange( cur, { line: cur.line, ch: cur.ch + 1 } ) !== close ) { return c.constructor.Pass; }
				c.setCursor( { line: cur.line, ch: cur.ch + 1 } );
			};
		}
		function setupCm( cm ) {
			ed.cm = cm;
			try { cm.setSize( null, 'fill' === opts.height ? '100%' : ( opts.height || 440 ) ); } catch ( e ) {}
			// Own theme name: our hint list gets ".CodeMirror-hints.tf" (the host's lists keep their look).
			try { cm.setOption( 'theme', 'tf' ); } catch ( e2 ) {}
			try { cm.setOption( 'autoCloseBrackets', false ); } catch ( e3 ) {} // our own pairs below
			cm.setOption( 'hintOptions', { hint: cmHint, completeSingle: false, closeCharacters: /[\s()\[\]{};>,]/ } );
			cm.on( 'change', function () { changed( cm.getValue() ); } );
			var keys = {
				Tab: function ( c ) {
					if ( cmExpand( c ) || olSnippet() ) { return; }
					var cur = c.getCursor();
					if ( ! c.somethingSelected() && tabSemi( c.getRange( { line: 0, ch: 0 }, cur ), c.getLine( cur.line ).slice( cur.ch ) ) ) { c.replaceRange( ';', cur, cur, '+input' ); return; }
					c.execCommand( c.somethingSelected() ? 'indentMore' : 'insertTab' );
				},
				'Ctrl-Space': function ( c ) { if ( c.showHint ) { c.state.tfForce = true; c.showHint(); } },
				Backspace: function ( c ) {
					var cur = c.getCursor();
					if ( c.somethingSelected() || cur.ch < 1 ) { return c.constructor.Pass; }
					var pair = c.getRange( { line: cur.line, ch: cur.ch - 1 }, { line: cur.line, ch: cur.ch + 1 } );
					if ( 2 === pair.length && PAIRS[ pair[0] ] === pair[1] ) { c.replaceRange( '', { line: cur.line, ch: cur.ch - 1 }, { line: cur.line, ch: cur.ch + 1 }, '+delete' ); return; }
					return c.constructor.Pass;
				},
				Enter: function ( c ) {
					var cur = c.getCursor();
					if ( c.somethingSelected() || '{}' !== c.getRange( { line: cur.line, ch: cur.ch - 1 }, { line: cur.line, ch: cur.ch + 1 } ) ) { return c.constructor.Pass; }
					var indent = /^\s*/.exec( c.getLine( cur.line ) )[0];
					c.replaceRange( '\n' + indent + '\t\n' + indent, cur, cur, '+input' );
					c.setCursor( { line: cur.line + 1, ch: indent.length + 1 } );
				}
			};
			if ( opts.onSave ) { keys['Ctrl-S'] = keys['Cmd-S'] = function () { opts.onSave(); }; }
				keys['Ctrl-Enter'] = keys['Cmd-Enter'] = function () { if ( OL ) { olAdd( false ); } };
				keys['Shift-Ctrl-Enter'] = keys['Shift-Cmd-Enter'] = function () { if ( OL ) { olAdd( true ); } };
				keys['Shift-Ctrl-O'] = keys['Shift-Cmd-O'] = function () { if ( OL ) { olOpen(); } };
			Object.keys( PAIRS ).forEach( function ( o ) { keys[ "'" + o + "'" ] = cmPair( o ); } );
			Object.keys( CLOSERS ).forEach( function ( cl ) { keys[ "'" + cl + "'" ] = cmSkip( cl ); } );
			cm.addKeyMap( keys );
			cm.on( 'inputRead', function ( c, ch ) {
				var typed = ( ch && ch.text ) ? ch.text.join( '' ) : '';
				if ( /;$/.test( typed ) && cmExpand( c ) ) { return; }
				if ( c.showHint && /[\w?\-.$@(:]$/.test( typed ) && ! ( c.state.completionActive && c.state.completionActive.widget ) ) {
					c.showHint();
				}
			} );
		}

		// ---- Plain textarea ----
		var ac = { el: null, items: [], sel: 0, from: 0 }, quiet = false;
		// Insert keeping the browser's undo history where possible.
		function put( text, start, end ) {
			ta.focus();
			ta.setSelectionRange( start, end );
			quiet = true;
			var ok = false;
			try { ok = document.execCommand && document.execCommand( 'insertText', false, text ); } catch ( e ) { ok = false; }
			quiet = false;
			if ( ! ok || ta.value.slice( start, start + text.length ) !== text ) { ta.setRangeText( text, start, end, 'end' ); }
		}
		// Put recipe r between start and end (it replaces "?name" or the selection).
		function taRecipe( r, start, end, typedSemi ) {
			var before = ta.value.slice( 0, start );
			var res = recipeText( r, recipeSpot( before ), /^[ \t]*/.exec( before.slice( before.lastIndexOf( '\n' ) + 1 ) )[0], typedSemi );
			if ( ! res ) { return false; }
			put( res.text, start, end );
			var stop = start + res.text.length;
			if ( res.select ) { ta.setSelectionRange( start, start + res.select ); }
			else if ( res.caret > -1 ) { ta.setSelectionRange( start + res.caret, start + res.caret ); }
			else if ( res.semi && wantsSemi( ta.value.slice( 0, stop ), ta.value.slice( stop ).split( '\n' )[0] ) ) { put( ';', stop, stop ); }
			return true;
		}
		function taExpand() {
			if ( ! data().recipesOn ) { return false; }
			var pos = ta.selectionStart, before = ta.value.slice( 0, pos );
			var m = /\?([a-z0-9-]+)(;?)$/.exec( before ), r = m && recipeMap()[ m[1] ];
			return !! r && taRecipe( r, pos - m[0].length, pos, !! m[2] );
		}
		function acCaret() {
			var box = ta.parentNode, cs = window.getComputedStyle( ta ), mirror = document.createElement( 'div' );
			[ 'fontFamily', 'fontSize', 'fontWeight', 'lineHeight', 'letterSpacing', 'paddingTop', 'paddingRight', 'paddingBottom', 'paddingLeft', 'borderTopWidth', 'borderLeftWidth', 'borderRightWidth', 'borderBottomWidth', 'boxSizing', 'tabSize' ].forEach( function ( p ) { mirror.style[ p ] = cs[ p ]; } );
			mirror.style.position = 'absolute'; mirror.style.visibility = 'hidden'; mirror.style.whiteSpace = 'pre-wrap'; mirror.style.wordWrap = 'break-word'; mirror.style.borderStyle = 'solid';
			mirror.style.width = ta.offsetWidth + 'px'; mirror.style.left = ta.offsetLeft + 'px'; mirror.style.top = ta.offsetTop + 'px';
			mirror.textContent = ta.value.slice( 0, ta.selectionStart );
			var mark = document.createElement( 'span' ); mark.textContent = '\u200b' /* zero-width space */; mirror.appendChild( mark );
			box.appendChild( mirror );
			var pos = { left: ta.offsetLeft + mark.offsetLeft - ta.scrollLeft, top: ta.offsetTop + mark.offsetTop - ta.scrollTop, h: mark.offsetHeight || parseFloat( cs.lineHeight ) || 18 };
			mirror.remove();
			return pos;
		}
		function acHide() { if ( ac.el ) { ac.el.remove(); } ac.el = null; ac.items = []; }
		function acShow( ctx ) {
			ac.items = ctx.items.slice( 0, 80 ); ac.sel = 0; ac.from = ctx.from;
			if ( ! ac.el || ! ac.el.isConnected ) {
				ac.el = document.createElement( 'ul' );
				ac.el.className = 'tf-ac-list';
				ac.el.setAttribute( 'role', 'listbox' );
				ac.el.addEventListener( 'mousedown', function ( e ) { e.preventDefault(); } ); // keep the caret
				ac.el.addEventListener( 'click', function ( e ) { var li = e.target.closest( 'li[data-i]' ); if ( li ) { acAccept( parseInt( li.getAttribute( 'data-i' ), 10 ) ); } } );
				ta.parentNode.appendChild( ac.el );
			}
			ac.el.innerHTML = ac.items.map( function ( it, i ) {
				return '<li role="option" data-i="' + i + '"' + ( i === ac.sel ? ' class="is-sel" aria-selected="true"' : '' ) + '><code>' + esc( it.label ) + '</code><span>' + esc( String( it.detail || '' ).slice( 0, 70 ) ) + '</span></li>';
			} ).join( '' );
			var c = acCaret(), boxW = ta.parentNode.clientWidth, w = Math.min( 420, boxW - 8 );
			ac.el.style.width = w + 'px';
			ac.el.style.left = Math.max( 0, Math.min( c.left, boxW - w - 4 ) ) + 'px';
			var below = c.top + c.h + 4, taBottom = ta.offsetTop + ta.offsetHeight;
			ac.el.style.top = ( below + 220 > taBottom && c.top - 224 > ta.offsetTop ? c.top - 224 : below ) + 'px';
		}
		function acMove( dx ) {
			if ( ! ac.el ) { return; }
			ac.sel = ( ac.sel + dx + ac.items.length ) % ac.items.length;
			ac.el.querySelectorAll( 'li' ).forEach( function ( li, i ) { li.classList.toggle( 'is-sel', i === ac.sel ); li.setAttribute( 'aria-selected', i === ac.sel ? 'true' : 'false' ); } );
			var cur = ac.el.querySelector( 'li.is-sel' );
			if ( cur && cur.scrollIntoView ) { cur.scrollIntoView( { block: 'nearest' } ); }
		}
		function acUpdate( force ) {
			var ctx = ta.selectionStart === ta.selectionEnd ? acContext( ta.value.slice( 0, ta.selectionStart ), force, data() ) : null;
			if ( ctx ) { acShow( ctx ); } else { acHide(); }
		}
		function acAccept( i ) {
			var it = ac.items[ i ];
			if ( ! it ) { acHide(); return; }
			var pos = ta.selectionStart, start = Math.max( 0, pos - ac.from ), ins = it.insert, skip = false;
			var lineStart = ta.value.lastIndexOf( '\n', start - 1 ) + 1, indent = /^[ \t]*/.exec( ta.value.slice( lineStart ) )[0];
			if ( it.close && ')' === ta.value.charAt( pos ) ) { ins = ins.replace( /\)$/, '' ); skip = true; }
			if ( it.block ) { ins += ' {\n' + indent + '\t\n' + indent + '}'; }
			acHide();
			put( ins, start, pos );
			var end = start + ins.length;
			if ( skip ) { end += 1; ta.setSelectionRange( end, end ); } // past the ) that was already there
			if ( it.semi ) {
				var restLine = ta.value.slice( end ).split( '\n' )[0];
				if ( wantsSemi( ta.value.slice( 0, end ), restLine ) ) { put( ';', end, end ); }
			}
			if ( 'args' === it.select ) {
				var o = ins.indexOf( '(' ), cl = ins.indexOf( ')' );
				if ( o > -1 && cl > o + 1 ) { ta.setSelectionRange( start + o + 1, start + cl ); }
				else if ( it.block ) { ta.selectionStart = ta.selectionEnd = start + ins.indexOf( '\t\n' ) + 1; }
			}
			if ( 'recipe' === it.after ) { taExpand(); }
			changed( ta.value );
			if ( 'reopen' === it.after ) { acUpdate( true ); }
		}
		function setupTextarea() {
			ta.classList.add( 'tf-mono', 'tf-scss-plain' );
			ta.parentNode.classList.add( 'tf-ac-box' );
			ta.addEventListener( 'input', function ( e ) {
				if ( quiet ) { return; }
				if ( /^insert/.test( e.inputType || 'insertText' ) && /;$/.test( e.data || '' ) && taExpand() ) { acHide(); changed( ta.value ); return; }
				changed( ta.value );
				if ( /^(insert|delete)/.test( e.inputType || 'insertText' ) ) { acUpdate(); }
			} );
			// Pairs on beforeinput, so typed and inserted characters behave the same:
			// an opener adds its closer (or wraps the selection), a closer steps over
			// the same closer, Backspace between an empty pair removes both.
			ta.addEventListener( 'beforeinput', function ( e ) {
				if ( quiet || e.isComposing ) { return; }
				var s = ta.selectionStart, en = ta.selectionEnd, v = ta.value, ch = e.data;
				if ( 'insertText' === e.inputType && ch && 1 === ch.length ) {
					if ( PAIRS[ ch ] ) {
						var close = PAIRS[ ch ], next = v.charAt( en ), prev = v.charAt( s - 1 );
						if ( s !== en ) { e.preventDefault(); var sel = v.slice( s, en ); put( ch + sel + close, s, en ); ta.setSelectionRange( s + 1, s + 1 + sel.length ); changed( ta.value ); return; }
						if ( ch === close ) { // quote
							if ( next === ch ) { e.preventDefault(); ta.setSelectionRange( s + 1, s + 1 ); return; }
							if ( /[\w\\]/.test( prev ) ) { return; }
						}
						if ( closeBefore( next ) ) { e.preventDefault(); put( ch + close, s, en ); ta.setSelectionRange( s + 1, s + 1 ); changed( ta.value ); acUpdate(); }
						return;
					}
					if ( CLOSERS[ ch ] && s === en && v.charAt( s ) === ch ) { e.preventDefault(); ta.setSelectionRange( s + 1, s + 1 ); acHide(); }
					return;
				}
				if ( 'deleteContentBackward' === e.inputType && s === en && s > 0 && v.charAt( s ) && PAIRS[ v.charAt( s - 1 ) ] === v.charAt( s ) ) {
					e.preventDefault(); put( '', s - 1, s + 1 ); changed( ta.value ); acUpdate();
				}
			} );
			ta.addEventListener( 'keydown', function ( e ) {
				var s = ta.selectionStart, en = ta.selectionEnd, v = ta.value, plain = ! e.ctrlKey && ! e.metaKey && ! e.altKey;
				if ( ac.el && ac.items.length ) {
					if ( 'ArrowDown' === e.key ) { e.preventDefault(); acMove( 1 ); return; }
					if ( 'ArrowUp' === e.key ) { e.preventDefault(); acMove( -1 ); return; }
					if ( 'Enter' === e.key || ( 'Tab' === e.key && ! e.shiftKey ) ) { e.preventDefault(); acAccept( ac.sel ); return; }
					if ( 'Escape' === e.key ) { e.preventDefault(); e.stopPropagation(); acHide(); return; }
				}
				if ( ( e.ctrlKey || e.metaKey ) && ! e.altKey && 's' === String( e.key ).toLowerCase() ) {
					if ( opts.onSave ) { e.preventDefault(); e.stopPropagation(); opts.onSave(); }
					return;
				}
				if ( ( e.ctrlKey || e.metaKey ) && ' ' === e.key ) { e.preventDefault(); acUpdate( true ); return; }
				if ( olKey( e ) ) { acHide(); return; }
				if ( 'Tab' === e.key && ! e.shiftKey && plain ) {
					e.preventDefault();
					if ( ! taExpand() && ! olSnippet() ) {
						if ( s === en && tabSemi( v.slice( 0, s ), v.slice( s ) ) ) { put( ';', s, s ); } else { put( '\t', s, en ); }
					}
					changed( ta.value );
					return;
				}
				if ( 'Enter' === e.key && ! e.shiftKey && plain ) {
					// Keep the indentation; one level deeper after "{"; split "{}" onto three lines.
					var before = v.slice( 0, s ), indent = /^[ \t]*/.exec( before.slice( before.lastIndexOf( '\n' ) + 1 ) )[0];
					var open = /\{\s*$/.test( before ), closeNext = '}' === v.charAt( en );
					e.preventDefault();
					if ( open && closeNext ) {
						put( '\n' + indent + '\t\n' + indent, s, en );
						ta.selectionStart = ta.selectionEnd = s + indent.length + 2;
					} else {
						put( '\n' + indent + ( open ? '\t' : '' ), s, en );
					}
					changed( ta.value );
				}
			} );
			ta.addEventListener( 'blur', function () { setTimeout( function () { if ( document.activeElement !== ta ) { acHide(); } }, 150 ); } );
			ta.addEventListener( 'click', acHide );
			ta.addEventListener( 'scroll', acHide );
		}

		// ---- Outline: Jump to, quick open and fast section comments (scss-outline.js) ----
		var OL = window.TokenForgeScssOutline, bar = null, olTimer = null, olCache = { v: null, f: [] }, menu = { open: false, items: [], sel: 0 };
		function olIndex() { return ed.cm ? ed.cm.indexFromPos( ed.cm.getCursor( 'from' ) ) : ta.selectionStart; }
		function olFlat() {
			var v = ed.getValue();
			if ( v !== olCache.v ) { olCache = { v: v, f: OL.flatten( OL.parse( v ) ) }; }
			return olCache.f;
		}
		// Apply an { from, to, insert, caret } edit from the outline helpers.
		function olApply( e ) {
			if ( ed.cm ) {
				ed.cm.replaceRange( e.insert, ed.cm.posFromIndex( e.from ), ed.cm.posFromIndex( e.to ) );
				ed.cm.setCursor( ed.cm.posFromIndex( e.caret ) );
				ed.cm.focus();
			} else {
				put( e.insert, e.from, e.to );
				ta.setSelectionRange( e.caret, e.caret );
				changed( ta.value );
			}
			olSched();
		}
		function olAdd( sub ) { olApply( ( sub ? OL.subsectionEdit : OL.sectionEdit )( ed.getValue(), olIndex() ) ); }
		// "#" then Tab (false = not a snippet spot, so Tab does what it did).
		function olSnippet() {
			var e = OL && ! ( ed.cm ? ed.cm.somethingSelected() : ta.selectionStart !== ta.selectionEnd ) && OL.snippetEdit( ed.getValue(), olIndex() );
			if ( ! e ) { return false; }
			olApply( e );
			return true;
		}
		// Textarea keys (CodeMirror gets the same ones through its key map). Layout-proof: Bulgarian types Cyrillic for "O".
		function olKey( e ) {
			if ( ! OL || ! ( e.ctrlKey || e.metaKey ) || e.altKey || e.isComposing ) { return false; }
			if ( 'Enter' === e.key ) { olAdd( e.shiftKey ); }
			else if ( e.shiftKey && ( 'KeyO' === e.code || 'o' === String( e.key ).toLowerCase() ) ) { olOpen(); }
			else { return false; }
			e.preventDefault(); e.stopPropagation();
			return true;
		}
		function olSched() { clearTimeout( olTimer ); olTimer = setTimeout( olSync, 120 ); }
		// The button shows the section the caret is in.
		function olSync() {
			if ( ! bar ) { return; }
			var cur = OL.current( olFlat(), olIndex() ), btn = bar.querySelector( '.tf-ol-jump' );
			bar.querySelector( '.tf-ol-cur' ).textContent = cur ? cur.title : __( 'Jump to', 'tokenforge' );
			btn.setAttribute( 'aria-label', cur ? sp( __( 'Jump to a section. Current section: %s', 'tokenforge' ), cur.title ) : __( 'Jump to a section', 'tokenforge' ) );
		}
		function olRender() {
			var q = bar.querySelector( '.tf-ol-q' ), list = bar.querySelector( '.tf-ol-list' ), all = olFlat(), id = list.id;
			menu.items = OL.filter( all, q.value );
			menu.sel = Math.max( 0, Math.min( menu.sel, menu.items.length - 1 ) );
			list.innerHTML = menu.items.map( function ( it, i ) {
				var label = sp( __( '%1$s, level %2$d, line %3$d', 'tokenforge' ), it.title, it.level, it.line + 1 );
				return '<li role="option" id="' + id + '-' + i + '" data-n="' + i + '" aria-label="' + esc( label ) + '" aria-selected="' + ( i === menu.sel ? 'true' : 'false' ) + '"' + ( i === menu.sel ? ' class="is-sel"' : '' ) + ' style="--d:' + it.depth + '"><i aria-hidden="true">' + new Array( it.level + 1 ).join( '#' ) + '</i><span>' + esc( it.title ) + '</span><em aria-hidden="true">' + ( it.line + 1 ) + '</em></li>';
			} ).join( '' );
			var none = bar.querySelector( '.tf-ol-none' ), msg = menu.items.length ? '' : ( all.length ? __( 'No section matches.', 'tokenforge' ) : __( 'No sections yet. Type # then Tab, or use the Add section button.', 'tokenforge' ) );
			none.textContent = msg; none.hidden = ! msg;
			// Spoken only when it differs, so an arrow key (which only moves the selection) is not followed by the count each time.
			var lv = bar.querySelector( '.tf-ol-live' ), say = msg || sp( _n( '%d section', '%d sections', menu.items.length, 'tokenforge' ), menu.items.length );
			if ( lv.textContent !== say ) { lv.textContent = say; }
			if ( menu.items.length ) { q.setAttribute( 'aria-activedescendant', id + '-' + menu.sel ); } else { q.removeAttribute( 'aria-activedescendant' ); }
			var cur = list.querySelector( '.is-sel' );
			if ( cur && cur.scrollIntoView ) { cur.scrollIntoView( { block: 'nearest' } ); }
		}
		function olMove( dx ) {
			if ( ! menu.items.length ) { return; }
			menu.sel = ( menu.sel + dx + menu.items.length ) % menu.items.length;
			olRender();
		}
		function olOutside( e ) { if ( ( e.composedPath ? e.composedPath() : [ e.target ] ).indexOf( bar ) < 0 ) { olClose( false ); } } // composedPath: the front-end window is a shadow root
		function olOpen() {
			if ( ! bar ) { return; }
			var q = bar.querySelector( '.tf-ol-q' );
			if ( ! menu.open ) {
				var all = olFlat();
				menu.open = true; menu.sel = Math.max( 0, all.indexOf( OL.current( all, olIndex() ) ) ); q.value = '';
				var m = bar.querySelector( '.tf-ol-menu' ), br = bar.getBoundingClientRect(), below = window.innerHeight - br.bottom - 12;
				m.hidden = false;
				// Open upward when a low dock leaves no room below.
				m.style.top = below < 180 && br.top > below ? 'auto' : '';
				m.style.bottom = below < 180 && br.top > below ? 'calc(100% + 4px)' : '';
				m.style.maxHeight = Math.max( 120, Math.min( 300, below < 180 && br.top > below ? br.top - 12 : below ) ) + 'px';
				bar.querySelector( '.tf-ol-jump' ).setAttribute( 'aria-expanded', 'true' );
				document.addEventListener( 'mousedown', olOutside, true );
				olRender();
			}
			q.focus();
		}
		function olClose( refocus ) {
			if ( ! menu.open ) { return; }
			menu.open = false;
			bar.querySelector( '.tf-ol-menu' ).hidden = true;
			bar.querySelector( '.tf-ol-live' ).textContent = ''; // the next opening says the count again
			bar.querySelector( '.tf-ol-jump' ).setAttribute( 'aria-expanded', 'false' );
			document.removeEventListener( 'mousedown', olOutside, true );
			if ( refocus ) { ed.focus(); }
		}
		function olGo( i ) {
			var it = menu.items[ i ];
			if ( ! it ) { return; }
			olClose( false );
			ed.goTo( it.index ); // focuses the editor
			olSync();
		}
		function olBuild() {
			var mod = /Mac|iPhone|iPad/.test( navigator.platform || '' ) ? 'Cmd' : 'Ctrl', id = 'tf-ol-' + ( ++olUid );
			var add = function ( sub, marks, text ) {
				var name = sub ? __( 'Add sub-section', 'tokenforge' ) : __( 'Add section', 'tokenforge' );
				return '<button type="button" class="tf-ol-add" data-sub="' + ( sub ? 1 : 0 ) + '" aria-label="' + esc( name ) + '" title="' + esc( name + ' (' + mod + '+' + ( sub ? 'Shift+' : '' ) + 'Enter)' ) + '"><i aria-hidden="true">' + marks + '</i><span class="tf-ol-t">' + esc( text ) + '</span></button>';
			};
			bar = document.createElement( 'div' );
			bar.className = 'tf-ol-bar';
			bar.innerHTML =
				'<button type="button" class="tf-ol-jump" aria-haspopup="listbox" aria-expanded="false" title="' + esc( __( 'Jump to a section', 'tokenforge' ) + ' (' + mod + '+Shift+O)' ) + '"><span class="tf-ol-cur"></span><b class="tf-ol-caret" aria-hidden="true"></b></button>' +
				add( false, '#', __( 'Section', 'tokenforge' ) ) + add( true, '##', __( 'Sub-section', 'tokenforge' ) ) +
				'<div class="tf-ol-menu" hidden>' +
					'<input type="text" class="tf-ol-q" role="combobox" aria-autocomplete="list" aria-expanded="true" aria-controls="' + id + '" autocomplete="off" spellcheck="false" aria-label="' + esc( __( 'Filter sections', 'tokenforge' ) ) + '" placeholder="' + esc( __( 'Filter sections', 'tokenforge' ) ) + '">' +
					'<ul class="tf-ol-list" id="' + id + '" role="listbox" aria-label="' + esc( __( 'Sections', 'tokenforge' ) ) + '"></ul>' +
					'<p class="tf-ol-none" hidden></p>' +
					'<span class="tf-ol-live" role="status" aria-live="polite"></span>' +
				'</div>';
			ta.parentNode.insertBefore( bar, ta );
			var q = bar.querySelector( '.tf-ol-q' ), list = bar.querySelector( '.tf-ol-list' );
			bar.querySelector( '.tf-ol-jump' ).addEventListener( 'click', function () { if ( menu.open ) { olClose( true ); } else { olOpen(); } } );
			bar.querySelectorAll( '.tf-ol-add' ).forEach( function ( b ) { b.addEventListener( 'click', function () { olAdd( '1' === b.getAttribute( 'data-sub' ) ); } ); } );
			q.addEventListener( 'input', function () { menu.sel = 0; olRender(); } );
			q.addEventListener( 'keydown', function ( e ) {
				if ( 'ArrowDown' === e.key || 'ArrowUp' === e.key ) { e.preventDefault(); olMove( 'ArrowDown' === e.key ? 1 : -1 ); }
				else if ( 'Enter' === e.key ) { e.preventDefault(); olGo( menu.sel ); }
				else if ( 'Escape' === e.key ) { e.preventDefault(); e.stopPropagation(); olClose( true ); }
				else if ( 'Tab' === e.key ) { olClose( false ); }
			} );
			list.addEventListener( 'mousedown', function ( e ) { e.preventDefault(); } ); // keep the focus in the filter box
			list.addEventListener( 'click', function ( e ) { var li = e.target.closest( 'li[data-n]' ); if ( li ) { olGo( parseInt( li.getAttribute( 'data-n' ), 10 ) ); } } );
			if ( ed.cm ) { ed.cm.on( 'cursorActivity', olSched ); }
			else { [ 'keyup', 'click', 'input', 'focus' ].forEach( function ( t ) { ta.addEventListener( t, olSched ); } ); }
			olSync();
		}

		// Pick the mode.
		var cm = null, shadow = ta.getRootNode && ta.getRootNode() !== document;
		try {
			if ( ! shadow && window.wp && window.wp.codeEditor && opts.codeEditor ) {
				cm = window.wp.codeEditor.initialize( ta, opts.codeEditor ).codemirror;
			} else if ( ! shadow && window.CodeMirror && window.CodeMirror.fromTextArea ) {
				var CMg = window.CodeMirror;
				cm = CMg.fromTextArea( ta, { mode: CMg.mimeModes && CMg.mimeModes[ 'text/x-scss' ] ? 'text/x-scss' : 'css', lineNumbers: true, indentWithTabs: true, tabSize: 4, indentUnit: 4, matchBrackets: true } );
			}
		} catch ( e ) { cm = null; }
		if ( cm ) { setupCm( cm ); } else { setupTextarea(); }

		ed.getValue = function () { return ed.cm ? ed.cm.getValue() : ta.value; };
		ed.setValue = function ( v ) {
			if ( ed.cm ) { var c = ed.cm.getCursor(); ed.cm.setValue( v ); ed.cm.setCursor( c ); } else { ta.value = v; olSched(); }
		};
		if ( OL ) { olBuild(); }
		// Insert at the caret, indented like the current line; "{{cursor}}" marks where the caret goes.
		ed.insert = function ( text ) {
			var line = ed.cm ? ed.cm.getLine( ed.cm.getCursor( 'from' ).line ) : ta.value.slice( ta.value.lastIndexOf( '\n', ta.selectionStart - 1 ) + 1, ta.selectionStart );
			var t = String( text ).replace( /\n/g, '\n' + /^[ \t]*/.exec( line )[0] ), at = t.indexOf( '{{cursor}}' );
			t = t.replace( '{{cursor}}', '' );
			if ( ed.cm ) {
				var from = ed.cm.indexFromPos( ed.cm.getCursor( 'from' ) );
				ed.cm.replaceSelection( t );
				if ( at > -1 ) { ed.cm.setCursor( ed.cm.posFromIndex( from + at ) ); }
				ed.cm.focus();
				changed( ed.cm.getValue() );
				return;
			}
			var start = ta.selectionStart;
			put( t, start, ta.selectionEnd );
			if ( at > -1 ) { ta.setSelectionRange( start + at, start + at ); }
			changed( ta.value );
		};
		// A recipe at the caret, shaped for where the caret is (false = it doesn't belong there).
		ed.insertRecipe = function ( name ) {
			var r = recipeMap()[ name ], ok, lineBefore, before;
			if ( ! r ) { return false; }
			if ( ed.cm ) {
				var from = ed.cm.getCursor( 'from' );
				before = ed.cm.getRange( { line: 0, ch: 0 }, from );
				lineBefore = ed.cm.getLine( from.line ).slice( 0, from.ch );
				// Declarations and rules start on their own line.
				if ( 'block' === recipeSpot( before ) && 'text' !== recipeKind( r ) && /\S/.test( lineBefore ) ) {
					ed.cm.replaceRange( '\n' + /^\s*/.exec( lineBefore )[0], from, ed.cm.getCursor( 'to' ) );
				}
				ok = cmRecipe( ed.cm, r, ed.cm.getCursor( 'from' ), ed.cm.getCursor( 'to' ), false );
				ed.cm.focus();
				if ( ok ) { changed( ed.cm.getValue() ); }
				return ok;
			}
			ta.focus();
			before = ta.value.slice( 0, ta.selectionStart );
			lineBefore = before.slice( before.lastIndexOf( '\n' ) + 1 );
			if ( 'block' === recipeSpot( before ) && 'text' !== recipeKind( r ) && /\S/.test( lineBefore ) ) {
				put( '\n' + /^[ \t]*/.exec( lineBefore )[0], ta.selectionStart, ta.selectionEnd );
			}
			ok = taRecipe( r, ta.selectionStart, ta.selectionEnd, false );
			if ( ok ) { changed( ta.value ); }
			return ok;
		};
		// Put the caret at a character index and scroll it into view (near the top).
		ed.goTo = function ( index ) {
			index = Math.max( 0, Math.min( index, ed.getValue().length ) );
			if ( ed.cm ) {
				var pos = ed.cm.posFromIndex( index );
				ed.cm.setCursor( pos );
				ed.cm.scrollTo( null, ed.cm.charCoords( pos, 'local' ).top - 8 );
				ed.cm.focus();
				return;
			}
			ta.focus();
			ta.setSelectionRange( index, index );
			var lines = ta.value.slice( 0, index ).split( '\n' ).length - 1;
			var lh = parseFloat( window.getComputedStyle( ta ).lineHeight ) || 20;
			ta.scrollTop = Math.max( 0, lines * lh - 8 );
		};
		ed.focus = function () { if ( ed.cm ) { ed.cm.focus(); } else { ta.focus(); } };
		ed.refresh = function () { if ( ed.cm ) { ed.cm.refresh(); } };
		ed.setError = function ( err ) {
			if ( ! ed.cm ) { return; }
			if ( ed.errLine > -1 ) { ed.cm.removeLineClass( ed.errLine, 'background', 'tf-cm-err' ); ed.errLine = -1; }
			if ( err && err.line > 0 && err.line <= ed.cm.lineCount() ) { ed.errLine = err.line - 1; ed.cm.addLineClass( ed.errLine, 'background', 'tf-cm-err' ); }
		};
		ed.hideHints = acHide;
		return ed;
	}

	window.TokenForgeScssEditor = {
		create: create,
		// For tests.
		_context: function ( textBefore, force, d ) { return acContext( textBefore, force, d || { recipes: [], recipesOn: false, ref: [] } ); },
		_wantsSemi: wantsSemi,
		_tabSemi: tabSemi,
		_recipeSpot: recipeSpot,
		_recipeText: recipeText,
		_setData: function ( d ) { acData = d; }
	};
} )();
