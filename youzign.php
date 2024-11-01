<?php
/*
Plugin Name: Youzign
Plugin URI: http://youzign.com/blog/new-youzign-for-wordpress/
Description: Youzign for WordPress is a WordPress plugin that lets you import any of your Youzign designs in your WordPress posts and pages.
Version: 2.2.0
Author: Youzign
Author URI: https://youzign.com
License: MIT License
License URI: http://opensource.org/licenses/MIT
Text Domain: youzign
Domain Path: /languages

Youzign for WordPress
Copyright (C) 2015, YMB Properties - accounts@ymbproperties.com
*/

// exit if accessed directly
if ( ! defined( 'ABSPATH' ) )
	exit;

/**
 * Youzign class.
 *
 * @class Youzign
 * @version	2.2.0
 */
class WP_Youzign {

	private $user_id = 0;
	private $defaults = array(
		'settings'	=> array(
			'public_key'	=> '',
			'token'			=> '',
			'authenticated'	=> false,
		),
		'api_url'	=> 'https://www.youzign.com/api/',
		'version'	=> '2.2.0'
	);
	public $options = array();
	
	private static $_instance;
	
	private function __clone() {}
	private function __wakeup() {}

	/**
	 * Youzign instance.
	 */
	public static function instance() {
		if ( self::$_instance === null ) {
			self::$_instance = new self();
		}
		return self::$_instance;
	}
	
	/**
	 * Class constructor.
	 */
	function __construct() {
		
		register_activation_hook( __FILE__, array( &$this, 'activate' ) );
		register_deactivation_hook( __FILE__, array( &$this, 'deactivate' ) );
		
		// actions
		add_action( 'plugins_loaded', array( &$this, 'load_textdomain' ) );
		add_action( 'admin_menu', array( &$this, 'admin_menu_settings' ) );
		add_action( 'admin_init', array( &$this, 'register_settings' ) );
		add_action( 'admin_menu', array( &$this, 'admin_init' ) );
		add_action( 'admin_notices', array( &$this, 'admin_notices' ) );
		add_action( 'media_upload_youzign', array( &$this, 'media_upload_form' ) );
		add_action( 'admin_enqueue_scripts', array( &$this, 'media_upload_scripts' ) );
		add_action( 'wp_ajax_yz-import-to-library', array( $this, 'import_to_library' ) );
		
		// filters
		add_filter( 'media_upload_tabs', array( &$this, 'media_upload_tabs' ) );
		
		// settings
		$this->options = array_merge( $this->defaults['settings'], get_option( 'youzign_settings', $this->defaults['settings'] ) );
	}
	
	/**
	 * Import designs to Media LIbrary via AJAX request.
	 */
	public function import_to_library() {
		if ( isset( $_POST['action'], $_POST['urls'], $_POST['titles'], $_POST['youzign_ids'], $_POST['post_id'], $_POST['yx_nonce'] ) && $_POST['action'] === 'yz-import-to-library' && ($urls = array_map( 'esc_url', $_POST['urls'] ) ) != false && wp_verify_nonce( $_POST['yx_nonce'], 'yz-import-to-library' ) !== false ) {
			
			if ( ! current_user_can( 'upload_files' ) )
				exit;
			
			$titles = ! empty( $_POST['titles'] ) ? array_map( 'sanitize_text_field', $_POST['titles'] ) : array();
			$youzign_ids = ! empty( $_POST['youzign_ids'] ) ? array_map( 'absint', $_POST['youzign_ids'] ) : array();

			if ( $urls ) {
				// get post the attachment is uploaded to
				$parent_id = absint( $_POST['post_id'] );
				$attachment_ids = array();
				
				include_once( ABSPATH . 'wp-admin/includes/media.php' );
				include_once( ABSPATH . 'wp-admin/includes/file.php' );
				include_once( ABSPATH . 'wp-admin/includes/image.php' );
				
				// get the path to the upload directory.
				$upload_dir = wp_upload_dir();
				
				foreach ( $urls as $index => $url ) {
					// generate file
					$imagedata = file_get_contents( $url );
					$filename = sanitize_file_name( basename( strtok( $url, '?' ) ) );
					
					if ( $imagedata === false )
						continue;

					// upload image
					$uploaded = wp_upload_bits( $filename, null, $imagedata );

					if ( ! empty( $uploaded['error'] ) )
						continue;
					
					// get image path
					$new_file = $uploaded['file'];
					
					/*
					// move the file to the uploads dir.
					$new_file = $upload_dir['path'] . "/$filename";
					$move_new_file = @ copy( $uploaded['file'], $new_file );
					
					// remove temporary file
					$removed_file = @ unlink( $uploaded['file'] );
					*/
					
					// get mime type
					$filetype = wp_check_filetype( $new_file );
					
					// check if file is an image
					if ( ! in_array( $filetype['type'], array( 'image/jpeg', 'image/png', 'image/gif' ) ) )
						continue;
					
					// generate attachment
					$args = array(
						'guid'           => $upload_dir['baseurl'] . _wp_relative_upload_path( $new_file ),
						'post_title'	 => preg_replace( '/\.[^.]+$/', '', ! empty( $titles[$index] ) ? $titles[$index] : $filename ),
						'post_parent'	 => $parent_id,
						'post_mime_type' => $filetype['type'],
						'post_type'		 => 'attachment',
						'post_content'   => '',
						'post_status'    => 'inherit'
					);
					$attachment_id = wp_insert_attachment( $args, $new_file );

					// generate metadata
					$attachment_data = wp_generate_attachment_metadata( $attachment_id, $new_file );
					wp_update_attachment_metadata( $attachment_id, $attachment_data );
					
					// insert youzign id
					if ( ! empty( $youzign_ids[$index] ) ) {
						update_post_meta( $attachment_id, '_youzign_id', $youzign_ids[$index] );
					}
					
					// uploaded attachment ids
					$attachment_ids[$youzign_ids[$index]] = $attachment_id;
				}
				
				$response = array(
					'ids'	 => $attachment_ids,
					'status' => 'ok'
				);

				echo json_encode( $response );
				exit;
			}
		}

		echo json_encode( array( 'status' => 'error' ) );
		exit;
	}

