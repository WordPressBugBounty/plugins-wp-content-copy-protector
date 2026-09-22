<?php

/**
 * Protection Center - protection activity insights.
 *
 * Counts how often visitors run into the protection that is really active on
 * a page (a blocked right-click, a blocked text selection, a blocked copy
 * shortcut...) and shows the site owner what happened since their last visit.
 *
 * Trust:
 * - no IP address, cookie, user agent or any other personal data is stored -
 *   only counts per page, per action type, per hour;
 * - one page view counts each action type at most WCCP_FREE_ACT_CAP times, so
 *   one visitor hammering the right mouse button cannot inflate the numbers;
 * - logged-in editors and admins are never counted (they test their own site);
 * - everything stays in this site's database and is deleted after
 *   wccp_free_activity_retention_days() days; the owner can switch it off or
 *   wipe it at any time.
 *
 * Speed:
 * - js/activity-tracker.js is a small deferred script with no dependencies;
 * - it sends nothing unless something was blocked, and then at most one
 *   request when the visitor leaves (or hides) the page, via sendBeacon;
 * - each request is one indexed INSERT ... ON DUPLICATE KEY UPDATE into a
 *   compact, pre-aggregated table (hour, page, type) => hits.
 *
 * Security:
 * - the collector has no nonce on purpose: pages are usually served from a
 *   page cache, where a nonce would be stale. Instead every value is
 *   clamped, the page id must be a published, publicly viewable post, bots
 *   are ignored and each network address is rate-limited (through a salted,
 *   daily-rotating hash that lives for minutes, never the address itself);
 * - every admin endpoint checks manage_options and its own nonce.
 */
if (! defined('ABSPATH')) exit; // Exit if accessed directly

define('WCCP_FREE_ACT_DB_VERSION', '1');

// Most times one page view may count the same action type.
define('WCCP_FREE_ACT_CAP', 5);

// Days of history shown in the free version.
define('WCCP_FREE_ACT_DAYS', 7);

// Below this many visits with blocked actions a period is called "quiet".
define('WCCP_FREE_ACT_QUIET', 3);

// Most collector requests one network address may send in 10 minutes.
define('WCCP_FREE_ACT_RATE', 40);

// Recorded visits with blocked actions needed before the Protection Center shows up.
define('WCCP_FREE_ACT_UNLOCK', 500);

/**
 * Action types: short key used by the tracker => stored type id.
 * 'v' is not an action but "a page view that had at least one blocked action".
 */
function wccp_free_activity_types()
{
	return array('v' => 0, 's' => 1, 'r' => 2, 'i' => 3, 'k' => 4, 'p' => 5);
}

function wccp_free_activity_labels()
{
	return array(
		's' => __('Content selections', 'wp-content-copy-protector'),
		'r' => __('Right-clicks', 'wp-content-copy-protector'),
		'i' => __('Image interactions', 'wp-content-copy-protector'),
		'k' => __('Keyboard copy shortcuts', 'wp-content-copy-protector'),
		'p' => __('Print attempts', 'wp-content-copy-protector'),
	);
}

function wccp_free_activity_table()
{
	global $wpdb;
	return $wpdb->prefix . 'wccp_activity';
}

/**
 * How many days of activity are kept on disk.
 * The PRO version (or a site owner) can raise it with the filter.
 */
function wccp_free_activity_retention_days()
{
	return max(WCCP_FREE_ACT_DAYS * 2, (int) apply_filters('wccp_free_activity_retention_days', 90));
}

function wccp_free_activity_options()
{
	$stored = get_option('wccp_free_activity');

	return array_merge(
		array(
			'enabled' => 'Yes',
			'badge'   => 'Yes',
			'digest'  => 'No',
			// Whether deleting the plugin also deletes what it collected. Off by
			// default: the data is the owner's, and uninstalling is not the same
			// as asking for it to be thrown away. Read by uninstall.php.
			'purge'   => 'No',
		),
		is_array($stored) ? $stored : array()
	);
}

function wccp_free_activity_enabled()
{
	$options = wccp_free_activity_options();
	return $options['enabled'] === 'Yes';
}

/**
 * When recording started (or was last reset), as a timestamp.
 */
function wccp_free_activity_started()
{
	$since = (int) get_option('wccp_free_activity_since');
	return $since > 0 ? $since : time();
}

/**
 * Is there enough recorded activity to show the Protection Center?
 *
 * Until WCCP_FREE_ACT_UNLOCK visits with blocked actions are on record the
 * tab, the dashboard widget and the menu count stay hidden - a handful of
 * numbers is not worth the owner's attention. Once reached it stays unlocked,
 * so the cleanup cron or "Delete all data" never makes the tab vanish again.
 */
function wccp_free_activity_unlocked()
{
	if (get_option('wccp_free_activity_unlocked')) {
		return true;
	}

	if (! get_option('wccp_free_activity_db')) {
		return false;
	}

	$total = get_transient('wccp_fa_total');

	if ($total === false) {
		global $wpdb;
		$table = wccp_free_activity_table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- Own table, no input.
		$total = (int) $wpdb->get_var("SELECT SUM(hits) FROM $table WHERE type = 0");
		set_transient('wccp_fa_total', $total, 30 * MINUTE_IN_SECONDS);
	}

	$threshold = (int) apply_filters('wccp_free_activity_unlock_threshold', WCCP_FREE_ACT_UNLOCK);

	if ((int) $total < $threshold) {
		return false;
	}

	update_option('wccp_free_activity_unlocked', time());
	delete_transient('wccp_fa_total');

	return true;
}

/* ==========================================================================
   Install, cleanup, cron
   ========================================================================== */

/**
 * Create or upgrade the table. Cheap to call: one autoloaded option read.
 */
