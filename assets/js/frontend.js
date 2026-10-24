/**
 * SW CloudCalendar frontend (no framework, progressive enhancement):
 * - loads views with protected sources through the non-cacheable REST endpoint (fresh nonce via admin-ajax);
 * - month navigation and event details without page reload; plain links keep working without JavaScript.
 * Server HTML is inserted only from our own REST endpoints, which render with the sanitizing template engine.
 */
( function () {
	'use strict';

	const config = window.swCloudCalendar || {};
	const i18n = config.i18n || {};
	let noncePromise = null;

	function nonce() {
		if ( ! noncePromise ) {
			noncePromise = fetch( config.ajaxUrl + '?action=sw_calendar_rest_nonce', { credentials: 'same-origin', cache: 'no-store' } )
				.then( function ( response ) {
					return response.ok ? response.json() : { nonce: '' };
				} )
				.then( function ( data ) {
					return data && typeof data.nonce === 'string' ? data.nonce : '';
				} )
				.catch( function () {
					return '';
				} );
		}
		return noncePromise;
	}

	function api( path, params ) {
		const url = new URL( config.restRoot + path, window.location.href );
		Object.keys( params || {} ).forEach( function ( key ) {
			if ( params[ key ] !== undefined && params[ key ] !== null && params[ key ] !== '' ) {
				url.searchParams.set( key, String( params[ key ] ) );
			}
		} );
		return nonce().then( function ( value ) {
			const headers = { Accept: 'application/json' };
			if ( value ) {
				headers[ 'X-WP-Nonce' ] = value;
			}
			return fetch( url.toString(), { credentials: 'same-origin', headers: headers, cache: 'no-store' } ).then( function ( response ) {
				if ( ! response.ok ) {
					throw new Error( 'HTTP ' + response.status );
				}
				return response.json();
			} );
		} );
	}

	function addCss( css ) {
		Object.keys( css || {} ).forEach( function ( scope ) {
			if ( ! /^swc-[a-z0-9-]+$/.test( scope ) || document.getElementById( scope + '-css' ) ) {
				return;
			}
			const style = document.createElement( 'style' );
			style.id = scope + '-css';
			style.textContent = String( css[ scope ] );
			document.head.appendChild( style );
		} );
	}

	function fragment( html ) {
		const template = document.createElement( 'template' );
		template.innerHTML = html;
		return template.content;
	}

	function pageUrl() {
		const url = new URL( window.location.href );
		[ 'swc_event', 'swc_view', 'swc_month' ].forEach( function ( key ) {
			url.searchParams.delete( key );
		} );
		return url.toString();
	}

	function showError( element ) {
		element.removeAttribute( 'aria-busy' );
		element.textContent = '';
		const p = document.createElement( 'p' );
		p.className = 'swc-error';
		p.textContent = i18n.error || 'Error';
		element.appendChild( p );
	}

	function renderInto( element, viewId, filters ) {
		element.setAttribute( 'aria-busy', 'true' );
		return api( 'views/' + viewId + '/render', Object.assign( {}, filters, { page_url: pageUrl() } ) )
			.then( function ( data ) {
				addCss( data.css );
				const content = fragment( data.html );
				const root = content.firstElementChild;
				if ( ! root ) {
					element.remove();
					return null;
				}
				element.replaceWith( content );
				return root;
			} )
			.catch( function () {
				showError( element );
				return null;
			} );
	}

	function stateOf( calendar ) {
		try {
			return JSON.parse( calendar.getAttribute( 'data-swc-state' ) || '{}' ) || {};
		} catch ( e ) {
			return {};
		}
	}

	// Dialog for event details (native <dialog>: focus trap, ESC, inert background).
	let dialog = null;
	function detailDialog() {
		if ( dialog ) {
			return dialog;
		}
		dialog = document.createElement( 'dialog' );
		dialog.className = 'swc-dialog';
		dialog.setAttribute( 'aria-label', i18n.details || 'Details' );
		const close = document.createElement( 'button' );
		close.type = 'button';
		close.className = 'swc-dialog__close';
		close.setAttribute( 'aria-label', i18n.close || 'Close' );
		close.textContent = '×';
		close.addEventListener( 'click', function () {
			dialog.close();
		} );
		const body = document.createElement( 'div' );
		body.className = 'swc-dialog__body';
		dialog.appendChild( close );
		dialog.appendChild( body );
		dialog.addEventListener( 'click', function ( event ) {
			if ( event.target === dialog ) {
				dialog.close();
			}
		} );
		document.body.appendChild( dialog );
		return dialog;
	}

	function openDetail( link, viewId, eventId ) {
		if ( typeof HTMLDialogElement !== 'function' ) {
			return false;
		}
		api( 'events/' + eventId, { view: viewId, page_url: pageUrl() } )
			.then( function ( data ) {
				addCss( data.css );
				const box = detailDialog();
				const body = box.querySelector( '.swc-dialog__body' );
				body.textContent = '';
				body.appendChild( fragment( data.html ) );
				box.showModal();
				box.addEventListener( 'close', function restore() {
					box.removeEventListener( 'close', restore );
					link.focus();
				} );
			} )
			.catch( function () {
				window.location.href = link.href;
			} );
		return true;
	}

	document.addEventListener( 'click', function ( event ) {
		const link = event.target instanceof Element ? event.target.closest( 'a' ) : null;
		if ( ! link || event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey ) {
			return;
		}
		const calendar = link.closest( '.swc-calendar[data-swc-view]' );
		if ( ! calendar ) {
			return;
		}
		const viewId = calendar.getAttribute( 'data-swc-view' );
		if ( link.hasAttribute( 'data-swc-month' ) ) {
			event.preventDefault();
			const filters = Object.assign( stateOf( calendar ), { month: link.getAttribute( 'data-swc-month' ), view: calendar.getAttribute( 'data-swc-mode' ) } );
			renderInto( calendar, viewId, filters ).then( function ( root ) {
				if ( root ) {
					const label = root.querySelector( '.swc-nav__label' );
					if ( label ) {
						label.setAttribute( 'tabindex', '-1' );
						label.focus();
					}
				}
			} );
			return;
		}
		const item = link.closest( '[data-swc-event]' );
		if ( link.hasAttribute( 'data-swc-detail' ) && item ) {
			if ( openDetail( link, viewId, item.getAttribute( 'data-swc-event' ) ) ) {
				event.preventDefault();
			}
		}
	} );

	function init() {
		document.querySelectorAll( '.swc-calendar.swc-dynamic[data-swc-view]' ).forEach( function ( placeholder ) {
			let filters = {};
			try {
				filters = JSON.parse( placeholder.getAttribute( 'data-swc-filters' ) || '{}' ) || {};
			} catch ( e ) {
				filters = {};
			}
			const viewId = placeholder.getAttribute( 'data-swc-view' );
			const eventId = parseInt( placeholder.getAttribute( 'data-swc-event' ) || '0', 10 );
			if ( eventId > 0 ) {
				api( 'events/' + eventId, { view: viewId, page_url: pageUrl() } )
					.then( function ( data ) {
						addCss( data.css );
						placeholder.replaceWith( fragment( data.html ) );
					} )
					.catch( function () {
						renderInto( placeholder, viewId, filters );
					} );
				return;
			}
			renderInto( placeholder, viewId, filters );
		} );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );
