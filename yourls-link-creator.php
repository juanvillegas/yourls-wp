<?php
/*
Plugin Name: YOURLS Link Creator
Plugin URI: http://andrewnorcross.com/plugins/yourls-link-creator/
Description: Creates a shortlink using YOURLS and stores as postmeta.
Version: 1.09
Author: Andrew Norcross
Author URI: http://andrewnorcross.com

	Copyright 2012 Andrew Norcross

	This program is free software; you can redistribute it and/or modify
	it under the terms of the GNU General Public License, version 2, as
	published by the Free Software Foundation.

	This program is distributed in the hope that it will be useful,
	but WITHOUT ANY WARRANTY; without even the implied warranty of
	MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
	GNU General Public License for more details.

	You should have received a copy of the GNU General Public License
	along with this program; if not, write to the Free Software
	Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
*/

if(!defined('YOURLS_BASE'))
	define('YOURLS_BASE', plugin_basename(__FILE__) );

if(!defined('YOURS_VER'))
	define('YOURS_VER', '1.09');

// Start up the engine
class YOURLSCreator
{

	/**
	 * This is our constructor
	 *
	 * @return YOURLSCreator
	 */
	public function __construct() {
		add_action		( 'plugins_loaded', 			array( $this, 'textdomain'			) 			);
		add_action		( 'admin_enqueue_scripts',		array( $this, 'scripts_styles'		), 10		);
		add_action		( 'admin_menu',					array( $this, 'yourls_settings'		) 			);
		add_action		( 'admin_init', 				array( $this, 'reg_settings'		) 			);
		add_action		( 'wp_ajax_create_yourls',		array( $this, 'create_yourls'		)			);
		add_action		( 'wp_ajax_delete_yourls',		array( $this, 'delete_yourls'		)			);
		add_action		( 'wp_ajax_stats_yourls',		array( $this, 'stats_yourls'		)			);
		add_action		( 'wp_ajax_clicks_yourls',		array( $this, 'clicks_yourls'		)			);
		add_action		( 'wp_ajax_key_change',			array( $this, 'key_change'			)			);
		add_action		( 'yourls_cron',				array( $this, 'yourls_click_cron'	)			);
		add_action		( 'transition_post_status',		array( $this, 'yourls_on_save'		), 10,  3	);
		add_action		( 'wp_head',					array( $this, 'shortlink_meta'		) 			);
		add_action		( 'manage_posts_custom_column',	array( $this, 'display_columns'		), 10,	2	);
		add_filter		( 'manage_posts_columns',		array( $this, 'register_columns'	)			);
		add_filter		( 'get_shortlink',				array( $this, 'yourls_shortlink'	), 10,	3	);
		add_filter		( 'pre_get_shortlink',			array( $this, 'shortlink_button'	), 2,	2	);
		add_filter		( 'plugin_action_links',		array( $this, 'quick_link'			), 10,	2	);
		// Select UI rendering hook depending on ShortURL interface style
		$yourls_options = get_option('yourls_options');
		$style = (array_key_exists( 'sty', $yourls_options ) ? $yourls_options['sty'] : 'metabox');
		switch ($style) 
		{
			case 'permalink':
				add_action		( 'edit_form_after_title',		array( $this, 'yourls_render_permalink'	), 10		);
				break;
			case 'metabox':
			default:
				add_action		( 'do_meta_boxes',				array( $this, 'metabox_yourls'		), 10,	2	);
		};

		register_activation_hook			( __FILE__, array( $this, 'schedule_cron'		)			);
		register_deactivation_hook			( __FILE__, array( $this, 'remove_cron'			)			);

		// custom template tag
		add_action		( 'yourls_display',				array( $this, 'yourls_display'		) 			);
	}

	/**
	 * load textdomain for international goodness
	 *
	 * @return YOURLSCreator
	 */


	public function textdomain() {

		load_plugin_textdomain( 'wpyourls', false, dirname( plugin_basename( __FILE__ ) ) . '/languages/' );
	}

	/**
	 * Scripts and stylesheets
	 *
	 * @return YOURLSCreator
	 */

	public function scripts_styles($hook) {
		$yourls_options = get_option('yourls_options');

		if ( $hook == 'settings_page_yourls-settings' || $hook == 'post-new.php' || $hook == 'post.php' ) {
			if ($yourls_options['sty'] != 'permalink')
				wp_enqueue_style( 'yourls-admin', plugins_url('/lib/css/yourls-admin.css', __FILE__), array(), YOURS_VER, 'all' );
			wp_enqueue_script( 'yourls-ajax', plugins_url('/lib/js/yourls.ajax.js', __FILE__) , array('jquery'), YOURS_VER, true );
		}

		if ( $hook == 'edit.php' ) {
			wp_enqueue_style( 'yourls-admin', plugins_url('/lib/css/yourls-admin.css', __FILE__), array(), YOURS_VER, 'all' );
		}

	}


	/**
	 * show settings link on plugins page
	 *
	 * @return YOURLSCreator
	 */

	public function quick_link( $links, $file ) {

		static $this_plugin;

		if (!$this_plugin) {
			$this_plugin = plugin_basename(__FILE__);
		}

		// check to make sure we are on the correct plugin
		if ($file == $this_plugin) {

			$settings_link	= '<a href="'.menu_page_url( 'yourls-settings', 0 ).'">'.__('Settings', 'wpyourls').'</a>';

			array_push($links, $settings_link);
		}

		return $links;

	}

	/**
	 * display YOURLS on theme via template tag
	 *
	 * @return YOURLSCreator
	 */

