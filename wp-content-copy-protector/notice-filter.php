<?php

/**
 * Quiet settings page: admin notices from other plugins, themes and core are
 * collected into a hidden drawer on the plugin settings page, behind a
 * "Show hidden notifications" button. Our own notices stay visible.
 *
 * Applies to the settings page only - every other admin page is untouched.
 */
if (! defined('ABSPATH')) exit; // Exit if accessed directly

/**
 * Hook the capture only when the settings page is loading.
 */
function wccp_free_nf_setup()
{
	foreach (array('admin_notices', 'network_admin_notices', 'user_admin_notices') as $hook) {
		add_action($hook, 'wccp_free_nf_start', PHP_INT_MIN);
	}
	add_action('all_admin_notices', 'wccp_free_nf_end', PHP_INT_MAX);
	add_action('admin_footer', 'wccp_free_nf_script');
}
add_action('load-toplevel_page_wccpoptionspro', 'wccp_free_nf_setup');

/**
 * Is this notice callback one of ours (wccp_* function or wccp_* class)?
 */
function wccp_free_nf_is_ours($callback)
{
	if (is_string($callback)) {
		$name = $callback;
	} elseif (is_array($callback) && isset($callback[0])) {
		$name = is_object($callback[0]) ? get_class($callback[0]) : (string) $callback[0];
	} else {
		return false; // Closures and invokables can't be told apart: treat as foreign.
	}

	return stripos(ltrim($name, '\\'), 'wccp') === 0;
}

/**
 * Runs first on the notices hook: set our own callbacks aside so they print
 * normally, then buffer everything else.
 */
function wccp_free_nf_start()
{
	global $wp_filter, $wccp_free_nf_own, $wccp_free_nf_level;

	$wccp_free_nf_own = array();

	foreach (array(current_filter(), 'all_admin_notices') as $hook) {
		if (empty($wp_filter[$hook]) || ! is_object($wp_filter[$hook])) {
			continue;
		}
		foreach ($wp_filter[$hook]->callbacks as $priority => $callbacks) {
			foreach ($callbacks as $callback) {
				// The capture itself is ours too, but must stay hooked to close the buffer.
				if (in_array($callback['function'], array('wccp_free_nf_start', 'wccp_free_nf_end'), true)) {
					continue;
				}
				if (wccp_free_nf_is_ours($callback['function'])) {
					remove_action($hook, $callback['function'], $priority);
					$wccp_free_nf_own[] = $callback['function'];
				}
			}
		}
	}

	ob_start();
	$wccp_free_nf_level = ob_get_level();
}

/**
 * Runs last on all_admin_notices: print our notices, then the drawer with
 * whatever the others printed.
 */
function wccp_free_nf_end()
{
	global $wccp_free_nf_own, $wccp_free_nf_level;

	$html = '';
	// Only close our own buffer, in case a notice callback broke the nesting.
	if ($wccp_free_nf_level && ob_get_level() >= $wccp_free_nf_level) {
		while (ob_get_level() > $wccp_free_nf_level) {
			ob_end_flush();
		}
		$html = ob_get_clean();
	}

	foreach ((array) $wccp_free_nf_own as $callback) {
		call_user_func($callback);
	}

	// Always printed (even empty) so the script can also gather notices
	// that other plugins inject outside the notice hooks.
	?>
	<div id="wccp-nf" class="wccp-nf" hidden>
		<button type="button" class="button wccp-nf__toggle" aria-expanded="false" aria-controls="wccp-nf-list"></button>
		<div id="wccp-nf-list" class="wccp-nf__list" hidden>
			<?php echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Markup printed by other notice callbacks, passed through unchanged. ?>
		</div>
	</div>
	<style>
		.wccp-nf { margin: 12px 20px 0 2px; }
		.wccp-nf[hidden], .wccp-nf__list[hidden] { display: none; }
		.wccp-nf__toggle .dashicons { margin: 3px 4px 0 -2px; font-size: 16px; width: 16px; height: 16px; }
		.wccp-nf__list { margin-top: 8px; }
		.wccp-nf__list > .notice, .wccp-nf__list > .error, .wccp-nf__list > .updated, .wccp-nf__list > .update-nag { margin: 5px 0 10px; }
	</style>
	<?php
}

/**
 * Toggle the drawer, and pull in stray notices added outside the notice hooks.
 */
function wccp_free_nf_script()
{
	$labels = array(
		/* translators: %d: number of hidden admin notifications. */
		'show' => __('Show hidden notifications (%d)', 'wp-content-copy-protector'),
		'hide' => __('Hide notifications', 'wp-content-copy-protector'),
	);
	?>
	<script>
		(function () {
			var wrap = document.getElementById('wccp-nf');
			if (!wrap) return;
			var list = document.getElementById('wccp-nf-list');
			var btn = wrap.querySelector('.wccp-nf__toggle');
			var body = document.getElementById('wpbody-content');
			var labels = <?php echo wp_json_encode($labels); ?>;

			function ours(el) {
				return el.closest('#wpb-admin, #wpccp_subscribe') ||
					/^(wccp|wpccp)/i.test(el.id || '') ||
					/(^|\s)(wccp|wpccp)/i.test(el.className || '');
			}

			// Notices injected straight into the page (not via the notice hooks).
			function gather() {
				if (!body) return;
				Array.prototype.slice.call(body.children).forEach(function (el) {
					if (!el.matches('div.notice, div.error, div.updated, div.update-nag') || ours(el) || el.classList.contains('inline')) return;
					list.appendChild(el);
				});
			}

			function render() {
				var count = 0;
				Array.prototype.forEach.call(list.children, function (el) {
					if (/^(SCRIPT|STYLE|LINK)$/.test(el.tagName) || el.hidden || el.classList.contains('hidden')) return;
					count++;
				});
				if (!count) list.hidden = true; // Start collapsed if notices show up again.

				var open = !list.hidden;
				btn.innerHTML = '<span class="dashicons dashicons-' + (open ? 'hidden' : 'visibility') + '" aria-hidden="true"></span>';
				btn.appendChild(document.createTextNode(open ? labels.hide : labels.show.replace('%d', count)));
				btn.setAttribute('aria-expanded', open ? 'true' : 'false');
				wrap.hidden = !count;
			}

			function update() {
				gather();
				render();
			}

			btn.addEventListener('click', function () {
				list.hidden = !list.hidden;
				render();
			});
			update();

			// This runs before DOM ready, so also catch notices that scripts add
			// later, and keep the count right when one is dismissed. Direct
			// children only: moving a notice can't re-trigger a move.
			if (window.MutationObserver) {
				var observer = new MutationObserver(update);
				if (body) observer.observe(body, { childList: true });
				observer.observe(list, { childList: true });
			}
		})();
	</script>
	<?php
}
