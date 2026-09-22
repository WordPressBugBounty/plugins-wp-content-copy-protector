<?php

/**
 * Admin bar insights: the card that opens when an admin hovers the plugin's
 * admin bar icon inside wp-admin.
 *
 * It is a teaser, not a report: one headline number, a 7 day sparkline, error
 * log counts and two short "stories" that raise a question the Protection
 * Center or the Error Monitor answers. Page titles are never sent - "one page
 * drew 41% of the activity" is meant to be looked up inside.
 *
 * Stories:
 * - every story whose condition holds is built server side, already
 *   translated, with a category (trend, page, type, time, record, reach,
 *   coverage, errors) and a weight;
 * - js/admin-bar-insights.js picks two per opening, least recently shown
 *   first and never two of one category, so a site where the same page always
 *   leads still gets a different angle on every hover;
 * - "pinned" stories (a spike, a fatal error today) are always shown.
 *
 * Speed:
 * - nothing is computed while a page loads except the icon dot, which reads
 *   the same cached count as the admin menu bubble and a stored error snapshot
 *   (rate-limited, see wccp_free_abi_dot());
 * - the card's data comes over admin-ajax on first hover, cached per user for
 *   WCCP_FREE_ABI_CACHE; log files are read at most every WCCP_FREE_ABI_ERR_TTL.
 */
if (! defined('ABSPATH')) exit; // Exit if accessed directly

define('WCCP_FREE_ABI_CACHE', 5 * MINUTE_IN_SECONDS);
define('WCCP_FREE_ABI_ERR_TTL', 10 * MINUTE_IN_SECONDS);

// Icon dot, per admin: opening the plugin page hides it for WCCP_FREE_ABI_DOT_SNOOZE;
// otherwise it shows for at most WCCP_FREE_ABI_DOT_SHOW in every WCCP_FREE_ABI_DOT_EVERY.
define('WCCP_FREE_ABI_DOT_SNOOZE', DAY_IN_SECONDS);
define('WCCP_FREE_ABI_DOT_SHOW', 5 * MINUTE_IN_SECONDS);
define('WCCP_FREE_ABI_DOT_EVERY', HOUR_IN_SECONDS);

/**
 * Can the current screen show the card at all?
 */
function wccp_free_abi_available()
{
	global $wccp_settings;

	if (! is_admin() || ! is_admin_bar_showing() || ! current_user_can('manage_options')) {
		return false;
	}

	if (! isset($wccp_settings['top_bar_icon_btn']) || $wccp_settings['top_bar_icon_btn'] !== 'Visible') {
		return false;
	}

	return wccp_free_activity_unlocked() || wccp_free_em_can();
}

function wccp_free_abi_forget($user = 0)
{
	delete_transient('wccp_fa_abi_' . ($user ? $user : get_current_user_id()));
}

/* ==========================================================================
   Error snapshot
   ========================================================================== */

/**
 * Error counts per local day and a compact list of issues, read from the logs
 * at most every WCCP_FREE_ABI_ERR_TTL and shared by every admin.
 *
 * "New" issues are judged against the time this plugin first saw each issue
 * (wccp_free_abi_err_known), not the first line left in a log tail: in a
 * busy, truncated log an old issue would otherwise look new on every scan.
 */
