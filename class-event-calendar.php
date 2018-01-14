<?php
/*
Available filters:
eventcalendar_shortcode
eventcalendar_fullcalendar_args
eventcalendar_qtip
eventcalendar_qtip_args
halftheory_admin_menu_parent
eventcalendar_admin_menu_parent
eventcalendar_post_types
eventcalendar_the_content
eventcalendar_toggle
*/

// Exit if accessed directly.
defined('ABSPATH') || exit;

if (!class_exists('Event_Calendar')) :
class Event_Calendar {

	public function __construct() {
		$this->plugin_name = get_called_class();
		$this->plugin_title = ucwords(str_replace('_', ' ', $this->plugin_name));
		$this->prefix = sanitize_key($this->plugin_name);
		$this->prefix = preg_replace("/[^a-z0-9]/", "", $this->prefix);

		// admin options
		if (!$this->is_front_end()) {
			if (!is_multisite()) {
				add_action('admin_menu', array($this,'admin_menu'));
			}
			else {
				add_action('network_admin_menu', array($this,'admin_menu'));
			}
		}

		// stop if not active
		$active = $this->get_option('active', false);
		if (empty($active)) {
			return;
		}

		// admin postmeta
		if (!$this->is_front_end()) {
			add_action('add_meta_boxes', array($this,'add_meta_boxes'));
		}
		else {
			// bbpress
			#add_action('media_buttons', array($this, 'add_meta_boxes_frontend'));
			add_action('bbp_theme_after_forum_form_content', array($this, 'add_meta_boxes_frontend'));
			add_action('bbp_theme_after_topic_form_content', array($this, 'add_meta_boxes_frontend'));
			add_action('bbp_theme_after_reply_form_content', array($this, 'add_meta_boxes_frontend'));
			// ajax
        	add_action('wp_ajax_'.$this->prefix.'_fullcalendar', array($this, 'ajax_fullcalendar'));
        	add_action('wp_ajax_nopriv_'.$this->prefix.'_fullcalendar', array($this, 'ajax_fullcalendar'));
        	add_action('wp_ajax_'.$this->prefix.'_qtip', array($this, 'ajax_qtip'));
        	add_action('wp_ajax_nopriv_'.$this->prefix.'_qtip', array($this, 'ajax_qtip'));
		}
		// only editors
		if (is_user_logged_in() && current_user_can('edit_posts')) {
			add_action('save_post', array($this,'save_post'), 10, 3);
        	add_action('wp_ajax_'.$this->prefix.'_geo_search', array($this, 'ajax_geo_search'));
        	add_action('wp_ajax_nopriv_'.$this->prefix.'_geo_search', array($this, 'ajax_geo_search'));
        }

		// filters
		$this->shortcode = 'event-calendar';
		add_shortcode($this->shortcode, array($this, 'shortcode'));
		if ($this->is_front_end()) {
			add_filter('the_content', array($this,'the_content'), 20);
			add_action('bbp_template_after_single_forum', array($this,'the_content'));
			add_action('bbp_template_after_single_topic', array($this,'the_content'));
			add_action('bbp_template_after_single_reply', array($this,'the_content'));
		}
	}

	/* functions-common */

	private function make_array($str = '', $sep = ',') {
		if (function_exists(__FUNCTION__)) {
			$func = __FUNCTION__;
			return $func($str, $sep);
		}
		if (is_array($str)) {
			return $str;
		}
		if (empty($str)) {
			return array();
		}
		$arr = explode($sep, $str);
		$arr = array_map('trim', $arr);
		$arr = array_filter($arr);
		return $arr;
	}

	private function is_front_end() {
		if (function_exists(__FUNCTION__)) {
			$func = __FUNCTION__;
			return $func();
		}
		if (is_admin() && !wp_doing_ajax()) {
			return false;
		}
		if (wp_doing_ajax()) {
			if (!empty($_SERVER["HTTP_REFERER"])) {
				$url_test = $_SERVER["HTTP_REFERER"];
			}
			else {
				$url_test = $this->get_current_uri();
			}
			if (strpos($url_test, admin_url()) !== false) {
				return false;
			}
		}
		return true;
	}

	private function get_current_uri() {
		if (function_exists(__FUNCTION__)) {
			$func = __FUNCTION__;
			return $func();
		}
	 	$res  = is_ssl() ? 'https://' : 'http://';
	 	$res .= $_SERVER['HTTP_HOST'];
	 	$res .= $_SERVER['REQUEST_URI'];
		return $res;
	}

	private function strip_all_shortcodes($str = '') {
		if (function_exists(__FUNCTION__)) {
			$func = __FUNCTION__;
			return $func($str);
		}
		return preg_replace("/\[[^\]]+\]/is", "", $str);
	}

	/* admin */

	public function admin_menu() {
		if (!is_array($GLOBALS['menu'])) {
			return;
		}

		$has_parent = false;
		$parent_slug = $this->prefix;
		$parent_name = apply_filters('halftheory_admin_menu_parent', 'Halftheory');
		$parent_name = apply_filters('eventcalendar_admin_menu_parent', $parent_name);

		// set parent to nothing to skip parent menu creation
		if (empty($parent_name)) {
			add_options_page(
				$this->plugin_title,
				$this->plugin_title,
				'manage_options',
				$this->prefix,
				__CLASS__ .'::menu_page'
			);
			return;
		}

		// find top level menu if it exists
	    foreach ($GLOBALS['menu'] as $value) {
	    	if ($value[0] == $parent_name) {
	    		$parent_slug = $value[2];
	    		$has_parent = true;
	    		break;
	    	}
	    }

		// add top level menu if it doesn't exist
		if (!$has_parent) {
			add_menu_page(
				$this->plugin_title,
				$parent_name,
				'manage_options',
				$parent_slug,
				__CLASS__ .'::menu_page'
			);
		}

		// add the menu
		add_submenu_page(
			$parent_slug,
			$this->plugin_title,
			$this->plugin_title,
			'manage_options',
			$this->prefix,
			__CLASS__ .'::menu_page'
		);
	}

