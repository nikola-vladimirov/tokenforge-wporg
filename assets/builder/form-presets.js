/* TokenForge: form presets in the builder's form element.
 *
 * Under "Presets (TokenForge)" of a form: apply a whole-form preset (ready-made
 * or saved), add one field from a field preset, save the form as a preset,
 * delete, export and import. A preset only fills the settings of the form being
 * edited ($_state.activeElement); the settings object is changed in place, like
 * the builder's own panel does, so undo and saving work as usual.
 *
 * Every field gets a new ID on apply. A preset names its fields with "ref":
 * a setting that is exactly "@ref" becomes that field's ID, "{{@ref}}" in text
 * becomes "{{ID}}". Saving turns the IDs back into refs, so saved presets keep
 * such links (registration fields, meta rows).
 */
( function () {
	'use strict';
	if ( window.__tfFormPresets ) { return; }
	window.__tfFormPresets = true;

	var C = window.TokenForgeFormPresets || {};
	var T = C.i18n || {};
	var saved = ( C.saved || [] ).slice();
	var KEEP = /^(tf[A-Z]|email|success|redirect|confirmation|registration)/;

	function props() {
		var el = document.querySelector( 'div.brx-body.main' );
		return el && el.__vue_app__ && el.__vue_app__.config && el.__vue_app__.config.globalProperties;
	}
	function state() {
		var gp = props();
		return gp && gp.$_state;
	}
	// Write a setting the builder's own way (its watchers, unsaved flag), then redraw the canvas.
	function put( el, key, value ) {
		var gp = props();
		if ( gp && 'function' === typeof gp.$_updateSetting ) { gp.$_updateSetting( el.id, key, value ); } else { el.settings[ key ] = value; }
	}
	function redraw( el ) {
		var gp = props();
		if ( gp && 'function' === typeof gp.$_forceRender ) { gp.$_forceRender( 0, el.id ); }
	}
	function form() {
		var st = state(), el = st && st.activeElement;
		return el && 'form' === el.name && el.settings ? el : null;
	}
	function clone( v ) { return JSON.parse( JSON.stringify( v ) ); }
	function make( tag, cls, text ) { var n = document.createElement( tag ); if ( cls ) { n.className = cls; } if ( text ) { n.textContent = text; } return n; }
	function rid() {
		var s = '', c = 'abcdefghijklmnopqrstuvwxyz0123456789';
		while ( s.length < 6 ) { s += c.charAt( Math.floor( Math.random() * c.length ) ); }
		return s;
	}
	// Apply fn to every string inside v (arrays and objects included).
	function walk( v, fn ) {
		if ( 'string' === typeof v ) { return fn( v ); }
		if ( Array.isArray( v ) ) { return v.map( function ( x ) { return walk( x, fn ); } ); }
		if ( v && 'object' === typeof v ) { var o = {}; Object.keys( v ).forEach( function ( k ) { o[ k ] = walk( v[ k ], fn ); } ); return o; }
		return v;
	}
	// Give the clone's fields new IDs and resolve the @ref placeholders.
	function prepare( settings ) {
		var s = clone( settings ), map = {};
		( s.fields || [] ).forEach( function ( f ) { f.id = rid(); if ( f.ref ) { map[ f.ref ] = f.id; } delete f.ref; } );
		var rest = {};
		Object.keys( s ).forEach( function ( k ) {
			if ( 'fields' !== k ) {
				rest[ k ] = walk( s[ k ], function ( t ) {
					var m = /^@(\w+)$/.exec( t );
					if ( m ) { return map[ m[1] ] || ''; }
					return t.replace( /\{\{@(\w+)\}\}/g, function ( a, r ) { return map[ r ] ? '{{' + map[ r ] + '}}' : ''; } );
				} );
			}
		} );
		rest.fields = s.fields || [];
		return rest;
	}
	// The form's settings as a preset: only preset keys, IDs turned into refs.
	function snapshot( el ) {
		var s = clone( el.settings ), out = {}, map = {};
		( s.fields || [] ).forEach( function ( f ) { if ( f.id ) { map[ f.id ] = f.id; f.ref = f.id; delete f.id; } } );
		Object.keys( s ).forEach( function ( k ) {
			if ( 'fields' === k || 'actions' === k || KEEP.test( k ) ) {
				out[ k ] = 'fields' === k ? s[ k ] : walk( s[ k ], function ( t ) {
					if ( map[ t ] ) { return '@' + t; }
					return t.replace( /\{\{(\w+)\}\}/g, function ( a, id ) { return map[ id ] ? '{{@' + id + '}}' : a; } );
				} );
			}
		} );
		return out;
	}
	function request( method, path, body ) {
		return fetch( C.url + ( path || '' ), {
			method: method,
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': C.nonce },
			body: body ? JSON.stringify( body ) : undefined
		} ).then( function ( r ) {
			return r.json().then( function ( j ) { if ( ! r.ok ) { throw new Error( j && j.message ? j.message : T.failed ); } return j; } );
		} );
	}
	function fail( e ) { window.alert( ( e && e.message ) || T.failed || 'Error' ); }

	function ui( wrap ) {
		var forms = C.forms || {}, fields = C.fields || {};
		var box = make( 'div', 'tf-fpre' );
		var formSel = make( 'select', 'tf-fpre-sel' ), apply = make( 'button', 'tf-fpre-btn', T.apply || 'Apply' );
		var fieldSel = make( 'select', 'tf-fpre-sel' ), add = make( 'button', 'tf-fpre-btn', T.addField || 'Add field' );
		var save = make( 'button', 'tf-fpre-btn', T.save || 'Save as preset' ), del = make( 'button', 'tf-fpre-btn', T.delete || 'Delete' );
		var exp = make( 'button', 'tf-fpre-btn', T.export || 'Export' ), imp = make( 'button', 'tf-fpre-btn', T.import || 'Import' );
		formSel.setAttribute( 'aria-label', T.form || 'Whole form' );
		fieldSel.setAttribute( 'aria-label', T.field || 'Field' );
		[ apply, add, save, del, exp, imp ].forEach( function ( b ) { b.type = 'button'; } );

		function option( parent, value, text ) { var o = make( 'option', '', text ); o.value = value; parent.appendChild( o ); }
		function render() {
			var keep = formSel.value;
			formSel.innerHTML = '';
			option( formSel, '', T.pickForm || 'Choose a form preset' );
			var g1 = make( 'optgroup' ); g1.label = T.builtIn || 'Ready-made';
			Object.keys( forms ).forEach( function ( k ) { option( g1, 'b:' + k, forms[ k ].name ); } );
			formSel.appendChild( g1 );
			if ( saved.length ) {
				var g2 = make( 'optgroup' ); g2.label = T.savedGrp || 'Saved';
				saved.forEach( function ( p ) { option( g2, 's:' + p.id, p.name ); } );
				formSel.appendChild( g2 );
			}
			formSel.value = keep;
			if ( formSel.value !== keep ) { formSel.value = ''; }
			sync();
		}
		// The chosen preset: { name, settings, saved } or null.
		function chosen() {
			var v = formSel.value, id = v.slice( 2 );
			if ( 0 === v.indexOf( 'b:' ) ) { return forms[ id ] ? { name: forms[ id ].name, settings: forms[ id ].settings } : null; }
			for ( var i = 0; i < saved.length; i++ ) { if ( saved[ i ].id === id ) { return { name: saved[ i ].name, settings: saved[ i ].settings, saved: true, id: id }; } }
			return null;
		}
		function sync() {
			var p = chosen();
			apply.disabled = ! p;
			exp.disabled = ! p;
			del.hidden = ! ( p && p.saved ) || ! C.canSave;
			save.hidden = ! C.canSave;
			imp.hidden = ! C.canSave;
			add.disabled = ! fieldSel.value;
		}
		fieldSel.appendChild( make( 'option', '', T.pickField || 'Choose a field preset' ) ).value = '';
		Object.keys( fields ).forEach( function ( k ) { option( fieldSel, k, fields[ k ].name ); } );

		formSel.addEventListener( 'change', sync );
		fieldSel.addEventListener( 'change', sync );
		apply.addEventListener( 'click', function () {
			var el = form(), p = chosen();
			if ( ! el ) { window.alert( T.noForm ); return; }
			if ( ! p || ! window.confirm( T.confirm ) ) { return; }
			var s = prepare( p.settings );
			Object.keys( s ).forEach( function ( k ) { put( el, k, s[ k ] ); } );
			redraw( el );
		} );
		add.addEventListener( 'click', function () {
			var el = form(), p = fields[ fieldSel.value ];
			if ( ! el ) { window.alert( T.noForm ); return; }
			if ( ! p ) { return; }
			var f = clone( p.field );
			f.id = rid();
			delete f.ref;
			put( el, 'fields', ( Array.isArray( el.settings.fields ) ? clone( el.settings.fields ) : [] ).concat( [ f ] ) );
			redraw( el );
		} );
		function store( name, settings ) {
			return request( 'POST', '', { name: name, settings: settings } ).then( function ( list ) { saved = list; render(); } ).catch( fail );
		}
		save.addEventListener( 'click', function () {
			var el = form();
			if ( ! el ) { window.alert( T.noForm ); return; }
			var name = window.prompt( T.name || 'Name of the preset' );
			if ( name && name.trim() ) { store( name.trim(), snapshot( el ) ); }
		} );
		del.addEventListener( 'click', function () {
			var p = chosen();
			if ( ! p || ! p.saved || ! window.confirm( T.sure ) ) { return; }
			request( 'DELETE', '/' + p.id ).then( function ( list ) { saved = list; formSel.value = ''; render(); } ).catch( fail );
		} );
		exp.addEventListener( 'click', function () {
			var p = chosen();
			if ( ! p ) { return; }
			var json = JSON.stringify( { name: p.name, settings: p.settings } );
			var done = function () { window.alert( T.copied ); };
			if ( navigator.clipboard && navigator.clipboard.writeText ) { navigator.clipboard.writeText( json ).then( done, function () { window.prompt( T.copy, json ); } ); } else { window.prompt( T.copy, json ); }
		} );
		imp.addEventListener( 'click', function () {
			var text = window.prompt( T.paste ), data;
			if ( ! text ) { return; }
			try { data = JSON.parse( text ); } catch ( e ) { window.alert( T.bad ); return; }
			var s = data && data.settings ? data.settings : data;
			if ( ! s || ! Array.isArray( s.fields ) || ! s.fields.length || ! s.fields.every( function ( f ) { return f && 'object' === typeof f && 'string' === typeof f.type; } ) ) { window.alert( T.bad ); return; }
			var name = ( data.name && String( data.name ) ) || window.prompt( T.name );
			if ( name && name.trim() ) { store( name.trim(), s ); }
		} );

		var r1 = make( 'div', 'tf-fpre-row' ), r2 = make( 'div', 'tf-fpre-row' ), r3 = make( 'div', 'tf-fpre-row' );
		[ formSel, apply ].forEach( function ( n ) { r1.appendChild( n ); } );
		[ fieldSel, add ].forEach( function ( n ) { r2.appendChild( n ); } );
		[ save, del, exp, imp ].forEach( function ( n ) { r3.appendChild( n ); } );
		[ r1, r2, r3 ].forEach( function ( n ) { box.appendChild( n ); } );
		wrap.appendChild( box );
		render();
	}

	function scan() {
		document.querySelectorAll( '[data-controlkey="tfPresetUi"] > .control:not([data-tf-fpre]),[data-control-key="tfPresetUi"] > .control:not([data-tf-fpre])' ).forEach( function ( ctl ) {
			ctl.setAttribute( 'data-tf-fpre', '' );
			ui( ctl );
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