function wccp_free_abi_errors($fresh = true)
{
	$snapshot = get_option('wccp_free_abi_errors');
	$zone     = wccp_free_em_site_zone();
	$now      = new DateTime('now', $zone);
	$today    = $now->format('Y-m-d');

	if (is_array($snapshot) && (! $fresh || ($snapshot['day'] === $today && $snapshot['time'] > time() - WCCP_FREE_ABI_ERR_TTL))) {
		return $snapshot;
	}

	if (! $fresh) {
		return null;
	}

	$groups    = array();
	$cache     = array();
	$found     = 0;
	$readable  = 0;
	$truncated = false;

	foreach (wccp_free_em_sources() as $id => $source) {
		$path = $source['path'];

		if (! @is_file($path)) {
			continue;
		}
		$found++;

		if (! @is_readable($path)) {
			continue;
		}
		$readable++;

		list($text, $cut) = wccp_free_em_tail($path, WCCP_FREE_EM_TAIL_BYTES);
		$truncated        = $truncated || $cut;
		wccp_free_em_parse($text, $id, $groups, $cache);
		unset($text);
	}

	// Local day => how many days ago (0 = today).
	$day_index = array();
	$cursor    = clone $now;
	for ($i = 0; $i < 8; $i++) {
		$day_index[$cursor->format('Y-m-d')] = $i;
		$cursor->modify('-1 day');
	}

	$days  = array_fill(0, 8, array('total' => 0, 'fatal' => 0));
	$known = get_option('wccp_free_abi_err_known');
	$known = is_array($known) ? $known : array();
	$first = empty($known) && ! get_option('wccp_free_abi_errors');
	$list  = array();

	foreach ($groups as $group) {
		$week = 0;

		foreach ($group['days'] as $log_days) {
			foreach ($log_days as $day => $count) {
				if (! isset($day_index[$day])) {
					continue;
				}
				$i = $day_index[$day];
				$days[$i]['total'] += $count;
				if ($group['level'] === 'fatal') {
					$days[$i]['fatal'] += $count;
				}
				if ($i < 7) {
					$week += $count;
				}
			}
		}

		if (! isset($known[$group['id']])) {
			// On the very first scan a truncated log cannot tell old from new.
			$known[$group['id']] = $first && $truncated ? 0 : $group['first'];
		}

		if ($group['last'] >= time() - 8 * DAY_IN_SECONDS) {
			$list[] = array(
				'id'   => $group['id'],
				'seen' => (int) $known[$group['id']],
				'last' => (int) $group['last'],
				'lv'   => $group['level'],
				'kind' => $group['source']['kind'],
				'src'  => $group['source']['key'],
				'week' => $week,
			);
		}
	}

	usort($list, function ($a, $b) {
		return $b['week'] - $a['week'];
	});

	// Forget issues not logged for a month, so the list cannot grow for ever.
	$keep = array();
	foreach ($groups as $group) {
		if ($group['last'] >= time() - 30 * DAY_IN_SECONDS) {
			$keep[$group['id']] = $known[$group['id']];
		}
	}

	$snapshot = array(
		'time'     => time(),
		'day'      => $today,
		'found'    => $found,
		'readable' => $readable,
		'days'     => $days,
		'groups'   => array_slice($list, 0, WCCP_FREE_EM_MAX_GROUPS),
	);

	update_option('wccp_free_abi_err_known', $keep, false);
	update_option('wccp_free_abi_errors', $snapshot, false);

	return $snapshot;
}

/**
 * Issues first seen by this plugin after $since (or in the last 24 hours).
 */
function wccp_free_abi_new_issues($snapshot, $since)
{
	$since = $since ? $since : time() - DAY_IN_SECONDS;
	$count = 0;

	foreach ($snapshot['groups'] as $group) {
		if ($group['seen'] > $since) {
			$count++;
		}
	}

	return $count;
}

/**
 * Numbers and stories from the error logs.
 *
 * @return array(array|null $tiles, array $stories)
 */
