/* TokenForge: one adapter for the builders that are not Bricks.
 *
 * window.TokenForgeBuilderCfg = { platform } picks an entry of the table below; an unknown id sets nothing and
 * the tools stay off. The entry becomes window.TokenForgeBuilder:
 *   { id, isControlInput(el), controlKey(el), isClassField(el), selected(), classes(), toggleClass(name),
 *     canvasDoc(), toolbar(), editorFrom(node) }
 * context-menu.js, input-helper.js and panel.js ask it instead of the Bricks store. Every call is lazy (made on
 * a user event) and guarded: nothing here may throw into the builder.
 * editorFrom() gives a CSS editor the CodeMirror 5 methods context-menu.js uses, over a small model
 * (CodeMirror 6, or Ace in Elementor); Divi's editor is a real CodeMirror 5 and is handed over as it is.
 * cm.tfRoot is the builder's word for the element ('' = it has none).
 * prepare( input, done ), where a builder has it, sets a field up for a variable (its custom unit) before a write.
 * commit( input ), where a builder has it, makes the builder take a menu write while the field keeps the focus.
 */
( function () {
	'use strict';
	if ( window.__tokenforgeBuilder ) { return; } // load once
	window.__tokenforgeBuilder = true;

	var id = ( window.TokenForgeBuilderCfg || {} ).platform;

	function safe( fn, fallback ) { try { return fn(); } catch ( e ) { return fallback; } }

	// ---- CodeMirror 5 look-alike over a model:
	// text(), head(), replace(from,to,text), select(a,b), coords(i), focus(), dom, onInput(fn(data)), closeOwnList().
	function shim( model, root ) {
		var on = {}, keys = null, last = -1;
		var KEY = { ArrowUp: 'Up', ArrowDown: 'Down', Enter: 'Enter', Tab: 'Tab', Escape: 'Esc' };
		function idx( pos ) {
			var lines = model.text().split( '\n' ), i = 0;
			for ( var l = 0; l < pos.line && l < lines.length; l++ ) { i += lines[ l ].length + 1; }
			return i + pos.ch;
		}
		function pos( i ) {
			var before = model.text().slice( 0, i ).split( '\n' );
			return { line: before.length - 1, ch: before[ before.length - 1 ].length };
		}
		function fire( ev, arg ) { if ( on[ ev ] ) { safe( function () { on[ ev ]( cm, arg ); } ); } }
		var cm = {
			tfRoot: root || '',
			state: { completionActive: { close: function () { safe( model.closeOwnList ); } } },
			getOption: function ( k ) { return 'mode' === k ? 'css' : undefined; },
			on: function ( ev, fn ) { on[ ev ] = fn; },
			getCursor: function () { return pos( model.head() ); },
			getLine: function ( n ) { return model.text().split( '\n' )[ n ] || ''; },
			getRange: function ( a, b ) { return model.text().slice( idx( a ), idx( b ) ); },
			replaceRange: function ( text, a, b ) { model.replace( idx( a ), idx( b || a ), text ); last = model.head(); },
			cursorCoords: function ( p ) { return model.coords( idx( p ) ); },
			addKeyMap: function ( k ) { keys = k; },
			removeKeyMap: function ( k ) { if ( keys === k ) { keys = null; } },
			indexFromPos: idx,
			posFromIndex: pos,
			setSelection: function ( a, b ) { model.select( idx( a ), idx( b ) ); last = model.head(); },
			setCursor: function ( p ) { model.select( idx( p ), idx( p ) ); last = model.head(); },
			focus: function () { safe( model.focus ); }
		};
		// Typed text: next tick, when the editor's own state has it.
		model.onInput( function ( data ) {
			setTimeout( function () {
				last = safe( model.head, -1 );
				if ( data ) { fire( 'inputRead', { text: [ data ], origin: '+input' } ); } else { fire( 'cursorActivity' ); }
			}, 0 );
		} );
		// The caret moved without typing (arrows, a click): only when it really moved, so the keys we handle
		// below do not reset the open list.
		var moved = function () {
			setTimeout( function () {
				var h = safe( model.head, -1 );
				if ( h !== last ) { last = h; fire( 'cursorActivity' ); }
			}, 0 );
		};
		model.dom.addEventListener( 'keyup', moved );
		model.dom.addEventListener( 'mouseup', moved );
		model.dom.addEventListener( 'focusout', function () { fire( 'blur' ); } );
		// While our list is open its keys are ours alone: capture on the editor root, before the editor's handlers.
		model.dom.addEventListener( 'keydown', function ( e ) {
			var fn = keys && ! e.ctrlKey && ! e.metaKey && ! e.altKey && keys[ KEY[ e.key ] ];
			if ( ! fn ) { return; }
			e.preventDefault(); e.stopPropagation();
			safe( fn );
		}, true );
		return cm;
	}

	// ---- CodeMirror 6 model (view = EditorView) ----
	function cm6( view ) {
		return {
			dom: view.dom,
			text: function () { return view.state.doc.toString(); },
			head: function () { return view.state.selection.main.head; },
			replace: function ( from, to, text ) {
				view.dispatch( { changes: { from: from, to: to, insert: text }, selection: { anchor: from + text.length }, userEvent: 'input.complete' } );
			},
			select: function ( a, b ) { view.dispatch( { selection: { anchor: a, head: b } } ); },
			coords: function ( i ) {
				var c = safe( function () { return view.coordsAtPos( i ); } ) || view.dom.getBoundingClientRect();
				return { left: c.left, top: c.top, bottom: c.bottom };
			},
			focus: function () { view.focus(); },
			onInput: function ( fn ) {
				view.contentDOM.addEventListener( 'input', function ( e ) {
					// Real typing only: a paste or a drop is not a cue for suggestions.
					fn( 'insertText' === e.inputType || 'insertCompositionText' === e.inputType ? e.data || '' : '' );
				} );
			},
			closeOwnList: function () {} // this builder shows no completion list of its own
		};
	}

	// ---- Ace model (Elementor Pro Custom CSS). Positions are counted over the lines joined with "\n", whatever
	// new line character the document itself keeps. ----
	function ace( ed ) {
		function lines() { return ed.session.doc.getAllLines(); }
		function at( i ) {
			var l = lines(), r = 0;
			while ( r < l.length - 1 && i > l[ r ].length ) { i -= l[ r ].length + 1; r++; }
			return { row: r, column: i };
		}
		function index( p ) {
			var l = lines(), i = 0;
			for ( var r = 0; r < p.row && r < l.length; r++ ) { i += l[ r ].length + 1; }
			return i + p.column;
		}
		function select( a, b ) {
			var p = at( a );
			ed.selection.setSelectionAnchor( p.row, p.column );
			ed.selection.selectToPosition( at( b ) );
		}
		return {
			dom: ed.container,
			text: function () { return lines().join( '\n' ); },
			head: function () { return index( ed.getCursorPosition() ); },
			replace: function ( from, to, text ) {
				ed.session.replace( { start: at( from ), end: at( to ) }, text );
				select( from + text.length, from + text.length );
			},
			select: select,
			coords: function ( i ) {
				var p = at( i ), c = ed.renderer.textToScreenCoordinates( p.row, p.column );
				var top = c.pageY - ( window.pageYOffset || 0 );
				return { left: c.pageX - ( window.pageXOffset || 0 ), top: top, bottom: top + ( ed.renderer.lineHeight || 16 ) };
			},
			focus: function () { ed.focus(); },
			onInput: function ( fn ) {
				// Real typing only (a paste is its own command).
				ed.commands.on( 'afterExec', function ( e ) {
					if ( e && e.command && 'insertstring' === e.command.name ) { fn( 'string' === typeof e.args ? e.args : '' ); }
				} );
			},
			// Elementor's own completion list (live autocompletion) stays shut while ours is open.
			closeOwnList: function () { if ( ed.completer ) { ed.completer.detach(); } }
		};
	}

	// One wrapper per editor, kept on its .cm-editor element.
	function editor( root, tfRoot ) {
		if ( ! root.__tfCm ) {
			var tile = root.querySelector( '.cm-content' ).cmTile, view = tile.view || tile.root.view;
			root.__tfCm = shim( cm6( view ), tfRoot );
		}
		root.__tfCm.tfRoot = tfRoot;
		return root.__tfCm;
	}

	// ---- Oxygen 6 and Breakdance 3: one code base (window.Breakdance.stores). oxy = the Oxygen build. ----
	function breakdance( oxy ) {
		function S() { return ( window.Breakdance && window.Breakdance.stores ) || null; }
		function selected() { return safe( function () { return S().uiStore.activeElementId || null; }, null ); }
		function props() { return S().uiStore.activeElement.data.properties || {}; }
		// Two class systems: Oxygen's (ids of library records in meta.classes) wherever its class widget shows,
		// which is always in Oxygen; else Breakdance's older one (names in settings.advanced.classes).
		// live check: Breakdance.stores.configStore.hasLegacySelectors; !! document.querySelector( '.oxy-class-selector' )
		function oxyStyle() { return oxy || !! document.querySelector( '.oxy-class-selector' ); }
		function ids() { return ( ( props().meta || {} ).classes || [] ).slice(); }
		function names() { return ( ( ( props().settings || {} ).advanced || {} ).classes || [] ).slice(); }
		function library() { return S().globalStore.oxySelectors || []; }
		// The builder's own ids are crypto.randomUUID(); the fallback is for a page without it (plain http).
		function uuid() {
			return window.crypto && window.crypto.randomUUID ? window.crypto.randomUUID() : 'xxxxxxxx-xxxx-4xxx-8xxx-xxxxxxxxxxxx'.replace( /x/g, function () { return Math.floor( Math.random() * 16 ).toString( 16 ); } );
		}
		// The property path of a field: its wrapper's path attribute (seen live in Oxygen: "spacing.spacing.padding.top"),
		// else the control's test id, which is the path with dashes.
		// live check (Breakdance): [].map.call( document.querySelectorAll( '[path], [data-test-id^="control-"]' ), function ( n ) { return n.getAttribute( 'path' ) || n.getAttribute( 'data-test-id' ); } )
		function key( el ) {
			var c = el && el.closest ? el.closest( '[path], [data-test-id^="control-"]' ) : null;
			return c ? c.getAttribute( 'path' ) || String( c.getAttribute( 'data-test-id' ) || '' ).slice( 8 ) : '';
		}
		return {
			// Style fields (some sit in popovers on the body, outside the panel): text inputs with a property path,
			// not those of the Content and Settings tabs, the class field or code.
			isControlInput: function ( el ) {
				var k = key( el );
				return !! k && 'INPUT' === el.tagName && ( ! el.type || 'text' === el.type ) && ! /^(content|settings)[.-]/.test( k ) &&
					! el.closest( '.oxy-class-selector, .element-properties-class-dropdown, .cm-editor' );
			},
			controlKey: key,
			// A unit field keeps var() and calc() only with its "custom" unit (seen live: px stores null). Switch to it
			// through the field's own unit menu, then hand back the field (done() runs at once when nothing to switch).
			// done( null ) when the switch did not take or another element got selected meanwhile: no write then.
			prepare: function ( input, done ) {
				function unit( el ) { return safe( function () { return el.closest( '.breakdance-unit-input' ).querySelector( '.breakdance-unit-input-unit' ); }, null ); }
				var btn = unit( input );
				if ( ! btn || '-' === btn.textContent.trim() ) { done( input ); return; }
				var path = key( input ), was = selected();
				btn.click();
				setTimeout( function () {
					[].some.call( document.querySelectorAll( '.v-popover .v-list-item' ), function ( li ) {
						return 'custom' === li.textContent.trim() && ( li.click(), true );
					} );
					setTimeout( function () {
						var el = input.isConnected ? input : safe( function () { return document.querySelector( '[path="' + path + '"] input' ); }, null ), b = el && unit( el );
						done( el && b && '-' === b.textContent.trim() && selected() === was ? el : null );
					}, 60 );
				}, 60 );
			},
			isClassField: function ( el ) { return !! ( el && el.closest && el.closest( '.oxy-class-selector, .element-properties-class-dropdown' ) ); },
			selected: selected,
			classes: function () {
				return safe( function () {
					if ( ! oxyStyle() ) { return names(); }
					var lib = library();
					return ids().map( function ( cid ) {
						for ( var i = 0; i < lib.length; i++ ) { if ( lib[ i ].id === cid ) { return lib[ i ].name; } }
						return '';
					} ).filter( Boolean );
				}, [] );
			},
			// True when the class was added. Through the builder's own store actions: its Save and undo keep the change.
			// live check: after a pick, Breakdance.stores.uiStore.unsavedChangesPresent (the Save button turns active)
			toggleClass: function ( name ) {
				return safe( function () {
					var sid = selected(), g = S().globalStore, ox = oxyStyle(), list, value, token = name, i, has = false;
					if ( ! sid ) { return false; }
					if ( ox ) {
						list = library(); value = ids(); token = '';
						for ( i = 0; i < list.length; i++ ) { if ( 'class' === list[ i ].type && list[ i ].name === name ) { token = list[ i ].id; } }
						has = !! token;
						if ( ! has ) { token = uuid(); }
					} else {
						list = g.legacySelectors || []; value = names();
						for ( i = 0; i < list.length; i++ ) { if ( list[ i ].name === '.' + name ) { has = true; } }
					}
					i = value.indexOf( token );
					if ( i > -1 ) {
						value.splice( i, 1 );
					} else {
						// A class the builder's library does not know yet joins it first.
						if ( ! has && ox ) { g.setOxygenSelectors( list.concat( [ { id: token, name: name, children: [], locked: false, collection: 'Default', type: 'class' } ] ) ); }
						if ( ! has && ! ox ) { g.setLegacySelectors( list.concat( [ { name: '.' + name, type: 'class', properties: {} } ] ) ); }
						value.push( token );
					}
					S().documentStore.unthrottledPropertyChanged( { elementId: sid, path: ox ? 'meta.classes' : 'settings.advanced.classes', value: value, meta: { snapshotLabel: i > -1 ? 'Remove class from element' : 'Add class to element' } } );
					return i < 0;
				}, false );
			},
			canvasDoc: function () { return safe( function () { return window.Breakdance.canvas.document; }, null ); },
			toolbar: function () { return document.querySelector( '.breakdance-top-bar-wrapper' ); },
			// The Custom CSS field only (other code fields are CodeMirror too).
			editorFrom: function ( node ) {
				return safe( function () {
					var root = node.closest( '.breakdance-codemirror .cm-editor, .oxy-code-input .cm-editor' );
					// Oxygen and classes: custom_css.custom_css; a Breakdance element (seen live): settings-advanced-css-breakpoint_base.
					if ( ! root || ! /custom[-_]css|advanced[-_.]css/.test( key( root ) ) ) { return null; }
					// The builder's word for the element: ':selector' in Oxygen (seen live in its placeholder).
					// live check (Breakdance): document.querySelector( '.breakdance-codemirror .cm-placeholder' ).textContent with an empty Custom CSS
					return editor( root, oxy ? ':selector' : '%%SELECTOR%%' );
				}, null );
			}
		};
	}

	// ---- Builders ----
	var TABLE = {
		// Etch 1.6.1. No style text inputs (tag, attribute fields and code drawers only).
		etch: function () {
			function blocks() { return ( window.etch && window.etch.blocks ) || null; }
			function selected() { return safe( function () { return blocks().getSelectedId() || null; }, null ); }
			return {
				isControlInput: function () { return false; },
				controlKey: function () { return ''; },
				// The CSS drawer's title row (its class badges strip is 0 px tall until a class exists), or the "class" attribute field.
				isClassField: function ( el ) {
					if ( ! el || ! el.closest ) { return false; }
					if ( el.closest( '.etch-css-header-title-wrapper, .etch-css-selectors' ) ) { return true; }
					var label = el.matches( 'input.etch-input--text' ) && el.closest( 'label.etch-label' );
					return !! label && 'class' === label.textContent.trim().toLowerCase();
				},
				selected: selected,
				classes: function () {
					return safe( function () {
						return String( blocks().getJson( selected() ).attributes[ 'class' ] || '' ).split( /\s+/ ).filter( Boolean );
					}, [] );
				},
				// True when the class was added. The builder's own API: its Save and undo keep the change.
				toggleClass: function ( name ) {
					return safe( function () {
						var b = blocks(), sid = selected();
						if ( ! sid ) { return false; }
						if ( b.hasClass( sid, name ) ) { b.removeClass( sid, name ); return false; }
						b.addClass( sid, name );
						return true;
					}, false );
				},
				canvasDoc: function () { return safe( function () { return document.getElementById( 'etch-iframe' ).contentDocument; }, null ); },
				toolbar: function () { return null; }, // no stable bar element known: the window keeps its own top
				// The CSS drawer only (the HTML and JS drawers are CodeMirror too).
				editorFrom: function ( node ) {
					return safe( function () {
						var root = node.closest( '.etch-css-editor .cm-editor' );
						if ( ! root ) { return null; }
						return editor( root, '' );
					}, null );
				}
			};
		},
		oxygen: function () { return breakdance( true ); },
		breakdance: function () { return breakdance( false ); },
		// Elementor 4.4, classic widgets and containers (the atomic elements have other fields and are left alone).
		elementor: function () {
			function ctl( el ) { return el && el.closest ? el.closest( '#elementor-panel .elementor-control' ) : null; }
			// A control's classes: elementor-control-<name> and elementor-control-type-<type>, among others.
			function part( c, type ) {
				var m = c ? ( ' ' + c.className + ' ' ).match( type ? / elementor-control-type-([\w-]+) / : / elementor-control-(?!type-|separator-|responsive-)([\w-]+) / ) : null;
				return m ? m[ 1 ] : '';
			}
			function custom( c ) { return c.querySelector( 'input[type="radio"][value="custom"]' ); }
			function container() { return safe( function () { return window.elementor.selection.getElements()[ 0 ] || null; }, null ); }
			// Widgets keep their classes in _css_classes, containers (sections, columns) in css_classes.
			function classKey( c ) { return 'widget' === c.model.get( 'elType' ) ? '_css_classes' : 'css_classes'; }
			function names( c ) { return String( c.settings.get( classKey( c ) ) || '' ).split( /\s+/ ).filter( Boolean ); }
			return {
				// Size fields. They take text (and so a variable) only with the custom unit: a number field counts
				// when its control offers that unit, and prepare() switches to it before a write.
				isControlInput: function ( el ) {
					var c = ctl( el );
					if ( ! c || 'INPUT' !== el.tagName || ! el.getAttribute( 'data-setting' ) || ! /^(dimensions|slider|number|gaps)$/.test( part( c, true ) ) ) { return false; }
					return ! el.type || 'text' === el.type || ( 'number' === el.type && !! custom( c ) );
				},
				controlKey: function ( el ) { var c = ctl( el ); return c ? part( c ) + '-' + ( el.getAttribute( 'data-setting' ) || '' ) : ''; },
				// Seen live: the unit radios sit in the control; a click on "custom" turns its number inputs into text inputs.
				prepare: function ( input, done ) {
					var c = ctl( input ), radio = safe( function () { return custom( c ); }, null );
					if ( ! radio || radio.checked ) { done( input ); return; }
					var name = part( c ), setting = input.getAttribute( 'data-setting' ), was = ( container() || {} ).id;
					radio.click();
					setTimeout( function () {
						var el = safe( function () {
							return ( c.isConnected ? c : document.querySelector( '#elementor-panel .elementor-control-' + name ) ).querySelector( 'input[data-setting="' + setting + '"]' );
						}, null );
						// Only the re-drawn text field of the same element (a number field would drop the var()).
						done( el && 'text' === el.type && ( container() || {} ).id === was ? el : null );
					}, 60 );
				},
				isClassField: function ( el ) { return !! ( el && el.closest && el.closest( '#elementor-panel .elementor-control-_css_classes, #elementor-panel .elementor-control-css_classes' ) ); },
				selected: function () { var c = container(); return ( c && c.id ) || null; },
				classes: function () { return safe( function () { return names( container() ); }, [] ); },
				// True when the class was added. The builder's own command: undo and Update keep the change, and
				// "external" makes the open CSS Classes field show it.
				toggleClass: function ( name ) {
					return safe( function () {
						var c = container(), list = names( c ), i = list.indexOf( name ), settings = {};
						if ( i > -1 ) { list.splice( i, 1 ); } else { list.push( name ); }
						settings[ classKey( c ) ] = list.join( ' ' );
						window.$e.run( 'document/elements/settings', { container: c, settings: settings, options: { external: true } } );
						return i < 0;
					}, false );
				},
				canvasDoc: function () { return safe( function () { return document.getElementById( 'elementor-preview-iframe' ).contentDocument; }, null ); },
				toolbar: function () { return document.querySelector( '#elementor-editor-wrapper-v2 header' ); },
				// Custom CSS (Elementor Pro): an Ace editor in CSS mode. ace.edit() on a node that has an editor returns it.
				editorFrom: function ( node ) {
					return safe( function () {
						var root = node.closest( '#elementor-panel .elementor-code-editor.ace_editor' );
						if ( ! root ) { return null; }
						if ( ! root.__tfCm ) {
							var ed = window.ace.edit( root );
							if ( 'ace/mode/css' !== ed.session.getMode().$id ) { return null; }
							root.__tfCm = shim( ace( ed ), 'selector' );
						}
						return root.__tfCm;
					}, null );
				}
			};
		},
		// Divi 5.15. TokenForge runs in the top window, where the settings panels are; the frame #et-vb-app-frame
		// is the canvas, and only its window has the builder's data store (seen live).
		divi: function () {
			var ATTRS = 'module.decoration.attributes', NUM = 'et-vb-field-common-numeric-input--';
			function frame() { return document.getElementById( 'et-vb-app-frame' ); }
			function data() { return frame().contentWindow.divi.data; }
			function selected() {
				return safe( function () { return data().select( 'divi/modal-library' ).getActiveModals()[ 'divi/module' ].owner || null; }, null );
			}
			// The module's HTML attributes: { desktop: { value: { attributes: [ { id, name, value, adminLabel, targetElement } ] } } }.
			function group( sid ) {
				return safe( function () { return data().select( 'divi/edit-post' ).getModuleAttrs( sid ).module.decoration.attributes; }, null ) || {};
			}
			// A plain copy: the store's arrays are immutable and their own slice() returns another immutable one.
			function entries( g ) { return Array.prototype.slice.call( ( ( g.desktop || {} ).value || {} ).attributes || [] ); }
			function isClass( a ) { return a && 'class' === a.name && 'main' === ( a.targetElement || 'main' ); }
			function split( v ) { return String( v || '' ).split( /\s+/ ).filter( Boolean ); }
			function copy( o, key, value ) {
				var out = {}, k;
				for ( k in o ) { if ( Object.prototype.hasOwnProperty.call( o, k ) ) { out[ k ] = o[ k ]; } }
				out[ key ] = value;
				return out;
			}
			// Entry ids as the builder writes them: 10 characters a-z and 0-9.
			function uid() {
				var s = '';
				while ( s.length < 10 ) { s += Math.floor( Math.random() * 36 ).toString( 36 ); }
				return s;
			}
			return {
				// Size fields (they take a variable as typed, no unit to switch).
				isControlInput: function ( el ) {
					return !! el && 'INPUT' === el.tagName && ( ! el.type || 'text' === el.type ) && 0 === String( el.id ).indexOf( NUM );
				},
				controlKey: function ( el ) { return String( ( el && el.id ) || '' ).slice( NUM.length ); },
				// Seen live: these fields write to the store on blur only; a focusout commits and the focus stays.
				commit: function ( input ) { input.dispatchEvent( new FocusEvent( 'focusout', { bubbles: true } ) ); },
				// This builder has no class field: classes are "class" entries in the Attributes list (Advanced tab).
				// Also the value box while such an entry is being edited.
				// live check: document.getElementById( 'et-vb-field-input-text-attribute-name' ) with the Edit Attribute box open
				isClassField: function ( el ) {
					if ( ! el || ! el.closest ) { return false; }
					if ( 'et-vb-field-input-text-attribute-value' === el.id ) {
						var n = document.getElementById( 'et-vb-field-input-text-attribute-name' );
						return !! n && 'class' === String( n.value ).trim().toLowerCase();
					}
					return !! el.closest( '.et-vb-field-module-decoration-attributes' );
				},
				selected: selected,
				classes: function () {
					return safe( function () {
						var out = [];
						entries( group( selected() ) ).forEach( function ( a ) { if ( isClass( a ) ) { out = out.concat( split( a.value ) ); } } );
						return out;
					}, [] );
				},
				// True when the class was added. The builder's own store action, with the whole attributes object (new
				// objects, the store's own are never changed): its Save and undo keep the change.
				toggleClass: function ( name ) {
					return safe( function () {
						var sid = selected(), list, at = -1, on = -1, i, names, was = null, now = null;
						var box = document.getElementById( 'et-vb-field-input-text-attribute-value' );
						if ( ! sid ) { return false; }
						list = entries( group( sid ) );
						for ( i = 0; i < list.length; i++ ) {
							if ( ! isClass( list[ i ] ) ) { continue; }
							if ( at < 0 ) { at = i; }
							if ( split( list[ i ].value ).indexOf( name ) > -1 ) { on = i; break; }
						}
						if ( on > -1 ) {
							names = split( list[ on ].value ).filter( function ( c ) { return c !== name; } );
							// The last class gone: the entry goes too, unless the Edit Attribute box is open on it (it stays, empty).
							if ( names.length || ( box && box.value === list[ on ].value ) ) { was = list[ on ].value; list[ on ] = copy( list[ on ], 'value', now = names.join( ' ' ) ); } else { list.splice( on, 1 ); }
						} else if ( at > -1 ) {
							was = list[ at ].value;
							list[ at ] = copy( list[ at ], 'value', now = split( list[ at ].value ).concat( [ name ] ).join( ' ' ) );
						} else {
							list.push( { id: uid(), name: 'class', value: name, adminLabel: '', targetElement: 'main' } );
						}
						// Seen live: a write of the whole group is ignored once the list exists; the list's own path always takes.
						data().dispatch( 'divi/edit-post' ).editModuleAttribute( { id: sid, attrName: ATTRS + '.desktop.value.attributes', value: list } );
						// The Edit Attribute box open on that entry keeps its own copy (seen live): give it the new value,
						// or the next keystroke there would write the old one back.
						if ( box && null !== was && was === box.value ) {
							Object.getOwnPropertyDescriptor( window.HTMLInputElement.prototype, 'value' ).set.call( box, now );
							box.dispatchEvent( new Event( 'input', { bubbles: true } ) );
						}
						return on < 0;
					}, false );
				},
				canvasDoc: function () { return safe( function () { return frame().contentDocument; }, null ); },
				toolbar: function () { return document.querySelector( '.et-vb-page-bar' ); },
				// Free-form CSS only: a real CodeMirror 5, used as it is. Its word for the element is "selector".
				editorFrom: function ( node ) {
					return safe( function () {
						var cm = node.closest( '.et-vb-field-css-free-form .CodeMirror' ).CodeMirror;
						cm.tfRoot = 'selector';
						return cm;
					}, null );
				}
			};
		}
	};

	if ( id && Object.prototype.hasOwnProperty.call( TABLE, id ) ) {
		var b = TABLE[ id ]();
		b.id = id;
		window.TokenForgeBuilder = b;
	}
} )();
