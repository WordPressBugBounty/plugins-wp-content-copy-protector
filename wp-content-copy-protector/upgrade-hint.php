<?php

/**
 * "What's new" hint card pointing at the plugin's admin bar icon.
 *
 * Shown only to sites that upgraded from an older version (never to fresh
 * installs), and only when the new release sets
 * WCCP_FREE_UPGRADE_NEED_ATTENTION to 'Yes' (below).
 *
 * Per admin user:
 * - it shows at most once every 24 hours; dismissing it (× or Esc) also
 *   hides it for 24 hours;
 * - opening the settings page hides it until a later release triggers it.
 */
if (! defined('ABSPATH')) exit; // Exit if accessed directly

// ---------------------------------------------------------------------------
// Release settings: review these on every new version.
// ---------------------------------------------------------------------------

// Yes = users upgrading to this version see the "what's new" hint on the admin bar icon.
// No  = a quiet update, no hint (a hint still pending from an earlier version keeps showing).
define('WCCP_FREE_UPGRADE_NEED_ATTENTION', 'Yes');

// Hint card title and description for this release.
// Add both strings to the .po files so they are translated.
define('WCCP_FREE_UPGRADE_HINT_TITLE', 'Big changes have arrived!');
define('WCCP_FREE_UPGRADE_HINT_DESC', 'The Copy Protection settings panel has been completely redesigned. Click to see what is new and check your settings.');

// TESTING ONLY - keep 'No' in every release.
// Yes = show the hint to every admin on every admin page, ignoring the upgrade
// check, the 24 hour snooze and the "settings page opened" state.
define('WCCP_FREE_HINT_FOR_EVER', 'No');

// ---------------------------------------------------------------------------

define('WCCP_FREE_HINT_SNOOZE', DAY_IN_SECONDS);

/**
 * Track the installed version and flag the hint for upgraded sites.
 *
 * Runs on admin_init priority 1, before wccp_free_Notification (priority 10)
 * creates 'wccp_free_active_time' for brand new installs.
 */
function wccp_free_track_version()
{
	$stored_version = get_option('wccp_free_version');

	if ($stored_version === WCCP_FREE_VERSION) {
		return;
	}

	if ($stored_version === false) {
		// Versions before 4.1 did not store their version, so look for the
		// traces they leave behind to tell an upgrade from a fresh install.
		$is_upgrade = get_option('wccp_settings') !== false
			|| get_site_option('wccp_free_active_time') !== false
			|| get_site_option('wccp_free_review_dismiss') !== false;
	} else {
		$is_upgrade = version_compare($stored_version, WCCP_FREE_VERSION, '<');
	}

	// A quiet release leaves any hint still pending from an earlier release alone.
	if ($is_upgrade && WCCP_FREE_UPGRADE_NEED_ATTENTION === 'Yes') {
		update_option('wccp_free_upgrade_hint', WCCP_FREE_VERSION, false);
	}

	update_option('wccp_free_version', WCCP_FREE_VERSION);
}
add_action('admin_init', 'wccp_free_track_version', 1);

/**
 * Has the current user still not opened the settings since the hint was triggered?
 */
function wccp_free_upgrade_hint_pending()
{
	if (! current_user_can('manage_options')) {
		return false;
	}

	if (WCCP_FREE_HINT_FOR_EVER === 'Yes') {
		return true;
	}

	$hint_version = get_option('wccp_free_upgrade_hint');
	if (! $hint_version) {
		return false;
	}

	return get_user_meta(get_current_user_id(), 'wccp_free_upgrade_hint_seen', true) !== $hint_version;
}

function wccp_free_upgrade_hint_enqueue($hook)
{
	global $wccp_settings;

	if (! wccp_free_upgrade_hint_pending()) {
		return;
	}

	// Opening the settings page is exactly what the hint asks for:
	// hide it until a later release triggers a new one.
	if ($hook === 'toplevel_page_wccpoptionspro') {
		if (WCCP_FREE_HINT_FOR_EVER !== 'Yes') {
			update_user_meta(get_current_user_id(), 'wccp_free_upgrade_hint_seen', get_option('wccp_free_upgrade_hint'));
		}
		return;
	}

	if (! is_admin_bar_showing()) {
		return;
	}

	// Shown or dismissed within the last 24 hours.
	$snoozed_until = (int) get_user_meta(get_current_user_id(), 'wccp_free_upgrade_hint_snooze', true);
	if ($snoozed_until > time() && WCCP_FREE_HINT_FOR_EVER !== 'Yes') {
		return;
	}

	// The card points at the admin bar icon, so it needs the icon to exist.
	if (! isset($wccp_settings['top_bar_icon_btn']) || $wccp_settings['top_bar_icon_btn'] !== 'Visible') {
		return;
	}

	wp_enqueue_style(
		'wccp-upgrade-hint',
		plugins_url('css/upgrade-hint.css', WCCP_FREE_PLUGIN_FILE),
		array(),
		WCCP_FREE_VERSION
	);

	wp_enqueue_script(
		'wccp-upgrade-hint',
		plugins_url('js/upgrade-hint.js', WCCP_FREE_PLUGIN_FILE),
		array(),
		WCCP_FREE_VERSION,
		true
	);

	wp_localize_script('wccp-upgrade-hint', 'wccpUpgradeHint', array(
		'ajaxUrl'  => admin_url('admin-ajax.php'),
		'nonce'    => wp_create_nonce('wccp_free_upgrade_hint'),
		'url'      => admin_url('admin.php?page=wccpoptionspro'),
		'badge'    => __('New in', 'wp-content-copy-protector') . ' ' . get_option('wccp_free_upgrade_hint', WCCP_FREE_VERSION),
		// phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- Text is set per release in the constants above.
		'title'    => __(WCCP_FREE_UPGRADE_HINT_TITLE, 'wp-content-copy-protector'),
		// phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- Text is set per release in the constants above.
		'text'     => __(WCCP_FREE_UPGRADE_HINT_DESC, 'wp-content-copy-protector'),
		'cta'      => __('See & check my settings', 'wp-content-copy-protector'),
		'dismiss'  => __('Dismiss', 'wp-content-copy-protector'),
	));
}
add_action('admin_enqueue_scripts', 'wccp_free_upgrade_hint_enqueue');

/**
 * Called by the card when it is shown and when it is dismissed:
 * starts (or restarts) the 24 hour snooze.
 */
function wccp_free_upgrade_hint_ajax_snooze()
{
	check_ajax_referer('wccp_free_upgrade_hint');

	if (! current_user_can('manage_options')) {
		wp_send_json_error(null, 403);
	}

	update_user_meta(get_current_user_id(), 'wccp_free_upgrade_hint_snooze', time() + WCCP_FREE_HINT_SNOOZE);
	wp_send_json_success();
}
add_action('wp_ajax_wccp_free_snooze_upgrade_hint', 'wccp_free_upgrade_hint_ajax_snooze');