function wccp_free_abi_error_part($user)
{
	if (! wccp_free_em_can()) {
		return array(null, array());
	}

	$snapshot = wccp_free_abi_errors();
	$stories  = array();

	if (! $snapshot['found']) {
		if (! (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG)) {
			$stories[] = wccp_free_abi_story('e_nolog', 'errors', 20, __('Error logging is off - problems on this site go unnoticed', 'wp-content-copy-protector'), 'visibility', 'warn');
		}
		return array(null, $stories);
	}

	if (! $snapshot['readable']) {
		return array(null, array());
	}

	$seen  = (int) get_user_meta($user, 'wccp_free_em_seen', true);
	$days  = $snapshot['days'];
	$new   = wccp_free_abi_new_issues($snapshot, $seen);
	$week  = 0;
	$fatal = 0;

	for ($i = 0; $i < 7; $i++) {
		$week  += $days[$i]['total'];
		$fatal += $days[$i]['fatal'];
	}

	$tiles = array(
		array('label' => __('Errors today', 'wp-content-copy-protector'), 'value' => number_format_i18n($days[0]['total']), 'tone' => $days[0]['total'] ? 'warn' : ''),
		array('label' => __('Fatal, 7 days', 'wp-content-copy-protector'), 'value' => number_format_i18n($fatal), 'tone' => $fatal ? 'alert' : ''),
		array('label' => __('New issues', 'wp-content-copy-protector'), 'value' => number_format_i18n($new), 'tone' => $new ? 'warn' : ''),
	);

	if ($days[0]['fatal'] > 0) {
		/* translators: %s: number of fatal errors. */
		$stories[] = wccp_free_abi_story('e_fatal', 'errors', 100, sprintf(__('Fatal errors logged today: %s', 'wp-content-copy-protector'), number_format_i18n($days[0]['fatal'])), 'warning', 'alert', true);
	}

	if ($new > 0) {
		$text = $seen
			/* translators: %s: number of issues. */
			? sprintf(__('New problems since you last checked the logs: %s', 'wp-content-copy-protector'), number_format_i18n($new))
			/* translators: %s: number of issues. */
			: sprintf(__('New problems in the last 24 hours: %s', 'wp-content-copy-protector'), number_format_i18n($new));
		$stories[] = wccp_free_abi_story('e_new', 'errors', 70, $text, 'flag', 'warn');
	}

	$today     = $days[0]['total'];
	$yesterday = $days[1]['total'];

	if ($yesterday >= 3 && $today >= 2 * $yesterday) {
		/* translators: %s: multiplier, e.g. "2.5". */
		$stories[] = wccp_free_abi_story('e_jump', 'errors', 65, sprintf(__('Errors today are already %s× yesterday\'s', 'wp-content-copy-protector'), wccp_free_abi_ratio($today / $yesterday)), 'chart-line', 'warn');
	} elseif ($yesterday === 0 && $today >= 5) {
		$stories[] = wccp_free_abi_story('e_jump', 'errors', 65, __('Errors appeared today after an error-free yesterday', 'wp-content-copy-protector'), 'chart-line', 'warn');
	}

	if ($week >= 10) {
		$by_source = array();
		$kinds     = array();
		foreach ($snapshot['groups'] as $group) {
			if (! $group['week'] || ! in_array($group['kind'], array('plugin', 'mu', 'theme'), true)) {
				continue;
			}
			$by_source[$group['src']] = (isset($by_source[$group['src']]) ? $by_source[$group['src']] : 0) + $group['week'];
			$kinds[$group['src']]     = $group['kind'];
		}

		if ($by_source) {
			arsort($by_source);
			$source = key($by_source);
			$share  = (int) round(current($by_source) / $week * 100);

			if ($share >= 50) {
				$text = $kinds[$source] === 'theme'
					/* translators: %s: percentage. */
					? sprintf(__('Your theme is behind %s%% of this week\'s errors', 'wp-content-copy-protector'), number_format_i18n($share))
					/* translators: %s: percentage. */
					: sprintf(__('One plugin is behind %s%% of this week\'s errors', 'wp-content-copy-protector'), number_format_i18n($share));
				$stories[] = wccp_free_abi_story('e_source', 'errors', 45, $text, 'admin-plugins', 'warn');
			}
		}
	}

	if (! empty($snapshot['groups'][0]) && $snapshot['groups'][0]['week'] >= 50) {
		/* translators: %s: number of times. */
		$stories[] = wccp_free_abi_story('e_repeat', 'errors', 40, sprintf(__('One error repeated %s times this week', 'wp-content-copy-protector'), number_format_i18n($snapshot['groups'][0]['week'])), 'update', 'warn');
	}

	if ($week === 0) {
		$stories[] = wccp_free_abi_story('e_clean', 'errors', 10, __('No PHP errors logged in the last 7 days', 'wp-content-copy-protector'), 'yes-alt', 'good');
	}

	return array($tiles, $stories);
}

/* ==========================================================================
   Activity
   ========================================================================== */

function wccp_free_abi_story($key, $cat, $weight, $text, $icon, $tone = '', $pin = false)
{
	return array(
		'key'  => $key,
		'cat'  => $cat,
		'w'    => $weight,
		'text' => $text,
		'icon' => $icon,
		'tone' => $tone,
		'pin'  => $pin,
	);
}

/**
 * "2.4" below 10, "12" above.
 */
function wccp_free_abi_ratio($ratio)
{
	return $ratio >= 10 ? number_format_i18n(round($ratio)) : number_format_i18n($ratio, 1);
}

/**
 * Busiest local day before yesterday, and the first day with data.
 * Changes at most once a day, so it is cached until the day ends.
 */
function wccp_free_abi_best_day($today)
{
	$cached = get_transient('wccp_fa_abi_best');

	if (is_array($cached) && $cached['today'] === $today) {
		return $cached;
	}

	global $wpdb;

	$table  = wccp_free_activity_table();
	$offset = wccp_free_activity_offset();
	$before = (int) floor((($today - 1) * DAY_IN_SECONDS - $offset) / HOUR_IN_SECONDS);

	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- Own table.
	$best  = (int) $wpdb->get_var($wpdb->prepare("SELECT SUM(hits) AS h FROM $table WHERE type = 0 AND hour < %d GROUP BY FLOOR((hour * 3600 + %d) / 86400) ORDER BY h DESC LIMIT 1", $before, $offset));
	$first = $wpdb->get_var("SELECT MIN(hour) FROM $table");
	// phpcs:enable

	$cached = array(
		'today' => $today,
		'best'  => $best,
		'first' => $first === null ? $today : (int) floor(((int) $first * HOUR_IN_SECONDS + $offset) / DAY_IN_SECONDS),
	);

	set_transient('wccp_fa_abi_best', $cached, 6 * HOUR_IN_SECONDS);

	return $cached;
}

/**
 * Which coverage row (see wccp_free_activity_coverage()) a stored object id falls in.
 */