	public function yourls_display() {

		global $post;

		// check for a link
		$yourls_link	= get_post_meta( $post->ID, '_yourls_url', true );

		// bail if there is no shortlink
		if (empty($yourls_link) )
			return;

		$yours_show = '<p class="yourls-display">';
		$yours_show .= __('Shortlink:', 'wpyourls');
		$yours_show .= '<input id="yourls-link" size="28" title="click to highlight" type="text" name="yourls-link" value="'.$yourls_link.'" readonly="readonly" tabindex="501" onclick="this.focus();this.select()" />';
		$yours_show .= '</p>';

		echo $yours_show;

	}

	/**
	 * register and display columns
	 *
	 * @since 1.0
	 */


	public function register_columns( $columns ) {

		global $post_type_object;

		$yourls_options = get_option('yourls_options');

		$customs	= array_key_exists( 'typ', $yourls_options ) ? $yourls_options['typ'] : '';
		$builtin	= array('post' => 'post', 'page' => 'page');
		$types		= !empty($customs) ? array_merge($customs, $builtin) : $builtin;
		$screen		= $post_type_object->name;

		if ( !in_array( $screen, $types ) )
			return $columns;

		// get display for column icon
		$icon = '<img src="'.plugins_url('/lib/img/yourls-click.png', __FILE__).'" alt="Clicked" title="Clicked">';
		$columns['yourls-click'] = $icon;

		return $columns;

	}

	public function display_columns( $column_name, $post_id ) {

		$current_screen = get_current_screen();

		$yourls_options = get_option('yourls_options');

		$customs	= isset($yourls_options['typ']) ? $yourls_options['typ'] : false;
		$builtin	= array('post' => 'post', 'page' => 'page');

		$types		= $customs !== false ? array_merge($customs, $builtin) : $builtin;
		$screen		= $current_screen->post_type;

		if ( !in_array( $screen,  $types ) )
			return;

		if ( 'yourls-click' != $column_name )
			return;

		$count	= get_post_meta($post_id, '_yourls_clicks', true);

		$clicks	= empty( $count ) ? '0' : $count;

		echo '<span>'.$clicks.'</span>';

	}

	/**
	 * Create yourls link on publish if one doesn't exist
	 *
	 * @return YOURLSCreator
	 */

	public function yourls_on_save( $new_status, $old_status, $post ) {
		if ( 'publish' != $new_status )
			return;

		// only fire when settings have been filled out
		$yourls_options = get_option('yourls_options');

		if( empty($yourls_options['api']) || empty($yourls_options['url']) )
			return;

		// bail if user hasn't checked the box
		if(	!isset($yourls_options['sav']) )
		   	return;

		// permissions and all
		// if scheduled, wp already handled this
		if ( 'future' != $old_status && !current_user_can( 'edit_post', $post->ID ) )
			return;

		// check for a link
		$exist	= get_post_meta( $post->ID, '_yourls_url', true );

		if (!empty($exist) )
			return;

		//verify post is not a revision
		if ( !wp_is_post_revision( $post->ID ) ) {

			// process YOURLS call
			$base_url	= $yourls_options['url'];
			$short_url	= str_replace('http://', '', $base_url);
			$short_url	= trim($short_url, '/');

			$yourls		= 'http://'.$short_url.'/yourls-api.php';
			$api_key	= $yourls_options['api'];
			$action		= 'shorturl';
			$format		= 'JSON';
			$post_url	= get_permalink($post->ID);

			$yourls_r	= $yourls.'?signature='.$api_key.'&action='.$action.'&url='.$post_url.'&format='.$format.'';

			$response	= wp_remote_get( $yourls_r );

			// error. bail.
			if( is_wp_error( $response ) )
				return;

			// parse values
			$data		= $response['body'];

			// no URL on return. bail
			if(!$data)
				return;

			// everything worked, so make a link
			update_post_meta($post->ID, '_yourls_url', $data);

		}

	}

	/**
	 * Create shortlink function. Called on ajax
	 *
	 * @return YOURLSCreator
	 */

	public function create_yourls (){

		// get the post ID first
		if ( empty( $_POST['postID'] ) )
			return;
		$postID		= $_POST['postID'];
		$custom_kw	= (isset($_POST['keyword']) && $_POST['keyword'] !== 'none' ? $_POST['keyword'] : '');

		// only fire when settings have been filled out
		$yourls_options = get_option('yourls_options');

		if ( empty( $yourls_options['api'] ) || empty( $yourls_options['url'] ) )
			return;

		if ( !current_user_can( 'edit_post', $postID ) )
			return;

		// check for existing YOURLS
		$yourls_exist = get_post_meta($postID, '_yourls_url', true);

		// go get us a swanky new short URL if we dont have one
		if(empty($yourls_exist) ) {

			$base_url	= $yourls_options['url'];
			
			
			$short_url	= str_replace('http://', '', $base_url);
			$short_url	= trim($short_url, '/');


			$yourls		= 'http://'.$short_url.'/yourls-api.php';
			$api_key	= $yourls_options['api'];
			$action		= 'shorturl';
			$format		= 'json';
			$keyword	= $custom_kw;
			$post_url	= get_permalink($postID);

			$yourls_r	= $yourls.'?signature='.$api_key.'&action='.$action.'&url='.$post_url.'&format='.$format.'&keyword='.$keyword.'';

			$response	= wp_remote_get( $yourls_r );

			$ret = array();

			if( is_wp_error( $response ) ) {
				// we don't want to interfere with the save process. store message and return
				$ret['success']		= false;
				$ret['message'] 	= __('There was an error contacting the YOURLS API. Please try again.', 'wpyourls');
				$ret['error']		= __('no connection made', 'wpyourls');
				$ret['errcode']		= 'NOCONNECT';
				echo json_encode($ret);
				die();

			} else {
				$data		= $response['body'];
				$data_obj = json_decode($data);
			}

			if(!$data) {
				$ret['success'] 	= false;
				$ret['message'] 	= __('The YOURLS API did not return a valid URL. Please try again.', 'wpyourls');
				$ret['error']		= __('no return data', 'wpyourls');
				$ret['errcode']		= 'NORETURN';
				echo json_encode($ret);
				die();
			}

			if($data){
				update_post_meta($postID, '_yourls_url', $data_obj->shorturl);
				
				$ret['success'] 	= true;
				$ret['message'] 	= __('You have created a new YOURLS link', 'wpyourls');
				$ret['link']		= esc_url( $data_obj->shorturl );
				$ret['yourlsbox']	= $this->yourls_exists_html($postID);

				echo json_encode($ret);
				die();
			}

		}

	}

