<?php
/*
Available filters:
eventcalendar_shortcode
eventcalendar_fullcalendar_args
eventcalendar_qtip
eventcalendar_qtip_args
eventcalendar_posts_args
eventcalendar_posts
eventcalendar_the_content
eventcalendar_toggle
eventcalendar_post_has_event_data_required
*/

// Exit if accessed directly.
defined('ABSPATH') || exit;

if (!class_exists('Halftheory_Helper_Plugin')) {
	@include_once(dirname(__FILE__).'/class-halftheory-helper-plugin.php');
}

if (!class_exists('Event_Calendar') && class_exists('Halftheory_Helper_Plugin')) :
final class Event_Calendar extends Halftheory_Helper_Plugin {

	public static $plugin_basename;
	public static $prefix;
	public static $active = false;
	public static $postmeta_key;

	/* setup */

	public function init($plugin_basename = '', $prefix = '') {
		parent::init($plugin_basename, $prefix);
		self::$active = $this->get_option(static::$prefix, 'active', false);
		self::$postmeta_key = static::$prefix;
	}

	protected function setup_actions() {
		parent::setup_actions();

		// stop if not active
		if (empty(self::$active)) {
			return;
		}

		// admin postmeta
		if (!$this->is_front_end()) {
			add_action('add_meta_boxes', array($this,'add_meta_boxes'));
		}
		else {
			// bbpress
			add_action('bbp_theme_after_forum_form_content', array($this, 'add_meta_boxes_frontend'));
			add_action('bbp_theme_after_topic_form_content', array($this, 'add_meta_boxes_frontend'));
			add_action('bbp_theme_after_reply_form_content', array($this, 'add_meta_boxes_frontend'));
			// ajax
        	add_action('wp_ajax_'.static::$prefix.'_fullcalendar', array($this, 'ajax_fullcalendar'));
        	add_action('wp_ajax_nopriv_'.static::$prefix.'_fullcalendar', array($this, 'ajax_fullcalendar'));
        	add_action('wp_ajax_'.static::$prefix.'_qtip', array($this, 'ajax_qtip'));
        	add_action('wp_ajax_nopriv_'.static::$prefix.'_qtip', array($this, 'ajax_qtip'));
		}
		// only editors
		if (is_user_logged_in() && current_user_can('edit_posts')) {
			add_action('save_post', array($this,'save_post'), 10, 3);
        	add_action('wp_ajax_'.static::$prefix.'_geo_search', array($this, 'ajax_geo_search'));
        	add_action('wp_ajax_nopriv_'.static::$prefix.'_geo_search', array($this, 'ajax_geo_search'));
        }

		// filters
		$this->shortcode = 'event-calendar';
		if (!shortcode_exists($this->shortcode)) {
			add_shortcode($this->shortcode, array($this,'shortcode'));
		}
		if ($this->is_front_end()) {
			add_filter('the_content', array($this,'the_content'), 20);
			add_action('bbp_template_after_single_forum', array($this,'the_content'));
			add_action('bbp_template_after_single_topic', array($this,'the_content'));
			add_action('bbp_template_after_single_reply', array($this,'the_content'));
		}
	}

	/* functions-common */

	private function strip_all_shortcodes($str = '') {
		if (function_exists(__FUNCTION__)) {
			$func = __FUNCTION__;
			return $func($str);
		}
		$go = true;
		// inline scripts
		if (strpos($str, '<script') !== false) {
			$go = false;
		}
		// [] inside html tag
		if ($go && preg_match("/<[a-z]+ [^>\[\]]+\[[^>]+>/is", $str)) {
			$go = false;
		}
		if ($go) {
			$str = preg_replace("/\[[^\]]{5,}\]/is", "", $str); // more than 4 letters
		}
		return $str;
	}

	/* admin */