function wccp_free_abi_areas($object_ids)
{
	global $wpdb;

	$areas = array();
	$posts = array();

	foreach ($object_ids as $id) {
		if ($id < 1) {
			$areas[$id] = 2;
		} else {
			$posts[] = (int) $id;
		}
	}

	if ($posts) {
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- Integers only.
		$rows = $wpdb->get_results("SELECT ID, post_type FROM $wpdb->posts WHERE ID IN (" . implode(',', $posts) . ')', ARRAY_A);

		foreach ((array) $rows as $row) {
			if ($row['post_type'] === 'post') {
				$areas[(int) $row['ID']] = 0;
			} elseif ($row['post_type'] === 'page') {
				$areas[(int) $row['ID']] = 1;
			}
		}
	}

	return $areas;
}

/**
 * Headline, sparkline and stories from the activity table.
 *
 * @return array(array|null $part, array $stories)
 */
function wccp_free_abi_activity_part($user)
{
	if (! wccp_free_activity_unlocked()) {
		return array(null, array());
	}

	if (! wccp_free_activity_enabled()) {
		return array(array('off' => true), array());
	}

	global $wpdb;

	$today      = wccp_free_activity_today();
	$offset     = wccp_free_activity_offset();
	$days       = WCCP_FREE_ACT_DAYS;
	$start      = $today - $days + 1;
	$prev_start = $start - $days;
	$started    = wccp_free_activity_started();
	$comparable = $started <= $prev_start * DAY_IN_SECONDS - $offset + HOUR_IN_SECONDS;
	$type_keys  = array_flip(wccp_free_activity_types());

	$cur      = wccp_free_activity_blank();
	$prev     = wccp_free_activity_blank();
	$obj_cur  = array();
	$obj_prev = array();
	$day_v    = array_fill($prev_start, $days * 2, 0);

	foreach (wccp_free_activity_rows($prev_start) as $row) {
		$d    = (int) $row['d'];
		$type = (int) $row['type'];
		$id   = (int) $row['object_id'];
		$hits = (int) $row['hits'];

		if (! isset($type_keys[$type]) || $d < $prev_start || $d > $today) {
			continue;
		}

		$key = $type_keys[$type];

		if ($d >= $start) {
			$cur[$key] += $hits;
			if ($key === 'v') {
				$obj_cur[$id] = (isset($obj_cur[$id]) ? $obj_cur[$id] : 0) + $hits;
			}
		} else {
			$prev[$key] += $hits;
			if ($key === 'v') {
				$obj_prev[$id] = (isset($obj_prev[$id]) ? $obj_prev[$id] : 0) + $hits;
			}
		}

		if ($key === 'v') {
			$day_v[$d] += $hits;
		}
	}

	$cur['interactions']  = wccp_free_activity_interactions($cur);
	$prev['interactions'] = wccp_free_activity_interactions($prev);

	// Visits per hour for the last 8 days: today vs the same hours yesterday, busiest hour of day.
	$table     = wccp_free_activity_table();
	$now_hour  = (int) floor(time() / HOUR_IN_SECONDS);
	$day_hour  = (int) floor(($today * DAY_IN_SECONDS - $offset) / HOUR_IN_SECONDS);
	$from_hour = $day_hour - 7 * 24;

	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- Own table.
	$hours = $wpdb->get_results($wpdb->prepare("SELECT hour, SUM(hits) AS hits FROM $table WHERE type = 0 AND hour >= %d GROUP BY hour", $from_hour), ARRAY_A);

	$today_v  = 0;
	$yday_now = 0;
	$of_day   = array_fill(0, 24, 0);

	foreach ((array) $hours as $row) {
		$hour = (int) $row['hour'];
		$hits = (int) $row['hits'];

		if ($hour >= $day_hour) {
			$today_v += $hits;
		} elseif ($hour >= $day_hour - 24 && $hour <= $now_hour - 24) {
			$yday_now += $hits;
		}

		if ($hour >= $day_hour - ($days - 1) * 24) {
			$local = (($hour * HOUR_IN_SECONDS + $offset) % DAY_IN_SECONDS + DAY_IN_SECONDS) % DAY_IN_SECONDS;
			$of_day[(int) floor($local / HOUR_IN_SECONDS)] += $hits;
		}
	}

	/* ---- Headline ---- */

	$seen  = (int) get_user_meta($user, 'wccp_free_activity_seen', true);
	$since = $seen ? wccp_free_activity_since(max($seen, $started)) : null;
	$trend = wccp_free_activity_trend(array('current' => $cur, 'previous' => $prev, 'comparable' => $comparable));
	$fresh = $since && $since['v'] > 0;

	if ($fresh) {
		/* translators: %s: time since the last visit, e.g. "3 days ago". */
		$title = sprintf(__('Since your last visit, %s', 'wp-content-copy-protector'), wccp_free_activity_ago($seen));
		$big   = $since['v'];
		/* translators: 1: number of blocked actions, 2: number of pages. */
		$sub   = sprintf(__('%1$s blocked actions on %2$s pages', 'wp-content-copy-protector'), number_format_i18n($since['interactions']), number_format_i18n($since['pages']));
	} else {
		$title = $seen ? __('Quiet since your last visit', 'wp-content-copy-protector') : __('Last 7 days', 'wp-content-copy-protector');
		$big   = $cur['v'];
		/* translators: 1: number of blocked actions, 2: number of pages. */
		$sub   = sprintf(__('%1$s blocked actions on %2$s pages', 'wp-content-copy-protector'), number_format_i18n($cur['interactions']), number_format_i18n(count($obj_cur)));
	}

	$trend_text = '';
	if ($trend[0] === 'up' || $trend[0] === 'down') {
		/* translators: %s: signed percentage, e.g. "+23". */
		$trend_text = sprintf(__('%s%% vs last week', 'wp-content-copy-protector'), ($trend[0] === 'up' ? '+' : '−') . number_format_i18n($trend[1]));
	}

	$bars = array();
	for ($d = $start; $d <= $today; $d++) {
		$bars[] = array(
			'v'     => $day_v[$d],
			/* translators: 1: day, e.g. "Mon 14 Sep", 2: number of visits. */
			'label' => sprintf(__('%1$s: %2$s', 'wp-content-copy-protector'), date_i18n('D j M', $d * DAY_IN_SECONDS), number_format_i18n($day_v[$d])),
		);
	}

	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- Own table, no input.
	$last_hour = (int) $wpdb->get_var("SELECT MAX(hour) FROM $table");
	$last      = '';
	if ($last_hour) {
		$last = $last_hour >= $now_hour
			? __('Last activity: within the last hour', 'wp-content-copy-protector')
			/* translators: %s: e.g. "3 hours ago". */
			: sprintf(__('Last activity: %s', 'wp-content-copy-protector'), wccp_free_activity_ago(($last_hour + 1) * HOUR_IN_SECONDS));
	}

	$part = array(
		'title' => $title,
		'big'   => number_format_i18n($big),
		'label' => $fresh ? __('visits ran into your protection', 'wp-content-copy-protector') : __('visits with blocked actions, last 7 days', 'wp-content-copy-protector'),
		'sub'   => $sub,
		'trend' => array('state' => $trend[0], 'text' => $trend_text),
		'bars'  => $bars,
		'last'  => $last,
		'fresh' => $fresh,
	);

	/* ---- Stories ---- */

	$stories = array();
	$labels  = wccp_free_activity_labels();

	// Spike: today so far against the average full day of the days before it.
	$past = array();
	for ($d = max($today - $days, (int) floor(($started + $offset) / DAY_IN_SECONDS) + 1); $d < $today; $d++) {
		$past[] = $day_v[$d];
	}
	if (count($past) >= 3) {
		$avg = array_sum($past) / count($past);
		if ($today_v >= 20 && $avg > 0 && $today_v >= 2 * $avg) {
			/* translators: %s: multiplier, e.g. "2.4". */
			$stories[] = wccp_free_abi_story('spike', 'trend', 90, sprintf(__('Today is already at %s× your usual daily activity', 'wp-content-copy-protector'), wccp_free_abi_ratio($today_v / $avg)), 'chart-line', 'hot', true);
		}
	}

	// Today against the same hours yesterday.
	if ($yday_now >= 5 && $today_v >= 5) {
		$pct = (int) round(($today_v - $yday_now) / $yday_now * 100);
		if ($pct >= 20) {
			/* translators: %s: percentage. */
			$stories[] = wccp_free_abi_story('today_up', 'time', 45, sprintf(__('%s%% more activity than yesterday at this hour', 'wp-content-copy-protector'), number_format_i18n($pct)), 'arrow-up-alt');
		} elseif ($pct <= -30) {
			/* translators: %s: percentage. */
			$stories[] = wccp_free_abi_story('today_down', 'time', 15, sprintf(__('%s%% less activity than yesterday at this hour', 'wp-content-copy-protector'), number_format_i18n(abs($pct))), 'arrow-down-alt');
		}
	}

	// Records, within the days actually on record.
	$best     = wccp_free_abi_best_day($today);
	$recorded = min($today - $best['first'] + 1, wccp_free_activity_retention_days());
	if ($recorded >= 14) {
		$yday_v = $day_v[$today - 1];
		if ($today_v >= 10 && $today_v > $best['best'] && $today_v > $yday_v) {
			/* translators: %s: number of days. */
			$stories[] = wccp_free_abi_story('record', 'record', 80, sprintf(__('Today is your busiest day in %s days of records', 'wp-content-copy-protector'), number_format_i18n($recorded)), 'awards', 'hot');
		} elseif ($yday_v >= 10 && $yday_v > $best['best']) {
			/* translators: %s: number of days. */
			$stories[] = wccp_free_abi_story('record', 'record', 75, sprintf(__('Yesterday was your busiest day in %s days of records', 'wp-content-copy-protector'), number_format_i18n($recorded)), 'awards', 'hot');
		}
	}

	// Pages - never named. A page that always leads is not a story; a change is.
	arsort($obj_cur);
	arsort($obj_prev);
	$top_ids = array_keys($obj_cur);

	if ($comparable && $cur['v'] >= 10 && $top_ids) {
		$top      = $top_ids[0];
		$prev_top = $obj_prev ? key($obj_prev) : null;

		if ($prev_top !== null && $prev_top !== $top && $obj_prev[$prev_top] >= 5) {
			$stories[] = wccp_free_abi_story('top_changed', 'page', 60, __('Your most targeted page changed this week', 'wp-content-copy-protector'), 'admin-page');
		}

		$share      = $obj_cur[$top] / $cur['v'];
		$prev_share = $prev['v'] > 0 && isset($obj_prev[$top]) ? $obj_prev[$top] / $prev['v'] : 0;
		if (count($obj_cur) > 1 && $share >= .3 && $prev_share < .2) {
			/* translators: %s: percentage. */
			$stories[] = wccp_free_abi_story('new_dominant', 'page', 40, sprintf(__('One page suddenly drew %s%% of this week\'s activity', 'wp-content-copy-protector'), number_format_i18n(round($share * 100))), 'admin-page');
		}

		if (count($obj_cur) >= 4) {
			foreach (array_slice($top_ids, 0, 3) as $id) {
				if ($obj_cur[$id] >= 5 && empty($obj_prev[$id])) {
					$stories[] = wccp_free_abi_story('newcomer', 'page', 55, __('A page with no activity last week is now in your top 3', 'wp-content-copy-protector'), 'star-filled');
					break;
				}
			}
		}

		$best_ratio = 0;
		foreach (array_slice($top_ids, 1, 50) as $id) {
			if ($obj_cur[$id] >= 10 && ! empty($obj_prev[$id]) && $obj_prev[$id] >= 3) {
				$best_ratio = max($best_ratio, $obj_cur[$id] / $obj_prev[$id]);
			}
		}
		if ($best_ratio >= 2) {
			/* translators: %s: multiplier, e.g. "3". */
			$stories[] = wccp_free_abi_story('riser', 'page', 50, sprintf(__('Activity on one page grew %s× compared to last week', 'wp-content-copy-protector'), wccp_free_abi_ratio($best_ratio)), 'arrow-up-alt');
		}
	}

	// Reach.
	$pages      = count($obj_cur);
	$prev_pages = count($obj_prev);
	if ($comparable && $pages >= 3 && $pages - $prev_pages >= 2 && $pages >= $prev_pages * 1.2) {
		/* translators: 1: number of pages, 2: difference. */
		$stories[] = wccp_free_abi_story('reach_up', 'reach', 35, sprintf(__('Pages with activity this week: %1$s, that is %2$s more than last week', 'wp-content-copy-protector'), number_format_i18n($pages), number_format_i18n($pages - $prev_pages)), 'admin-site-alt3');
	} elseif ($pages >= 5) {
		/* translators: %s: number of pages. */
		$stories[] = wccp_free_abi_story('reach', 'reach', 10, sprintf(__('Activity was spread across %s different pages this week', 'wp-content-copy-protector'), number_format_i18n($pages)), 'admin-site-alt3');
	}

	// What visitors reached for.
	if ($cur['interactions'] >= 10) {
		$types = array();
		foreach ($labels as $key => $label) {
			$types[$key] = $cur[$key];
		}
		arsort($types);
		$lead  = key($types);
		$share = $types[$lead] / $cur['interactions'];

		if ($share >= .5) {
			/* translators: 1: action type, e.g. "Right-clicks", 2: percentage. */
			$stories[] = wccp_free_abi_story('type_lead', 'type', 30, sprintf(__('Most blocked this week: %1$s (%2$s%%)', 'wp-content-copy-protector'), $labels[$lead], number_format_i18n(round($share * 100))), 'hidden');
		}

		$rise     = null;
		$rise_pct = 0;
		foreach ($comparable ? $labels : array() as $key => $label) {
			if ($prev[$key] === 0 && $cur[$key] >= 5) {
				/* translators: %s: action type, e.g. "Print attempts". */
				$stories[] = wccp_free_abi_story('type_new', 'type', 50, sprintf(__('New this week: %s', 'wp-content-copy-protector'), $label), 'star-filled');
			} elseif ($prev[$key] >= 5 && $cur[$key] >= 10) {
				$pct = (int) round(($cur[$key] - $prev[$key]) / $prev[$key] * 100);
				if ($pct >= 50 && $pct > $rise_pct) {
					$rise     = $key;
					$rise_pct = $pct;
				}
			}
		}
		if ($rise) {
			/* translators: 1: action type, e.g. "Image interactions", 2: percentage. */
			$stories[] = wccp_free_abi_story('type_rise', 'type', 40, sprintf(__('%1$s rose %2$s%% compared to last week', 'wp-content-copy-protector'), $labels[$rise], number_format_i18n($rise_pct)), 'arrow-up-alt');
		}

		$user_hint = function ($key) use ($user) {
			return (int) get_user_meta($user, 'wccp_free_activity_hint_' . $key, true) < time();
		};
		if ($cur['i'] >= 10 && $cur['i'] / $cur['interactions'] >= .2 && $user_hint('watermark')) {
			/* translators: %s: number of image interactions. */
			$stories[] = wccp_free_abi_story('pro_images', 'type', 10, sprintf(__('Images received %s protected interactions this week', 'wp-content-copy-protector'), number_format_i18n($cur['i'])), 'format-image');
		}
		if ($cur['k'] >= 10 && $cur['k'] / $cur['interactions'] >= .25 && $user_hint('shortcuts')) {
			/* translators: %s: number of blocked shortcuts. */
			$stories[] = wccp_free_abi_story('pro_keys', 'type', 10, sprintf(__('%s copy shortcuts were blocked this week', 'wp-content-copy-protector'), number_format_i18n($cur['k'])), 'editor-code');
		}
	}

	// Busiest hour of the day.
	if ($cur['v'] >= 30) {
		arsort($of_day);
		$peak = key($of_day);
		if (current($of_day) / $cur['v'] >= .12) {
			/* translators: %s: time of day, e.g. "9:00 pm". */
			$stories[] = wccp_free_abi_story('peak_hour', 'time', 25, sprintf(__('Most activity arrives around %s', 'wp-content-copy-protector'), date_i18n(get_option('time_format'), $peak * HOUR_IN_SECONDS)), 'clock');
		}
	}

	// Activity where a protection layer is switched off.
	if ($obj_cur) {
		$area_v = array(0, 0, 0);
		foreach (wccp_free_abi_areas(array_slice($top_ids, 0, 100)) as $id => $area) {
			$area_v[$area] += $obj_cur[$id];
		}
		foreach (wccp_free_activity_coverage() as $area => $row) {
			if ($area_v[$area] >= 10 && in_array(false, $row['layers'], true)) {
				/* translators: %s: content area, e.g. "Pages". */
				$stories[] = wccp_free_abi_story('coverage', 'coverage', 45, sprintf(__('%s drew activity this week, but not all of their protection layers are on', 'wp-content-copy-protector'), $row['label']), 'shield', 'warn');
				break;
			}
		}
	}

	return array($part, $stories);
}

