<?php
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly
if ( !current_user_can('edit_others_pages') ) { //protection for admin content
	exit(0);
} ?>
<?php
//define all variables the needed alot
include 'the_globals.php';
include 'notifications.php';
add_action('admin_footer','wccp_free_alert_message');

if(isset($_POST["Restore_defaults"]))
{
	check_admin_referer( 'Restore_defaults_nonce', '_Restore_defaults' );

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to change these settings.', 'wp-content-copy-protector' ), 403 );
	}

	update_option("wccp_settings" , "");
	wp_safe_redirect( admin_url( 'admin.php?page=wccpoptionspro' ) );
	exit;
}

if(isset($_POST["Save_settings"]))
{
	check_admin_referer( 'Save_settings_nonce', '_Save_settings' );

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to change these settings.', 'wp-content-copy-protector' ), 403 );
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
	foreach ( $wccp_free_text_fields as $wccp_free_field ) {
		$wccp_free_posted[ $wccp_free_field ] = isset( $_POST[ $wccp_free_field ] ) ? sanitize_text_field( wp_unslash( $_POST[ $wccp_free_field ] ) ) : '';
	}

	// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized by wccp_sanitize().
	$wccp_free_posted['smessage']     = isset( $_POST['smessage'] ) ? wccp_sanitize( wp_unslash( $_POST['smessage'] ), 'textbox' ) : '';
	// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized by wccp_sanitize().
	$wccp_free_posted['prnt_scr_msg'] = isset( $_POST['prnt_scr_msg'] ) ? wccp_sanitize( wp_unslash( $_POST['prnt_scr_msg'] ), 'text' ) : '';

	//----------------------------------------------------Get the options array values
	$wccp_settings = 
	Array (
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

		if(get_option('wccp_settings') !== null) {
		    update_option( 'wccp_settings' , $wccp_settings );
		} else {
		    add_option( 'wccp_settings', $wccp_settings, '', 'yes' );
		}
	wp_safe_redirect( admin_url( 'admin.php?page=wccpoptionspro' ) );
	exit;
}

$wccp_settings = wccp_read_options();
$wccp_free_allowed_tags = array( 'u' => array(), 'b' => array(), 'strong' => array(), 'em' => array(), 'br' => array() );
?>
<div id="wpccp_subscribe" class="notice notice-info is-dismissible">
<?php $wccp_free_admin_email = get_bloginfo("admin_email");  ?>
<table style="background-image:url(<?php echo esc_url( $wccp_free_pluginsurl ) ?>/images/ad.png);background-repeat:no-repeat;" border="0" width="100%" cellspacing="0" cellpadding="0">
	<tr>
		<td><h2 style="background-color:#FFFFFF;padding:3px;" class="alert-heading"><?php esc_html_e("WP Content Protection Plugin Group", 'wp-content-copy-protector') ?></h2></td>
		<td rowspan="2">
		<p align="center"><a href="#" onclick="wpccp_dismiss_notice()"><?php esc_html_e('Dismiss', 'wp-content-copy-protector'); ?> </a></td>
	</tr>
	<tr>
		<td style="padding-left: 77px;">
			<h4>
			<?php echo esc_html__("Begin your adventure to improve your WordPress website, Also you will win a ", 'wp-content-copy-protector') . ' <b style="color:red">' . esc_html__("discount codes" , 'wp-content-copy-protector') . '</b>'; ?>
			<input type="text" id="admin_email_wccp" name= "admin_email_wccp" value="<?php echo esc_attr( $wccp_free_admin_email ); ?>" />
			<button type="button" class="btn btn-primary wpccp_subscribe_btn" onclick='wpccp_open_subscribe_page();'> <?php esc_html_e("Start it!", 'wp-content-copy-protector'); ?> </button>
			</h4>
		</td>
	</tr>
</table>
</div>
<script>
function wpccp_dismiss_notice()
	{
		localStorage.setItem('wpccp_subscribed', 'wpccp_subsbc_user');
		document.getElementById("wpccp_subscribe").style.display="none";
	}

function wpccp_open_subscribe_page()
	{
		if(localStorage.getItem('wpccp_subscribed') !='wpccp_subsbc_user')
		{
			var admin_email_wccp = document.getElementById('admin_email_wccp').value;
		window.open('https://www.wp-buy.com/wpccp-subscribe/?email='+admin_email_wccp,'_blank');
		}
	}

if(localStorage.getItem('wpccp_subscribed') =='wpccp_subsbc_user')
	{
		 document.getElementById("wpccp_subscribe").style.display="none";
	}
</script>
<div id="aio_admin_main">
<p style="margin: 20px 0 20px;font-size: 16px;font-weight: bold;color: rgba(30,140,190,.8);">WP Content Copy Protection &amp; No Right Click (FREE) 
<font color="#0909FF"><u>
<a target="_blank" href="https://www.wp-buy.com/product/wp-content-copy-protection-pro/">
<font color="#0909FF"><?php esc_html_e('PRO Version','wp-content-copy-protector'); ?></font></a></u></font>
</p>
<form method="POST">
<input type="hidden" value="update" name="action">
<?php wp_nonce_field('Save_settings_nonce','_Save_settings'); ?>
<?php wp_nonce_field('Restore_defaults_nonce','_Restore_defaults'); ?>
<div class="simpleTabs">
<ul class="simpleTabsNavigation">
    <li><a href="#"><?php esc_html_e('Main Settings','wp-content-copy-protector'); ?></a></li>
	<li><a href="#"><?php esc_html_e('Premium RightClick Protection','wp-content-copy-protector'); ?></a></li>
	<li><a href="#"><?php esc_html_e('Premium Protection by CSS','wp-content-copy-protector'); ?></a></li>
    <li><a href="#"><?php esc_html_e('More with pro','wp-content-copy-protector'); ?></a></li>
</ul>
<div class="simpleTabsContent">
<table border="0" width="100%" cellspacing="0" cellpadding="0">
	<tr>
		<td width="77%">
	<h4><?php esc_html_e('Copy Protection using JavaScript','wp-content-copy-protector'); ?> (<font color="#008000"><?php esc_html_e('Basic Layer','wp-content-copy-protector'); ?></font>):</h4>
	<p><font face="Tahoma" size="2"><?php echo wp_kses( __('This is the basic protection layer that uses <u>JavaScript</u> to protect the posts, home page content from being copied by any other web site author.','wp-content-copy-protector'), $wccp_free_allowed_tags ); ?></font></p>
		<table border="0" width="100%" cellspacing="0" cellpadding="0">
			<tr>
				<td width="60%">
	<div style="float: auto;padding: 4px" id="layer3">
		<table border="0" width="100%" height="320" cellspacing="0" cellpadding="0">
			<tr>
				<td width="221" height="33"><font face="Tahoma" size="2"><?php echo wp_kses( __('Posts protection by <u>JavaScript</u>','wp-content-copy-protector'), $wccp_free_allowed_tags ); ?></font></td>
				<td>
				<select size="1" name="single_posts_protection">
				<?php 
				if ($wccp_settings['single_posts_protection'] == 'Enabled')
					{
						echo '<option selected value="Enabled">'. esc_html__('Enabled','wp-content-copy-protector') .'</option>';
						echo '<option value="Disabled">'. esc_html__('Disabled','wp-content-copy-protector') .'</option>';
					}
					else
					{
						echo '<option value="Enabled">'. esc_html__('Enabled','wp-content-copy-protector') .'</option>';
						echo '<option selected value="Disabled">'. esc_html__('Disabled','wp-content-copy-protector') .'</option>';
					}
				?>
				</select>
				</td>
				<td>
				<p><font face="Tahoma" size="2"><?php esc_html_e('For single posts content','wp-content-copy-protector'); ?></font></p></td>
			</tr>
			<tr>
				<td width="221" height="33"><font face="Tahoma" size="2"><?php echo wp_kses( __('Homepage protection by <u>JavaScript</u>','wp-content-copy-protector'), $wccp_free_allowed_tags ); ?></font></td>
				<td>
				<select size="1" name="home_page_protection">
				<?php 
				if ($wccp_settings['home_page_protection'] == 'Enabled')
					{
						echo '<option selected value="Enabled">'. esc_html__('Enabled','wp-content-copy-protector') .'</option>';
						echo '<option value="Disabled">'. esc_html__('Disabled','wp-content-copy-protector') .'</option>';
					}
					else
					{
						echo '<option value="Enabled">'. esc_html__('Enabled','wp-content-copy-protector') .'</option>';
						echo '<option selected value="Disabled">'. esc_html__('Disabled','wp-content-copy-protector') .'</option>';
					}
				?>
				</select>
				</td>
				<td>
				<p><font face="Tahoma" size="2"><?php esc_html_e('Don\'t copy any thing! even from my homepage','wp-content-copy-protector'); ?></font></td>
			</tr>
			<tr>
				<td width="221" height="33"><font face="Tahoma" size="2"><?php esc_html_e('Static page\'s protection','wp-content-copy-protector'); ?></font></td>
				<td>
				<select size="1" name="page_protection">
				<?php 
				if ($wccp_settings['page_protection'] == 'Enabled')
					{
						echo '<option selected value="Enabled">'. esc_html__('Enabled','wp-content-copy-protector') .'</option>';
						echo '<option value="Disabled">'. esc_html__('Disabled','wp-content-copy-protector') .'</option>';
					}
					else
					{
						echo '<option selected value="Disabled">'. esc_html__('Disabled','wp-content-copy-protector') .'</option>';
						echo '<option value="Enabled">'. esc_html__('Enabled','wp-content-copy-protector') .'</option>';
					}
				?>
				</select></td>
				<td>
				<p><font face="Tahoma" size="2"><?php esc_html_e('Use Premium Settings tab to customize more options','wp-content-copy-protector'); ?></font></td>
			</tr>
			<tr>
				<td width="221" height="33"><font face="Tahoma" size="2"><?php esc_html_e('Plugin icon on top admin bar','wp-content-copy-protector'); ?></font></td>
				<td>
				<select size="1" name="top_bar_icon_btn">
				<?php
				//Check if Hidden first, because default value is Visible
				if (array_key_exists("top_bar_icon_btn",$wccp_settings) && $wccp_settings['top_bar_icon_btn'] == 'Hidden')
					{
						echo '<option selected value="Hidden">'. esc_html__('Hidden','wp-content-copy-protector') .'</option>';
						echo '<option value="Visible">'. esc_html__('Visible','wp-content-copy-protector') .'</option>';
					}
				else
					{
						echo '<option selected value="Visible">'. esc_html__('Visible','wp-content-copy-protector') .'</option>';
						echo '<option value="Hidden">'. esc_html__('Hidden','wp-content-copy-protector') .'</option>';
					}
				?>
				</select></td>
				<td>
				<p><font face="Tahoma" size="2"><?php esc_html_e('Show/Hide the plugin icon on the top admin bar','wp-content-copy-protector'); ?></font></td>
			</tr>
			<tr>
				<td width="221" height="33"><font face="Tahoma" size="2"><?php echo wp_kses( __('Exclude <u>Admin</u> from protection','wp-content-copy-protector'), $wccp_free_allowed_tags ); ?></font></td>
				<td width="88px">
				<p align="center"><a style="color:#FF0000;" target="_blank" href="https://www.wp-buy.com/product/wp-content-copy-protection-pro/">
				<?php esc_html_e('Premium','wp-content-copy-protector'); ?></a></td>
				<td>
				<font face="Tahoma" size="2"><?php echo wp_kses( __('If <u>Yes</u>, The protection functions will be inactive for the admin when he is logged in','wp-content-copy-protector'), $wccp_free_allowed_tags ); ?></font></td>
			</tr>
			<tr>
				<td width="221" height="33"><font face="Tahoma" size="2"><?php esc_html_e('Selection disabled message','wp-content-copy-protector'); ?></font></td>
				<td colspan="2">
				<table border="0" width="59%" cellspacing="0" cellpadding="0">
					<tr>
						<td>
						<input type="text" style="width: 100%;" placeholder="Enter something" class="form-control" name="smessage"  value="<?php echo esc_attr( html_entity_decode( $wccp_settings['smessage'], ENT_QUOTES, 'UTF-8' ) ); ?>"></td>
					</tr>
				</table>
				</td>
			</tr>
			<tr>
				<td width="221" height="33"><font face="Tahoma" size="2"><?php esc_html_e('Print preview message','wp-content-copy-protector'); ?></font></td>
				<td colspan="2">
				<table border="0" width="99%" cellspacing="0" cellpadding="0">
					<tr>
						<td>
						<textarea placeholder="Enter something" style="height: 110px; width: 100%;" class="form-control" name="prnt_scr_msg"><?php echo esc_textarea( html_entity_decode( $wccp_settings['prnt_scr_msg'], ENT_QUOTES, 'UTF-8' ) ); ?></textarea></td>
					</tr>
				</table>
				</td>
			</tr>
			</table></div>
			</td>
			</tr>
	</table>
</td>
	</tr>
</table></div>
<div class="simpleTabsContent">
	<h4><?php esc_html_e('Copy Protection on RightClick','wp-content-copy-protector'); ?> (<font color="#008000"><?php esc_html_e('Premium Layer 2','wp-content-copy-protector'); ?></font>):</h4>
	<p><font face="Tahoma" size="2"><?php echo wp_kses( __('In this protection layer your visitors will be able to <u>right click</u> on a specific page elements only (such as Links as an example)','wp-content-copy-protector'), $wccp_free_allowed_tags ); ?></font></p>
	<div id="layer4">
		<table border="0" width="100%" height="361" cellspacing="0" cellpadding="0">
			<tr>
				<td height="53" width="21%"><font face="Tahoma" size="2"><?php echo wp_kses( __('Disable <u>RightClick</u> on','wp-content-copy-protector'), $wccp_free_allowed_tags ); ?></font></td>
				<td height="53">
				<table border="0" width="521" height="100%" cellspacing="1" cellpadding="0">
					<tr>
						<td width="161" height="46">
				<label class="checkbox" for="checkbox1">
					<font face="Tahoma">
					<input data-toggle="checkbox" type="checkbox" name="right_click_protection_posts" value="checked" <?php checked( $wccp_settings['right_click_protection_posts'], 'checked' ); ?>><font size="2"><?php esc_html_e('Posts','wp-content-copy-protector'); ?></font></font>
						</label>
						</td>
						<td width="161" height="46">
				<label class="checkbox" for="checkbox1">
					<font face="Tahoma">
					<input data-toggle="checkbox" type="checkbox" name="right_click_protection_homepage" value="checked" <?php checked( $wccp_settings['right_click_protection_homepage'], 'checked' ); ?>><font size="2"><?php esc_html_e('HomePage','wp-content-copy-protector'); ?></font></font>
						</label>
						</td>
						<td width="185" height="46">
				<label class="checkbox" for="checkbox1">
					<font face="Tahoma">
					<input data-toggle="checkbox" type="checkbox" name="right_click_protection_pages" value="checked" <?php checked( $wccp_settings['right_click_protection_pages'], 'checked' ); ?>><font size="2"><?php esc_html_e('Static pages','wp-content-copy-protector'); ?></font></font> 
				</label>
						</td>
					</tr>
					</table>
				</td>
			</tr>
			<tr>
				<td height="44" colspan="2">
				<p><font color="#FF0000" face="Tahoma" size="2"><?php esc_html_e('Remaining premium options preview image ','wp-content-copy-protector'); ?></font>
				<img src="<?php echo esc_url( $wccp_free_pluginsurl ) ?>/images/click-here-arrow.png" id="irc_mi">&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;
				<b><font color="#0909FF"><u>
				<a target="_blank" href="https://www.wp-buy.com/product/wp-content-copy-protection-pro/">
				<font color="#0909FF"><?php esc_html_e('Preview & Pricing','wp-content-copy-protector'); ?></font></a></u></font></b>
				</td>
			</tr>
			<tr>
				<td height="264" colspan="2">
				&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;
				<a target="_blank" href="https://www.wp-buy.com/product/wp-content-copy-protection-pro/">
				<img class="size-full" border="1" src="<?php echo esc_url( $wccp_free_pluginsurl ) ?>/images/right-click-protection.jpg" style="border: 1px dotted #C0C0C0"></a><p>&nbsp;</td>
			</tr>
			</table></div>
</div>
<div class="simpleTabsContent">
<h4><?php esc_html_e('Protection by CSS Techniques','wp-content-copy-protector'); ?> (<font color="#008000"><?php esc_html_e('Premium Layer 3','wp-content-copy-protector'); ?></font>):</h4>
	<p><font face="Tahoma" size="2"><?php echo wp_kses( __('In this protection layer your website will be protected by some <u>CSS</u> tricks that will word even if <u>JavaScript</u> is disabled from the browser settings','wp-content-copy-protector'), $wccp_free_allowed_tags ); ?></font></p>
	<table border="0" width="100%" cellspacing="0" cellpadding="0">
			<tr>
				<td width="60%">
	<div style="float: auto;padding: 4px" id="layer5">
		<table border="0" width="100%" height="232" cellspacing="0" cellpadding="0">
			<tr>
				<td width="221" height="74"><font face="Tahoma" size="2"><?php echo wp_kses( __('<b>Home Page</b> Protection by CSS','wp-content-copy-protector'), $wccp_free_allowed_tags ); ?></font></td>
				<td height="74" width="90">
				<select size="1" name="home_css_protection">
				<?php 
				if ($wccp_settings['home_css_protection'] == 'Enabled')
					{
						echo '<option selected value="Enabled">'. esc_html__('Enabled','wp-content-copy-protector') .'</option>';
						echo '<option value="Disabled">'. esc_html__('Disabled','wp-content-copy-protector') .'</option>';
					}
					else
					{
						echo '<option value="Enabled">'. esc_html__('Enabled','wp-content-copy-protector') .'</option>';
						echo '<option selected value="Disabled">'. esc_html__('Disabled','wp-content-copy-protector') .'</option>';
					}
				?>
				</select>
				</td>
				<td height="74">
				<font face="Tahoma" size="2"><?php esc_html_e('Protect your Homepage by CSS tricks','wp-content-copy-protector'); ?></font></td>
			</tr>
			<tr>
				<td width="221" height="77"><font face="Tahoma" size="2"><?php echo wp_kses( __('<b>Posts</b> Protection by CSS','wp-content-copy-protector'), $wccp_free_allowed_tags ); ?></font></td>
				<td width="90" align="center">
				<select size="1" name="posts_css_protection">
				<?php 
				if ($wccp_settings['posts_css_protection'] == 'Enabled')
					{
						echo '<option selected value="Enabled">'. esc_html__('Enabled','wp-content-copy-protector') .'</option>';
						echo '<option value="Disabled">'. esc_html__('Disabled','wp-content-copy-protector') .'</option>';
					}
					else
					{
						echo '<option value="Enabled">'. esc_html__('Enabled','wp-content-copy-protector') .'</option>';
						echo '<option selected value="Disabled">'. esc_html__('Disabled','wp-content-copy-protector') .'</option>';
					}
				?>
				</select>
				</td>
				<td>
				<font face="Tahoma" size="2"><?php esc_html_e('Protect your single posts by CSS tricks','wp-content-copy-protector'); ?></font> (Pro option - unlocked for free!!)</td>
			</tr>
			<tr>
				<td width="221"><font face="Tahoma" size="2"><?php echo wp_kses( __('<b>Pages</b> Protection by CSS','wp-content-copy-protector'), $wccp_free_allowed_tags ); ?></font></td>
				<td width="90" align="center">
				<a style="color:#FF0000;" target="_blank" href="https://www.wp-buy.com/product/wp-content-copy-protection-pro/">
				<?php esc_html_e('Premium','wp-content-copy-protector'); ?></a>
				</td>
				<td><font face="Tahoma" size="2"><?php esc_html_e('Protect your static pages by CSS tricks','wp-content-copy-protector'); ?></font></td>
			</tr>
			</table></div>
			</td>
			</tr>
	</table>

</div>
<div class="simpleTabsContent" id="layer1">
		<p><font color="#FF0000" face="Tahoma" size="3"><?php esc_html_e('Crazy discount offer is now running for a limited time!! You might love it','wp-content-copy-protector'); ?></font>
		<img src="<?php echo esc_url( $wccp_free_pluginsurl ) ?>/images/click-here-arrow.png" id="irc_mi">&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;
		<b><font color="#0909FF"><u>
		<a target="_blank" href="https://www.wp-buy.com/product/wp-content-copy-protection-pro/">
		<font color="#0909FF" size="3"><?php esc_html_e('See it now','wp-content-copy-protector'); ?></font></a></u></font></b>
		<a target="_blank" href="https://www.wp-buy.com/product/wp-content-copy-protection-pro/">
		<img class="size-full" border="1" src="<?php echo esc_url( $wccp_free_pluginsurl ) ?>/images/smart-phones-protection.png" style="border: 1px dotted #C0C0C0">
		</a>
		<p>&nbsp;</p>
		<a target="_blank" href="https://www.wp-buy.com/product/wp-content-copy-protection-pro/">
		<img class="size-full" border="1" src="<?php echo esc_url( $wccp_free_pluginsurl ) ?>/images/watermark-adv.jpg" style="border: 1px dotted #C0C0C0">
		</a>
		<p></p>
		<a target="_blank" href="https://www.wp-buy.com/product/wp-content-copy-protection-pro/">
		<img class="size-full" border="1" src="<?php echo esc_url( $wccp_free_pluginsurl ) ?>/images/watermarking-adv-examples.png" style="border: 1px dotted #C0C0C0">
		</a>
		<p></p>
		<p><b><font face="Tahoma" size="2" color="#FFFFFF">
		
		
		<span style="background-color: #008000"><?php esc_html_e('Basic features:','wp-content-copy-protector'); ?></span></font></b></p>
		<ul>
			<li><font style="font-size: 10pt" face="Tahoma"><?php esc_html_e('Protect your content from selection and copy. this plugin makes protecting your posts extremely simple without yelling at your readers','wp-content-copy-protector'); ?></font></li>
			<li><font style="font-size: 10pt" face="Tahoma"><?php esc_html_e('No one can save images from your site.','wp-content-copy-protector'); ?></font></li>
			<li><font style="font-size: 10pt" face="Tahoma"><?php esc_html_e('No right-click or context menu.','wp-content-copy-protector'); ?></font></li>
			<li><font style="font-size: 10pt" face="Tahoma"><?php esc_html_e('Show alert message, Image Ad, or HTML Ad on saving images or right-click.','wp-content-copy-protector'); ?></font></li>
			<li><font style="font-size: 10pt" face="Tahoma"><?php esc_html_e('Disable the following keys','wp-content-copy-protector'); ?> CTRL+A, CTRL+C, CTRL+X, CTRL+S, or CTRL+V.</font></li>
			<li><font style="font-size: 10pt" face="Tahoma"><?php esc_html_e('Advanced and easy to use control panel.','wp-content-copy-protector'); ?></font></li>
			<li><font style="font-size: 10pt" face="Tahoma"><?php esc_html_e('No one can right-click images on your site if you want','wp-content-copy-protector'); ?></font></li>
		</ul>
		<p><b><font face="Tahoma" size="2" color="#FFFFFF">
		<span style="background-color: #5B2473"><?php esc_html_e('Premium features:','wp-content-copy-protector'); ?></span></font></b></p>
		<ul>
			<li><font style="font-size: 10pt" face="Tahoma"><?php esc_html_e('Get full control over Right-click or context menu','wp-content-copy-protector'); ?></font></li>
			<li><font style="font-size: 10pt" face="Tahoma"><?php esc_html_e('Full watermarking','wp-content-copy-protector'); ?></font></li>
			<li><font style="font-size: 10pt" face="Tahoma"><?php esc_html_e('Show alert messages, when the user made right click on images, text boxes, links, plain text.. etc','wp-content-copy-protector'); ?></font></li>
			<li><font style="font-size: 10pt" face="Tahoma"><?php esc_html_e('Admin can exclude Home page Or Single posts from being copy protected ','wp-content-copy-protector'); ?></font></li>
			<li><font style="font-size: 10pt" face="Tahoma"><?php esc_html_e('Admin can disable copy protection for admin users.','wp-content-copy-protector'); ?></font></li>
			<li><font face="Tahoma" size="2"><?php esc_html_e('3 protection layers (JavaScript protection, RightClick protection, CSS protection)','wp-content-copy-protector'); ?></font></li>
			<li><font style="font-size: 10pt" face="Tahoma"><?php esc_html_e('Aggressive image protection (it is nearly impossible for expert users to steal your images !!)','wp-content-copy-protector'); ?></font></li>
			<li><font style="font-size: 10pt" face="Tahoma"><?php esc_html_e('Compatible with all major theme frameworks','wp-content-copy-protector'); ?></font></li>
			<li><font style="font-size: 10pt" face="Tahoma"><?php esc_html_e('Compatible with all major browsers','wp-content-copy-protector'); ?></font></li>
			<li><font style="font-size: 10pt" face="Tahoma"><?php esc_html_e('Tested in IE9, IE10, edge, Firefox, Google Chrome, Opera, safari','wp-content-copy-protector'); ?></font></li>
			<li><font style="font-size: 10pt" face="Tahoma"><?php esc_html_e('Disables image drag and drop function','wp-content-copy-protector'); ?></font></li>
			<li><font style="font-size: 10pt" face="Tahoma"><?php esc_html_e('Works on smartphones.','wp-content-copy-protector'); ?></font></li>
			<li><font style="font-size: 10pt" face="Tahoma"><?php esc_html_e('Ability to set varying levels of protection per page or post.','wp-content-copy-protector'); ?></font></li>
		</ul>
		
		
		<p><a target="_blank" href="https://www.wp-buy.com/wpccp-subscribe">Subscribe</a> to our mailing list to get flash discounts</p>
		</div>
</div><!-- simple tabs div end -->
<div style="width:97%;" class="">
<div class="row justify-content-end form-btns">
<div class="col-15-auto"><input type="submit" class="btn wccp-form-button gray-btn" value="<?php esc_attr_e('Restore defaults','wp-content-copy-protector'); ?>" name="Restore_defaults"></div>
<div class="col-15-auto"><input type="button" class="btn wccp-form-button blue-btn" value="<?php esc_attr_e('Preview alert message','wp-content-copy-protector'); ?>" alt="Use CTRL+F5 after saving" onclick="show_wpcp_message('<?php echo esc_js( __('This is a preview message (do not forget to save changes)','wp-content-copy-protector') ); ?>');" name="B5"></div>
<div class="col-15-auto"><input type="submit" class="btn wccp-form-button green-btn" value="   <?php esc_attr_e('Save Settings','wp-content-copy-protector'); ?>   " name="Save_settings"></div>
</div></div>
</form>
</div>
<style>
#aio_admin_main {
padding:10px;
margin: 10px;
background-color: #ffffff;
border:1px solid #EBDDE2;
display: relative;
overflow: auto;
}
.inner_block{
height: 370px;
display: inline;
min-width:770px;
}
#donate{
    background-color: #EEFFEE;
    border: 1px solid #66DD66;
    border-radius: 10px 10px 10px 10px;
    height: 58px;
    padding: 10px;
    margin: 15px;
    }
