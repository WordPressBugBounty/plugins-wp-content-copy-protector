/**
 * Upgrade hint card that points at the plugin's admin bar icon.
 * Data comes from wp_localize_script() as window.wccpUpgradeHint.
 */
(function () {
	'use strict';

	var data = window.wccpUpgradeHint;
	var target = document.getElementById('wp-admin-bar-wccp_free_top_button');
	if (!data || !target) return;

	var card, arrow;

	function isTargetVisible() {
		var rect = target.getBoundingClientRect();
		return rect.width > 0 && rect.height > 0 && window.getComputedStyle(target).display !== 'none';
	}

	function position() {
		if (!isTargetVisible()) {
			card.style.visibility = 'hidden';
			return;
		}
		card.style.visibility = '';

		var gap = 12;
		var edge = 12;
		var rect = target.getBoundingClientRect();
		var iconCenter = rect.left + rect.width / 2;
		var width = card.offsetWidth;
		var left = Math.min(
			Math.max(iconCenter - 28, edge),
			window.innerWidth - width - edge
		);
		// Keep the card anchored near the icon's far edge in RTL layouts.
		if (document.documentElement.dir === 'rtl' || document.body.classList.contains('rtl')) {
			left = Math.max(Math.min(iconCenter + 28 - width, window.innerWidth - width - edge), edge);
		}

		card.style.top = (rect.bottom + gap) + 'px';
		card.style.left = left + 'px';
		arrow.style.left = Math.min(Math.max(iconCenter - left, 20), width - 20) + 'px';
	}

	// Hide the card for the next 24 hours (server side, per user).
	function snooze() {
		var body = new URLSearchParams({
			action: 'wccp_free_snooze_upgrade_hint',
			_ajax_nonce: data.nonce
		});
		fetch(data.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body });
	}

	function dismiss() {
		close();
		snooze();
	}

	function close() {
		target.classList.remove('wccp-hint-target');
		card.classList.remove('is-visible');
		window.removeEventListener('resize', position);
		window.removeEventListener('scroll', position);
		document.removeEventListener('keydown', onKey);
		setTimeout(function () { card.remove(); }, 300);
	}

	function onKey(e) {
		if (e.key === 'Escape') dismiss();
	}

	function el(tag, className, text) {
		var node = document.createElement(tag);
		if (className) node.className = className;
		if (text) node.textContent = text;
		return node;
	}

	function build() {
		card = el('div');
		card.id = 'wccp-upgrade-hint';
		card.setAttribute('role', 'dialog');
		card.setAttribute('aria-labelledby', 'wccp-upgrade-hint-title');

		arrow = el('span', 'wccp-hint__arrow');
		arrow.setAttribute('aria-hidden', 'true');

		var closeBtn = el('button', 'wccp-hint__close', '×');
		closeBtn.type = 'button';
		closeBtn.setAttribute('aria-label', data.dismiss);
		closeBtn.addEventListener('click', dismiss);

		var title = el('p', 'wccp-hint__title', data.title);
		title.id = 'wccp-upgrade-hint-title';

		var cta = el('a', 'wccp-hint__cta', data.cta + ' →');
		cta.href = data.url;

		card.appendChild(arrow);
		card.appendChild(closeBtn);
		card.appendChild(el('span', 'wccp-hint__badge', data.badge));
		card.appendChild(title);
		card.appendChild(el('p', 'wccp-hint__text', data.text));
		card.appendChild(cta);
		document.body.appendChild(card);
	}

	function init() {
		// The admin bar hides most items on small screens; nothing to point at.
		if (!isTargetVisible()) return;

		build();
		target.classList.add('wccp-hint-target');
		position();
		window.addEventListener('resize', position);
		window.addEventListener('scroll', position, { passive: true });
		document.addEventListener('keydown', onKey);

		setTimeout(function () { card.classList.add('is-visible'); }, 400);

		// Counts as this user's showing for the next 24 hours.
		snooze();
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
