<?php

/**
 * Error Monitor tab.
 *
 * Finds the PHP / WordPress error logs this site writes to, reads the newest
 * part of each one and folds repeated entries into single issues (same level,
 * same message, same file and line), so one broken plugin line that fired
 * 10,000 times shows up as one issue with a count - not 10,000 rows.
 *
 * Everything runs through admin-ajax: opening the settings page never pays for
 * reading logs, the panel asks for a scan the first time it is opened.
 *
 * Security:
 * - every endpoint checks wccp_free_em_can() and its own nonce;
 * - the browser only ever sends a log id, never a path - ids are mapped to
 *   paths server side by wccp_free_em_sources(), so there is nothing to traverse;
 * - log text is attacker controlled (a request URL can end up inside a warning),
 *   so js/error-monitor.js only ever inserts it with textContent.
 */
if (! defined('ABSPATH')) exit; // Exit if accessed directly

// How much of the end of each log file is read. Older lines are ignored, so a
// multi-GB log costs the same as a small one.
define('WCCP_FREE_EM_TAIL_BYTES', MB_IN_BYTES);

// Most issues sent to the browser (the most frequent ones are kept).
define('WCCP_FREE_EM_MAX_GROUPS', 300);

// Longest message kept per issue, in characters.
define('WCCP_FREE_EM_MAX_MESSAGE', 1500);

/**
 * Who may read and clear logs.
 *
 * On multisite the logs belong to the whole network (and a server log may even
 * hold other sites' errors), so a single site's admin is not enough there.
 */
function wccp_free_em_can()
{
	return is_multisite() ? is_super_admin() : current_user_can('manage_options');
}

/**
 * Every location checked for a log, keyed by the id the browser uses.
 *
 * Paths never come from the request: this list is the only way an id turns
 * into a file. Locations resolving to the same file are listed once.
 *
 * @return array id => array('label' => string, 'path' => string)
 */
function wccp_free_em_sources()
{
	$candidates = array();

	// WP_DEBUG_LOG may be true (wp-content/debug.log) or a custom path (WP 5.1+),
	// read the same way wp_debug_mode() does.
	if (defined('WP_DEBUG_LOG') && is_string(WP_DEBUG_LOG) && ! in_array(strtolower(WP_DEBUG_LOG), array('', 'true', '1'), true)) {
		$candidates['wp_debug_custom'] = array(
			'label' => __('WordPress debug log (custom path)', 'wp-content-copy-protector'),
			'path'  => WP_DEBUG_LOG,
		);
	}

	$candidates['wp_debug'] = array(
		'label' => __('WordPress debug log', 'wp-content-copy-protector'),
		'path'  => WP_CONTENT_DIR . '/debug.log',
	);

	// A relative error_log setting means "next to the running script", which
	// the error_log locations below already cover.
	$ini_log = (string) ini_get('error_log');
	if ($ini_log !== '' && $ini_log !== 'syslog' && path_is_absolute($ini_log)) {
		$candidates['php_ini'] = array(
			'label' => __('PHP error log (server setting)', 'wp-content-copy-protector'),
			'path'  => $ini_log,
		);
	}

	$candidates['root'] = array(
		'label' => __('error_log in the site root', 'wp-content-copy-protector'),
		'path'  => ABSPATH . 'error_log',
	);
	$candidates['wp_admin'] = array(
		'label' => __('error_log in wp-admin', 'wp-content-copy-protector'),
		'path'  => ABSPATH . 'wp-admin/error_log',
	);
	$candidates['wp_content'] = array(
		'label' => __('error_log in wp-content', 'wp-content-copy-protector'),
		'path'  => WP_CONTENT_DIR . '/error_log',
	);
	$candidates['root_php'] = array(
		'label' => __('php_errorlog in the site root', 'wp-content-copy-protector'),
		'path'  => ABSPATH . 'php_errorlog',
	);

	$sources = array();
	$seen    = array();

	foreach ($candidates as $id => $candidate) {
		$real = @realpath($candidate['path']);
		$key  = strtolower(wp_normalize_path($real ? $real : $candidate['path']));

		if (isset($seen[$key])) {
			continue;
		}

		$seen[$key]   = true;
		$sources[$id] = $candidate;
	}

	return $sources;
}

