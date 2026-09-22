/**
 * WP Content Copy Protection - protection activity counter.
 *
 * Counts the actions the protection on this page really blocks and reports
 * them once, when the visitor leaves or hides the page. Pages where nothing
 * was blocked send nothing at all.
 *
 * Counted (only when the matching layer is active on this page, see
 * wccp_free_activity_tracker() in activity.php):
 *   s  selecting content  - drag-selecting, double-clicking or long-pressing text
 *   r  right-clicks       - anywhere except on images
 *   i  image interactions - right-click, drag or long-press on an image
 *   k  copy shortcuts     - the Ctrl+A/C/X/V/S/U/I combinations the plugin blocks
 *   p  print attempts     - when a print-protection message is set
 *
 * Every type is capped per page view, listeners are passive and never block
 * or change anything themselves, and nothing personal is sent: only the page
 * id and the counts.
 */
( function () {
	'use strict';

	var cfg = window.wccpActivity;

	if ( ! cfg || ! window.addEventListener || navigator.webdriver ) {
		return;
	}

	var flags = cfg.f;
	var cap = cfg.m || 5;
	var counts = { s: 0, r: 0, i: 0, k: 0, p: 0 };
	var sent = { s: 0, r: 0, i: 0, k: 0, p: 0 };
	var visitSent = false;
	var passive = { passive: true, capture: true };

	// Mirrors the keyCodes the selection layer blocks with Ctrl held.
	var KEYS = [ 65, 67, 88, 85, 86, 83, 73 ];

	function add( key ) {
		if ( counts[ key ] < cap ) {
			counts[ key ]++;
		}
	}

	// Form fields and editable areas are never blocked, so never counted.
	function editable( node ) {
		for ( ; node && node.nodeType === 1; node = node.parentElement ) {
			if ( /^(INPUT|TEXTAREA|SELECT|OPTION)$/.test( node.nodeName ) || node.isContentEditable ) {
				return true;
			}
		}
		return false;
	}

	function image( node ) {
		return !! node && node.nodeName === 'IMG';
	}

	if ( flags.r ) {
		window.addEventListener( 'contextmenu', function ( e ) {
			add( image( e.target ) ? 'i' : 'r' );
		}, passive );

		window.addEventListener( 'dragstart', function ( e ) {
			if ( image( e.target ) ) {
				add( 'i' );
			}
		}, passive );
	}

	if ( flags.s ) {
		window.addEventListener( 'keydown', function ( e ) {
			if ( e.ctrlKey && ! e.repeat && KEYS.indexOf( e.keyCode || e.which ) !== -1 && ! editable( e.target ) ) {
				add( 'k' );
			}
		}, passive );
	}

	if ( flags.s || flags.c ) {
		var down = null;

		window.addEventListener( 'mousedown', function ( e ) {
			down = null;

			if ( e.button !== 0 || editable( e.target ) || image( e.target ) ) {
				return;
			}

			// A double click is a word selection; a triple click only extends it.
			if ( e.detail === 2 ) {
				add( 's' );
				return;
			}

			down = { x: e.clientX, y: e.clientY };
		}, passive );

		window.addEventListener( 'mousemove', function ( e ) {
			if ( down && ( e.buttons & 1 ) && Math.abs( e.clientX - down.x ) + Math.abs( e.clientY - down.y ) > 16 ) {
				down = null;
				add( 's' );
			}
		}, passive );

		window.addEventListener( 'mouseup', function () {
			down = null;
		}, passive );

		// Long press: the touch equivalent of selecting or saving.
		var press = null;

		function release() {
			if ( press ) {
				clearTimeout( press );
				press = null;
			}
		}

		window.addEventListener( 'touchstart', function ( e ) {
			release();

			var target = e.target;

			if ( e.touches.length !== 1 || editable( target ) ) {
				return;
			}

			press = setTimeout( function () {
				press = null;

				if ( image( target ) ) {
					add( 'i' );
				} else if ( ! ( target.closest && target.closest( 'a' ) ) ) {
					add( 's' );
				}
			}, 650 );
		}, passive );

		window.addEventListener( 'touchmove', release, passive );
		window.addEventListener( 'touchend', release, passive );
		window.addEventListener( 'touchcancel', release, passive );
	}

	if ( flags.p ) {
		window.addEventListener( 'beforeprint', function () {
			add( 'p' );
		} );
	}

	function flush() {
		var body = 'action=wccp_free_activity&o=' + encodeURIComponent( cfg.o );
		var any = false;

		for ( var key in counts ) {
			var delta = counts[ key ] - sent[ key ];

			if ( delta > 0 ) {
				body += '&' + key + '=' + delta;
				sent[ key ] = counts[ key ];
				any = true;
			}
		}

		if ( ! any ) {
			return;
		}

		if ( ! visitSent ) {
			body += '&v=1';
			visitSent = true;
		}

		var type = 'application/x-www-form-urlencoded';

		try {
			if ( navigator.sendBeacon && navigator.sendBeacon( cfg.u, new Blob( [ body ], { type: type } ) ) ) {
				return;
			}
		} catch ( e ) {}

		if ( window.fetch ) {
			window.fetch( cfg.u, { method: 'POST', body: body, keepalive: true, credentials: 'same-origin', headers: { 'Content-Type': type } } ).catch( function () {} );
		}
	}

	document.addEventListener( 'visibilitychange', function () {
		if ( document.visibilityState === 'hidden' ) {
			flush();
		}
	} );
	window.addEventListener( 'pagehide', flush );
}() );
