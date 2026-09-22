<?php
if (! defined('ABSPATH')) exit; // Exit if accessed directly
if (!current_user_can('edit_others_pages')) { //protection for admin content
	exit(0);
} ?>
<?php
//define all variables the needed alot
include 'the_globals.php';
include 'notifications.php';
add_action('admin_footer', 'wccp_free_alert_message');

if (isset($_POST["Restore_defaults"])) {
	check_admin_referer('Restore_defaults_nonce', '_Restore_defaults');

	if (! current_user_can('manage_options')) {
		wp_die(esc_html__('You do not have permission to change these settings.', 'wp-content-copy-protector'), 403);
	}

	update_option("wccp_settings", "");
	wp_safe_redirect(admin_url('admin.php?page=wccpoptionspro'));
	exit;
}

if (isset($_POST["Save_settings"])) {
	check_admin_referer('Save_settings_nonce', '_Save_settings');

	if (! current_user_can('manage_options')) {
		wp_die(esc_html__('You do not have permission to change these settings.', 'wp-content-copy-protector'), 403);
	}

	//----------------------------------------------------list the options array values
	$wccp_free_text_fields = array(
		'single_posts_protection',
		'home_page_protection',
		'page_protection',
		'top_bar_icon_btn',
		'home_css_protection',
		'posts_css_protection',
		'right_click_protection_posts',
		'right_click_protection_homepage',
		'right_click_protection_pages',
		'alert_msg_img',
		'alert_msg_a',
		'alert_msg_pb',
		'alert_msg_input',
		'alert_msg_h',
		'alert_msg_textarea',
		'alert_msg_emptyspaces',
	);

	$wccp_free_posted = array();
	foreach ($wccp_free_text_fields as $wccp_free_field) {
		$wccp_free_posted[$wccp_free_field] = isset($_POST[$wccp_free_field]) ? sanitize_text_field(wp_unslash($_POST[$wccp_free_field])) : '';
	}

	// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized by wccp_sanitize().
	$wccp_free_posted['smessage']     = isset($_POST['smessage']) ? wccp_sanitize(wp_unslash($_POST['smessage']), 'textbox') : '';
	// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized by wccp_sanitize().
	$wccp_free_posted['prnt_scr_msg'] = isset($_POST['prnt_scr_msg']) ? wccp_sanitize(wp_unslash($_POST['prnt_scr_msg']), 'text') : '';

	//----------------------------------------------------Get the options array values
	$wccp_settings =
		array(
			'single_posts_protection' => $wccp_free_posted['single_posts_protection'], // prevent content copy, take 2 parameters
			'home_page_protection' => $wccp_free_posted['home_page_protection'], // PROTECT THE HOME PAGE OR NOT
			'page_protection' => $wccp_free_posted['page_protection'], // protect pages by javascript
			'top_bar_icon_btn' => $wccp_free_posted['top_bar_icon_btn'], // protection icon on top bar
			'right_click_protection_posts' => $wccp_free_posted['right_click_protection_posts'], //no comment here
			'right_click_protection_homepage' => $wccp_free_posted['right_click_protection_homepage'], //no comment here
			'right_click_protection_pages' => $wccp_free_posted['right_click_protection_pages'], //no comment here
			'home_css_protection' => $wccp_free_posted['home_css_protection'], // premium option
			'posts_css_protection' => $wccp_free_posted['posts_css_protection'], // premium option (unlocked and become free option)
			'pages_css_protection' => 'No', // premium option
			'exclude_admin_from_protection' => 'No', // premium option
			'img' => '', // premium option
			'a' => '', // premium option
			'pb' => '', // premium option
			'input' => '', // premium option
			'h' => '', // premium option
			'textarea' => '', // premium option
			'emptyspaces' => '', // premium option
			'smessage' => $wccp_free_posted['smessage'],
			'alert_msg_img' => $wccp_free_posted['alert_msg_img'],
			'alert_msg_a' => $wccp_free_posted['alert_msg_a'],
			'alert_msg_pb' => $wccp_free_posted['alert_msg_pb'],
			'alert_msg_input' => $wccp_free_posted['alert_msg_input'],
			'alert_msg_h' => $wccp_free_posted['alert_msg_h'],
			'alert_msg_textarea' => $wccp_free_posted['alert_msg_textarea'],
			'alert_msg_emptyspaces' => $wccp_free_posted['alert_msg_emptyspaces'],
			'prnt_scr_msg' => $wccp_free_posted['prnt_scr_msg']
		);

	if (get_option('wccp_settings') !== null) {
		update_option('wccp_settings', $wccp_settings);
	} else {
		add_option('wccp_settings', $wccp_settings, '', 'yes');
	}
	wp_safe_redirect(admin_url('admin.php?page=wccpoptionspro'));
	exit;
}

$wccp_settings = wccp_read_options();
$wccp_free_allowed_tags = array('u' => array(), 'b' => array(), 'strong' => array(), 'em' => array(), 'br' => array());

/**
 * Presentation helpers for the settings screen.
 *
 * The panel below is styled after the wp-buy.com shop (css/wpb-admin.css):
 * the same token palette, card box, .wpb-pill buttons in place of the old
 * tab strip, the gradient featured banner for the PRO upsell, and the
 * three-column help CTA in the footer.
 */

if (! function_exists('wccp_free_select')) {
	/**
	 * Render a settings dropdown.
	 *
	 * @param string $name    Field name, also the key in the settings array.
	 * @param array  $choices value => label pairs, in display order.
	 * @param string $current Currently stored value.
	 * @param string $default Value to select when nothing is stored yet.
	 */
	function wccp_free_select($name, $choices, $current, $default)
	{
		$wccp_free_selected = array_key_exists($current, $choices) ? $current : $default;

		echo '<select name="' . esc_attr($name) . '" id="' . esc_attr($name) . '">';

		foreach ($choices as $wccp_free_value => $wccp_free_label) {
			printf(
				'<option value="%1$s"%2$s>%3$s</option>',
				esc_attr($wccp_free_value),
				selected($wccp_free_value, $wccp_free_selected, false),
				esc_html($wccp_free_label)
			);
		}

		echo '</select>';
	}
}

if (! function_exists('wccp_free_icon')) {
	/**
	 * Echo one of the panel's inline icons.
	 *
	 * Inline rather than an icon font so the markup carries no extra request
	 * and the glyphs inherit currentColor from whatever box they sit in.
	 *
	 * @param string $name Icon key.
	 */
	function wccp_free_icon($name)
	{
		$wccp_free_paths = array(
			'shield'   => '<path d="M12 2 4 5v6c0 5 3.4 9.4 8 11 4.6-1.6 8-6 8-11V5l-8-3Z"/>',
			'check'    => '<path d="m20 6-11 11-5-5"/>',
			'lock'     => '<rect x="4" y="10" width="16" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/>',
			'code'     => '<path d="m9 18-6-6 6-6"/><path d="m15 6 6 6-6 6"/>',
			'mouse'    => '<rect x="6" y="2" width="12" height="20" rx="6"/><path d="M12 7v4"/>',
			'brush'    => '<path d="m12 3 9 5-9 5-9-5 9-5Z"/><path d="m3 13 9 5 9-5"/>',
			'star'     => '<path d="m12 3 2.7 5.6 6.1.9-4.4 4.3 1 6.2-5.4-3-5.4 3 1-6.2L3.2 9.5l6.1-.9L12 3Z"/>',
			'sparkles' => '<path d="m12 3 1.9 4.6L18.5 9.5l-4.6 1.9L12 16l-1.9-4.6L5.5 9.5l4.6-1.9L12 3Z"/><path d="M18 15.5 18.8 17.5 20.8 18.3 18.8 19.1 18 21.1 17.2 19.1 15.2 18.3 17.2 17.5 18 15.5Z"/>',
			'crown'    => '<path d="M4.4 17.8h15.2"/><path d="m3.6 6.6 4.3 3.1L12 3.6l4.1 6.1 4.3-3.1-1.7 8.6H5.3L3.6 6.6Z"/>',
			'life'     => '<circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="3.6"/><path d="m5.6 5.6 3.8 3.8M14.6 14.6l3.8 3.8M18.4 5.6l-3.8 3.8M9.4 14.6l-3.8 3.8"/>',
			'book'     => '<path d="M4 5a2 2 0 0 1 2-2h13v16H6a2 2 0 0 0-2 2V5Z"/><path d="M8 7h7M8 11h7"/>',
			'mail'     => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/>',
			'phone'    => '<rect x="7" y="2" width="10" height="20" rx="2.5"/><path d="M11 18.5h2"/>',
			'image'    => '<rect x="3" y="4" width="18" height="16" rx="2"/><circle cx="8.5" cy="9.5" r="1.5"/><path d="m4 17 5-5 4 4 3-2 4 4"/>',
			'info'     => '<circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 7.6v.6"/>',
			'save'     => '<path d="M5 4h11l3 3v13H5V4Z"/><path d="M8 4v5h7V4M8 20v-6h8v6"/>',
			'undo'     => '<path d="M3 9h11a5 5 0 0 1 0 10h-4"/><path d="m3 9 4-4M3 9l4 4"/>',
			'eye'      => '<path d="M2.5 12S6 5.5 12 5.5 21.5 12 21.5 12 18 18.5 12 18.5 2.5 12 2.5 12Z"/><circle cx="12" cy="12" r="2.8"/>',
			'pointer'  => '<path d="M5.5 3.2 19 10.4l-6.1 1.6-1.7 6.1L5.5 3.2Z"/>',
			'keyboard' => '<rect x="2.5" y="6" width="19" height="12" rx="2.2"/><path d="M6 10h.01M9.5 10h.01M13 10h.01M16.5 10h.01M8 14h8"/>',
			'palette'  => '<path d="M12 3.2a8.8 8.8 0 1 0 0 17.6c1.1 0 1.9-.9 1.9-1.9 0-.5-.2-.9-.5-1.3-.3-.4-.5-.8-.5-1.2 0-.9.8-1.6 1.7-1.6h1.3a5 5 0 0 0 5-5c0-3.7-4-6.6-8.9-6.6Z"/><circle cx="7.6" cy="11.6" r="1"/><circle cx="10.4" cy="7.8" r="1"/><circle cx="15.2" cy="8.6" r="1"/>',
			'droplet'  => '<path d="M12 3s5.8 5.9 5.8 9.8a5.8 5.8 0 0 1-11.6 0C6.2 8.9 12 3 12 3Z"/>',
			'layers'   => '<path d="m12 2.8 8.6 4.6-8.6 4.6-8.6-4.6 8.6-4.6Z"/><path d="m3.4 12 8.6 4.6L20.6 12"/><path d="m3.4 16.6 8.6 4.6 8.6-4.6"/>',
			'filter'   => '<path d="M3.2 5h17.6l-6.9 8v6.2l-3.8 1.8V13L3.2 5Z"/>',
			'sliders'  => '<path d="M3.5 7h9M17.5 7h3M3.5 12h3M11.5 12h9M3.5 17h11M19.5 17h1"/><circle cx="15" cy="7" r="2.2"/><circle cx="9" cy="12" r="2.2"/><circle cx="17" cy="17" r="2.2"/>',
			'copy'     => '<rect x="9" y="9" width="11.5" height="11.5" rx="2.2"/><path d="M5.2 15H4.6A1.6 1.6 0 0 1 3 13.4V5.2A1.6 1.6 0 0 1 4.6 3.6h8.2A1.6 1.6 0 0 1 14.4 5.2v.6"/>',
			'type'     => '<path d="M4.5 7.2V5h15v2.2M12 5v14M9 19h6"/>',
			'printer'  => '<path d="M6.5 9V3h11v6"/><rect x="3" y="9" width="18" height="7.5" rx="2.2"/><path d="M6.5 13.5h11V21h-11z"/>',
			'search'   => '<circle cx="11" cy="11" r="7"/><path d="m20.5 20.5-4.5-4.5"/>',
			'users'    => '<circle cx="9" cy="8" r="3.4"/><path d="M2.6 20.2a6.4 6.4 0 0 1 12.8 0"/><path d="M16.4 5.3a3.4 3.4 0 0 1 0 5.4M17.6 14.6a6.4 6.4 0 0 1 3.8 5.6"/>',
			'zap'      => '<path d="M13.2 2.4 4.6 14h6.6l-1.4 7.6L18.6 10h-6.6l1.2-7.6Z"/>',
			'globe'    => '<circle cx="12" cy="12" r="9"/><path d="M3.4 9.2h17.2M3.4 14.8h17.2"/><path d="M12 3c2.4 2.4 3.7 5.5 3.7 9S14.4 18.6 12 21c-2.4-2.4-3.7-5.5-3.7-9S9.6 5.4 12 3Z"/>',
			'link'     => '<path d="M10.2 13.4a3.9 3.9 0 0 0 5.6.4l2.5-2.5a3.9 3.9 0 0 0-5.5-5.6l-1.5 1.4"/><path d="M13.8 10.6a3.9 3.9 0 0 0-5.6-.4l-2.5 2.5a3.9 3.9 0 0 0 5.5 5.6l1.5-1.4"/>',
			'crop'     => '<path d="M6.2 2.4v15.4h15.4"/><path d="M2.4 6.2h15.4v15.4"/>',
			'refresh'  => '<path d="M20.2 11a8.2 8.2 0 0 0-14-4.8L4 8.4"/><path d="M3.8 4v4.6h4.6"/><path d="M3.8 13a8.2 8.2 0 0 0 14 4.8l2.2-2.2"/><path d="M20.2 20v-4.6h-4.6"/>',
			'file'     => '<path d="M14 3H7.4A2.2 2.2 0 0 0 5.2 5.2v13.6A2.2 2.2 0 0 0 7.4 21h9.2a2.2 2.2 0 0 0 2.2-2.2V8L14 3Z"/><path d="M14 3v5h4.8"/>',
			'grid'     => '<rect x="3.2" y="3.2" width="7.2" height="7.2" rx="1.6"/><rect x="13.6" y="3.2" width="7.2" height="7.2" rx="1.6"/><rect x="3.2" y="13.6" width="7.2" height="7.2" rx="1.6"/><rect x="13.6" y="13.6" width="7.2" height="7.2" rx="1.6"/>',
			'message'  => '<path d="M20.8 11.6a8.2 8.2 0 0 1-8.2 8.2H4l2.3-3a8.2 8.2 0 1 1 14.5-5.2Z"/>',
			'clock'    => '<circle cx="12" cy="12" r="9"/><path d="M12 6.8V12l3.4 2.1"/>',
			'server'   => '<rect x="3" y="4" width="18" height="7" rx="2.2"/><rect x="3" y="13" width="18" height="7" rx="2.2"/><path d="M7 7.5h.01M7 16.5h.01"/>',
			'gauge'    => '<path d="M3.6 18a8.6 8.6 0 1 1 16.8 0"/><path d="m12 17.4 4.2-5.2"/><circle cx="12" cy="18" r="1.3"/>',
			'alert'    => '<path d="M12 3.4 21.4 19.6H2.6L12 3.4Z"/><path d="M12 9.4v4.3M12 16.6v.1"/>',
			'x'        => '<path d="m6 6 12 12M18 6 6 18"/>',
			'activity' => '<path d="M3 12h4l3-8 4 16 3-8h4"/>',
			'download' => '<path d="M12 3.5v11.5"/><path d="m7 10.5 5 5 5-5"/><path d="M4.5 19.5h15"/>',
			'trash'    => '<path d="M4 6.5h16M9.5 6.5V4.2h5v2.3"/><path d="M6.2 6.5 7.1 20h9.8l.9-13.5"/><path d="M10 10.5v6M14 10.5v6"/>',
			'bell'     => '<path d="M6.2 16.5V11a5.8 5.8 0 0 1 11.6 0v5.5l1.7 1.8H4.5l1.7-1.8Z"/><path d="M10 20.5a2.2 2.2 0 0 0 4 0"/>',
		);

		if (! isset($wccp_free_paths[$name])) {
			return;
		}

		echo '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">'
			. $wccp_free_paths[$name] // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static markup from the map above.
			. '</svg>';
	}
}