/**
 * The site's own timezone, for grouping entries into local days.
 */
function wccp_free_em_site_zone()
{
	if (function_exists('wp_timezone')) {
		return wp_timezone();
	}

	$zone = get_option('timezone_string');
	if ($zone) {
		return new DateTimeZone($zone);
	}

	$offset  = (float) get_option('gmt_offset');
	$hours   = (int) $offset;
	$minutes = abs(($offset - $hours) * 60);

	return new DateTimeZone(sprintf('%+03d:%02d', $hours, $minutes));
}

/**
 * Format a timestamp in the site's timezone and language.
 */
function wccp_free_em_local_date($timestamp)
{
	$format = get_option('date_format') . ' ' . get_option('time_format');

	if (function_exists('wp_date')) {
		return wp_date($format, $timestamp);
	}

	return date_i18n($format, $timestamp + (int) ((float) get_option('gmt_offset') * HOUR_IN_SECONDS));
}

/**
 * Is $path inside $dir? Case-insensitive, both already normalized.
 */
function wccp_free_em_path_in($path, $dir)
{
	$dir = rtrim($dir, '/') . '/';

	return strncasecmp($path, $dir, strlen($dir)) === 0;
}

/**
 * Read the last $bytes of a file, starting at a whole line.
 *
 * @return array(string $text, bool $truncated)
 */
function wccp_free_em_tail($path, $bytes)
{
	$size = (int) @filesize($path);

	if ($size <= 0) {
		return array('', false);
	}

	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Streaming the end of a possibly huge file, WP_Filesystem cannot seek.
	$handle = @fopen($path, 'rb');

	if (! $handle) {
		return array('', false);
	}

	$start = max(0, $size - $bytes);
	fseek($handle, $start);
	$text = (string) stream_get_contents($handle, $bytes);
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
	fclose($handle);

	$truncated = $start > 0;

	// The first line was cut in half by the seek - drop it.
	if ($truncated) {
		$newline = strpos($text, "\n");
		$text    = $newline === false ? '' : substr($text, $newline + 1);
	}

	return array($text, $truncated);
}

/**
 * Map a PHP error type ("Fatal error", "Warning", ...) to a severity level.
 */
function wccp_free_em_level($type)
{
	$type = strtolower($type);

	if (strpos($type, 'fatal') !== false || strpos($type, 'parse error') !== false) {
		return 'fatal';
	}
	if (strpos($type, 'warning') !== false || strpos($type, 'database error') !== false) {
		return 'warning';
	}
	if (strpos($type, 'notice') !== false || strpos($type, 'strict') !== false) {
		return 'notice';
	}
	if (strpos($type, 'deprecated') !== false) {
		return 'deprecated';
	}

	return 'other';
}

/**
 * Work out which plugin, theme or part of WordPress a file belongs to.
 *
 * @param string $file  Path as written in the log ('' when there is none).
 * @param array  $cache Per-scan cache of directory roots and names.
 * @return array('key' => string, 'kind' => string, 'name' => string)
 */
