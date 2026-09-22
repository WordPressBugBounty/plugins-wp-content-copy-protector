/**
 * Admin bar insights card (see admin-bar-insights.php).
 *
 * Opens on hover or keyboard focus of the plugin's admin bar icon, loads its
 * numbers once over admin-ajax and shows two stories per opening, picked so
 * that each hover brings a different angle:
 * - pinned stories (a spike, a fatal error today) always come first;
 * - the rest are ranked least recently shown first (weight breaks ties and
 *   brings important stories back sooner), never two of one category.
 *
 * Every text comes from the server already translated and is inserted with
 * textContent only.
 */
( function () {
	'use strict';

	var cfg = window.wccpAbi;
	var item = document.getElementById( 'wp-admin-bar-wccp_free_top_button' );
	var link = item && item.querySelector( '.ab-item' );

	if ( ! cfg || ! link ) {
		return;
	}

	var t = cfg.i18n;
	var STORIES = 2;
	var OPEN_DELAY = 200;
	var CLOSE_DELAY = 300;
	var STORE = 'wccpAbiRotation';

	var card, body, arrow;
	var data = null;
	var request = null;
	var failed = false;
	var isOpen = false;
	var openTimer, closeTimer;
	var small = window.matchMedia( '(max-width: 782px)' );

	/* ------------------------------------------------------------------
	   Helpers
	   ------------------------------------------------------------------ */

	function h( tag, className, text ) {
		var node = document.createElement( tag );

		if ( className ) {
			node.className = className;
		}
		if ( text !== undefined && text !== null && text !== '' ) {
			node.textContent = String( text );
		}

		return node;
	}

	function icon( name ) {
		var node = h( 'span', 'dashicons dashicons-' + name );
		node.setAttribute( 'aria-hidden', 'true' );
		return node;
	}

	function isRtl() {
		return document.documentElement.dir === 'rtl' || document.body.classList.contains( 'rtl' );
	}

	// The upgrade hint card points at the same icon - it goes first.
	function blocked() {
		return small.matches || !! document.getElementById( 'wccp-upgrade-hint' ) || ( data && data.empty );
	}

	/* ------------------------------------------------------------------
	   Story rotation
	   ------------------------------------------------------------------ */

	function readRotation() {
		try {
			var stored = JSON.parse( window.localStorage.getItem( STORE ) );
			if ( stored && typeof stored.n === 'number' && stored.s ) {
				return stored;
			}
		} catch ( e ) {}

		return { n: 0, s: {} };
	}

	function saveRotation( rotation ) {
		try {
			window.localStorage.setItem( STORE, JSON.stringify( rotation ) );
		} catch ( e ) {}
	}

	function pickStories( stories ) {
		var rotation = readRotation();
		var picked = [];
		var cats = {};

		rotation.n++;

		function take( story ) {
			picked.push( story );
			cats[ story.cat ] = true;
		}

		stories.filter( function ( s ) {
			return s.pin;
		} ).sort( function ( a, b ) {
			return b.w - a.w;
		} ).slice( 0, STORIES ).forEach( take );

		var rest = stories.filter( function ( s ) {
			return ! s.pin;
		} ).map( function ( s ) {
			var last = rotation.s[ s.key ];
			var age = typeof last === 'number' ? rotation.n - last : 99;

			// Shown last time: only if nothing else is left.
			return { story: s, score: age <= 1 ? -1000 + s.w : Math.min( age, 8 ) * 10 + s.w };
		} ).sort( function ( a, b ) {
			return b.score - a.score;
		} );

		// First pass keeps categories apart, the second fills what is left.
		[ true, false ].forEach( function ( distinct ) {
			rest.forEach( function ( entry ) {
				if ( picked.length >= STORIES || picked.indexOf( entry.story ) !== -1 ) {
					return;
				}
				if ( distinct && cats[ entry.story.cat ] ) {
					return;
				}
				take( entry.story );
			} );
		} );

		picked.forEach( function ( s ) {
			rotation.s[ s.key ] = rotation.n;
		} );

		// Forget stories that no longer exist.
		var keys = stories.map( function ( s ) {
			return s.key;
		} );
		Object.keys( rotation.s ).forEach( function ( key ) {
			if ( keys.indexOf( key ) === -1 && rotation.n - rotation.s[ key ] > 50 ) {
				delete rotation.s[ key ];
			}
		} );

		saveRotation( rotation );

		return picked;
	}

	/* ------------------------------------------------------------------
	   Data
	   ------------------------------------------------------------------ */

	function load() {
		if ( data || request ) {
			return request;
		}

		failed = false;

		var params = new URLSearchParams( { action: 'wccp_free_abi_data', _ajax_nonce: cfg.nonce } );

		request = window.fetch( cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: params } )
			.then( function ( response ) {
				return response.json();
			} )
			.then( function ( json ) {
				if ( ! json || ! json.success ) {
					throw new Error( 'wccp-abi' );
				}
				data = json.data;
			} )
			.catch( function () {
				failed = true;
			} )
			.then( function () {
				request = null;
				if ( isOpen ) {
					if ( data && data.empty ) {
						close();
					} else {
						render( true );
					}
				}
			} );

		return request;
	}

	/* ------------------------------------------------------------------
	   Rendering
	   ------------------------------------------------------------------ */

	function build() {
		card = h( 'div' );
		card.id = 'wccp-abi';
		card.setAttribute( 'role', 'region' );
		card.setAttribute( 'aria-label', link.textContent.trim() );

		arrow = h( 'span', 'wccp-abi__arrow' );
		arrow.setAttribute( 'aria-hidden', 'true' );

		body = h( 'div', 'wccp-abi__body' );
		body.setAttribute( 'aria-live', 'polite' );

		card.appendChild( arrow );
		card.appendChild( body );
		document.body.appendChild( card );

		card.addEventListener( 'mouseenter', cancelClose );
		card.addEventListener( 'mouseleave', scheduleClose );
		card.addEventListener( 'keydown', onCardKey );
		card.addEventListener( 'focusout', onFocusOut );
	}

	function renderActivity( a ) {
		var wrap = h( 'div', 'wccp-abi__activity' );

		if ( a.off ) {
			var off = h( 'p', 'wccp-abi__off' );
			off.appendChild( icon( 'visibility' ) );
			off.appendChild( h( 'span', '', t.off ) );
			var on = h( 'a', '', t.turnOn );
			on.href = data.offUrl;
			off.appendChild( on );
			wrap.appendChild( off );
			return wrap;
		}

		var head = h( 'p', 'wccp-abi__title' );
		head.appendChild( icon( 'shield' ) );
		head.appendChild( h( 'span', '', a.title ) );
		wrap.appendChild( head );

		var big = h( 'div', 'wccp-abi__big' );
		big.appendChild( h( 'span', 'wccp-abi__num', a.big ) );
		if ( a.trend && a.trend.text ) {
			big.appendChild( h( 'span', 'wccp-abi__trend is-' + a.trend.state, a.trend.text ) );
		}
		wrap.appendChild( big );
		wrap.appendChild( h( 'p', 'wccp-abi__label', a.label ) );

		var max = 0;
		a.bars.forEach( function ( bar ) {
			max = Math.max( max, bar.v );
		} );

		var chart = h( 'div', 'wccp-abi__bars' );
		chart.setAttribute( 'role', 'img' );
		chart.setAttribute( 'aria-label', t.chart + '. ' + a.bars.map( function ( bar ) {
			return bar.label;
		} ).join( ', ' ) );

		a.bars.forEach( function ( bar, i ) {
			var col = h( 'span', 'wccp-abi__bar' + ( i === a.bars.length - 1 ? ' is-today' : '' ) );
			col.style.height = ( max ? Math.max( 6, Math.round( bar.v / max * 100 ) ) : 6 ) + '%';
			col.title = bar.label;
			chart.appendChild( col );
		} );

		wrap.appendChild( chart );
		wrap.appendChild( h( 'p', 'wccp-abi__sub', a.sub ) );

		return wrap;
	}

	function renderErrors( tiles ) {
		var wrap = h( 'div', 'wccp-abi__errors' );
		wrap.setAttribute( 'role', 'group' );
		wrap.setAttribute( 'aria-label', t.errors );

		tiles.forEach( function ( tile ) {
			var box = h( 'div', 'wccp-abi__tile' + ( tile.tone ? ' is-' + tile.tone : '' ) );
			box.appendChild( h( 'span', 'wccp-abi__tile-num', tile.value ) );
			box.appendChild( h( 'span', 'wccp-abi__tile-label', tile.label ) );
			wrap.appendChild( box );
		} );

		return wrap;
	}

	function renderStories( stories ) {
		var list = h( 'ul', 'wccp-abi__stories' );

		stories.forEach( function ( story ) {
			var li = h( 'li', 'wccp-abi__story' + ( story.tone ? ' is-' + story.tone : '' ) );
			li.appendChild( icon( story.icon ) );
			li.appendChild( h( 'span', '', story.text ) );
			list.appendChild( li );
		} );

		return list;
	}

	// newOpening: pick a fresh set of stories (not on a re-render of the same opening).
	function render( newOpening ) {
		body.textContent = '';
		card.classList.remove( 'is-loading' );

		if ( ! data ) {
			if ( failed ) {
				body.appendChild( h( 'p', 'wccp-abi__msg', t.failed ) );
			} else {
				card.classList.add( 'is-loading' );
				body.appendChild( h( 'p', 'wccp-abi__msg', t.loading ) );
				[ 1, 2, 3 ].forEach( function () {
					body.appendChild( h( 'span', 'wccp-abi__skeleton' ) );
				} );
			}
			position();
			return;
		}

		if ( newOpening || ! card.wccpStories ) {
			card.wccpStories = pickStories( data.stories || [] );
		}

		if ( data.activity ) {
			body.appendChild( renderActivity( data.activity ) );
		}
		if ( data.errors ) {
			body.appendChild( renderErrors( data.errors ) );
		}
		if ( card.wccpStories.length ) {
			body.appendChild( renderStories( card.wccpStories ) );
		}

		var foot = h( 'div', 'wccp-abi__foot' );

		if ( data.activity && data.activity.last ) {
			var live = h( 'p', 'wccp-abi__last' );
			live.appendChild( h( 'span', 'wccp-abi__pulse' ) );
			live.appendChild( h( 'span', '', data.activity.last ) );
			foot.appendChild( live );
		}

		var actions = h( 'div', 'wccp-abi__actions' );
		var cta = h( 'a', 'wccp-abi__cta', data.cta.text + ( isRtl() ? ' ←' : ' →' ) );
		cta.href = data.cta.url;
		actions.appendChild( cta );

		if ( data.second ) {
			var second = h( 'a', 'wccp-abi__second', data.second.text );
			second.href = data.second.url;
			actions.appendChild( second );
		}

		foot.appendChild( actions );
		body.appendChild( foot );

		position();
	}

	function position() {
		if ( ! card ) {
			return;
		}

		var gap = 10;
		var edge = 12;
		var rect = link.getBoundingClientRect();
		var width = card.offsetWidth;
		var center = rect.left + rect.width / 2;
		var left = isRtl()
			? Math.max( Math.min( center + 28 - width, window.innerWidth - width - edge ), edge )
			: Math.min( Math.max( center - 28, edge ), window.innerWidth - width - edge );

		card.style.top = ( rect.bottom + gap ) + 'px';
		card.style.left = left + 'px';
		arrow.style.left = Math.min( Math.max( center - left, 20 ), width - 20 ) + 'px';
	}

	/* ------------------------------------------------------------------
	   Open / close
	   ------------------------------------------------------------------ */

	function open() {
		clearTimeout( openTimer );
		cancelClose();

		if ( isOpen || blocked() ) {
			return;
		}

		if ( ! card ) {
			build();
		}

		isOpen = true;
		load();
		render( true );

		link.setAttribute( 'aria-expanded', 'true' );
		item.classList.add( 'wccp-abi-open' );
		card.classList.add( 'is-visible' );

		window.addEventListener( 'resize', position );
		window.addEventListener( 'scroll', position, { passive: true } );
		document.addEventListener( 'keydown', onDocKey );
	}

	function close() {
		clearTimeout( openTimer );
		cancelClose();

		if ( ! isOpen ) {
			return;
		}

		isOpen = false;
		link.setAttribute( 'aria-expanded', 'false' );
		item.classList.remove( 'wccp-abi-open' );
		card.classList.remove( 'is-visible' );

		window.removeEventListener( 'resize', position );
		window.removeEventListener( 'scroll', position );
		document.removeEventListener( 'keydown', onDocKey );
	}

	function scheduleOpen() {
		cancelClose();
		if ( blocked() ) {
			return;
		}
		load();
		clearTimeout( openTimer );
		openTimer = setTimeout( open, OPEN_DELAY );
	}

	function scheduleClose() {
		clearTimeout( openTimer );
		cancelClose();
		closeTimer = setTimeout( close, CLOSE_DELAY );
	}

	function cancelClose() {
		clearTimeout( closeTimer );
	}

	function focusables() {
		return card ? Array.prototype.slice.call( card.querySelectorAll( 'a[href]' ) ) : [];
	}

	function onDocKey( e ) {
		if ( e.key === 'Escape' && isOpen ) {
			var inside = card.contains( document.activeElement );
			close();
			if ( inside || document.activeElement === link ) {
				link.focus();
			}
		}
	}

	// The card lives at the end of <body>: move keyboard focus in and out by hand.
	function onLinkKey( e ) {
		if ( e.key === 'ArrowDown' || ( e.key === 'Tab' && ! e.shiftKey && isOpen ) ) {
			if ( ! isOpen ) {
				open();
			}
			var links = focusables();
			if ( links.length ) {
				e.preventDefault();
				links[ 0 ].focus();
			}
		}
	}

	function onCardKey( e ) {
		if ( e.key !== 'Tab' ) {
			return;
		}

		var links = focusables();
		var index = links.indexOf( document.activeElement );

		if ( ( e.shiftKey && index === 0 ) || ( ! e.shiftKey && index === links.length - 1 ) ) {
			e.preventDefault();
			close();
			link.focus();
		}
	}

	function onFocusOut( e ) {
		var next = e.relatedTarget;
		if ( next && ( card.contains( next ) || item.contains( next ) ) ) {
			return;
		}
		scheduleClose();
	}

	/* ------------------------------------------------------------------
	   Init
	   ------------------------------------------------------------------ */

	// The native tooltip would sit on top of the card.
	link.removeAttribute( 'title' );
	link.setAttribute( 'aria-expanded', 'false' );

	// cfg.dot: seconds the dot may still show in this hour's allowance.
	if ( cfg.dot > 0 ) {
		var dot = h( 'span', 'wccp-abi-dot' );
		dot.setAttribute( 'role', 'img' );
		dot.setAttribute( 'aria-label', t.newDot );
		var abIcon = link.querySelector( '.ab-icon' );
		link.insertBefore( dot, abIcon ? abIcon.nextSibling : link.firstChild );

		setTimeout( function () {
			dot.remove();
		}, cfg.dot * 1000 );
	}

	item.addEventListener( 'mouseenter', scheduleOpen );
	item.addEventListener( 'mouseleave', scheduleClose );
	// Keyboard focus only: a mouse click focuses the link too, then navigates away.
	link.addEventListener( 'focus', function () {
		try {
			if ( link.matches( ':focus-visible' ) ) {
				open();
			}
		} catch ( e ) {}
	} );
	link.addEventListener( 'blur', onFocusOut );
	link.addEventListener( 'keydown', onLinkKey );
	link.addEventListener( 'click', close );
} )();
