/**
 * WP Content Copy Protection - admin panel behaviour.
 *
 * Replaces the SimpleTabs plugin. The tab strip is now a rail of .wpb-pill
 * buttons (the shop's category pills) driving ARIA tab panels, so the whole
 * thing is keyboard-operable and needs no jQuery.
 */
( function () {
	'use strict';

	var STORE_KEY = 'wccp_active_panel';

	function ready( fn ) {
		if ( document.readyState !== 'loading' ) {
			fn();
		} else {
			document.addEventListener( 'DOMContentLoaded', fn );
		}
	}

	ready( function () {
		var root = document.getElementById( 'wpb-admin' );

		if ( ! root ) {
			return;
		}

		// CSS keeps every panel open until JS confirms it can switch them.
		root.classList.remove( 'no-js' );

		// Destructive buttons (Restore defaults) open their own .wpb-modal
		// instead of the browser's confirm() box. The modal is markup, so its
		// wording is translated server side, and its confirm button is a real
		// submit carrying name="Restore_defaults" - nothing is re-posted by
		// hand. With JS off the modal stays hidden and the trigger submits
		// straight away, which is how the button behaved before.
		[].slice.call( root.querySelectorAll( '[data-wpb-modal]' ) )
			.forEach( function ( trigger ) {
				var modal = document.getElementById( trigger.getAttribute( 'data-wpb-modal' ) );

				if ( ! modal ) {
					return;
				}

				var box = modal.querySelector( '.wpb-modal__box' );

				function focusable() {
					return [].slice.call(
						box.querySelectorAll( 'button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])' )
					).filter( function ( el ) {
						return ! el.disabled && el.offsetParent !== null;
					} );
				}

				function close() {
					modal.hidden = true;
					document.body.classList.remove( 'wpb-modal-open' );
					document.removeEventListener( 'keydown', onKey );
					trigger.focus();
				}

				// Escape closes; Tab is trapped so focus cannot wander off
				// into the page behind the veil.
				function onKey( event ) {
					if ( event.key === 'Escape' ) {
						event.preventDefault();
						close();
						return;
					}

					if ( event.key !== 'Tab' ) {
						return;
					}

					var stops = focusable();

					if ( ! stops.length ) {
						return;
					}

					var first = stops[ 0 ];
					var last = stops[ stops.length - 1 ];

					if ( event.shiftKey && document.activeElement === first ) {
						event.preventDefault();
						last.focus();
					} else if ( ! event.shiftKey && document.activeElement === last ) {
						event.preventDefault();
						first.focus();
					}
				}

				trigger.addEventListener( 'click', function ( event ) {
					event.preventDefault();

					modal.hidden = false;
					document.body.classList.add( 'wpb-modal-open' );
					document.addEventListener( 'keydown', onKey );

					// Cancel takes the focus, never the destructive button -
					// so a stray Enter or Space dismisses instead of resets.
					var cancel = modal.querySelector( '.wpb-modal__acts [data-wpb-modal-close]' );

					if ( cancel ) {
						cancel.focus();
					}
				} );

				[].slice.call( modal.querySelectorAll( '[data-wpb-modal-close]' ) )
					.forEach( function ( closer ) {
						closer.addEventListener( 'click', function ( event ) {
							event.preventDefault();
							close();
						} );
					} );
			} );

		var pills = [].slice.call( root.querySelectorAll( '.wpb-pill[data-panel]' ) );
		var panels = [].slice.call( root.querySelectorAll( '.wpb-panel' ) );

		if ( ! pills.length || ! panels.length ) {
			return;
		}

		function store( id ) {
			try {
				window.localStorage.setItem( STORE_KEY, id );
			} catch ( e ) {
				// Private browsing / storage disabled - the tab just won't persist.
			}
		}

		function restore() {
			try {
				return window.localStorage.getItem( STORE_KEY );
			} catch ( e ) {
				return null;
			}
		}

		// PRO badges, primary CTAs and screenshots sweep once when their tab
		// opens - the same glass shine they carry on hover. The class comes
		// off again on animationend so a later hover starts a fresh sweep.
		function shine( id ) {
			var panel = document.getElementById( id );

			if ( ! panel ) {
				return;
			}

			[].slice.call( panel.querySelectorAll( '.wpb-tag--pro, .wpb-btn--primary, .wpb-shot' ) )
				.forEach( function ( el ) {
					el.classList.remove( 'wpb-shine' );

					// Reading a layout property flushes the removal, so the
					// animation restarts instead of being seen as unchanged.
					void el.offsetWidth;

					el.classList.add( 'wpb-shine' );
				} );
		}

		root.addEventListener( 'animationend', function ( event ) {
			if ( event.animationName === 'wpb-shine' && event.target.classList ) {
				event.target.classList.remove( 'wpb-shine' );
			}
		} );

		function activate( id, focus ) {
			var matched = false;

			pills.forEach( function ( pill ) {
				var on = pill.getAttribute( 'data-panel' ) === id;

				pill.classList.toggle( 'is-active', on );
				pill.setAttribute( 'aria-selected', on ? 'true' : 'false' );
				pill.setAttribute( 'tabindex', on ? '0' : '-1' );

				if ( on ) {
					matched = true;

					if ( focus ) {
						pill.focus();
					}
				}
			} );

			if ( ! matched ) {
				return false;
			}

			panels.forEach( function ( panel ) {
				panel.classList.toggle( 'is-active', panel.id === id );
			} );

			shine( id );
			store( id );

			return true;
		}

		pills.forEach( function ( pill, index ) {
			pill.addEventListener( 'click', function ( event ) {
				event.preventDefault();
				activate( pill.getAttribute( 'data-panel' ), false );
			} );

			// Left/right walk the rail, Home/End jump to its ends.
			pill.addEventListener( 'keydown', function ( event ) {
				var next = null;

				if ( event.key === 'ArrowRight' || event.key === 'ArrowDown' ) {
					next = pills[ ( index + 1 ) % pills.length ];
				} else if ( event.key === 'ArrowLeft' || event.key === 'ArrowUp' ) {
					next = pills[ ( index - 1 + pills.length ) % pills.length ];
				} else if ( event.key === 'Home' ) {
					next = pills[ 0 ];
				} else if ( event.key === 'End' ) {
					next = pills[ pills.length - 1 ];
				}

				if ( next ) {
					event.preventDefault();
					activate( next.getAttribute( 'data-panel' ), true );
				}
			} );
		} );

		// A saved panel wins, otherwise fall back to the first pill.
		if ( ! activate( restore(), false ) ) {
			activate( pills[ 0 ].getAttribute( 'data-panel' ), false );
		}

		// The Premium features card shows the same offer two ways: the plans
		// grid (default) and the module-by-module breakdown. The switch only
		// swaps which .wpb-view is on, so nothing is lost when JS is absent.
		var switches = [].slice.call( root.querySelectorAll( '.wpb-switch' ) );

		switches.forEach( function ( group ) {
			var buttons = [].slice.call( group.querySelectorAll( '.wpb-switch__btn[data-view]' ) );

			if ( ! buttons.length ) {
				return;
			}

			function show( id ) {
				buttons.forEach( function ( button ) {
					var on = button.getAttribute( 'data-view' ) === id;

					button.classList.toggle( 'is-active', on );
					button.setAttribute( 'aria-pressed', on ? 'true' : 'false' );

					var view = document.getElementById( button.getAttribute( 'data-view' ) );

					if ( view ) {
						view.classList.toggle( 'is-active', on );
					}
				} );

				shine( id );
			}

			buttons.forEach( function ( button ) {
				button.addEventListener( 'click', function () {
					show( button.getAttribute( 'data-view' ) );
				} );
			} );
		} );

		// Checkbox tiles carry their checked state in the border/tint.
		[].slice.call( root.querySelectorAll( '.wpb-check input[type="checkbox"]' ) )
			.forEach( function ( box ) {
				var tile = box.closest( '.wpb-check' );

				if ( ! tile ) {
					return;
				}

				function sync() {
					tile.classList.toggle( 'is-on', box.checked );
				}

				box.addEventListener( 'change', sync );
				sync();
			} );
	} );
}() );