function wccp_free_activity_install()
{
	if (get_option('wccp_free_activity_db') === WCCP_FREE_ACT_DB_VERSION) {
		return;
	}

	global $wpdb;

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';

	$table   = wccp_free_activity_table();
	$collate = $wpdb->get_charset_collate();

	// dbDelta wants two spaces after PRIMARY KEY.
	dbDelta("CREATE TABLE $table (
		hour int(10) unsigned NOT NULL,
		object_id bigint(20) NOT NULL,
		type tinyint(3) unsigned NOT NULL,
		hits int(10) unsigned NOT NULL DEFAULT 0,
		PRIMARY KEY  (hour,object_id,type)
	) $collate;");

	update_option('wccp_free_activity_db', WCCP_FREE_ACT_DB_VERSION);

	if (! get_option('wccp_free_activity_since')) {
		update_option('wccp_free_activity_since', time());
	}
}
add_action('admin_init', 'wccp_free_activity_install', 5);

function wccp_free_activity_schedule()
{
	if (! wp_next_scheduled('wccp_free_activity_cleanup')) {
		wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', 'wccp_free_activity_cleanup');
	}

	$options = wccp_free_activity_options();
	$next    = wp_next_scheduled('wccp_free_activity_digest');

	if ($options['digest'] === 'Yes' && $options['enabled'] === 'Yes') {
		if (! $next) {
			// Monday morning, site time: the week that just ended is complete.
			$monday = new DateTime('next monday 08:00', wccp_free_activity_zone());
			wp_schedule_event($monday->getTimestamp(), 'wccp_free_weekly', 'wccp_free_activity_digest');
		}
	} elseif ($next) {
		wp_clear_scheduled_hook('wccp_free_activity_digest');
	}
}
add_action('admin_init', 'wccp_free_activity_schedule');

// 'weekly' is only built in since WordPress 5.4.
function wccp_free_activity_cron_schedules($schedules)
{
	$schedules['wccp_free_weekly'] = array(
		'interval' => WEEK_IN_SECONDS,
		'display'  => __('Once a week', 'wp-content-copy-protector'),
	);
	return $schedules;
}
add_filter('cron_schedules', 'wccp_free_activity_cron_schedules');

function wccp_free_activity_cleanup()
{
	global $wpdb;

	$oldest = (int) floor(time() / HOUR_IN_SECONDS) - wccp_free_activity_retention_days() * 24;
	$table  = wccp_free_activity_table();

	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- Own table.
	$wpdb->query($wpdb->prepare("DELETE FROM $table WHERE hour < %d", $oldest));
}
add_action('wccp_free_activity_cleanup', 'wccp_free_activity_cleanup');

function wccp_free_activity_deactivate()
{
	wp_clear_scheduled_hook('wccp_free_activity_cleanup');
	wp_clear_scheduled_hook('wccp_free_activity_digest');
}
register_deactivation_hook(WCCP_FREE_PLUGIN_FILE, 'wccp_free_activity_deactivate');

/* ==========================================================================
   Front end: the tracker
   ========================================================================== */

/**
 * Which "page" the current request is, as stored in object_id.
 * Positive: a post id. 0: homepage. Negative: listings without their own post.
 */
function wccp_free_activity_current_object()
{
	if (is_front_page()) {
		return 0;
	}
	if (is_singular()) {
		return (int) get_queried_object_id();
	}
	if (is_home()) {
		return (int) get_option('page_for_posts');
	}
	if (is_search()) {
		return -2;
	}
	if (is_404()) {
		return -3;
	}
	return -1;
}

/**
 * Hooked on wp_enqueue_scripts from preventer-index.php, inside the same
 * page-builder guard as the protection layers themselves.
 */
function wccp_free_activity_tracker()
{
	global $wccp_settings;

	if (! wccp_free_activity_enabled() || is_feed() || is_preview() || is_customize_preview()) {
		return;
	}

	// The site's own team is testing, not copying.
	if (current_user_can('edit_posts')) {
		return;
	}

	$flags = array(
		's' => wccp_free_protects_selection() ? 1 : 0,
		'r' => wccp_free_protects_right_click() ? 1 : 0,
		'c' => wccp_free_protects_css() ? 1 : 0,
		'p' => ! empty($wccp_settings['prnt_scr_msg']) ? 1 : 0,
	);

	// Nothing is blocked here, so there is nothing to count.
	if (! array_sum($flags)) {
		return;
	}

	wp_enqueue_script(
		'wccp-activity-tracker',
		plugins_url('js/activity-tracker.js', WCCP_FREE_PLUGIN_FILE),
		array(),
		WCCP_FREE_VERSION,
		// WordPress 6.3+ reads the strategy; older versions read this as in_footer = true.
		array('in_footer' => true, 'strategy' => 'defer')
	);

	wp_add_inline_script(
		'wccp-activity-tracker',
		'window.wccpActivity=' . wp_json_encode(array(
			'u' => admin_url('admin-ajax.php'),
			'o' => wccp_free_activity_current_object(),
			'f' => $flags,
			'm' => WCCP_FREE_ACT_CAP,
		)) . ';',
		'before'
	);
}

/* ==========================================================================
   Collector
   ========================================================================== */

function wccp_free_activity_is_bot()
{
	$agent = isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])) : '';

	return $agent === '' || preg_match('/bot|crawl|spider|slurp|headless|lighthouse|pagespeed|preview|monitor|curl|wget|python|scrapy/i', $agent);
}

/**
 * Is $object_id something a visitor can really be looking at?
 */
function wccp_free_activity_valid_object($object_id)
{
	if (in_array($object_id, array(0, -1, -2, -3), true)) {
		return true;
	}

	if ($object_id < 1) {
		return false;
	}

	$post = get_post($object_id);

	return $post
		&& $post->post_status === 'publish'
		&& (! function_exists('is_post_type_viewable') || is_post_type_viewable($post->post_type));
}