$wccp_free_admin_email = get_bloginfo('admin_email');
$wccp_free_pro_url     = 'https://www.wp-buy.com/product/wp-content-copy-protection-pro/';
$wccp_free_docs_url    = 'https://www.wp-buy.com/category/plugins-support/copy-protection-plugin-support/';
$wccp_free_support_url = 'https://www.wp-buy.com/contact-us/';
$wccp_free_onoff       = array(
	'Enabled'  => __('Enabled', 'wp-content-copy-protector'),
	'Disabled' => __('Disabled', 'wp-content-copy-protector'),
);
?>
<div id="wpccp_subscribe" class="notice wpb-notice">
	<div class="wpb-notice__in">
		<span class="wpb-notice__ic"><?php wccp_free_icon('mail'); ?></span>
		<div class="wpb-notice__txt">
			<h3><?php esc_html_e('WP Content Protection Plugin Group', 'wp-content-copy-protector'); ?></h3>
			<p><?php esc_html_e('Begin your adventure to improve your WordPress website, and win discount codes along the way.', 'wp-content-copy-protector'); ?></p>
		</div>
		<div class="wpb-notice__form">
			<label class="screen-reader-text" for="admin_email_wccp"><?php esc_html_e('Email address', 'wp-content-copy-protector'); ?></label>
			<input type="email" id="admin_email_wccp" name="admin_email_wccp" value="<?php echo esc_attr($wccp_free_admin_email); ?>">
			<button type="button" class="wpb-notice__go" onclick="wpccp_open_subscribe_page();"><?php esc_html_e('Start it!', 'wp-content-copy-protector'); ?></button>
			<button type="button" class="wpb-notice__x" onclick="wpccp_dismiss_notice();"><?php esc_html_e('Dismiss', 'wp-content-copy-protector'); ?></button>
		</div>
	</div>
</div>
<script>
	function wpccp_dismiss_notice() {
		localStorage.setItem('wpccp_subscribed', 'wpccp_subsbc_user');
		document.getElementById("wpccp_subscribe").style.display = "none";
	}

	function wpccp_open_subscribe_page() {
		if (localStorage.getItem('wpccp_subscribed') != 'wpccp_subsbc_user') {
			var admin_email_wccp = document.getElementById('admin_email_wccp').value;
			window.open('https://www.wp-buy.com/wpccp-subscribe/?email=' + admin_email_wccp, '_blank');
		}
	}

	if (localStorage.getItem('wpccp_subscribed') == 'wpccp_subsbc_user') {
		document.getElementById("wpccp_subscribe").style.display = "none";
	}
</script>