function wccp_free_em_source_of($file, &$cache)
{
	if (! isset($cache['roots'])) {
		if (! function_exists('get_plugins')) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$names = array();
		foreach (get_plugins() as $plugin_file => $plugin) {
			$names[strtolower(strtok($plugin_file, '/'))] = $plugin['Name'];
		}
		$mu_names = array();
		foreach (get_mu_plugins() as $plugin_file => $plugin) {
			$mu_names[strtolower($plugin_file)] = $plugin['Name'];
		}

		$cache['plugins'] = $names;
		$cache['mu']      = $mu_names;
		$cache['roots']   = array(
			'plugin' => wp_normalize_path(WP_PLUGIN_DIR),
			'mu'     => wp_normalize_path(WPMU_PLUGIN_DIR),
			'theme'  => wp_normalize_path(get_theme_root()),
			'core'   => array(wp_normalize_path(ABSPATH . 'wp-includes'), wp_normalize_path(ABSPATH . 'wp-admin')),
			'site'   => wp_normalize_path(ABSPATH),
		);
	}

	if ($file === '' || (strpos($file, '/') === false && strpos($file, '\\') === false)) {
		return array('key' => 'unknown', 'kind' => 'unknown', 'name' => __('Unknown source', 'wp-content-copy-protector'));
	}

	$path  = wp_normalize_path($file);
	$roots = $cache['roots'];

	foreach (array('plugin', 'mu', 'theme') as $kind) {
		if (! wccp_free_em_path_in($path, $roots[$kind])) {
			continue;
		}

		$slug = strtok(substr($path, strlen(rtrim($roots[$kind], '/')) + 1), '/');
		$low  = strtolower($slug);

		if ($kind === 'plugin') {
			$name = isset($cache['plugins'][$low]) ? $cache['plugins'][$low] : $slug;
		} elseif ($kind === 'mu') {
			$name = isset($cache['mu'][$low]) ? $cache['mu'][$low] : $slug;
		} else {
			$theme = wp_get_theme($slug);
			$name  = $theme->exists() ? $theme->get('Name') : $slug;
		}

		return array('key' => $kind . ':' . $low, 'kind' => $kind, 'name' => $name);
	}

	foreach ($roots['core'] as $core_dir) {
		if (wccp_free_em_path_in($path, $core_dir)) {
			return array('key' => 'core', 'kind' => 'core', 'name' => __('WordPress core', 'wp-content-copy-protector'));
		}
	}

	if (wccp_free_em_path_in($path, $roots['site'])) {
		$relative = substr($path, strlen(rtrim($roots['site'], '/')) + 1);

		// wp-login.php, wp-cron.php, index.php... sit in the root but are core files.
		if (strpos($relative, '/') === false && $relative !== 'wp-config.php' && preg_match('/^(wp-[a-z-]+|index|xmlrpc)\.php$/i', $relative)) {
			return array('key' => 'core', 'kind' => 'core', 'name' => __('WordPress core', 'wp-content-copy-protector'));
		}

		return array('key' => 'site', 'kind' => 'site', 'name' => __('Other site files', 'wp-content-copy-protector'));
	}

	return array('key' => 'external', 'kind' => 'external', 'name' => __('Outside this site', 'wp-content-copy-protector'));
}

/**
 * Parse log text and fold its entries into $groups.
 *
 * An entry starts with "[14-Sep-2026 10:22:11 UTC]"; lines without that
 * prefix (stack traces, multi-line messages) belong to the entry above and
 * are skipped - the first line already carries the message, file and line.
 */