	/**
	 * Delete shortlink function. Called on ajax
	 *
	 * @return YOURLSCreator
	 */

	public function delete_yourls (){

		// get the post ID first
		$postID		= $_POST['postID'];

		// only fire when settings have been filled out
		$yourls_options = get_option('yourls_options');

		if ( empty( $yourls_options['api'] ) || empty( $yourls_options['url'] ) )
			return;

		// only fire if user has the option
		if (!current_user_can('edit_post', $postID))
			return;

		// check for existing YOURLS
		$yourls_exist = get_post_meta($postID, '_yourls_url', true);

		if(!empty($yourls_exist) ) {

			// NOTE
			// Depends on the "delete" action plugin: install https://github.com/claytondaley/yourls-api-delete
			$base_url	= $yourls_options['url'];
			
			
			$short_url	= str_replace('http://', '', $base_url);
			$short_url	= trim($short_url, '/');


			$yourls		= 'http://'.$short_url.'/yourls-api.php';
			$api_key	= $yourls_options['api'];
			$action		= 'delete';
			$format		= 'json';

			$yourls_r	= $yourls.'?signature='.$api_key.'&action='.$action.'&format='.$format.'&shorturl='.$yourls_exist;
			$response	= wp_remote_get( $yourls_r );
			$ret = array();

			if( is_wp_error( $response ) ) {
				// we don't want to interfere with the save process. store message and return
				$ret['success']		= false;
				$ret['message'] 	= __('There was an error contacting the YOURLS API. Please try again.', 'wpyourls');
				$ret['error']		= __('no connection made', 'wpyourls');
				$ret['errcode']		= 'NOCONNECT';
				echo json_encode($ret);
				die();

			} else {
				$data		= $response['body'];
				$data_obj = json_decode($data);
			}
			if(!$data) {
				$ret['success'] 	= false;
				$ret['message'] 	= __('The YOURLS API could not delete the url. Please try again.', 'wpyourls');
				$ret['error']		= __('no return data', 'wpyourls');
				$ret['errcode']		= 'NORETURN';
				echo json_encode($ret);
				die();
			}

			// 400 means undefined action: in case the user hasnt installed the delete plugin, it wont work..
			if( $data && $data_obj->errorCode != 400 ){ 
				// removed it from wordpress and yourls
				$ret['success'] 	= true;
				$ret['message'] 	= __('The shortlink has been removed from WordPress and also from YOURLS', 'wpyourls');
				$ret['yourlsbox']	= $this->yourls_create_html();

				delete_post_meta($postID, '_yourls_url');
				echo json_encode($ret);
				die();
			}else{
				// only removed it from wordpress.. yourls failed
				$ret['success'] 	= true;
				$ret['message'] 	= __('The shortlink has been removed from WordPress only. The yourls api call failed: have you installed the "delete" action plugin? Read <a href="https://github.com/claytondaley/yourls-api-delete" target="_blank">this</a>', 'wpyourls');
				$ret['yourlsbox']	= $this->yourls_create_html();

				delete_post_meta($postID, '_yourls_url');
				echo json_encode($ret);
				die();
			}
		} else {

			$ret['success'] 	= false;
			$ret['error']		= __('There is no shortlink to delete', 'wpyourls');
			$ret['errcode']		= 'NODELETE';
			echo json_encode($ret);
			die();

		}

	}

	/**
	 * retrieve stats
	 *
	 * @return YOURLSCreator
	 */

	public function stats_yourls() {

		$yourls_options = get_option('yourls_options');

		if(	empty($yourls_options['api']) || empty($yourls_options['url']) )
			return;

		$postID		= $_POST['postID'];

		$yourls_url = get_post_meta($postID, '_yourls_url', true);

		// gimme some click data honey
		if(!empty($yourls_url) ) {
			$base_url	= $yourls_options['url'];
			$short_url	= str_replace('http://', '', $base_url);
			$short_url	= trim($short_url, '/');

			$yourls		= 'http://'.$short_url.'/yourls-api.php';
			$api_key	= $yourls_options['api'];
			$action		= 'url-stats';
			$format		= 'json';
			$shorturl	= $yourls_url;

			$yourls_r	= $yourls.'?signature='.$api_key.'&action='.$action.'&shorturl='.$shorturl.'&format='.$format.'';

			$response	= wp_remote_get( $yourls_r );

			$ret = array();

			if( is_wp_error( $response ) ) {
				// we don't want to interfere with the save process. store message and return
				$ret['success']	= false;
				$ret['message'] = __('There was an error contacting the YOURLS API. Please try again.', 'wpyourls');
				$ret['error']	= __('There was an error contacting the YOURLS API. Please try again.', 'wpyourls');
				$ret['errcode']	= 'NOCONNECT';
				$ret['process'] = 'remote post failed';
				echo json_encode($ret);
				die();

			} else {
				$raw_data	= $response['body'];
				$data		= json_decode($raw_data);
			}

			if(!$data) {
				$ret['success'] = false;
				$ret['error']	= __('Unknown error', 'wpyourls');
				$ret['errcode']	= 'NORETURN';
				echo json_encode($ret);
				die();
			}

			if($data){
				$linkdata	= $data->link;
				$clicks		= $linkdata->clicks;

				$ret['success'] = true;
				$ret['message'] = sprintf( _n('Your shortlink has been clicked %d time.', 'Your shortlink has been clicked %d times.', $clicks, 'wpyourls'), $clicks );
				$ret['clicks']	= $clicks;
				update_post_meta($postID, '_yourls_clicks', $clicks);
				echo json_encode($ret);
				die();
			}

		}

	}

