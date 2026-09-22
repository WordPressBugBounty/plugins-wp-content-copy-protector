/**
 * WP Content Copy Protection - Error Monitor panel.
 *
 * Asks error-monitor.php for a scan the first time the panel opens, then
 * renders everything client side so the range / log filters are instant:
 * stat tiles, the stacked "errors per day" chart (with a table twin), the
 * per-source bars, the grouped issue list and the log files list.
 *
 * Log text is untrusted - a visitor can get a URL of their choosing into a
 * PHP warning - so every value from the scan goes in through textContent.
 * The only markup inserted is the static icon <template> from admin-core.php.
 */
( function () {
	'use strict';

	var cfg = window.wccpErrorMonitor;
	var root = document.getElementById( 'wpb-admin' );
	var panel = document.getElementById( 'wpb-em' );

	if ( ! cfg || ! root || ! panel ) {
		return;
	}

	var t = cfg.i18n;
	var SVG_NS = 'http://www.w3.org/2000/svg';

	// Bottom of the stack first: fatal errors sit on the baseline where they
	// are easiest to compare. Severity is ordered, so it wears one red ramp
	// (validated as an ordinal ramp on white); "other" is not a severity and
	// stays neutral. Colours live in css/error-monitor.css as --em-* tokens.
	var LEVELS = [ 'fatal', 'warning', 'notice', 'deprecated', 'other' ];
	var LEVEL_ICONS = { fatal: 'alert', warning: 'alert', notice: 'info', deprecated: 'clock', other: 'message' };
	var PAGE = 25;

	var state = {
		data: null,
		loading: false,
		range: 30,
		log: '',
		sort: 'count',
		view: 'chart',
		limit: PAGE,
		active: -1
	};

	var el = {
		status: document.getElementById( 'wpb-em-status' ),
		alerts: document.getElementById( 'wpb-em-alerts' ),
		body: document.getElementById( 'wpb-em-body' ),
		log: document.getElementById( 'wpb-em-log' ),
		refresh: document.getElementById( 'wpb-em-refresh' ),
		legend: document.getElementById( 'wpb-em-legend' ),
		chart: document.getElementById( 'wpb-em-chart' ),
		dayTable: document.getElementById( 'wpb-em-daytable' ),
		sources: document.getElementById( 'wpb-em-sources' ),
		issues: document.getElementById( 'wpb-em-issues' ),
		more: document.getElementById( 'wpb-em-more' ),
		files: document.getElementById( 'wpb-em-files' ),
		icons: document.getElementById( 'wpb-em-icons' ),
		modal: document.getElementById( 'wpb-em-clear-modal' ),
		modalFile: document.getElementById( 'wpb-em-clear-file' ),
		modalShared: document.getElementById( 'wpb-em-clear-shared' ),
		confirm: document.getElementById( 'wpb-em-clear-confirm' )
	};

	/* ------------------------------------------------------------------
	   Small helpers
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

	function icon( name ) {
		var source = el.icons && el.icons.content.querySelector( '[data-icon="' + name + '"] svg' );

		return source ? source.cloneNode( true ) : document.createTextNode( '' );
	}

	function clear( node ) {
		while ( node.firstChild ) {
			node.removeChild( node.firstChild );
		}
	}

	function num( value ) {
		try {
			return Number( value ).toLocaleString( cfg.locale );
		} catch ( e ) {
			return String( value );
		}
	}

	function fill( template ) {
		var args = [].slice.call( arguments, 1 );
		var i = 0;

		return template
			.replace( /%(\d)\$s/g, function ( match, n ) {
				return args[ n - 1 ];
			} )
			.replace( /%s/g, function () {
				return args[ i++ ];
			} );
	}

	function ago( timestamp ) {
		var seconds = timestamp - state.data.now;
		var units = [ [ 'year', 31536000 ], [ 'month', 2592000 ], [ 'week', 604800 ], [ 'day', 86400 ], [ 'hour', 3600 ], [ 'minute', 60 ] ];

		try {
			var format = new Intl.RelativeTimeFormat( cfg.locale, { numeric: 'auto' } );

			for ( var i = 0; i < units.length; i++ ) {
				if ( Math.abs( seconds ) >= units[ i ][ 1 ] ) {
					return format.format( Math.round( seconds / units[ i ][ 1 ] ), units[ i ][ 0 ] );
				}
			}

			return format.format( 0, 'minute' );
		} catch ( e ) {
			return '';
		}
	}

	// 'YYYY-MM-DD' strings are handled as UTC dates so no DST shift can
	// move a day; the server already bucketed entries in the site timezone.
	function dayList( range ) {
		var parts = state.data.today.split( '-' );
		var base = Date.UTC( +parts[ 0 ], parts[ 1 ] - 1, +parts[ 2 ] );
		var days = [];

		for ( var i = range - 1; i >= 0; i-- ) {
			days.push( new Date( base - i * 86400000 ).toISOString().slice( 0, 10 ) );
		}

		return days;
	}

	function dayLabel( day, long ) {
		var parts = day.split( '-' );
		var date = new Date( Date.UTC( +parts[ 0 ], parts[ 1 ] - 1, +parts[ 2 ] ) );
		var options = long ?
			{ weekday: 'short', month: 'short', day: 'numeric', timeZone: 'UTC' } :
			{ month: 'short', day: 'numeric', timeZone: 'UTC' };

		try {
			return date.toLocaleDateString( cfg.locale, options );
		} catch ( e ) {
			return day;
		}
	}

	function setStatus( text ) {
		el.status.textContent = text || '';
	}

	/* ------------------------------------------------------------------
	   Data
	   ------------------------------------------------------------------ */

	function request( action, fields ) {
		var body = new FormData();

		body.append( 'action', action );
		Object.keys( fields ).forEach( function ( key ) {
			body.append( key, fields[ key ] );
		} );

		return fetch( cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } )
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

	function scan( doneMessage ) {
		if ( state.loading ) {
			return;
		}

		state.loading = true;
		panel.classList.add( 'is-loading' );
		el.refresh.disabled = true;
		setStatus( t.loading );

		if ( ! state.data ) {
			el.chart.textContent = t.loading;
		}

		request( 'wccp_free_em_scan', { _ajax_nonce: cfg.scanNonce } )
			.then( function ( data ) {
				state.data = data;
				setStatus( doneMessage || '' );
				renderAll();
			} )
			.catch( function ( error ) {
				setStatus( error.message || t.failed );

				if ( ! state.data ) {
					el.chart.textContent = error.message || t.failed;
				}
			} )
			.then( function () {
				state.loading = false;
				panel.classList.remove( 'is-loading' );
				el.refresh.disabled = false;
			} );
	}

	/**
	 * Apply the range and log filters: per-issue counts, per-day stacks,
	 * per-source totals. Every view below renders from this one slice so
	 * the numbers always agree.
	 */
	function slice() {
		var days = dayList( state.range );
		var index = {};
		var perDay = days.map( function ( day, i ) {
			var row = { day: day, total: 0 };

			index[ day ] = i;
			LEVELS.forEach( function ( level ) {
				row[ level ] = 0;
			} );

			return row;
		} );

		var issues = [];
		var sources = {};
		var totals = { count: 0, fatal: 0, last: 0 };

		state.data.issues.forEach( function ( issue ) {
			var count = 0;

			Object.keys( issue.days ).forEach( function ( logId ) {
				if ( state.log && state.log !== logId ) {
					return;
				}

				var byDay = issue.days[ logId ];

				Object.keys( byDay ).forEach( function ( day ) {
					if ( index[ day ] === undefined ) {
						return;
					}

					var row = perDay[ index[ day ] ];

					row[ issue.level ] += byDay[ day ];
					row.total += byDay[ day ];
					count += byDay[ day ];
				} );
			} );

			if ( ! count ) {
				return;
			}

			issues.push( { issue: issue, count: count } );

			totals.count += count;
			totals.last = Math.max( totals.last, issue.last );

			if ( issue.level === 'fatal' ) {
				totals.fatal += count;
			}

			var key = issue.source.key;

			if ( ! sources[ key ] ) {
				sources[ key ] = { name: issue.source.name, kind: issue.source.kind, count: 0, issues: 0, fatal: 0 };
			}

			sources[ key ].count += count;
			sources[ key ].issues++;

			if ( issue.level === 'fatal' ) {
				sources[ key ].fatal += count;
			}
		} );

		return {
			days: perDay,
			issues: issues,
			sources: Object.keys( sources ).map( function ( key ) {
				return sources[ key ];
			} ).sort( function ( a, b ) {
				return b.count - a.count;
			} ),
			totals: totals
		};
	}

	/* ------------------------------------------------------------------
	   Rendering
	   ------------------------------------------------------------------ */

	function renderAll() {
		renderLogSelect();
		renderAlerts();
		renderFiles();
		renderView();
	}

	// Everything the range / log / sort controls affect.
	function renderView() {
		var view = slice();
		var hasLogs = state.data.files.some( function ( file ) {
			return file.readable;
		} );

		el.body.hidden = ! hasLogs;

		if ( ! hasLogs ) {
			return;
		}

		renderTiles( view );
		renderLegend();
		renderChart( view.days );
		renderDayTable( view.days );
		renderSources( view.sources );
		renderIssues( view.issues );
	}

	function renderLogSelect() {
		var current = state.log;

		while ( el.log.options.length > 1 ) {
			el.log.remove( 1 );
		}

		state.data.files.forEach( function ( file ) {
			if ( ! file.readable ) {
				return;
			}

			var option = h( 'option', '', file.label + ' (' + file.path + ')' );

			option.value = file.id;
			el.log.appendChild( option );
		} );

		el.log.value = current;

		// The chosen log was cleared away or no longer exists.
		if ( el.log.value !== current ) {
			state.log = '';
			el.log.value = '';
		}

		el.log.closest( '.wpb-em-select' ).hidden = el.log.options.length <= 2;
	}

	function renderAlerts() {
		clear( el.alerts );

		var files = state.data.files;
		var found = files.some( function ( file ) {
			return file.exists;
		} );

		if ( ! found ) {
			el.alerts.appendChild( alertBox( 'info', 'info', t.noLogs, t.noLogsText ) );
		}
	}

	function alertBox( tone, iconName, title, text ) {
		var box = h( 'div', 'wpb-em-alert wpb-em-alert--' + tone );
		var ic = h( 'span', 'wpb-em-alert__ic' );
		var body = h( 'div', 'wpb-em-alert__txt' );

		box.setAttribute( 'role', 'status' );
		ic.appendChild( icon( iconName ) );
		body.appendChild( h( 'strong', 'wpb-em-alert__title', title ) );
		body.appendChild( h( 'p', '', text ) );
		box.appendChild( ic );
		box.appendChild( body );

		return box;
	}


	function renderTiles( view ) {
		var set = function ( name, value ) {
			var node = panel.querySelector( '[data-em-tile="' + name + '"]' );

			if ( node ) {
				node.textContent = value;
			}
		};

		set( 'occurrences', num( view.totals.count ) );
		set( 'issues', num( view.issues.length ) );
		set( 'fatal', num( view.totals.fatal ) );

		// "Last error" is about the logs as a whole, whatever the range.
		var last = 0;
		var lastLabel = '';

		state.data.issues.forEach( function ( issue ) {
			if ( state.log && ! issue.days[ state.log ] ) {
				return;
			}
			if ( issue.last > last ) {
				last = issue.last;
				lastLabel = issue.lastLabel;
			}
		} );

		set( 'last', last ? ago( last ) : t.never );
		set( 'lastLabel', lastLabel );

		panel.querySelector( '.wpb-em-tile--fatal' ).classList.toggle( 'has-fatal', view.totals.fatal > 0 );

		[].slice.call( panel.querySelectorAll( '[data-em-note]' ) ).forEach( function ( node ) {
			node.textContent = fill( t.inLastDays, num( state.range ) );
		} );
	}

	function renderLegend() {
		if ( el.legend.childNodes.length ) {
			return;
		}

		LEVELS.forEach( function ( level ) {
			var item = h( 'li', 'wpb-em-legend__item' );

			item.appendChild( h( 'span', 'wpb-em-swatch wpb-em-lv--' + level ) );
			item.appendChild( document.createTextNode( t.levels[ level ] ) );
			el.legend.appendChild( item );
		} );
	}

	/**
	 * Clean axis ticks: a 1/2/5 step giving about four gridlines.
	 */
	function ticks( max ) {
		if ( max <= 0 ) {
			return { top: 4, step: 1 };
		}

		var rough = max / 4;
		var magnitude = Math.pow( 10, Math.floor( Math.log10( rough ) ) );
		var step = [ 1, 2, 5, 10 ].map( function ( m ) {
			return m * magnitude;
		} ).filter( function ( candidate ) {
			return candidate >= rough;
		} )[ 0 ];

		step = Math.max( 1, step );

		return { top: Math.ceil( max / step ) * step, step: step };
	}

	/**
	 * Column with a 4px rounded data end and a square foot on the baseline.
	 */
	function columnPath( x, y, width, height, rounded ) {
		var r = rounded ? Math.min( 4, width / 2, height ) : 0;

		return 'M' + x + ',' + ( y + height ) +
			'V' + ( y + r ) +
			( r ? 'Q' + x + ',' + y + ' ' + ( x + r ) + ',' + y : '' ) +
			'H' + ( x + width - r ) +
			( r ? 'Q' + ( x + width ) + ',' + y + ' ' + ( x + width ) + ',' + ( y + r ) : '' ) +
			'V' + ( y + height ) + 'Z';
	}

	function renderChart( days ) {
		clear( el.chart );

		var width = Math.max( 280, el.chart.clientWidth || 600 );
		var plotH = 190;
		var top = 22;
		var axisH = 26;
		var max = days.reduce( function ( m, row ) {
			return Math.max( m, row.total );
		}, 0 );
		var scale = ticks( max );
		var left = Math.max( 28, String( num( scale.top ) ).length * 7 + 12 );
		var right = 8;
		var plotW = width - left - right;
		var band = plotW / days.length;
		var barW = Math.max( 2, Math.min( 24, band * 0.64 ) );
		var baseY = top + plotH;
		var height = baseY + axisH;
		var GAP = 2;

		var svg = s( 'svg', {
			class: 'wpb-em-svg',
			width: width,
			height: height,
			viewBox: '0 0 ' + width + ' ' + height,
			role: 'img',
			'aria-label': fill( t.chartLabel, fill( t.inLastDays, num( state.range ) ) )
		} );

		var y = function ( value ) {
			return baseY - ( value / scale.top ) * plotH;
		};

		// Recessive hairline grid with thousands-comma'd ticks.
		for ( var v = 0; v <= scale.top; v += scale.step ) {
			var gy = Math.round( y( v ) ) + 0.5;

			svg.appendChild( s( 'line', { class: v === 0 ? 'wpb-em-axis' : 'wpb-em-gridline', x1: left, x2: width - right, y1: gy, y2: gy } ) );

			var tick = s( 'text', { class: 'wpb-em-tick', x: left - 8, y: gy + 4, 'text-anchor': 'end' } );

			tick.textContent = num( v );
			svg.appendChild( tick );
		}

		var highlight = s( 'rect', { class: 'wpb-em-hl', x: 0, y: top, width: band, height: plotH, rx: 6, visibility: 'hidden' } );

		svg.appendChild( highlight );

		// Label only as many dates as fit, counted back from today.
		var every = Math.max( 1, Math.ceil( 56 / band ) );
		var maxIndex = -1;

		days.forEach( function ( row, i ) {
			if ( row.total && ( maxIndex < 0 || row.total >= days[ maxIndex ].total ) ) {
				maxIndex = i;
			}
		} );

		days.forEach( function ( row, i ) {
			var cx = left + band * i + band / 2;
			var x = cx - barW / 2;
			var stackTop = baseY;
			var drawn = LEVELS.filter( function ( level ) {
				return row[ level ] > 0;
			} );

			drawn.forEach( function ( level, n ) {
				var h0 = Math.max( 2, ( row[ level ] / scale.top ) * plotH );
				var yTop = stackTop - h0;
				// 2px surface gap between stacked segments.
				var segH = n === 0 ? h0 : Math.max( 1, h0 - GAP );

				svg.appendChild( s( 'path', {
					class: 'wpb-em-bar wpb-em-lv--' + level,
					d: columnPath( x, yTop, barW, segH, n === drawn.length - 1 )
				} ) );

				stackTop = yTop;
			} );

			// Selective direct label: the busiest day only.
			if ( i === maxIndex ) {
				var label = s( 'text', { class: 'wpb-em-peak', x: cx, y: Math.max( 12, stackTop - 6 ), 'text-anchor': 'middle' } );

				label.textContent = num( row.total );
				svg.appendChild( label );
			}

			if ( ( days.length - 1 - i ) % every === 0 ) {
				var dateText = s( 'text', { class: 'wpb-em-tick', x: cx, y: baseY + 18, 'text-anchor': 'middle' } );

				dateText.textContent = dayLabel( row.day, false );
				svg.appendChild( dateText );
			}
		} );

		// Whole-column hit areas: the pointer only has to be over the day.
		var hit = s( 'rect', { class: 'wpb-em-hit', x: left, y: top, width: plotW, height: plotH + axisH } );

		svg.appendChild( hit );

		var wrap = h( 'div', 'wpb-em-chart__wrap' );
		var tip = h( 'div', 'wpb-em-tip' );

		tip.hidden = true;
		wrap.tabIndex = 0;
		wrap.appendChild( svg );
		wrap.appendChild( tip );
		el.chart.appendChild( wrap );

		if ( ! max ) {
			el.chart.appendChild( h( 'p', 'wpb-em-chart__empty', t.noErrors ) );
		}

		function show( i ) {
			if ( i < 0 || i >= days.length ) {
				return;
			}

			state.active = i;

			var row = days[ i ];

			highlight.setAttribute( 'x', left + band * i );
			highlight.setAttribute( 'visibility', 'visible' );

			clear( tip );
			tip.appendChild( h( 'div', 'wpb-em-tip__day', dayLabel( row.day, true ) ) );

			LEVELS.concat( [ 'total' ] ).forEach( function ( level ) {
				var line = h( 'div', 'wpb-em-tip__row' + ( level === 'total' ? ' wpb-em-tip__row--total' : '' ) );

				line.appendChild( h( 'span', level === 'total' ? 'wpb-em-tip__key' : 'wpb-em-tip__key wpb-em-lv--' + level ) );
				line.appendChild( h( 'strong', '', num( row[ level ] ) ) );
				line.appendChild( h( 'span', '', level === 'total' ? t.total : t.levels[ level ] ) );
				tip.appendChild( line );
			} );

			tip.hidden = false;

			// Beside the column, flipped to the other side near the edge.
			var cx = left + band * i + band / 2;
			var tipW = tip.offsetWidth;
			var x = cx + band / 2 + 8;

			if ( x + tipW > width ) {
				x = cx - band / 2 - 8 - tipW;
			}

			tip.style.left = Math.max( 0, x ) + 'px';
			tip.style.top = top + 'px';
		}

		function hide() {
			tip.hidden = true;
			highlight.setAttribute( 'visibility', 'hidden' );
		}

		hit.addEventListener( 'pointermove', function ( event ) {
			var box = svg.getBoundingClientRect();
			var px = ( event.clientX - box.left ) * ( width / box.width );

			show( Math.min( days.length - 1, Math.max( 0, Math.floor( ( px - left ) / band ) ) ) );
		} );

		hit.addEventListener( 'pointerleave', function () {
			if ( document.activeElement !== wrap ) {
				hide();
			}
		} );

		// Keyboard gets what hover gets: arrows walk the days.
		wrap.addEventListener( 'focus', function () {
			var lastWithData = -1;

			days.forEach( function ( row, i ) {
				if ( row.total ) {
					lastWithData = i;
				}
			} );

			show( state.active >= 0 && state.active < days.length ? state.active : ( lastWithData >= 0 ? lastWithData : days.length - 1 ) );
		} );

		wrap.addEventListener( 'blur', hide );

		wrap.addEventListener( 'keydown', function ( event ) {
			var next = null;

			if ( event.key === 'ArrowRight' ) {
				next = state.active + 1;
			} else if ( event.key === 'ArrowLeft' ) {
				next = state.active - 1;
			} else if ( event.key === 'Home' ) {
				next = 0;
			} else if ( event.key === 'End' ) {
				next = days.length - 1;
			}

			if ( next !== null ) {
				event.preventDefault();
				show( Math.min( days.length - 1, Math.max( 0, next ) ) );
			}
		} );
	}

	// The chart's table twin: same slice, newest day first, empty days skipped.
	function renderDayTable( days ) {
		clear( el.dayTable );

		var rows = days.filter( function ( row ) {
			return row.total > 0;
		} ).reverse();

		if ( ! rows.length ) {
			el.dayTable.appendChild( h( 'p', 'wpb-em-chart__empty', t.noErrors ) );
			return;
		}

		var table = h( 'table', 'wpb-em-table' );
		var head = h( 'tr' );

		[ t.day ].concat( LEVELS.map( function ( level ) {
			return t.levels[ level ];
		} ), [ t.total ] ).forEach( function ( label ) {
			var th = h( 'th', '', label );

			th.scope = 'col';
			head.appendChild( th );
		} );

		table.appendChild( h( 'thead' ) ).appendChild( head );

		var body = table.appendChild( h( 'tbody' ) );

		rows.forEach( function ( row ) {
			var tr = h( 'tr' );
			var th = h( 'th', '', dayLabel( row.day, true ) );

			th.scope = 'row';
			tr.appendChild( th );

			LEVELS.concat( [ 'total' ] ).forEach( function ( level ) {
				tr.appendChild( h( 'td', row[ level ] ? '' : 'is-zero', num( row[ level ] ) ) );
			} );

			body.appendChild( tr );
		} );

		var scroller = h( 'div', 'wpb-em-table__scroll' );

		scroller.appendChild( table );
		el.dayTable.appendChild( scroller );
	}

	function renderSources( sources ) {
		clear( el.sources );

		if ( ! sources.length ) {
			el.sources.appendChild( h( 'li', 'wpb-em-empty', t.noErrors ) );
			return;
		}

		// Six named rows at most; the tail folds into one "other sources" row.
		var shown = sources.slice( 0, 6 );
		var rest = sources.slice( 6 );

		if ( rest.length ) {
			shown.push( rest.reduce( function ( sum, source ) {
				sum.count += source.count;
				sum.issues += source.issues;
				sum.fatal += source.fatal;

				return sum;
			}, { name: fill( t.otherSources, num( rest.length ) ), kind: '', count: 0, issues: 0, fatal: 0 } ) );
		}

		var max = shown.reduce( function ( m, source ) {
			return Math.max( m, source.count );
		}, 0 );

		shown.forEach( function ( source ) {
			var item = h( 'li', 'wpb-em-src' );
			var head = h( 'div', 'wpb-em-src__head' );

			head.appendChild( h( 'span', 'wpb-em-src__name', source.name ) );

			if ( source.kind && t.kinds[ source.kind ] ) {
				head.appendChild( h( 'span', 'wpb-em-src__kind', t.kinds[ source.kind ] ) );
			}

			var track = h( 'div', 'wpb-em-src__track' );
			var bar = h( 'span', 'wpb-em-src__bar' );

			bar.style.width = Math.max( 1.5, ( source.count / max ) * 100 ) + '%';
			track.appendChild( bar );
			track.appendChild( h( 'strong', 'wpb-em-src__value', num( source.count ) ) );

			var meta = source.issues === 1 ? t.issueOne : fill( t.issuesCount, num( source.issues ) );

			if ( source.fatal ) {
				meta += ' · ' + fill( t.fatalCount, num( source.fatal ) );
			}

			item.appendChild( head );
			item.appendChild( track );
			item.appendChild( h( 'span', 'wpb-em-src__meta', meta ) );
			el.sources.appendChild( item );
		} );
	}

	function levelBadge( level ) {
		var badge = h( 'span', 'wpb-em-badge wpb-em-badge--' + level );

		badge.appendChild( icon( LEVEL_ICONS[ level ] ) );
		badge.appendChild( document.createTextNode( t.levels[ level ] ) );

		return badge;
	}

	function renderIssues( rows ) {
		clear( el.issues );
		clear( el.more );

		if ( ! rows.length ) {
			var empty = h( 'li', 'wpb-em-empty wpb-em-empty--good' );

			empty.appendChild( icon( 'check' ) );
			empty.appendChild( document.createTextNode( t.noIssues ) );
			el.issues.appendChild( empty );
			return;
		}

		rows.sort( function ( a, b ) {
			if ( state.sort === 'last' ) {
				return b.issue.last - a.issue.last;
			}
			if ( state.sort === 'level' ) {
				return LEVELS.indexOf( a.issue.level ) - LEVELS.indexOf( b.issue.level ) || b.count - a.count;
			}

			return b.count - a.count || b.issue.last - a.issue.last;
		} );

		var labels = {};

		state.data.files.forEach( function ( file ) {
			labels[ file.id ] = file.path;
		} );

		rows.slice( 0, state.limit ).forEach( function ( row ) {
			var issue = row.issue;
			var item = h( 'li', 'wpb-em-issue wpb-em-issue--' + issue.level );
			var details = h( 'details' );
			var summary = h( 'summary', 'wpb-em-issue__sum' );
			var main = h( 'span', 'wpb-em-issue__main' );

			main.appendChild( levelBadge( issue.level ) );
			main.appendChild( h( 'span', 'wpb-em-issue__msg', issue.message ) );

			var meta = h( 'span', 'wpb-em-issue__meta' );

			meta.appendChild( h( 'span', 'wpb-em-issue__src', issue.source.name ) );

			if ( issue.file ) {
				meta.appendChild( h( 'span', 'wpb-em-issue__file', issue.file + ( issue.line ? ':' + issue.line : '' ) ) );
			}

			var seen = h( 'span', '', ago( issue.last ) );

			seen.title = issue.lastLabel;
			meta.appendChild( seen );
			main.appendChild( meta );

			var count = h( 'span', 'wpb-em-issue__count' );

			count.appendChild( h( 'strong', '', '×' + num( row.count ) ) );

			summary.appendChild( main );
			summary.appendChild( count );
			details.appendChild( summary );

			var list = h( 'dl', 'wpb-em-issue__dl' );
			var add = function ( term, value, className ) {
				list.appendChild( h( 'dt', '', term ) );
				list.appendChild( h( 'dd', className || '', value ) );
			};

			add( t.message, ( issue.type ? issue.type + ': ' : '' ) + issue.message, 'wpb-em-issue__full' );

			if ( issue.file ) {
				add( t.file, issue.file + ( issue.line ? ':' + issue.line : '' ), 'wpb-em-mono' );
			}

			add( t.source, issue.source.name + ( t.kinds[ issue.source.kind ] ? ' - ' + t.kinds[ issue.source.kind ] : '' ) );
			add( t.occurrences, fill( t.rangeOfTotal, num( row.count ), num( issue.count ) ) );
			add( t.firstSeen, issue.firstLabel );
			add( t.lastSeen, issue.lastLabel + ' (' + ago( issue.last ) + ')' );
			add( t.logFiles, Object.keys( issue.days ).map( function ( id ) {
				return labels[ id ] || id;
			} ).join( ', ' ), 'wpb-em-mono' );

			details.appendChild( list );
			item.appendChild( details );
			el.issues.appendChild( item );
		} );

		if ( rows.length > state.limit ) {
			var button = h( 'button', 'wpb-btn wpb-btn--ghost', fill( t.showMore, num( Math.min( PAGE, rows.length - state.limit ) ) ) );

			button.type = 'button';
			button.addEventListener( 'click', function () {
				state.limit += PAGE;
				renderIssues( slice().issues );
			} );
			el.more.appendChild( button );
		}

		if ( state.data.issuesTotal > state.data.issues.length ) {
			el.more.appendChild( h( 'p', 'wpb-em-note', fill( t.capped, num( state.data.issues.length ) ) ) );
		}
	}

	function renderFiles() {
		clear( el.files );

		var missing = [];

		state.data.files.forEach( function ( file ) {
			if ( ! file.exists ) {
				missing.push( file.path );
				return;
			}

			var item = h( 'li', 'wpb-em-file' );
			var ic = h( 'span', 'wpb-em-file__ic' );
			var info = h( 'div', 'wpb-em-file__info' );

			ic.appendChild( icon( 'file' ) );
			info.appendChild( h( 'strong', 'wpb-em-file__label', file.label ) );
			info.appendChild( h( 'code', 'wpb-em-file__path', file.path ) );

			var facts = h( 'span', 'wpb-em-file__facts' );

			if ( ! file.readable ) {
				facts.textContent = t.notReadable;
			} else {
				facts.textContent = ( file.size ? file.sizeLabel : t.empty ) +
					( file.modified ? ' · ' + t.updated + ' ' + ago( file.modified ) : '' );
			}

			info.appendChild( facts );

			var chips = h( 'div', 'wpb-em-file__chips' );
			if ( file.shared ) {
				var shared = h( 'span', 'wpb-em-state wpb-em-state--unknown' );

				shared.appendChild( icon( 'server' ) );
				shared.appendChild( document.createTextNode( t.shared ) );
				chips.appendChild( shared );
			}

			if ( file.truncated ) {
				var big = h( 'span', 'wpb-em-state wpb-em-state--unknown' );

				big.appendChild( icon( 'info' ) );
				big.appendChild( document.createTextNode( fill( t.truncated, cfg.tailLabel ) ) );
				chips.appendChild( big );
			}

			info.appendChild( chips );

			var actions = h( 'div', 'wpb-actions wpb-em-file__acts' );

			if ( file.download && file.size ) {
				var download = h( 'a', 'wpb-btn wpb-btn--ghost' );

				download.href = file.download;
				download.appendChild( icon( 'download' ) );
				download.appendChild( document.createTextNode( t.download ) );
				actions.appendChild( download );
			}

			if ( file.writable && file.size ) {
				var clearBtn = h( 'button', 'wpb-btn wpb-btn--quiet' );

				clearBtn.type = 'button';
				clearBtn.setAttribute( 'data-wpb-modal', 'wpb-em-clear-modal' );
				clearBtn.setAttribute( 'data-em-log', file.id );
				clearBtn.appendChild( icon( 'trash' ) );
				clearBtn.appendChild( document.createTextNode( t.clear ) );
				actions.appendChild( clearBtn );
			}

			item.appendChild( ic );
			item.appendChild( info );
			item.appendChild( actions );
			el.files.appendChild( item );
		} );

		if ( missing.length ) {
			var note = h( 'li', 'wpb-em-file__missing' );

			note.appendChild( h( 'span', '', t.alsoChecked + ' ' ) );

			missing.forEach( function ( path, i ) {
				if ( i ) {
					note.appendChild( document.createTextNode( ', ' ) );
				}
				note.appendChild( h( 'code', '', path ) );
			} );

			el.files.appendChild( note );
		}
	}

	/* ------------------------------------------------------------------
	   Controls
	   ------------------------------------------------------------------ */

	function pressSwitch( attr, value ) {
		[].slice.call( panel.querySelectorAll( '[' + attr + ']' ) ).forEach( function ( button ) {
			var on = button.getAttribute( attr ) === String( value );

			button.classList.toggle( 'is-active', on );
			button.setAttribute( 'aria-pressed', on ? 'true' : 'false' );
		} );
	}

	panel.addEventListener( 'click', function ( event ) {
		var range = event.target.closest( '[data-em-range]' );
		var view = event.target.closest( '[data-em-view]' );
		var sort = event.target.closest( '[data-em-sort]' );
		var copy = event.target.closest( '[data-em-copy]' );

		if ( range && state.data ) {
			state.range = parseInt( range.getAttribute( 'data-em-range' ), 10 );
			state.limit = PAGE;
			state.active = -1;
			pressSwitch( 'data-em-range', state.range );
			renderView();
		} else if ( range ) {
			state.range = parseInt( range.getAttribute( 'data-em-range' ), 10 );
			pressSwitch( 'data-em-range', state.range );
		}

		if ( view ) {
			state.view = view.getAttribute( 'data-em-view' );
			pressSwitch( 'data-em-view', state.view );
			el.chart.hidden = state.view !== 'chart';
			el.legend.hidden = state.view !== 'chart';
			el.dayTable.hidden = state.view !== 'table';

			if ( state.view === 'chart' && state.data ) {
				renderChart( slice().days );
			}
		}

		if ( sort && state.data ) {
			state.sort = sort.getAttribute( 'data-em-sort' );
			pressSwitch( 'data-em-sort', state.sort );
			renderIssues( slice().issues );
		}

		if ( copy ) {
			var code = copy.parentNode.querySelector( 'code' );
			var label = copy.querySelector( 'span' );

			if ( code && navigator.clipboard ) {
				navigator.clipboard.writeText( code.textContent ).then( function () {
					label.textContent = t.copied;
					setTimeout( function () {
						label.textContent = t.copy;
					}, 1600 );
				} );
			}
		}
	} );

	el.log.addEventListener( 'change', function () {
		state.log = el.log.value;
		state.limit = PAGE;

		if ( state.data ) {
			renderView();
		}
	} );

	el.refresh.addEventListener( 'click', function () {
		scan();
	} );

	// Clear log: wpb-admin.js opens the modal and tells us which button did.
	var pendingLog = '';

	el.modal.addEventListener( 'wpb:modal-open', function ( event ) {
		var trigger = event.detail.trigger;
		var file = null;

		pendingLog = trigger.getAttribute( 'data-em-log' ) || '';

		state.data.files.forEach( function ( candidate ) {
			if ( candidate.id === pendingLog ) {
				file = candidate;
			}
		} );

		el.modalFile.textContent = file ? file.path : '';
		el.modalShared.hidden = ! ( file && file.shared );
	} );

	el.confirm.addEventListener( 'click', function () {
		if ( ! pendingLog ) {
			return;
		}

		var id = pendingLog;

		pendingLog = '';
		setStatus( t.loading );

		request( 'wccp_free_em_clear', { _ajax_nonce: cfg.clearNonce, log: id } )
			.then( function () {
				scan( t.cleared );
			} )
			.catch( function ( error ) {
				setStatus( error.message || t.failed );
			} );
	} );

	// Redraw the SVG at the new width; everything else is fluid CSS.
	var resizeTimer = null;

	window.addEventListener( 'resize', function () {
		clearTimeout( resizeTimer );
		resizeTimer = setTimeout( function () {
			if ( state.data && state.view === 'chart' && ! el.body.hidden && el.chart.offsetParent !== null ) {
				renderChart( slice().days );
			}
		}, 150 );
	} );

	/* ------------------------------------------------------------------
	   Lazy start: scan the first time the panel is shown.
	   ------------------------------------------------------------------ */

	function onPanel( id ) {
		if ( id !== 'wpb-panel-errors' ) {
			return;
		}

		if ( ! state.data ) {
			scan();
		} else if ( state.view === 'chart' ) {
			// It was drawn while hidden (zero width) or the window changed.
			renderChart( slice().days );
		}
	}

	root.addEventListener( 'wpb:panel', function ( event ) {
		onPanel( event.detail.id );
	} );

	if ( root.getAttribute( 'data-panel' ) ) {
		onPanel( root.getAttribute( 'data-panel' ) );
	}
}() );