	/**
	 * Load textdomain.
	 */
	public function load_textdomain() {
		load_plugin_textdomain( 'youzign', false, dirname( plugin_basename( __FILE__ ) ) . '/languages/' );
	}
	
	/**
	 * Activation function.
	 */
	public function activate() {
		add_option( 'youzign_settings', $this->defaults['settings'], '', 'no' );
	}
	
	/**
	 * Dectivation function.
	 */
	public function deactivate() {
		delete_option( 'youzign_settings' );
	}
	
	/**
	 * Register options page.
	 * 
	 * @return void
	 */
	public function admin_menu_settings() {
		add_options_page(
			__( 'Youzign', 'youzign' ), __( 'Youzign', 'youzign' ), 'manage_options', 'youzign', array( &$this, 'settings_page' )
		);
	}
	
	/**
	 * Admin init function.
	 * 
	 * @return void
	 */
	public function admin_init() {
		if ( ! current_user_can( 'install_plugins' ) )
			return;
		
		global $pagenow;
		
		if ( $pagenow != 'options-general.php' )
			return;
		
		if ( ! empty( $_GET['page'] ) && $_GET['page'] !== 'youzign' )
			return;
		
		$data = get_transient( 'youzign_authentication_status' );
		
		if ( $data === false ) {
			$data = $this->api_request();

			if ( empty( $data ) || isset( $data['error'] ) || empty( $data['authenticated'] ) ) {
				$this->options['authenticated'] = false;
				update_option( 'youzign_settings', $this->options );
			} else {
				$this->user_id = $data['authenticated'];
				$this->options['authenticated'] = true;
				update_option( 'youzign_settings', $this->options );
			}
			
			set_transient( 'youzign_authentication_status', $data, 3600 );
		}
	}
	
	/**
	 * Display admin notices.
	 * 
	 * @return void
	 */
	public function admin_notices() {
		global $wp_styles;
		
		if ( $this->options['authenticated'] != true ) {
			$url = esc_url( admin_url( 'options-general.php?page=youzign' ) );
			?>
			<div class="error notice is-dismissible">
				<p><strong><?php printf( __( 'Your Youzign account requires <a href="%s">authentication</a>.', 'youzign' ), $url ); ?></strong></p>
			</div>
			<?php
		}
	}
	
	/**
	 * Options page output.
	 * 
	 * @return mixed
	 */
	public function settings_page() {
		echo '
		<div class="wrap">' . screen_icon() . '
			<h2>' . __( 'Youzign', 'youzign' ) . '</h2>
			<div class="youzign-settings">
				<form action="options.php" method="post">';

		settings_fields( 'youzign_settings' );
		do_settings_sections( 'youzign_settings' );
		
		echo '
				<p class="submit">';
		submit_button( '', 'primary', 'save_youzign_settings', false );
		echo ' ';
		submit_button( __( 'Reset to defaults', 'youzign' ), 'secondary', 'reset_youzign_settings', false );
		echo '
				</p>
				</form>
			</div>
			<div class="clear"></div>
		</div>';
	}
	