	/**
	 * run update job to get click counts via manual ajax
	 *
	 * @return YOURLSCreator
	 */

	public function clicks_yourls() {

		$yourls_options = get_option('yourls_options');

		if(	empty($yourls_options['api']) || empty($yourls_options['url']) )
			return;

		$args = array (
			'fields'		=> 'ids',
			'post_type'		=> 'any',
			'numberposts'	=> -1,
			'meta_key'		=> '_yourls_url',
			);

		$yourls_posts = get_posts( $args );
		$yourls_count = count($yourls_posts);
		$yourls_check = $yourls_count > 0 ? true : false;

		$ret = array();

		if($yourls_check === false) {
			$ret['success'] = false;
			$ret['error']	= __('No posts to check.', 'wpyourls');
			$ret['errcode']	= 'NOPOSTS';
			echo json_encode($ret);
			die();
		}

		foreach ($yourls_posts as $post) :

			$yourls_url = get_post_meta($post, '_yourls_url', true);

			$base_url	= $yourls_options['url'];
			$short_url	= str_replace('http://', '', $base_url);
			$short_url	= trim($short_url, '/');

			$yourls		= 'http://'.$short_url.'/yourls-api.php';

			$api_key	= $yourls_options['api'];
			$action		= 'url-stats';
			$format		= 'json';
			$shorturl	= $yourls_url;

			$yourls_r	= $yourls.'?signature='.$api_key.'&action='.$action.'&shorturl='.$shorturl.'&format='.$format.'';

			$response	= wp_remote_get( $yourls_r );

			if( is_wp_error( $response ) ) {
				$ret['success'] = false;
				$ret['error']	= __('Could not connect to the YOURLS server.', 'wpyourls');
				$ret['errcode']	= 'APIERROR';
				echo json_encode($ret);
				die();
			}

			$raw_data	= $response['body'];
			$data		= json_decode($raw_data);

			if(!$data)
				return;

			if(isset($data->link)) {
				$linkdata	= $data->link;
				$clicks		= $linkdata->clicks;
				update_post_meta($post, '_yourls_clicks', $clicks);
			}

		endforeach;

			$ret['updated'] = $yourls_count;
			$ret['success'] = true;
			$ret['message']	= sprintf( _n('Success! %d entry has been updated.', 'Success! %d entries have been updated.', $yourls_count, 'wpyourls'), $yourls_count );
			echo json_encode($ret);
			die();

	}

	/**
	 * scheduling for YOURLS cron jobs
	 *
	 * @return YOURLSCreator
	 */

	public function schedule_cron() {
		if ( !wp_next_scheduled( 'yourls_cron' ) ) {
			wp_schedule_event(time(), 'hourly', 'yourls_cron');
		}
	}

	public function remove_cron() {
		$timestamp = wp_next_scheduled( 'yourls_cron' );
		wp_unschedule_event($timestamp, 'yourls_cron' );
	}

	/**
	 * run update job to get click counts via cron
	 *
	 * @return YOURLSCreator
	 */

	public function yourls_click_cron() {

		$yourls_options = get_option('yourls_options');

		if(	empty($yourls_options['api']) || empty($yourls_options['url']) )
			return;

		$args = array (
			'fields'		=> 'ids',
			'post_type'		=> 'any',
			'numberposts'	=> -1,
			'meta_key'		=> '_yourls_url',
			);

		$yourls_posts = get_posts( $args );
		$yourls_count = (count($yourls_posts) > 0 ) ? true : false;

		if($yourls_count == false)
			return;

		foreach ($yourls_posts as $post) : setup_postdata($post);

			$yourls_url = get_post_meta($post, '_yourls_url', true);

			$base_url	= $yourls_options['url'];
			$short_url	= str_replace('http://', '', $base_url);
			$short_url	= trim($short_url, '/');

			$yourls		= 'http://'.$short_url.'/yourls-api.php';

			$api_key	= $yourls_options['api'];
			$action		= 'url-stats';
			$format		= 'json';
			$shorturl	= $yourls_url;

			$yourls_r	= $yourls.'?signature='.$api_key.'&action='.$action.'&shorturl='.$shorturl.'&format='.$format.'';

			$response	= wp_remote_get( $yourls_r );

			if( is_wp_error( $response ) )
				return;

			$raw_data	= $response['body'];
			$data		= json_decode($raw_data);

			if($data){
				$linkdata	= $data->link;
				$clicks		= $linkdata->clicks;
				update_post_meta($post, '_yourls_clicks', $clicks);
			}

		endforeach;

	}

	/**
	 * convert from Ozh (and Otto's) plugin
	 *
	 * @return YOURLSCreator
	 */