	public function menu_page() {
 		global $title;
		?>
		<div class="wrap">
			<h2><?php echo $title; ?></h2>
		<?php
 		$plugin = new Event_Calendar();

	    // http://codex.wordpress.org/Geodata
        if ($_POST['save']) {
        	$save = function() use ($plugin) {
				// verify this came from the our screen and with proper authorization
				if (!isset($_POST[$plugin->plugin_name.'::menu_page'])) {
					return;
				}
				if (!wp_verify_nonce($_POST[$plugin->plugin_name.'::menu_page'], plugin_basename(__FILE__))) {
					return;
				}
				// get values
				$options_arr = $plugin->get_options_array();
				$options = array();
				foreach ($options_arr as $value) {
					$name = $plugin->prefix.'_'.$value;
					if (!isset($_POST[$name])) {
						continue;
					}
					if (empty($_POST[$name])) {
						continue;
					}
					$options[$value] = $_POST[$name];
				}
				// save it
	            $updated = '<div class="updated"><p><strong>Options saved.</strong></p></div>';
	            $error = '<div class="error"><p><strong>Error: There was a problem.</strong></p></div>';
				if (!empty($options)) {
		            if ($plugin->update_option($options)) {
		            	echo $updated;
		            }
		        	else {
		        		// where there changes?
		        		$options_old = $plugin->get_option(null, array());
		        		ksort($options_old);
		        		ksort($options);
		        		if ($options_old !== $options) {
		            		echo $error;
		            	}
		            	else {
			            	echo $updated;
		            	}
		        	}
				}
				else {
		            if ($plugin->delete_option()) {
		            	echo $updated;
		            }
		        	else {
		            	echo $updated;
		        	}
				}
			};
			$save();

			// import postmeta
			if (isset($_POST[$plugin->prefix.'_eventpost_import']) && !empty($_POST[$plugin->prefix.'_eventpost_import'])) {
				$args = array(
					'post_type' => 'any',
					'post_status' => 'any',
					'posts_per_page' => -1,
					'no_found_rows' => true,
					'nopaging' => true,
					'ignore_sticky_posts' => true,
					'orderby' => 'modified',
					'suppress_filters' => false,
					'meta_query' => array(
						array(
							'key' => 'event_begin',
							'compare' => 'EXISTS',
						)
					)
				);
				$posts = get_posts($args);
				if (!empty($posts)) {
					foreach ($posts as $post) {
						$date_start = get_post_meta($post->ID, 'event_begin', true);
						if (!empty($date_start)) {
							$date_start = date('d-m-Y H:i', strtotime($date_start));
						}
						$date_end = get_post_meta($post->ID, 'event_end', true);
						if (!empty($date_end)) {
							$date_end = date('d-m-Y H:i', strtotime($date_end));
						}
						$postmeta = array(
							'date_start' => $date_start,
							'date_end' => $date_end,
							'geo_address' => get_post_meta($post->ID, 'geo_address', true),
							'geo_latitude' => get_post_meta($post->ID, 'geo_latitude', true),
							'geo_longitude' => get_post_meta($post->ID, 'geo_longitude', true),
						);
						$postmeta = array_filter($postmeta);
						update_post_meta($post->ID, $plugin->prefix, $postmeta);
					}
	            	echo '<div class="updated"><p><strong>Imported data from the Event Post plugin.</strong></p></div>';
				}
				else {
	            	echo '<div class="error"><p><strong>No data to import from the Event Post plugin.</strong></p></div>';
				}
			}
			// delete postmeta
			if (isset($_POST[$plugin->prefix.'_eventpost_delete']) && !empty($_POST[$plugin->prefix.'_eventpost_delete'])) {
				if (!isset($posts)) {
					$args = array(
						'post_type' => 'any',
						'post_status' => 'any',
						'posts_per_page' => -1,
						'no_found_rows' => true,
						'nopaging' => true,
						'ignore_sticky_posts' => true,
						'orderby' => 'modified',
						'suppress_filters' => false,
						'meta_query' => array(
							array(
								'key' => 'event_begin',
								'compare' => 'EXISTS',
							)
						)
					);
					$posts = get_posts($args);
				}
				if (!empty($posts)) {
					foreach ($posts as $post) {
						delete_post_meta($post->ID, 'event_begin');
						delete_post_meta($post->ID, 'event_end');
						delete_post_meta($post->ID, 'event_color');
						delete_post_meta($post->ID, 'geo_address');
						delete_post_meta($post->ID, 'geo_latitude');
						delete_post_meta($post->ID, 'geo_longitude');
					}
	            	echo '<div class="updated"><p><strong>Deleted data from the Event Post plugin.</strong></p></div>';
				}
				else {
	            	echo '<div class="error"><p><strong>No data to delete from the Event Post plugin.</strong></p></div>';
				}
			}

        } // save

		// show the form
		$options_arr = $plugin->get_options_array();
		$options = $plugin->get_option(null, array());
		$options = array_merge( array_fill_keys($options_arr, null), $options );
		?>
	    <form id="<?php echo $plugin->prefix; ?>-admin-form" name="<?php echo $plugin->prefix; ?>-admin-form" method="post" action="<?php echo $_SERVER['REQUEST_URI']; ?>">
		<?php
		// Use nonce for verification
		wp_nonce_field(plugin_basename(__FILE__), $plugin->plugin_name.'::'.__FUNCTION__);
		?>
	    <div id="poststuff">

        <p><label for="<?php echo $plugin->prefix; ?>_active"><input type="checkbox" id="<?php echo $plugin->prefix; ?>_active" name="<?php echo $plugin->prefix; ?>_active" value="1"<?php checked($options['active'], 1); ?> /> <?php echo $plugin->plugin_title; ?> active?</label></p>

        <div class="postbox">
        	<div class="inside">
	            <h4><?php _e('Allowed Post Types'); ?></h4>
	            <p><span class="description"><?php _e('Calendars will only display the following post types. You can override this with the "post_types" variable in the shortcode.'); ?></span></p>
	            <?php
	            $post_types = array();
	            $arr = get_post_types(array('public' => true), 'objects');
	            foreach ($arr as $key => $value) {
	            	$post_types[$key] = $value->label;
	            }
	            $post_types = apply_filters('eventcalendar_post_types', $post_types);
	            $options['post_types'] = $plugin->make_array($options['post_types']);
	            foreach ($post_types as $key => $value) {
					echo '<label style="display: inline-block; width: 50%;"><input type="checkbox" name="'.$plugin->prefix.'_post_types[]" value="'.$key.'"';
					if (in_array($key, $options['post_types'])) {
						checked($key, $key);
					}
					echo '> '.$value.'</label>';
	            }
	            ?>
        	</div>
        </div>

        <div class="postbox">
        	<div class="inside">
	            <h4><?php _e('Automatic Calendar Inclusion'); ?></h4>
	            <p><span class="description"><?php _e('If both of these are checked the modified date will take precedence. You can override these with the "include_post_date" and "include_post_modified" variables in the shortcode.'); ?></span></p>

		        <p><label for="<?php echo $plugin->prefix; ?>_include_post_date"><input type="checkbox" id="<?php echo $plugin->prefix; ?>_include_post_date" name="<?php echo $plugin->prefix; ?>_include_post_date" value="1"<?php checked($options['include_post_date'], 1); ?> /> <?php _e('Automatically include posts on the date they were created?'); ?></label></p>

		        <p><label for="<?php echo $plugin->prefix; ?>_include_post_modified"><input type="checkbox" id="<?php echo $plugin->prefix; ?>_include_post_modified" name="<?php echo $plugin->prefix; ?>_include_post_modified" value="1"<?php checked($options['include_post_modified'], 1); ?> /> <?php _e('Automatically include posts on the date they were modified?'); ?></label></p>
			</div>
        </div>

        <div class="postbox">
        	<div class="inside">
	            <h4>Google Maps Details</h4>
	            <p><span class="description"><?php _e('These details are available from Google.'); ?></span></p>

	            <p><label for="<?php echo $plugin->prefix; ?>_googlemaps_api_key" style="display: inline-block; width: 20em; max-width: 25%;"><?php _e('Server API Key'); ?></label>
	            <input type="text" name="<?php echo $plugin->prefix; ?>_googlemaps_api_key" id="<?php echo $plugin->prefix; ?>_googlemaps_api_key" style="width: 50%;" value="<?php echo esc_attr($options['googlemaps_api_key']); ?>" /></p>
        	</div>
        </div>

        <div class="postbox">
        	<div class="inside">
	            <h4>Data Import</h4>

		        <p><label for="<?php echo $plugin->prefix; ?>_eventpost_import"><input type="checkbox" id="<?php echo $plugin->prefix; ?>_eventpost_import" name="<?php echo $plugin->prefix; ?>_eventpost_import" value="1" /> <?php _e('Import existing postmeta data from the Event Post plugin?'); ?></label></p>
		        <p><label for="<?php echo $plugin->prefix; ?>_eventpost_delete"><input type="checkbox" id="<?php echo $plugin->prefix; ?>_eventpost_delete" name="<?php echo $plugin->prefix; ?>_eventpost_delete" value="1" /> <?php _e('Delete existing postmeta data from the Event Post plugin?'); ?></label></p>
        	</div>
        </div>

        <p class="submit">
            <input type="submit" value="Update" id="publish" class="button button-primary button-large" name="save">
        </p>

        </div><!-- poststuff -->
    	</form>

		</div><!-- wrap -->
		<?php
	}