	/**
	 * Register settings function.
	 * 
	 * @return void
	 */
	public function register_settings() {
		register_setting( 'youzign_settings', 'youzign_settings', array( $this, 'validate_settings' ) );

		// configuration
		add_settings_section( 'youzign_authentication', __( 'Authentication', 'youzign' ), array( $this, 'yz_authentication_section' ), 'youzign_settings' );
		add_settings_field( 'yz_public_key_field', __( 'Public Key', 'youzign' ), array( $this, 'yz_public_key_field' ), 'youzign_settings', 'youzign_authentication' );
		add_settings_field( 'yz_token_field', __( 'Token', 'youzign' ), array( $this, 'yz_token_field' ), 'youzign_settings', 'youzign_authentication' );
		add_settings_field( 'yz_authentication_status_field', __( 'Status', 'youzign' ), array( $this, 'yz_authentication_status_field' ), 'youzign_settings', 'youzign_authentication' );
	}
	
	/**
	 * Authentication section.
	 */
	public function yz_authentication_section() {
		?>
		<p class="description"><?php printf( __( 'Please enter your authentication data to connect to your <a href="%s" target="_blank">Youzign</a> account.', 'youzign' ), 'https://youzign.com/' ); ?></p>
		<?php
	}
	
	/**
	 * Public key field.
	 */
	public function yz_public_key_field() {
		?>
		<div>
			<input type="text" class="regular-text" name="youzign_settings[public_key]" value="<?php echo esc_attr( $this->options['public_key'] ); ?>" />
			<p class="description"><?php _e( 'Enter your API Public Key.', 'cookie-notice' ); ?></p>
		</div>
		<?php
	}
	
	/**
	 * Token field.
	 */
	public function yz_token_field() {
		?>
		<div>
			<input type="text" class="regular-text" name="youzign_settings[token]" value="<?php echo esc_attr( $this->options['token'] ); ?>" />
			<p class="description"><?php _e( 'Enter your API Token.', 'cookie-notice' ); ?></p>
		</div>
		<?php
	}
	
	/**
	 * Authentication status field.
	 * 
	 * @return mixed
	 */
	public function yz_authentication_status_field() {
		
		$options = get_option( 'youzign_settings' );
		$data = get_transient( 'youzign_authentication_status' );

		if ( ! empty( $options['authenticated'] ) ) {
			?>
			<div class="status authenticated"><strong><?php _e( 'Authenticated', 'cookie-notice' ); ?></strong></div>
			<?php
		} else {
			?>
			<div class="status not-authenticated"><strong><?php _e( 'Not Authenticated', 'cookie-notice' ); ?></strong><?php echo ! empty( $data->error ) ? ' - ' . $data->error : ''; ?></div>
			<?php
		}
	}
	
	/**
	 * Validate options.
	 * 
	 * @param array $input
	 * @return array
	 */
	public function validate_settings( $input ) {

		if ( ! check_admin_referer( 'youzign_settings-options') )
			return $input;
		
		if ( ! current_user_can( 'manage_options' ) )
			return $input;
		
		delete_transient( 'youzign_authentication_status' );
		
		if ( isset( $_POST['save_youzign_settings'] ) ) {
			$input['youzign_settings']['public_key'] = sanitize_text_field( $_POST['youzign_settings']['public_key'] );
			$input['youzign_settings']['token'] = sanitize_text_field( $_POST['youzign_settings']['token'] );
			
			// add_settings_error( 'em_settings_errors', 'settings_caps_saved', __( 'Youzign settings restored to defaults.', 'youzign' ), 'updated' );
		} elseif ( isset( $_POST['reset_youzign_settings'] ) ) {
			$input = $this->defaults;
			
			add_settings_error( 'yz_settings_errors', 'yz_settings_restored', __( 'Youzign settings restored to defaults.', 'youzign' ), 'updated' );
		}
	
		return $input;
	}
	
	/**
     * Youzign API request.
     *
     * @param string  $action The requested action.
     * @param array   $_data   Parameters for the API action.
     * @return false|object
     */
    private function api_request( $query = '' ) {
		
		$api_url = $this->defaults['api_url'];
		
        if ( $api_url == home_url() ) {
            return false;
        }
		
		if ( $query ) {
			$api_url = esc_url( $api_url . $query );
		}
		
		$api_params = array(
			'key' => $this->options['public_key'],
			'token' => $this->options['token'],
		);

        $request = wp_remote_post( $api_url, array( 'timeout' => 60, 'sslverify' => false, 'body' => $api_params ) );

        if ( ! is_wp_error( $request ) ) {
            $request = json_decode( wp_remote_retrieve_body( $request ), true );
        } else {
            $request = false;
        }
		
		return $request;
	}
	