	public function menu_page() {
 		global $title;
		?>
		<div class="wrap">
			<h2><?php echo $title; ?></h2>
		<?php
 		$plugin = new static(static::$plugin_basename, static::$prefix, false);

	    // http://codex.wordpress.org/Geodata
		if ($plugin->save_menu_page()) {
        	$save = function() use ($plugin) {
				// get values
				$options_arr = $plugin->get_options_array();
				$options = array();
				foreach ($options_arr as $value) {
					$name = $plugin::$prefix.'_'.$value;
					if (!isset($_POST[$name])) {
						continue;
					}
					if ($this->empty_notzero($_POST[$name])) {
						continue;
					}
					$options[$value] = $_POST[$name];
				}
				// save it
	            $updated = '<div class="updated"><p><strong>'.esc_html__('Options saved.').'</strong></p></div>';
	            $error = '<div class="error"><p><strong>'.esc_html__('Error: There was a problem.').'</strong></p></div>';
				if (!empty($options)) {
		            if ($plugin->update_option($plugin::$prefix, $options)) {
		            	echo $updated;
		            }
		        	else {
		        		// where there changes?
		        		$options_old = $plugin->get_option($plugin::$prefix, null, array());
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
			if (isset($_POST[$plugin::$prefix.'_eventpost_import']) && !empty($_POST[$plugin::$prefix.'_eventpost_import'])) {
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
				if (!empty($posts) && !is_wp_error($posts)) {
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
						update_post_meta($post->ID, $plugin::$postmeta_key, $postmeta);
					}
	            	echo '<div class="updated"><p><strong>'.esc_html__('Imported data from the Event Post plugin.').'</strong></p></div>';
				}
				else {
	            	echo '<div class="error"><p><strong>'.esc_html__('No data to import from the Event Post plugin.').'</strong></p></div>';
				}
			}
			// delete postmeta
			if (isset($_POST[$plugin::$prefix.'_eventpost_delete']) && !empty($_POST[$plugin::$prefix.'_eventpost_delete'])) {
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
				if (!empty($posts) && !is_wp_error($posts)) {
					foreach ($posts as $post) {
						delete_post_meta($post->ID, 'event_begin');
						delete_post_meta($post->ID, 'event_end');
						delete_post_meta($post->ID, 'event_color');
						delete_post_meta($post->ID, 'geo_address');
						delete_post_meta($post->ID, 'geo_latitude');
						delete_post_meta($post->ID, 'geo_longitude');
					}
	            	echo '<div class="updated"><p><strong>'.esc_html__('Deleted data from the Event Post plugin.').'</strong></p></div>';
				}
				else {
	            	echo '<div class="error"><p><strong>'.esc_html__('No data to delete from the Event Post plugin.').'</strong></p></div>';
				}
			}

        } // save

		// show the form
		$options_arr = $plugin->get_options_array();
		$options = $plugin->get_option($plugin::$prefix, null, array());
		$options = array_merge( array_fill_keys($options_arr, null), $options );
		?>
	    <form id="<?php echo $plugin::$prefix; ?>-admin-form" name="<?php echo $plugin::$prefix; ?>-admin-form" method="post" action="<?php echo $_SERVER['REQUEST_URI']; ?>">
		<?php
		// Use nonce for verification
		wp_nonce_field($plugin::$plugin_basename, $plugin->plugin_name.'::'.__FUNCTION__);
		?>
	    <div id="poststuff">

        <p><label for="<?php echo $plugin::$prefix; ?>_active"><input type="checkbox" id="<?php echo $plugin::$prefix; ?>_active" name="<?php echo $plugin::$prefix; ?>_active" value="1"<?php checked($options['active'], 1); ?> /> <?php echo $plugin->plugin_title; ?> <?php _e('active?'); ?></label></p>

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
	            $options['post_types'] = $plugin->make_array($options['post_types']);
	            foreach ($post_types as $key => $value) {
					echo '<label style="display: inline-block; width: 50%;"><input type="checkbox" name="'.$plugin::$prefix.'_post_types[]" value="'.$key.'"';
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

		        <p><label for="<?php echo $plugin::$prefix; ?>_include_post_date"><input type="checkbox" id="<?php echo $plugin::$prefix; ?>_include_post_date" name="<?php echo $plugin::$prefix; ?>_include_post_date" value="1"<?php checked($options['include_post_date'], 1); ?> /> <?php _e('Automatically include posts on the date they were created?'); ?></label></p>

		        <p><label for="<?php echo $plugin::$prefix; ?>_include_post_modified"><input type="checkbox" id="<?php echo $plugin::$prefix; ?>_include_post_modified" name="<?php echo $plugin::$prefix; ?>_include_post_modified" value="1"<?php checked($options['include_post_modified'], 1); ?> /> <?php _e('Automatically include posts on the date they were modified?'); ?></label></p>
			</div>
        </div>

        <div class="postbox">
        	<div class="inside">
	            <h4><?php _e('Maps'); ?></h4>
				<?php
				$maps_providers = array(
					'openstreetmap' => 'OpenStreetMap',
					'google' => 'Google',
				);
				?>
	            <p><label for="<?php echo $plugin::$prefix; ?>_maps_provider" style="display: inline-block; width: 20em; max-width: 25%;"><?php _e('Maps Provider'); ?></label>
				<select id="<?php echo $plugin::$prefix; ?>_maps_provider" name="<?php echo $plugin::$prefix; ?>_maps_provider">
					<option value=""><?php _e('&mdash;&mdash;'); ?></option>
					<?php foreach ($maps_providers as $key => $value) : ?>
						<option value="<?php echo esc_attr($key); ?>"<?php selected($key, $options['maps_provider']); ?>><?php echo esc_html($value); ?></option>
					<?php endforeach; ?>
				</select></p>

	            <p><label for="<?php echo $plugin::$prefix; ?>_maps_google_api_key" style="display: inline-block; width: 20em; max-width: 25%;"><?php _e('Google API Key'); ?></label>
	            <input type="text" name="<?php echo $plugin::$prefix; ?>_maps_google_api_key" id="<?php echo $plugin::$prefix; ?>_maps_google_api_key" style="width: 50%;" value="<?php echo esc_attr($options['maps_google_api_key']); ?>" /><br />
	            <span class="description small" style="margin-left: 20em;"><?php _e('These details are available from Google.'); ?></span></p>

		        <p><label for="<?php echo $plugin::$prefix; ?>_maps_load_startup"><input type="checkbox" id="<?php echo $plugin::$prefix; ?>_maps_load_startup" name="<?php echo $plugin::$prefix; ?>_maps_load_startup" value="1"<?php checked($options['maps_load_startup'], 1); ?> /> <?php _e('Load maps at startup? Disabling this can reduce the number of API calls.'); ?></label></p>
        	</div>
        </div>

        <div class="postbox">
        	<div class="inside">
	            <h4><?php _e('Data Import'); ?></h4>
		        <p><label for="<?php echo $plugin::$prefix; ?>_eventpost_import"><input type="checkbox" id="<?php echo $plugin::$prefix; ?>_eventpost_import" name="<?php echo $plugin::$prefix; ?>_eventpost_import" value="1" /> <?php _e('Import existing postmeta data from the Event Post plugin?'); ?></label></p>
		        <p><label for="<?php echo $plugin::$prefix; ?>_eventpost_delete"><input type="checkbox" id="<?php echo $plugin::$prefix; ?>_eventpost_delete" name="<?php echo $plugin::$prefix; ?>_eventpost_delete" value="1" /> <?php _e('Delete existing postmeta data from the Event Post plugin?'); ?></label></p>
        	</div>
        </div>

        <?php submit_button(__('Update'), array('primary','large'), 'save'); ?>

        </div><!-- poststuff -->
    	</form>

		</div><!-- wrap -->
		<?php
	}

	public function add_meta_boxes($post_type) {
		$post_types = $this->get_option(static::$prefix, 'post_types', array());
		if (!in_array($post_type, $post_types)) {
			return;
		}
		add_meta_box(
			static::$prefix,
			$this->plugin_title,
			array($this, 'postmeta'),
			$post_type
		);
	}

	public function add_meta_boxes_frontend() {
		global $post;
		$post_types = $this->get_option(static::$prefix, 'post_types', array());
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
		$str = '<p><button class="'.static::$prefix.'-toggle">Toggle '.$this->plugin_title.' options</button></p>';
		$str = apply_filters('eventcalendar_toggle', $str);
		echo $str;
		$this->postmeta();
	}

	public function postmeta() { // may be used in admin or frontend
		global $post;

		$postmeta_arr = $this->get_postmeta_array();
		$postmeta = get_post_meta($post->ID, self::$postmeta_key, true);
		$postmeta = array_merge( array_fill_keys($postmeta_arr, null), $this->make_array($postmeta) );

		// Use nonce for verification
		wp_nonce_field(static::$plugin_basename, $this->plugin_name.'::'.__FUNCTION__);

		// datetimepicker
		$js_handle_datetimepicker = $this->enqueue_script(static::$prefix.'-datetimepicker', plugins_url('/assets/js/datetimepicker/jquery.datetimepicker.full.min.js', __FILE__), array('jquery'));
		$this->enqueue_style(static::$prefix.'-datetimepicker', plugins_url('/assets/js/datetimepicker/jquery.datetimepicker.min.css', __FILE__));

		// js + css
		wp_enqueue_script(static::$prefix.'-postmeta', plugins_url('/assets/js/postmeta.min.js', __FILE__), array($js_handle_datetimepicker), null, true);
        wp_localize_script(static::$prefix.'-postmeta', 'postmeta', array(
            'prefix' => static::$prefix,
            'ajaxurl' => admin_url().'admin-ajax.php'
        ));
		wp_enqueue_style(static::$prefix.'-postmeta', plugins_url('/assets/css/postmeta.css', __FILE__), array(), null);

		// html
		?>
<div class="<?php echo static::$prefix; ?>">
	<div class="<?php echo static::$prefix; ?>-fields <?php echo static::$prefix; ?>-date">
		<h4><?php _e('Date'); ?></h4>

		<p><label for="<?php echo static::$prefix; ?>_date_start"><?php _e('Start:'); ?></label> <input type="text" name="<?php echo static::$prefix; ?>_date_start" id="<?php echo static::$prefix; ?>_date_start" value="<?php echo esc_attr($postmeta['date_start']); ?>" /></p>

		<p><label for="<?php echo static::$prefix; ?>_date_end"><?php _e('End:'); ?></label> <input type="text" name="<?php echo static::$prefix; ?>_date_end" id="<?php echo static::$prefix; ?>_date_end" value="<?php echo esc_attr($postmeta['date_end']); ?>" /></p>
	</div>
	<div class="<?php echo static::$prefix; ?>-fields <?php echo static::$prefix; ?>-location">
		<h4><?php _e('Location'); ?></h4>

		<p><input type="text" name="<?php echo static::$prefix; ?>_geo_search" id="<?php echo static::$prefix; ?>_geo_search" value="" placeholder="<?php _e('Search for GPS coordinates'); ?>" /> <input id="<?php echo static::$prefix; ?>_geo_search_button" class="button" value="Go" type="button" /></p>
		<div id="<?php echo static::$prefix; ?>_geo_search_result"></div>

		<p><label for="<?php echo static::$prefix; ?>_geo_address"><?php _e('Address:'); ?></label> <textarea name="<?php echo static::$prefix; ?>_geo_address" id="<?php echo static::$prefix; ?>_geo_address" rows="2"><?php echo $postmeta['geo_address']; ?></textarea></p>

		<p><label for="<?php echo static::$prefix; ?>_geo_latitude"><?php _e('Latitude:'); ?></label> <input type="text" name="<?php echo static::$prefix; ?>_geo_latitude" id="<?php echo static::$prefix; ?>_geo_latitude" value="<?php echo esc_attr($postmeta['geo_latitude']); ?>" /></p>

		<p><label for="<?php echo static::$prefix; ?>_geo_longitude"><?php _e('Longitude:'); ?></label> <input type="text" name="<?php echo static::$prefix; ?>_geo_longitude" id="<?php echo static::$prefix; ?>_geo_longitude" value="<?php echo esc_attr($postmeta['geo_longitude']); ?>" /></p>
	</div>
</div>
		<?php
	}

	public function save_post($post_id, $post, $update) {
		// verify if this is an auto save routine. 
		// If it is our form has not been submitted, so we dont want to do anything
		if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
			return;
		}
    	if (empty($update)) {
    		return;
    	}
		// verify this came from the our screen and with proper authorization
		// because save_post can be triggered at other times
		if (isset($_POST)) {
			if (isset($_POST[$this->plugin_name.'::postmeta'])) {
				if (wp_verify_nonce($_POST[$this->plugin_name.'::postmeta'], static::$plugin_basename)) {
					// get values
					$postmeta_arr = $this->get_postmeta_array();
					$postmeta = array();
					foreach ($postmeta_arr as $value) {
						$name = static::$prefix.'_'.$value;
						if (!isset($_POST[$name])) {
							continue;
						}
						if ($this->empty_notzero($_POST[$name])) {
							continue;
						}
						$postmeta[$value] = $_POST[$name];
					}
					// save it
					$maybe_revision = wp_is_post_revision($post_id);
					if ($maybe_revision !== false) {
						$post_id = $maybe_revision;
					}
					if (!empty($postmeta)) {
						update_post_meta($post_id, self::$postmeta_key, $postmeta);
					}
					else {
						 delete_post_meta($post_id, self::$postmeta_key);
					}
				}
			}
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

		// options - list of keys
		$options_arr = $this->get_options_array();
		$key = array_search('active', $options_arr);
		if (!$this->empty_notzero($key)) {
			unset($options_arr[$key]);
		}
		if (is_multisite()) {
			$options_arr[] = 'sites';
		}

		// $atts - vars that determine list of events (options)
		$atts = $this->make_array($atts);
		// add options from db
		$atts = wp_parse_args($atts, $this->get_option(static::$prefix, null, array()));
		// removes keys not found in defaults
		$atts = shortcode_atts(array_fill_keys($options_arr, null), $atts, $this->shortcode);
		// resolve user input
		if (!empty($atts)) {
			$trim_quotes = function($str) use (&$trim_quotes) {
				if (is_string($str)) {
					$str = trim($str, " '".'"');
				}
				elseif (is_array($str)) {
					$str = array_map($trim_quotes, $str);
				}
				return $str;
			};
			$atts = array_map($trim_quotes, $atts);
			if (isset($atts['post_types'])) {
				$atts['post_types'] = $this->make_array($atts['post_types']);
			}
			if (isset($atts['sites'])) {
				$atts['sites'] = $this->make_array($atts['sites']);
			}
			if (isset($atts['include_post_date'])) {
				$atts['include_post_date'] = $this->is_true($atts['include_post_date']);
			}
			if (isset($atts['include_post_modified'])) {
				$atts['include_post_modified'] = $this->is_true($atts['include_post_modified']);
			}
			if (isset($atts['maps_load_startup'])) {
				$atts['maps_load_startup'] = $this->is_true($atts['maps_load_startup']);
			}
		}
		$atts = array_filter($atts);

		// fullcalendar
		// css
		$css_handle_fullcalendar = $this->enqueue_style(static::$prefix.'-fullcalendar', plugins_url('/assets/js/fullcalendar/fullcalendar.min.css', __FILE__));
		$css_handle_fullcalendar_print = $this->enqueue_style(static::$prefix.'-fullcalendar-print', plugins_url('/assets/js/fullcalendar/fullcalendar.print.min.css', __FILE__), array($css_handle_fullcalendar), null, 'print');
		// js
		$js_handle_moment = $this->enqueue_script(static::$prefix.'-moment', plugins_url('/assets/js/fullcalendar/lib/moment.min.js', __FILE__));
		$js_handle_jquery = 'jquery';
		if (!wp_script_is('jquery', 'registered')) {
			$js_handle_jquery = $this->enqueue_script('jquery', plugins_url('/assets/js/fullcalendar/lib/jquery.min.js', __FILE__));
		}
		$js_handle_fullcalendar = $this->enqueue_script(static::$prefix.'-fullcalendar', plugins_url('/assets/js/fullcalendar/fullcalendar.min.js', __FILE__), array($js_handle_jquery, $js_handle_moment));
		// qtip
		// css
		$css_handle_qtip = $this->enqueue_style(static::$prefix.'-qtip', plugins_url('/assets/js/qtip/jquery.qtip.min.css', __FILE__), array(), null, 'screen');
		// js
		$js_handle_imagesloaded = $this->enqueue_script(static::$prefix.'-imagesloaded', plugins_url('/assets/js/qtip/imagesloaded.pkgd.min.js', __FILE__), array($js_handle_jquery));
		$this->enqueue_script(static::$prefix.'-qtip', plugins_url('/assets/js/qtip/jquery.qtip.min.js', __FILE__), array($js_handle_imagesloaded));

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
				'text' => __('Loading...'),
				'button' => __('Close')
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
		wp_enqueue_script(static::$prefix.'-fullcalendar-init', plugins_url('/assets/js/fullcalendar/fullcalendar-init.min.js', __FILE__), array($js_handle_fullcalendar), null, true);
        wp_localize_script(static::$prefix.'-fullcalendar-init', 'fullcalendar', array(
            'prefix' => static::$prefix,
            'ajaxurl' => admin_url().'admin-ajax.php',
            'data' => array_merge(array('action' => static::$prefix.'_fullcalendar'), $atts),
            'args' => $args,
            'qtip' => $qtip
        ));

        $str = '<div class="'.static::$prefix.'-fullcalendar"></div>';
		return apply_filters('eventcalendar_shortcode', $str);
	}

	/* ajax */

    public function ajax_fullcalendar() {
	    if (!isset($_REQUEST['post_types'])) {
	    	wp_die();
	    }
	    if (empty($_REQUEST['post_types'])) {
	    	wp_die();
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
						'key' => self::$postmeta_key,
						'compare' => 'EXISTS',
					)
				);
				$has_includes = false;
			}
			$args = apply_filters('eventcalendar_posts_args', $args, $default_args);
			$posts = get_posts($args);
			if (empty($posts) || is_wp_error($posts)) {
				return $items;
			}
			$posts = apply_filters('eventcalendar_posts', $posts, $args, $default_args);
			foreach ($posts as $post) {
	    		$item = array(
	    			'title' => get_post_field('post_title', $post, 'display'),
	    			'url' => esc_url(get_permalink($post)),
	    			'post_id' => $post->ID,
	    		);
	    		$postmeta = self::post_has_event_data($post->ID);
	    		if (!$postmeta && !$has_includes) {
	    			continue;
	    		}
	    		elseif ($postmeta) {
	    			$item['start'] = date('Y-m-d\TH:i:s', strtotime($postmeta['date_start']));
	    			$has_end = true;
	    			if (!isset($postmeta['date_end'])) {
	    				$has_end = false;
	    			}
	    			elseif (empty($postmeta['date_end'])) {
	    				$has_end = false;
	    			}
	    			elseif (strtotime($postmeta['date_end']) < strtotime($postmeta['date_start'])) {
	    				$has_end = false;
	    			}
	    			if ($has_end) {
	    				$item['end'] = date('Y-m-d\TH:i:s', strtotime($postmeta['date_end']));
	    				// find all day events
	    				if (!preg_match("/[0-9]{2}:[0-9]{2}/i", $postmeta['date_start']) || strpos($postmeta['date_start'], '00:00') !== false) {
	    					if ( (strtotime($postmeta['date_end']) - strtotime($postmeta['date_start'])) > DAY_IN_SECONDS) {
			    				$item['allDay'] = true;
			    			}
	    				}
	    			}
	    			$item['className'] = static::$prefix.'-postmeta';
	    		}
	    		elseif (isset($_REQUEST['include_post_modified'])) {
	    			$item['start'] = $post->post_modified;
	    			$item['className'] = static::$prefix.'-modified';
	    		}
	    		elseif (isset($_REQUEST['include_post_date'])) {
	    			$item['start'] = $post->post_date;
	    			$item['className'] = static::$prefix.'-date';
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
	    wp_die();
    }

    public function ajax_qtip() {
	    if (!isset($_REQUEST['post_id'])) {
	    	wp_die();
	    }
	    if (empty($_REQUEST['post_id'])) {
	    	wp_die();
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
	    wp_die();
	}

    public function ajax_geo_search() {
	    if (!isset($_REQUEST['q'])) {
	    	wp_die();
	    }
	    if (empty($_REQUEST['q'])) {
	    	wp_die();
	    }
	    if (!headers_sent()) {
        	header('Content-Type: application/json');
        }
        $val = self::get_geo_search($_REQUEST['q']);
        if (empty($val)) {
        	$val = wp_json_encode('There was a problem contacting openstreetmap.org');
        }
        echo $val;
		wp_die();
    }

	/* actions */

	public function the_content($str = '') {
		if (current_filter() == 'the_content' && !in_the_loop()) {
			return $str;
		}
		if (!is_main_query() && !wp_doing_ajax()) {
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
		if (!is_singular()) {
			return $str;
		}
		$postmeta = self::post_has_event_data(get_the_ID());
		if ($postmeta === false) {
			return $str;
		}
		$post_types = $this->get_option(static::$prefix, 'post_types', array());
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
		self::enqueue_event_scripts('css');

		// start html
		$res = '<div class="'.static::$prefix.'-event" itemscope itemtype="'.set_url_scheme('http://schema.org/Event').'">';

		// date_start
		$has_start = true;
		if (!isset($postmeta['date_start'])) {
			$has_start = false;
		}
		elseif (empty($postmeta['date_start'])) {
			$has_start = false;
		}
		// date_end
		$has_end = true;
		if (!isset($postmeta['date_end'])) {
			$has_end = false;
		}
		elseif (empty($postmeta['date_end'])) {
			$has_end = false;
		}
		elseif (strtotime($postmeta['date_end']) < strtotime($postmeta['date_start'])) {
			$has_end = false;
		}

		if ($has_start || $has_end) {
			$date_format = $this->get_option('date_format', null, 'j F Y');
			$time_format = $this->get_option('time_format', null, 'H:i');

			$res .= '<div class="'.static::$prefix.'-fields '.static::$prefix.'-date">
			<h4 class="'.static::$prefix.'-event-title">'.__('Event Date').'</h4>';
			if ($has_start) {
				$res .= '<p><label for="'.static::$prefix.'_date_start">'.__('Start:').'</label><span class="'.static::$prefix.'_date_start" itemprop="startDate" content="'.date('Y-m-d\TH:i', strtotime($postmeta['date_start'])).'">';
				if (strpos($postmeta['date_start'], ' 00:00') === false) {
					$res .= date($date_format.' - '.$time_format, strtotime($postmeta['date_start']));
				}
				else {
					$res .= date($date_format, strtotime($postmeta['date_start']));
				}
				$res .= '</span></p>';
			}
			if ($has_end) {
				$res .= '<p><label for="'.static::$prefix.'_date_end">'.__('End:').'</label><span class="'.static::$prefix.'_date_end" itemprop="endDate" content="'.date('Y-m-d\TH:i', strtotime($postmeta['date_end'])).'">';
				if (strpos($postmeta['date_end'], ' 00:00') === false) {
					$res .= date($date_format.' - '.$time_format, strtotime($postmeta['date_end']));
				}
				else {
					$res .= date($date_format, strtotime($postmeta['date_end']));
				}
				$res .= '</span></p>';
			}
			$res .= '</div>'; // close date
		}

		$has_map = false;
		if ($this->post_has_event_location($postmeta)) {
			$res .= '<div class="'.static::$prefix.'-fields '.static::$prefix.'-location">
			<h4 class="'.static::$prefix.'-event-title">'.__('Event Location').'</h4>';

			// geo_address
			if (isset($postmeta['geo_address']) && !empty($postmeta['geo_address'])) {
				$res .= '<p><label for="'.static::$prefix.'_geo_address">'.__('Address:').'</label><span class="'.static::$prefix.'_geo_address" itemprop="location" itemscope itemtype="'.set_url_scheme('http://schema.org/Place').'">'.nl2br($postmeta['geo_address']).'</span></p>';
			}

			// map
			if (isset($postmeta['geo_latitude']) && isset($postmeta['geo_longitude']) && !empty($postmeta['geo_latitude']) && !empty($postmeta['geo_longitude']) && !empty($this->get_option(static::$prefix, 'maps_provider', null))) {
				$has_map = true;

				// js
				self::enqueue_event_scripts('js');

				$res .= '<p><label for="'.static::$prefix.'-map">'.__('Map:').'</label><span><button class="'.static::$prefix.'-map-toggle" data-latitude="'.$postmeta['geo_latitude'].'" data-longitude="'.$postmeta['geo_longitude'].'" data-id="'.get_the_ID().'">'.__('Toggle Map').'</button></span></p>';
			}
			$res .= '</div>'; // close location
		}
		if ($has_map) {
			$class = 'map-open';
			if (empty($this->get_option(static::$prefix, 'maps_load_startup', null))) {
				$class = 'map-close';
			}
			$res .= '<div class="'.static::$prefix.'-map '.$class.'" id="'.static::$prefix.'-map-'.get_the_ID().'" itemprop="geo" itemscope itemtype="'.set_url_scheme('http://schema.org/Map').'"></div>';
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

    private function get_options_array() {
		return array(
			'active',
			'post_types',
			'include_post_date',
			'include_post_modified',
			'maps_provider',
			'maps_google_api_key',
			'maps_load_startup',
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

	public static function post_has_event_data($post_ID) {
		$postmeta = get_post_meta($post_ID, self::$postmeta_key, true);
		if (empty($postmeta)) {
			return false;
		}
		// required fields
		$postmeta_arr = array(
			'date_start',
		);
        $postmeta_arr = apply_filters('eventcalendar_post_has_event_data_required', $postmeta_arr);
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

	public static function enqueue_event_scripts($style_or_script = 'all') {
 		$plugin = new static(static::$plugin_basename, static::$prefix, false);

		// css
		if ($style_or_script == 'all' || $style_or_script == 'css') {
			if (wp_style_is($plugin::$prefix.'-event', 'enqueued') || wp_style_is($plugin::$prefix.'-event', 'registered')) {
				return;
			}
			$plugin->enqueue_style($plugin::$prefix.'-event', plugins_url('/assets/css/event.css', __FILE__), array(), null);
		}
		// js
		if ($style_or_script == 'all' || $style_or_script == 'js') {
			if (wp_script_is($plugin::$prefix.'-event', 'enqueued') || wp_script_is($plugin::$prefix.'-event', 'registered')) {
				return;
			}
			if (empty($plugin->get_option($plugin::$prefix, 'maps_provider', false))) {
				return;
			}
			$maps_provider = $plugin->get_option($plugin::$prefix, 'maps_provider', false);

			// plugin js
			$plugin->enqueue_script($plugin::$prefix.'-event', plugins_url('/assets/js/event.min.js', __FILE__), array('jquery'), null, true);
			$data = array(
	            'prefix' => $plugin::$prefix,
	            'maps_provider' => $maps_provider,
	            'maps_load_startup' => $plugin->get_option($plugin::$prefix, 'maps_load_startup', false),
	        );

			// openstreetmap
			if ($maps_provider == 'openstreetmap') {
				$data['openstreetmap_src'] = esc_url(set_url_scheme('http://www.openstreetmap.org').'/export/embed.html');
			}

	        wp_localize_script($plugin::$prefix.'-event', 'eventcalendar', $data);

			// google js
			if ($maps_provider == 'google') {
				$args = array(
					'key' => $plugin->get_option($plugin::$prefix, 'maps_google_api_key', ''),
					'language' => $plugin->get_language(),
					'callback' => $plugin::$prefix.'_init',
				);
				$script = add_query_arg($args, set_url_scheme('http://maps.googleapis.com').'/maps/api/js');
				$js_handle_googlemaps = $plugin->enqueue_script($plugin::$prefix.'-googlemaps', $script, array($plugin::$prefix.'-event'));
			}
		}
	}

    public static function get_geo_search($q = '', $limit = 4) {
	    if (empty($q)) {
	    	return false;
	    }
		$plugin = new static(static::$plugin_basename, static::$prefix, false);
		$transient_name = $plugin::$prefix.'_geo_'.$q;
		// look for old query
		$val = $plugin->get_transient($transient_name);
		if (!empty($val)) {
			return $val;
		}
        // make a new query
        $url = set_url_scheme('http://nominatim.openstreetmap.org').'/search?q='.urlencode($q).'&format=json&limit='.$limit.'&accept-language='.$plugin->get_language();
		// use user_agent when available
		$user_agent = $plugin->plugin_title;
		if (isset($_SERVER["HTTP_USER_AGENT"]) && !empty($_SERVER["HTTP_USER_AGENT"])) {
			$user_agent = $_SERVER["HTTP_USER_AGENT"];
		}
		$options = array('http' => array('user_agent' => $user_agent));
		$context = stream_context_create($options);
		$val = @file_get_contents($url, false, $context);
		if ($val === false || (is_string($val) && trim($val) == '')) {
			return false;
		}
		$plugin->set_transient($transient_name, $val, 30 * DAY_IN_SECONDS);
		return $val;
    }
}
endif;
?>