/**
 * Sliding 10 minute allowance per network address. The address itself is
 * never stored: only a salted hash that changes every day, for 10 minutes.
 */
function wccp_free_activity_rate_ok()
{
	$ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '';

	$key   = 'wccp_fa_rl_' . substr(hash_hmac('md5', $ip . '|' . gmdate('Ymd'), wp_salt('nonce')), 0, 20);
	$count = (int) get_transient($key);

	if ($count >= WCCP_FREE_ACT_RATE) {
		return false;
	}

	set_transient($key, $count + 1, 10 * MINUTE_IN_SECONDS);

	return true;
}

function wccp_free_activity_collect()
{
	// No nonce by design - see the file header. Every input is clamped below.
	// phpcs:disable WordPress.Security.NonceVerification.Missing

	$done = function () {
		status_header(204);
		exit;
	};

	if (! wccp_free_activity_enabled() || current_user_can('edit_posts') || wccp_free_activity_is_bot()) {
		$done();
	}

	$object_id = isset($_POST['o']) ? (int) $_POST['o'] : null;

	if ($object_id === null || ! wccp_free_activity_valid_object($object_id)) {
		$done();
	}

	// One flush may carry at most one visit and WCCP_FREE_ACT_CAP of each action.
	$counts = array();
	foreach (wccp_free_activity_types() as $key => $type) {
		$max           = $key === 'v' ? 1 : WCCP_FREE_ACT_CAP;
		$value         = isset($_POST[$key]) ? (int) $_POST[$key] : 0;
		$counts[$type] = max(0, min($max, $value));
	}
	// phpcs:enable

	$actions = array_sum($counts) - $counts[0];

	if (! $actions || ! wccp_free_activity_rate_ok()) {
		$done();
	}

	wccp_free_activity_install();

	global $wpdb;

	$table  = wccp_free_activity_table();
	$hour   = (int) floor(time() / HOUR_IN_SECONDS);
	$values = array();
	$args   = array();

	foreach ($counts as $type => $hits) {
		if ($hits > 0) {
			$values[] = '(%d,%d,%d,%d)';
			array_push($args, $hour, $object_id, $type, $hits);
		}
	}

	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery -- Own table, placeholders built above.
	$wpdb->query($wpdb->prepare("INSERT INTO $table (hour,object_id,type,hits) VALUES " . implode(',', $values) . ' ON DUPLICATE KEY UPDATE hits = hits + VALUES(hits)', $args));

	$done();
}
add_action('wp_ajax_nopriv_wccp_free_activity', 'wccp_free_activity_collect');
add_action('wp_ajax_wccp_free_activity', 'wccp_free_activity_collect');

/* ==========================================================================
   Reading
   ========================================================================== */

function wccp_free_activity_zone()
{
	return function_exists('wccp_free_em_site_zone') ? wccp_free_em_site_zone() : new DateTimeZone('UTC');
}

/**
 * Seconds between UTC and site time right now.
 * (Days that cross a DST change are off by one hour at their edge - fine for counts.)
 */
function wccp_free_activity_offset()
{
	return wccp_free_activity_zone()->getOffset(new DateTime('now', new DateTimeZone('UTC')));
}

/**
 * Today as a site-local day number (days since 1970-01-01).
 */
function wccp_free_activity_today()
{
	return (int) floor((time() + wccp_free_activity_offset()) / DAY_IN_SECONDS);
}

function wccp_free_activity_blank()
{
	return array('v' => 0, 's' => 0, 'r' => 0, 'i' => 0, 'k' => 0, 'p' => 0);
}

function wccp_free_activity_interactions($row)
{
	return $row['s'] + $row['r'] + $row['i'] + $row['k'] + $row['p'];
}

/**
 * Totals per local day, page and type, from $from_day onwards.
 */
function wccp_free_activity_rows($from_day)
{
	global $wpdb;

	$offset    = wccp_free_activity_offset();
	$from_hour = (int) floor(($from_day * DAY_IN_SECONDS - $offset) / HOUR_IN_SECONDS);
	$table     = wccp_free_activity_table();

	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- Own table.
	$rows = $wpdb->get_results($wpdb->prepare("SELECT FLOOR((hour * 3600 + %d) / 86400) AS d, object_id, type, SUM(hits) AS hits FROM $table WHERE hour >= %d GROUP BY d, object_id, type", $offset, $from_hour), ARRAY_A);

	return is_array($rows) ? $rows : array();
}

/**
 * Everything the Protection Center, the dashboard widget and the weekly email
 * show for the last $days days, plus the $days before them for comparison.
 */