/* ==========================================================================
   Endpoint and assets
   ========================================================================== */

function wccp_free_abi_payload($user)
{
	list($activity, $activity_stories) = wccp_free_abi_activity_part($user);
	list($errors, $error_stories)      = wccp_free_abi_error_part($user);

	$stories = array_merge($activity_stories, $error_stories);

	if (! $activity && ! $errors && ! $stories) {
		return array('empty' => true);
	}

	$center_url = wccp_free_activity_center_url();
	$errors_url = admin_url('admin.php?page=wccpoptionspro&tab=errors');
	$has_center = $activity && empty($activity['off']);

	return array(
		'activity' => $activity,
		'errors'   => $errors,
		'stories'  => $stories,
		'cta'      => $has_center
			? array('text' => ! empty($activity['fresh']) ? __('See what you missed', 'wp-content-copy-protector') : __('Open Protection Center', 'wp-content-copy-protector'), 'url' => $center_url)
			: array('text' => __('Open Error Monitor', 'wp-content-copy-protector'), 'url' => $errors_url),
		'second'   => $has_center && ($errors || $error_stories) ? array('text' => __('Error Monitor', 'wp-content-copy-protector'), 'url' => $errors_url) : null,
		'offUrl'   => $center_url,
	);
}

function wccp_free_abi_ajax_data()
{
	check_ajax_referer('wccp_free_abi');

	if (! current_user_can('manage_options')) {
		wp_send_json_error(null, 403);
	}

	$user    = get_current_user_id();
	$payload = get_transient('wccp_fa_abi_' . $user);

	if (! is_array($payload)) {
		$payload = wccp_free_abi_payload($user);
		set_transient('wccp_fa_abi_' . $user, $payload, WCCP_FREE_ABI_CACHE);
	}

	wp_send_json_success($payload);
}
add_action('wp_ajax_wccp_free_abi_data', 'wccp_free_abi_ajax_data');

