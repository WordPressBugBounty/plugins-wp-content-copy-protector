<?php

/**
 * Deactivation feedback survey popup.
 *
 * Shows a modal on the Plugins screen when the user clicks "Deactivate" on this
 * plugin, inviting them to fill in the survey form hosted on wp-buy.com before
 * the deactivation goes through.
 *
 * @package wp-content-copy-protector
 * @since   3.7.3
 */

if (! defined('ABSPATH')) exit; // Exit if accessed directly

if (! class_exists('wccp_free_Deactivation_Survey')) :

	class wccp_free_Deactivation_Survey
	{

		/**
		 * Where the feedback form lives.
		 */
		const SURVEY_URL = 'https://www.wp-buy.com/survey/wp-content-copy-protection';

		/**
		 * Plugin basename, ie. wp-content-copy-protector/preventer-index.php
		 *
		 * @var string
		 */
		private $plugin_basename = '';

		public function __construct($plugin_file)
		{

			$this->plugin_basename = plugin_basename($plugin_file);

			add_action('admin_footer', array($this, 'wccp_free_survey_modal'));
		}

		/**
		 * Only the plugins list (single site + network) gets the modal.
		 */
		private function wccp_free_is_plugins_screen()
		{

			if (! function_exists('get_current_screen')) return false;

			$screen = get_current_screen();

			if (! $screen) return false;

			return in_array($screen->id, array('plugins', 'plugins-network'), true);
		}

		/**
		 * Print the modal markup, styles and the script that hijacks the
		 * "Deactivate" link for this plugin only.
		 */
		public function wccp_free_survey_modal()
		{

			if (! current_user_can('activate_plugins')) return;

			if (! $this->wccp_free_is_plugins_screen()) return;

			$icon_path = plugins_url('/images/icon-128x128.png', __FILE__);

			$survey_url = self::SURVEY_URL;

			if (defined('WCCP_FREE_VERSION')) {
				$survey_url = add_query_arg('ver', WCCP_FREE_VERSION, $survey_url);
			}
?>
			<style>
				#wccp_free_survey_overlay {
					position: fixed;
					top: 0;
					left: 0;
					width: 100%;
					height: 100%;
					background: rgba(0, 0, 0, .65);
					z-index: 100000;
					display: none;
				}

				#wccp_free_survey_overlay.wccp_free_open {
					display: flex;
					align-items: center;
					justify-content: center;
				}

				#wccp_free_survey_modal {
					position: relative;
					width: 100%;
					max-width: 520px;
					margin: 20px;
					background: #fff;
					border-radius: 6px;
					box-shadow: 0 5px 30px rgba(0, 0, 0, .35);
					box-sizing: border-box;
					max-height: 90vh;
					overflow-y: auto;
				}

				#wccp_free_survey_modal .wccp_free_survey_head {
					display: flex;
					align-items: center;
					gap: 14px;
					padding: 20px 24px;
					border-bottom: 1px solid #e2e4e7;
					background: #f6f7f7;
					border-radius: 6px 6px 0 0;
				}

				#wccp_free_survey_modal .wccp_free_survey_head img {
					width: 48px;
					height: 48px;
					flex: 0 0 48px;
				}

				#wccp_free_survey_modal .wccp_free_survey_head h2 {
					margin: 0;
					font-size: 18px;
					line-height: 1.4;
				}

				#wccp_free_survey_modal:focus {
					outline: none;
				}

				#wccp_free_survey_modal .wccp_free_survey_body {
					padding: 20px 24px;
				}

				#wccp_free_survey_modal .wccp_free_survey_body p {
					margin: 0 0 14px;
					font-size: 14px;
					line-height: 1.6;
				}

				#wccp_free_survey_modal .wccp_free_survey_gift {
					margin: 0 0 4px !important;
					padding: 12px 14px;
					background: #f0f0f1;
					border-left: 4px solid #8c8f94;
					color: #3c434a;
				}

				#wccp_free_survey_modal .wccp_free_survey_reward {
					color: #b34700;
					font-weight: 700;
					background: #fff1e0;
					padding: 1px 5px;
					border-radius: 3px;
					white-space: normal;
				}

				#wccp_free_survey_modal .wccp_free_survey_footer {
					display: flex;
					align-items: center;
					flex-wrap: wrap;
					gap: 12px;
					padding: 16px 24px 20px;
				}

				#wccp_free_survey_modal .wccp_free_survey_skip {
					margin-left: auto;
					color: #646970;
					text-decoration: none;
					font-size: 13px;
				}

				#wccp_free_survey_modal .wccp_free_survey_skip:hover,
				#wccp_free_survey_modal .wccp_free_survey_skip:focus {
					color: #3c434a;
					text-decoration: underline;
				}

				#wccp_free_survey_close {
					position: absolute;
					top: 10px;
					right: 10px;
					border: 0;
					background: none;
					cursor: pointer;
					padding: 4px;
					color: #787c82;
					line-height: 1;
				}

				#wccp_free_survey_close:hover,
				#wccp_free_survey_close:focus {
					color: #3c434a;
				}

				@media screen and (max-width: 600px) {
					#wccp_free_survey_modal .wccp_free_survey_footer {
						flex-direction: column;
						align-items: stretch;
					}

					#wccp_free_survey_modal .wccp_free_survey_skip {
						margin-left: 0;
						text-align: center;
					}
				}
			</style>

			<div id="wccp_free_survey_overlay" aria-hidden="true">
				<div id="wccp_free_survey_modal" role="dialog" aria-modal="true" aria-labelledby="wccp_free_survey_title" tabindex="-1">

					<button type="button" id="wccp_free_survey_close">
						<span class="dashicons dashicons-no-alt"></span>
						<span class="screen-reader-text"><?php esc_html_e('Close', 'wp-content-copy-protector'); ?></span>
					</button>

					<div class="wccp_free_survey_head">
						<img src="<?php echo esc_url($icon_path); ?>" alt="">
						<h2 id="wccp_free_survey_title"><?php esc_html_e('Before you go, may we ask why?', 'wp-content-copy-protector'); ?></h2>
					</div>

					<div class="wccp_free_survey_body">
						<p><?php esc_html_e('We are sorry to see you deactivate WP Content Copy Protection. Telling us what went wrong takes less than a minute, and it is the fastest way for us to fix it, for you and for everyone else.', 'wp-content-copy-protector'); ?></p>
						<p class="wccp_free_survey_gift">
							<strong><?php esc_html_e('A thank you from us:', 'wp-content-copy-protector'); ?></strong>
							<?php
							printf(
								/* translators: %s: the highlighted "FREE one month subscription to our PRO version" reward. */
								esc_html__('completing this survey accurately qualifies you to receive a %s, on the house!', 'wp-content-copy-protector'),
								'<span class="wccp_free_survey_reward">' . esc_html__('FREE one month subscription to our PRO version', 'wp-content-copy-protector') . '</span>'
							);
							?>
						</p>
					</div>

					<div class="wccp_free_survey_footer">
						<a href="<?php echo esc_url($survey_url); ?>" class="button button-primary" id="wccp_free_survey_go" target="_blank" rel="noopener noreferrer">
							<?php esc_html_e('Take the survey &amp; claim my free month', 'wp-content-copy-protector'); ?>
						</a>
						<a href="#" class="wccp_free_survey_skip" id="wccp_free_survey_skip">
							<?php esc_html_e('Skip &amp; deactivate', 'wp-content-copy-protector'); ?>
						</a>
					</div>

				</div>
			</div>

			<script>
				jQuery(function($) {

					var pluginBasename = <?php echo wp_json_encode($this->plugin_basename); ?>;
					var overlay = $('#wccp_free_survey_overlay');
					var deactivateUrl = '';

					// The "Deactivate" row action of this plugin only.
					var pluginRow = $('#the-list tr[data-plugin="' + pluginBasename + '"]');
					var deactivateLink = pluginRow.find('span.deactivate a');

					if (!deactivateLink.length) {
						deactivateLink = pluginRow.find('a[href*="action=deactivate"]');
					}

					if (!deactivateLink.length) {
						return; // Plugin row not on screen (filtered view), nothing to hook.
					}

					function openModal() {
						overlay.addClass('wccp_free_open').attr('aria-hidden', 'false');
						$('#wccp_free_survey_modal').trigger('focus');
					}

					function closeModal() {
						overlay.removeClass('wccp_free_open').attr('aria-hidden', 'true');
					}

					deactivateLink.on('click', function(e) {
						e.preventDefault();
						deactivateUrl = $(this).attr('href');
						openModal();
					});

					// Survey opens in a new tab, then we carry on with the deactivation.
					$('#wccp_free_survey_go').on('click', function() {
						closeModal();
						if (deactivateUrl) {
							window.setTimeout(function() {
								window.location.href = deactivateUrl;
							}, 500);
						}
					});

					$('#wccp_free_survey_skip').on('click', function(e) {
						e.preventDefault();
						if (deactivateUrl) {
							window.location.href = deactivateUrl;
						}
					});

					// Closing the modal cancels the deactivation, the plugin stays active.
					$('#wccp_free_survey_close').on('click', closeModal);

					overlay.on('click', function(e) {
						if (e.target === this) closeModal();
					});

					$(document).on('keyup', function(e) {
						if (e.key === 'Escape' && overlay.hasClass('wccp_free_open')) closeModal();
					});

				});
			</script>
<?php
		}
	}

endif;

new wccp_free_Deactivation_Survey(WCCP_FREE_PLUGIN_FILE);