function wccp_free_activity_summary($days = WCCP_FREE_ACT_DAYS)
{
	$type_keys  = array_flip(wccp_free_activity_types());
	$today      = wccp_free_activity_today();
	$start      = $today - $days + 1;
	$prev_start = $start - $days;

	$series = array();
	for ($d = $start; $d <= $today; $d++) {
		$series[$d] = wccp_free_activity_blank() + array('prev' => 0);
	}

	$current  = wccp_free_activity_blank();
	$previous = wccp_free_activity_blank();
	$objects  = array();
	$prev_obj = array();

	foreach (wccp_free_activity_rows($prev_start) as $row) {
		$d    = (int) $row['d'];
		$type = (int) $row['type'];
		$hits = (int) $row['hits'];
		$id   = (int) $row['object_id'];

		if (! isset($type_keys[$type]) || $d < $prev_start || $d > $today) {
			continue;
		}

		$key = $type_keys[$type];

		if ($d >= $start) {
			$series[$d][$key] += $hits;
			$current[$key]    += $hits;

			if (! isset($objects[$id])) {
				$objects[$id] = wccp_free_activity_blank();
			}
			$objects[$id][$key] += $hits;
		} else {
			$previous[$key] += $hits;
			$prev_obj[$id]   = true;

			// Same weekday slot in the previous period, for the chart's ghost bars.
			if ($key === 'v') {
				$series[$d + $days]['prev'] += $hits;
			}
		}
	}

	uasort($objects, function ($a, $b) {
		if ($a['v'] !== $b['v']) {
			return $b['v'] - $a['v'];
		}
		return wccp_free_activity_interactions($b) - wccp_free_activity_interactions($a);
	});

	$top = array();
	foreach (array_slice($objects, 0, 8, true) as $id => $counts) {
		$top[] = array_merge(wccp_free_activity_object($id), $counts, array(
			'id'           => $id,
			'interactions' => wccp_free_activity_interactions($counts),
		));
	}

	$day_rows = array();
	foreach ($series as $d => $row) {
		$day_rows[] = array_merge($row, array(
			'day'   => gmdate('Y-m-d', $d * DAY_IN_SECONDS),
			'label' => date_i18n('D j M', $d * DAY_IN_SECONDS),
			'short' => date_i18n('D', $d * DAY_IN_SECONDS),
		));
	}

	$current['interactions']  = wccp_free_activity_interactions($current);
	$previous['interactions'] = wccp_free_activity_interactions($previous);
	$current['pages']         = count($objects);
	$previous['pages']        = count($prev_obj);

	// A comparison is only fair when recording covered the whole previous period.
	$prev_start_ts = $prev_start * DAY_IN_SECONDS - wccp_free_activity_offset();

	return array(
		'days'       => $days,
		'series'     => $day_rows,
		'current'    => $current,
		'previous'   => $previous,
		'comparable' => wccp_free_activity_started() <= $prev_start_ts + HOUR_IN_SECONDS,
		'top'        => $top,
	);
}

/**
 * Title, link and kind of a stored object id.
 */
function wccp_free_activity_object($id)
{
	$special = array(
		0  => array(__('Homepage', 'wp-content-copy-protector'), home_url('/')),
		-1 => array(__('Archives & listings', 'wp-content-copy-protector'), ''),
		-2 => array(__('Search results', 'wp-content-copy-protector'), ''),
		-3 => array(__('"Not found" pages', 'wp-content-copy-protector'), ''),
	);

	if (isset($special[$id])) {
		return array('title' => $special[$id][0], 'url' => $special[$id][1], 'kind' => __('Site page', 'wp-content-copy-protector'));
	}

	$post = get_post($id);

	if (! $post) {
		/* translators: %d: post id. */
		return array('title' => sprintf(__('Deleted content #%d', 'wp-content-copy-protector'), $id), 'url' => '', 'kind' => '');
	}

	$type  = get_post_type_object($post->post_type);
	$title = wp_strip_all_tags(html_entity_decode(get_the_title($post), ENT_QUOTES, 'UTF-8'));

	return array(
		'title' => $title !== '' ? $title : __('(no title)', 'wp-content-copy-protector'),
		'url'   => get_permalink($post),
		'kind'  => $type ? $type->labels->singular_name : '',
	);
}

/**
 * Totals since a timestamp (hour precision).
 */
function wccp_free_activity_since($timestamp)
{
	global $wpdb;

	$table  = wccp_free_activity_table();
	$hour   = (int) floor($timestamp / HOUR_IN_SECONDS);
	$result = wccp_free_activity_blank();
	$keys   = array_flip(wccp_free_activity_types());

	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- Own table.
	$rows = $wpdb->get_results($wpdb->prepare("SELECT type, SUM(hits) AS hits, COUNT(DISTINCT object_id) AS pages FROM $table WHERE hour >= %d GROUP BY type", $hour), ARRAY_A);

	$result['pages'] = 0;
	foreach ((array) $rows as $row) {
		$type = (int) $row['type'];
		if (isset($keys[$type])) {
			$result[$keys[$type]] = (int) $row['hits'];
		}
		if ($type === 0) {
			$result['pages'] = (int) $row['pages'];
		}
	}
	$result['interactions'] = wccp_free_activity_interactions($result);

	return $result;
}

/**
 * The most recent hour with activity, and its busiest page.
 */
function wccp_free_activity_last()
{
	global $wpdb;

	$table = wccp_free_activity_table();

	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- Own table, no input.
	$row = $wpdb->get_row("SELECT hour, object_id FROM $table ORDER BY hour DESC, hits DESC LIMIT 1", ARRAY_A);

	if (! $row) {
		return null;
	}

	return array_merge(wccp_free_activity_object((int) $row['object_id']), array('time' => (int) $row['hour'] * HOUR_IN_SECONDS));
}

/**
 * Change against the previous period: array(state, percent).
 * state: up | down | flat | none (not enough history to compare honestly).
 */
function wccp_free_activity_trend($summary, $key = 'v')
{
	$now  = $summary['current'][$key];
	$then = $summary['previous'][$key];

	if (! $summary['comparable'] || $then < 5) {
		return array('none', 0);
	}

	$pct = (int) round(($now - $then) / $then * 100);

	if (abs($pct) < 5) {
		return array('flat', $pct);
	}

	return array($pct > 0 ? 'up' : 'down', abs($pct));
}

/**
 * Which published posts and pages the current settings protect.
 */