/**
 * Should the icon carry a "something new" dot on this page load?
 *
 * To stay out of the way, per admin:
 * - opening the plugin page hides it for WCCP_FREE_ABI_DOT_SNOOZE
 *   (wccp_free_abi_snooze_dot());
 * - otherwise the first time it shows opens a WCCP_FREE_ABI_DOT_EVERY window,
 *   and it stays only for the first WCCP_FREE_ABI_DOT_SHOW of that window.
 *
 * @return int Seconds the dot may still show (0 = none). The script removes
 *             it when they run out, even if the admin stays on the page.
 */
function wccp_free_abi_dot()
{
	$user = get_current_user_id();
	$now  = time();

	if ((int) get_user_meta($user, 'wccp_free_abi_dot_until', true) > $now || ! wccp_free_abi_has_news()) {
		return 0;
	}

	$start = (int) get_user_meta($user, 'wccp_free_abi_dot_window', true);

	if (! $start || $now - $start >= WCCP_FREE_ABI_DOT_EVERY) {
		$start = $now;
		update_user_meta($user, 'wccp_free_abi_dot_window', $start);
	}

	return max(0, $start + WCCP_FREE_ABI_DOT_SHOW - $now);
}

/**
 * The admin opened the plugin page: no dot for a day, and a fresh hourly window after that.
 */