<div id="wpb-admin" class="no-js">

	<!-- ============ Head ============ -->
	<div class="wpb-head">
		<div class="wpb-head__main">
			<span class="wpb-eyebrow"><?php wccp_free_icon('shield'); ?><?php esc_html_e('Content Protection Center', 'wp-content-copy-protector'); ?></span>
			<h1 class="wpb-title">
				<?php esc_html_e('WP Content Copy Protection', 'wp-content-copy-protector'); ?>
				<span class="gradient-text"><?php esc_html_e('& No Right Click', 'wp-content-copy-protector'); ?></span>
			</h1>
			<p class="wpb-subtitle"><?php esc_html_e('Stop visitors from selecting, copying, printing or right-clicking your posts, pages and images. Switch a layer on below, save, and it is live on the front end.', 'wp-content-copy-protector'); ?></p>
		</div>
		<div class="wpb-badges">
			<span class="wpb-badge"><?php wccp_free_icon('check'); ?><?php esc_html_e('Free version active', 'wp-content-copy-protector'); ?></span>
			<span class="wpb-badge"><?php wccp_free_icon('lock'); ?><?php esc_html_e('3 protection layers', 'wp-content-copy-protector'); ?></span>
		</div>
	</div>

	<!-- ============ Featured banner - PRO advertisement ============ -->
	<div class="wpb-featured">
		<div class="wpb-featured__icon">
			<img src="<?php echo esc_url($wccp_free_pluginsurl); ?>/images/wccp-free-box-96x96.webp" alt="">
		</div>
		<div class="wpb-featured__info">
			<span class="wpb-featured__tag">&#9733; <?php esc_html_e('Featured', 'wp-content-copy-protector'); ?></span>
			<h2 class="wpb-featured__title"><?php esc_html_e('WP Content Copy Protection & No Right Click (PRO)', 'wp-content-copy-protector'); ?></h2>
			<p class="wpb-featured__desc"><?php esc_html_e('The ultimate defense for your hard work. Full right-click control, image watermarking, aggressive image protection and per-post protection levels - everything the free layers leave on the table.', 'wp-content-copy-protector'); ?></p>
			<ul class="wpb-featured__points">
				<li><?php wccp_free_icon('check'); ?><?php esc_html_e('Full watermarking', 'wp-content-copy-protector'); ?></li>
				<li><?php wccp_free_icon('check'); ?><?php esc_html_e('Aggressive protection', 'wp-content-copy-protector'); ?></li>
				<li><?php wccp_free_icon('check'); ?><?php esc_html_e('Post types protection', 'wp-content-copy-protector'); ?></li>
				<li><?php wccp_free_icon('star'); ?><?php esc_html_e('Rated 4.9 by hundreds', 'wp-content-copy-protector'); ?></li>
			</ul>
		</div>
		<div class="wpb-featured__buy">
			<div class="wpb-featured__price"><?php esc_html_e('Live Demo', 'wp-content-copy-protector'); ?></div>
			<a class="wpb-featured__btn" target="_blank" rel="noopener" href="<?php echo esc_url($wccp_free_pro_url); ?>"><?php esc_html_e('View Details', 'wp-content-copy-protector'); ?></a>
			<span class="wpb-featured__mbg"><?php esc_html_e('30-Day Money Back', 'wp-content-copy-protector'); ?></span>
		</div>
	</div>

	<form method="POST">
		<input type="hidden" value="update" name="action">
		<?php wp_nonce_field('Save_settings_nonce', '_Save_settings'); ?>
		<?php wp_nonce_field('Restore_defaults_nonce', '_Restore_defaults'); ?>

		<?php
		// The Protection Center only appears once enough activity was recorded.
		$wccp_free_show_center = current_user_can('manage_options') && wccp_free_activity_unlocked();
		?>

		<!-- ============ Pill rail - replaces the old tabs ============ -->
		<div class="wpb-pills" role="tablist" aria-label="<?php esc_attr_e('Settings sections', 'wp-content-copy-protector'); ?>">
			<?php if ($wccp_free_show_center) : ?>
				<button type="button" class="wpb-pill" role="tab" id="wpb-pill-center" data-panel="wpb-panel-center" aria-controls="wpb-panel-center" aria-selected="false">
					<?php wccp_free_icon('eye'); ?><?php esc_html_e('Protection Center', 'wp-content-copy-protector'); ?>
				</button>
			<?php endif; ?>
			<button type="button" class="wpb-pill" role="tab" id="wpb-pill-main" data-panel="wpb-panel-main" aria-controls="wpb-panel-main" aria-selected="false">
				<?php wccp_free_icon('code'); ?><?php esc_html_e('Main Settings', 'wp-content-copy-protector'); ?>
			</button>
			<button type="button" class="wpb-pill" role="tab" id="wpb-pill-click" data-panel="wpb-panel-click" aria-controls="wpb-panel-click" aria-selected="false">
				<?php wccp_free_icon('mouse'); ?><?php esc_html_e('RightClick Protection', 'wp-content-copy-protector'); ?>
			</button>
			<button type="button" class="wpb-pill" role="tab" id="wpb-pill-css" data-panel="wpb-panel-css" aria-controls="wpb-panel-css" aria-selected="false">
				<?php wccp_free_icon('brush'); ?><?php esc_html_e('Protection by CSS', 'wp-content-copy-protector'); ?>
			</button>
			<?php if (wccp_free_em_can()) : ?>
				<button type="button" class="wpb-pill" role="tab" id="wpb-pill-errors" data-panel="wpb-panel-errors" aria-controls="wpb-panel-errors" aria-selected="false">
					<?php wccp_free_icon('activity'); ?><?php esc_html_e('Error Monitor', 'wp-content-copy-protector'); ?>
				</button>
			<?php endif; ?>
			<button type="button" class="wpb-pill" role="tab" id="wpb-pill-pro" data-panel="wpb-panel-pro" aria-controls="wpb-panel-pro" aria-selected="false">
				<?php wccp_free_icon('sparkles'); ?><?php esc_html_e('More with PRO', 'wp-content-copy-protector'); ?>
			</button>
		</div>

		<?php if ($wccp_free_show_center) {
			// ============ Panel 0 - Protection Center (activity insights) ============
			include 'activity-panel.php';
		} ?>

		<!-- ============ Panel 1 - Main settings ============ -->
		<div class="wpb-panel" id="wpb-panel-main" role="tabpanel" aria-labelledby="wpb-pill-main" tabindex="0">
			<div class="wpb-card">
				<div class="wpb-card__head">
					<span class="wpb-card__ic"><?php wccp_free_icon('code'); ?></span>
					<h2 class="wpb-card__title"><?php esc_html_e('Copy Protection using JavaScript', 'wp-content-copy-protector'); ?></h2>
					<span class="wpb-tag wpb-tag--free"><?php esc_html_e('Basic Layer', 'wp-content-copy-protector'); ?></span>
				</div>
				<p class="wpb-card__desc"><?php echo wp_kses(__('This is the basic protection layer that uses <u>JavaScript</u> to protect the posts, home page content from being copied by any other web site author.', 'wp-content-copy-protector'), $wccp_free_allowed_tags); ?></p>

				<div class="wpb-fields">
					<div class="wpb-field">
						<label class="wpb-field__label" for="single_posts_protection"><?php echo wp_kses(__('Posts protection by <u>JavaScript</u>', 'wp-content-copy-protector'), $wccp_free_allowed_tags); ?></label>
						<?php wccp_free_select('single_posts_protection', $wccp_free_onoff, $wccp_settings['single_posts_protection'], 'Disabled'); ?>
						<p class="wpb-field__hint"><?php esc_html_e('For single posts content', 'wp-content-copy-protector'); ?></p>
					</div>

					<div class="wpb-field">
						<label class="wpb-field__label" for="home_page_protection"><?php echo wp_kses(__('Homepage protection by <u>JavaScript</u>', 'wp-content-copy-protector'), $wccp_free_allowed_tags); ?></label>
						<?php wccp_free_select('home_page_protection', $wccp_free_onoff, $wccp_settings['home_page_protection'], 'Disabled'); ?>
						<p class="wpb-field__hint"><?php esc_html_e('Don\'t copy any thing! even from my homepage', 'wp-content-copy-protector'); ?></p>
					</div>

					<div class="wpb-field">
						<label class="wpb-field__label" for="page_protection"><?php esc_html_e('Static page\'s protection', 'wp-content-copy-protector'); ?></label>
						<?php wccp_free_select('page_protection', $wccp_free_onoff, $wccp_settings['page_protection'], 'Disabled'); ?>
						<p class="wpb-field__hint"><?php esc_html_e('Use Premium Settings tab to customize more options', 'wp-content-copy-protector'); ?></p>
					</div>

					<div class="wpb-field">
						<label class="wpb-field__label" for="top_bar_icon_btn"><?php esc_html_e('Plugin icon on top admin bar', 'wp-content-copy-protector'); ?></label>
						<?php
						wccp_free_select(
							'top_bar_icon_btn',
							array(
								'Visible' => __('Visible', 'wp-content-copy-protector'),
								'Hidden'  => __('Hidden', 'wp-content-copy-protector'),
							),
							isset($wccp_settings['top_bar_icon_btn']) ? $wccp_settings['top_bar_icon_btn'] : '',
							'Visible'
						);
						?>
						<p class="wpb-field__hint"><?php esc_html_e('Show/Hide the plugin icon on the top admin bar', 'wp-content-copy-protector'); ?></p>
					</div>

					<div class="wpb-field">
						<span class="wpb-field__label"><?php echo wp_kses(__('Exclude <u>Admin</u> from protection', 'wp-content-copy-protector'), $wccp_free_allowed_tags); ?></span>
						<span class="wpb-field__locked">
							<a class="wpb-tag wpb-tag--pro" target="_blank" rel="noopener" href="<?php echo esc_url($wccp_free_pro_url); ?>"><?php wccp_free_icon('lock'); ?><?php esc_html_e('Premium', 'wp-content-copy-protector'); ?></a>
						</span>
						<p class="wpb-field__hint"><?php echo wp_kses(__('If <u>Yes</u>, The protection functions will be inactive for the admin when he is logged in', 'wp-content-copy-protector'), $wccp_free_allowed_tags); ?></p>
					</div>

					<div class="wpb-field wpb-field--wide">
						<label class="wpb-field__label" for="smessage"><?php esc_html_e('Selection disabled message', 'wp-content-copy-protector'); ?></label>
						<input type="text" id="smessage" placeholder="<?php esc_attr_e('Enter something', 'wp-content-copy-protector'); ?>" name="smessage" value="<?php echo esc_attr(html_entity_decode($wccp_settings['smessage'], ENT_QUOTES, 'UTF-8')); ?>">
					</div>

					<div class="wpb-field wpb-field--wide wpb-field--top">
						<label class="wpb-field__label" for="prnt_scr_msg"><?php esc_html_e('Print preview message', 'wp-content-copy-protector'); ?></label>
						<textarea id="prnt_scr_msg" placeholder="<?php esc_attr_e('Enter something', 'wp-content-copy-protector'); ?>" name="prnt_scr_msg"><?php echo esc_textarea(html_entity_decode($wccp_settings['prnt_scr_msg'], ENT_QUOTES, 'UTF-8')); ?></textarea>
					</div>
				</div>

				<div class="wpb-card__foot">
					<div class="wpb-meta">
						<span class="wpb-chip wpb-chip--safe"><?php wccp_free_icon('check'); ?><?php esc_html_e('Works with every theme', 'wp-content-copy-protector'); ?></span>
						<span class="wpb-sep"></span>
						<span class="wpb-chip"><?php wccp_free_icon('phone'); ?><?php esc_html_e('Desktop & mobile', 'wp-content-copy-protector'); ?></span>
					</div>
					<a class="wpb-btn wpb-btn--ghost" target="_blank" rel="noopener" href="<?php echo esc_url($wccp_free_pro_url); ?>"><?php wccp_free_icon('sparkles'); ?><?php esc_html_e('Unlock more options', 'wp-content-copy-protector'); ?></a>
				</div>
			</div>
		</div>

		<!-- ============ Panel 2 - RightClick ============ -->
		<div class="wpb-panel" id="wpb-panel-click" role="tabpanel" aria-labelledby="wpb-pill-click" tabindex="0">
			<div class="wpb-card">
				<div class="wpb-card__head">
					<span class="wpb-card__ic"><?php wccp_free_icon('mouse'); ?></span>
					<h2 class="wpb-card__title"><?php esc_html_e('Copy Protection on RightClick', 'wp-content-copy-protector'); ?></h2>
					<span class="wpb-tag wpb-tag--layer"><?php esc_html_e('Premium Layer 2', 'wp-content-copy-protector'); ?></span>
				</div>
				<p class="wpb-card__desc"><?php echo wp_kses(__('In this protection layer your visitors will be able to <u>right click</u> on a specific page elements only (such as Links as an example)', 'wp-content-copy-protector'), $wccp_free_allowed_tags); ?></p>

				<div class="wpb-fields">
					<div class="wpb-field">
						<span class="wpb-field__label"><?php echo wp_kses(__('Disable <u>RightClick</u> on', 'wp-content-copy-protector'), $wccp_free_allowed_tags); ?></span>
						<label class="wpb-check">
							<input type="checkbox" name="right_click_protection_posts" value="checked" <?php checked($wccp_settings['right_click_protection_posts'], 'checked'); ?>>
							<?php esc_html_e('Posts', 'wp-content-copy-protector'); ?>
						</label>
						<p class="wpb-field__hint"><?php esc_html_e('Kills the right-click context menu on single posts - no "Save As", no "View Source", no easy theft.', 'wp-content-copy-protector'); ?></p>
					</div>

					<div class="wpb-field">
						<span class="wpb-field__label"><?php echo wp_kses(__('Disable <u>RightClick</u> on', 'wp-content-copy-protector'), $wccp_free_allowed_tags); ?></span>
						<label class="wpb-check">
							<input type="checkbox" name="right_click_protection_homepage" value="checked" <?php checked($wccp_settings['right_click_protection_homepage'], 'checked'); ?>>
							<?php esc_html_e('HomePage', 'wp-content-copy-protector'); ?>
						</label>
						<p class="wpb-field__hint"><?php esc_html_e('Disables the right-click pop-up across your homepage so visitors can read, but can\'t harvest your content.', 'wp-content-copy-protector'); ?></p>
					</div>

					<div class="wpb-field">
						<span class="wpb-field__label"><?php echo wp_kses(__('Disable <u>RightClick</u> on', 'wp-content-copy-protector'), $wccp_free_allowed_tags); ?></span>
						<label class="wpb-check">
							<input type="checkbox" name="right_click_protection_pages" value="checked" <?php checked($wccp_settings['right_click_protection_pages'], 'checked'); ?>>
							<?php esc_html_e('Static pages', 'wp-content-copy-protector'); ?>
						</label>
						<p class="wpb-field__hint"><?php esc_html_e('Turns off the right-click menu on every static page - your About, Services and landing copy stay yours.', 'wp-content-copy-protector'); ?></p>
					</div>

					<div class="wpb-field">
						<span class="wpb-field__label"><?php echo wp_kses(__('Disable <u>RightClick</u> on', 'wp-content-copy-protector'), $wccp_free_allowed_tags); ?></span>
						<span class="wpb-field__locked">
							<a class="wpb-tag wpb-tag--pro" target="_blank" rel="noopener" href="<?php echo esc_url($wccp_free_pro_url); ?>"><?php wccp_free_icon('lock'); ?><?php esc_html_e('Any post type', 'wp-content-copy-protector'); ?></a>
						</span>
						<p class="wpb-field__hint"><?php echo wp_kses(__('<b>PRO</b> unlocks the same right-click lock on <u>any</u> post type you pick - WooCommerce products, listings, portfolios, courses, downloads. Sell on a marketplace? Your product photos and descriptions stop being one right-click away from a competitor.', 'wp-content-copy-protector'), $wccp_free_allowed_tags); ?></p>
					</div>
				</div>
			</div>

			<div class="wpb-card">
				<div class="wpb-card__head">
					<span class="wpb-card__ic"><?php wccp_free_icon('eye'); ?></span>
					<h2 class="wpb-card__title"><?php esc_html_e('Remaining premium options preview', 'wp-content-copy-protector'); ?></h2>
					<span class="wpb-tag wpb-tag--pro"><?php esc_html_e('PRO', 'wp-content-copy-protector'); ?></span>
				</div>
				<p class="wpb-card__desc"><?php esc_html_e('PRO gives you a rule per element - images, links, text boxes, plain text - each with its own alert message.', 'wp-content-copy-protector'); ?></p>

				<div class="wpb-shots">
					<a class="wpb-shot" target="_blank" rel="noopener" href="<?php echo esc_url($wccp_free_pro_url); ?>">
						<img src="<?php echo esc_url($wccp_free_pluginsurl); ?>/images/right-click-protection.webp" alt="<?php esc_attr_e('Right click protection options in the PRO version', 'wp-content-copy-protector'); ?>">
					</a>
				</div>

				<div class="wpb-card__foot">
					<div class="wpb-meta">
						<span class="wpb-chip"><?php wccp_free_icon('star'); ?><?php esc_html_e('Preview & Pricing', 'wp-content-copy-protector'); ?></span>
					</div>
					<a class="wpb-btn wpb-btn--primary" target="_blank" rel="noopener" href="<?php echo esc_url($wccp_free_pro_url); ?>"><?php wccp_free_icon('sparkles'); ?><?php esc_html_e('Get the PRO version', 'wp-content-copy-protector'); ?></a>
				</div>
			</div>
		</div>

		<!-- ============ Panel 3 - CSS ============ -->
		<div class="wpb-panel" id="wpb-panel-css" role="tabpanel" aria-labelledby="wpb-pill-css" tabindex="0">
			<div class="wpb-card">
				<div class="wpb-card__head">
					<span class="wpb-card__ic"><?php wccp_free_icon('brush'); ?></span>
					<h2 class="wpb-card__title"><?php esc_html_e('Protection by CSS Techniques', 'wp-content-copy-protector'); ?></h2>
					<span class="wpb-tag wpb-tag--layer"><?php esc_html_e('Premium Layer 3', 'wp-content-copy-protector'); ?></span>
				</div>
				<p class="wpb-card__desc"><?php echo wp_kses(__('In this protection layer your website will be protected by some <u>CSS</u> tricks that will word even if <u>JavaScript</u> is disabled from the browser settings', 'wp-content-copy-protector'), $wccp_free_allowed_tags); ?></p>

				<div class="wpb-fields">
					<div class="wpb-field">
						<label class="wpb-field__label" for="home_css_protection"><?php echo wp_kses(__('<b>Home Page</b> Protection by CSS', 'wp-content-copy-protector'), $wccp_free_allowed_tags); ?></label>
						<?php wccp_free_select('home_css_protection', $wccp_free_onoff, $wccp_settings['home_css_protection'], 'Disabled'); ?>
						<p class="wpb-field__hint"><?php esc_html_e('Protect your Homepage by CSS tricks', 'wp-content-copy-protector'); ?></p>
					</div>

					<div class="wpb-field">
						<label class="wpb-field__label" for="posts_css_protection"><?php echo wp_kses(__('<b>Posts</b> Protection by CSS', 'wp-content-copy-protector'), $wccp_free_allowed_tags); ?></label>
						<?php wccp_free_select('posts_css_protection', $wccp_free_onoff, $wccp_settings['posts_css_protection'], 'Disabled'); ?>
						<p class="wpb-field__hint">
							<?php esc_html_e('Protect your single posts by CSS tricks', 'wp-content-copy-protector'); ?>
							<span class="wpb-tag wpb-tag--free"><?php esc_html_e('PRO option, unlocked for free', 'wp-content-copy-protector'); ?></span>
						</p>
					</div>

					<div class="wpb-field">
						<span class="wpb-field__label"><?php echo wp_kses(__('<b>Pages</b> Protection by CSS', 'wp-content-copy-protector'), $wccp_free_allowed_tags); ?></span>
						<span class="wpb-field__locked">
							<a class="wpb-tag wpb-tag--pro" target="_blank" rel="noopener" href="<?php echo esc_url($wccp_free_pro_url); ?>"><?php wccp_free_icon('lock'); ?><?php esc_html_e('Premium', 'wp-content-copy-protector'); ?></a>
						</span>
						<p class="wpb-field__hint"><?php esc_html_e('Protect your static pages by CSS tricks', 'wp-content-copy-protector'); ?></p>
					</div>
				</div>

				<div class="wpb-card__foot">
					<div class="wpb-meta">
						<span class="wpb-chip wpb-chip--safe"><?php wccp_free_icon('check'); ?><?php esc_html_e('Keeps working with JavaScript disabled', 'wp-content-copy-protector'); ?></span>
					</div>
					<a class="wpb-btn wpb-btn--ghost" target="_blank" rel="noopener" href="<?php echo esc_url($wccp_free_pro_url); ?>"><?php wccp_free_icon('lock'); ?><?php esc_html_e('Unlock pages protection', 'wp-content-copy-protector'); ?></a>
				</div>
			</div>
		</div>

		<?php if (wccp_free_em_can()) :
			// Debug constants, read the way wp_debug_mode() applies them.
			$wccp_free_em_debug   = defined('WP_DEBUG') && WP_DEBUG;
			$wccp_free_em_log     = $wccp_free_em_debug && defined('WP_DEBUG_LOG') && WP_DEBUG_LOG;
			$wccp_free_em_display = $wccp_free_em_debug && defined('WP_DEBUG_DISPLAY') && WP_DEBUG_DISPLAY;
			$wccp_free_em_phplog  = filter_var(ini_get('log_errors'), FILTER_VALIDATE_BOOLEAN);
			$wccp_free_em_snippet = "define( 'WP_DEBUG', true );\ndefine( 'WP_DEBUG_LOG', true );\ndefine( 'WP_DEBUG_DISPLAY', false );\n@ini_set( 'display_errors', 0 );";
		?>
			<!-- ============ Panel 4 - Error Monitor ============
			     Filled by js/error-monitor.js the first time the panel opens
			     (see error-monitor.php); only the static parts live here. -->
			<div class="wpb-panel" id="wpb-panel-errors" role="tabpanel" aria-labelledby="wpb-pill-errors" tabindex="0">
				<div class="wpb-em" id="wpb-em">

					<div class="wpb-card">
						<div class="wpb-card__head">
							<span class="wpb-card__ic"><?php wccp_free_icon('activity'); ?></span>
							<h2 class="wpb-card__title"><?php esc_html_e('Error Monitor', 'wp-content-copy-protector'); ?></h2>
							<span class="wpb-tag wpb-tag--free"><?php esc_html_e('Free', 'wp-content-copy-protector'); ?></span>
						</div>
						<p class="wpb-card__desc"><?php esc_html_e('Reads the PHP and WordPress error logs of this site and groups repeated entries into single issues, so you can see what is breaking, how often, and which plugin or theme causes it.', 'wp-content-copy-protector'); ?></p>

						<!-- Filter row - scopes every tile, chart and list below it. -->
						<div class="wpb-em-bar">
							<div class="wpb-switch" role="group" aria-label="<?php esc_attr_e('Time range', 'wp-content-copy-protector'); ?>">
								<button type="button" class="wpb-switch__btn" data-em-range="7" aria-pressed="false"><?php esc_html_e('7 days', 'wp-content-copy-protector'); ?></button>
								<button type="button" class="wpb-switch__btn is-active" data-em-range="30" aria-pressed="true"><?php esc_html_e('30 days', 'wp-content-copy-protector'); ?></button>
								<button type="button" class="wpb-switch__btn" data-em-range="90" aria-pressed="false"><?php esc_html_e('90 days', 'wp-content-copy-protector'); ?></button>
							</div>
							<label class="wpb-em-select">
								<span><?php esc_html_e('Log file', 'wp-content-copy-protector'); ?></span>
								<select id="wpb-em-log">
									<option value=""><?php esc_html_e('All log files', 'wp-content-copy-protector'); ?></option>
								</select>
							</label>
							<button type="button" class="wpb-btn wpb-btn--ghost" id="wpb-em-refresh"><?php wccp_free_icon('refresh'); ?><?php esc_html_e('Refresh', 'wp-content-copy-protector'); ?></button>
							<span class="wpb-em-status" id="wpb-em-status" role="status" aria-live="polite"></span>
						</div>
					</div>

					<div id="wpb-em-alerts"></div>

					<div class="wpb-em-body" id="wpb-em-body">
						<div class="wpb-em-tiles">
							<div class="wpb-em-tile">
								<span class="wpb-em-tile__label"><?php esc_html_e('Occurrences', 'wp-content-copy-protector'); ?></span>
								<span class="wpb-em-tile__value" data-em-tile="occurrences">&ndash;</span>
								<span class="wpb-em-tile__note" data-em-note></span>
							</div>
							<div class="wpb-em-tile">
								<span class="wpb-em-tile__label"><?php esc_html_e('Unique issues', 'wp-content-copy-protector'); ?></span>
								<span class="wpb-em-tile__value" data-em-tile="issues">&ndash;</span>
								<span class="wpb-em-tile__note"><?php esc_html_e('repeated entries grouped', 'wp-content-copy-protector'); ?></span>
							</div>
							<div class="wpb-em-tile wpb-em-tile--fatal">
								<span class="wpb-em-tile__label"><?php wccp_free_icon('alert'); ?><?php esc_html_e('Fatal errors', 'wp-content-copy-protector'); ?></span>
								<span class="wpb-em-tile__value" data-em-tile="fatal">&ndash;</span>
								<span class="wpb-em-tile__note"><?php esc_html_e('pages that stopped loading', 'wp-content-copy-protector'); ?></span>
							</div>
							<div class="wpb-em-tile">
								<span class="wpb-em-tile__label"><?php wccp_free_icon('clock'); ?><?php esc_html_e('Last error', 'wp-content-copy-protector'); ?></span>
								<span class="wpb-em-tile__value" data-em-tile="last">&ndash;</span>
								<span class="wpb-em-tile__note" data-em-tile="lastLabel"></span>
							</div>
						</div>

						<div class="wpb-em-grid">
							<div class="wpb-card wpb-em-chartcard">
								<div class="wpb-card__head">
									<span class="wpb-card__ic"><?php wccp_free_icon('gauge'); ?></span>
									<h2 class="wpb-card__title"><?php esc_html_e('Errors per day', 'wp-content-copy-protector'); ?></h2>
									<div class="wpb-switch" role="group" aria-label="<?php esc_attr_e('Show the daily errors as', 'wp-content-copy-protector'); ?>">
										<button type="button" class="wpb-switch__btn is-active" data-em-view="chart" aria-pressed="true"><?php wccp_free_icon('gauge'); ?><?php esc_html_e('Chart', 'wp-content-copy-protector'); ?></button>
										<button type="button" class="wpb-switch__btn" data-em-view="table" aria-pressed="false"><?php wccp_free_icon('grid'); ?><?php esc_html_e('Table', 'wp-content-copy-protector'); ?></button>
									</div>
								</div>
								<ul class="wpb-em-legend" id="wpb-em-legend"></ul>
								<div class="wpb-em-chart" id="wpb-em-chart"></div>
								<div class="wpb-em-daytable" id="wpb-em-daytable" hidden></div>
							</div>

							<div class="wpb-card wpb-em-sourcecard">
								<div class="wpb-card__head">
									<span class="wpb-card__ic"><?php wccp_free_icon('layers'); ?></span>
									<h2 class="wpb-card__title"><?php esc_html_e('Where they come from', 'wp-content-copy-protector'); ?></h2>
								</div>
								<p class="wpb-card__desc"><?php esc_html_e('Occurrences per plugin, theme or part of WordPress.', 'wp-content-copy-protector'); ?></p>
								<ol class="wpb-em-sources" id="wpb-em-sources"></ol>
							</div>
						</div>

						<div class="wpb-card">
							<div class="wpb-card__head">
								<span class="wpb-card__ic"><?php wccp_free_icon('filter'); ?></span>
								<h2 class="wpb-card__title"><?php esc_html_e('Issues', 'wp-content-copy-protector'); ?></h2>
								<div class="wpb-switch" role="group" aria-label="<?php esc_attr_e('Sort issues by', 'wp-content-copy-protector'); ?>">
									<button type="button" class="wpb-switch__btn is-active" data-em-sort="count" aria-pressed="true"><?php esc_html_e('Most frequent', 'wp-content-copy-protector'); ?></button>
									<button type="button" class="wpb-switch__btn" data-em-sort="last" aria-pressed="false"><?php esc_html_e('Latest', 'wp-content-copy-protector'); ?></button>
									<button type="button" class="wpb-switch__btn" data-em-sort="level" aria-pressed="false"><?php esc_html_e('Most severe', 'wp-content-copy-protector'); ?></button>
								</div>
							</div>
							<p class="wpb-card__desc"><?php esc_html_e('Each issue is one cause: the same message from the same file and line, however many times it was logged. Open an issue for the full message and where it happened.', 'wp-content-copy-protector'); ?></p>
							<ul class="wpb-em-issues" id="wpb-em-issues"></ul>
							<div class="wpb-em-more" id="wpb-em-more"></div>
						</div>
					</div>

					<div class="wpb-card">
						<div class="wpb-card__head">
							<span class="wpb-card__ic"><?php wccp_free_icon('file'); ?></span>
							<h2 class="wpb-card__title"><?php esc_html_e('Log files', 'wp-content-copy-protector'); ?></h2>
						</div>
						<p class="wpb-card__desc"><?php esc_html_e('Every place this site can write errors to. Download a log to send it to a developer, or clear it once the issues are fixed so new errors stand out.', 'wp-content-copy-protector'); ?></p>
						<ul class="wpb-em-files" id="wpb-em-files"></ul>
					</div>

					<div class="wpb-card">
						<div class="wpb-card__head">
							<span class="wpb-card__ic"><?php wccp_free_icon('sliders'); ?></span>
							<h2 class="wpb-card__title"><?php esc_html_e('Debug settings', 'wp-content-copy-protector'); ?></h2>
						</div>
						<p class="wpb-card__desc"><?php esc_html_e('These settings live in wp-config.php and decide whether errors are written to a log at all. This plugin only reads them - it never edits wp-config.php.', 'wp-content-copy-protector'); ?></p>

						<ul class="wpb-em-config">
							<li>
								<code>WP_DEBUG</code>
								<?php if ($wccp_free_em_debug) : ?>
									<span class="wpb-em-state wpb-em-state--on"><?php wccp_free_icon('check'); ?><?php esc_html_e('On', 'wp-content-copy-protector'); ?></span>
								<?php else : ?>
									<span class="wpb-em-state"><?php wccp_free_icon('x'); ?><?php esc_html_e('Off', 'wp-content-copy-protector'); ?></span>
								<?php endif; ?>
								<span class="wpb-em-config__hint"><?php esc_html_e('WordPress debug mode. The two settings below only work while it is on.', 'wp-content-copy-protector'); ?></span>
							</li>
							<li>
								<code>WP_DEBUG_LOG</code>
								<?php if ($wccp_free_em_log) : ?>
									<span class="wpb-em-state wpb-em-state--on"><?php wccp_free_icon('check'); ?><?php esc_html_e('On', 'wp-content-copy-protector'); ?></span>
								<?php else : ?>
									<span class="wpb-em-state"><?php wccp_free_icon('x'); ?><?php esc_html_e('Off', 'wp-content-copy-protector'); ?></span>
								<?php endif; ?>
								<span class="wpb-em-config__hint"><?php esc_html_e('Writes WordPress errors to wp-content/debug.log (or the path you give it).', 'wp-content-copy-protector'); ?></span>
							</li>
							<li>
								<code>WP_DEBUG_DISPLAY</code>
								<?php if ($wccp_free_em_display) : ?>
									<span class="wpb-em-state wpb-em-state--bad"><?php wccp_free_icon('alert'); ?><?php esc_html_e('Shown to visitors', 'wp-content-copy-protector'); ?></span>
								<?php else : ?>
									<span class="wpb-em-state wpb-em-state--on"><?php wccp_free_icon('check'); ?><?php esc_html_e('Hidden from visitors', 'wp-content-copy-protector'); ?></span>
								<?php endif; ?>
								<span class="wpb-em-config__hint"><?php esc_html_e('When errors are shown, they are printed right on your pages. Keep it off on a live site.', 'wp-content-copy-protector'); ?></span>
							</li>
							<li>
								<code>log_errors</code>
								<?php if ($wccp_free_em_phplog) : ?>
									<span class="wpb-em-state wpb-em-state--on"><?php wccp_free_icon('check'); ?><?php esc_html_e('On', 'wp-content-copy-protector'); ?></span>
								<?php else : ?>
									<span class="wpb-em-state"><?php wccp_free_icon('x'); ?><?php esc_html_e('Off', 'wp-content-copy-protector'); ?></span>
								<?php endif; ?>
								<span class="wpb-em-config__hint"><?php esc_html_e('PHP setting on the server. When on, PHP keeps its own error log even while WP_DEBUG is off.', 'wp-content-copy-protector'); ?></span>
							</li>
						</ul>

						<?php if (! $wccp_free_em_log || $wccp_free_em_display) : ?>
							<div class="wpb-em-snippet">
								<p class="wpb-em-snippet__txt"><?php echo wp_kses(__('To log errors <b>without showing them to visitors</b>, add these lines to <u>wp-config.php</u>, just above the line <code>/* That\'s all, stop editing! */</code> (replace any existing WP_DEBUG lines):', 'wp-content-copy-protector'), array('b' => array(), 'u' => array(), 'code' => array())); ?></p>
								<div class="wpb-em-code">
									<pre><code><?php echo esc_html($wccp_free_em_snippet); ?></code></pre>
									<button type="button" class="wpb-btn wpb-btn--quiet wpb-em-copy" data-em-copy><?php wccp_free_icon('copy'); ?><span><?php esc_html_e('Copy', 'wp-content-copy-protector'); ?></span></button>
								</div>
							</div>
						<?php endif; ?>
					</div>

					<!-- Icons the script clones into the markup it builds. -->
					<template id="wpb-em-icons">
						<?php foreach (array('alert', 'check', 'info', 'clock', 'message', 'download', 'trash', 'server', 'file', 'copy') as $wccp_free_em_icon) : ?>
							<span data-icon="<?php echo esc_attr($wccp_free_em_icon); ?>"><?php wccp_free_icon($wccp_free_em_icon); ?></span>
						<?php endforeach; ?>
					</template>

					<!-- Clear log confirmation - same .wpb-modal as Restore defaults, but
					     its confirm button is not a submit: the script clears the log
					     over AJAX, so the settings form is never posted. -->
					<div class="wpb-modal" id="wpb-em-clear-modal" hidden>
						<div class="wpb-modal__veil" data-wpb-modal-close></div>
						<div class="wpb-modal__box" role="dialog" aria-modal="true" aria-labelledby="wpb-em-clear-modal__title" aria-describedby="wpb-em-clear-modal__text">
							<button type="button" class="wpb-modal__x" data-wpb-modal-close aria-label="<?php esc_attr_e('Close', 'wp-content-copy-protector'); ?>">
								<?php wccp_free_icon('x'); ?>
							</button>
							<span class="wpb-modal__ic"><?php wccp_free_icon('trash'); ?></span>
							<h2 class="wpb-modal__title" id="wpb-em-clear-modal__title"><?php esc_html_e('Clear this log file?', 'wp-content-copy-protector'); ?></h2>
							<p class="wpb-modal__text" id="wpb-em-clear-modal__text">
								<?php esc_html_e('Every entry in this file will be deleted and this cannot be undone. Download it first if a developer may still need it.', 'wp-content-copy-protector'); ?>
								<code class="wpb-em-modal__file" id="wpb-em-clear-file"></code>
								<span class="wpb-em-modal__shared" id="wpb-em-clear-shared" hidden><?php esc_html_e('This is a server-wide log, so errors from other sites on this server will be deleted too.', 'wp-content-copy-protector'); ?></span>
							</p>
							<div class="wpb-modal__acts">
								<button type="button" class="wpb-btn wpb-btn--ghost" data-wpb-modal-close><?php esc_html_e('Cancel', 'wp-content-copy-protector'); ?></button>
								<button type="button" class="wpb-btn wpb-btn--danger" id="wpb-em-clear-confirm" data-wpb-modal-close><?php wccp_free_icon('trash'); ?><?php esc_html_e('Yes, clear log', 'wp-content-copy-protector'); ?></button>
							</div>
						</div>
					</div>

				</div>
			</div>
		<?php endif; ?>

		<!-- ============ Panel 5 - More with PRO ============ -->
		<div class="wpb-panel" id="wpb-panel-pro" role="tabpanel" aria-labelledby="wpb-pill-pro" tabindex="0">
			<div class="wpb-card">
				<div class="wpb-card__head">
					<span class="wpb-card__ic"><?php wccp_free_icon('image'); ?></span>
					<h2 class="wpb-card__title"><?php esc_html_e('See what the PRO version adds', 'wp-content-copy-protector'); ?></h2>
					<span class="wpb-tag wpb-tag--pro"><?php esc_html_e('Limited-time discount', 'wp-content-copy-protector'); ?></span>
				</div>
				<p class="wpb-card__desc"><?php esc_html_e('Crazy discount offer is now running for a limited time!! You might love it', 'wp-content-copy-protector'); ?></p>

				<div class="wpb-shots">
					<a class="wpb-shot" target="_blank" rel="noopener" href="<?php echo esc_url($wccp_free_pro_url); ?>">
						<img src="<?php echo esc_url($wccp_free_pluginsurl); ?>/images/smart-phones-protection.webp" alt="<?php esc_attr_e('Smartphone protection in the PRO version', 'wp-content-copy-protector'); ?>">
					</a>
					<a class="wpb-shot" target="_blank" rel="noopener" href="<?php echo esc_url($wccp_free_pro_url); ?>">
						<img src="<?php echo esc_url($wccp_free_pluginsurl); ?>/images/watermark-adv.webp" alt="<?php esc_attr_e('Image watermarking in the PRO version', 'wp-content-copy-protector'); ?>">
					</a>
					<a class="wpb-shot" target="_blank" rel="noopener" href="<?php echo esc_url($wccp_free_pro_url); ?>">
						<img src="<?php echo esc_url($wccp_free_pluginsurl); ?>/images/watermarking-adv-examples.webp" alt="<?php esc_attr_e('Watermarking examples from the PRO version', 'wp-content-copy-protector'); ?>">
					</a>
				</div>

				<div class="wpb-card__foot">
					<div class="wpb-meta">
						<span class="wpb-chip wpb-chip--safe"><?php wccp_free_icon('check'); ?><?php esc_html_e('30-Day Money Back', 'wp-content-copy-protector'); ?></span>
						<span class="wpb-sep"></span>
						<span class="wpb-chip"><?php wccp_free_icon('star'); ?><?php esc_html_e('Rated 4.9 by hundreds', 'wp-content-copy-protector'); ?></span>
					</div>
					<a class="wpb-btn wpb-btn--primary" target="_blank" rel="noopener" href="<?php echo esc_url($wccp_free_pro_url); ?>"><?php wccp_free_icon('sparkles'); ?><?php esc_html_e('See it now', 'wp-content-copy-protector'); ?></a>
				</div>
			</div>

			<div class="wpb-card">
				<div class="wpb-card__head">
					<span class="wpb-card__ic"><?php wccp_free_icon('sparkles'); ?></span>
					<h2 class="wpb-card__title"><?php esc_html_e('Premium features', 'wp-content-copy-protector'); ?></h2>
					<span class="wpb-tag wpb-tag--pro"><?php wccp_free_icon('lock'); ?><?php esc_html_e('PRO only', 'wp-content-copy-protector'); ?></span>
					<span class="wpb-tag wpb-tag--layer"><?php wccp_free_icon('layers'); ?><?php esc_html_e('7 protection modules', 'wp-content-copy-protector'); ?></span>
					<div class="wpb-switch" role="group" aria-label="<?php esc_attr_e('Choose how the premium features are shown', 'wp-content-copy-protector'); ?>">
						<button type="button" class="wpb-switch__btn is-active" data-view="wpb-view-plans" aria-pressed="true"><?php wccp_free_icon('grid'); ?><?php esc_html_e('Plans', 'wp-content-copy-protector'); ?></button>
						<button type="button" class="wpb-switch__btn" data-view="wpb-view-detailed" aria-pressed="false"><?php wccp_free_icon('layers'); ?><?php esc_html_e('Detailed info', 'wp-content-copy-protector'); ?></button>
					</div>
				</div>

				<!-- View A - the three plans (default) -->
				<div class="wpb-view is-active" id="wpb-view-plans">
					<p class="wpb-card__desc"><?php esc_html_e('Three ways to run the plugin. You are on the free plan - the paid plans keep everything you already have and add the modules below on top of it.', 'wp-content-copy-protector'); ?></p>

					<!-- The prices below are the full list prices, so the running promotion is announced right next to them. -->
					<div class="wpb-plans__promo">
						<span class="wpb-plans__promo-ic"><?php wccp_free_icon('zap'); ?></span>
						<p class="wpb-plans__promo-txt">
							<b><?php esc_html_e('These are the full prices, with no discount applied.', 'wp-content-copy-protector'); ?></b>
							<?php esc_html_e('A discount of up to 50% is waiting for you on our website - check the running offer before you buy.', 'wp-content-copy-protector'); ?>
						</p>
					</div>

					<div class="wpb-plans">

						<div class="wpb-plan wpb-plan--current">
							<div class="wpb-plan__head">
								<span class="wpb-plan__ic"><?php wccp_free_icon('copy'); ?></span>
								<div class="wpb-plan__id">
									<h3 class="wpb-plan__name"><?php esc_html_e('FREE', 'wp-content-copy-protector'); ?></h3>
									<p class="wpb-plan__note"><?php esc_html_e('Basic protection for your content', 'wp-content-copy-protector'); ?></p>
								</div>
							</div>

							<p class="wpb-plan__price">
								<span class="wpb-plan__amount"><span class="wpb-plan__cur">$</span>0</span>
								<span class="wpb-plan__per"><?php esc_html_e('/ forever', 'wp-content-copy-protector'); ?></span>
							</p>
							<span class="wpb-tag wpb-tag--free wpb-plan__badge"><?php wccp_free_icon('check'); ?><?php esc_html_e('Current plan', 'wp-content-copy-protector'); ?></span>

							<ul class="wpb-plan__list">
								<li><?php wccp_free_icon('check'); ?><?php esc_html_e('Disable right-click', 'wp-content-copy-protector'); ?></li>
								<li><?php wccp_free_icon('check'); ?><?php esc_html_e('Prevent text selection', 'wp-content-copy-protector'); ?></li>
								<li><?php wccp_free_icon('check'); ?><?php esc_html_e('Basic alert messages', 'wp-content-copy-protector'); ?></li>
								<li><?php wccp_free_icon('check'); ?><?php esc_html_e('CSS protection layer', 'wp-content-copy-protector'); ?></li>
								<li><?php wccp_free_icon('check'); ?><?php esc_html_e('Works on your site content', 'wp-content-copy-protector'); ?></li>
							</ul>

							<span class="wpb-plan__cta wpb-plan__cta--current"><?php esc_html_e('Current plan', 'wp-content-copy-protector'); ?></span>
						</div>

						<div class="wpb-plan">
							<div class="wpb-plan__head">
								<span class="wpb-plan__ic wpb-plan__ic--paid"><?php wccp_free_icon('shield'); ?></span>
								<div class="wpb-plan__id">
									<h3 class="wpb-plan__name wpb-plan__name--paid"><?php esc_html_e('ESSENTIAL', 'wp-content-copy-protector'); ?></h3>
									<p class="wpb-plan__note"><?php esc_html_e('Advanced control and flexibility', 'wp-content-copy-protector'); ?></p>
								</div>
							</div>

							<p class="wpb-plan__price">
								<span class="wpb-plan__amount"><span class="wpb-plan__cur">$</span>58</span>
								<span class="wpb-plan__per"><?php esc_html_e('one-time', 'wp-content-copy-protector'); ?></span>
							</p>
							<span class="wpb-tag wpb-tag--layer wpb-plan__badge"><?php wccp_free_icon('clock'); ?><?php esc_html_e('Lifetime license', 'wp-content-copy-protector'); ?></span>
							<p class="wpb-plan__care"><?php wccp_free_icon('refresh'); ?><?php esc_html_e('Support & updates $1 / month, paid as $9 per year.', 'wp-content-copy-protector'); ?></p>

							<ul class="wpb-plan__list">
								<li><?php wccp_free_icon('check'); ?><?php esc_html_e('Everything in FREE', 'wp-content-copy-protector'); ?></li>
								<li><?php wccp_free_icon('check'); ?><?php esc_html_e('Per-content protection rules', 'wp-content-copy-protector'); ?></li>
								<li><?php wccp_free_icon('check'); ?><?php esc_html_e('Overlay protection & triggers', 'wp-content-copy-protector'); ?></li>
								<li><?php wccp_free_icon('check'); ?><?php esc_html_e('Blocked shortcuts & print protection', 'wp-content-copy-protector'); ?></li>
								<li><?php wccp_free_icon('check'); ?><?php esc_html_e('Advanced exclusion options', 'wp-content-copy-protector'); ?></li>
								<li><?php wccp_free_icon('check'); ?><?php esc_html_e('Admin workflow tools', 'wp-content-copy-protector'); ?></li>
								<li><?php wccp_free_icon('check'); ?><?php esc_html_e('Priority email support', 'wp-content-copy-protector'); ?></li>
							</ul>

							<a class="wpb-btn wpb-btn--ghost wpb-plan__cta" target="_blank" rel="noopener" href="<?php echo esc_url($wccp_free_pro_url); ?>"><?php esc_html_e('Upgrade to ESSENTIAL', 'wp-content-copy-protector'); ?></a>
						</div>

						<div class="wpb-plan wpb-plan--popular">
							<span class="wpb-plan__ribbon"><?php wccp_free_icon('star'); ?><?php esc_html_e('Most Popular', 'wp-content-copy-protector'); ?></span>

							<div class="wpb-plan__head">
								<span class="wpb-plan__ic wpb-plan__ic--paid"><?php wccp_free_icon('crown'); ?></span>
								<div class="wpb-plan__id">
									<h3 class="wpb-plan__name wpb-plan__name--paid"><?php esc_html_e('COMPLETE', 'wp-content-copy-protector'); ?></h3>
									<p class="wpb-plan__note"><?php esc_html_e('Full protection. Maximum control.', 'wp-content-copy-protector'); ?></p>
								</div>
							</div>

							<p class="wpb-plan__price">
								<span class="wpb-plan__amount"><span class="wpb-plan__cur">$</span>98</span>
								<span class="wpb-plan__per"><?php esc_html_e('one-time', 'wp-content-copy-protector'); ?></span>
							</p>
							<span class="wpb-tag wpb-tag--layer wpb-plan__badge"><?php wccp_free_icon('clock'); ?><?php esc_html_e('Lifetime license', 'wp-content-copy-protector'); ?></span>
							<p class="wpb-plan__care"><?php wccp_free_icon('refresh'); ?><?php esc_html_e('Support & updates $1 / month, paid as $9 per year.', 'wp-content-copy-protector'); ?></p>

							<ul class="wpb-plan__list">
								<li><?php wccp_free_icon('check'); ?><?php esc_html_e('Everything in ESSENTIAL', 'wp-content-copy-protector'); ?></li>
								<li><?php wccp_free_icon('check'); ?><?php esc_html_e('Full watermark protection', 'wp-content-copy-protector'); ?></li>
								<li><?php wccp_free_icon('check'); ?><?php esc_html_e('Watermark presets & custom rules', 'wp-content-copy-protector'); ?></li>
								<li><?php wccp_free_icon('check'); ?><?php esc_html_e('Logo, text and signature watermarking', 'wp-content-copy-protector'); ?></li>
								<li><?php wccp_free_icon('check'); ?><?php esc_html_e('Automatic watermark colors & image filters', 'wp-content-copy-protector'); ?></li>
								<li><?php wccp_free_icon('check'); ?><?php esc_html_e('Hotlinked images watermarked too', 'wp-content-copy-protector'); ?></li>
								<li><?php wccp_free_icon('check'); ?><?php esc_html_e('Advanced targeting & scheduling', 'wp-content-copy-protector'); ?></li>
								<li><?php wccp_free_icon('check'); ?><?php esc_html_e('Beta layers: DevTools & extension blocking', 'wp-content-copy-protector'); ?></li>
								<li><?php wccp_free_icon('check'); ?><?php esc_html_e('Multisite support', 'wp-content-copy-protector'); ?></li>
								<li><?php wccp_free_icon('check'); ?><?php esc_html_e('Premium support & updates', 'wp-content-copy-protector'); ?></li>
							</ul>

							<a class="wpb-btn wpb-btn--primary wpb-plan__cta" target="_blank" rel="noopener" href="<?php echo esc_url($wccp_free_pro_url); ?>"><?php esc_html_e('Upgrade to COMPLETE', 'wp-content-copy-protector'); ?></a>
						</div>

					</div>

					<div class="wpb-plans__trust">
						<span class="wpb-chip wpb-chip--safe"><?php wccp_free_icon('shield'); ?><?php esc_html_e('30-Day Money Back Guarantee', 'wp-content-copy-protector'); ?></span>
						<span class="wpb-sep"></span>
						<span class="wpb-chip"><?php wccp_free_icon('clock'); ?><?php esc_html_e('Paid plans are lifetime licenses', 'wp-content-copy-protector'); ?></span>
						<span class="wpb-sep"></span>
						<span class="wpb-chip"><?php wccp_free_icon('star'); ?><?php esc_html_e('Rated 4.9/5 by 443 customers', 'wp-content-copy-protector'); ?></span>
						<span class="wpb-sep"></span>
						<span class="wpb-chip"><?php wccp_free_icon('layers'); ?><?php esc_html_e('Works with major themes and builders', 'wp-content-copy-protector'); ?></span>
					</div>
				</div>

				<!-- View B - the module-by-module breakdown -->
				<div class="wpb-view" id="wpb-view-detailed">
					<p class="wpb-card__desc"><?php esc_html_e('The PRO version keeps every free layer running and adds seven modules on top of them. Here is the full list, module by module.', 'wp-content-copy-protector'); ?></p>

					<div class="wpb-pro-first">
						<span class="wpb-pro-first__num">1</span>
						<span class="wpb-pro-first__ic"><?php wccp_free_icon('shield'); ?></span>
						<div class="wpb-pro-first__txt">
							<h3><?php esc_html_e('Everything in the free version', 'wp-content-copy-protector'); ?></h3>
							<p><?php esc_html_e('JavaScript copy protection, right-click protection and CSS protection keep working exactly as they do now. PRO is built on top of them, nothing is taken away.', 'wp-content-copy-protector'); ?></p>
						</div>
						<span class="wpb-tag wpb-tag--free"><?php wccp_free_icon('check'); ?><?php esc_html_e('Included', 'wp-content-copy-protector'); ?></span>
					</div>

					<div class="wpb-group">
						<h3 class="wpb-group__head">
							<span class="wpb-group__num">2</span>
							<span class="wpb-group__ic"><?php wccp_free_icon('pointer'); ?></span>
							<span class="wpb-group__title"><?php esc_html_e('Selection Protection', 'wp-content-copy-protector'); ?></span>
							<span class="wpb-group__n"><?php wccp_free_icon('check'); ?><?php esc_html_e('8 features', 'wp-content-copy-protector'); ?></span>
						</h3>
						<ul class="wpb-list wpb-list--pro">
							<li><?php wccp_free_icon('layers'); ?><?php esc_html_e('Apply text-selection protection to any public content type: homepage, archives, categories, tags, taxonomies, author pages, search results, posts, pages, attachments, 404 pages, login pages and WooCommerce pages.', 'wp-content-copy-protector'); ?></li>
							<li><?php wccp_free_icon('keyboard'); ?><?php esc_html_e('Block Ctrl+A, Ctrl+C, Ctrl+X, Ctrl+V, Ctrl+S, Ctrl+U, Ctrl+P, Print Screen, F12 / DevTools and drag and drop.', 'wp-content-copy-protector'); ?></li>
							<li><?php wccp_free_icon('message'); ?><?php esc_html_e('Write your own message for blocked selection, blocked shortcuts, special keys and printing.', 'wp-content-copy-protector'); ?></li>
							<li><?php wccp_free_icon('printer'); ?><?php esc_html_e('Custom print-protection output styling with a configurable print-disabled message.', 'wp-content-copy-protector'); ?></li>
							<li><?php wccp_free_icon('code'); ?><?php esc_html_e('Allow selection inside code blocks so visitors can still use your code snippets.', 'wp-content-copy-protector'); ?></li>
							<li><?php wccp_free_icon('copy'); ?><?php esc_html_e('Add a one-click copy button to code blocks without opening selection for the whole page.', 'wp-content-copy-protector'); ?></li>
							<li><?php wccp_free_icon('palette'); ?><?php esc_html_e('Style the alert box with ready-made color palettes or manual colors, border width, glow, shadow strength, font size, display time and alert width and height.', 'wp-content-copy-protector'); ?></li>
							<li><?php wccp_free_icon('eye'); ?><?php esc_html_e('Preview the alert message straight from the settings page before you save.', 'wp-content-copy-protector'); ?></li>
						</ul>
					</div>

					<div class="wpb-group">
						<h3 class="wpb-group__head">
							<span class="wpb-group__num">3</span>
							<span class="wpb-group__ic"><?php wccp_free_icon('mouse'); ?></span>
							<span class="wpb-group__title"><?php esc_html_e('Right Click Protection', 'wp-content-copy-protector'); ?></span>
							<span class="wpb-group__n"><?php wccp_free_icon('check'); ?><?php esc_html_e('4 features', 'wp-content-copy-protector'); ?></span>
						</h3>
						<ul class="wpb-list wpb-list--pro">
							<li><?php wccp_free_icon('layers'); ?><?php esc_html_e('Apply right-click protection to the content types you pick, using the same dynamic content-type picker.', 'wp-content-copy-protector'); ?></li>
							<li><?php wccp_free_icon('mouse'); ?><?php esc_html_e('Block the context menu on images, links, text content, headings, text areas, text fields, empty spaces, videos, code blocks, canvas elements and audio players.', 'wp-content-copy-protector'); ?></li>
							<li><?php wccp_free_icon('message'); ?><?php esc_html_e('Write a separate alert message for every protected HTML element type.', 'wp-content-copy-protector'); ?></li>
							<li><?php wccp_free_icon('grid'); ?><?php esc_html_e('Read an active-count indicator for the protected right-click targets inside the control panel.', 'wp-content-copy-protector'); ?></li>
						</ul>
					</div>

					<div class="wpb-group">
						<h3 class="wpb-group__head">
							<span class="wpb-group__num">4</span>
							<span class="wpb-group__ic"><?php wccp_free_icon('layers'); ?></span>
							<span class="wpb-group__title"><?php esc_html_e('Overlay Protection', 'wp-content-copy-protector'); ?></span>
							<span class="wpb-group__n"><?php wccp_free_icon('check'); ?><?php esc_html_e('4 features', 'wp-content-copy-protector'); ?></span>
						</h3>
						<ul class="wpb-list wpb-list--pro">
							<li><?php wccp_free_icon('layers'); ?><?php esc_html_e('Put transparent overlays over your images on the public content types you choose.', 'wp-content-copy-protector'); ?></li>
							<li><?php wccp_free_icon('link'); ?><?php esc_html_e('Remove image attachment links automatically when they are not needed.', 'wp-content-copy-protector'); ?></li>
							<li><?php wccp_free_icon('shield'); ?><?php esc_html_e('Decide what happens when JavaScript is disabled, including hiding the protected content behind your own message.', 'wp-content-copy-protector'); ?></li>
							<li><?php wccp_free_icon('crop'); ?><?php esc_html_e('Overlays follow the real rendered image size and position, so responsive and absolutely positioned images stay aligned after a resize.', 'wp-content-copy-protector'); ?></li>
						</ul>
					</div>

					<div class="wpb-group">
						<h3 class="wpb-group__head">
							<span class="wpb-group__num">5</span>
							<span class="wpb-group__ic"><?php wccp_free_icon('droplet'); ?></span>
							<span class="wpb-group__title"><?php esc_html_e('Watermark Protection', 'wp-content-copy-protector'); ?></span>
							<span class="wpb-group__n"><?php wccp_free_icon('check'); ?><?php esc_html_e('16 features', 'wp-content-copy-protector'); ?></span>
						</h3>
						<ul class="wpb-list wpb-list--pro">
							<li><?php wccp_free_icon('globe'); ?><?php esc_html_e('Watermark hotlinked images, images loaded on your own site, or both, through rewrite rules.', 'wp-content-copy-protector'); ?></li>
							<li><?php wccp_free_icon('server'); ?><?php esc_html_e('Force watermarking on non-Apache servers when the rewrite rules cannot be applied automatically.', 'wp-content-copy-protector'); ?></li>
							<li><?php wccp_free_icon('refresh'); ?><?php esc_html_e('Turn the watermark image cache on, or clear it, right from the settings page.', 'wp-content-copy-protector'); ?></li>
							<li><?php wccp_free_icon('image'); ?><?php esc_html_e('Stamp a logo watermark with a percentage-based size and ready-made position presets.', 'wp-content-copy-protector'); ?></li>
							<li><?php wccp_free_icon('type'); ?><?php esc_html_e('Add central text, repeated text and signature text over your images.', 'wp-content-copy-protector'); ?></li>
							<li><?php wccp_free_icon('book'); ?><?php esc_html_e('Pick a separate font for the central text, the repeated text and the signature, including bundled Latin and Arabic-capable fonts.', 'wp-content-copy-protector'); ?></li>
							<li><?php wccp_free_icon('palette'); ?><?php esc_html_e('Use manual text colors, or automatic color mode that adapts to the brightness of every image.', 'wp-content-copy-protector'); ?></li>
							<li><?php wccp_free_icon('sliders'); ?><?php esc_html_e('Control font size, transparency, rotation, repeated-text spacing, signature Y position and signature alignment.', 'wp-content-copy-protector'); ?></li>
							<li><?php wccp_free_icon('brush'); ?><?php esc_html_e('Add a signature background band with separate main and top colors and independent transparency values.', 'wp-content-copy-protector'); ?></li>
							<li><?php wccp_free_icon('droplet'); ?><?php esc_html_e('Apply image filters to watermarked images: Blur, Grayscale, Negate, Brightness or None.', 'wp-content-copy-protector'); ?></li>
							<li><?php wccp_free_icon('eye'); ?><?php esc_html_e('Test your settings with the live preview on light, medium and dark sample images before saving.', 'wp-content-copy-protector'); ?></li>
							<li><?php wccp_free_icon('save'); ?><?php esc_html_e('Save and load watermark presets, stored in a dedicated JSON file with a fallback through the settings form.', 'wp-content-copy-protector'); ?></li>
							<li><?php wccp_free_icon('file'); ?><?php esc_html_e('Watermark indexed and palette PNG images and single-frame GIF images reliably.', 'wp-content-copy-protector'); ?></li>
							<li><?php wccp_free_icon('crop'); ?><?php esc_html_e('Keep banner-like extreme image sizes out of watermarking with your own ratio and size limits.', 'wp-content-copy-protector'); ?></li>
							<li><?php wccp_free_icon('info'); ?><?php esc_html_e('Use the Watermark Testing page to test every common image format, inspect server data and read the generated .htaccess content.', 'wp-content-copy-protector'); ?></li>
							<li><?php wccp_free_icon('gauge'); ?><?php esc_html_e('Watermarking is 78% faster, with safe atomic .htaccess writes that never clear the file mid-way.', 'wp-content-copy-protector'); ?></li>
						</ul>
					</div>

					<div class="wpb-group">
						<h3 class="wpb-group__head">
							<span class="wpb-group__num">6</span>
							<span class="wpb-group__ic"><?php wccp_free_icon('filter'); ?></span>
							<span class="wpb-group__title"><?php esc_html_e('Exclusions and Targeting', 'wp-content-copy-protector'); ?></span>
							<span class="wpb-group__n"><?php wccp_free_icon('check'); ?><?php esc_html_e('10 features', 'wp-content-copy-protector'); ?></span>
						</h3>
						<ul class="wpb-list wpb-list--pro">
							<li><?php wccp_free_icon('users'); ?><?php esc_html_e('Exclude the user roles you choose from protection.', 'wp-content-copy-protector'); ?></li>
							<li><?php wccp_free_icon('grid'); ?><?php esc_html_e('Exclude selected post types and categories.', 'wp-content-copy-protector'); ?></li>
							<li><?php wccp_free_icon('crop'); ?><?php esc_html_e('Exclude registered WordPress image sizes from watermarking.', 'wp-content-copy-protector'); ?></li>
							<li><?php wccp_free_icon('link'); ?><?php esc_html_e('Exclude single URLs, one per line, including wildcard URLs that end with a slash and a star.', 'wp-content-copy-protector'); ?></li>
							<li><?php wccp_free_icon('search'); ?><?php esc_html_e('Search your pages and posts from the URL picker and append their permalinks to the URL exclude list.', 'wp-content-copy-protector'); ?></li>
							<li><?php wccp_free_icon('globe'); ?><?php esc_html_e('Exclude online services, domains or user agents that must never be blocked.', 'wp-content-copy-protector'); ?></li>
							<li><?php wccp_free_icon('image'); ?><?php esc_html_e('Exclude images from watermarking by file name or by a fragment of the file name.', 'wp-content-copy-protector'); ?></li>
							<li><?php wccp_free_icon('brush'); ?><?php esc_html_e('Exclude CSS classes from selection protection.', 'wp-content-copy-protector'); ?></li>
							<li><?php wccp_free_icon('filter'); ?><?php esc_html_e('Turn on Opposite Mode with the URL Included List so protection runs only on the URLs you list.', 'wp-content-copy-protector'); ?></li>
							<li><?php wccp_free_icon('zap'); ?><?php esc_html_e('Scan Site Images: scan the homepage and blog HTML, review the detected images in a popup grid and click any image to add its file name to the manual exclusion list, without duplicates.', 'wp-content-copy-protector'); ?></li>
						</ul>
					</div>

					<div class="wpb-group">
						<h3 class="wpb-group__head">
							<span class="wpb-group__num">7</span>
							<span class="wpb-group__ic"><?php wccp_free_icon('sliders'); ?></span>
							<span class="wpb-group__title"><?php esc_html_e('Admin Tools and Workflow', 'wp-content-copy-protector'); ?></span>
							<span class="wpb-group__n"><?php wccp_free_icon('check'); ?><?php esc_html_e('11 features', 'wp-content-copy-protector'); ?></span>
						</h3>
						<ul class="wpb-list wpb-list--pro">
							<li><?php wccp_free_icon('sliders'); ?><?php esc_html_e('Light-mode settings interface with tabs for Selection, Right Click, Overlay, Watermark, Exclusion, Custom Settings, Beta and Contact.', 'wp-content-copy-protector'); ?></li>
							<li><?php wccp_free_icon('clock'); ?><?php esc_html_e('The last open tab is reopened for you after a save or after navigation.', 'wp-content-copy-protector'); ?></li>
							<li><?php wccp_free_icon('grid'); ?><?php esc_html_e('The tabbar stays on a single row on narrow admin screens by collapsing trailing tabs into icon-only buttons.', 'wp-content-copy-protector'); ?></li>
							<li><?php wccp_free_icon('search'); ?><?php esc_html_e('Searchable chip pickers for content types, roles, categories and image sizes, with selected rows kept in view.', 'wp-content-copy-protector'); ?></li>
							<li><?php wccp_free_icon('undo'); ?><?php esc_html_e('Restore the default settings from the control panel with one click.', 'wp-content-copy-protector'); ?></li>
							<li><?php wccp_free_icon('info'); ?><?php esc_html_e('Show or hide the plugin icon in the top admin bar and read the live protection status from its menu, including the blocked shortcut keys.', 'wp-content-copy-protector'); ?></li>
							<li><?php wccp_free_icon('code'); ?><?php esc_html_e('Turn on Developer Mode for debugging.', 'wp-content-copy-protector'); ?></li>
							<li><?php wccp_free_icon('zap'); ?><?php esc_html_e('Remove conflicting CSS or JS files from the control panel by listing file fragments in the Beta tab.', 'wp-content-copy-protector'); ?></li>
							<li><?php wccp_free_icon('shield'); ?><?php esc_html_e('Beta protections that interrupt open DevTools panels and common copy and paste browser extensions.', 'wp-content-copy-protector'); ?></li>
							<li><?php wccp_free_icon('brush'); ?><?php esc_html_e('Keep your own custom CSS in the Custom Settings tab.', 'wp-content-copy-protector'); ?></li>
							<li><?php wccp_free_icon('life'); ?><?php esc_html_e('Contact tab with built-in FAQ, documentation, tutorials and priority support links.', 'wp-content-copy-protector'); ?></li>
						</ul>
					</div>

					<div class="wpb-group">
						<h3 class="wpb-group__head">
							<span class="wpb-group__num">8</span>
							<span class="wpb-group__ic"><?php wccp_free_icon('globe'); ?></span>
							<span class="wpb-group__title"><?php esc_html_e('Compatibility and Platform', 'wp-content-copy-protector'); ?></span>
							<span class="wpb-group__n"><?php wccp_free_icon('check'); ?><?php esc_html_e('8 features', 'wp-content-copy-protector'); ?></span>
						</h3>
						<ul class="wpb-list wpb-list--pro">
							<li><?php wccp_free_icon('phone'); ?><?php esc_html_e('Works on smartphones and tablets, including iPhone and iPad.', 'wp-content-copy-protector'); ?></li>
							<li><?php wccp_free_icon('layers'); ?><?php esc_html_e('Compatible with Elementor, SiteOrigin, Beaver Builder, the WordPress preview mode and wpDiscuz.', 'wp-content-copy-protector'); ?></li>
							<li><?php wccp_free_icon('globe'); ?><?php esc_html_e('Compatible with all major theme frameworks and all major browsers.', 'wp-content-copy-protector'); ?></li>
							<li><?php wccp_free_icon('server'); ?><?php esc_html_e('Multisite installations are supported.', 'wp-content-copy-protector'); ?></li>
							<li><?php wccp_free_icon('code'); ?><?php esc_html_e('Compatible with PHP 8.2 and 8.3, ZeptoJS and the jQuery slim builds.', 'wp-content-copy-protector'); ?></li>
							<li><?php wccp_free_icon('pointer'); ?><?php esc_html_e('Set a different protection level per page or per post, straight from the admin bar.', 'wp-content-copy-protector'); ?></li>
							<li><?php wccp_free_icon('shield'); ?><?php esc_html_e('Extra .htaccess rules block image-grabbing browser extensions and PHP files inside wp-content, except watermark.php.', 'wp-content-copy-protector'); ?></li>
							<li><?php wccp_free_icon('type'); ?><?php esc_html_e('Translation ready, with Arabic, Russian and RTL support for watermark text.', 'wp-content-copy-protector'); ?></li>
						</ul>
					</div>
				</div>

				<div class="wpb-card__foot">
					<p class="wpb-formbar__note"><?php wccp_free_icon('mail'); ?>
						<?php
						printf(
							/* translators: %s: link to the mailing list sign-up page. */
							esc_html__('%s to our mailing list to get flash discounts', 'wp-content-copy-protector'),
							'<a target="_blank" rel="noopener" href="https://www.wp-buy.com/wpccp-subscribe">' . esc_html__('Subscribe', 'wp-content-copy-protector') . '</a>'
						);
						?>
					</p>
					<a class="wpb-btn wpb-btn--primary" target="_blank" rel="noopener" href="<?php echo esc_url($wccp_free_pro_url); ?>"><?php wccp_free_icon('sparkles'); ?><?php esc_html_e('See the premium plans', 'wp-content-copy-protector'); ?></a>
				</div>
			</div>
		</div>

		<!-- ============ Sticky action bar ============ -->
		<div class="wpb-formbar">
			<p class="wpb-formbar__note"><?php wccp_free_icon('info'); ?><?php esc_html_e('Use CTRL+F5 on the front end after saving to clear the cached scripts.', 'wp-content-copy-protector'); ?></p>
			<div class="wpb-actions">
				<input type="submit" class="wpb-btn wpb-btn--quiet" value="<?php esc_attr_e('Restore defaults', 'wp-content-copy-protector'); ?>" name="Restore_defaults" data-wpb-modal="wpb-reset-modal">
				<input type="button" class="wpb-btn wpb-btn--ghost" value="<?php esc_attr_e('Preview alert message', 'wp-content-copy-protector'); ?>" onclick="show_wpcp_message('<?php echo esc_js(__('This is a preview message (do not forget to save changes)', 'wp-content-copy-protector')); ?>');" name="B5">
				<button type="submit" class="wpb-btn wpb-btn--primary" name="Save_settings" value="1"><?php wccp_free_icon('save'); ?><?php esc_html_e('Save Settings', 'wp-content-copy-protector'); ?></button>
			</div>
		</div>
		<!-- ============ Restore defaults confirmation - .wpb-modal ============
		     Lives inside the form so its confirm button is a real submit
		     carrying name="Restore_defaults" through the nonce check above.
		     Hidden until the JS opens it, so with JS off the formbar button
		     keeps submitting straight away exactly as it always did. -->
		<div class="wpb-modal" id="wpb-reset-modal" hidden>
			<div class="wpb-modal__veil" data-wpb-modal-close></div>
			<div class="wpb-modal__box" role="dialog" aria-modal="true" aria-labelledby="wpb-reset-modal__title" aria-describedby="wpb-reset-modal__text">
				<button type="button" class="wpb-modal__x" data-wpb-modal-close aria-label="<?php esc_attr_e('Close', 'wp-content-copy-protector'); ?>">
					<?php wccp_free_icon('x'); ?>
				</button>
				<span class="wpb-modal__ic"><?php wccp_free_icon('alert'); ?></span>
				<h2 class="wpb-modal__title" id="wpb-reset-modal__title"><?php esc_html_e('Restore the default settings?', 'wp-content-copy-protector'); ?></h2>
				<p class="wpb-modal__text" id="wpb-reset-modal__text"><?php esc_html_e('Every option on this page goes back to its default value. Your current settings will be lost and this cannot be undone.', 'wp-content-copy-protector'); ?></p>
				<div class="wpb-modal__acts">
					<button type="button" class="wpb-btn wpb-btn--ghost" data-wpb-modal-close><?php esc_html_e('Cancel', 'wp-content-copy-protector'); ?></button>
					<button type="submit" class="wpb-btn wpb-btn--danger" name="Restore_defaults" value="1"><?php wccp_free_icon('undo'); ?><?php esc_html_e('Yes, restore defaults', 'wp-content-copy-protector'); ?></button>
				</div>
			</div>
		</div>

	</form>

	<!-- ============ Footer CTA - .wpb-shop__help ============ -->
	<div class="wpb-help">
		<div class="wpb-help-col">
			<span class="wpb-help-ic"><?php wccp_free_icon('life'); ?></span>
			<div class="wpb-help-col__txt">
				<h3><?php esc_html_e('Need a hand?', 'wp-content-copy-protector'); ?></h3>
				<p><?php esc_html_e('We reply every working day.', 'wp-content-copy-protector'); ?></p>
			</div>
			<a class="wpb-help-btn" target="_blank" rel="noopener" href="<?php echo esc_url($wccp_free_support_url); ?>"><?php esc_html_e('Get support', 'wp-content-copy-protector'); ?></a>
		</div>

		<span class="wpb-help-sep"></span>

		<div class="wpb-help-col">
			<span class="wpb-help-ic"><?php wccp_free_icon('book'); ?></span>
			<div class="wpb-help-col__txt">
				<h3><?php esc_html_e('How it works', 'wp-content-copy-protector'); ?></h3>
				<p><?php esc_html_e('Read the docs before you switch a layer on.', 'wp-content-copy-protector'); ?></p>
			</div>
			<a class="wpb-help-btn" target="_blank" rel="noopener" href="<?php echo esc_url($wccp_free_docs_url); ?>"><?php esc_html_e('Help topics', 'wp-content-copy-protector'); ?></a>
		</div>

		<span class="wpb-help-sep"></span>

		<div class="wpb-help-col">
			<span class="wpb-help-ic"><?php wccp_free_icon('star'); ?></span>
			<div class="wpb-help-col__txt">
				<h3><?php esc_html_e('Enjoying the plugin?', 'wp-content-copy-protector'); ?></h3>
				<p><?php esc_html_e('It takes a minute and helps a lot.', 'wp-content-copy-protector'); ?></p>
			</div>
			<a class="wpb-help-btn" target="_blank" rel="noopener" href="https://wordpress.org/support/plugin/wp-content-copy-protector/reviews/#new-post"><?php esc_html_e('Leave a review', 'wp-content-copy-protector'); ?></a>
		</div>
	</div>
</div>