	public function key_change() {

		$yourls_options = get_option('yourls_options');

		// set up return array for ajax responses
		$ret = array();

		// run check for API info
		if(	empty($yourls_options['api']) || empty($yourls_options['url']) ) {
			$ret['success'] = false;
			$ret['errcode'] = 'API_MISSING';
			$ret['message'] = __('You have not entered any API information.', 'wpyourls');
			echo json_encode($ret);
			die();
		}

		// set query to look for existing keys
		$args = array (
			'fields'		=> 'ids',
			'post_type'		=> 'any',
			'numberposts'	=> -1,
			'meta_key'		=> 'yourls_shorturl',
			);

		$yourls_posts = get_posts( $args );
		$yourls_count = count($yourls_posts);

		// return if no keys found
		if( $yourls_count == 0 ) {
			$ret['success'] = false;
			$ret['errcode'] = 'KEY_MISSING';
			$ret['message'] = $ret['message'] = __('There are no keys to convert.', 'wpyourls');
			echo json_encode($ret);
			die();
		}

		// set up SQL query
		global $wpdb;

		// set keys for swap
		$key_old  = 'yourls_shorturl';
		$key_new  = '_yourls_url';

		// run SQL query
		$key_query = $wpdb->query (
			$wpdb->prepare("
				UPDATE $wpdb->postmeta
				SET meta_key = REPLACE (meta_key, %s, %s )",
				$key_old, $key_new
			)
		);

		// return if keys found and conversion is done
		if( $key_query > 0 ) {
			$ret['success'] = true;
			$ret['updated'] = $key_query;
			$ret['message'] = sprintf( _n('Success! %d key has been updated.', 'Success! %d keys have been updated.', $key_query, 'wpyourls'), $key_query );
			echo json_encode($ret);
			die();
		}

	}

	/**
	 * build out settings page and meta boxes
	 *
	 * @return YOURLSCreator
	 */

	public function yourls_settings() {
		add_submenu_page('options-general.php', __('YOURLS Settings', 'wpyourls'), __('YOURLS Settings', 'wpyourls'), 'manage_options', 'yourls-settings', array( $this, 'yourls_settings_display' ));
	}

	/**
	 * display metabox
	 *
	 * @return YOURLSCreator
	 */

	public function metabox_yourls( $page, $context ) {
		$yourls_options = get_option('yourls_options');

		if(	empty($yourls_options['api']) || empty($yourls_options['url']) )
			return;

		// check post status, only display on posts with URLs
		$status		= get_post_status();
		if ( $status == 'publish' ||
			 $status == 'future' ||
			 $status == 'pending'
			)
		{

			// now set array of post types
			$customs	= isset($yourls_options['typ']) ? $yourls_options['typ'] : false;
			$builtin	= array('post' => 'post', 'page' => 'page');

			$types		= $customs !== false ? array_merge($customs, $builtin) : $builtin;

			if ( in_array( $page,  $types ) && 'side' == $context )
				add_meta_box('yourls-post-display', __('YOURLS Shortlink', 'wpyourls'), array(&$this, 'yourls_post_display'), $page, $context, 'high');
		}
	}


	/**
	 * Register settings
	 *
	 * @return YOURLSCreator
	 */


	public function reg_settings() {
		register_setting( 'yourls_options', 'yourls_options');
	}


	/**
	 * Display YOURLS shortlink if present
	 *
	 * @return YOURLSCreator
	 */

	public function yourls_post_display() {

		global $post;
		$yourls_link	= get_post_meta($post->ID, '_yourls_url', true);

		if(!empty($yourls_link)) {
			echo $this->yourls_exists_html($post->ID);
		} else {
			echo $this->yourls_create_html();
		}
	}

	/**
	 * Display YOURLS shortlink in format friendly to permalink region
	 *
	 * @return YOURLSCreator
	 */

	public function yourls_render_permalink() {
		global $post;
		$yourls_options = get_option('yourls_options');
		$yourls_link = get_post_meta($post->ID, '_yourls_url', true);

		if(	empty($yourls_options['api']) || empty($yourls_options['url']) )
			return;

		// check post status, only display on posts with URLs
		$status		= get_post_status();
		if ( $status == 'publish' ||
			 $status == 'future' ||
			 $status == 'pending'
			)
		{
			if(!empty($yourls_link)) {
				echo '<div id="yourls-post-display"  class="hide-if-no-js">' . 
				$this->yourls_exists_html($post->ID) . 
				'</div>';
			} else {
				echo '<div id="yourls-post-display"  class="hide-if-no-js">' . 
				$this->yourls_create_html() .
				'</div>';
			}
		}
	}
	
	/**
	 * Returns input for posts without a shorturl in the current UI mode
	 *
	 * @return YOURLSCreator
	 */

	public function yourls_create_html() {
		$yourls_options = get_option('yourls_options');
		switch (array_key_exists( 'sty', $yourls_options ) ? $yourls_options['sty'] : '') 
		{
			case 'permalink':
				return '' . 
				'<p class="yourls-create-block">' . 
				'<strong>Shortlink (YoURLs):</strong> ' . 
				'<span id="sample-permalink" tabindex="-1">http://'.$yourls_options['url'].'/' . 
				'<input title="optional keyword" id="yourls-keyw" class="yourls-keyw" size="20" type="text" name="yourls-keyw" value="" tabindex="501" />' . 
				'/</span> ' . 
				'<input type="button" class="button button-small yourls-api" title="enter a phrse" id="yourls-get" type="text" name="yourls-get" value="Request URL" tabindex="502" /> ' . 
				'<img class="ajax-loading btn-yourls" src="'.plugins_url('/lib/img/wpspin-light.gif', __FILE__).'">' . 
//				'<span class="howto">' . __('optional keyword', 'wpyourls') . '</span>' . 
				'</p>' . 
				'';
			case 'metabox':
			default:
				return '' . 
				'<p class="yourls-create-block">' . 
				'<input id="yourls-keyw" class="yourls-keyw" size="20" type="text" name="yourls-keyw" value="" tabindex="501" />' . 
				'<img class="ajax-loading btn-yourls" src="'.plugins_url('/lib/img/wpspin-light.gif', __FILE__).'">' . 
				'<input type="button" class="button-secondary yourls-api" id="yourls-get" type="text" name="yourls-get" value="Get YOURLS" tabindex="502" />' . 
				'<span class="howto">' . __('optional keyword', 'wpyourls') . '</span>' . 
				'</p>';
		}
	}
	