function wccp_free_em_parse($text, $log_id, &$groups, &$cache)
{
	$site_zone = wccp_free_em_site_zone();
	$zones     = array();

	foreach (preg_split('/\r\n|\n|\r/', $text) as $line) {
		if ($line === '' || $line[0] !== '[' || ! preg_match('/^\[([^\]]+)\]\s?(.*)$/', $line, $match)) {
			continue;
		}

		$parts = explode(' ', $match[1], 3);
		if (count($parts) < 2) {
			continue;
		}

		$zone_name = isset($parts[2]) ? $parts[2] : 'UTC';
		if (! isset($zones[$zone_name])) {
			try {
				$zones[$zone_name] = new DateTimeZone($zone_name);
			} catch (Exception $e) {
				$zones[$zone_name] = new DateTimeZone('UTC');
			}
		}

		$date = DateTime::createFromFormat('d-M-Y H:i:s', $parts[0] . ' ' . $parts[1], $zones[$zone_name]);
		if (! $date) {
			continue;
		}

		$message = trim($match[2]);

		// Xdebug's stack trace lines carry their own timestamps.
		if (preg_match('/^PHP\s+(Stack trace:|\d+\.\s)/', $message)) {
			continue;
		}

		if (preg_match('/^PHP\s+([A-Za-z ]+?):\s+(.*)$/', $message, $typed)) {
			$type = $typed[1];
			$body = $typed[2];
		} elseif (strpos($message, 'WordPress database error ') === 0) {
			$type = 'WordPress database error';
			$body = substr($message, strlen('WordPress database error '));
		} else {
			$type = '';
			$body = $message;
		}

		$file     = '';
		$line_no  = 0;
		// Greedy head so the last " in <path>" wins: "... in /a/b.php on line 12" or "... in /a/b.php:12".
		if (preg_match('/^(.*)\s+in\s+(\S.*?)(?:\s+on\s+line\s+|:)(\d+)\.?\s*$/', $body, $located)) {
			$body    = $located[1];
			$file    = $located[2];
			$line_no = (int) $located[3];
		}

		$level = wccp_free_em_level($type);

		// Same cause = same level, message, file and line. Digits are masked so
		// "Undefined offset: 3" and "Undefined offset: 7" on one line are one issue.
		$key = md5($level . '|' . $type . '|' . preg_replace('/\d+/', '#', $body) . '|' . strtolower($file) . '|' . $line_no);

		$timestamp = $date->getTimestamp();
		$date->setTimezone($site_zone);
		$day = $date->format('Y-m-d');

		if (! isset($groups[$key])) {
			$groups[$key] = array(
				'id'      => substr($key, 0, 12),
				'level'   => $level,
				'type'    => $type,
				'message' => '',
				'file'    => $file,
				'line'    => $line_no,
				'source'  => wccp_free_em_source_of($file, $cache),
				'count'   => 0,
				'first'   => $timestamp,
				'last'    => 0,
				'days'    => array(),
			);
		}

		$group = &$groups[$key];

		$group['count']++;
		$group['first'] = min($group['first'], $timestamp);

		// Keep the newest wording (its masked digits may differ).
		if ($timestamp >= $group['last']) {
			$group['last']    = $timestamp;
			$group['message'] = $body;
		}

		if (! isset($group['days'][$log_id][$day])) {
			$group['days'][$log_id][$day] = 0;
		}
		$group['days'][$log_id][$day]++;

		unset($group);
	}
}

/**
 * Show a path relative to the WordPress root when it lives inside it.
 */
function wccp_free_em_display_path($path)
{
	$normalized = wp_normalize_path($path);
	$root       = wp_normalize_path(ABSPATH);

	return wccp_free_em_path_in($normalized, $root) ? substr($normalized, strlen(rtrim($root, '/')) + 1) : $normalized;
}

/**
 * Cut a string to a length, multibyte-safe when mbstring is around.
 */
function wccp_free_em_cut($text, $length)
{
	if (function_exists('mb_substr')) {
		return mb_strlen($text) > $length ? mb_substr($text, 0, $length) . '…' : $text;
	}

	return strlen($text) > $length ? substr($text, 0, $length) . '…' : $text;
}

/**
 * AJAX: scan every log and return files, issues and debug settings.
 */