function wccp_free_activity_coverage()
{
	global $wccp_settings;

	$s     = $wccp_settings;
	$posts = wp_count_posts('post');
	$pages = wp_count_posts('page');

	return array(
		array(
			'label'  => __('Posts', 'wp-content-copy-protector'),
			'count'  => isset($posts->publish) ? (int) $posts->publish : 0,
			'layers' => array(
				'js'  => $s['single_posts_protection'] === 'Enabled',
				'rc'  => $s['right_click_protection_posts'] === 'checked',
				'css' => $s['posts_css_protection'] === 'Enabled',
			),
		),
		array(
			'label'  => __('Pages', 'wp-content-copy-protector'),
			'count'  => isset($pages->publish) ? (int) $pages->publish : 0,
			'layers' => array(
				'js'  => $s['page_protection'] === 'Enabled',
				// preventer-index.php reads the posts checkbox for pages too.
				'rc'  => $s['right_click_protection_posts'] === 'checked',
				'css' => $s['pages_css_protection'] === 'Enabled',
			),
		),
		array(
			'label'  => __('Homepage & archives', 'wp-content-copy-protector'),
			'count'  => -1,
			'layers' => array(
				'js'  => $s['home_page_protection'] === 'Enabled',
				'rc'  => $s['right_click_protection_homepage'] === 'checked',
				'css' => $s['home_css_protection'] === 'Enabled',
			),
		),
	);
}

/**
 * The one contextual PRO suggestion that fits this period's activity, if any.
 */
function wccp_free_activity_hint($summary)
{
	$c     = $summary['current'];
	$total = max(1, $c['interactions']);
	$user  = get_current_user_id();
	$hints = array();

	if ($c['i'] >= 10 && $c['i'] / $total >= .2) {
		$hints[] = array(
			'key'   => 'watermark',
			'icon'  => 'droplet',
			/* translators: %s: number of image interactions. */
			'title' => sprintf(__('Images received %s protected interactions this week', 'wp-content-copy-protector'), number_format_i18n($c['i'])),
			'text'  => __('Your current protection blocks basic image interactions such as right-click and drag. It cannot stop a screenshot. A watermark keeps your name on your images wherever they end up.', 'wp-content-copy-protector'),
			'cta'   => __('Watermark your images with PRO', 'wp-content-copy-protector'),
		);
	}

	if (! empty($summary['top'][0]) && $c['v'] >= 10) {
		$top   = $summary['top'][0];
		$share = $top['v'] / $c['v'];

		if ($share >= .4 && count($summary['top']) > 1) {
			$hints[] = array(
				'key'   => 'per-page',
				'icon'  => 'pointer',
				/* translators: 1: page title, 2: percentage. */
				'title' => sprintf(__('"%1$s" drew %2$s%% of this week\'s activity', 'wp-content-copy-protector'), $top['title'], number_format_i18n(round($share * 100))),
				'text'  => __('In the free version every page gets the same protection. PRO lets you give a single page or post a stronger protection level, straight from the admin bar.', 'wp-content-copy-protector'),
				'cta'   => __('See per-page protection in PRO', 'wp-content-copy-protector'),
			);
		}
	}

	if ($c['k'] >= 10 && $c['k'] / $total >= .25) {
		$hints[] = array(
			'key'   => 'shortcuts',
			'icon'  => 'keyboard',
			/* translators: %s: number of blocked shortcuts. */
			'title' => sprintf(__('%s copy shortcuts were blocked this week', 'wp-content-copy-protector'), number_format_i18n($c['k'])),
			'text'  => __('The free version blocks Ctrl+A, C, X, V, S and U. PRO also covers Ctrl+P, Print Screen and F12 / DevTools, with your own message for each.', 'wp-content-copy-protector'),
			'cta'   => __('See shortcut protection in PRO', 'wp-content-copy-protector'),
		);
	}

	foreach ($hints as $hint) {
		if ((int) get_user_meta($user, 'wccp_free_activity_hint_' . $hint['key'], true) < time()) {
			return $hint;
		}
	}

	return null;
}

/**
 * "Since your last visit" reference point for the current user.
 *
 * Opening the page again within an hour keeps the same reference, so a
 * quick reload does not wipe the comparison down to zero.
 *
 * @param bool $touch Record this visit.
 * @return int Timestamp, 0 on the first visit.
 */
function wccp_free_activity_last_visit($touch)
{
	$user = get_current_user_id();
	$seen = (int) get_user_meta($user, 'wccp_free_activity_seen', true);
	$ref  = (int) get_user_meta($user, 'wccp_free_activity_ref', true);

	if ($seen && time() - $seen >= HOUR_IN_SECONDS) {
		$ref = $seen;
		if ($touch) {
			update_user_meta($user, 'wccp_free_activity_ref', $ref);
		}
	}

	if ($touch) {
		update_user_meta($user, 'wccp_free_activity_seen', time());
		delete_transient('wccp_fa_badge_' . $user);
		delete_transient('wccp_fa_abi_' . $user);
	}

	return $ref;
}

/**
 * Human "3 days ago" in the site language.
 */
function wccp_free_activity_ago($timestamp)
{
	/* translators: %s: time span, e.g. "3 days". */
	return sprintf(__('%s ago', 'wp-content-copy-protector'), human_time_diff($timestamp, time()));
}

/* ==========================================================================
   Admin menu count
   ========================================================================== */

/**
 * "New activity" count bubble on the plugin's admin menu item: visits with
 * blocked actions since the current admin last opened the Protection Center.
 */