	/**
	 * Returns input for posts with a shorturl in the current UI mode
	 *
	 * @return YOURLSCreator
	 */

	public function yourls_exists_html($postID) {
		$yourls_options = get_option('yourls_options');
		$yourls_link = get_post_meta($postID, '_yourls_url', true);
		$yourls_clicks = get_post_meta($postID, '_yourls_clicks', true);
		switch (array_key_exists( 'sty', $yourls_options ) ? $yourls_options['sty'] : '') 
		{
			case 'permalink':
				return '' . 
				'<p class="yourls-exist-block">' . 
				'<strong>Shortlink (YoURLs):</strong> ' . 
				'<span id="sample-permalink" tabindex="-1">http://'.$yourls_options['url'].'/' . 
				'<input id="yourls-link" title="click to highlight" class="yourls-link-input" type="text" name="yourls-link" value="'. substr( esc_url($yourls_link), strlen($yourls_options['url'])+8 ) .'" readonly="readonly" tabindex="501" onclick="this.focus();this.select()" />' . 
				'/</span> ' . 
				'<input type="button" class="button button-small yourls-delete" value="'. __('Delete Link', 'wpyourls').'" > ' . 
				'<img class="ajax-loading btn-yourls" src="'.plugins_url('/lib/img/wpspin-light.gif', __FILE__).'"> ' . 
//				'<p class="howto">' . sprintf( _n('Your YOURLS link has generated %d click.', 'Your YOURLS link has generated %d clicks.', $yourls_clicks, 'wpyourls'), $yourls_clicks ) . 
				'</p>' . 
				'';
			case 'metabox':
			default:
				return '' .
				'<p class="yourls-exist-block">' . 
				'<input id="yourls-link" title="click to highlight" class="yourls-link-input" type="text" name="yourls-link" value="'.esc_url( $yourls_link ).'" readonly="readonly" tabindex="501" onclick="this.focus();this.select()" /> ' . 
				'<input type="button" class="yourls-delete" value="'. __('Delete Link', 'wpyourls').'" >' . 
				'</p>' . 
				'<p class="howto">' . sprintf( _n('Your YOURLS link has generated %d click.', 'Your YOURLS link has generated %d clicks.', $yourls_clicks, 'wpyourls'), $yourls_clicks );
		}
	}

	/**
	 * Display the button on the backend
	 *
	 * @return YOURLSCreator
	 */

	public function shortlink_button($shortlink, $id) {

		// check options to see if it's enabled
		$yourls_options = get_option('yourls_options');

		if(	!isset($yourls_options['sht']) )
			return $shortlink;

		$post = get_post($id);

		// bail if nothing there
		if(empty($post))
			return $shortlink;

		// check existing postmeta for YOURLS
		$yourls_link = get_post_meta($id, '_yourls_url', true);

		// bail if no YOURLS is present
		if(empty($yourls_link))
			return $shortlink;

		// we got this far? good. send it out
		return $yourls_link;
	}

	/**
	 * Filter wp_shortlink with new YOURLS link
	 *
	 * @return YOURLSCreator
	 */

	public function yourls_shortlink($shortlink, $id, $context) {

		// no shortlinks exist on non-singular items, so bail
		if (!is_singular() )
			return;

		$yourls_options = get_option('yourls_options');

		// Look for the post ID passed by wp_get_shortlink() first
		if ( empty( $id ) ) {
			global $post;
			$id = $post->ID;
		}

		// Fall back in case we still don't have a post ID
		if ( empty( $id ) ) {

			if ( ! empty( $shortlink ) )
				return $shortlink;

			return false;
		}

		if(	empty($yourls_options['api']) || empty($yourls_options['url']) )
			return $shortlink;

		if(	!isset($yourls_options['sht']) )
			return $shortlink;

		$yourls_exist = get_post_meta($id, '_yourls_url', true);

		if ( !empty($yourls_exist))
			$shortlink = get_post_meta( $id, '_yourls_url', true );

		return $shortlink;
	}

	/**
	 * add shortlink into head if present
	 *
	 * @return YOURLSCreator
	 */

	public function shortlink_meta() {

		// no shortlinks exist on non-singular items, so bail
		if (!is_singular() )
			return;

		// check options to see if it's enabled
		$yourls_options = get_option('yourls_options');

		if(	!isset($yourls_options['sht']) )
			return;

		global $post;

		// check existing postmeta for YOURLS
		$yourls_link = get_post_meta($post->ID, '_yourls_url', true);

		// got a YOURLS? well then add it
		if(!empty($yourls_link))
			echo '<link href="'.esc_url($yourls_link).'" rel="shortlink">';


	}

	/**
	 * Post type helper
	 *
	 * @return YOURLSCreator
	 */