function wccp_free_em_ajax_scan()
{
	check_ajax_referer('wccp_free_em_scan');

	if (! wccp_free_em_can()) {
		wp_send_json_error(null, 403);
	}

	$files   = array();
	$groups  = array();
	$cache   = array();
	$site    = wp_normalize_path(ABSPATH);

	foreach (wccp_free_em_sources() as $id => $source) {
		$path     = $source['path'];
		$exists   = @is_file($path);
		$readable = $exists && @is_readable($path);
		$size     = $exists ? (int) @filesize($path) : 0;
		$modified = $exists ? (int) @filemtime($path) : 0;

		$file = array(
			'id'        => $id,
			'label'     => $source['label'],
			'path'      => wccp_free_em_display_path($path),
			'name'      => basename($path),
			'exists'    => $exists,
			'readable'  => $readable,
			'writable'  => $exists && @is_writable($path),
			'size'      => $size,
			'sizeLabel' => size_format($size, $size >= KB_IN_BYTES ? 1 : 0),
			'modified'  => $modified,
			'modifiedLabel' => $modified ? wccp_free_em_local_date($modified) : '',
			// A server-wide PHP log outside this site may hold other sites' errors.
			'shared'    => $id === 'php_ini' && ! wccp_free_em_path_in(wp_normalize_path($path), $site),
			'truncated' => $size > WCCP_FREE_EM_TAIL_BYTES,
			'download'  => '',
		);

		if ($readable) {
			$file['download'] = add_query_arg(array(
				'action'   => 'wccp_free_em_download',
				'log'      => $id,
				'_wpnonce' => wp_create_nonce('wccp_free_em_download'),
			), admin_url('admin-ajax.php'));

			list($text) = wccp_free_em_tail($path, WCCP_FREE_EM_TAIL_BYTES);
			wccp_free_em_parse($text, $id, $groups, $cache);
			unset($text);
		}

		$files[] = $file;
	}

	$total = count($groups);

	uasort($groups, function ($a, $b) {
		return $b['count'] - $a['count'];
	});
	$groups = array_slice($groups, 0, WCCP_FREE_EM_MAX_GROUPS);

	$issues = array();
	foreach ($groups as $group) {
		$group['message']   = wccp_free_em_cut($group['message'], WCCP_FREE_EM_MAX_MESSAGE);
		$group['file']      = $group['file'] === '' ? '' : wccp_free_em_display_path($group['file']);
		$group['firstLabel'] = wccp_free_em_local_date($group['first']);
		$group['lastLabel']  = wccp_free_em_local_date($group['last']);
		$issues[]           = $group;
	}

	$now = new DateTime('now', wccp_free_em_site_zone());

	// The admin bar card counts "new problems since you last checked" from here.
	update_user_meta(get_current_user_id(), 'wccp_free_em_seen', time());
	if (function_exists('wccp_free_abi_forget')) {
		wccp_free_abi_forget();
	}

	wp_send_json_success(array(
		'files'       => $files,
		'issues'      => $issues,
		'issuesTotal' => $total,
		'today'       => $now->format('Y-m-d'),
		'now'         => time(),
	));
}
add_action('wp_ajax_wccp_free_em_scan', 'wccp_free_em_ajax_scan');

/**
 * AJAX: empty one log file.
 */
function wccp_free_em_ajax_clear()
{
	check_ajax_referer('wccp_free_em_clear');

	if (! wccp_free_em_can()) {
		wp_send_json_error(null, 403);
	}

	$sources = wccp_free_em_sources();
	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified above.
	$id = isset($_POST['log']) ? sanitize_key(wp_unslash($_POST['log'])) : '';

	if (! isset($sources[$id])) {
		wp_send_json_error(array('message' => __('Unknown log file.', 'wp-content-copy-protector')), 400);
	}

	$path = $sources[$id]['path'];

	if (! @is_file($path) || ! @is_writable($path)) {
		wp_send_json_error(array('message' => __('This log file cannot be changed by WordPress. Ask your host to clear it.', 'wp-content-copy-protector')), 400);
	}

	// Truncate in place rather than delete, so the server keeps its handle
	// and file permissions.
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
	$handle = @fopen($path, 'r+');

	if (! $handle || ! ftruncate($handle, 0)) {
		wp_send_json_error(array('message' => __('The log file could not be cleared.', 'wp-content-copy-protector')), 500);
	}

	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
	fclose($handle);
	clearstatcache(true, $path);

	// The admin bar card's error counts came from this log.
	delete_option('wccp_free_abi_errors');
	if (function_exists('wccp_free_abi_forget')) {
		wccp_free_abi_forget();
	}

	wp_send_json_success();
}
add_action('wp_ajax_wccp_free_em_clear', 'wccp_free_em_ajax_clear');

