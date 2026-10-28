/* TokenForge: extensions of the builder's "Form submissions" screen.
   Adds a search bar and results list, a detail dialog (read/unread, status, note, edit),
   unread marks and a View button on the rows, bulk buttons, export buttons and the column
   choice. The builder's own table, search, bulk delete and export are not touched: cells
   are only reordered/hidden when the user chooses columns. Entry values are only ever put
   into the page with textContent. */
( function () {
	'use strict';

	var cfg = window.tfSubs;
	if ( ! cfg || ! window.wp || ! wp.i18n ) {
		return;
	}
	var __ = wp.i18n.__;
	var sprintf = wp.i18n.sprintf;
	var sw = cfg.switches || {};
	var form, table, rows = [], meta = {}, uid = 0;
	var ctx = { ids: [], i: 0, opener: null, entry: null, editing: false };
	var dlg, dTitle, dSub, dBody, dMsg, dPrev, dNext, dPos;
	var results = { box: null, list: null, last: null };

	// ------------------------------------------------------------------
	// Helpers
	// ------------------------------------------------------------------

	function el( tag, attrs, kids ) {
		var n = document.createElement( tag );
		Object.keys( attrs || {} ).forEach( function ( k ) {
			if ( k === 'text' ) {
				n.textContent = attrs[ k ];
			} else if ( k.indexOf( 'on' ) === 0 ) {
				n.addEventListener( k.slice( 2 ), attrs[ k ] );
			} else if ( attrs[ k ] !== false && attrs[ k ] !== null && attrs[ k ] !== undefined ) {
				n.setAttribute( k, attrs[ k ] === true ? '' : attrs[ k ] );
			}
		} );
		( kids || [] ).forEach( function ( c ) {
			if ( c ) {
				n.appendChild( typeof c === 'string' ? document.createTextNode( c ) : c );
			}
		} );
		return n;
	}

	function skin( tag, extra ) {
		return el( tag, { class: 'tf-skin tf-' + cfg.uiTheme + ( extra ? ' ' + extra : '' ) } );
	}

	function nextId( p ) {
		uid += 1;
		return 'tf-subs-' + p + '-' + uid;
	}

	function url( path, params ) {
		var qs = Object.keys( params || {} ).filter( function ( k ) {
			return params[ k ] !== '' && params[ k ] !== null && params[ k ] !== undefined;
		} ).map( function ( k ) {
			return encodeURIComponent( k ) + '=' + encodeURIComponent( params[ k ] );
		} ).join( '&' );
		var base = cfg.restUrl + path;
		return qs ? base + ( base.indexOf( '?' ) > -1 ? '&' : '?' ) + qs : base;
	}

	function api( method, path, body, params ) {
		var o = { method: method, credentials: 'same-origin', headers: { 'X-WP-Nonce': cfg.nonce } };
		if ( body ) {
			o.headers[ 'Content-Type' ] = 'application/json';
			o.body = JSON.stringify( body );
		}
		return fetch( url( path, params ), o ).then( function ( r ) {
			return r.json().catch( function () {
				return {};
			} ).then( function ( j ) {
				if ( ! r.ok ) {
					throw new Error( ( j && j.message ) || __( 'Something went wrong. Try again.', 'tokenforge' ) );
				}
				return j;
			} );
		} );
	}

	/** Only http(s) and site-relative links become <a href>. */
	function safeUrl( u ) {
		u = String( u || '' );
		return /^(https?:\/\/|\/(?!\/))/i.test( u ) ? u : '';
	}

	function statusLabel( s ) {
		return { new: __( 'New', 'tokenforge' ), done: __( 'Done', 'tokenforge' ), spam: __( 'Spam', 'tokenforge' ) }[ s ] || s;
	}

	function valText( f ) {
		if ( f.type === 'file' ) {
			return ( f.value || [] ).map( function ( x ) {
				return x.name;
			} ).join( ', ' );
		}
		return Array.isArray( f.value ) ? f.value.join( ', ' ) : String( f.value === null || f.value === undefined ? '' : f.value );
	}

	function setBadge( n ) {
		var links = document.querySelectorAll( '#adminmenu a[href*="bricks-form-submissions"]' );
		Array.prototype.forEach.call( links, function ( a ) {
			var c = a.querySelector( '.pending-count' );
			var w = a.querySelector( '.awaiting-mod' );
			if ( ! c || ! w ) {
				return;
			}
			if ( n < 1 ) {
				w.parentNode.removeChild( w );
				return;
			}
			c.textContent = String( n );
			w.className = 'awaiting-mod count-' + n;
		} );
	}

	function applyMeta( resp ) {
		Object.keys( resp.meta || {} ).forEach( function ( id ) {
			meta[ id ] = resp.meta[ id ];
			if ( results.last ) {
				results.last.items.forEach( function ( it ) {
					if ( String( it.id ) === String( id ) ) {
						it.meta = resp.meta[ id ];
					}
				} );
			}
			if ( ctx.entry && String( ctx.entry.id ) === String( id ) ) {
				ctx.entry.meta = resp.meta[ id ];
			}
		} );
		if ( typeof resp.unread === 'number' ) {
			setBadge( resp.unread );
		}
		rows.forEach( paintRow );
		renderResults();
	}

	// ------------------------------------------------------------------
	// Rows of the builder's table: unread mark, status chip, View button
	// ------------------------------------------------------------------

	function colKey( c ) {
		for ( var i = 0; i < c.classList.length; i++ ) {
			var n = c.classList[ i ];
			if ( n.indexOf( 'column-' ) === 0 && n !== 'column-primary' ) {
				return n.slice( 7 );
			}
		}
		return '';
	}

	function collectRows() {
		rows = [];
		Array.prototype.forEach.call( form.querySelectorAll( 'tbody tr' ), function ( tr ) {
			var cb = tr.querySelector( 'input[name="row_id[]"]' );
			if ( cb ) {
				rows.push( { id: parseInt( cb.value, 10 ), tr: tr, cb: cb } );
			}
		} );
	}

	function decorateRows() {
		rows.forEach( function ( r ) {
			var host = r.tr.querySelector( 'td.column-id' ) || r.tr.querySelector( 'td' );
			if ( ! host ) {
				return;
			}
			var tools = skin( 'span', 'tf-subs-tools' );
			var view = el( 'button', { type: 'button', class: 'tf-subs-link', text: __( 'View', 'tokenforge' ), 'aria-label': sprintf( /* translators: %d: entry number */ __( 'View entry %d', 'tokenforge' ), r.id ) } );
			view.addEventListener( 'click', function () {
				openEntry( rows.map( function ( x ) {
					return x.id;
				} ), rows.indexOf( r ), view );
			} );
			tools.appendChild( view );
			if ( sw.status ) {
				r.dot = el( 'span', { class: 'tf-subs-dot', hidden: true }, [ el( 'span', { class: 'tf-subs-sr', text: __( 'Unread', 'tokenforge' ) } ) ] );
				r.chip = el( 'span', { class: 'tf-subs-chip', hidden: true } );
				tools.appendChild( r.dot );
				tools.appendChild( r.chip );
			}
			host.appendChild( tools );
		} );
	}

	function paintRow( r ) {
		var m = meta[ r.id ];
		if ( ! sw.status || ! m || ! r.dot ) {
			return;
		}
		r.tr.classList.toggle( 'tf-subs-unread', ! m.read );
		r.dot.hidden = !! m.read;
		r.chip.hidden = m.status === 'new';
		r.chip.textContent = statusLabel( m.status );
		r.chip.setAttribute( 'data-status', m.status );
	}

	function loadRowMeta() {
		if ( ! sw.status || ! rows.length ) {
			return;
		}
		for ( var i = 0; i < rows.length; i += 100 ) {
			( function ( chunk ) {
				api( 'GET', '/submissions/meta', null, { ids: chunk.join( ',' ) } ).then( function ( m ) {
					Object.keys( m ).forEach( function ( id ) {
						meta[ id ] = m[ id ];
					} );
					rows.forEach( paintRow );
				} ).catch( function () {} );
			} )( rows.slice( i, i + 100 ).map( function ( r ) {
				return r.id;
			} ) );
		}
	}

	// ------------------------------------------------------------------
	// Detail dialog
	// ------------------------------------------------------------------

	function ensureDialog() {
		if ( dlg ) {
			return;
		}
		var tid = nextId( 'title' );
		dTitle = el( 'h2', { id: tid, class: 'tf-subs-dtitle' } );
		dSub = el( 'p', { class: 'tf-subs-dsub' } );
		dBody = el( 'div', { class: 'tf-subs-dbody' } );
		dMsg = el( 'p', { class: 'tf-subs-msg', role: 'status' } );
		dPrev = el( 'button', { type: 'button', class: 'tf-subs-btn', text: __( 'Previous', 'tokenforge' ), onclick: function () {
			step( -1 );
		} } );
		dNext = el( 'button', { type: 'button', class: 'tf-subs-btn', text: __( 'Next', 'tokenforge' ), onclick: function () {
			step( 1 );
		} } );
		dPos = el( 'span', { class: 'tf-subs-pos' } );
		var close = el( 'button', { type: 'button', class: 'tf-subs-x', 'aria-label': __( 'Close', 'tokenforge' ), text: '×', onclick: function () {
			dlg.close();
		} } );
		dlg = el( 'dialog', { class: 'tf-subs-dialog', 'aria-labelledby': tid }, [
			el( 'div', { class: 'tf-subs-dhead' }, [ el( 'div', {}, [ dTitle, dSub ] ), close ] ),
			dBody,
			dMsg,
			el( 'div', { class: 'tf-subs-dfoot' }, [ dPrev, dPos, dNext ] )
		] );
		dlg.addEventListener( 'close', function () {
			if ( ctx.opener && document.body.contains( ctx.opener ) ) {
				ctx.opener.focus();
			}
		} );
		dlg.addEventListener( 'click', function ( e ) {
			if ( e.target === dlg ) {
				dlg.close();
			}
		} );
		var wrap = skin( 'div', 'tf-subs-dwrap' );
		wrap.appendChild( dlg );
		document.body.appendChild( wrap );
	}

	function openEntry( ids, i, opener ) {
		ensureDialog();
		ctx.ids = ids;
		ctx.i = i;
		ctx.opener = opener || ctx.opener;
		if ( ! dlg.open ) {
			dlg.showModal();
		}
		loadEntry();
	}

	function step( d ) {
		var n = ctx.i + d;
		if ( n >= 0 && n < ctx.ids.length ) {
			ctx.i = n;
			loadEntry();
		}
	}

	/** Put focus back inside the dialog after the body was rebuilt. */
	function focusIn( sel ) {
		var n = dBody.querySelector( sel );
		if ( n ) {
			n.focus();
		}
	}

	function say( text, err ) {
		dMsg.textContent = text || '';
		dMsg.setAttribute( 'role', err ? 'alert' : 'status' );
		dMsg.classList.toggle( 'is-err', !! err );
	}

	function loadEntry() {
		var id = ctx.ids[ ctx.i ];
		ctx.editing = false;
		ctx.entry = null;
		dTitle.textContent = sprintf( /* translators: %d: entry number */ __( 'Entry #%d', 'tokenforge' ), id );
		dSub.textContent = '';
		dBody.textContent = __( 'Loading…', 'tokenforge' );
		say( '' );
		dPrev.disabled = ctx.i < 1;
		dNext.disabled = ctx.i >= ctx.ids.length - 1;
		dPos.textContent = ctx.ids.length > 1 ? sprintf( /* translators: 1: position, 2: total */ __( '%1$d of %2$d', 'tokenforge' ), ctx.i + 1, ctx.ids.length ) : '';
		api( 'GET', '/submissions/' + id ).then( function ( e ) {
			if ( ctx.ids[ ctx.i ] !== id ) {
				return;
			}
			ctx.entry = e;
			meta[ id ] = e.meta;
			render();
			if ( sw.status && ! e.meta.read ) {
				api( 'POST', '/submissions/meta', { ids: [ id ], read: true } ).then( function ( r ) {
					applyMeta( r );
					if ( ctx.entry && ctx.entry.id === id && ! ctx.editing ) {
						render();
					}
				} ).catch( function () {} );
			}
		} ).catch( function ( err ) {
			dBody.textContent = '';
			say( err.message, true );
		} );
	}

	function dl( pairs ) {
		var d = el( 'dl', { class: 'tf-subs-dl' } );
		pairs.forEach( function ( p ) {
			d.appendChild( el( 'dt', { text: p[ 0 ] } ) );
			var dd = el( 'dd' );
			if ( p[ 1 ] instanceof Node ) {
				dd.appendChild( p[ 1 ] );
			} else if ( p[ 1 ] === '' || p[ 1 ] === null || p[ 1 ] === undefined ) {
				dd.appendChild( el( 'span', { class: 'tf-subs-empty', text: __( '(empty)', 'tokenforge' ) } ) );
			} else {
				dd.textContent = String( p[ 1 ] );
			}
			d.appendChild( dd );
		} );
		return d;
	}

	function fieldValueNode( f ) {
		if ( f.type === 'file' ) {
			var box = el( 'span' );
			( f.value || [] ).forEach( function ( x, i ) {
				if ( i ) {
					box.appendChild( document.createTextNode( ', ' ) );
				}
				var href = safeUrl( x.url );
				box.appendChild( href ? el( 'a', { href: href, target: '_blank', rel: 'noopener noreferrer', text: x.name } ) : document.createTextNode( x.name ) );
			} );
			return ( f.value || [] ).length ? box : '';
		}
		if ( f.type === 'password' ) {
			return el( 'span', { class: 'tf-subs-empty', text: __( '(hidden)', 'tokenforge' ) } );
		}
		return valText( f );
	}

	function link( href, text ) {
		var h = safeUrl( href );
		return h ? el( 'a', { href: h, target: '_blank', rel: 'noopener noreferrer', text: text || href } ) : ( text || href );
	}

	function render() {
		var e = ctx.entry;
		if ( ! e ) {
			return;
		}
		dTitle.textContent = sprintf( /* translators: %d: entry number */ __( 'Entry #%d', 'tokenforge' ), e.id );
		dSub.textContent = [ e.form_name, e.created ].filter( Boolean ).join( ' · ' );
		dBody.textContent = '';
		if ( ctx.editing ) {
			renderEdit( e );
			return;
		}
		dBody.appendChild( el( 'h3', { class: 'tf-subs-h', text: __( 'Entry', 'tokenforge' ) } ) );
		dBody.appendChild( dl( e.fields.map( function ( f ) {
			return [ f.label || f.id, fieldValueNode( f ) ];
		} ) ) );
		dBody.appendChild( el( 'h3', { class: 'tf-subs-h', text: __( 'Details', 'tokenforge' ) } ) );
		dBody.appendChild( dl( [
			[ __( 'Date', 'tokenforge' ), e.created ],
			[ __( 'Page', 'tokenforge' ), e.post_title ? e.post_title + ' (#' + e.post_id + ')' : ( e.post_id ? '#' + e.post_id : '' ) ],
			[ __( 'User', 'tokenforge' ), e.user || ( e.user_id ? '#' + e.user_id : __( 'Guest', 'tokenforge' ) ) ],
			[ __( 'Browser', 'tokenforge' ), e.browser ],
			[ __( 'System', 'tokenforge' ), e.os ],
			[ __( 'Referrer', 'tokenforge' ), e.referrer ? link( e.referrer ) : '' ],
			[ __( 'IP address', 'tokenforge' ), e.ip ]
		] ) );
		if ( sw.status ) {
			dBody.appendChild( statusBlock( e ) );
		}
		if ( sw.edit ) {
			dBody.appendChild( el( 'div', { class: 'tf-subs-actions' }, [ el( 'button', { type: 'button', class: 'tf-subs-btn tf-subs-editbtn', text: __( 'Edit entry', 'tokenforge' ), onclick: function () {
				ctx.editing = true;
				say( '' );
				render();
				focusIn( 'input, textarea' );
			} } ) ] ) );
		}
	}

	function statusBlock( e ) {
		var sid = nextId( 'status' );
		var nid = nextId( 'note' );
		var sel = el( 'select', { id: sid } );
		[ 'new', 'done', 'spam' ].forEach( function ( s ) {
			sel.appendChild( el( 'option', { value: s, text: statusLabel( s ), selected: e.meta.status === s } ) );
		} );
		var note = el( 'textarea', { id: nid, rows: '3', maxlength: '5000' } );
		note.value = e.meta.note || '';
		var read = el( 'button', { type: 'button', class: 'tf-subs-btn tf-subs-readbtn', text: e.meta.read ? __( 'Mark as unread', 'tokenforge' ) : __( 'Mark as read', 'tokenforge' ), onclick: function () {
			api( 'POST', '/submissions/meta', { ids: [ e.id ], read: ! e.meta.read } ).then( function ( r ) {
				applyMeta( r );
				render();
				focusIn( '.tf-subs-readbtn' );
				say( __( 'Saved.', 'tokenforge' ) );
			} ).catch( function ( err ) {
				say( err.message, true );
			} );
		} } );
		var save = el( 'button', { type: 'button', class: 'tf-subs-btn is-primary', text: __( 'Save status and note', 'tokenforge' ), onclick: function () {
			api( 'POST', '/submissions/meta', { ids: [ e.id ], status: sel.value, note: note.value } ).then( function ( r ) {
				applyMeta( r );
				say( __( 'Saved.', 'tokenforge' ) );
			} ).catch( function ( err ) {
				say( err.message, true );
			} );
		} } );
		return el( 'div', { class: 'tf-subs-handle' }, [
			el( 'h3', { class: 'tf-subs-h', text: __( 'Handling', 'tokenforge' ) } ),
			el( 'div', { class: 'tf-subs-field' }, [ el( 'label', { for: sid, text: __( 'Status', 'tokenforge' ) } ), sel ] ),
			el( 'div', { class: 'tf-subs-field' }, [ el( 'label', { for: nid, text: __( 'Note', 'tokenforge' ) } ), note ] ),
			el( 'div', { class: 'tf-subs-actions' }, [ save, read ] )
		] );
	}

	function renderEdit( e ) {
		var inputs = [];
		var list = el( 'div', { class: 'tf-subs-editlist' } );
		var map = { email: 'email', tel: 'tel', url: 'url', number: 'number' };
		e.fields.forEach( function ( f ) {
			var label = f.label || f.id;
			if ( f.type === 'file' || f.type === 'password' ) {
				list.appendChild( el( 'div', { class: 'tf-subs-field' }, [ el( 'span', { class: 'tf-subs-fl', text: label } ), el( 'div', {}, [ f.type === 'file' ? fieldValueNode( f ) || el( 'span', { class: 'tf-subs-empty', text: __( '(empty)', 'tokenforge' ) } ) : fieldValueNode( f ), el( 'small', { class: 'tf-subs-hint', text: ' ' + __( '(read only)', 'tokenforge' ) } ) ] ) ] ) );
				return;
			}
			var vals = Array.isArray( f.value ) ? f.value : null;
			( vals && vals.length ? vals : vals ? [ '' ] : [ f.value ] ).forEach( function ( v, i ) { // an empty list still gets one input
				var id = nextId( 'f' );
				var inp;
				if ( f.type === 'textarea' ) {
					inp = el( 'textarea', { id: id, rows: '4' } );
				} else {
					inp = el( 'input', { id: id, type: map[ f.type ] || 'text' } );
					if ( inp.type === 'number' ) {
						inp.step = 'any';
					}
				}
				inp.value = v === null || v === undefined ? '' : String( v );
				inputs.push( { f: f, inp: inp, multi: !! vals } );
				var text = vals && vals.length > 1 ? sprintf( /* translators: 1: field label, 2: position */ __( '%1$s (%2$d)', 'tokenforge' ), label, i + 1 ) : label;
				list.appendChild( el( 'div', { class: 'tf-subs-field' }, [ el( 'label', { for: id, text: text } ), inp ] ) );
			} );
		} );
		var save = el( 'button', { type: 'button', class: 'tf-subs-btn is-primary', text: __( 'Save changes', 'tokenforge' ), onclick: function () {
			var values = {};
			inputs.forEach( function ( x ) {
				if ( x.multi ) {
					values[ x.f.id ] = values[ x.f.id ] || [];
					if ( x.inp.value !== '' ) {
						values[ x.f.id ].push( x.inp.value );
					}
				} else {
					values[ x.f.id ] = x.inp.value;
				}
			} );
			save.disabled = true;
			api( 'POST', '/submissions/' + e.id, { values: values } ).then( function ( ne ) {
				ctx.entry = ne;
				ctx.editing = false;
				meta[ ne.id ] = ne.meta;
				render();
				focusIn( '.tf-subs-editbtn' );
				say( __( 'Saved. Reload the page to see the new values in the table.', 'tokenforge' ) );
			} ).catch( function ( err ) {
				save.disabled = false;
				say( err.message, true );
			} );
		} } );
		var cancel = el( 'button', { type: 'button', class: 'tf-subs-btn', text: __( 'Cancel', 'tokenforge' ), onclick: function () {
			ctx.editing = false;
			say( '' );
			render();
			focusIn( '.tf-subs-editbtn' );
		} } );
		dBody.appendChild( el( 'h3', { class: 'tf-subs-h', text: __( 'Edit entry', 'tokenforge' ) } ) );
		dBody.appendChild( list );
		dBody.appendChild( el( 'div', { class: 'tf-subs-actions' }, [ save, cancel ] ) );
	}

	// ------------------------------------------------------------------
	// Search bar and results
	// ------------------------------------------------------------------

	var q = { s: '', status: '', read: '', page: 1 };
	var colsBtn = null, colsPanel = null;

	function buildSearch( anchor ) {
		var wrap = skin( 'div', 'tf-subs-search' );
		var sid = nextId( 's' );
		var input = el( 'input', { id: sid, type: 'search', class: 'tf-subs-input' } );
		var fields = [ el( 'div', { class: 'tf-subs-field' }, [ el( 'label', { for: sid, text: __( 'Search entries', 'tokenforge' ) } ), input ] ) ];
		var selStatus, selRead;
		if ( sw.status ) {
			var a = nextId( 'st' );
			var b = nextId( 'rd' );
			selStatus = el( 'select', { id: a }, [
				el( 'option', { value: '', text: __( 'Any status', 'tokenforge' ) } ),
				el( 'option', { value: 'new', text: statusLabel( 'new' ) } ),
				el( 'option', { value: 'done', text: statusLabel( 'done' ) } ),
				el( 'option', { value: 'spam', text: statusLabel( 'spam' ) } )
			] );
			selRead = el( 'select', { id: b }, [
				el( 'option', { value: '', text: __( 'Read or unread', 'tokenforge' ) } ),
				el( 'option', { value: '0', text: __( 'Unread', 'tokenforge' ) } ),
				el( 'option', { value: '1', text: __( 'Read', 'tokenforge' ) } )
			] );
			fields.push( el( 'div', { class: 'tf-subs-field' }, [ el( 'label', { for: a, text: __( 'Status', 'tokenforge' ) } ), selStatus ] ) );
			fields.push( el( 'div', { class: 'tf-subs-field' }, [ el( 'label', { for: b, text: __( 'Read', 'tokenforge' ) } ), selRead ] ) );
		}
		var go = el( 'button', { type: 'submit', class: 'tf-subs-btn is-primary', text: __( 'Search', 'tokenforge' ) } );
		var clear = el( 'button', { type: 'button', class: 'tf-subs-btn', text: __( 'Clear', 'tokenforge' ), hidden: true } );
		var f = el( 'form', { class: 'tf-subs-searchform', role: 'search', 'aria-label': __( 'Search all entries', 'tokenforge' ) }, fields.concat( [ el( 'div', { class: 'tf-subs-actions' }, [ go, clear ] ) ] ) );
		f.addEventListener( 'submit', function ( ev ) {
			ev.preventDefault();
			q = { s: input.value.trim(), status: selStatus ? selStatus.value : '', read: selRead ? selRead.value : '', page: 1 };
			clear.hidden = false;
			runSearch();
		} );
		clear.addEventListener( 'click', function () {
			input.value = '';
			if ( selStatus ) {
				selStatus.value = '';
				selRead.value = '';
			}
			clear.hidden = true;
			results.last = null;
			results.box.hidden = true;
			results.list.textContent = '';
			input.focus();
		} );
		wrap.appendChild( el( 'p', { class: 'tf-subs-scope', text: cfg.formId ? __( 'Searches this form. The builder’s own search box below works as before.', 'tokenforge' ) : __( 'Searches the entries of all forms.', 'tokenforge' ) } ) );
		wrap.appendChild( f );
		results.box = el( 'div', { class: 'tf-subs-results', hidden: true, 'aria-live': 'polite' } );
		results.list = results.box;
		wrap.appendChild( results.box );
		anchor.parentNode.insertBefore( wrap, anchor );
		return { wrap: wrap, actions: f.querySelector( '.tf-subs-actions' ) };
	}

	function runSearch() {
		results.box.hidden = false;
		results.box.textContent = __( 'Searching…', 'tokenforge' );
		api( 'GET', '/submissions', null, { form_id: cfg.formId, s: q.s, status: q.status, read: q.read, page: q.page, per_page: 20 } ).then( function ( r ) {
			results.last = r;
			renderResults();
		} ).catch( function ( err ) {
			results.box.textContent = err.message;
		} );
	}

	function renderResults() {
		var r = results.last;
		if ( ! r || ! results.box ) {
			return;
		}
		var box = results.box;
		var oid = ctx.opener && ctx.opener.dataset ? ctx.opener.dataset.id : ''; // the rebuilt list replaces the button that opened the dialog
		box.textContent = '';
		box.hidden = false;
		var pages = Math.max( 1, Math.ceil( r.total / r.per_page ) );
		box.appendChild( el( 'h2', { class: 'tf-subs-rh', text: sprintf( /* translators: %d: number of entries */ wp.i18n._n( '%d entry found', '%d entries found', r.total, 'tokenforge' ), r.total ) } ) );
		var ul = el( 'ul', { class: 'tf-subs-hits' } );
		var ids = r.items.map( function ( x ) {
			return x.id;
		} );
		r.items.forEach( function ( it, i ) {
			var m = it.meta || {};
			var summary = it.fields.filter( function ( f ) {
				return f.type !== 'password';
			} ).slice( 0, 3 ).map( function ( f ) {
				return ( f.label || f.id ) + ': ' + valText( f );
			} ).join( ' · ' );
			var btn = el( 'button', { type: 'button', class: 'tf-subs-hit' + ( sw.status && ! m.read ? ' is-unread' : '' ) }, [
				el( 'span', { class: 'tf-subs-hitid', text: '#' + it.id } ),
				sw.status && ! m.read ? el( 'span', { class: 'tf-subs-dot' }, [ el( 'span', { class: 'tf-subs-sr', text: __( 'Unread', 'tokenforge' ) } ) ] ) : null,
				el( 'span', { class: 'tf-subs-hitmeta', text: [ it.form_name, it.created ].filter( Boolean ).join( ' · ' ) } ),
				el( 'span', { class: 'tf-subs-hitsum', text: summary } ),
				sw.status && m.status && m.status !== 'new' ? el( 'span', { class: 'tf-subs-chip', 'data-status': m.status, text: statusLabel( m.status ) } ) : null
			] );
			btn.dataset.id = it.id;
			if ( oid && String( it.id ) === oid ) {
				ctx.opener = btn;
			}
			btn.addEventListener( 'click', function () {
				openEntry( ids, i, btn );
			} );
			ul.appendChild( el( 'li', {}, [ btn ] ) );
		} );
		box.appendChild( ul );
		if ( pages > 1 ) {
			box.appendChild( el( 'div', { class: 'tf-subs-paging' }, [
				el( 'button', { type: 'button', class: 'tf-subs-btn', text: __( 'Previous page', 'tokenforge' ), disabled: r.page <= 1, onclick: function () {
					q.page = r.page - 1;
					runSearch();
				} } ),
				el( 'span', { text: sprintf( /* translators: 1: page, 2: pages */ __( 'Page %1$d of %2$d', 'tokenforge' ), r.page, pages ) } ),
				el( 'button', { type: 'button', class: 'tf-subs-btn', text: __( 'Next page', 'tokenforge' ), disabled: r.page >= pages, onclick: function () {
					q.page = r.page + 1;
					runSearch();
				} } )
			] ) );
		}
	}

	// ------------------------------------------------------------------
	// Bulk buttons (entries view)
	// ------------------------------------------------------------------

	function ticked() {
		return rows.filter( function ( r ) {
			return r.cb.checked;
		} ).map( function ( r ) {
			return r.id;
		} );
	}

	function buildBulk() {
		var bar = skin( 'div', 'alignleft tf-subs-bulk' );
		bar.setAttribute( 'role', 'group' );
		bar.setAttribute( 'aria-label', __( 'More actions for the ticked entries', 'tokenforge' ) );
		var note = el( 'span', { class: 'tf-subs-bulknote', role: 'status' } );
		function need( fn ) {
			var ids = ticked();
			if ( ! ids.length ) {
				note.textContent = __( 'Tick at least one entry first.', 'tokenforge' );
				return;
			}
			note.textContent = '';
			fn( ids );
		}
		function setMeta( fields ) {
			need( function ( ids ) {
				api( 'POST', '/submissions/meta', Object.assign( { ids: ids }, fields ) ).then( function ( r ) {
					applyMeta( r );
					note.textContent = __( 'Saved.', 'tokenforge' );
				} ).catch( function ( err ) {
					note.textContent = err.message;
				} );
			} );
		}
		function exportAs( format ) {
			need( function ( ids ) {
				api( 'POST', '/submissions/export', { ids: ids, format: format } ).then( function ( r ) {
					var blob = new Blob( [ r.content ], { type: r.mime || 'text/plain' } );
					var a = el( 'a', { href: URL.createObjectURL( blob ), download: r.filename || 'entries.' + format } );
					document.body.appendChild( a );
					a.click();
					document.body.removeChild( a );
					setTimeout( function () {
						URL.revokeObjectURL( a.href );
					}, 2000 );
					note.textContent = '';
				} ).catch( function ( err ) {
					note.textContent = err.message;
				} );
			} );
		}
		function btn( text, fn ) {
			return el( 'button', { type: 'button', class: 'tf-subs-btn', text: text, onclick: fn } );
		}
		if ( sw.status ) {
			bar.appendChild( btn( __( 'Mark read', 'tokenforge' ), function () {
				setMeta( { read: true } );
			} ) );
			bar.appendChild( btn( __( 'Mark unread', 'tokenforge' ), function () {
				setMeta( { read: false } );
			} ) );
			var sid = nextId( 'bs' );
			var sel = el( 'select', { id: sid }, [
				el( 'option', { value: 'new', text: statusLabel( 'new' ) } ),
				el( 'option', { value: 'done', text: statusLabel( 'done' ) } ),
				el( 'option', { value: 'spam', text: statusLabel( 'spam' ) } )
			] );
			bar.appendChild( el( 'label', { for: sid, class: 'tf-subs-sr', text: __( 'Status for the ticked entries', 'tokenforge' ) } ) );
			bar.appendChild( sel );
			bar.appendChild( btn( __( 'Set status', 'tokenforge' ), function () {
				setMeta( { status: sel.value } );
			} ) );
		}
		if ( sw.export ) {
			bar.appendChild( btn( __( 'Export CSV', 'tokenforge' ), function () {
				exportAs( 'csv' );
			} ) );
			bar.appendChild( btn( __( 'Export JSON', 'tokenforge' ), function () {
				exportAs( 'json' );
			} ) );
		}
		bar.appendChild( note );
		var nav = document.querySelector( '.tablenav.top' );
		var ba = nav && nav.querySelector( '.bulkactions' );
		if ( ba ) {
			ba.parentNode.insertBefore( bar, ba.nextSibling );
		} else if ( nav ) {
			nav.insertBefore( bar, nav.firstChild );
		} else {
			table.parentNode.insertBefore( bar, table );
		}
	}

	// ------------------------------------------------------------------
	// Columns: choose, hide and reorder the builder's table columns
	// ------------------------------------------------------------------

	var cols = [], defaults = [], saveTimer = 0;

	function readColumns() {
		var head = table.querySelector( 'thead tr' );
		var found = [];
		if ( head ) {
			Array.prototype.forEach.call( head.children, function ( th ) {
				var key = colKey( th );
				if ( key && ! th.classList.contains( 'check-column' ) ) {
					found.push( { key: key, label: th.textContent.replace( /\s+/g, ' ' ).trim() || key, visible: true } );
				}
			} );
		}
		defaults = found.map( function ( c ) {
			return c.key;
		} );
		var saved = ( cfg.columns || [] ).map( String );
		var out = [];
		saved.forEach( function ( s ) {
			var hide = s.charAt( 0 ) === '-';
			var k = ( hide ? s.slice( 1 ) : s ).toLowerCase();
			found.forEach( function ( c, i ) {
				if ( c && c.key.toLowerCase() === k ) {
					c.visible = ! hide;
					out.push( c );
					found[ i ] = null;
				}
			} );
		} );
		cols = out.concat( found.filter( Boolean ) );
	}

	function applyColumns() {
		var pos = {};
		cols.forEach( function ( c, i ) {
			pos[ c.key ] = i;
		} );
		Array.prototype.forEach.call( table.querySelectorAll( 'thead tr, tfoot tr, tbody tr' ), function ( tr ) {
			if ( tr.querySelector( 'td[colspan]' ) ) {
				return; // the builder's "no entries" row
			}
			var cells = Array.prototype.slice.call( tr.children );
			var keyed = cells.filter( function ( c ) {
				return ! c.classList.contains( 'check-column' ) && pos[ colKey( c ) ] !== undefined;
			} );
			keyed.sort( function ( a, b ) {
				return pos[ colKey( a ) ] - pos[ colKey( b ) ];
			} );
			keyed.forEach( function ( c ) {
				tr.appendChild( c );
				c.classList.toggle( 'tf-subs-off', ! cols[ pos[ colKey( c ) ] ].visible );
			} );
		} );
	}

	function saveColumns( reset ) {
		clearTimeout( saveTimer );
		saveTimer = setTimeout( function () {
			var body = new URLSearchParams();
			body.set( 'action', cfg.colsAction );
			body.set( 'nonce', cfg.colsNonce );
			body.set( 'form_id', cfg.formId );
			body.set( 'columns', JSON.stringify( reset ? [] : cols.map( function ( c ) {
				return ( c.visible ? '' : '-' ) + c.key;
			} ) ) );
			fetch( cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } ).then( function ( r ) {
				return r.json();
			} ).then( function ( j ) {
				colsPanel.querySelector( '.tf-subs-colmsg' ).textContent = j && j.success ? __( 'Saved for you.', 'tokenforge' ) : __( 'Could not save the column choice.', 'tokenforge' );
			} ).catch( function () {
				colsPanel.querySelector( '.tf-subs-colmsg' ).textContent = __( 'Could not save the column choice.', 'tokenforge' );
			} );
		}, 400 );
	}

	function renderCols( focus ) {
		var ul = colsPanel.querySelector( '.tf-subs-collist' );
		ul.textContent = '';
		cols.forEach( function ( c, i ) {
			var cid = nextId( 'c' );
			var keep = c.key === 'id'; // the ID cell carries the View button
			var cb = el( 'input', { type: 'checkbox', id: cid, checked: c.visible, disabled: keep } );
			cb.addEventListener( 'change', function () {
				c.visible = cb.checked;
				applyColumns();
				saveColumns();
			} );
			function mv( d ) {
				var j = i + d;
				cols.splice( j, 0, cols.splice( i, 1 )[ 0 ] );
				applyColumns();
				saveColumns();
				renderCols( { key: c.key, dir: d } );
			}
			var up = el( 'button', { type: 'button', class: 'tf-subs-btn', 'data-dir': '-1', 'aria-label': sprintf( /* translators: %s: column name */ __( 'Move %s up', 'tokenforge' ), c.label ), text: '↑', disabled: i === 0, onclick: function () {
				mv( -1 );
			} } );
			var down = el( 'button', { type: 'button', class: 'tf-subs-btn', 'data-dir': '1', 'aria-label': sprintf( /* translators: %s: column name */ __( 'Move %s down', 'tokenforge' ), c.label ), text: '↓', disabled: i === cols.length - 1, onclick: function () {
				mv( 1 );
			} } );
			var li = el( 'li', { 'data-key': c.key }, [ cb, el( 'label', { for: cid, text: c.label } ), up, down ] );
			ul.appendChild( li );
		} );
		if ( focus ) {
			var li = ul.querySelector( '[data-key="' + ( window.CSS && CSS.escape ? CSS.escape( focus.key ) : focus.key ) + '"]' );
			var b = li && ( li.querySelector( '[data-dir="' + focus.dir + '"]:not(:disabled)' ) || li.querySelector( 'button:not(:disabled)' ) );
			if ( b ) {
				b.focus();
			}
		}
	}

	function buildColumns( host ) {
		if ( ! cols.length ) {
			return;
		}
		var pid = nextId( 'colpanel' );
		colsBtn = el( 'button', { type: 'button', class: 'tf-subs-btn', 'aria-expanded': 'false', 'aria-controls': pid, text: __( 'Columns', 'tokenforge' ) } );
		colsPanel = el( 'div', { id: pid, class: 'tf-subs-colpanel', hidden: true }, [
			el( 'p', { class: 'tf-subs-hint', text: __( 'Tick the columns to show and use the arrows to change their order. Saved for you, per form.', 'tokenforge' ) } ),
			el( 'ul', { class: 'tf-subs-collist' } ),
			el( 'div', { class: 'tf-subs-actions' }, [ el( 'button', { type: 'button', class: 'tf-subs-btn', text: __( 'Reset columns', 'tokenforge' ), onclick: function () {
				var order = {};
				defaults.forEach( function ( k, i ) {
					order[ k ] = i;
				} );
				cols.sort( function ( a, b ) {
					return order[ a.key ] - order[ b.key ];
				} );
				cols.forEach( function ( c ) {
					c.visible = true;
				} );
				applyColumns();
				renderCols();
				saveColumns( true );
			} } ), el( 'span', { class: 'tf-subs-colmsg', role: 'status' } ) ] )
		] );
		colsBtn.addEventListener( 'click', function () {
			var open = colsPanel.hidden;
			colsPanel.hidden = ! open;
			colsBtn.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
			if ( open ) {
				renderCols();
			}
		} );
		host.actions.appendChild( colsBtn );
		host.wrap.insertBefore( colsPanel, results.box );
		if ( ( cfg.columns || [] ).length ) {
			applyColumns();
		}
	}

	// ------------------------------------------------------------------
	// Start
	// ------------------------------------------------------------------

	function init() {
		form = document.getElementById( 'bricks-form-submissions' );
		if ( ! form ) {
			return;
		}
		table = form.querySelector( 'table.wp-list-table' );
		// The search bar sits outside the builder's form, so Enter in it never submits the builder's form.
		var host = buildSearch( form );
		if ( ! table ) {
			return;
		}
		collectRows();
		if ( ! rows.length ) {
			return; // overview, or no entries: the search bar is all there is
		}
		decorateRows();
		loadRowMeta();
		if ( sw.status || sw.export ) {
			buildBulk();
		}
		if ( sw.columns && cfg.formId ) {
			readColumns();
			buildColumns( host );
		}
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );
