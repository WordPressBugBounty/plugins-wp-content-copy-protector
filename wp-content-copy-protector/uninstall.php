<?php

/**
 * Removes the Protection Center's activity data when the plugin is deleted.
 * Protection settings are left alone, as they always have been.
 *
 * What goes is the site owner's decision, taken in the Protection Center itself
 * ("Delete this data when the plugin is deleted", off by default):
 *
 * - always: the scheduled events, which have nothing left to run, and the
 *   transients, which are only caches of rows;
 * - only when they asked for it: the activity table, the options and the
 *   per-user meta — the record of what their protection has been doing, which
 *   the PRO edition reads from the same place if it is installed next.
 *
 * Uninstalling is not the same as asking for the data to be thrown away, so the
 * default is to keep it.
 */
if (! defined('WP_UNINSTALL_PLUGIN')) exit;

global $wpdb;

/**
 * Did the owner ask for the recorded activity to go with the plugin?
 *
 * @return bool
 */
function wccp_free_uninstall_purges()
{
	$stored = get_option('wccp_free_activity');

	return is_array($stored) && isset($stored['purge']) && 'Yes' === $stored['purge'];
}

$wccp_free_sites = is_multisite() ? get_sites(array('fields' => 'ids', 'number' => 0)) : array(get_current_blog_id());

foreach ($wccp_free_sites as $wccp_free_site) {
	if (is_multisite()) {
		switch_to_blog($wccp_free_site);
	}

	$wccp_free_purge = wccp_free_uninstall_purges();

	// Runtime leftovers go either way: without the plugin they run nothing and
	// cache nothing.
	wp_clear_scheduled_hook('wccp_free_activity_cleanup');
	wp_clear_scheduled_hook('wccp_free_activity_digest');
	delete_transient('wccp_fa_widget');
	delete_transient('wccp_fa_total');
	delete_transient('wccp_fa_abi_best');

	// The admin-bar insights cache is the plugin's own scratch data, not the
	// owner's record, so it always goes too.
	delete_option('wccp_free_abi_errors');
	delete_option('wccp_free_abi_err_known');

	if ($wccp_free_purge) {
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Own table.
		$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}wccp_activity");

		delete_option('wccp_free_activity');
		delete_option('wccp_free_activity_db');
		delete_option('wccp_free_activity_since');
		delete_option('wccp_free_activity_unlocked');

		delete_metadata('user', 0, 'wccp_free_activity_seen', '', true);
		delete_metadata('user', 0, 'wccp_free_activity_ref', '', true);
		delete_metadata('user', 0, 'wccp_free_activity_notice', '', true);

		foreach (array('watermark', 'per-page', 'shortcuts') as $wccp_free_hint) {
			delete_metadata('user', 0, 'wccp_free_activity_hint_' . $wccp_free_hint, '', true);
		}
	}

	if (is_multisite()) {
		restore_current_blog();
	}
}

// Interface state that records nothing about the site's activity.
delete_metadata('user', 0, 'wccp_free_em_seen', '', true);
delete_metadata('user', 0, 'wccp_free_abi_dot_until', '', true);
delete_metadata('user', 0, 'wccp_free_abi_dot_window', '', true);
