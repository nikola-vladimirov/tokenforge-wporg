/* TokenForge: the Global SCSS outline, shared by the dashboard editor and the builder dock.
 *
 * Section comments are block comments: "/* # Hero *\/" is level 1, "##" level 2, "###" level 3.
 * Indentation and "//" comments are ignored; four or more "#" and an empty title are not sections.
 * Pure text in, plain data out, so a Node harness can test it (tokenforge-tools/tf-scss-outline.js).
 *
 * parse( text )              -> tree: [ { level, title, line (0-based), ch, index, children } ]
 * flatten( tree )            -> document order: [ { ...node, depth } ]
 * current( flat, index )     -> the section the character index is in (null above the first)
 * filter( flat, query )      -> items whose title holds every word of the query
 * sectionEdit( text, index ) / subsectionEdit( text, index ) / snippetEdit( text, index )
 *                            -> { from, to, insert, caret } to apply to the text, or null
 */
( function ( root ) {
	'use strict';

	function parse( text ) {
		var items = [], i = 0, n = text.length;
		while ( i < n ) {
			var c = text.charAt( i );
			if ( '"' === c || "'" === c ) { // a string ends at its quote or at the line end
				for ( i++; i < n && c !== text.charAt( i ) && '\n' !== text.charAt( i ); ) { i += ( '\\' === text.charAt( i ) && '\n' !== text.charAt( i + 1 ) ) ? 2 : 1; }
				i++;
			} else if ( '/' === c && '/' === text.charAt( i + 1 ) ) { // line comment, not a section
				i = text.indexOf( '\n', i );
				if ( i < 0 ) { break; }
			} else if ( '/' === c && '*' === text.charAt( i + 1 ) ) {
				var end = text.indexOf( '*/', i + 2 ), body = text.slice( i + 2, end < 0 ? n : end );
				var m = /^[\s*]*(#+)[ \t]*([^\n]*)/.exec( body.split( '\n' )[0] ), title = m && m[2].replace( /[\s*]+$/, '' );
				if ( m && m[1].length < 4 && title ) { items.push( { level: m[1].length, title: title, index: i, line: 0, ch: 0, children: [] } ); } // unclosed counts: it is being typed
				i = end < 0 ? n : end + 2;
			} else if ( ( 'u' === c || 'U' === c ) && 'url(' === text.substr( i, 4 ).toLowerCase() ) { // an unquoted url may hold "//"
				i += 4;
				if ( ! /^\s*["']/.test( text.substr( i, 20 ) ) ) { while ( i < n && ')' !== text.charAt( i ) && '\n' !== text.charAt( i ) ) { i++; } }
			} else {
				i++;
			}
		}
		// Line and column, one pass (items are in document order).
		var line = 0, start = 0, next = text.indexOf( '\n' );
		items.forEach( function ( it ) {
			while ( next > -1 && next < it.index ) { line++; start = next + 1; next = text.indexOf( '\n', start ); }
			it.line = line; it.ch = it.index - start;
		} );
		// Tree: a section is a child of the nearest section above it with a lower level.
		var tree = [], stack = [];
		items.forEach( function ( it ) {
			while ( stack.length && stack[ stack.length - 1 ].level >= it.level ) { stack.pop(); }
			( stack.length ? stack[ stack.length - 1 ].children : tree ).push( it );
			stack.push( it );
		} );
		return tree;
	}

	function flatten( tree, depth, out ) {
		out = out || []; depth = depth || 0;
		tree.forEach( function ( it ) {
			out.push( { level: it.level, title: it.title, index: it.index, line: it.line, ch: it.ch, depth: depth } );
			flatten( it.children, depth + 1, out );
		} );
		return out;
	}

	function current( flat, index ) {
		var found = null;
		for ( var k = 0; k < flat.length && flat[ k ].index <= index; k++ ) { found = flat[ k ]; }
		return found;
	}

	function filter( flat, query ) {
		var words = String( query || '' ).toLowerCase().split( /\s+/ ).filter( Boolean );
		return flat.filter( function ( it ) { var t = it.title.toLowerCase(); return words.every( function ( w ) { return t.indexOf( w ) > -1; } ); } );
	}

	function lineStart( text, index ) { return index > 0 ? text.lastIndexOf( '\n', index - 1 ) + 1 : 0; }
	function lineEnd( text, index ) { var e = text.indexOf( '\n', index ); return e < 0 ? text.length : e; }
	function marks( level ) { return new Array( level + 1 ).join( '#' ); }

	function isSection( text, index ) { return parse( text.slice( lineStart( text, index ), lineEnd( text, index ) ) ).length > 0; }
	// A section comment of this level on the cursor's line: a blank line is used, a section comment line gets it
	// below (so a sub-section follows its section), any other line gets it above.
	function insertAt( text, index, level ) {
		var s = lineStart( text, index ), e = lineEnd( text, index ), line = text.slice( s, e );
		var indent = /^[ \t]*/.exec( line )[0], open = indent + '/* ' + marks( level ) + ' ', blank = ! /\S/.test( line );
		if ( ! blank && isSection( text, index ) ) { return { from: e, to: e, insert: '\n' + open + ' */', caret: e + 1 + open.length }; }
		return { from: s, to: blank ? e : s, insert: open + ' */' + ( blank ? '' : '\n' ), caret: s + open.length };
	}
	function sectionEdit( text, index ) { return insertAt( text, index, 1 ); }
	// One level below the nearest section above the line, or on it (level 2 when there is none, level 3 stays 3).
	function subsectionEdit( text, index ) {
		var above = current( flatten( parse( text.slice( 0, isSection( text, index ) ? lineEnd( text, index ) : lineStart( text, index ) ) ) ), Infinity );
		return insertAt( text, index, above ? Math.min( 3, above.level + 1 ) : 2 );
	}
	// "#", "##" or "###" alone on the line before the caret, then Tab: becomes "/* # | */".
	function snippetEdit( text, index ) {
		var s = lineStart( text, index ), m = /^([ \t]*)(#{1,3})$/.exec( text.slice( s, index ) ), e = lineEnd( text, index );
		if ( ! m || /\S/.test( text.slice( index, e ) ) ) { return null; }
		var open = '/* ' + m[2] + ' ';
		return { from: index - m[2].length, to: e, insert: open + ' */', caret: index - m[2].length + open.length };
	}

	var api = { parse: parse, flatten: flatten, current: current, filter: filter, sectionEdit: sectionEdit, subsectionEdit: subsectionEdit, snippetEdit: snippetEdit };
	if ( 'undefined' !== typeof module && module.exports ) { module.exports = api; } else { root.TokenForgeScssOutline = api; }
} )( this );