function wccp_free_abi_snooze_dot()
{
	$user = get_current_user_id();

	update_user_meta($user, 'wccp_free_abi_dot_until', time() + WCCP_FREE_ABI_DOT_SNOOZE);
	delete_user_meta($user, 'wccp_free_abi_dot_window');
}
add_action('load-toplevel_page_wccpoptionspro', 'wccp_free_abi_snooze_dot');

/**
 * New activity or new errors since the admin last looked? Cheap: cached counts only.
 */
function wccp_free_abi_has_news()
{
	$user    = get_current_user_id();
	$options = wccp_free_activity_options();

	if ($options['enabled'] === 'Yes' && $options['badge'] === 'Yes' && wccp_free_activity_unlocked()) {
		$count = get_transient('wccp_fa_badge_' . $user);

		if ($count === false) {
			$seen  = (int) get_user_meta($user, 'wccp_free_activity_seen', true);
			$count = get_option('wccp_free_activity_db') ? wccp_free_activity_since(max($seen, wccp_free_activity_started()))['v'] : 0;
			set_transient('wccp_fa_badge_' . $user, $count, 15 * MINUTE_IN_SECONDS);
		}

		if ((int) $count > 0) {
			return true;
		}
	}

	if (wccp_free_em_can()) {
		$snapshot = wccp_free_abi_errors(false);
		$seen     = (int) get_user_meta($user, 'wccp_free_em_seen', true);

		if (is_array($snapshot) && $snapshot['day'] === (new DateTime('now', wccp_free_em_site_zone()))->format('Y-m-d')) {
			foreach ($snapshot['groups'] as $group) {
				if (($group['lv'] === 'fatal' && $group['last'] > $seen) || ($seen && $group['seen'] > $seen)) {
					return true;
				}
			}
		}
	}

	return false;
}