function wccp_free_activity_menu_badge()
{
	global $menu;

	$options = wccp_free_activity_options();

	if ($options['enabled'] !== 'Yes' || $options['badge'] !== 'Yes' || ! current_user_can('manage_options') || ! is_array($menu) || ! wccp_free_activity_unlocked()) {
		return;
	}

	// Opening the Protection Center is what clears the count.
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen check.
	if (isset($_GET['page']) && $_GET['page'] === 'wccpoptionspro') {
		return;
	}

	$user  = get_current_user_id();
	$cache = get_transient('wccp_fa_badge_' . $user);

	if ($cache === false) {
		$seen  = (int) get_user_meta($user, 'wccp_free_activity_seen', true);
		$since = max($seen, wccp_free_activity_started());
		$cache = get_option('wccp_free_activity_db') ? wccp_free_activity_since($since)['v'] : 0;
		set_transient('wccp_fa_badge_' . $user, $cache, 15 * MINUTE_IN_SECONDS);
	}

	$count = (int) $cache;

	if ($count < 1) {
		return;
	}

	foreach ($menu as $index => $item) {
		if (isset($item[2]) && $item[2] === 'wccpoptionspro') {
			$menu[$index][0] .= sprintf(
				' <span class="awaiting-mod count-%1$d"><span class="pending-count" aria-hidden="true">%2$s</span><span class="screen-reader-text">%3$s</span></span>',
				$count,
				esc_html($count > 99 ? '99+' : number_format_i18n($count)),
				/* translators: %s: number of visits. */
				esc_html(sprintf(_n('%s new visit with blocked actions', '%s new visits with blocked actions', $count, 'wp-content-copy-protector'), number_format_i18n($count)))
			);
			break;
		}
	}
}
add_action('admin_menu', 'wccp_free_activity_menu_badge', 99);

/* ==========================================================================
   "Protection Center is ready" admin notice
   ========================================================================== */

/**
 * Tells the site owner, once, that enough activity was recorded for the
 * Protection Center to open. It goes away for good when they dismiss it or
 * open the plugin page (see wccp_free_activity_notice_seen()).
 */
function wccp_free_activity_notice()
{
	if (! current_user_can('manage_options') || ! wccp_free_activity_unlocked()) {
		return;
	}

	$user = get_current_user_id();

	if (get_user_meta($user, 'wccp_free_activity_notice', true)) {
		return;
	}

	// The plugin page itself already shows the Protection Center.
	$screen = function_exists('get_current_screen') ? get_current_screen() : null;
	if ($screen && $screen->id === 'toplevel_page_wccpoptionspro') {
		return;
	}

	$total = get_transient('wccp_fa_total');
	?>
	<div class="notice notice-info is-dismissible" id="wccp-fa-notice">
		<p><strong><?php esc_html_e('WP Content Copy Protection: your Protection Center is ready', 'wp-content-copy-protector'); ?></strong></p>
		<p>
			<?php
			echo esc_html(sprintf(
				/* translators: %s: number of visits. */
				__('More than %s visits have already run into your copy protection. See which pages draw the most copy attempts, what visitors tried to do and how it changes week by week.', 'wp-content-copy-protector'),
				number_format_i18n(max((int) $total, (int) apply_filters('wccp_free_activity_unlock_threshold', WCCP_FREE_ACT_UNLOCK)))
			));
			?>
		</p>
		<p><a class="button button-primary" href="<?php echo esc_url(wccp_free_activity_center_url()); ?>"><?php esc_html_e('Open Protection Center', 'wp-content-copy-protector'); ?></a></p>
	</div>
	<script>
		( function () {
			document.addEventListener( 'click', function ( event ) {
				if ( ! event.target.closest || ! event.target.closest( '#wccp-fa-notice .notice-dismiss' ) ) {
					return;
				}

				var body = new FormData();
				body.append( 'action', 'wccp_free_activity_dismiss_notice' );
				body.append( '_ajax_nonce', <?php echo wp_json_encode(wp_create_nonce('wccp_free_activity_notice')); ?> );

				window.fetch( <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>, { method: 'POST', credentials: 'same-origin', body: body } );
			} );
		} )();
	</script>
	<?php
}
add_action('admin_notices', 'wccp_free_activity_notice');

/**
 * The owner has seen the Protection Center (or dismissed the notice).
 */
function wccp_free_activity_notice_seen()
{
	update_user_meta(get_current_user_id(), 'wccp_free_activity_notice', time());
}

function wccp_free_activity_ajax_dismiss_notice()
{
	wccp_free_activity_ajax_guard('wccp_free_activity_notice');
	wccp_free_activity_notice_seen();
	wp_send_json_success();
}
add_action('wp_ajax_wccp_free_activity_dismiss_notice', 'wccp_free_activity_ajax_dismiss_notice');

/* ==========================================================================
   Dashboard widget
   ========================================================================== */

function wccp_free_activity_center_url()
{
	return admin_url('admin.php?page=wccpoptionspro&tab=center');
}

function wccp_free_activity_dashboard_setup()
{
	if (! current_user_can('manage_options') || ! wccp_free_activity_enabled() || ! wccp_free_activity_unlocked()) {
		return;
	}

	wp_add_dashboard_widget(
		'wccp_free_activity_widget',
		__('Content protection activity', 'wp-content-copy-protector'),
		'wccp_free_activity_dashboard_widget'
	);
}
add_action('wp_dashboard_setup', 'wccp_free_activity_dashboard_setup');

