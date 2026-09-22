<?php

/**
 * Protection Center panel - included by admin-core.php inside the settings form.
 *
 * Rendered server side from wccp_free_activity_summary() so the numbers are on
 * screen with the page; js/activity.js only draws the daily chart and handles
 * the insight settings. Nothing in here posts the settings form: the controls
 * carry no name attribute and every button is type="button".
 *
 * Expects from admin-core.php: $wccp_settings, $wccp_free_pro_url, wccp_free_icon().
 */
if (! defined('ABSPATH')) exit; // Exit if accessed directly

// The owner found the Protection Center: the "it's ready" notice has done its job.
wccp_free_activity_notice_seen();

$wccp_free_ac_options = wccp_free_activity_options();
$wccp_free_ac_on      = $wccp_free_ac_options['enabled'] === 'Yes';
$wccp_free_ac_labels  = wccp_free_activity_labels();
$wccp_free_ac_started = wccp_free_activity_started();
$wccp_free_ac_upgrade = add_query_arg(array('utm_source' => 'plugin', 'utm_medium' => 'protection-center'), $wccp_free_pro_url);

if (! function_exists('wccp_free_ac_num')) {
	function wccp_free_ac_num($value)
	{
		return esc_html(number_format_i18n($value));
	}

	/**
	 * Change against the previous period, as a small chip. Arrows and words,
	 * never colour alone - and no "good/bad" colour: more activity is neither.
	 */
	function wccp_free_ac_trend_chip($trend)
	{
		if ($trend[0] === 'none') {
			return;
		}

		if ($trend[0] === 'flat') {
			echo '<span class="wpb-ac-trend">' . esc_html__('about the same', 'wp-content-copy-protector') . '</span>';
			return;
		}

		printf(
			'<span class="wpb-ac-trend wpb-ac-trend--%1$s"><span aria-hidden="true">%2$s</span> %3$s<span class="screen-reader-text"> %4$s</span></span>',
			esc_attr($trend[0]),
			$trend[0] === 'up' ? '&#9650;' : '&#9660;',
			esc_html(number_format_i18n($trend[1]) . '%'),
			esc_html($trend[0] === 'up' ? __('more than the previous period', 'wp-content-copy-protector') : __('less than the previous period', 'wp-content-copy-protector'))
		);
	}
}
?>
<div class="wpb-panel" id="wpb-panel-center" role="tabpanel" aria-labelledby="wpb-pill-center" tabindex="0">
	<div class="wpb-ac" id="wpb-ac">

		<?php if (! $wccp_free_ac_on) : ?>

			<div class="wpb-card wpb-ac-off">
				<div class="wpb-card__head">
					<span class="wpb-card__ic"><?php wccp_free_icon('eye'); ?></span>
					<h2 class="wpb-card__title"><?php esc_html_e('Protection activity is switched off', 'wp-content-copy-protector'); ?></h2>
				</div>
				<p class="wpb-card__desc"><?php esc_html_e('Switch it on to see how often visitors run into your copy protection, which pages draw the most attention and whether it is your text or your images they reach for. It stores counts only - no personal data.', 'wp-content-copy-protector'); ?></p>
				<div class="wpb-card__foot">
					<span class="wpb-ac-status" data-ac-status role="status" aria-live="polite"></span>
					<button type="button" class="wpb-btn wpb-btn--primary" data-ac-enable><?php wccp_free_icon('eye'); ?><?php esc_html_e('Switch on activity insights', 'wp-content-copy-protector'); ?></button>
				</div>
			</div>

		<?php else :

			$wccp_free_ac      = wccp_free_activity_summary();
			$wccp_free_ac_c    = $wccp_free_ac['current'];
			$wccp_free_ac_ref  = wccp_free_activity_last_visit(true);
			$wccp_free_ac_new  = $wccp_free_ac_ref ? wccp_free_activity_since(max($wccp_free_ac_ref, $wccp_free_ac_started)) : null;
			$wccp_free_ac_tr   = wccp_free_activity_trend($wccp_free_ac);
			$wccp_free_ac_quiet = $wccp_free_ac_c['v'] < WCCP_FREE_ACT_QUIET;
			$wccp_free_ac_young = $wccp_free_ac_started > time() - WCCP_FREE_ACT_DAYS * DAY_IN_SECONDS;
			$wccp_free_ac_hint = $wccp_free_ac_quiet ? null : wccp_free_activity_hint($wccp_free_ac);
			$wccp_free_ac_print = ! empty($wccp_settings['prnt_scr_msg']) || $wccp_free_ac_c['p'] > 0;

			wp_localize_script('wccp-activity', 'wccpActivityData', array(
				'series' => $wccp_free_ac['series'],
				'labels' => $wccp_free_ac_labels,
				'showPrev' => $wccp_free_ac['comparable'],
			));
		?>

			<!-- ============ Headline ============ -->
			<div class="wpb-card wpb-ac-hero">
				<div class="wpb-card__head">
					<span class="wpb-card__ic"><?php wccp_free_icon('eye'); ?></span>
					<h2 class="wpb-card__title"><?php esc_html_e('Protection activity', 'wp-content-copy-protector'); ?></h2>
					<span class="wpb-tag wpb-tag--layer"><?php wccp_free_icon('clock'); ?><?php esc_html_e('Last 7 days', 'wp-content-copy-protector'); ?></span>
					<div class="wpb-switch" role="group" aria-label="<?php esc_attr_e('Time range', 'wp-content-copy-protector'); ?>">
						<button type="button" class="wpb-switch__btn is-active" aria-pressed="true"><?php esc_html_e('7 days', 'wp-content-copy-protector'); ?></button>
						<button type="button" class="wpb-switch__btn wpb-ac-locked" data-ac-locked aria-pressed="false" aria-controls="wpb-ac-history"><?php wccp_free_icon('lock'); ?><?php esc_html_e('30 days', 'wp-content-copy-protector'); ?></button>
						<button type="button" class="wpb-switch__btn wpb-ac-locked" data-ac-locked aria-pressed="false" aria-controls="wpb-ac-history"><?php wccp_free_icon('lock'); ?><?php esc_html_e('90 days', 'wp-content-copy-protector'); ?></button>
						<button type="button" class="wpb-switch__btn wpb-ac-locked" data-ac-locked aria-pressed="false" aria-controls="wpb-ac-history"><?php wccp_free_icon('lock'); ?><?php esc_html_e('12 months', 'wp-content-copy-protector'); ?></button>
					</div>
				</div>

				<p class="wpb-ac-headline">
					<?php
					if ($wccp_free_ac_c['v'] < 1) {
						esc_html_e('Your protection is on. Nobody ran into it in the last 7 days.', 'wp-content-copy-protector');
					} else {
						$wccp_free_ac_sentences = array(
							/* translators: 1: number of visits, 2: percentage. */
							'up'   => _n('%1$s visit ran into your copy protection in the last 7 days - %2$s%% more than the 7 days before.', '%1$s visits ran into your copy protection in the last 7 days - %2$s%% more than the 7 days before.', $wccp_free_ac_c['v'], 'wp-content-copy-protector'),
							/* translators: 1: number of visits, 2: percentage. */
							'down' => _n('%1$s visit ran into your copy protection in the last 7 days - %2$s%% less than the 7 days before.', '%1$s visits ran into your copy protection in the last 7 days - %2$s%% less than the 7 days before.', $wccp_free_ac_c['v'], 'wp-content-copy-protector'),
							/* translators: 1: number of visits. */
							'flat' => _n('%1$s visit ran into your copy protection in the last 7 days - about the same as the 7 days before.', '%1$s visits ran into your copy protection in the last 7 days - about the same as the 7 days before.', $wccp_free_ac_c['v'], 'wp-content-copy-protector'),
							/* translators: 1: number of visits. */
							'none' => _n('%1$s visit ran into your copy protection in the last 7 days.', '%1$s visits ran into your copy protection in the last 7 days.', $wccp_free_ac_c['v'], 'wp-content-copy-protector'),
						);
						echo wp_kses(
							sprintf($wccp_free_ac_sentences[$wccp_free_ac_tr[0]], '<b>' . wccp_free_ac_num($wccp_free_ac_c['v']) . '</b>', number_format_i18n($wccp_free_ac_tr[1])),
							array('b' => array())
						);
					}
					?>
				</p>

				<?php if ($wccp_free_ac_new !== null) : ?>
					<div class="wpb-ac-since<?php echo $wccp_free_ac_new['v'] ? ' has-new' : ''; ?>">
						<span class="wpb-ac-since__ic"><?php wccp_free_icon($wccp_free_ac_new['v'] ? 'bell' : 'check'); ?></span>
						<p>
							<b><?php
								/* translators: %s: time since the last visit, e.g. "3 days ago". */
								echo esc_html(sprintf(__('Since your last visit (%s):', 'wp-content-copy-protector'), wccp_free_activity_ago($wccp_free_ac_ref)));
							?></b>
							<?php
							if ($wccp_free_ac_new['v']) {
								echo esc_html(sprintf(
									/* translators: 1: number of visits, 2: number of interactions, 3: number of pages. */
									_n('%1$s new visit with blocked actions - %2$s interactions on %3$s pages.', '%1$s new visits with blocked actions - %2$s interactions on %3$s pages.', $wccp_free_ac_new['v'], 'wp-content-copy-protector'),
									number_format_i18n($wccp_free_ac_new['v']),
									number_format_i18n($wccp_free_ac_new['interactions']),
									number_format_i18n($wccp_free_ac_new['pages'])
								));
							} else {
								esc_html_e('nothing new yet.', 'wp-content-copy-protector');
							}
							?>
						</p>
					</div>
				<?php endif; ?>

				<?php if ($wccp_free_ac_young) : ?>
					<p class="wpb-ac-note"><?php wccp_free_icon('info'); ?>
						<?php
						/* translators: %s: date. */
						echo esc_html(sprintf(__('Recording started on %s, so this period is not complete yet. Comparisons appear once there are two full weeks to compare.', 'wp-content-copy-protector'), date_i18n(get_option('date_format'), $wccp_free_ac_started + wccp_free_activity_offset())));
						?>
					</p>
				<?php endif; ?>

				<div class="wpb-ac-history" id="wpb-ac-history" hidden>
					<span class="wpb-ac-history__ic"><?php wccp_free_icon('clock'); ?></span>
					<div class="wpb-ac-history__txt">
						<b><?php esc_html_e('Longer history is part of PRO', 'wp-content-copy-protector'); ?></b>
						<p><?php esc_html_e('See 30 days, 90 days or 12 months of activity, spot seasonal peaks and follow how each page trends over time.', 'wp-content-copy-protector'); ?></p>
					</div>
					<a class="wpb-btn wpb-btn--primary" target="_blank" rel="noopener" href="<?php echo esc_url($wccp_free_ac_upgrade); ?>"><?php wccp_free_icon('sparkles'); ?><?php esc_html_e('See PRO', 'wp-content-copy-protector'); ?></a>
				</div>
			</div>

			<?php if ($wccp_free_ac_quiet) :
				$wccp_free_ac_last = wccp_free_activity_last();
			?>

				<!-- ============ Quiet period: status instead of a row of zeros ============ -->
				<div class="wpb-card wpb-ac-quiet">
					<span class="wpb-ac-quiet__ic"><?php wccp_free_icon('shield'); ?></span>
					<div class="wpb-ac-quiet__txt">
						<h3><?php esc_html_e('Quiet week - your protection is on and watching', 'wp-content-copy-protector'); ?></h3>
						<p><?php esc_html_e('Very few visits ran into your protection in the last 7 days. That is normal for a newer or smaller site: this page fills up as your content gets more visitors, and you will see here the moment it does.', 'wp-content-copy-protector'); ?></p>
						<?php if ($wccp_free_ac_last) : ?>
							<p class="wpb-ac-quiet__last"><?php wccp_free_icon('clock'); ?>
								<?php
								/* translators: 1: time ago, 2: page title. */
								echo esc_html(sprintf(__('Last blocked action: %1$s, on "%2$s".', 'wp-content-copy-protector'), wccp_free_activity_ago($wccp_free_ac_last['time']), $wccp_free_ac_last['title']));
								?>
							</p>
						<?php endif; ?>
					</div>
				</div>

			<?php else : ?>

				<!-- ============ Hero number + daily chart ============ -->
				<div class="wpb-ac-grid">
					<div class="wpb-ac-big">
						<span class="wpb-ac-big__label"><?php esc_html_e('Visits with blocked actions', 'wp-content-copy-protector'); ?></span>
						<span class="wpb-ac-big__value"><?php echo wccp_free_ac_num($wccp_free_ac_c['v']); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in the helper. ?></span>
						<?php wccp_free_ac_trend_chip($wccp_free_ac_tr); ?>
						<dl class="wpb-ac-big__facts">
							<div>
								<dt><?php esc_html_e('Blocked interactions', 'wp-content-copy-protector'); ?></dt>
								<dd><?php echo wccp_free_ac_num($wccp_free_ac_c['interactions']); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></dd>
							</div>
							<div>
								<dt><?php esc_html_e('Pages with activity', 'wp-content-copy-protector'); ?></dt>
								<dd><?php echo wccp_free_ac_num($wccp_free_ac_c['pages']); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></dd>
							</div>
						</dl>
						<p class="wpb-ac-big__why"><?php esc_html_e('One visit counts once, however many times the visitor clicked. A blocked action is not necessarily an attempt to steal.', 'wp-content-copy-protector'); ?></p>
					</div>

					<div class="wpb-card wpb-ac-chartcard">
						<div class="wpb-card__head">
							<span class="wpb-card__ic"><?php wccp_free_icon('gauge'); ?></span>
							<h2 class="wpb-card__title"><?php esc_html_e('Visits with blocked actions per day', 'wp-content-copy-protector'); ?></h2>
							<div class="wpb-switch" role="group" aria-label="<?php esc_attr_e('Show the daily activity as', 'wp-content-copy-protector'); ?>">
								<button type="button" class="wpb-switch__btn is-active" data-ac-view="chart" aria-pressed="true"><?php wccp_free_icon('gauge'); ?><?php esc_html_e('Chart', 'wp-content-copy-protector'); ?></button>
								<button type="button" class="wpb-switch__btn" data-ac-view="table" aria-pressed="false"><?php wccp_free_icon('grid'); ?><?php esc_html_e('Table', 'wp-content-copy-protector'); ?></button>
							</div>
						</div>
						<?php if ($wccp_free_ac['comparable']) : ?>
							<ul class="wpb-ac-legend">
								<li><span class="wpb-ac-key wpb-ac-key--now"></span><?php esc_html_e('Last 7 days', 'wp-content-copy-protector'); ?></li>
								<li><span class="wpb-ac-key wpb-ac-key--prev"></span><?php esc_html_e('The 7 days before', 'wp-content-copy-protector'); ?></li>
							</ul>
						<?php endif; ?>
						<div class="wpb-ac-chart" id="wpb-ac-chart"></div>

						<div class="wpb-ac-table" id="wpb-ac-table" hidden>
							<div class="wpb-ac-table__scroll">
								<table>
									<thead>
										<tr>
											<th scope="col"><?php esc_html_e('Day', 'wp-content-copy-protector'); ?></th>
											<th scope="col"><?php esc_html_e('Visits', 'wp-content-copy-protector'); ?></th>
											<?php foreach ($wccp_free_ac_labels as $wccp_free_ac_label) : ?>
												<th scope="col"><?php echo esc_html($wccp_free_ac_label); ?></th>
											<?php endforeach; ?>
										</tr>
									</thead>
									<tbody>
										<?php foreach ($wccp_free_ac['series'] as $wccp_free_ac_row) : ?>
											<tr>
												<th scope="row"><?php echo esc_html($wccp_free_ac_row['label']); ?></th>
												<td><?php echo wccp_free_ac_num($wccp_free_ac_row['v']); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
												<?php foreach (array_keys($wccp_free_ac_labels) as $wccp_free_ac_key) : ?>
													<td><?php echo wccp_free_ac_num($wccp_free_ac_row[$wccp_free_ac_key]); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
												<?php endforeach; ?>
											</tr>
										<?php endforeach; ?>
									</tbody>
								</table>
							</div>
						</div>
					</div>
				</div>

				<!-- ============ One tile per action type ============ -->
				<div class="wpb-ac-tiles">
					<?php
					$wccp_free_ac_icons = array('s' => 'type', 'r' => 'mouse', 'i' => 'image', 'k' => 'keyboard', 'p' => 'printer');
					foreach ($wccp_free_ac_labels as $wccp_free_ac_key => $wccp_free_ac_label) :
						if ($wccp_free_ac_key === 'p' && ! $wccp_free_ac_print) continue;
					?>
						<div class="wpb-ac-tile">
							<span class="wpb-ac-tile__label"><?php wccp_free_icon($wccp_free_ac_icons[$wccp_free_ac_key]); ?><?php echo esc_html($wccp_free_ac_label); ?></span>
							<span class="wpb-ac-tile__value"><?php echo wccp_free_ac_num($wccp_free_ac_c[$wccp_free_ac_key]); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
							<?php wccp_free_ac_trend_chip(wccp_free_activity_trend($wccp_free_ac, $wccp_free_ac_key)); ?>
						</div>
					<?php endforeach; ?>
				</div>

				<!-- ============ Top content + what visitors reach for ============ -->
				<div class="wpb-ac-grid wpb-ac-grid--even">
					<div class="wpb-card wpb-ac-topcard">
						<div class="wpb-card__head">
							<span class="wpb-card__ic"><?php wccp_free_icon('file'); ?></span>
							<h2 class="wpb-card__title"><?php esc_html_e('Most interacted-with protected content', 'wp-content-copy-protector'); ?></h2>
						</div>
						<div class="wpb-ac-top__scroll">
							<table class="wpb-ac-top">
								<thead>
									<tr>
										<th scope="col"><?php esc_html_e('Content', 'wp-content-copy-protector'); ?></th>
										<th scope="col"><?php esc_html_e('Visits', 'wp-content-copy-protector'); ?></th>
										<th scope="col"><?php esc_html_e('Interactions', 'wp-content-copy-protector'); ?></th>
									</tr>
								</thead>
								<tbody>
									<?php
									$wccp_free_ac_max = max(1, $wccp_free_ac['top'][0]['v']);
									foreach ($wccp_free_ac['top'] as $wccp_free_ac_item) :
										// Its most frequent blocked action, for the line under the title.
										$wccp_free_ac_most = '';
										$wccp_free_ac_most_n = 0;
										foreach ($wccp_free_ac_labels as $wccp_free_ac_key => $wccp_free_ac_label) {
											if ($wccp_free_ac_item[$wccp_free_ac_key] > $wccp_free_ac_most_n) {
												$wccp_free_ac_most   = $wccp_free_ac_label;
												$wccp_free_ac_most_n = $wccp_free_ac_item[$wccp_free_ac_key];
											}
										}
									?>
										<tr>
											<th scope="row">
												<?php if ($wccp_free_ac_item['url']) : ?>
													<a class="wpb-ac-top__title" href="<?php echo esc_url($wccp_free_ac_item['url']); ?>" target="_blank" rel="noopener"><?php echo esc_html($wccp_free_ac_item['title']); ?></a>
												<?php else : ?>
													<span class="wpb-ac-top__title"><?php echo esc_html($wccp_free_ac_item['title']); ?></span>
												<?php endif; ?>
												<span class="wpb-ac-top__meta">
													<?php echo esc_html(implode(' · ', array_filter(array($wccp_free_ac_item['kind'], $wccp_free_ac_most ? sprintf(
														/* translators: %s: action type, e.g. "Right-clicks". */
														__('mostly %s', 'wp-content-copy-protector'),
														function_exists('mb_strtolower') ? mb_strtolower($wccp_free_ac_most) : strtolower($wccp_free_ac_most)
													) : '')))); ?>
												</span>
											</th>
											<td class="wpb-ac-top__visits">
												<span class="wpb-ac-bar" style="width:<?php echo esc_attr(max(2, round($wccp_free_ac_item['v'] / $wccp_free_ac_max * 100))); ?>%"></span>
												<span><?php echo wccp_free_ac_num($wccp_free_ac_item['v']); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
											</td>
											<td><?php echo wccp_free_ac_num($wccp_free_ac_item['interactions']); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
										</tr>
									<?php endforeach; ?>
								</tbody>
							</table>
						</div>
					</div>

					<div class="wpb-card wpb-ac-mixcard">
						<div class="wpb-card__head">
							<span class="wpb-card__ic"><?php wccp_free_icon('layers'); ?></span>
							<h2 class="wpb-card__title"><?php esc_html_e('Text or images?', 'wp-content-copy-protector'); ?></h2>
						</div>
						<?php
						$wccp_free_ac_total = max(1, $wccp_free_ac_c['interactions']);
						$wccp_free_ac_mix   = array(
							array(__('Text (selecting & copy shortcuts)', 'wp-content-copy-protector'), $wccp_free_ac_c['s'] + $wccp_free_ac_c['k']),
							array(__('Images', 'wp-content-copy-protector'), $wccp_free_ac_c['i']),
							array(__('Right-clicks elsewhere', 'wp-content-copy-protector'), $wccp_free_ac_c['r']),
						);
						if ($wccp_free_ac_print) {
							$wccp_free_ac_mix[] = array(__('Printing', 'wp-content-copy-protector'), $wccp_free_ac_c['p']);
						}
						$wccp_free_ac_text = $wccp_free_ac_mix[0][1];
						$wccp_free_ac_img  = $wccp_free_ac_mix[1][1];
						?>
						<p class="wpb-ac-mix__lead">
							<?php
							if ($wccp_free_ac_text > $wccp_free_ac_img * 1.25) {
								esc_html_e('Visitors mostly reach for your text.', 'wp-content-copy-protector');
							} elseif ($wccp_free_ac_img > $wccp_free_ac_text * 1.25) {
								esc_html_e('Visitors mostly reach for your images.', 'wp-content-copy-protector');
							} else {
								esc_html_e('Your text and your images draw about the same attention.', 'wp-content-copy-protector');
							}
							?>
						</p>
						<ul class="wpb-ac-mix">
							<?php foreach ($wccp_free_ac_mix as $wccp_free_ac_part) :
								$wccp_free_ac_pct = round($wccp_free_ac_part[1] / $wccp_free_ac_total * 100);
							?>
								<li>
									<span class="wpb-ac-mix__head">
										<span><?php echo esc_html($wccp_free_ac_part[0]); ?></span>
										<b><?php echo esc_html(number_format_i18n($wccp_free_ac_pct) . '%'); ?></b>
									</span>
									<span class="wpb-ac-mix__track"><span class="wpb-ac-bar" style="width:<?php echo esc_attr($wccp_free_ac_part[1] ? max(1, $wccp_free_ac_pct) : 0); ?>%"></span></span>
								</li>
							<?php endforeach; ?>
						</ul>
						<?php
						$wccp_free_ac_busy = null;
						foreach ($wccp_free_ac['series'] as $wccp_free_ac_row) {
							if (! $wccp_free_ac_busy || $wccp_free_ac_row['v'] > $wccp_free_ac_busy['v']) {
								$wccp_free_ac_busy = $wccp_free_ac_row;
							}
						}
						if ($wccp_free_ac_busy && $wccp_free_ac_busy['v'] > 0) :
						?>
							<p class="wpb-ac-note"><?php wccp_free_icon('zap'); ?>
								<?php
								/* translators: 1: day, 2: number of visits. */
								echo esc_html(sprintf(_n('Busiest day: %1$s, with %2$s visit.', 'Busiest day: %1$s, with %2$s visits.', $wccp_free_ac_busy['v'], 'wp-content-copy-protector'), $wccp_free_ac_busy['label'], number_format_i18n($wccp_free_ac_busy['v'])));
								?>
							</p>
						<?php endif; ?>
					</div>
				</div>

				<?php if ($wccp_free_ac_hint) : ?>
					<!-- ============ Contextual PRO suggestion - one at most, based on this week's data ============ -->
					<div class="wpb-ac-hint" data-ac-hint="<?php echo esc_attr($wccp_free_ac_hint['key']); ?>">
						<span class="wpb-ac-hint__ic"><?php wccp_free_icon($wccp_free_ac_hint['icon']); ?></span>
						<div class="wpb-ac-hint__txt">
							<span class="wpb-tag wpb-tag--pro"><?php wccp_free_icon('sparkles'); ?><?php esc_html_e('Based on your activity', 'wp-content-copy-protector'); ?></span>
							<h3><?php echo esc_html($wccp_free_ac_hint['title']); ?></h3>
							<p><?php echo esc_html($wccp_free_ac_hint['text']); ?></p>
						</div>
						<div class="wpb-ac-hint__acts">
							<a class="wpb-btn wpb-btn--primary" target="_blank" rel="noopener" href="<?php echo esc_url($wccp_free_ac_upgrade); ?>"><?php echo esc_html($wccp_free_ac_hint['cta']); ?></a>
							<button type="button" class="wpb-btn wpb-btn--quiet" data-ac-hide-hint><?php esc_html_e('Not now', 'wp-content-copy-protector'); ?></button>
						</div>
					</div>
				<?php endif; ?>

			<?php endif; ?>

			<!-- ============ What is protected ============ -->
			<div class="wpb-card">
				<div class="wpb-card__head">
					<span class="wpb-card__ic"><?php wccp_free_icon('shield'); ?></span>
					<h2 class="wpb-card__title"><?php esc_html_e('What your protection covers', 'wp-content-copy-protector'); ?></h2>
					<button type="button" class="wpb-btn wpb-btn--ghost wpb-ac-head-btn" data-wpb-goto="wpb-panel-main"><?php wccp_free_icon('sliders'); ?><?php esc_html_e('Change settings', 'wp-content-copy-protector'); ?></button>
				</div>
				<div class="wpb-ac-top__scroll">
					<table class="wpb-ac-cover">
						<thead>
							<tr>
								<th scope="col"><?php esc_html_e('Content', 'wp-content-copy-protector'); ?></th>
								<th scope="col"><?php esc_html_e('Selection & shortcuts', 'wp-content-copy-protector'); ?></th>
								<th scope="col"><?php esc_html_e('Right-click', 'wp-content-copy-protector'); ?></th>
								<th scope="col"><?php esc_html_e('CSS layer', 'wp-content-copy-protector'); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach (wccp_free_activity_coverage() as $wccp_free_ac_row) : ?>
								<tr>
									<th scope="row">
										<span class="wpb-ac-top__title"><?php echo esc_html($wccp_free_ac_row['label']); ?></span>
										<?php if ($wccp_free_ac_row['count'] >= 0) : ?>
											<span class="wpb-ac-top__meta"><?php
												/* translators: %s: number of published items. */
												echo esc_html(sprintf(__('%s published', 'wp-content-copy-protector'), number_format_i18n($wccp_free_ac_row['count'])));
											?></span>
										<?php endif; ?>
									</th>
									<?php foreach ($wccp_free_ac_row['layers'] as $wccp_free_ac_layer) : ?>
										<td>
											<?php if ($wccp_free_ac_layer) : ?>
												<span class="wpb-ac-state wpb-ac-state--on"><?php wccp_free_icon('check'); ?><?php esc_html_e('On', 'wp-content-copy-protector'); ?></span>
											<?php else : ?>
												<span class="wpb-ac-state"><?php wccp_free_icon('x'); ?><?php esc_html_e('Off', 'wp-content-copy-protector'); ?></span>
											<?php endif; ?>
										</td>
									<?php endforeach; ?>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			</div>

		<?php endif; ?>

		<!-- ============ Insight settings & privacy ============ -->
		<div class="wpb-card">
			<div class="wpb-card__head">
				<span class="wpb-card__ic"><?php wccp_free_icon('lock'); ?></span>
				<h2 class="wpb-card__title"><?php esc_html_e('Activity insights & privacy', 'wp-content-copy-protector'); ?></h2>
				<span class="wpb-ac-status" data-ac-status role="status" aria-live="polite"></span>
			</div>

			<div class="wpb-ac-settings">
				<div class="wpb-ac-toggles">
					<label class="wpb-ac-toggle">
						<input type="checkbox" data-ac-option="enabled" <?php checked($wccp_free_ac_on); ?>>
						<span class="wpb-ac-toggle__ui" aria-hidden="true"></span>
						<span class="wpb-ac-toggle__txt">
							<b><?php esc_html_e('Record protection activity', 'wp-content-copy-protector'); ?></b>
							<small><?php esc_html_e('Counts blocked actions on your protected pages.', 'wp-content-copy-protector'); ?></small>
						</span>
					</label>
					<label class="wpb-ac-toggle">
						<input type="checkbox" data-ac-option="badge" <?php checked($wccp_free_ac_options['badge'], 'Yes'); ?>>
						<span class="wpb-ac-toggle__ui" aria-hidden="true"></span>
						<span class="wpb-ac-toggle__txt">
							<b><?php esc_html_e('New activity count in the admin menu', 'wp-content-copy-protector'); ?></b>
							<small><?php esc_html_e('A small number next to "Copy Protection" until you open this page.', 'wp-content-copy-protector'); ?></small>
						</span>
					</label>
					<label class="wpb-ac-toggle">
						<input type="checkbox" data-ac-option="digest" <?php checked($wccp_free_ac_options['digest'], 'Yes'); ?>>
						<span class="wpb-ac-toggle__ui" aria-hidden="true"></span>
						<span class="wpb-ac-toggle__txt">
							<b><?php esc_html_e('Weekly email summary', 'wp-content-copy-protector'); ?></b>
							<small>
								<?php
								/* translators: %s: email address. */
								echo esc_html(sprintf(__('Every Monday to %s, only in weeks with activity.', 'wp-content-copy-protector'), get_option('admin_email')));
								?>
								<button type="button" class="wpb-ac-link" data-ac-test><?php esc_html_e('Send me a preview', 'wp-content-copy-protector'); ?></button>
							</small>
						</span>
					</label>
					<label class="wpb-ac-toggle">
						<input type="checkbox" data-ac-option="purge" <?php checked($wccp_free_ac_options['purge'], 'Yes'); ?>>
						<span class="wpb-ac-toggle__ui" aria-hidden="true"></span>
						<span class="wpb-ac-toggle__txt">
							<b><?php esc_html_e('Delete this data when the plugin is deleted', 'wp-content-copy-protector'); ?></b>
							<small><?php esc_html_e('Off by default: deleting the plugin leaves everything it recorded on your site, so a later install picks the history up again. Switch it on and deleting the plugin also drops the activity table for good.', 'wp-content-copy-protector'); ?></small>
						</span>
					</label>
				</div>

				<ul class="wpb-ac-privacy">
					<li><?php wccp_free_icon('check'); ?><?php esc_html_e('No IP addresses, cookies or personal data are stored - only counts per page and hour.', 'wp-content-copy-protector'); ?></li>
					<li><?php wccp_free_icon('check'); ?><?php esc_html_e('Your own visits are not counted while you are logged in as an editor or admin.', 'wp-content-copy-protector'); ?></li>
					<li><?php wccp_free_icon('check'); ?><?php
						/* translators: %s: maximum count. */
						echo esc_html(sprintf(__('A single visit counts each kind of action at most %s times.', 'wp-content-copy-protector'), number_format_i18n(WCCP_FREE_ACT_CAP)));
					?></li>
					<li><?php wccp_free_icon('check'); ?><?php esc_html_e('Nothing is sent until something is blocked, and then only once per visit - page speed is not affected.', 'wp-content-copy-protector'); ?></li>
					<li><?php wccp_free_icon('check'); ?><?php
						/* translators: %s: number of days. */
						echo esc_html(sprintf(__('Everything stays in your own database and is deleted after %s days.', 'wp-content-copy-protector'), number_format_i18n(wccp_free_activity_retention_days())));
					?></li>
				</ul>
			</div>

			<div class="wpb-card__foot">
				<p class="wpb-formbar__note"><?php wccp_free_icon('info'); ?><?php esc_html_e('Right-clicks and copy shortcuts are ordinary browser habits too - these numbers show attention to your content, not proof of theft.', 'wp-content-copy-protector'); ?></p>
				<button type="button" class="wpb-btn wpb-btn--quiet" data-wpb-modal="wpb-ac-purge-modal"><?php wccp_free_icon('trash'); ?><?php esc_html_e('Delete recorded activity', 'wp-content-copy-protector'); ?></button>
			</div>
		</div>

		<div class="wpb-modal" id="wpb-ac-purge-modal" hidden>
			<div class="wpb-modal__veil" data-wpb-modal-close></div>
			<div class="wpb-modal__box" role="dialog" aria-modal="true" aria-labelledby="wpb-ac-purge-modal__title" aria-describedby="wpb-ac-purge-modal__text">
				<button type="button" class="wpb-modal__x" data-wpb-modal-close aria-label="<?php esc_attr_e('Close', 'wp-content-copy-protector'); ?>">
					<?php wccp_free_icon('x'); ?>
				</button>
				<span class="wpb-modal__ic"><?php wccp_free_icon('trash'); ?></span>
				<h2 class="wpb-modal__title" id="wpb-ac-purge-modal__title"><?php esc_html_e('Delete all recorded activity?', 'wp-content-copy-protector'); ?></h2>
				<p class="wpb-modal__text" id="wpb-ac-purge-modal__text"><?php esc_html_e('Every count collected so far is removed and the history starts again from today. Your protection settings are not touched. This cannot be undone.', 'wp-content-copy-protector'); ?></p>
				<div class="wpb-modal__acts">
					<button type="button" class="wpb-btn wpb-btn--ghost" data-wpb-modal-close><?php esc_html_e('Cancel', 'wp-content-copy-protector'); ?></button>
					<button type="button" class="wpb-btn wpb-btn--danger" data-ac-purge data-wpb-modal-close><?php wccp_free_icon('trash'); ?><?php esc_html_e('Yes, delete activity', 'wp-content-copy-protector'); ?></button>
				</div>
			</div>
		</div>

		<?php
		wp_localize_script('wccp-activity', 'wccpActivityCenter', array(
			'ajaxUrl' => admin_url('admin-ajax.php'),
			'nonce'   => wp_create_nonce('wccp_free_activity_admin'),
			'locale'  => str_replace('_', '-', get_user_locale()),
			'i18n'    => array(
				'saving'   => __('Saving…', 'wp-content-copy-protector'),
				'saved'    => __('Saved', 'wp-content-copy-protector'),
				'failed'   => __('Could not save. Reload the page and try again.', 'wp-content-copy-protector'),
				'sending'  => __('Sending…', 'wp-content-copy-protector'),
				'deleted'  => __('Activity deleted', 'wp-content-copy-protector'),
				'visits'   => __('Visits with blocked actions', 'wp-content-copy-protector'),
				'previous' => __('7 days before', 'wp-content-copy-protector'),
				/* translators: %s: chart description. */
				'chartLabel' => __('Visits with blocked actions per day, last 7 days. Use the arrow keys to read each day.', 'wp-content-copy-protector'),
			),
		));
		?>
	</div>
</div>
