/**
 * TokenForge: text recipes in any builder.
 *
 * Type a text recipe's name with a question mark, then a space or ";" (for example
 * "?ipsum-short "), in a text field, a text area or text edited in place, and it
 * becomes the recipe's text. Works in the editor page and in same-origin canvas
 * frames. Code editors are left alone. Config: window.TokenForgeText = { text: { name: text }, editable: bool }.
 */
( function () {
	var cfg = window.TokenForgeText || {};
	var TEXT = cfg.text || {};
	if ( ! Object.keys( TEXT ).length ) {
		return;
	}
	var RE = /\?([a-z0-9-]+)(;|[ \u00a0])$/;
	var SKIP = '.CodeMirror, .cm-editor, .monaco-editor, .ace_editor, [data-tf-no-recipes]';

	function textOf( m ) {
		return m && Object.prototype.hasOwnProperty.call( TEXT, m[1] ) ? String( TEXT[ m[1] ] ) + ( ';' === m[2] ? '' : ' ' ) : null;
	}
	function isField( el ) {
		return el && ( 'TEXTAREA' === el.tagName || ( 'INPUT' === el.tagName && /^(text|search|)$/.test( el.type || '' ) ) );
	}

	function onField( t ) {
		var pos = null == t.selectionStart ? t.value.length : t.selectionStart;
		var m = RE.exec( t.value.slice( 0, pos ) ), code = textOf( m );
		if ( null === code ) {
			return;
		}
		var start = pos - m[0].length, typed = m[0];
		var win = t.ownerDocument.defaultView;
		var setter = Object.getOwnPropertyDescriptor( ( 'TEXTAREA' === t.tagName ? win.HTMLTextAreaElement : win.HTMLInputElement ).prototype, 'value' ).set;
		setTimeout( function () {
			// Worked out from the field as it is now: keys typed meanwhile stay.
			var v = t.value;
			if ( v.substr( start, typed.length ) !== typed ) {
				return;
			}
			var caret = null == t.selectionStart ? v.length : t.selectionStart;
			setter.call( t, v.slice( 0, start ) + code + v.slice( start + typed.length ) ); // the native setter, so frameworks see the change
			t.dispatchEvent( new win.Event( 'input', { bubbles: true } ) );
			t.dispatchEvent( new win.Event( 'change', { bubbles: true } ) );
			var at = caret >= start + typed.length ? caret + code.length - typed.length : start + code.length;
			try {
				t.setSelectionRange( at, at );
			} catch ( e ) {}
		}, 0 );
	}

	function onEditable( doc ) {
		var sel = doc.getSelection();
		if ( ! sel || ! sel.rangeCount || ! sel.isCollapsed ) {
			return;
		}
		var rng = sel.getRangeAt( 0 ), node = rng.startContainer, off = rng.startOffset;
		if ( ! node || 3 !== node.nodeType ) {
			return;
		}
		var m = RE.exec( node.data.slice( 0, off ) ), code = textOf( m );
		if ( null === code ) {
			return;
		}
		setTimeout( function () {
			if ( node.data.substr( off - m[0].length, m[0].length ) !== m[0] ) {
				return;
			}
			var r = doc.createRange();
			r.setStart( node, off - m[0].length );
			r.setEnd( node, off );
			sel.removeAllRanges();
			sel.addRange( r );
			// Typed in by the browser, so the builder's editor records it like a key press.
			doc.execCommand( 'insertText', false, code );
		}, 0 );
	}

	function hook( doc ) {
		if ( ! doc || doc.__tfTextRecipes ) {
			return;
		}
		doc.__tfTextRecipes = true;
		doc.addEventListener( 'input', function ( e ) {
			var t = e.target;
			// Our own input event after a swap cannot loop: the new text no longer ends with "?name ".
			if ( ! t || ! t.closest || t.closest( SKIP ) ) {
				return;
			}
			// cfg.editable is truthy or not: wp_localize_script sends false as an empty string.
			if ( isField( t ) ) {
				onField( t );
			} else if ( cfg.editable && ( t.isContentEditable || ( t.closest && t.closest( '[contenteditable]:not([contenteditable="false"])' ) ) ) ) {
				onEditable( doc );
			}
		}, true );
	}

	// The canvas and popups come and go: hook every same-origin frame when it appears.
	function scan( doc, depth ) {
		hook( doc );
		if ( depth > 2 ) {
			return;
		}
		Array.prototype.forEach.call( doc.querySelectorAll( 'iframe' ), function ( f ) {
			var d = null;
			try {
				d = f.contentDocument;
			} catch ( e ) {}
			if ( d && d.documentElement ) {
				scan( d, depth + 1 );
			}
		} );
	}
	scan( document, 0 );
	setInterval( function () { scan( document, 0 ); }, 1500 );
}() );
