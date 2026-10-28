/* TokenForge: pick fields for form conditions and calculations in the builder.
 *
 * Under "More conditions", "Required only when" and a step's conditions of a
 * form field: choose a field, how to compare and the value (a choice field
 * lists its options), then Add writes the line. Under "Calculate": choose a
 * field to put it in the formula where the cursor is. The lines are the same
 * text you could type; the builder keeps them like any other setting.
 *
 * The fields come from the form being edited ($_state.activeElement, the
 * builder's own state); the settings update through the input event the
 * builder listens to.
 */
( function () {
	'use strict';
	if ( window.__tfFormPicker ) { return; }
	window.__tfFormPicker = true;

	var L = window.TokenForgeFormPicker || {};
	var RULES = [ 'tfShowRules', 'tfStepShowRules', 'tfRequireRules', 'tfSubmitRules' ]; // Bricks 2.4 names the wrapper data-controlkey, older versions data-control-key
	var OPS = [ [ '=', L.is || 'is' ], [ '!=', L.isnot || 'is not' ], [ '>', '>' ], [ '<', '<' ], [ '>=', '>=' ], [ '<=', '<=' ], [ 'contains', L.contains || 'contains' ], [ 'empty', L.empty || 'is empty' ], [ 'not empty', L.notempty || 'is not empty' ] ];

	function state() {
		var el = document.querySelector( 'div.brx-body.main' );
		var gp = el && el.__vue_app__ && el.__vue_app__.config && el.__vue_app__.config.globalProperties;
		return gp && gp.$_state;
	}
	// The form's fields, minus the one being edited and text-only parts.
	function fields( selfId ) {
		var st = state(), form = st && st.activeElement;
		if ( ! form || 'form' !== form.name || ! form.settings || ! Array.isArray( form.settings.fields ) ) { return []; }
		var list = form.settings.fields.filter( function ( f ) { return f && f.id && f.id !== selfId && 'html' !== f.type; } );
		var count = {};
		list.forEach( function ( f ) { var k = String( f.label || '' ).trim().toLowerCase(); if ( k ) { count[ k ] = ( count[ k ] || 0 ) + 1; } } );
		return list.map( function ( f ) {
			var label = String( f.label || '' ).trim();
			// The label when it names one field only (and has no braces), else the ID.
			var ref = label && 1 === count[ label.toLowerCase() ] && ! /[{}]/.test( label ) ? label : f.id;
			return { ref: ref, text: label ? label + ( ref === f.id ? ' (' + f.id + ')' : '' ) : f.id + ' (' + ( f.type || '' ) + ')', field: f };
		} );
	}
	// A choice field's values: [ value, label ].
	function options( f ) {
		if ( ! f || [ 'select', 'radio', 'checkbox' ].indexOf( f.type ) === -1 || ! f.options ) { return null; }
		return String( f.options ).split( /\r?\n/ ).map( function ( line ) { return line.trim(); } ).filter( Boolean ).map( function ( line ) {
			var i = line.indexOf( ':' );
			return f.valueLabelOptions && i > -1 ? [ line.slice( 0, i ).trim(), line.slice( i + 1 ).trim() ] : [ line, line ];
		} );
	}
	function make( tag, cls, text ) { var n = document.createElement( tag ); if ( cls ) { n.className = cls; } if ( text ) { n.textContent = text; } return n; }
	function fill( select, list, first ) {
		select.innerHTML = '';
		if ( first ) { select.appendChild( make( 'option', '', first ) ).value = ''; }
		list.forEach( function ( o ) { var opt = make( 'option', '', o[1] ); opt.value = o[0]; select.appendChild( opt ); } );
	}
	// Tell the builder the box changed (it listens to input).
	function changed( box ) { box.dispatchEvent( new Event( 'input', { bubbles: true } ) ); }

	function rulesBar( wrap, selfId ) {
		var box = wrap.querySelector( 'textarea' );
		if ( ! box ) { return; }
		var bar = make( 'div', 'tf-fp' ), field = make( 'select', 'tf-fp-field' ), op = make( 'select', 'tf-fp-op' ), valSel = make( 'select', 'tf-fp-val' ), valIn = make( 'input', 'tf-fp-val' ), add = make( 'button', 'tf-fp-add', L.add || 'Add' );
		field.setAttribute( 'aria-label', L.field || 'Field' );
		op.setAttribute( 'aria-label', L.compare || 'Compare' );
		valSel.setAttribute( 'aria-label', L.value || 'Value' );
		valIn.setAttribute( 'aria-label', L.value || 'Value' );
		valIn.type = 'text';
		valIn.placeholder = L.value || 'Value';
		add.type = 'button';
		fill( op, OPS );
		var list = [];
		var refresh = function () {
			var keep = field.value;
			list = fields( selfId );
			fill( field, list.map( function ( x ) { return [ x.ref, x.text ]; } ), L.pick || 'Choose a field' );
			field.value = keep;
			sync();
		};
		var current = function () { for ( var i = 0; i < list.length; i++ ) { if ( list[ i ].ref === field.value ) { return list[ i ]; } } return null; };
		var sync = function () {
			var c = current(), o = options( c && c.field ), noValue = /empty$/.test( op.value );
			valSel.hidden = noValue || ! o;
			valIn.hidden = noValue || !! o;
			if ( o ) { fill( valSel, o ); }
			add.disabled = ! c;
		};
		field.addEventListener( 'focus', refresh );
		field.addEventListener( 'change', sync );
		op.addEventListener( 'change', sync );
		add.addEventListener( 'click', function () {
			var c = current();
			if ( ! c ) { return; }
			var v = /empty$/.test( op.value ) ? '' : ( valSel.hidden ? valIn.value : valSel.value ).trim();
			var line = '{' + c.ref + '} ' + op.value + ( '' !== v ? ' ' + v : '' );
			box.value = box.value.replace( /\s+$/, '' ) + ( box.value.trim() ? '\n' : '' ) + line;
			changed( box );
			valIn.value = '';
		} );
		[ field, op, valSel, valIn, add ].forEach( function ( n ) { bar.appendChild( n ); } );
		wrap.appendChild( bar );
		refresh();
	}

	function calcBar( wrap, selfId ) {
		var box = wrap.querySelector( 'input[type="text"]' );
		if ( ! box ) { return; }
		var bar = make( 'div', 'tf-fp' ), field = make( 'select', 'tf-fp-field' );
		field.setAttribute( 'aria-label', L.insert || 'Insert a field' );
		var caret = null;
		box.addEventListener( 'blur', function () { caret = [ box.selectionStart, box.selectionEnd ]; } );
		var refresh = function () { fill( field, fields( selfId ).map( function ( x ) { return [ x.ref, x.text ]; } ), L.insert || 'Insert a field' ); };
		field.addEventListener( 'focus', refresh );
		field.addEventListener( 'change', function () {
			if ( ! field.value ) { return; }
			var text = '{' + field.value + '}', v = box.value, at = caret || [ v.length, v.length ];
			box.value = v.slice( 0, at[0] ) + text + v.slice( at[1] );
			changed( box );
			caret = [ at[0] + text.length, at[0] + text.length ];
			field.value = '';
			box.focus();
			try { box.setSelectionRange( caret[0], caret[1] ); } catch ( e ) {}
		} );
		bar.appendChild( field );
		wrap.appendChild( bar );
		refresh();
	}

	function scan() {
		var sel = RULES.concat( [ 'tfCalc' ] ).map( function ( k ) { return '[data-controlkey="' + k + '"] > .control:not([data-tf-fp]),[data-control-key="' + k + '"] > .control:not([data-tf-fp])'; } ).join( ',' );
		document.querySelectorAll( sel ).forEach( function ( ctl ) {
			ctl.setAttribute( 'data-tf-fp', '' );
			var key = ( ctl.parentNode.getAttribute( 'data-controlkey' ) || ctl.parentNode.getAttribute( 'data-control-key' ) ), item = ctl.closest( 'li[data-key]' ), selfId = item ? item.getAttribute( 'data-key' ) : '';
			if ( 'tfCalc' === key ) { calcBar( ctl, selfId ); } else { rulesBar( ctl, selfId ); }
		} );
	}
	var queued = false;
	function later() { if ( ! queued ) { queued = true; requestAnimationFrame( function () { queued = false; scan(); } ); } }
	function start() {
		new MutationObserver( later ).observe( document.body, { childList: true, subtree: true } );
		scan();
	}
	if ( 'loading' === document.readyState ) { document.addEventListener( 'DOMContentLoaded', start ); } else { start(); }
} )();