	public function add_meta_boxes($post_type) {
		$post_types = $this->get_option('post_types', array());
		if (!in_array($post_type, $post_types)) {
			return;
		}
		add_meta_box(
			$this->prefix,
			$this->plugin_title,
			array($this, 'postmeta'),
			$post_type
		);
	}
	public function add_meta_boxes_frontend() {
		global $post;
		$post_types = $this->get_option('post_types', array());
		// checking bbpress because $post is unreliable
		if (current_filter() == 'bbp_theme_after_forum_form_content' && !in_array('forum', $post_types)) {
			return;
		}
		elseif (current_filter() == 'bbp_theme_after_topic_form_content' && !in_array('topic', $post_types)) {
			return;
		}
		elseif (current_filter() == 'bbp_theme_after_reply_form_content' && !in_array('reply', $post_types)) {
			return;
		}
		elseif (!in_array($post->post_type, $post_types)) {
			return;
		}
		$str = '<p><button class="'.$this->prefix.'-toggle">Toggle '.$this->plugin_title.' options</button></p>';
		$str = apply_filters('eventcalendar_toggle', $str);
		echo $str;
		$this->postmeta();
	}
	public function postmeta() { // may be used in admin or frontend
		global $post;

		$postmeta_arr = $this->get_postmeta_array();
		$postmeta = get_post_meta($post->ID, $this->prefix, true);
		$postmeta = array_merge( array_fill_keys($postmeta_arr, null), $this->make_array($postmeta) );

		// Use nonce for verification
		wp_nonce_field(plugin_basename(__FILE__), $this->plugin_name.'::'.__FUNCTION__);

		// datetimepicker
		$js_handle_datetimepicker = $this->enqueue_script($this->prefix.'-datetimepicker', plugins_url('/assets/js/datetimepicker/jquery.datetimepicker.full.min.js', __FILE__), array('jquery'));
		$this->enqueue_style($this->prefix.'-datetimepicker', plugins_url('/assets/js/datetimepicker/jquery.datetimepicker.min.css', __FILE__));

		// js + css
		wp_enqueue_script($this->prefix.'-postmeta', plugins_url('/assets/js/postmeta.js', __FILE__), array($js_handle_datetimepicker), null, true);
        wp_localize_script($this->prefix.'-postmeta', 'postmeta', array(
            'prefix' => $this->prefix,
            'ajaxurl' => admin_url().'admin-ajax.php'
        ));
		wp_enqueue_style($this->prefix.'-postmeta', plugins_url('/assets/css/postmeta.css', __FILE__), array(), null);
		?>
<div class="<?php echo $this->prefix; ?>">
	<div class="<?php echo $this->prefix; ?>-fields <?php echo $this->prefix; ?>-date">
		<h4><?php _e('Event Date'); ?></h4>

		<p><label for="<?php echo $this->prefix; ?>_date_start"><?php _e('Start:'); ?></label> <input type="text" name="<?php echo $this->prefix; ?>_date_start" id="<?php echo $this->prefix; ?>_date_start" value="<?php echo esc_attr($postmeta['date_start']); ?>" /></p>

		<p><label for="<?php echo $this->prefix; ?>_date_end"><?php _e('End:'); ?></label> <input type="text" name="<?php echo $this->prefix; ?>_date_end" id="<?php echo $this->prefix; ?>_date_end" value="<?php echo esc_attr($postmeta['date_end']); ?>" /></p>
	</div>
	<div class="<?php echo $this->prefix; ?>-fields <?php echo $this->prefix; ?>-location">
		<h4><?php _e('Event Location'); ?></h4>

		<p><input type="text" name="<?php echo $this->prefix; ?>_geo_search" id="<?php echo $this->prefix; ?>_geo_search" value="" placeholder="Search for GPS coordinates" /> <input id="<?php echo $this->prefix; ?>_geo_search_button" class="button" value="Go" type="button" /></p>
		<div id="<?php echo $this->prefix; ?>_geo_search_result"></div>

		<p><label for="<?php echo $this->prefix; ?>_geo_address"><?php _e('Address:'); ?></label> <textarea name="<?php echo $this->prefix; ?>_geo_address" id="<?php echo $this->prefix; ?>_geo_address" rows="2"><?php echo $postmeta['geo_address']; ?></textarea></p>

		<p><label for="<?php echo $this->prefix; ?>_geo_latitude"><?php _e('Latitude:'); ?></label> <input type="text" name="<?php echo $this->prefix; ?>_geo_latitude" id="<?php echo $this->prefix; ?>_geo_latitude" value="<?php echo esc_attr($postmeta['geo_latitude']); ?>" /></p>

		<p><label for="<?php echo $this->prefix; ?>_geo_longitude"><?php _e('Longitude:'); ?></label> <input type="text" name="<?php echo $this->prefix; ?>_geo_longitude" id="<?php echo $this->prefix; ?>_geo_longitude" value="<?php echo esc_attr($postmeta['geo_longitude']); ?>" /></p>
	</div>
</div>
		<?php
	}
	public function save_post($post_ID, $post, $update) {
		// verify if this is an auto save routine. 
		// If it is our form has not been submitted, so we dont want to do anything
		if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
			return;
		}
		// verify this came from the our screen and with proper authorization
		// because save_post can be triggered at other times
		if (!isset($_POST[$this->plugin_name.'::postmeta'])) {
			return;
		}
		if (!wp_verify_nonce($_POST[$this->plugin_name.'::postmeta'], plugin_basename(__FILE__))) {
			return;
		}		
		// get values
		$postmeta_arr = $this->get_postmeta_array();
		$postmeta = array();
		foreach ($postmeta_arr as $value) {
			$name = $this->prefix.'_'.$value;
			if (!isset($_POST[$name])) {
				continue;
			}
			if (empty($_POST[$name])) {
				continue;
			}
			$postmeta[$value] = $_POST[$name];
		}
		// save it
		$maybe_revision = wp_is_post_revision($post_ID);
		if ($maybe_revision !== false) {
			$post_ID = $maybe_revision;
		}
		if (!empty($postmeta)) {
			update_post_meta($post_ID, $this->prefix, $postmeta);
		}
		else {
			 delete_post_meta($post_ID, $this->prefix);
		}
	}

	/* shortcode */

	public function shortcode($atts = array(), $content = '', $shortcode = '') {
 		$str = '';
		if (!is_main_query()) {
			return $str;
		}
		if (!in_the_loop()) {
			return $str;
		}
		if (!is_singular()) {
			return $str;
		}
		// $atts - vars that determine list of events (options)
		$options_arr = $this->get_options_array();
		$options = $this->get_option(null, array());
		$default_atts = array_merge( array_fill_keys($options_arr, null), $options );
		unset($default_atts['active']);
		if (is_multisite()) {
			$default_atts['sites'] = get_current_blog_id();
		}
		elseif (isset($atts['sites'])) {
			unset($atts['sites']);
		}
		// resolve user input
		$atts = $this->make_array($atts);
		if (!empty($atts)) {
			$remove_quotes = function($str) {
				return trim($str, "'".'"');
			};
			$atts = array_map($remove_quotes, $atts);
			$default_atts = shortcode_atts($default_atts, $atts, $this->shortcode); // removes keys not found in defaults
			$default_atts['post_types'] = $this->make_array($default_atts['post_types']);
			$default_atts['include_post_date'] = $this->true($default_atts['include_post_date']);
			$default_atts['include_post_modified'] = $this->true($default_atts['include_post_modified']);
		}
		$atts = $default_atts;

		// fullcalendar
		// css
		$css_handle_fullcalendar = $this->enqueue_style($this->prefix.'-fullcalendar', plugins_url('/assets/js/fullcalendar/fullcalendar.min.css', __FILE__));
		$css_handle_fullcalendar_print = $this->enqueue_style($this->prefix.'-fullcalendar-print', plugins_url('/assets/js/fullcalendar/fullcalendar.print.min.css', __FILE__), array($css_handle_fullcalendar), null, 'print');
		// js
		$js_handle_moment = $this->enqueue_script($this->prefix.'-moment', plugins_url('/assets/js/fullcalendar/lib/moment.min.js', __FILE__));
		$js_handle_jquery = 'jquery';
		if (!wp_script_is('jquery', 'registered')) {
			$js_handle_jquery = $this->enqueue_script('jquery', plugins_url('/assets/js/fullcalendar/lib/jquery.min.js', __FILE__));
		}
		$js_handle_fullcalendar = $this->enqueue_script($this->prefix.'-fullcalendar', plugins_url('/assets/js/fullcalendar/fullcalendar.min.js', __FILE__), array($js_handle_jquery, $js_handle_moment));
		// qtip
		// css
		$css_handle_qtip = $this->enqueue_style($this->prefix.'-qtip', plugins_url('/assets/js/qtip/jquery.qtip.min.css', __FILE__), array(), null, 'screen');
		// js
		$js_handle_imagesloaded = $this->enqueue_script($this->prefix.'-imagesloaded', plugins_url('/assets/js/qtip/imagesloaded.pkgd.min.js', __FILE__), array($js_handle_jquery));
		$this->enqueue_script($this->prefix.'-qtip', plugins_url('/assets/js/qtip/jquery.qtip.min.js', __FILE__), array($js_handle_imagesloaded));

		// $args - list of vars to send to fullcalendar https://fullcalendar.io/docs/
		$args = array(
			'dayPopoverFormat' => 'dddd D MMMM Y',
			'firstDay' => get_option('start_of_week', 1),
			'listDayAltFormat' => 'D MMMM Y',
			'timeFormat' => 'HH:mm',
		);
		$args = apply_filters('eventcalendar_fullcalendar_args', $args);

		// $qtip - list of vars to send to qtip http://qtip2.com/options
		$qtip = array(
			'content' => array(
				'text' => 'Loading...',
				'button' => 'Close'
			),
			'hide' => array(
				'event' => 'click unfocus mouseleave',
				'fixed' => true,
				'delay' => 500
			),
			'position' => array(
				'my' => 'top left',
				'at' => 'bottom center',
			),
			'style' => array(
				'classes' => 'qtip-blue qtip-shadow'
			),
		);
		$qtip = apply_filters('eventcalendar_qtip_args', $qtip);

		// plugin js
		wp_enqueue_script($this->prefix.'-fullcalendar-init', plugins_url('/assets/js/fullcalendar/fullcalendar-init.js', __FILE__), array($js_handle_fullcalendar), null, true);
        wp_localize_script($this->prefix.'-fullcalendar-init', 'fullcalendar', array(
            'prefix' => $this->prefix,
            'ajaxurl' => admin_url().'admin-ajax.php',
            'data' => array_merge(array('action' => $this->prefix.'_fullcalendar'), array_filter($atts)),
            'args' => $args,
            'qtip' => $qtip
        ));

        $str = '<div class="'.$this->prefix.'-fullcalendar"></div>';
		return apply_filters('eventcalendar_shortcode', $str);
	}

	/* ajax */

    public function ajax_fullcalendar() {
	    if (!isset($_REQUEST['post_types'])) {
	    	exit;
	    }
	    if (empty($_REQUEST['post_types'])) {
	    	exit;
	    }
	    // items function
		$get_items = function($default_args = array()) {
			$items = array();
			$args = array(
				'post_type' => $_REQUEST['post_types'],
				'post_status' => array('publish','inherit'),
				'posts_per_page' => -1,
				'no_found_rows' => true,
				'nopaging' => true,
				'ignore_sticky_posts' => true,
				'orderby' => 'modified',
				'suppress_filters' => false,
			);
			$has_includes = true;
			if (!isset($_REQUEST['include_post_date']) && !isset($_REQUEST['include_post_modified'])) {
				$args['meta_query'] = array(
					array(
						'key' => $this->prefix,
						'compare' => 'EXISTS',
					)
				);
				$has_includes = false;
			}
			$posts = get_posts($args);
			if (empty($posts)) {
				return $items;
			}
			foreach ($posts as $post) {
	    		$item = array(
	    			'title' => get_the_title($post),
	    			'url' => esc_url(get_permalink($post)),
	    			'post_id' => $post->ID,
	    		);
	    		$postmeta = $this->post_has_event_data($post->ID);
	    		if (!$postmeta && !$has_includes) {
	    			continue;
	    		}
	    		elseif ($postmeta) {
	    			$item['start'] = date('Y-m-d\TH:i:s', strtotime($postmeta['date_start']));
	    			$no_end = false;
	    			if (!isset($postmeta['date_end'])) {
	    				$no_end = true;
	    			}
	    			elseif (empty($postmeta['date_end'])) {
	    				$no_end = true;
	    			}
	    			elseif (strtotime($postmeta['date_end']) < strtotime($postmeta['date_start'])) {
	    				$no_end = true;
	    			}
	    			if (!$no_end) {
	    				$item['end'] = date('Y-m-d\TH:i:s', strtotime($postmeta['date_end']));
	    				// find all day events
	    				if (!preg_match("/[0-9]{2}:[0-9]{2}/i", $postmeta['date_start']) || strpos($postmeta['date_start'], '00:00') !== false) {
	    					if ( (strtotime($postmeta['date_end']) - strtotime($postmeta['date_start'])) > DAY_IN_SECONDS) {
			    				$item['allDay'] = true;
			    			}
	    				}
	    			}
	    			$item['className'] = $this->prefix.'-postmeta';
	    		}
	    		elseif (isset($_REQUEST['include_post_modified'])) {
	    			$item['start'] = $post->post_modified;
	    			$item['className'] = $this->prefix.'-modified';
	    		}
	    		elseif (isset($_REQUEST['include_post_date'])) {
	    			$item['start'] = $post->post_date;
	    			$item['className'] = $this->prefix.'-date';
	    		}
	    		$items[] = array_merge($default_args, $item);
			}
			return $items;
		};
		// multisite or normal
		$items = array();
		if (is_multisite() && isset($_REQUEST['sites'])) {
			$current_blog_id = get_current_blog_id();
			if ($_REQUEST['sites'] == 'all') {
				$sites = get_sites();
			}
			else {
				$sites = get_sites(array(
					'site__in' => $this->make_array($_REQUEST['sites'])
				));
			}
			foreach ($sites as $key => $value) {
				switch_to_blog($value->blog_id);
				$blog_items = $get_items( array('blog_id' => $value->blog_id) );
				if (!empty($blog_items)) {
					$items = array_merge($items, $blog_items);
				}
			}
			switch_to_blog($current_blog_id);
		}
		else {
			$items = $get_items( array('blog_id' => get_current_blog_id()) );
		}
	    echo json_encode($items);
        exit;
    }

    public function ajax_qtip() {
	    if (!isset($_REQUEST['post_id'])) {
	    	exit;
	    }
	    if (empty($_REQUEST['post_id'])) {
	    	exit;
	    }
	    $post_id = absint($_REQUEST['post_id']);
	    $blog_id = absint($_REQUEST['blog_id']);

	    $switched = false;
	    if (is_multisite() && $blog_id > 0) {
	    	if (get_current_blog_id() != $blog_id) {
				$switched = switch_to_blog($blog_id);
			}
	    }

	    $post = get_post($post_id);

	    $str = '<h2><a href="'.esc_url(get_permalink($post)).'">'.get_the_title($post).'</a></h2>';

		if (!empty($post->post_excerpt)) {
			$excerpt = $post->post_excerpt;
			$excerpt = $this->strip_all_shortcodes($excerpt);
			if (function_exists('get_the_excerpt_filtered')) {
				$excerpt = get_the_excerpt_filtered($post);
			}
		}
		else {
			$excerpt = $post->post_content;
			$excerpt = $this->strip_all_shortcodes($excerpt);
			if (function_exists('get_the_content_filtered')) {
				$excerpt = get_the_content_filtered($excerpt);
			}
		}
		$maxchars = 250;
		if (function_exists('get_excerpt')) {
			$excerpt = get_excerpt($excerpt, $maxchars, array('trim_urls' => false, 'plaintext' => false, 'single_line' => false, 'allowable_tags' => array('p', 'br', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'strong', 'b', 'em', 'i', 'img', 'ul', 'li', 'ol', 'blockquote', 'a') ));
		}
		else {
			$excerpt = wp_strip_all_tags($excerpt, false);
			if (strlen($excerpt) > $maxchars) {
				$excerpt = substr($excerpt, 0, $maxchars).wp_trim_words(substr($excerpt, $maxchars), 1);
			}
			$excerpt = '<p>'.nl2br($excerpt).'</p>';
			$excerpt = force_balance_tags($excerpt);
		}
		$str .= $excerpt;

		$str = apply_filters('eventcalendar_qtip', $str, $post_id, $post);

	    if ($switched) {
			restore_current_blog();
	    }
	    echo $str;
	    exit;
	}

    public function ajax_geo_search() {
	    if (!isset($_REQUEST['q'])) {
	    	exit;
	    }
	    if (empty($_REQUEST['q'])) {
	    	exit;
	    }
        header('Content-Type: application/json');
        $q = $_REQUEST['q'];
        $transient_name = $this->prefix.'_geo_'.$q;

        // look for old query
        $val = $this->get_transient($transient_name);
        if ($val !== false && !empty($val)) {
	        echo $val;
	        exit;
        }

        // make a new query
        $url = set_url_scheme('http://nominatim.openstreetmap.org').'/search?q='.urlencode($q).'&format=json&limit=5&accept-language='.$this->get_language();
        $val = file_get_contents($url);
        if ($val === false) {
        	$val = json_encode('There was a problem contacting openstreetmap.org');
        }
        else {
			$this->set_transient($transient_name, $val, 30 * DAY_IN_SECONDS);
        }
        echo $val;
        exit;
    }

	/* actions + filters */

	public function the_content($str = '') {
		if (current_filter() == 'the_content' && empty($str)) {
			return $str;
		}
		if (!is_singular()) {
			return $str;
		}
		if (is_404()) {
			return $str;
		}
		if (is_search()) {
			return $str;
		}
		if (is_home()) {
			return $str;
		}
		if (!is_main_query()) {
			return $str;
		}
		if (current_filter() == 'the_content' && !in_the_loop()) {
			return $str;
		}
		$postmeta = $this->post_has_event_data(get_the_ID());
		if ($postmeta === false) {
			return $str;
		}
		$post_types = $this->get_option('post_types', array());
		// checking bbpress because $post is unreliable
		if (current_filter() == 'bbp_template_after_single_forum' && !in_array('forum', $post_types)) {
			return;
		}
		elseif (current_filter() == 'bbp_template_after_single_topic' && !in_array('topic', $post_types)) {
			return;
		}
		elseif (current_filter() == 'bbp_template_after_single_reply' && !in_array('reply', $post_types)) {
			return;
		}

		// css
		wp_enqueue_style($this->prefix.'-event', plugins_url('/assets/css/event.css', __FILE__), array(), null);

		$date_format = get_option('date_format', 'j F Y');
		$time_format = get_option('time_format', 'H:i');

		// start html
		$res = '<div class="'.$this->prefix.'-event" itemscope itemtype="'.set_url_scheme('http://schema.org/Event').'">
		<div class="'.$this->prefix.'-fields '.$this->prefix.'-date">
		<h4 class="'.$this->prefix.'-event-title">'.__('Event Date').'</h4>';

		// date_start
		$res .= '<p><label for="'.$this->prefix.'_date_start">'.__('Start:').'</label><span class="'.$this->prefix.'_date_start" itemprop="startDate" content="'.date('Y-m-d\TH:i', strtotime($postmeta['date_start'])).'">';
		if (strpos($postmeta['date_start'], ' 00:00') === false) {
			$res .= date($date_format.' - '.$time_format, strtotime($postmeta['date_start']));
		}
		else {
			$res .= date($date_format, strtotime($postmeta['date_start']));
		}
		$res .= '</span></p>';

		// date_end
		$no_end = false;
		if (!isset($postmeta['date_end'])) {
			$no_end = true;
		}
		elseif (empty($postmeta['date_end'])) {
			$no_end = true;
		}
		elseif (strtotime($postmeta['date_end']) < strtotime($postmeta['date_start'])) {
			$no_end = true;
		}
		if (!$no_end) {
			$res .= '<p><label for="'.$this->prefix.'_date_end">'.__('End:').'</label><span class="'.$this->prefix.'_date_end" itemprop="endDate" content="'.date('Y-m-d\TH:i', strtotime($postmeta['date_end'])).'">';
			if (strpos($postmeta['date_end'], ' 00:00') === false) {
				$res .= date($date_format.' - '.$time_format, strtotime($postmeta['date_end']));
			}
			else {
				$res .= date($date_format, strtotime($postmeta['date_end']));
			}
			$res .= '</span></p>';
		}
		$res .= '</div>'; // close date

		$has_map = false;
		if ($this->post_has_event_location($postmeta)) {
			$res .= '<div class="'.$this->prefix.'-fields '.$this->prefix.'-location">
			<h4 class="'.$this->prefix.'-event-title">'.__('Event Location').'</h4>';

			// geo_address
			if (isset($postmeta['geo_address']) && !empty($postmeta['geo_address'])) {
				$res .= '<p><label for="'.$this->prefix.'_geo_address">'.__('Address:').'</label><span class="'.$this->prefix.'_geo_address" itemprop="location" itemscope itemtype="'.set_url_scheme('http://schema.org/Place').'">'.nl2br($postmeta['geo_address']).'</span></p>';
			}

			// map
			if (isset($postmeta['geo_latitude']) && isset($postmeta['geo_longitude']) && !empty($postmeta['geo_latitude']) && !empty($postmeta['geo_longitude']) && $this->get_option('googlemaps_api_key', false)) {
				$has_map = true;

				// plugin js
				wp_enqueue_script($this->prefix.'-event', plugins_url('/assets/js/event.js', __FILE__), array('jquery'), null, true);
		        wp_localize_script($this->prefix.'-event', 'event', array(
		            'prefix' => $this->prefix,
		        ));
				// google js
				$args = array(
					'key' => $this->get_option('googlemaps_api_key', ''),
					'language' => $this->get_language(),
					'callback' => $this->prefix.'_initialize',
				);
				$script = add_query_arg($args, set_url_scheme('http://maps.googleapis.com').'/maps/api/js');
				$js_handle_googlemaps = $this->enqueue_script($this->prefix.'-googlemaps', $script, array($this->prefix.'-event'));

				$res .= '<p><label for="'.$this->prefix.'-map">'.__('Map:').'</label><span><button class="'.$this->prefix.'-map-toggle" data-latitude="'.$postmeta['geo_latitude'].'" data-longitude="'.$postmeta['geo_longitude'].'" data-id="'.get_the_ID().'">'.__('Toggle Google Map').'</button></span></p>';
			}

			$res .= '</div>'; // close location
		}
		if ($has_map) {
			$res .= '<div class="'.$this->prefix.'-map" id="'.$this->prefix.'-map-'.get_the_ID().'"></div>';
		}

		$res .= '</div>'; // close event

		$res = apply_filters('eventcalendar_the_content', $res, $postmeta, $this);
		if (current_filter() == 'the_content') {
			return $str.$res;
		}
		else {
			echo $str.$res;
		}
	}

    /* functions */

	private function true($value) {
		if (is_bool($value)) {
			return $value;
		}
		elseif (empty($value)) {
			return false;
		}
		elseif (is_int($value)) {
			if ($value == 1) {
				return true;
			}
			elseif ($value == 0) {
				return false;
			}
			return $value;
		}
		elseif (is_string($value)) {
			if ($value == '1' || $value == 'true') {
				return true;
			}
			elseif ($value == '0' || $value == 'false') {
				return false;
			}
			return $value;
		}
		return false;
	}

	private function get_option($key = '', $default = array()) {
		if (!isset($this->option)) {
			if (is_multisite()) {
				$option = get_site_option($this->prefix, array());
			}
			else {
				$option = get_option($this->prefix, array());
			}
			$this->option = $option;
		}
		if (!empty($key)) {
			if (array_key_exists($key, $this->option)) {
				return $this->option[$key];
			}
			return $default;
		}
		return $this->option;
	}
	private function update_option($option) {
		if (is_multisite()) {
			$bool = update_site_option($this->prefix, $option);
		}
		else {
			$bool = update_option($this->prefix, $option);
		}
		if ($bool !== false) {
			$this->option = $option;
		}
		return $bool;
	}
	private function delete_option() {
		if (is_multisite()) {
			$bool = delete_site_option($this->prefix);
		}
		else {
			$bool = delete_option($this->prefix);
		}
		if ($bool !== false && isset($this->option)) {
			unset($this->option);
		}
		return $bool;
	}
	private function get_transient($transient) {
		if (is_multisite()) {
			$value = get_site_transient($transient);
		}
		else {
			$value = get_transient($transient);
		}
		return $value;
	}
	private function set_transient($transient, $value, $expiration = 0) {
		if (is_string($expiration)) {
			$expiration = strtotime('+'.$expiration) - time();
			if (!$expiration || $expiration < 0) {
				$expiration = 0;
			}
		}
		if (is_multisite()) {
			$bool = set_site_transient($transient, $value, $expiration);
		}
		else {
			$bool = set_transient($transient, $value, $expiration);
		}
		return $bool;
	}
	private function delete_transient($transient) {
		if (is_multisite()) {
			$bool = delete_site_transient($transient);
		}
		else {
			$bool = delete_transient($transient);
		}
		return $bool;
	}

    private function get_options_array() {
		return array(
			'active',
			'post_types',
			'include_post_date',
			'include_post_modified',
			'googlemaps_api_key',
		);
    }
    private function get_postmeta_array() {
		return array(
			'date_start',
			'date_end',
			'geo_address',
			'geo_latitude',
			'geo_longitude',
		);
    }

    private function get_language() {
        $language = get_bloginfo('language');
        if (strpos($language, '-') !== false) {
            $language = strtolower(substr($language, 0, 2));
        }
        return $language;
	}

	private function post_has_event_data($post_ID) {
		$postmeta = get_post_meta($post_ID, $this->prefix, true);
		if (empty($postmeta)) {
			return false;
		}
		// required fields
		$postmeta_arr = array(
			'date_start',
		);
		foreach ($postmeta_arr as $value) {
			if (isset($postmeta[$value])) {
				if (!empty($postmeta[$value])) {
					return $postmeta;
				}
			}
		}
		return false;
	}
	private function post_has_event_location($postmeta) {
		if (isset($postmeta['geo_address'])) {
			if (!empty($postmeta['geo_address'])) {
				return true;
			}
		}
		if (isset($postmeta['geo_latitude']) && isset($postmeta['geo_longitude'])) {
			if (!empty($postmeta['geo_latitude']) && !empty($postmeta['geo_longitude'])) {
				return true;
			}
		}
		return false;
	}

	public function enqueue_style($handle, $src = '', $deps = array(), $ver = null, $media = 'all') {
		global $wp_styles;
		// update registered array if different src or deps
		if (wp_style_is($handle, 'enqueued') || wp_style_is($handle, 'registered')) {
			if (isset($wp_styles->registered[$handle])) {
				if ($wp_styles->registered[$handle]->src !== $src) {
					$wp_styles->registered[$handle]->src = $src;
				}
				if ($wp_styles->registered[$handle]->deps !== $deps) {
					$wp_styles->registered[$handle]->deps = $deps;
				}
			}
			if (!wp_style_is($handle, 'enqueued')) {
				wp_enqueue_style($handle, $src, $deps, $ver, $media);
			}
			return $handle;
		}
		// check if same filename is enqueued
		$queue = $this->make_array($wp_styles->queue);
		$done = $this->make_array($wp_styles->done);
		$check = array_merge($queue, $done);
		$check = array_unique($check);
		$basename = basename($src);
		foreach ($check as $value) {
			if (isset($wp_styles->registered[$value])) {
				if (basename($wp_styles->registered[$value]->src) == $basename) {
					return $value;
				}
			}
		}
		wp_enqueue_style($handle, $src, $deps, $ver, $media);
		return $handle;
	}
	private function enqueue_script($handle, $src = '', $deps = array(), $ver = null, $in_footer = true) { // default: $in_footer = false
		global $wp_scripts;
		// update registered array if different src or deps
		if (wp_script_is($handle, 'enqueued') || wp_script_is($handle, 'registered')) {
			if (isset($wp_scripts->registered[$handle])) {
				if ($wp_scripts->registered[$handle]->src !== $src) {
					$wp_scripts->registered[$handle]->src = $src;
				}
				if ($wp_scripts->registered[$handle]->deps !== $deps) {
					$wp_scripts->registered[$handle]->deps = $deps;
				}
			}
			if (!wp_script_is($handle, 'enqueued')) {
				wp_enqueue_script($handle, $src, $deps, $ver, $in_footer);
				if ($in_footer && !in_array($handle, $wp_scripts->in_footer)) {
					$wp_scripts->in_footer[] = $handle;
				}
			}
			return $handle;
		}
		// check if same filename is enqueued
		$queue = $this->make_array($wp_scripts->queue);
		$done = $this->make_array($wp_scripts->done);
		$check = array_merge($queue, $done);
		$check = array_unique($check);
		$basename = basename($src);
		foreach ($check as $value) {
			if (isset($wp_scripts->registered[$value])) {
				if (basename($wp_scripts->registered[$value]->src) == $basename) {
					return $value;
				}
			}
		}
		wp_enqueue_script($handle, $src, $deps, $ver, $in_footer);
		return $handle;
	}

}
endif;
?>