/**
 * AJAX (GET link): download one log file as it is on disk.
 */
function wccp_free_em_ajax_download()
{
	check_ajax_referer('wccp_free_em_download');

	if (! wccp_free_em_can()) {
		wp_die(esc_html__('You do not have permission to view the error logs.', 'wp-content-copy-protector'), 403);
	}

	$sources = wccp_free_em_sources();
	$id      = isset($_GET['log']) ? sanitize_key(wp_unslash($_GET['log'])) : '';

	if (! isset($sources[$id]) || ! @is_readable($sources[$id]['path'])) {
		wp_die(esc_html__('This log file cannot be read.', 'wp-content-copy-protector'), 404);
	}

	$path     = $sources[$id]['path'];
	$filename = sanitize_file_name(basename($path) . '-' . gmdate('Y-m-d') . '.txt');

	// The plugin opens an output buffer in preventer-index.php - drop it so
	// nothing is prepended to the file.
	while (ob_get_level()) {
		ob_end_clean();
	}

	nocache_headers();
	header('Content-Type: text/plain; charset=utf-8');
	header('Content-Disposition: attachment; filename="' . $filename . '"');
	header('Content-Length: ' . (int) filesize($path));
	header('X-Content-Type-Options: nosniff');

	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
	readfile($path);
	exit;
}
add_action('wp_ajax_wccp_free_em_download', 'wccp_free_em_ajax_download');

/**
 * Load the panel script and styles on the settings page.
 */
