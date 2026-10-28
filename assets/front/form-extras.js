/* TokenForge: steps, conditions, a summary and saved answers for the builder's form.
 *
 * The form carries data-tf-form, data-tf-steps (the step settings, the
 * summary) and data-tf-save (keep answers in this browser); its field
 * wrappers carry data-tf-step (a new step starts here, with an optional
 * title), data-tf-step-show (that step's own condition) and data-tf-show (the
 * field's condition: the other field's input name, the operator, the value).
 *
 * Conditions: a hidden field's inputs are disabled, so the browser neither
 * checks nor sends them (the server does the same check on submit). A step
 * whose condition fails is skipped the same way.
 * Steps: only the current step's fields show; Next checks them first; the
 * submit button shows on the last step (the summary, when there is one).
 * Saved answers stay in localStorage on the visitor's device until the form
 * is sent; passwords, files and hidden fields are never kept.
 *
 * Original implementation.
 */
( function () {
	'use strict';

	function esc( s ) { return window.CSS && CSS.escape ? CSS.escape( s ) : String( s ).replace( /["\\]/g, '\\$&' ); }

	var FIELDS = 'input:not([data-tf-ui]),select,textarea';

	function parse( json ) { try { return JSON.parse( json || '{}' ) || {}; } catch ( e ) { return {}; } }

	// Values of a field, by input name (checkbox groups send name[]).
	function values( form, name ) {
		var out = [];
		form.querySelectorAll( '[name="' + esc( name ) + '"]:not([data-tf-ui]),[name="' + esc( name + '[]' ) + '"]:not([data-tf-ui])' ).forEach( function ( el ) {
			if ( el.disabled ) { return; }
			if ( 'checkbox' === el.type || 'radio' === el.type ) {
				if ( el.checked ) { out.push( el.value ); }
			} else if ( 'SELECT' === el.tagName && el.multiple ) {
				Array.prototype.forEach.call( el.selectedOptions, function ( o ) { out.push( o.value ); } );
			} else {
				out.push( el.value );
			}
		} );
		return out;
	}

	// Same rules as Form_Extras::test() on the server.
	function test( op, value, list ) {
		value = String( value || '' ).trim().toLowerCase();
		list = list.map( function ( v ) { return String( v ).trim().toLowerCase(); } );
		var filled = list.filter( function ( v ) { return '' !== v; } );
		switch ( op ) {
			case 'empty': return ! filled.length;
			case 'notempty': return !! filled.length;
			case 'isnot': return list.indexOf( value ) === -1;
			case 'contains': return '' !== value && filled.some( function ( v ) { return v.indexOf( value ) > -1; } );
			case 'gt':
			case 'lt':
			case 'gte':
			case 'lte':
				if ( ! /\d/.test( value ) ) { return false; } // a number is compared with a number only
				return filled.some( function ( v ) {
					if ( ! /\d/.test( v ) ) { return false; }
					var a = num( v ), b = num( value );
					return ( 'gt' === op && a > b ) || ( 'lt' === op && a < b ) || ( 'gte' === op && a >= b ) || ( 'lte' === op && a <= b );
				} );
			default: return list.indexOf( value ) > -1;
		}
	}
	// A condition set: { match: all|any, rules: [ { name, op, v } ] } (or one rule, from older pages).
	function holds( form, c ) {
		if ( ! c || ! Array.isArray( c.rules ) ) { return ! c || ! c.name || test( c.op, c.v, values( form, c.name ) ); }
		if ( ! c.rules.length ) { return true; }
		var res = c.rules.map( function ( r ) { return test( r.op, r.v, values( form, r.name ) ); } );
		return 'any' === c.match ? res.indexOf( true ) > -1 : res.indexOf( false ) === -1;
	}

	// ---- Calculations: a field worked out from other fields -----------------------------
	// data-tf-calc = { e: '{0} * {1} + 25', r: [ input names ], d: decimals, p: before, s: after }.
	// The same rules as Form_Extras::calc_eval() on the server, which works it out again on send.
	// The first number in a value: "€ 1,250.50" → 1250.5, "1.250,50" → 1250.5, "2,5" → 2.5, "Premium 250" → 250.
	function num( v ) {
		var m = /-?\d[\d.,\s']*/.exec( String( null == v ? '' : v ) );
		if ( ! m ) { return 0; }
		var t = m[0].replace( /[\s']+/g, '' ).replace( /[.,]+$/, '' ), c = t.lastIndexOf( ',' ), d = t.lastIndexOf( '.' );
		if ( c > -1 && d > -1 ) {
			t = c > d ? t.replace( /\./g, '' ).replace( ',', '.' ) : t.replace( /,/g, '' );
		} else if ( c > -1 ) {
			t = /^-?\d+,\d{1,2}$/.test( t ) ? t.replace( ',', '.' ) : t.replace( /,/g, '' );
		}
		var n = parseFloat( t );
		return isFinite( n ) ? n : 0;
	}
	function calcFn( name, a ) {
		var x = a.length ? a[0] : 0;
		switch ( name ) {
			case 'round': var f = Math.pow( 10, Math.max( 0, Math.min( 10, Math.round( a[1] || 0 ) ) ) ); return Math.floor( x * f + 0.5 ) / f;
			case 'ceil': return Math.ceil( x );
			case 'floor': return Math.floor( x );
			case 'abs': return Math.abs( x );
			case 'min': return a.length ? Math.min.apply( null, a ) : 0;
			case 'max': return a.length ? Math.max.apply( null, a ) : 0;
			case 'if': return x ? ( a[1] || 0 ) : ( a[2] || 0 );
		}
		return 0;
	}
	function calcEval( expr, vals ) {
		var s = String( expr || '' ), i = 0;
		function ws() { while ( i < s.length && /\s/.test( s.charAt( i ) ) ) { i++; } }
		function take( t ) { ws(); if ( s.substr( i, t.length ) === t ) { i += t.length; return true; } return false; }
		function atom() {
			ws();
			if ( take( '(' ) ) { var v = cmp(); take( ')' ); return v; }
			if ( '{' === s.charAt( i ) ) { var j = s.indexOf( '}', i ), k = j < 0 ? '' : s.slice( i + 1, j ); i = j < 0 ? s.length : j + 1; return Number( vals[ parseInt( k, 10 ) ] ) || 0; }
			var m = /^(\d+(?:\.\d+)?|\.\d+)/.exec( s.slice( i ) );
			if ( m ) { i += m[0].length; return parseFloat( m[0] ); }
			m = /^([a-z]+)\s*\(/i.exec( s.slice( i ) );
			if ( m ) {
				i += m[0].length;
				var args = [];
				if ( ! take( ')' ) ) { do { args.push( cmp() ); } while ( take( ',' ) ); take( ')' ); }
				return calcFn( m[1].toLowerCase(), args );
			}
			i = s.length; // anything else ends the formula
			return 0;
		}
		function power() { var b = atom(); return take( '^' ) ? Math.pow( b, unary() ) : b; }
		function unary() { if ( take( '-' ) ) { return -unary(); } if ( take( '+' ) ) { return unary(); } return power(); }
		function prod() {
			var v = unary(), d;
			for ( ;; ) {
				if ( take( '*' ) ) { v *= unary(); } else if ( take( '/' ) ) { d = unary(); v = d ? v / d : 0; } else if ( take( '%' ) ) { d = unary(); v = d ? v % d : 0; } else { return v; }
			}
		}
		function sum() { var v = prod(); for ( ;; ) { if ( take( '+' ) ) { v += prod(); } else if ( take( '-' ) ) { v -= prod(); } else { return v; } } }
		function cmp() {
			var a = sum(), ops = [ '>=', '<=', '!=', '==', '>', '<', '=' ];
			for ( var k = 0; k < ops.length; k++ ) {
				if ( take( ops[ k ] ) ) {
					var b = sum();
					switch ( ops[ k ] ) {
						case '>=': return a >= b ? 1 : 0;
						case '<=': return a <= b ? 1 : 0;
						case '!=': return Math.abs( a - b ) > 1e-9 ? 1 : 0;
						case '>': return a > b ? 1 : 0;
						case '<': return a < b ? 1 : 0;
						default: return Math.abs( a - b ) <= 1e-9 ? 1 : 0;
					}
				}
			}
			return a;
		}
		var out = cmp();
		return isFinite( out ) ? out : 0;
	}
	// "1234567.50" in a number format: comma 1,234,567.50, dot 1.234.567,50,
	// space 1 234 567,50, apostrophe 1'234'567.50. The same as Form_Extras::group_digits().
	var FORMATS = { comma: [ ',', '.' ], dot: [ '.', ',' ], space: [ ' ', ',' ], apostrophe: [ "'", '.' ] };
	function group( text, format ) {
		var sep = FORMATS[ format ];
		if ( ! sep ) { return text; }
		var neg = '-' === text.charAt( 0 ), parts = ( neg ? text.slice( 1 ) : text ).split( '.' );
		return ( neg ? '-' : '' ) + parts[0].replace( /\B(?=(\d{3})+(?!\d))/g, sep[0] ) + ( parts[1] ? sep[1] + parts[1] : '' );
	}
	// Work out every calculated field; true when a value changed. A result that
	// uses another result counts with its rounded number, whatever its format.
	function calcs( form ) {
		var changed = false, raw = form.__tfRaw || ( form.__tfRaw = {} );
		form.querySelectorAll( '[data-tf-calc]' ).forEach( function ( g ) {
			var c = g.__tfCalc || ( g.__tfCalc = parse( g.getAttribute( 'data-tf-calc' ) ) );
			var el = g.querySelector( 'input:not([data-tf-ui]),textarea' );
			if ( ! el ) { return; }
			var vals = ( c.r || [] ).map( function ( name ) {
				if ( ! name ) { return 0; }
				var src = form.querySelector( '[name="' + esc( name ) + '"]:not([data-tf-ui])' );
				if ( src && ! src.disabled && Object.prototype.hasOwnProperty.call( raw, name ) && src.closest( '[data-tf-calc]' ) ) { return raw[ name ]; }
				return values( form, name ).reduce( function ( a, v ) { return a + num( v ); }, 0 );
			} );
			var d = Math.max( 0, Math.min( 6, parseInt( c.d, 10 ) || 0 ) ), f = Math.pow( 10, d );
			var n = Math.floor( calcEval( c.e, vals ) * f + 0.5 ) / f, text = n.toFixed( d );
			raw[ el.name ] = n;
			if ( 'number' !== el.type && 'hidden' !== el.type ) { text = ( c.p || '' ) + group( text, c.f ) + ( c.s || '' ); }
			el.readOnly = true;
			if ( c.t ) { shownAsText( g, el, text ); }
			if ( el.value !== text ) { el.value = text; changed = true; }
		} );
		return changed;
	}
	// "Show the result: as plain text": the input stays (it is sent) but only
	// the text shows, read out when it changes.
	function shownAsText( g, el, text ) {
		var out = g.__tfOut;
		if ( ! out ) {
			out = g.__tfOut = document.createElement( 'output' );
			out.className = 'tf-calc-text';
			out.setAttribute( 'aria-live', 'polite' );
			if ( el.id ) { out.setAttribute( 'for', el.id ); }
			el.classList.add( 'tf-calc-input' );
			el.setAttribute( 'tabindex', '-1' );
			el.setAttribute( 'aria-hidden', 'true' );
			el.parentNode.insertBefore( out, el.nextSibling );
		}
		if ( out.textContent !== text ) { out.textContent = text; }
	}

	// "Required only when": data-tf-require = { match, rules, star }. A field
	// that is hidden keeps the wish in data-tf-req (mark() puts it back).
	function requires( form ) {
		form.querySelectorAll( '[data-tf-require]' ).forEach( function ( g ) {
			var c = g.__tfReq || ( g.__tfReq = parse( g.getAttribute( 'data-tf-require' ) ) );
			var need = holds( form, c ), boxes = g.querySelectorAll( 'input[type="checkbox"]' );
			// A checkbox group is filled in once one box is ticked (like the builder's own check).
			var ticked = Array.prototype.some.call( boxes, function ( b ) { return b.checked; } );
			g.querySelectorAll( FIELDS ).forEach( function ( el ) {
				var t = ( el.type || '' ).toLowerCase();
				if ( 'hidden' === t || 'submit' === t || 'button' === t ) { return; }
				var want = need && ! ( 'checkbox' === t && ticked );
				if ( el.hasAttribute( 'data-tf-off' ) || el.hasAttribute( 'data-tf-skip' ) ) {
					if ( want ) { el.setAttribute( 'data-tf-req', '' ); } else { el.removeAttribute( 'data-tf-req' ); }
				} else if ( el.required !== want ) {
					el.required = want;
				}
			} );
			g.classList.toggle( 'tf-required', need );
			if ( c.star ) {
				var label = g.querySelector( 'label' );
				if ( label ) { label.classList.toggle( 'required', need ); }
			}
		} );
	}

	function inputs( group ) { return group.querySelectorAll( 'input:not([data-tf-ui]),select,textarea,button' ); }

	// An input is off (disabled, not required) while its field is hidden by its
	// condition (data-tf-off) or its step is skipped (data-tf-skip). Inputs the
	// page disabled itself are left alone.
	function mark( el, attr, on ) {
		var before = el.hasAttribute( 'data-tf-off' ) || el.hasAttribute( 'data-tf-skip' );
		if ( on ) {
			if ( ! before && el.disabled ) { return; }
			el.setAttribute( attr, '' );
		} else {
			el.removeAttribute( attr );
		}
		var after = el.hasAttribute( 'data-tf-off' ) || el.hasAttribute( 'data-tf-skip' );
		if ( after && ! before ) {
			el.disabled = true;
			if ( el.required ) { el.required = false; el.setAttribute( 'data-tf-req', '' ); }
		} else if ( ! after && before ) {
			el.disabled = false;
			if ( el.hasAttribute( 'data-tf-req' ) ) { el.required = true; el.removeAttribute( 'data-tf-req' ); }
		}
	}

	// Show or hide one field wrapper for its condition.
	function setShown( group, show ) {
		group.hidden = ! show;
		group.classList.toggle( 'tf-cond-hidden', ! show );
		inputs( group ).forEach( function ( el ) { mark( el, 'data-tf-off', ! show ); } );
	}

	function conditions( form ) {
		var groups = form.querySelectorAll( '[data-tf-show]' );
		// A few passes: a field can depend on a field that depends on another.
		for ( var pass = 0; pass < 5; pass++ ) {
			var changed = false;
			groups.forEach( function ( g ) {
				var c = g.__tfCond || ( g.__tfCond = parse( g.getAttribute( 'data-tf-show' ) ) );
				var show = holds( form, c );
				if ( show === g.classList.contains( 'tf-cond-hidden' ) ) {
					setShown( g, show );
					changed = true;
				}
			} );
			if ( ! changed ) { break; }
		}
	}

	// The label of a field wrapper, for the summary.
	function labelOf( group ) {
		// The field's own label (not an option's): a label of the wrapper, its aria label, or its labelling element.
		var l = group.querySelector( ':scope > label' ), t = l ? l.textContent : '';
		if ( ! t ) { t = group.getAttribute( 'aria-label' ) || ''; }
		if ( ! t && group.getAttribute( 'aria-labelledby' ) ) {
			var by = document.getElementById( group.getAttribute( 'aria-labelledby' ) );
			t = by ? by.textContent : '';
		}
		if ( ! t && ! group.querySelector( '.options-wrapper' ) ) {
			l = group.querySelector( 'label' );
			t = l ? l.textContent : '';
		}
		if ( ! t ) {
			var el = group.querySelector( FIELDS );
			t = el ? ( el.getAttribute( 'aria-label' ) || el.getAttribute( 'placeholder' ) || el.name || '' ) : '';
		}
		return t.replace( /\s*\*\s*$/, '' ).replace( /\s+/g, ' ' ).trim();
	}
	function optionText( el ) {
		var l = el.id && el.form ? el.form.querySelector( 'label[for="' + esc( el.id ) + '"]' ) : null;
		l = l || el.closest( 'label' ) || ( el.parentNode && el.parentNode.querySelector( 'label' ) );
		return l ? l.textContent.replace( /\s+/g, ' ' ).trim() : el.value;
	}
	// What a visitor filled in, as text.
	function answerOf( group ) {
		var out = [];
		group.querySelectorAll( FIELDS ).forEach( function ( el ) {
			var type = ( el.type || '' ).toLowerCase();
			if ( el.disabled || [ 'hidden', 'submit', 'button', 'reset', 'image' ].indexOf( type ) > -1 ) { return; }
			if ( 'checkbox' === type || 'radio' === type ) {
				if ( el.checked ) { out.push( optionText( el ) ); }
			} else if ( 'SELECT' === el.tagName ) {
				Array.prototype.forEach.call( el.selectedOptions, function ( o ) { if ( '' !== o.value ) { out.push( o.textContent.trim() ); } } );
			} else if ( 'file' === type ) {
				Array.prototype.forEach.call( el.files || [], function ( f ) { out.push( f.name ); } );
			} else if ( 'password' === type ) {
				if ( el.value ) { out.push( '********' ); }
			} else if ( '' !== el.value.trim() ) {
				out.push( el.value.trim() );
			}
		} );
		return out.join( ', ' );
	}

	function steps( form ) {
		var cfg = parse( form.getAttribute( 'data-tf-steps' ) );
		var submit = form.querySelector( ':scope > .submit-button-wrapper' );
		// Field wrappers in order; the captcha and the submit button belong to the last step.
		var tail = function ( el ) { return el.classList.contains( 'submit-button-wrapper' ) || el.classList.contains( 'captcha' ) || el.classList.contains( 'recaptcha-error' ); };
		var groups = Array.prototype.filter.call( form.children, function ( el ) {
			return ( el.classList.contains( 'form-group' ) || el.hasAttribute( 'data-tf-step' ) || el.hasAttribute( 'data-tf-show' ) ) && ! tail( el ) && ! el.classList.contains( 'tf-form-restored' );
		} );
		var ends = Array.prototype.filter.call( form.children, tail );
		var list = [ { title: cfg.first || '', groups: [], cond: null } ];
		groups.forEach( function ( g ) {
			if ( g.hasAttribute( 'data-tf-step' ) && list[ list.length - 1 ].groups.length ) {
				list.push( { title: g.getAttribute( 'data-tf-step' ) || '', groups: [], cond: g.hasAttribute( 'data-tf-step-show' ) ? parse( g.getAttribute( 'data-tf-step-show' ) ) : null } );
			}
			list[ list.length - 1 ].groups.push( g );
		} );
		var anchor = groups[0] || submit || null;
		var summary = null;
		if ( cfg.summary ) {
			summary = document.createElement( 'div' );
			summary.className = 'form-group tf-form-summary';
			list.push( { title: cfg.sumTitle || '', groups: [ summary ], cond: null, summary: true } );
		}
		if ( list.length < 2 ) { return; }
		list.forEach( function ( s, i ) { if ( ! s.title ) { s.title = String( i + 1 ); } } );

		var current = 0;
		var progress = document.createElement( 'div' );
		progress.className = 'tf-form-progress tf-form-progress--' + ( cfg.progress || 'steps' );
		if ( 'none' !== cfg.progress ) { form.insertBefore( progress, anchor ); }

		var nav = document.createElement( 'div' );
		nav.className = 'form-group tf-form-nav';
		var back = document.createElement( 'button' );
		back.type = 'button';
		back.className = 'tf-form-back';
		back.textContent = cfg.back || 'Back';
		var next = document.createElement( 'button' );
		next.type = 'button';
		var sb = submit && submit.querySelector( 'button' );
		next.className = ( sb ? sb.className.replace( /\bsending\b/g, '' ) + ' ' : 'bricks-button ' ) + 'tf-form-next';
		next.textContent = cfg.next || 'Next';
		nav.appendChild( back );
		nav.appendChild( next );
		form.insertBefore( nav, submit || null );
		if ( summary ) { form.insertBefore( summary, nav ); }

		function label( n, total ) { return String( cfg.of || 'Step %1$s of %2$s' ).replace( '%1$s', n ).replace( '%2$s', total ); }

		// Steps whose own condition fails are skipped: their fields are off.
		function on( s ) { return ! s.cond || holds( form, s.cond ); }
		function skips() {
			var changed = false;
			list.forEach( function ( s ) {
				var skip = ! on( s );
				if ( !! s.skipped !== skip ) {
					s.skipped = skip;
					changed = true;
					s.groups.forEach( function ( g ) {
						g.classList.toggle( 'tf-step-skipped', skip );
						inputs( g ).forEach( function ( el ) { mark( el, 'data-tf-skip', skip ); } );
					} );
				}
			} );
			return changed;
		}
		function active() { return list.filter( function ( s ) { return ! s.skipped; } ); }

		function drawProgress() {
			var shown = active(), total = shown.length, at = shown.indexOf( list[ current ] );
			if ( 'bar' === cfg.progress ) {
				progress.innerHTML = '';
				var bar = document.createElement( 'div' );
				bar.className = 'tf-form-progress__bar';
				bar.setAttribute( 'role', 'progressbar' );
				bar.setAttribute( 'aria-valuemin', '1' );
				bar.setAttribute( 'aria-valuemax', String( total ) );
				bar.setAttribute( 'aria-valuenow', String( at + 1 ) );
				bar.setAttribute( 'aria-valuetext', label( at + 1, total ) );
				var fill = document.createElement( 'span' );
				fill.style.width = ( ( at + 1 ) / total * 100 ) + '%';
				bar.appendChild( fill );
				var text = document.createElement( 'span' );
				text.className = 'tf-form-progress__text';
				text.textContent = label( at + 1, total );
				progress.appendChild( text );
				progress.appendChild( bar );
				return;
			}
			if ( 'count' === cfg.progress ) {
				progress.textContent = label( at + 1, total );
				return;
			}
			progress.innerHTML = '';
			var ol = document.createElement( 'ol' );
			ol.className = 'tf-form-progress__steps';
			shown.forEach( function ( s, i ) {
				var li = document.createElement( 'li' );
				li.className = 'tf-form-progress__step' + ( i < at ? ' is-done' : '' ) + ( i === at ? ' is-current' : '' );
				if ( i === at ) { li.setAttribute( 'aria-current', 'step' ); }
				var num = document.createElement( 'span' );
				num.className = 'tf-form-progress__num';
				num.textContent = String( i + 1 );
				var t = document.createElement( 'span' );
				t.className = 'tf-form-progress__title';
				t.textContent = s.title;
				li.appendChild( num );
				li.appendChild( t );
				if ( i < at ) {
					li.tabIndex = 0;
					li.setAttribute( 'role', 'button' );
					li.addEventListener( 'click', function () { show( list.indexOf( s ) ); } );
					li.addEventListener( 'keydown', function ( e ) { if ( 'Enter' === e.key || ' ' === e.key ) { e.preventDefault(); show( list.indexOf( s ) ); } } );
				}
				ol.appendChild( li );
			} );
			progress.appendChild( ol );
		}

		// The summary: every answer of the steps that are on, with a way back to it.
		function drawSummary() {
			summary.innerHTML = '';
			var dl = document.createElement( 'dl' );
			dl.className = 'tf-form-summary__list';
			list.forEach( function ( s, i ) {
				if ( s.summary || s.skipped ) { return; }
				s.groups.forEach( function ( g ) {
					var real = Array.prototype.some.call( g.querySelectorAll( FIELDS ), function ( el ) { return 'hidden' !== ( el.type || '' ).toLowerCase(); } );
					if ( g.classList.contains( 'tf-cond-hidden' ) || ! real ) { return; }
					var row = document.createElement( 'div' );
					row.className = 'tf-form-summary__row';
					var dt = document.createElement( 'dt' );
					dt.textContent = labelOf( g );
					var dd = document.createElement( 'dd' );
					var a = answerOf( g );
					dd.textContent = a || cfg.none || '';
					if ( ! a ) { dd.className = 'is-empty'; }
					var edit = document.createElement( 'button' );
					edit.type = 'button';
					edit.className = 'tf-form-summary__edit';
					edit.textContent = cfg.edit || 'Edit';
					edit.setAttribute( 'aria-label', ( cfg.edit || 'Edit' ) + ': ' + dt.textContent );
					edit.addEventListener( 'click', function () { show( i ); } );
					row.appendChild( dt );
					row.appendChild( dd );
					row.appendChild( edit );
					dl.appendChild( row );
				} );
			} );
			summary.appendChild( dl );
		}

		function show( i, focus ) {
			current = Math.max( 0, Math.min( list.length - 1, i ) );
			if ( list[ current ].skipped ) { current = step( current, 1 ); }
			list.forEach( function ( s, n ) {
				s.groups.forEach( function ( g ) { g.classList.toggle( 'tf-step-hidden', n !== current ); } );
			} );
			var last = current === list.length - 1;
			var firstOn = list.indexOf( active()[0] );
			back.hidden = current === firstOn;
			next.hidden = last;
			ends.forEach( function ( el ) { el.classList.toggle( 'tf-step-hidden', ! last ); } );
			nav.classList.toggle( 'is-first', current === firstOn );
			nav.classList.toggle( 'is-last', last );
			form.setAttribute( 'data-tf-step-current', String( current + 1 ) );
			if ( list[ current ].summary ) { drawSummary(); }
			drawProgress();
			if ( false !== focus ) {
				var first = list[ current ].groups.map( function ( g ) { return g.classList.contains( 'tf-cond-hidden' ) ? null : g.querySelector( 'input:not([type="hidden"]):not(:disabled),select:not(:disabled),textarea:not(:disabled),button' ); } ).filter( Boolean )[0];
				if ( first ) { try { first.focus( { preventScroll: true } ); } catch ( e ) { first.focus(); } }
				var top = progress.parentNode ? progress : form;
				if ( top.getBoundingClientRect().top < 0 ) { top.scrollIntoView( { block: 'start', behavior: 'smooth' } ); }
			}
			form.dispatchEvent( new CustomEvent( 'tokenforge:step', { detail: { step: current + 1 } } ) );
		}
		// The next (dir 1) or previous (dir -1) step that is on.
		function step( from, dir ) {
			for ( var n = from + dir; n >= 0 && n < list.length; n += dir ) {
				if ( ! list[ n ].skipped ) { return n; }
			}
			return from;
		}

		// Next: the current step's visible fields must be valid first.
		function valid( at ) {
			var bad = null;
			list[ at ].groups.forEach( function ( g ) {
				if ( bad || g.classList.contains( 'tf-cond-hidden' ) ) { return; }
				g.querySelectorAll( FIELDS ).forEach( function ( el ) {
					if ( ! bad && ! el.disabled && el.willValidate && ! el.checkValidity() ) { bad = el; }
				} );
			} );
			return bad;
		}
		function forward() {
			var bad = valid( current );
			if ( bad ) { bad.reportValidity(); return; }
			show( step( current, 1 ) );
		}

		next.addEventListener( 'click', forward );
		back.addEventListener( 'click', function () { show( step( current, -1 ) ); } );
		// Enter in a field moves on instead of sending an unfinished form.
		form.addEventListener( 'keydown', function ( e ) {
			if ( 'Enter' !== e.key || current === list.length - 1 ) { return; }
			var t = e.target;
			if ( ! t || 'TEXTAREA' === t.tagName || 'BUTTON' === t.tagName || ( t.isContentEditable ) ) { return; }
			e.preventDefault();
			forward();
		} );
		form.__tfSteps = {
			refresh: function () {
				if ( skips() ) {
					conditions( form );
					skips();
					if ( list[ current ].skipped ) { show( step( current, -1 ), false ); } else { drawProgress(); }
				}
			},
			// Carry on at a step (saved answers): no further than the first step that isn't complete.
			resume: function ( n ) {
				var to = 0;
				for ( var k = 0; k < list.length && k < n; k++ ) {
					if ( list[ k ].skipped ) { continue; }
					if ( valid( k ) ) { break; }
					to = step( k, 1 );
				}
				show( Math.min( to, n ), false );
			},
			first: function () { show( 0, false ); }
		};
		// After a successful send the builder clears the form: start again at step 1.
		document.addEventListener( 'bricks/form/success', function ( e ) {
			var id = e.detail && e.detail.elementId;
			if ( ! id || form.getAttribute( 'data-element-id' ) === id || form.id === 'brxe-' + id ) {
				setTimeout( function () { conditions( form ); skips(); show( 0, false ); }, 0 );
			}
		} );
		form.classList.add( 'tf-has-steps' );
		skips();
		show( 0, false );
	}

	// ---- Saved answers (this browser only, until the form is sent) ----
	var SKIP_TYPES = [ 'password', 'file', 'hidden', 'submit', 'button', 'reset', 'image' ];
	var DAYS = 30;
	function storeKey( form ) { return 'tf-form:' + window.location.pathname + ':' + ( form.id || form.getAttribute( 'data-element-id' ) || '' ); }
	function keep( el ) { return !! el.name && SKIP_TYPES.indexOf( ( el.type || '' ).toLowerCase() ) === -1 && ! /captcha|nonce|token|honeypot/i.test( el.name ); }
	function collect( form ) {
		var out = {};
		form.querySelectorAll( FIELDS ).forEach( function ( el ) {
			if ( ! keep( el ) || el.disabled ) { return; }
			var k = el.name;
			if ( 'checkbox' === el.type || 'radio' === el.type ) {
				out[ k ] = out[ k ] || [];
				if ( el.checked ) { out[ k ].push( el.value ); }
			} else if ( 'SELECT' === el.tagName && el.multiple ) {
				out[ k ] = Array.prototype.map.call( el.selectedOptions, function ( o ) { return o.value; } );
			} else {
				out[ k ] = el.value;
			}
		} );
		return out;
	}
	function fill( form, data ) {
		var any = false;
		form.querySelectorAll( FIELDS ).forEach( function ( el ) {
			if ( ! keep( el ) || ! Object.prototype.hasOwnProperty.call( data, el.name ) ) { return; }
			var v = data[ el.name ];
			if ( 'checkbox' === el.type || 'radio' === el.type ) {
				el.checked = Array.isArray( v ) && v.indexOf( el.value ) > -1;
				any = any || el.checked;
			} else if ( 'SELECT' === el.tagName && el.multiple ) {
				Array.prototype.forEach.call( el.options, function ( o ) { o.selected = Array.isArray( v ) && v.indexOf( o.value ) > -1; } );
				any = any || ( Array.isArray( v ) && v.length > 0 );
			} else if ( 'string' === typeof v ) {
				el.value = v;
				any = any || '' !== v;
			}
		} );
		return any;
	}
	function saving( form ) {
		var cfg = parse( form.getAttribute( 'data-tf-save' ) );
		var key = storeKey( form ), timer = null, stored = null;
		var store = function () {
			try {
				window.localStorage.setItem( key, JSON.stringify( { t: Date.now(), step: parseInt( form.getAttribute( 'data-tf-step-current' ) || '1', 10 ), v: collect( form ) } ) );
			} catch ( e ) {}
		};
		// After a send nothing is kept until the visitor types again (going back to step 1 is not typing).
		var sent = false;
		var later = function () { if ( sent ) { return; } clearTimeout( timer ); timer = setTimeout( store, 400 ); };
		var typed = function () { sent = false; later(); };
		var forget = function () { clearTimeout( timer ); try { window.localStorage.removeItem( key ); } catch ( e ) {} };
		try { stored = JSON.parse( window.localStorage.getItem( key ) || 'null' ); } catch ( e ) { stored = null; }
		if ( stored && ( ! stored.t || Date.now() - stored.t > DAYS * 864e5 ) ) { forget(); stored = null; }
		form.addEventListener( 'input', typed );
		form.addEventListener( 'change', typed );
		form.addEventListener( 'tokenforge:step', later );
		document.addEventListener( 'bricks/form/success', function ( e ) {
			var id = e.detail && e.detail.elementId;
			if ( ! id || form.getAttribute( 'data-element-id' ) === id || form.id === 'brxe-' + id ) { sent = true; forget(); }
		} );
		if ( ! stored || ! stored.v || ! fill( form, stored.v ) ) { return 0; }
		// Tell the visitor, with a way to start over.
		var note = document.createElement( 'div' );
		note.className = 'form-group tf-form-restored';
		note.setAttribute( 'role', 'status' );
		var text = document.createElement( 'span' );
		text.textContent = cfg.restored || '';
		var again = document.createElement( 'button' );
		again.type = 'button';
		again.className = 'tf-form-restart';
		again.textContent = cfg.clear || 'Start over';
		again.addEventListener( 'click', function () {
			forget();
			form.reset();
			if ( note.parentNode ) { note.parentNode.removeChild( note ); }
			setTimeout( function () {
				conditions( form );
				if ( form.__tfSteps ) { form.__tfSteps.refresh(); form.__tfSteps.first(); }
			}, 0 );
		} );
		note.appendChild( text );
		note.appendChild( again );
		form.insertBefore( note, form.firstChild );
		return stored.step || 1;
	}

	// ---- Looks, masks, counters, dates, messages ----
	// Helper controls carry data-tf-ui and a form attribute pointing nowhere, so
	// they are never sent and never read by the code above.
	function fire( el, type ) { el.dispatchEvent( new Event( type, { bubbles: true } ) ); }
	function addSync( el, fn ) {
		( el.__tfSyncs || ( el.__tfSyncs = [] ) ).push( fn );
		el.__tfSync = function () { el.__tfSyncs.forEach( function ( f ) { f(); } ); };
		fn();
	}
	function syncLooks( form ) {
		form.querySelectorAll( FIELDS ).forEach( function ( el ) { if ( el.__tfSync ) { el.__tfSync(); } } );
	}
	function hideReal( el ) {
		el.classList.add( 'tf-sr-input' );
		el.setAttribute( 'tabindex', '-1' );
		el.setAttribute( 'aria-hidden', 'true' );
	}
	function uiInput( type ) {
		var i = document.createElement( 'input' );
		i.type = type;
		i.setAttribute( 'data-tf-ui', '' );
		i.setAttribute( 'form', 'tf-none' );
		return i;
	}
	function nameOf( el ) {
		var g = el.closest( '.form-group' ), l = g && g.querySelector( ':scope > label' );
		return { id: l && l.id ? l.id : '', text: g ? labelOf( g ) : ( el.name || '' ) };
	}

	function slider( el, c ) {
		var out = document.createElement( 'output' );
		out.className = 'tf-look-value';
		if ( el.id ) { out.setAttribute( 'for', el.id ); }
		el.parentNode.insertBefore( out, el.nextSibling );
		el.addEventListener( 'input', function () { el.__tfSync(); } );
		addSync( el, function () { out.textContent = ( c.p || '' ) + el.value + ( c.s || '' ); } );
	}

	function rating( el, c ) {
		var max = Math.max( 1, Math.min( 20, parseInt( c.max, 10 ) || 5 ) ), base = el.id || el.name || 'x', n = nameOf( el );
		hideReal( el );
		var box = document.createElement( 'div' );
		box.className = 'tf-rating';
		box.setAttribute( 'role', 'radiogroup' );
		if ( n.id ) { box.setAttribute( 'aria-labelledby', n.id ); } else { box.setAttribute( 'aria-label', n.text ); }
		var radios = [], labels = [];
		for ( var i = 1; i <= max; i++ ) {
			var r = uiInput( 'radio' ), l = document.createElement( 'label' ), t = document.createElement( 'span' );
			r.name = 'tf-rating-' + base;
			r.id = 'tf-rating-' + base + '-' + i;
			r.value = String( i );
			r.className = 'tf-sr-input';
			l.className = 'tf-rating__star';
			l.setAttribute( 'for', r.id );
			t.className = 'tf-sr-input';
			t.textContent = String( 1 === i ? ( c.one || '%s' ) : ( c.n || '%s' ) ).replace( '%s', i );
			l.appendChild( t );
			box.appendChild( r );
			box.appendChild( l );
			radios.push( r );
			labels.push( l );
			r.addEventListener( 'change', function () {
				el.value = this.value;
				fire( el, 'input' );
				fire( el, 'change' );
			} );
		}
		el.parentNode.insertBefore( box, el.nextSibling );
		el.addEventListener( 'input', function () { el.__tfSync(); } );
		addSync( el, function () {
			var v = parseInt( el.value, 10 ) || 0;
			radios.forEach( function ( r, k ) {
				r.checked = k + 1 === v;
				labels[ k ].classList.toggle( 'is-on', k + 1 <= v );
			} );
		} );
	}

	function pairRange( el, c ) {
		var n = nameOf( el ), box = document.createElement( 'div' ), handles = [], p = c.p || '', s = c.s || '';
		hideReal( el );
		box.className = 'tf-range';
		var track = document.createElement( 'div' );
		track.className = 'tf-range__track';
		box.appendChild( track );
		[ c.from || 'From', c.to || 'To' ].forEach( function ( label ) {
			var h = uiInput( 'range' );
			h.min = c.min; h.max = c.max; h.step = c.step || 1;
			h.setAttribute( 'aria-label', ( n.text ? n.text + ': ' : '' ) + label );
			track.appendChild( h );
			handles.push( h );
		} );
		var out = document.createElement( 'output' );
		out.className = 'tf-look-value';
		box.appendChild( out );
		el.parentNode.insertBefore( box, el.nextSibling );
		function draw() { out.textContent = p + handles[0].value + s + ' - ' + p + handles[1].value + s; }
		function write() { el.value = handles[0].value + ' - ' + handles[1].value; draw(); }
		handles.forEach( function ( h, k ) {
			h.addEventListener( 'input', function () {
				var a = parseFloat( handles[0].value ), b = parseFloat( handles[1].value );
				if ( a > b ) { handles[ 1 - k ].value = h.value; } // keep from <= to: push the other handle
				write();
				fire( el, 'input' );
			} );
			h.addEventListener( 'change', function () { fire( el, 'change' ); } );
		} );
		addSync( el, function () {
			var m = /^\s*(-?\d+(?:\.\d+)?)\s*-\s*(-?\d+(?:\.\d+)?)\s*$/.exec( el.value );
			handles[0].value = m ? m[1] : c.min;
			handles[1].value = m ? m[2] : c.max;
			write();
		} );
	}

	// ---- Input masks: 9 digit, a letter, * letter or digit, anything else is literal ----
	var SLOTS = { '#': /\d/, '@': /[A-Za-zÀ-ɏͰ-ϿЀ-ӿ]/, '*': /[A-Za-z0-9À-ɏͰ-ϿЀ-ӿ]/ };
	function maskFormat( mask, value ) {
		var v = String( null == value ? '' : value ), vi = 0, out = '', pend = '';
		for ( var i = 0; i < mask.length; i++ ) {
			var m = mask.charAt( i ), re = SLOTS[ m ];
			if ( ! re ) { if ( v.charAt( vi ) === m ) { vi++; } pend += m; continue; } // a typed literal is taken, not doubled
			while ( vi < v.length && ! re.test( v.charAt( vi ) ) ) { vi++; }
			if ( vi >= v.length ) { break; }
			out += pend + v.charAt( vi++ );
			pend = '';
		}
		return out;
	}
	function masked( el, mask ) {
		el.addEventListener( 'input', function () {
			var raw = el.value, pos = null, f = maskFormat( mask, raw );
			try { pos = el.selectionStart; } catch ( e ) {}
			if ( f === raw ) { return; }
			var inside = null !== pos && pos < raw.length;
			el.value = f;
			var at = f.length;
			if ( inside ) { // best effort: keep the caret after the same number of letters and digits
				var left = raw.slice( 0, pos ).replace( /[^A-Za-z0-9À-ɏͰ-ϿЀ-ӿ]/g, '' ).length, seen = 0;
				for ( at = 0; at < f.length && seen < left; at++ ) { if ( /[A-Za-z0-9À-ɏͰ-ϿЀ-ӿ]/.test( f.charAt( at ) ) ) { seen++; } }
			}
			try { el.setSelectionRange( at, at ); } catch ( e ) {}
		} );
	}

	function counter( el ) {
		var box = document.createElement( 'div' ), max = parseInt( el.getAttribute( 'maxlength' ), 10 );
		box.className = 'tf-counter';
		box.id = ( el.id || el.name || 'tf' ) + '-count';
		el.parentNode.insertBefore( box, el.nextSibling );
		el.setAttribute( 'aria-describedby', ( ( el.getAttribute( 'aria-describedby' ) || '' ) + ' ' + box.id ).trim() );
		el.addEventListener( 'input', function () { el.__tfSync(); } );
		addSync( el, function () { box.textContent = el.value.length + ( max > 0 ? ' / ' + max : '' ); } );
	}

	// ---- Dates: Bricks starts flatpickr itself; limits are added once it is there ----
	function ymd( d ) { return d.getFullYear() + '-' + ( '0' + ( d.getMonth() + 1 ) ).slice( -2 ) + '-' + ( '0' + d.getDate() ).slice( -2 ); }
	function today( plus ) { var d = new Date(); return new Date( d.getFullYear(), d.getMonth(), d.getDate() + plus ); }
	function dateLimits( el, c ) {
		var tries = 0, timer = setInterval( function () {
			if ( el._flatpickr ) { clearInterval( timer ); dateApply( el, el._flatpickr, c ); } else if ( ++tries > 100 ) { clearInterval( timer ); }
		}, 100 );
	}
	function dateApply( el, fp, c ) {
		var own = null != c.min ? today( parseInt( c.min, 10 ) || 0 ) : null;
		var wd = Array.isArray( c.wd ) ? c.wd.map( Number ) : [], off = Array.isArray( c.off ) ? c.off : [];
		var old = fp.config.disable;
		fp.set( 'disable', ( Array.isArray( old ) ? old : [] ).concat( [ function ( d ) {
			var s = ymd( d );
			return wd.indexOf( d.getDay() ) > -1 || off.some( function ( r ) { return r && r[0] <= s && s <= ( r[1] || r[0] ) ; } );
		} ] ) );
		if ( null != c.max ) { fp.set( 'maxDate', today( parseInt( c.max, 10 ) || 0 ) ); }
		var start = c.after ? el.form && el.form.querySelector( '[name="' + esc( c.after ) + '"]' ) : null;
		function limit() {
			var min = own, from = null;
			if ( start && start.value ) {
				from = start._flatpickr && start._flatpickr.selectedDates[0];
				if ( ! from && /^\d{4}-\d\d-\d\d/.test( start.value ) ) { from = new Date( +start.value.slice( 0, 4 ), +start.value.slice( 5, 7 ) - 1, +start.value.slice( 8, 10 ) ); }
				if ( from ) {
					from = new Date( from.getFullYear(), from.getMonth(), from.getDate() + ( parseInt( c.gap, 10 ) || 0 ) );
					if ( ! min || from > min ) { min = from; }
				}
			}
			fp.set( 'minDate', min || null );
			var chosen = fp.selectedDates[0];
			if ( chosen && min && chosen < min ) { fp.clear(); }
		}
		if ( start ) {
			start.addEventListener( 'change', limit );
			if ( start._flatpickr ) { start._flatpickr.config.onChange.push( limit ); }
		}
		limit();
	}

	// ---- Messages: Bricks shows data-error-message, so it is set before Bricks reads it ----
	function setMsg( el ) {
		var m = el.__tfMsg || ( el.__tfMsg = parse( el.getAttribute( 'data-tf-msg' ) ) );
		var msg = '' === String( el.value ).trim() ? ( m.r || m.i ) : ( m.i || m.r );
		if ( msg ) { el.setAttribute( 'data-error-message', msg ); }
	}
	function messages( form ) {
		var one = function ( e ) { if ( e.target && e.target.hasAttribute && e.target.hasAttribute( 'data-tf-msg' ) ) { setMsg( e.target ); } };
		[ 'input', 'blur', 'change' ].forEach( function ( t ) { form.addEventListener( t, one, true ); } );
		form.addEventListener( 'submit', function () { form.querySelectorAll( '[data-tf-msg]' ).forEach( setMsg ); }, true );
	}

	function extras( form ) {
		form.querySelectorAll( FIELDS ).forEach( function ( el ) {
			if ( el.__tfLooks ) { return; }
			el.__tfLooks = true;
			var look = el.hasAttribute( 'data-tf-look' ) ? parse( el.getAttribute( 'data-tf-look' ) ) : null;
			if ( look && 'slider' === look.t ) { slider( el, look ); }
			if ( look && 'rating' === look.t ) { rating( el, look ); }
			if ( look && 'range' === look.t ) { pairRange( el, look ); }
			if ( el.hasAttribute( 'data-tf-mask' ) ) { masked( el, el.getAttribute( 'data-tf-mask' ) ); }
			if ( el.hasAttribute( 'data-tf-counter' ) ) { counter( el ); }
			if ( el.hasAttribute( 'data-tf-date' ) ) { dateLimits( el, parse( el.getAttribute( 'data-tf-date' ) ) ); }
		} );
		messages( form );
	}

	// "Submit only when": data-tf-submit = { match, rules, mode: disable|hide }.
	function submitRules( form ) {
		if ( ! form.hasAttribute( 'data-tf-submit' ) ) { return; }
		var c = form.__tfSubmit || ( form.__tfSubmit = parse( form.getAttribute( 'data-tf-submit' ) ) ), ok = holds( form, c );
		var wrap = form.querySelector( ':scope > .submit-button-wrapper' );
		if ( 'hide' === c.mode ) {
			if ( wrap ) { wrap.classList.toggle( 'tf-submit-off', ! ok ); }
		} else {
			var b = form.querySelector( ':scope > .submit-button-wrapper button[type="submit"],:scope > .submit-button-wrapper button' ) || form.querySelector( 'button[type="submit"]' );
			if ( b ) { b.disabled = ! ok; b.setAttribute( 'aria-disabled', ok ? 'false' : 'true' ); }
		}
	}

	// ---- Live values: data-tf-live="<field label or id>" shows that field's answer ----
	function liveText( ref ) {
		var r = String( ref ).trim().toLowerCase(), found = null;
		Array.prototype.some.call( document.querySelectorAll( 'form.brxe-form' ), function ( form ) {
			return Array.prototype.some.call( form.querySelectorAll( '.form-group' ), function ( g ) {
				var hit = labelOf( g ).toLowerCase() === r || Array.prototype.some.call( g.querySelectorAll( FIELDS ), function ( el ) { return el.name === ref || el.name === 'form-field-' + ref || el.name === 'form-field-' + r; } );
				if ( hit ) { found = g; }
				return hit;
			} );
		} );
		return found ? answerOf( found ) : '';
	}
	function liveAll() {
		document.querySelectorAll( '[data-tf-live]' ).forEach( function ( el ) {
			var t = liveText( el.getAttribute( 'data-tf-live' ) );
			if ( el.textContent !== t ) { el.textContent = t; }
		} );
	}
	var liveQueued = false;
	function liveSoon() {
		if ( liveQueued ) { return; }
		liveQueued = true;
		( window.requestAnimationFrame || setTimeout )( function () { liveQueued = false; liveAll(); } );
	}
	function liveInit() {
		if ( window.__tfLive ) { return; }
		window.__tfLive = true;
		document.addEventListener( 'input', liveSoon );
		document.addEventListener( 'change', liveSoon );
		document.addEventListener( 'reset', function () { setTimeout( liveAll, 0 ); } );
		liveAll();
	}

	function init( root ) {
		( root || document ).querySelectorAll( 'form[data-tf-form]' ).forEach( function ( form ) {
			if ( form.__tfForm ) { return; }
			form.__tfForm = true;
			extras( form );
			var resume = form.hasAttribute( 'data-tf-save' ) ? saving( form ) : 0;
			syncLooks( form );
			conditions( form );
			for ( var k = 0; k < 4 && calcs( form ); k++ ) { conditions( form ); }
			requires( form );
			submitRules( form );
			// Results can show or hide fields, and hidden fields count as 0: a few rounds.
			var update = function () {
				for ( var k = 0; k < 4; k++ ) { conditions( form ); if ( ! calcs( form ) ) { break; } }
				requires( form );
				submitRules( form );
				if ( form.__tfSteps ) { form.__tfSteps.refresh(); }
			};
			form.addEventListener( 'input', update );
			form.addEventListener( 'change', update );
			form.addEventListener( 'reset', function () { setTimeout( function () { syncLooks( form ); update(); }, 0 ); } );
			if ( form.hasAttribute( 'data-tf-cart' ) ) {
				document.addEventListener( 'bricks/form/success', function ( e ) {
					var id = e.detail && e.detail.elementId;
					if ( ( ! id || form.getAttribute( 'data-element-id' ) === id || form.id === 'brxe-' + id ) && window.jQuery ) { window.jQuery( document.body ).trigger( 'wc_fragment_refresh' ); }
				} );
			}
			if ( form.hasAttribute( 'data-tf-steps' ) ) { steps( form ); }
			if ( resume > 1 && form.__tfSteps ) { form.__tfSteps.resume( resume - 1 ); }
		} );
		liveAll();
	}

	window.tokenforgeForms = { init: init, test: test, calc: calcEval, num: num, group: group, maskFormat: maskFormat };
	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', function () { init(); liveInit(); } );
	} else {
		init();
		liveInit();
	}
} )();