	/**
     * Extend media upload tabs.
     *
     * @param array  $tabs
     * @return array
     */
	public function media_upload_tabs( $tabs ) {
		if ( $this->options['authenticated'] == true ) {
			$tabs['youzign'] = __( 'Insert from Youzign', 'youzign' );
		}
		return $tabs;
	}
	
	/**
     * Register new media tab form.
     *
     * @return void
     */
	public function media_upload_form( ) {
		wp_iframe( array( &$this, 'media_upload_form_content' ) );
	}
	
	/**
     * New media tab form content.
     *
     * @return mixed
     */
	public function media_upload_form_content() {
		// print media uploader headers etc.	
		media_upload_header();

		// designs API request
		$designs = $this->api_request( 'designs' );
		
		echo '<div class="media-frame mode-grid youzign"><div class="media-frame-content" data-columns="9"><div class="attachments-browser">';
		
		if ( $designs && is_array( $designs ) ) {
			
			$uploaded = array();
			
			$attachment_ids = get_posts( array(
				'post_type'		 => 'attachment',
				'posts_per_page' => -1,
				'meta_key'		 => '_youzign_id',
				'meta_compare'	 => 'EXISTS',
				'fields'		 => 'ids'
			) );
			
			// assign attachment id to uploaded youzign design id
			if ( $attachment_ids ) {
				foreach ( $attachment_ids as $attachment_id ) {
					$youzign_id = get_post_meta( $attachment_id, '_youzign_id', true );
					
					if ( ! empty( $youzign_id ) ) {
						$uploaded[$youzign_id][] = $attachment_id;
					}
				}
			}

			echo '<ul class="attachments">';
				foreach ( $designs as $design ) {
					
					// get image data, design-thumb if possible, if not, the full size
					if ( ! empty( $design['image_sizes']['thumbnail'] ) ) {
						$image = array(
							'url'		=> $design['image_sizes']['thumbnail'][0],
							'width'		=> $design['image_sizes']['thumbnail'][1],
							'height'	=> $design['image_sizes']['thumbnail'][2],
							'crop'		=> $design['image_sizes']['thumbnail'][3],
						);
					} else {
						$image = array(
							'url'		=> $design['image_src'][0],
							'width'		=> $design['image_src'][1],
							'height'	=> $design['image_src'][2],
							'crop'		=> $design['image_src'][3],
						);
					}
					
					echo '<li class="attachment save-ready' . ( isset( $uploaded[$design['id']] ) ? ' in-library ' : '' ) . '" data-id="' . (int) $design['id'] . '" data-image-url="' . esc_url( $design['image_src'][0] ) . '" data-image-width="' . $design['image_src'][1] . '" data-image-height="' . $design['image_src'][2] . '">
						<div class="attachment-preview type-remote">
							<div class="thumbnail">
								<div class="centered">
									<img src="' . esc_url( $image['url'] ) . '" class="icon" draggable="false" />
								</div>
								<div class="filename">
									<div>' . esc_html( $design['title'] ) . '</div>
								</div>
							</div>
						</div>
					</li>';
				}
			echo '</ul>';
		}
		
		echo '</div></div></div>';
	}
	
	/**
     * Enqueue New media tab scripts and styles.
     *
     * @return void
     */
	public function media_upload_scripts( $pagenow ) {
		wp_register_script( 'youzign-media-tab', plugins_url( 'js/media-tab.js', __FILE__), array( 'media-views' ), $this->defaults['version'], true );
		wp_register_style( 'youzign-media-tab', plugins_url( 'css/media-tab.css', __FILE__ ), array( 'media-views' ), $this->defaults['version'] );

		$args = array(
			'ajax_url'		=> admin_url( 'admin-ajax.php' ),
			'text_insert'	=> __( 'Insert into Post', 'youzign' ),
			'text_import'	=> __( 'Import to Media Library', 'youzign' ),
			'nonce'			=> wp_create_nonce( 'yz-import-to-library' )
		);
		wp_localize_script( 'youzign-media-tab', 'youzignArgs', $args );

		if ( $pagenow = 'media-upload-popup' && isset( $_GET['tab'] ) && $_GET['tab'] === 'youzign') {
			wp_enqueue_media();
			wp_enqueue_script( 'youzign-media-tab' );
			wp_enqueue_style( 'media-views' );
			wp_enqueue_style( 'youzign-media-tab' );
		}
	}
       
}

/**
 * Initialise Youzign plugin.
 */
function WP_Youzign() {
	static $instance;

	// first call to instance() initializes the plugin
	if ( $instance === null || ! ( $instance instanceof WP_Youzign ) ) {
		$instance = WP_Youzign::instance();
	}

	return $instance;
}

$youzign = WP_Youzign();