	private function post_types($yourls_typ) {

		// grab CPTs
		$args	= array(
			'public'	=> true,
			'_builtin'	=> false
		);
		$output = 'objects';
		$types	= get_post_types($args, $output);
		// output loop of types
			$boxes	= '';

			foreach ($types as $type ) {
				// type variables
				$name	= $type->name;
				$icon	= $type->menu_icon;
				$label	= $type->labels->name;
				// check for setting in array
				$check	= !empty($yourls_typ) && in_array($name, $yourls_typ) ? 'checked="checked"' : '';
				// output checkboxes
				$boxes	.= '<span>';
				$boxes	.= '<label for="yourls_options[typ]['.$name.']">';
				$boxes	.= '<input type="checkbox" name="yourls_options[typ]['.$name.']" id="yourls_options[typ]['.$name.']" value="'.$name.'" '.$check.' />';
				$boxes	.= ' '.$label.'</label>';
				$boxes	.= '</span>';
			}
		return $boxes;
	}

	/**
	 * Display main options page structure
	 *
	 * @return YOURLSCreator
	 */

	public function yourls_settings_display() {
		if (!current_user_can('manage_options') )
			return;
		?>

		<div class="wrap">
		<div class="icon32" id="icon-yourls"><br></div>
		<h2><?php _e('YOURLS Link Creator Settings', 'wpyourls') ?></h2>

		<div id="poststuff" class="metabox-holder has-right-sidebar">
		<?php
		echo $this->settings_side();
		echo $this->settings_open();
		?>

		   	<div class="yourls-form-text">
		   	<p><?php _e('Below are the basic settings for the YOURLS creator', 'wpyourls') ?></p>
			</div>

			<div class="yourls-form-options">
				<form method="post" action="options.php">
				<?php
				settings_fields( 'yourls_options' );
				$yourls_options	= get_option('yourls_options');

				$yourls_url		= (isset($yourls_options['url'])	? $yourls_options['url']	: ''		);
				$yourls_api		= (isset($yourls_options['api'])	? $yourls_options['api']	: ''		);
				$yourls_sav		= (isset($yourls_options['sav'])	? $yourls_options['sav']	: 'false'	);
				$yourls_cpt		= (isset($yourls_options['cpt'])	? $yourls_options['cpt']	: 'false'	);
				$yourls_typ		= (isset($yourls_options['typ'])	? $yourls_options['typ']	: ''		);
				$yourls_sht		= (isset($yourls_options['sht'])	? $yourls_options['sht']	: 'false'	);
				$yourls_sty		= (isset($yourls_options['sty'])	? $yourls_options['sty']	: ''		);

				?>

				<table class="form-table yourls-table">
				<tbody>
					<tr>
						<th><label for="yourls_options[url]"><?php _e('ShortURL Style', 'wpyourls') ?></label></th>
						<td>
						<p><input type="radio" name="yourls_options[sty]" value="metabox" <?php if ($yourls_sty == '' || $yourls_sty == 'metabox') echo 'checked' ?>> Metabox (default)</p>
						<p><img style="vertical-align:middle" class="" src="<?php echo plugins_url('/lib/img/metabox.gif', __FILE__); ?>" ></p>
						<p><input type="radio" name="yourls_options[sty]" value="permalink" <?php if ($yourls_sty == 'permalink') echo 'checked' ?>> Permalink</p>
						<p><img style="vertical-align:middle" class="" src="<?php echo plugins_url('/lib/img/permalink.gif', __FILE__); ?>" ></p>
						<p class="description"><?php _e('Select the location of the short-URL controls.', 'wpyourls') ?></p>
						</td>
					</tr>
					
					<tr>
						<th><label for="yourls_options[url]"><?php _e('YOURLS Custom URL', 'wpyourls') ?></label></th>
						<td>
						<input type="text" class="regular-text" value="<?php echo $yourls_url; ?>" id="yourls_url" name="yourls_options[url]">
						<p class="description"><?php _e('Enter your custom YOURLS URL', 'wpyourls') ?></p>
						</td>
					</tr>

					<tr>
						<th><label for="yourls_options[api]"><?php _e('YOURLS API Signature Key', 'wpyourls') ?></label></th>
						<td>
						<input type="text" class="regular-text" value="<?php echo $yourls_api; ?>" id="yourls_api" name="yourls_options[api]">
						<p class="description"><?php _e('Found in the tools section on your YOURLS admin page.', 'wpyourls') ?></p>
						</td>
					</tr>

					<tr>
						<th><label for="yourls_options[sav]"><?php _e('Auto generate links', 'wpyourls') ?></label></th>
						<td>
						<input type="checkbox" name="yourls_options[sav]" id="yourls_sav" value="true" <?php checked( $yourls_sav, 'true' ); ?> />
						<span class="description"><?php _e('Create a YOURLS link whenever a post is saved.', 'wpyourls') ?></span>
						</td>
					</tr>

					<tr>
						<th><label for="yourls_options[sht]"><?php _e('Use YOURLS for Shortlink', 'wpyourls') ?></label></th>
						<td>
						<input type="checkbox" name="yourls_options[sht]" id="yourls_sht" value="true" <?php checked( $yourls_sht, 'true' ); ?> />
						<span class="description"><?php _e('Use the YOURLS link wherever wp_shortlink is fired', 'wpyourls') ?></span>
						</td>
					</tr>

					<tr>
						<th><label for="yourls_options[cpt]"><?php _e('Display on Custom Post Types', 'wpyourls') ?></label></th>
						<td>
						<input type="checkbox" name="yourls_options[cpt]" id="yourls_cpt" value="true" <?php checked( $yourls_cpt, 'true' ); ?> />
						<span class="description"><?php _e('Display the YOURLS creator on public custom post types', 'wpyourls') ?></span>
						</td>
					</tr>

					<tr class="secondary yourls-types" style="display:none;">
						<th><label for="yourls_options[typ]"><?php _e('Select the post types to include', 'wpyourls') ?></label></th>
						<td><?php echo $this->post_types($yourls_typ); ?></td>
					</tr>

				</tbody>
				</table>

				<p><input type="submit" class="button-primary" value="<?php _e('Save Changes') ?>" /></p>
				</form>

			</div>

	<?php echo $this->settings_close(); ?>

	</div>
	</div>

	<?php }