function wccp_free_em_enqueue($hook)
{
	if ($hook !== 'toplevel_page_wccpoptionspro' || ! wccp_free_em_can()) {
		return;
	}

	wp_enqueue_style(
		'wccp-error-monitor',
		plugins_url('css/error-monitor.css', WCCP_FREE_PLUGIN_FILE),
		array('wccp-admin'),
		WCCP_FREE_VERSION
	);

	wp_enqueue_script(
		'wccp-error-monitor',
		plugins_url('js/error-monitor.js', WCCP_FREE_PLUGIN_FILE),
		array('wccp-admin'),
		WCCP_FREE_VERSION,
		true
	);

	wp_localize_script('wccp-error-monitor', 'wccpErrorMonitor', array(
		'ajaxUrl'    => admin_url('admin-ajax.php'),
		'scanNonce'  => wp_create_nonce('wccp_free_em_scan'),
		'clearNonce' => wp_create_nonce('wccp_free_em_clear'),
		'locale'     => str_replace('_', '-', get_user_locale()),
		'tailLabel'  => size_format(WCCP_FREE_EM_TAIL_BYTES),
		'i18n'       => array(
			'levels'       => array(
				'fatal'      => __('Fatal error', 'wp-content-copy-protector'),
				'warning'    => __('Warning', 'wp-content-copy-protector'),
				'notice'     => __('Notice', 'wp-content-copy-protector'),
				'deprecated' => __('Deprecated', 'wp-content-copy-protector'),
				'other'      => __('Other message', 'wp-content-copy-protector'),
			),
			'kinds'        => array(
				'plugin'   => __('Plugin', 'wp-content-copy-protector'),
				'mu'       => __('Must-use plugin', 'wp-content-copy-protector'),
				'theme'    => __('Theme', 'wp-content-copy-protector'),
				'core'     => __('WordPress', 'wp-content-copy-protector'),
				'site'     => __('Site files', 'wp-content-copy-protector'),
				'external' => __('Another site on this server', 'wp-content-copy-protector'),
				'unknown'  => __('No file given', 'wp-content-copy-protector'),
			),
			/* translators: %s: number of days. */
			'inLastDays'   => __('in the last %s days', 'wp-content-copy-protector'),
			'loading'      => __('Reading log files…', 'wp-content-copy-protector'),
			'failed'       => __('The logs could not be read. Reload the page and try again.', 'wp-content-copy-protector'),
			'total'        => __('Total', 'wp-content-copy-protector'),
			'day'          => __('Day', 'wp-content-copy-protector'),
			'never'        => __('None', 'wp-content-copy-protector'),
			'noErrors'     => __('No errors logged in this period.', 'wp-content-copy-protector'),
			'noIssues'     => __('Nothing to fix - no errors were logged in this period.', 'wp-content-copy-protector'),
			'noLogs'       => __('No error log was found on this site.', 'wp-content-copy-protector'),
			'noLogsText'   => __('Either nothing has gone wrong yet, or error logging is switched off. The Debug settings card below shows how to switch it on.', 'wp-content-copy-protector'),
			/* translators: %s: chart period, e.g. "in the last 30 days". */
			'chartLabel'   => __('Errors per day %s, stacked by severity. Use the arrow keys to read each day.', 'wp-content-copy-protector'),
			/* translators: %s: number of issues. */
			'showMore'     => __('Show %s more', 'wp-content-copy-protector'),
			/* translators: %s: number of sources. */
			'otherSources' => __('%s other sources', 'wp-content-copy-protector'),
			'issueOne'     => __('1 issue', 'wp-content-copy-protector'),
			/* translators: %s: number of issues (2 or more). */
			'issuesCount'  => __('%s issues', 'wp-content-copy-protector'),
			/* translators: %s: number of fatal errors. */
			'fatalCount'   => __('%s fatal', 'wp-content-copy-protector'),
			/* translators: %s: number of issues shown. */
			'capped'       => __('Only the %s most frequent issues are listed.', 'wp-content-copy-protector'),
			'details'      => __('Details', 'wp-content-copy-protector'),
			'message'      => __('Message', 'wp-content-copy-protector'),
			'file'         => __('File', 'wp-content-copy-protector'),
			'source'       => __('Source', 'wp-content-copy-protector'),
			'firstSeen'    => __('First seen', 'wp-content-copy-protector'),
			'lastSeen'     => __('Last seen', 'wp-content-copy-protector'),
			'occurrences'  => __('Occurrences', 'wp-content-copy-protector'),
			'logFiles'     => __('Log files', 'wp-content-copy-protector'),
			/* translators: 1: occurrences in the chosen period, 2: occurrences in the whole log. */
			'rangeOfTotal' => __('%1$s in this period, %2$s in the log', 'wp-content-copy-protector'),
			'download'     => __('Download', 'wp-content-copy-protector'),
			'clear'        => __('Clear log', 'wp-content-copy-protector'),
			'cleared'      => __('The log file was cleared.', 'wp-content-copy-protector'),
			'updated'      => __('Updated', 'wp-content-copy-protector'),
			'empty'        => __('Empty', 'wp-content-copy-protector'),
			'notReadable'  => __('Found, but WordPress is not allowed to read it', 'wp-content-copy-protector'),
			'alsoChecked'  => __('Also checked, not found:', 'wp-content-copy-protector'),
			/* translators: %s: size, e.g. "1 MB". */
			'truncated'    => __('Large file - only the newest %s is analysed', 'wp-content-copy-protector'),
			'shared'       => __('Server-wide log - may include other sites', 'wp-content-copy-protector'),
			'copy'         => __('Copy', 'wp-content-copy-protector'),
			'copied'       => __('Copied', 'wp-content-copy-protector'),
		),
	));
}
add_action('admin_enqueue_scripts', 'wccp_free_em_enqueue', 20);