function wccp_free_abi_enqueue($hook)
{
	// The plugin page itself is where the card would send you.
	if ($hook === 'toplevel_page_wccpoptionspro' || ! wccp_free_abi_available()) {
		return;
	}

	wp_enqueue_style(
		'wccp-admin-bar-insights',
		plugins_url('css/admin-bar-insights.css', WCCP_FREE_PLUGIN_FILE),
		array('dashicons'),
		WCCP_FREE_VERSION
	);

	wp_enqueue_script(
		'wccp-admin-bar-insights',
		plugins_url('js/admin-bar-insights.js', WCCP_FREE_PLUGIN_FILE),
		array(),
		WCCP_FREE_VERSION,
		true
	);

	wp_localize_script('wccp-admin-bar-insights', 'wccpAbi', array(
		'ajaxUrl' => admin_url('admin-ajax.php'),
		'nonce'   => wp_create_nonce('wccp_free_abi'),
		'dot'     => wccp_free_abi_dot(),
		'i18n'    => array(
			'loading'  => __('Loading your numbers…', 'wp-content-copy-protector'),
			'failed'   => __('The numbers could not be loaded right now.', 'wp-content-copy-protector'),
			'chart'    => __('Visits with blocked actions, last 7 days', 'wp-content-copy-protector'),
			'errors'   => __('Error logs', 'wp-content-copy-protector'),
			'off'      => __('Activity recording is switched off', 'wp-content-copy-protector'),
			'turnOn'   => __('Switch it on', 'wp-content-copy-protector'),
			'newDot'   => __('New activity', 'wp-content-copy-protector'),
		),
	));
}
add_action('admin_enqueue_scripts', 'wccp_free_abi_enqueue', 30);
