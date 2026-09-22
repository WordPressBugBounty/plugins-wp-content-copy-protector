/**
 * WP Content Copy Protection - Protection Center panel.
 *
 * The numbers are rendered server side (activity-panel.php). This script
 * draws the "visits with blocked actions per day" chart - one series, one
 * hue, the previous period as hollow bars beside it - with a hover / keyboard
 * tooltip and a table twin, and handles the insight settings over AJAX.
 *
 * Page titles never reach this script; the only values it inserts are counts
 * and day labels, always through textContent.
 */
( function () {
	'use strict';

	var cfg = window.wccpActivityCenter;
	var data = window.wccpActivityData;
	var root = document.getElementById( 'wpb-admin' );
	var panel = document.getElementById( 'wpb-ac' );

	if ( ! cfg || ! root || ! panel ) {
		return;
	}

	var t = cfg.i18n;
	var SVG_NS = 'http://www.w3.org/2000/svg';

	/* ------------------------------------------------------------------
	   Helpers
	   ------------------------------------------------------------------ */

	function h( tag, className, text ) {
		var node = document.createElement( tag );

		if ( className ) {
			node.className = className;
		}
		if ( text !== undefined && text !== null ) {
			node.textContent = String( text );
		}

		return node;
	}

	function s( tag, attrs ) {
		var node = document.createElementNS( SVG_NS, tag );

		Object.keys( attrs || {} ).forEach( function ( key ) {
			node.setAttribute( key, attrs[ key ] );
		} );

		return node;
	}

	function num( value ) {
		try {
			return Number( value ).toLocaleString( cfg.locale );
		} catch ( e ) {
			return String( value );
		}
	}

	function request( action, fields ) {
		var body = new FormData();

		body.append( 'action', action );
		body.append( '_ajax_nonce', cfg.nonce );

		Object.keys( fields || {} ).forEach( function ( key ) {
			body.append( key, fields[ key ] );
		} );

		return window.fetch( cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } )
			.then( function ( response ) {
				return response.json();
			} )
			.then( function ( json ) {
				if ( ! json || ! json.success ) {
					throw new Error( json && json.data && json.data.message ? json.data.message : t.failed );
				}

				return json.data;
			} );
	}

	var statusTimer;

	function status( text, sticky ) {
		[].slice.call( panel.querySelectorAll( '[data-ac-status]' ) ).forEach( function ( node ) {
			node.textContent = text;
		} );

		clearTimeout( statusTimer );

		if ( ! sticky ) {
			statusTimer = setTimeout( function () {
				status( '', true );
			}, 4000 );
		}
	}

	/* ------------------------------------------------------------------
	   Chart
	   ------------------------------------------------------------------ */

	var chartBox = document.getElementById( 'wpb-ac-chart' );
	var tableBox = document.getElementById( 'wpb-ac-table' );

	// Round axis steps: 1, 2, 5 x 10^n, at most four gridlines.
	function ticks( max ) {
		if ( max <= 0 ) {
			return [ 0, 1 ];
		}

		var power = Math.pow( 10, Math.floor( Math.log( max ) / Math.LN10 ) );
		var step = [ 1, 2, 5, 10 ].map( function ( m ) {
			return m * power;
		} ).filter( function ( candidate ) {
			return candidate >= 1 && Math.ceil( max / candidate ) <= 4;
		} )[ 0 ] || power * 10;

		var list = [];

		for ( var v = 0; v <= Math.ceil( max / step ) * step; v += step ) {
			list.push( v );
		}

		return list;
	}

	// A column with only its data end rounded, anchored to the baseline.
	function column( x, y, width, height ) {
		var r = Math.min( 4, width / 2, height );

		return 'M' + x + ',' + ( y + height ) +
			'V' + ( y + r ) +
			'Q' + x + ',' + y + ' ' + ( x + r ) + ',' + y +
			'H' + ( x + width - r ) +
			'Q' + ( x + width ) + ',' + y + ' ' + ( x + width ) + ',' + ( y + r ) +
			'V' + ( y + height ) + 'Z';
	}

	function renderChart() {
		if ( ! chartBox || ! data || ! chartBox.offsetParent ) {
			return;
		}

		var rows = data.series;
		var prev = !! data.showPrev;
		var width = Math.max( 280, chartBox.clientWidth );
		var height = 230;
		var pad = { top: 22, right: 6, bottom: 28, left: 34 };
		var plotW = width - pad.left - pad.right;
		var plotH = height - pad.top - pad.bottom;

		var max = rows.reduce( function ( m, row ) {
			return Math.max( m, row.v, prev ? row.prev : 0 );
		}, 0 );
		var scale = ticks( max );
		var top = scale[ scale.length - 1 ];

		function y( value ) {
			return pad.top + plotH - ( value / top ) * plotH;
		}

		chartBox.textContent = '';

		var wrap = h( 'div', 'wpb-ac-chart__wrap' );
		wrap.setAttribute( 'tabindex', '0' );
		wrap.setAttribute( 'role', 'img' );
		wrap.setAttribute( 'aria-label', t.chartLabel );

		var svg = s( 'svg', { width: width, height: height, viewBox: '0 0 ' + width + ' ' + height, 'class': 'wpb-ac-svg' } );

		scale.forEach( function ( value ) {
			svg.appendChild( s( 'line', { x1: pad.left, x2: width - pad.right, y1: y( value ), y2: y( value ), 'class': value === 0 ? 'wpb-ac-axis' : 'wpb-ac-gridline' } ) );

			var label = s( 'text', { x: pad.left - 8, y: y( value ) + 4, 'text-anchor': 'end', 'class': 'wpb-ac-tick' } );
			label.textContent = num( value );
			svg.appendChild( label );
		} );

		var band = plotW / rows.length;
		var barW = Math.min( prev ? 26 : 44, ( band * 0.66 - ( prev ? 2 : 0 ) ) / ( prev ? 2 : 1 ) );
		var groupW = prev ? barW * 2 + 2 : barW;
		var peak = rows.reduce( function ( best, row, i ) {
			return row.v > rows[ best ].v ? i : best;
		}, 0 );

		var highlight = s( 'rect', { x: 0, y: pad.top, width: band, height: plotH, 'class': 'wpb-ac-hl', visibility: 'hidden' } );
		svg.appendChild( highlight );

		rows.forEach( function ( row, i ) {
			var x = pad.left + band * i + ( band - groupW ) / 2;

			if ( prev && row.prev > 0 ) {
				var ph = plotH - ( y( row.prev ) - pad.top );
				// Inset by half the stroke so the outline sits inside its slot.
				svg.appendChild( s( 'path', { d: column( x + 0.75, y( row.prev ) + 0.75, barW - 1.5, Math.max( 0, ph - 0.75 ) ), 'class': 'wpb-ac-col--prev' } ) );
			}

			if ( row.v > 0 ) {
				var cx = prev ? x + barW + 2 : x;
				svg.appendChild( s( 'path', { d: column( cx, y( row.v ), barW, plotH - ( y( row.v ) - pad.top ) ), 'class': 'wpb-ac-col' } ) );

				// Label the peak only - the tooltip and the table carry the rest.
				if ( i === peak ) {
					var peakLabel = s( 'text', { x: cx + barW / 2, y: y( row.v ) - 6, 'text-anchor': 'middle', 'class': 'wpb-ac-peak' } );
					peakLabel.textContent = num( row.v );
					svg.appendChild( peakLabel );
				}
			}

			var day = s( 'text', { x: pad.left + band * i + band / 2, y: height - 8, 'text-anchor': 'middle', 'class': 'wpb-ac-tick' } );
			day.textContent = band < 64 ? row.short : row.label;
			svg.appendChild( day );
		} );

		var hit = s( 'rect', { x: pad.left, y: pad.top, width: plotW, height: plotH, 'class': 'wpb-ac-hit' } );
		svg.appendChild( hit );

		wrap.appendChild( svg );
		chartBox.appendChild( wrap );

		var tip = h( 'div', 'wpb-ac-tip' );
		tip.hidden = true;
		chartBox.appendChild( tip );

		var active = -1;

		function show( i ) {
			var row = rows[ i ];
			active = i;

			highlight.setAttribute( 'x', pad.left + band * i );
			highlight.setAttribute( 'visibility', 'visible' );

			tip.textContent = '';
			tip.appendChild( h( 'div', 'wpb-ac-tip__day', row.label ) );

			function line( label, value, key ) {
				var el = h( 'div', 'wpb-ac-tip__row' + ( key ? ' wpb-ac-tip__row--' + key : '' ) );

				if ( key ) {
					el.appendChild( h( 'span', 'wpb-ac-tip__key' ) );
				}
				el.appendChild( h( 'strong', '', num( value ) ) );
				el.appendChild( h( 'span', '', label ) );
				tip.appendChild( el );
			}

			line( t.visits, row.v, 'now' );

			if ( prev ) {
				line( t.previous, row.prev, 'prev' );
			}

			var any = false;
			Object.keys( data.labels ).forEach( function ( key ) {
				if ( row[ key ] > 0 ) {
					if ( ! any ) {
						tip.appendChild( h( 'div', 'wpb-ac-tip__sep' ) );
						any = true;
					}
					line( data.labels[ key ], row[ key ] );
				}
			} );

			tip.hidden = false;

			var left = pad.left + band * i + band / 2 - tip.offsetWidth / 2;
			tip.style.left = Math.max( 0, Math.min( width - tip.offsetWidth, left ) ) + 'px';
			tip.style.top = Math.max( 0, pad.top - 8 ) + 'px';

			wrap.setAttribute( 'aria-label', row.label + ': ' + num( row.v ) + ' ' + t.visits );
		}

		function hide() {
			active = -1;
			tip.hidden = true;
			highlight.setAttribute( 'visibility', 'hidden' );
			wrap.setAttribute( 'aria-label', t.chartLabel );
		}

		hit.addEventListener( 'pointermove', function ( event ) {
			var box = svg.getBoundingClientRect();
			var i = Math.floor( ( event.clientX - box.left - pad.left ) / band );

			if ( i >= 0 && i < rows.length && i !== active ) {
				show( i );
			}
		} );
		hit.addEventListener( 'pointerleave', hide );
		wrap.addEventListener( 'blur', hide );

		wrap.addEventListener( 'keydown', function ( event ) {
			var next = active;

			if ( event.key === 'ArrowRight' ) {
				next = Math.min( rows.length - 1, active + 1 );
			} else if ( event.key === 'ArrowLeft' ) {
				next = active < 0 ? rows.length - 1 : Math.max( 0, active - 1 );
			} else if ( event.key === 'Home' ) {
				next = 0;
			} else if ( event.key === 'End' ) {
				next = rows.length - 1;
			} else if ( event.key === 'Escape' ) {
				hide();
				return;
			} else {
				return;
			}

			event.preventDefault();
			show( next );
		} );
	}

	// The panel may be hidden on load (another tab open), so draw on open and resize.
	root.addEventListener( 'wpb:panel', function ( event ) {
		if ( event.detail && event.detail.id === 'wpb-panel-center' ) {
			renderChart();
		}
	} );

	var resizeTimer;
	window.addEventListener( 'resize', function () {
		clearTimeout( resizeTimer );
		resizeTimer = setTimeout( renderChart, 150 );
	} );

	renderChart();

	// Chart / table switch.
	[].slice.call( panel.querySelectorAll( '[data-ac-view]' ) ).forEach( function ( button, index, all ) {
		button.addEventListener( 'click', function () {
			var table = button.getAttribute( 'data-ac-view' ) === 'table';

			all.forEach( function ( other ) {
				var on = other === button;
				other.classList.toggle( 'is-active', on );
				other.setAttribute( 'aria-pressed', on ? 'true' : 'false' );
			} );

			chartBox.hidden = table;
			tableBox.hidden = ! table;

			if ( ! table ) {
				renderChart();
			}
		} );
	} );

	/* ------------------------------------------------------------------
	   Longer history (PRO)
	   ------------------------------------------------------------------ */

	var history = document.getElementById( 'wpb-ac-history' );

	[].slice.call( panel.querySelectorAll( '[data-ac-locked]' ) ).forEach( function ( button ) {
		button.addEventListener( 'click', function () {
			if ( ! history ) {
				return;
			}

			history.hidden = false;
			button.setAttribute( 'aria-expanded', 'true' );

			var cta = history.querySelector( '.wpb-btn--primary' );
			if ( cta ) {
				cta.classList.remove( 'wpb-shine' );
				void cta.offsetWidth;
				cta.classList.add( 'wpb-shine' );
			}
		} );
	} );

	/* ------------------------------------------------------------------
	   Settings
	   ------------------------------------------------------------------ */

	function save( fields, reload ) {
		status( t.saving, true );

		return request( 'wccp_free_activity_save', fields )
			.then( function () {
				status( t.saved );

				if ( reload ) {
					window.location.href = window.location.pathname + '?page=wccpoptionspro&tab=center';
				}
			} )
			.catch( function ( error ) {
				status( error.message );
				throw error;
			} );
	}

	[].slice.call( panel.querySelectorAll( '[data-ac-option]' ) ).forEach( function ( box ) {
		box.addEventListener( 'change', function () {
			var field = box.getAttribute( 'data-ac-option' );
			var fields = {};

			fields[ field ] = box.checked ? 'yes' : 'no';

			// Switching recording on or off changes the whole panel.
			save( fields, field === 'enabled' ).catch( function () {
				box.checked = ! box.checked;
			} );
		} );
	} );

	var enable = panel.querySelector( '[data-ac-enable]' );

	if ( enable ) {
		enable.addEventListener( 'click', function () {
			enable.disabled = true;
			save( { enabled: 'yes' }, true ).catch( function () {
				enable.disabled = false;
			} );
		} );
	}

	var test = panel.querySelector( '[data-ac-test]' );

	if ( test ) {
		test.addEventListener( 'click', function ( event ) {
			// The button sits inside a <label>: keep the checkbox as it is.
			event.preventDefault();
			test.disabled = true;
			status( t.sending, true );

			request( 'wccp_free_activity_test_digest' )
				.then( function ( result ) {
					status( result.message );
				} )
				.catch( function ( error ) {
					status( error.message );
				} )
				.then( function () {
					test.disabled = false;
				} );
		} );
	}

	var purge = document.querySelector( '[data-ac-purge]' );

	if ( purge ) {
		purge.addEventListener( 'click', function () {
			request( 'wccp_free_activity_purge' )
				.then( function () {
					status( t.deleted, true );
					window.location.href = window.location.pathname + '?page=wccpoptionspro&tab=center';
				} )
				.catch( function ( error ) {
					status( error.message );
				} );
		} );
	}

	var hint = panel.querySelector( '[data-ac-hint]' );

	if ( hint ) {
		hint.querySelector( '[data-ac-hide-hint]' ).addEventListener( 'click', function () {
			request( 'wccp_free_activity_hide_hint', { hint: hint.getAttribute( 'data-ac-hint' ) } ).catch( function () {} );
			hint.hidden = true;
		} );
	}
}() );
