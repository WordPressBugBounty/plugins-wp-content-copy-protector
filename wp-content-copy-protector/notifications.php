<?php
if ( ! defined( 'ABSPATH' ) ) {
  // Exit if accessed directly.
  exit;
}
// Use your own prefix, i use "wccp_free_", replace it;
$wccp_free_icon_path = plugins_url( '/images/icon-128x128.png' , __FILE__);
$wccp_free_rating_url = "https://wordpress.org/support/plugin/wp-content-copy-protector/reviews/";
$wccp_free_activation_time = 604800; // 7 days in seconds
$wccp_free_file_version = 2.1;
$wccp_free_development_mode = false; // Put yes to allow development mode, you will see the rating notice without timers

/**
* @since  1.9
* @version 1.9
* @class wccp_free_Notification
*/

if ( ! class_exists( 'wccp_free_Notification' ) ) :

  class wccp_free_Notification {
	
	/* * * * * * * * * *
    * Class constructor
    * * * * * * * * * */
    public function __construct() {

      $this->_hooks();
    }

    /**
    * Hook into actions and filters
    * @since  1.0.0
    * @version 1.2.1
    */
    private function _hooks() {
      add_action( 'admin_init', array( $this, 'wccp_free_review_notice' ) );
    }
	
	/**
  	 * Ask users to review our plugin on wordpress.org
  	 *
  	 * @since 1.0.11
  	 * @return boolean false
  	 * @version 1.1.3
  	 */
  	public function wccp_free_review_notice() {
		
		global $wccp_free_file_version, $wccp_free_activation_time, $wccp_free_development_mode;
		
		$this->wccp_free_review_dismissal();
		
  		$this->wccp_free_review_pending();
		
		$wccp_free_activation_time 	= get_site_option( 'wccp_free_active_time' );
		
  		$review_dismissal	= get_site_option( 'wccp_free_review_dismiss' );
		
		if ($review_dismissal == 'yes' && !$wccp_free_development_mode) return;
		
		if ( !$wccp_free_activation_time && !$wccp_free_development_mode ) :

  			$wccp_free_activation_time = time(); // Reset Time to current time.
  			add_site_option( 'wccp_free_active_time', $wccp_free_activation_time );
			
  		endif;
		if ($wccp_free_development_mode) $wccp_free_activation_time = 432001; //This variable used to show the message always for testing purposes only
  		// 432000 = 5 Days in seconds.
  		if ( time() - $wccp_free_activation_time > 432000 ) :
		
			wp_enqueue_style( 'wccp_free_review_stlye', plugins_url( '/css/style-review.css', __FILE__ ), array(), $wccp_free_file_version );
			add_action( 'admin_notices' , array( $this, 'wccp_free_review_notice_message' ) );
		
		endif;
  	}

    /**
  	 *	Check and Dismiss review message.
  	 *
  	 *	@since 1.9
  	 */
  	private function wccp_free_review_dismissal() {

  		if ( ! is_admin() ||
  			! current_user_can( 'manage_options' ) ||
  			! isset( $_GET['_wpnonce'] ) ||
  			! wp_verify_nonce( sanitize_key( wp_unslash( $_GET['_wpnonce'] ) ), 'wccp_free_review-nonce' ) ||
  			! isset( $_GET['wccp_free_review_dismiss'] ) ) :

  			return;
  		endif;

  		add_site_option( 'wccp_free_review_dismiss', 'yes' );
  	}

    /**
  	 * Set time to current so review notice will popup after 14 days
  	 *
  	 * @since 1.9
  	 */
  	private function wccp_free_review_pending() {

  		if ( ! is_admin() ||
  			! current_user_can( 'manage_options' ) ||
  			! isset( $_GET['_wpnonce'] ) ||
  			! wp_verify_nonce( sanitize_key( wp_unslash( $_GET['_wpnonce'] ) ), 'wccp_free_review-nonce' ) ||
  			! isset( $_GET['wccp_free_review_later'] ) ) :

  			return;
  		endif;

  		// Reset Time to current time.
  		update_site_option( 'wccp_free_active_time', time() );
  	}

    /**
  	 * Review notice message
  	 *
  	 * @since  1.0.11
  	 */
  	public function wccp_free_review_notice_message() {

  		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
  		$scheme      = ( wp_parse_url( $request_uri, PHP_URL_QUERY ) ) ? '&' : '?';
  		$url         = $request_uri . $scheme . 'wccp_free_review_dismiss=yes';
  		$dismiss_url = wp_nonce_url( $url, 'wccp_free_review-nonce' );

  		$_later_link = $request_uri . $scheme . 'wccp_free_review_later=yes';
  		$later_url   = wp_nonce_url( $_later_link, 'wccp_free_review-nonce' );
		
		global $wccp_free_icon_path;
		
		global $wccp_free_rating_url;
      ?>

  		<div class="wccp_free_review-notice">
  			<div class="wccp_free_review-thumbnail">
  				<img src="<?php echo esc_url( $wccp_free_icon_path ); ?>" alt="">
  			</div>
  			<div class="wccp_free_review-text">
  				<h3><?php esc_html_e( 'Leave A Review?', 'wp-content-copy-protector' ) ?></h3>
  				<p><?php echo wp_kses( __( 'We hope you\'ve enjoyed using WP copy Protection :) Would you mind taking a few minutes to write a review on WordPress.org?<br>Just writing simple "thank you" will make us happy!', 'wp-content-copy-protector' ), array( 'br' => array() ) ) ?></p>
  				<ul class="wccp_free_review-ul">
            <li><a href="<?php echo esc_url( $wccp_free_rating_url ); ?>" target="_blank"><span class="dashicons dashicons-external"></span><?php esc_html_e( 'Sure! I\'d love to!', 'wp-content-copy-protector' ) ?></a></li>
            <li><a href="<?php echo esc_url( $dismiss_url ) ?>"><span class="dashicons dashicons-smiley"></span><?php esc_html_e( 'I\'ve already left a review', 'wp-content-copy-protector' ) ?></a></li>
            <li><a href="<?php echo esc_url( $later_url ) ?>"><span class="dashicons dashicons-calendar-alt"></span><?php esc_html_e( 'Will Rate Later', 'wp-content-copy-protector' ) ?></a></li>
            <li><a href="<?php echo esc_url( $dismiss_url ) ?>"><span class="dashicons dashicons-dismiss"></span><?php esc_html_e( 'Hide Forever', 'wp-content-copy-protector' ) ?></a></li></ul>
  			</div>
  		</div>
  	<?php
  	}
}

endif;
$wccp_free_admincore = '';
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only reading the current admin screen slug, nothing is processed or saved.
	if ( isset( $_GET['page'] ) ) $wccp_free_admincore = sanitize_key( wp_unslash( $_GET['page'] ) );
	if($wccp_free_admincore != 'wccpoptionspro') {
		new wccp_free_Notification();
	}
?>