.text-font {
    color: #1ABC9C;
    font-size: 14px;
    line-height: 1.5;
    padding-left: 3px;
    transition: color 0.25s linear 0s;
}
.text-font:hover {
    opacity: 1;
    transition: color 0.25s linear 0s;
}
.simpleTabsContent{
	border: 1px solid #E9E9E9;
	padding: 4px;
}
div.simpleTabsContent{
	margin-top:0;
	border: 1px solid #E0E0E0;
    display: none;
    height: 100%;
    min-height: 400px;
    padding: 5px 15px 15px;
}

.size-full {
    height: auto;
    max-width: 100%;
}

.wccp-form-button {
  color: white;
  padding: 12px 28px;
  text-align: center;
  text-decoration: none;
  display: inline-block;
  font-size: 16px;
  cursor: pointer;
  transition-duration: 0.4s;
  border: 2px solid;
  border-radius: 5px;
}

.wccp-form-button:hover {background-color: white;}

.green-btn {background-color: #4CAF50; border-color: #4CAF50;} /* Green */
.green-btn:hover {color: #4CAF50;}

.blue-btn {background-color: #008CBA; border-color: #008CBA;} /* Blue */
.blue-btn:hover {color: #008CBA;}

.red-btn {background-color: #f44336; border-color: #f44336;} /* Red */
.red-btn:hover {color: #f44336;}

.gray-btn {background-color: #e7e7e7; color: black; border-color: #e7e7e7;} /* Gray */
.gray-btn:hover {color: #black;}

.black-btn {background-color: #555555; border-color: #555555;} /* Black */
.black-btn:hover {color: #555555;}

.form-btns .btn{
	margin-right: 5px;
	float: right;
}
@media (max-width: 435px)
{
	.form-btns .btn
	{
	width:95% !important;
	}
	.form-btns
	{
	-webkit-box-pack: center!important;
    -ms-flex-pack: center!important;
    justify-content: center!important;
	}
	.form-btns div
	{
	width: 100%;
	}
	
}
@media (min-width: 436px) and (max-width: 646px)
{
	.form-btns .btn
	{
	width:390px !important;
	}
	.form-btns
	{
	-webkit-box-pack: center!important;
    -ms-flex-pack: center!important;
    justify-content: center!important;
	}
}
@media (min-width: 647px) and (min-width: 768px)
{
	.form-btns .btn
	{
	margin-right: 5px;
	}
}
</style>