function wccp_free_activity_dashboard_widget()
{
	$summary = get_transient('wccp_fa_widget');

	if (! is_array($summary)) {
		$summary = wccp_free_activity_summary();
		set_transient('wccp_fa_widget', $summary, 30 * MINUTE_IN_SECONDS);
	}

	$c     = $summary['current'];
	$trend = wccp_free_activity_trend($summary);
	?>
	<style>
		.wccp-fa-w__big{display:flex;align-items:baseline;gap:8px;flex-wrap:wrap;margin:4px 0 2px}
		.wccp-fa-w__num{font-size:28px;font-weight:700;line-height:1.1;color:#0f172a}
		.wccp-fa-w__trend{font-size:12px;font-weight:600;color:#50575e}
		.wccp-fa-w__sub{margin:0 0 12px;color:#50575e}
		.wccp-fa-w__list{margin:0 0 12px;border-top:1px solid #f0f0f1}
		.wccp-fa-w__list li{display:flex;justify-content:space-between;gap:10px;margin:0;padding:7px 0;border-bottom:1px solid #f0f0f1}
		.wccp-fa-w__list span:first-child{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
		.wccp-fa-w__list b{font-variant-numeric:tabular-nums}
	</style>
	<?php if ($c['v'] < 1) : ?>
		<p><span class="dashicons dashicons-shield" style="color:#00a32a"></span> <?php esc_html_e('Protection is on. No blocked copy-related actions in the last 7 days.', 'wp-content-copy-protector'); ?></p>
	<?php else : ?>
		<div class="wccp-fa-w__big">
			<span class="wccp-fa-w__num"><?php echo esc_html(number_format_i18n($c['v'])); ?></span>
			<span><?php esc_html_e('visits with blocked actions, last 7 days', 'wp-content-copy-protector'); ?></span>
		</div>
		<p class="wccp-fa-w__sub">
			<?php
			/* translators: 1: number of interactions, 2: number of pages. */
			echo esc_html(sprintf(__('%1$s blocked interactions on %2$s pages', 'wp-content-copy-protector'), number_format_i18n($c['interactions']), number_format_i18n($c['pages'])));
			if ($trend[0] === 'up' || $trend[0] === 'down') {
				echo ' &middot; <span class="wccp-fa-w__trend">';
				/* translators: %s: percentage. */
				echo esc_html(sprintf($trend[0] === 'up' ? __('%s%% more than the week before', 'wp-content-copy-protector') : __('%s%% less than the week before', 'wp-content-copy-protector'), number_format_i18n($trend[1])));
				echo '</span>';
			}
			?>
		</p>
		<ul class="wccp-fa-w__list">
			<?php foreach (array_slice($summary['top'], 0, 3) as $item) : ?>
				<li><span><?php echo esc_html($item['title']); ?></span><b><?php echo esc_html(number_format_i18n($item['v'])); ?></b></li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>
	<p style="margin:0"><a class="button button-primary" href="<?php echo esc_url(wccp_free_activity_center_url()); ?>"><?php esc_html_e('Open Protection Center', 'wp-content-copy-protector'); ?></a></p>
	<?php
}

/* ==========================================================================
   Weekly email
   ========================================================================== */

/**
 * @param string $to    Recipient.
 * @param bool   $force Send even in a week without activity (test email).
 */
function wccp_free_activity_send_digest($to = '', $force = false)
{
	if (! $force && ! wccp_free_activity_enabled()) {
		return false;
	}

	$summary = wccp_free_activity_summary();
	$c       = $summary['current'];

	// Only weeks with activity are worth an email.
	if (! $force && $c['v'] < 1) {
		return false;
	}

	$to     = $to ? $to : get_option('admin_email');
	$site   = wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);
	$trend  = wccp_free_activity_trend($summary);
	$labels = wccp_free_activity_labels();

	$subject = sprintf(
		/* translators: 1: site name, 2: number of visits. */
		_n('[%1$s] Content protection this week: %2$s visit with blocked copy actions', '[%1$s] Content protection this week: %2$s visits with blocked copy actions', $c['v'], 'wp-content-copy-protector'),
		$site,
		number_format_i18n($c['v'])
	);

	$trend_text = '';
	if ($trend[0] === 'up' || $trend[0] === 'down') {
		/* translators: %s: percentage. */
		$trend_text = sprintf($trend[0] === 'up' ? __('%s%% more than the week before', 'wp-content-copy-protector') : __('%s%% less than the week before', 'wp-content-copy-protector'), number_format_i18n($trend[1]));
	}

	ob_start();
	?>
	<div style="font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Arial,sans-serif;max-width:560px;margin:0 auto;color:#0b1220;font-size:14px;line-height:1.6">
		<p style="margin:0 0 4px;color:#6c63ff;font-size:12px;font-weight:700;letter-spacing:.08em;text-transform:uppercase"><?php esc_html_e('Protection activity - last 7 days', 'wp-content-copy-protector'); ?></p>
		<p style="margin:0;font-size:34px;font-weight:700;line-height:1.15;color:#0f172a"><?php echo esc_html(number_format_i18n($c['v'])); ?></p>
		<p style="margin:0 0 4px"><?php esc_html_e('visits ran into your copy protection', 'wp-content-copy-protector'); ?></p>
		<p style="margin:0 0 18px;color:#6b7280">
			<?php
			/* translators: 1: number of interactions, 2: number of pages. */
			echo esc_html(sprintf(__('%1$s blocked interactions on %2$s pages', 'wp-content-copy-protector'), number_format_i18n($c['interactions']), number_format_i18n($c['pages'])));
			echo $trend_text ? ' &middot; ' . esc_html($trend_text) : '';
			?>
		</p>

		<table role="presentation" style="width:100%;border-collapse:collapse;margin:0 0 18px">
			<?php foreach ($labels as $key => $label) : if (! $c[$key] && $key === 'p') continue; ?>
				<tr>
					<td style="padding:7px 0;border-bottom:1px solid #eef0f3"><?php echo esc_html($label); ?></td>
					<td style="padding:7px 0;border-bottom:1px solid #eef0f3;text-align:right;font-weight:700"><?php echo esc_html(number_format_i18n($c[$key])); ?></td>
				</tr>
			<?php endforeach; ?>
		</table>

		<?php if ($summary['top']) : ?>
			<p style="margin:0 0 6px;font-weight:700"><?php esc_html_e('Most interacted-with protected content', 'wp-content-copy-protector'); ?></p>
			<table role="presentation" style="width:100%;border-collapse:collapse;margin:0 0 22px">
				<?php foreach (array_slice($summary['top'], 0, 5) as $item) : ?>
					<tr>
						<td style="padding:7px 0;border-bottom:1px solid #eef0f3"><?php echo esc_html($item['title']); ?></td>
						<td style="padding:7px 0;border-bottom:1px solid #eef0f3;text-align:right;font-weight:700"><?php echo esc_html(number_format_i18n($item['v'])); ?></td>
					</tr>
				<?php endforeach; ?>
			</table>
		<?php endif; ?>

		<p style="margin:0 0 26px"><a href="<?php echo esc_url(wccp_free_activity_center_url()); ?>" style="display:inline-block;padding:10px 18px;border-radius:9px;background:#6c63ff;color:#fff;font-weight:700;text-decoration:none"><?php esc_html_e('Open Protection Center', 'wp-content-copy-protector'); ?></a></p>

		<p style="margin:0;color:#6b7280;font-size:12px"><?php esc_html_e('A blocked action is not necessarily an attempt to steal: it counts a visit where someone right-clicked, tried to select or copy, or tried to print protected content. No personal data about your visitors was collected.', 'wp-content-copy-protector'); ?></p>
		<p style="margin:8px 0 0;color:#6b7280;font-size:12px"><?php esc_html_e('You receive this because the weekly summary is switched on in WP Content Copy Protection. Switch it off in the Protection Center.', 'wp-content-copy-protector'); ?></p>
	</div>
	<?php
	$body = ob_get_clean();

	return wp_mail($to, $subject, $body, array('Content-Type: text/html; charset=UTF-8'));
}

function wccp_free_activity_digest_cron()
{
	wccp_free_activity_send_digest();
}
add_action('wccp_free_activity_digest', 'wccp_free_activity_digest_cron');

/* ==========================================================================
   Admin endpoints
   ========================================================================== */

function wccp_free_activity_ajax_guard($action)
{
	check_ajax_referer($action);

	if (! current_user_can('manage_options')) {
		wp_send_json_error(null, 403);
	}
}

function wccp_free_activity_ajax_save()
{
	wccp_free_activity_ajax_guard('wccp_free_activity_admin');

	$options = wccp_free_activity_options();
	$was_on  = $options['enabled'] === 'Yes';

	foreach (array('enabled', 'badge', 'digest', 'purge') as $field) {
		if (isset($_POST[$field])) {
			$options[$field] = sanitize_key(wp_unslash($_POST[$field])) === 'yes' ? 'Yes' : 'No';
		}
	}

	update_option('wccp_free_activity', $options);

	// Recording resumed: numbers before now are not comparable any more.
	if (! $was_on && $options['enabled'] === 'Yes') {
		update_option('wccp_free_activity_since', time());
	}

	wccp_free_activity_schedule();
	delete_transient('wccp_fa_widget');
	delete_transient('wccp_fa_badge_' . get_current_user_id());
	delete_transient('wccp_fa_abi_' . get_current_user_id());
	delete_transient('wccp_fa_abi_best');

	wp_send_json_success($options);
}
add_action('wp_ajax_wccp_free_activity_save', 'wccp_free_activity_ajax_save');

function wccp_free_activity_ajax_purge()
{
	wccp_free_activity_ajax_guard('wccp_free_activity_admin');

	global $wpdb;
	$table = wccp_free_activity_table();

	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- Own table, no input.
	$wpdb->query("DELETE FROM $table");

	update_option('wccp_free_activity_since', time());
	delete_transient('wccp_fa_widget');
	delete_transient('wccp_fa_badge_' . get_current_user_id());
	delete_transient('wccp_fa_abi_' . get_current_user_id());
	delete_transient('wccp_fa_abi_best');

	wp_send_json_success();
}
add_action('wp_ajax_wccp_free_activity_purge', 'wccp_free_activity_ajax_purge');

function wccp_free_activity_ajax_test_digest()
{
	wccp_free_activity_ajax_guard('wccp_free_activity_admin');

	$user = wp_get_current_user();

	if (! wccp_free_activity_send_digest($user->user_email, true)) {
		wp_send_json_error(array('message' => __('WordPress could not send the email. Check that this site can send mail.', 'wp-content-copy-protector')), 500);
	}

	/* translators: %s: email address. */
	wp_send_json_success(array('message' => sprintf(__('A preview was sent to %s.', 'wp-content-copy-protector'), $user->user_email)));
}
add_action('wp_ajax_wccp_free_activity_test_digest', 'wccp_free_activity_ajax_test_digest');

function wccp_free_activity_ajax_hide_hint()
{
	wccp_free_activity_ajax_guard('wccp_free_activity_admin');

	$key = isset($_POST['hint']) ? sanitize_key(wp_unslash($_POST['hint'])) : '';

	if (in_array($key, array('watermark', 'per-page', 'shortcuts'), true)) {
		update_user_meta(get_current_user_id(), 'wccp_free_activity_hint_' . $key, time() + 30 * DAY_IN_SECONDS);
	}

	wp_send_json_success();
}
add_action('wp_ajax_wccp_free_activity_hide_hint', 'wccp_free_activity_ajax_hide_hint');

/* ==========================================================================
   Admin assets
   ========================================================================== */

function wccp_free_activity_enqueue($hook)
{
	if ($hook !== 'toplevel_page_wccpoptionspro' || ! current_user_can('manage_options') || ! wccp_free_activity_unlocked()) {
		return;
	}

	wp_enqueue_style(
		'wccp-activity',
		plugins_url('css/activity.css', WCCP_FREE_PLUGIN_FILE),
		array('wccp-admin'),
		WCCP_FREE_VERSION
	);

	wp_enqueue_script(
		'wccp-activity',
		plugins_url('js/activity.js', WCCP_FREE_PLUGIN_FILE),
		array('wccp-admin'),
		WCCP_FREE_VERSION,
		true
	);
	// The chart data is added by wccp_free_activity_panel() while the page renders.
}
add_action('admin_enqueue_scripts', 'wccp_free_activity_enqueue', 20);