	/**
	 * Some extra stuff for the settings page
	 *
	 * this is just to keep the area cleaner
	 *
	 * @return YOURLSCreator
	 */

	public function settings_side() { ?>

		<div id="side-info-column" class="inner-sidebar">
			<div class="meta-box-sortables">
				<div id="yourls-admin-about" class="postbox yourls-sidebox">
					<h3 class="hndle" id="about-sidebar"><?php _e('About the Plugin', 'wpyourls') ?></h3>
					<div class="inside">
						<p>Talk to <a href="http://twitter.com/norcross" target="_blank">@norcross</a> on twitter or visit the <a href="http://wordpress.org/support/plugin/yourls-link-creator/" target="_blank"><?php _e('plugin support form', 'wpyourls'); ?></a> <?php _e('for bugs or feature requests.', 'wpyourls'); ?></p>
						<p><?php _e('<strong>Enjoy the plugin?</strong>', 'wpyourls'); ?><br />
						<a href="http://twitter.com/?status=I'm using @norcross's YOURLS Link Creator plugin - check it out! http://l.norc.co/yourls/" target="_blank"><?php _e('Tweet about it', 'wpyourls') ?></a> <?php _e('and consider donating.', 'wpyourls') ?></p>
						<p><?php _e('<strong>Donate:</strong> A lot of hard work goes into building plugins - support your open source developers. Include your twitter username and I\'ll send you a shout out for your generosity. Thank you!', 'wpyourls'); ?><br />
						<form action="https://www.paypal.com/cgi-bin/webscr" method="post" target="_blank">
						<input type="hidden" name="cmd" value="_s-xclick">
						<input type="hidden" name="hosted_button_id" value="11085100">
						<input type="image" src="https://www.paypalobjects.com/en_US/i/btn/btn_donate_SM.gif" border="0" name="submit" alt="PayPal - The safer, easier way to pay online!">
						<img alt="" border="0" src="https://www.paypalobjects.com/en_US/i/scr/pixel.gif" width="1" height="1">
						</form></p>
					</div>
				</div>
			</div>

			<div class="meta-box-sortables">
				<div id="yourls-data-refresh" class="postbox yourls-sidebox">
					<h3 class="hndle" id="data-sidebar"><?php _e('Data Options:', 'wpyourls') ?></h3>
					<div class="inside">
						<p><?php _e('Click the button below to refresh the click count data for all posts with a YOURLS link.', 'wpyourls'); ?></p>

						<input type="button" class="yourls-click-updates button-primary" value="<?php _e('Refresh Click Counts', 'wpyourls'); ?>" >
						<img class="ajax-loading btn-yourls" src="<?php echo plugins_url('/lib/img/wpspin-light.gif', __FILE__); ?>" >
						<hr />

						<p><?php _e('Using Ozh\'s plugin? Click here to convert the existing meta keys', 'wpyourls'); ?></p>
						<input type="button" class="yourls-convert button-primary" value="<?php _e('Convert Meta Keys', 'wpyourls'); ?>" >
						<img class="ajax-loading btn-convert" src="<?php echo plugins_url('/lib/img/wpspin-light.gif', __FILE__); ?>" >


<!--					the YOURLS API doesn't support a way just check for a URL or not.
						<hr />
						<p>Click the button below to check for existing YOURLS data for your site content.</p>
						<input type="button" class="yourls-import button-secondary" value="Import YOURLS data" >
-->
					</div>
				</div>
			</div>

			<div class="meta-box-sortables">
				<div id="yourls-admin-more" class="postbox yourls-sidebox">
					<h3 class="hndle" id="links-sidebar"><?php _e('Links:', 'wpyourls') ?></h3>
					<div class="inside">
						<ul>
						<li><a href="http://yourls.org/" target="_blank"><?php _e('YOURLS homepage', 'wpyourls'); ?></a></li>
						<li><a href="http://wordpress.org/extend/plugins/yourls-link-creator/" target="_blank"><?php _e('Plugin on WP.org', 'wpyourls'); ?></a></li>
						<li><a href="https://github.com/norcross/yourls-link-creator/" target="_blank"><?php _e('Plugin on GitHub', 'wpyourls'); ?></a></li>
						<li><a href="http://wordpress.org/support/plugin/yourls-link-creator/" target="_blank"><?php _e('Support Forum', 'wpyourls'); ?></a><li>

						</ul>
					</div>
				</div>
			</div>

		</div> <!-- // #side-info-column .inner-sidebar -->

	<?php }

	public function settings_open() { ?>

		<div id="post-body" class="has-sidebar">
			<div id="post-body-content" class="has-sidebar-content">
				<div id="normal-sortables" class="meta-box-sortables">
					<div id="about" class="postbox">
						<div class="inside">

	<?php }

	public function settings_close() { ?>

						<br class="clear" />
						</div>
					</div>
				</div>
			</div>
		</div>

	<?php }

/// end class
}

// Instantiate our class
$YOURLSCreator = new YOURLSCreator();
