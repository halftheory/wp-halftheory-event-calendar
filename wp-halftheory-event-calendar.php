<?php
/*
Plugin Name: Half/theory Event Calendar
Plugin URI: https://github.com/halftheory/wp-halftheory-event-calendar
GitHub Plugin URI: https://github.com/halftheory/wp-halftheory-event-calendar
Description: Half/theory Event Calendar Plugin.
Author: Half/theory
Author URI: https://github.com/halftheory
Version: 3.0
Network: false
*/

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
*/

// Exit if accessed directly.
defined('ABSPATH') || exit;

if ( ! class_exists('Halftheory_Helper_Plugin', false) && is_readable(dirname(__FILE__) . '/class-halftheory-helper-plugin.php') ) {
	include_once dirname(__FILE__) . '/class-halftheory-helper-plugin.php';
}

if ( ! class_exists('Halftheory_Event_Calendar', false) && class_exists('Halftheory_Helper_Plugin', false) ) :
	final class Halftheory_Event_Calendar extends Halftheory_Helper_Plugin {

		protected static $instance;
		public static $prefix;
		public static $active = false;
		public $postmeta_key;
		public $postmeta_key_time;
		public $shortcode = 'event-calendar';

		/* setup */

		protected function setup_globals( $plugin_basename = null, $prefix = null ) {
			parent::setup_globals($plugin_basename, $prefix);

			self::$active = $this->get_options_context('db', 'active');
			$this->postmeta_key = static::$prefix;
			$this->postmeta_key_time = static::$prefix . '_time';
		}

		protected function setup_actions() {
			parent::setup_actions();

			// Stop if not active.
			if ( empty(self::$active) ) {
				if ( $this->is_front_end() ) {
					add_action('pre_get_posts', array( $this, 'pre_get_posts' ), 90);
				}
				return;
			}

			if ( ! $this->is_front_end() ) {
				// admin.
				add_action('add_meta_boxes', array( $this, 'add_meta_boxes' ), 10, 2);
				// add column for date_start.
				$post_types_custom_column = $this->get_options_context('db', 'post_types_custom_column');
				if ( ! empty($post_types_custom_column) ) {
					foreach ( $post_types_custom_column as $post_type ) {
						add_filter("manage_{$post_type}_posts_columns", array( $this, 'manage_posts_columns' ), 20, 1);
						add_action("manage_{$post_type}_posts_custom_column", array( $this, 'manage_posts_custom_column' ), 20, 2);
						add_filter("manage_edit-{$post_type}_sortable_columns", array( $this, 'manage_sortable_columns' ), 20, 1);
					}
					add_action('admin_head', array( $this, 'admin_head' ));
					add_action('pre_get_posts', array( $this, 'pre_get_posts_admin' ));
				}
			} else {
				// public.
				// bbpress.
				add_action('bbp_theme_after_forum_form_content', array( $this, 'add_meta_boxes_frontend' ));
				add_action('bbp_theme_after_topic_form_content', array( $this, 'add_meta_boxes_frontend' ));
				add_action('bbp_theme_after_reply_form_content', array( $this, 'add_meta_boxes_frontend' ));
				// ajax.
				add_action('wp_ajax_' . static::$prefix . '_fullcalendar', array( $this, 'ajax_fullcalendar' ));
				add_action('wp_ajax_nopriv_' . static::$prefix . '_fullcalendar', array( $this, 'ajax_fullcalendar' ));
				add_action('wp_ajax_' . static::$prefix . '_qtip', array( $this, 'ajax_qtip' ));
				add_action('wp_ajax_nopriv_' . static::$prefix . '_qtip', array( $this, 'ajax_qtip' ));
			}
			// admin + public. only for editors.
			add_action('save_post', array( $this, 'save_post' ), 10, 3);
			add_action('wp_ajax_' . static::$prefix . '_geo_search', array( $this, 'ajax_geo_search' ));
			add_action('wp_ajax_nopriv_' . static::$prefix . '_geo_search', array( $this, 'ajax_geo_search' ));

			// filters.
			if ( ! shortcode_exists($this->shortcode) ) {
				add_shortcode($this->shortcode, array( $this, 'shortcode' ));
			}
			if ( $this->is_front_end() ) {
				add_action('pre_get_posts', array( $this, 'pre_get_posts' ), 90);
				add_filter('the_content', array( $this, 'the_content' ), 20);
				add_action('bbp_template_after_single_forum', array( $this, 'the_content' ));
				add_action('bbp_template_after_single_topic', array( $this, 'the_content' ));
				add_action('bbp_template_after_single_reply', array( $this, 'the_content' ));
			}
			// add meta to search.
			add_action('posts_where', array( $this, 'posts_where' ), 20, 2);
			// adjacent links.
			$post_types_adjacent = $this->get_options_context('db', 'post_types_adjacent');
			if ( ! empty($post_types_adjacent) ) {
				add_filter('get_previous_post_where', array( $this, 'get_adjacent_post_where' ), 20, 5);
				add_filter('get_next_post_where', array( $this, 'get_adjacent_post_where' ), 20, 5);
			}
		}

		public function plugin_deactivation( $network_wide ) {
			$this->delete_transient_uninstall();
			parent::plugin_deactivation($network_wide);
		}

		public static function plugin_uninstall() {
			static::$instance->delete_transient_uninstall();
			static::$instance->delete_postmeta_uninstall();
			static::$instance->delete_option_uninstall();
			parent::plugin_uninstall();
		}

		/* admin */

		public function menu_page() {
			$plugin = static::$instance;

			global $title;
			?>
			<div class="wrap">
			<h2><?php echo $title; ?></h2>

			<?php
			// http://codex.wordpress.org/Geodata
			if ( $plugin->save_menu_page() ) {
				$save = function () use ( $plugin ) {
					// get values.
					$options = array();
					foreach ( array_keys($plugin->get_options_context('default')) as $value ) {
						$name = $plugin::$prefix . '_' . $value;
						if ( ! isset($_POST[ $name ]) ) {
							continue;
						}
						if ( $plugin->empty_notzero($_POST[ $name ]) ) {
							continue;
						}
						$options[ $value ] = $_POST[ $name ];
					}
					// save it.
					$updated = '<div class="updated"><p><strong>' . esc_html__('Options saved.') . '</strong></p></div>';
					$error = '<div class="error"><p><strong>' . esc_html__('Error: There was a problem.') . '</strong></p></div>';
					if ( ! empty($options) ) {
						$options = $plugin->get_options_context('input', null, array(), $options);
						if ( $plugin->update_option($plugin::$prefix, $options) ) {
							echo $updated;
						} else {
							echo $error;
						}
					} else {
						if ( $plugin->delete_option($plugin::$prefix) ) {
							echo $updated;
						} else {
							echo $updated;
						}
					}
				};
				$save();

				// postmeta recalculate.
				if ( isset($_POST[ $plugin::$prefix . '_postmeta_recalculate' ]) && ! empty($_POST[ $plugin::$prefix . '_postmeta_recalculate' ]) ) {
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
								'key' => $plugin->postmeta_key,
								'compare' => 'EXISTS',
							),
						),
					);
					$posts = get_posts($args);
					if ( ! empty($posts) && ! is_wp_error($posts) ) {
						foreach ( $posts as $post ) {
							$postmeta = $plugin->get_postmeta($post->ID, $plugin->postmeta_key);
							$postmeta = array_filter($postmeta, function ( $v ) use ( $plugin ) { return ! $plugin->empty_notzero($v); });
							if ( ! empty($postmeta) ) {
								$plugin->update_postmeta($post->ID, $plugin->postmeta_key, $postmeta);
								if ( $plugin->post_has_event_data($post->ID, array( 'date_start' ), $postmeta) ) {
									$plugin->update_postmeta($post->ID, $plugin->postmeta_key_time, strtotime($postmeta['date_start']));
								} else {
									$plugin->delete_postmeta($post->ID, $plugin->postmeta_key_time);
								}
							} else {
								$plugin->delete_postmeta($post->ID, $plugin->postmeta_key);
								$plugin->delete_postmeta($post->ID, $plugin->postmeta_key_time);
							}
						}
						echo '<div class="updated"><p><strong>' . esc_html__('All postmeta data successfully recalculated.') . '</strong></p></div>';
					} else {
						echo '<div class="error"><p><strong>' . esc_html__('No postmeta data to recalculate.') . '</strong></p></div>';
					}
				}
				// import postmeta.
				if ( isset($_POST[ $plugin::$prefix . '_eventpost_import' ]) && ! empty($_POST[ $plugin::$prefix . '_eventpost_import' ]) ) {
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
							),
						),
					);
					$posts = get_posts($args);
					if ( ! empty($posts) && ! is_wp_error($posts) ) {
						foreach ( $posts as $post ) {
							$date_start = get_post_meta($post->ID, 'event_begin', true);
							if ( ! empty($date_start) ) {
								$date_start = gmdate('d-m-Y H:i', strtotime($date_start));
							}
							$date_end = get_post_meta($post->ID, 'event_end', true);
							if ( ! empty($date_end) ) {
								$date_end = gmdate('d-m-Y H:i', strtotime($date_end));
							}
							$postmeta = array(
								'date_start' => $date_start,
								'date_end' => $date_end,
								'geo_address' => get_post_meta($post->ID, 'geo_address', true),
								'geo_latitude' => get_post_meta($post->ID, 'geo_latitude', true),
								'geo_longitude' => get_post_meta($post->ID, 'geo_longitude', true),
							);
							$postmeta = array_filter($postmeta, function ( $v ) use ( $plugin ) { return ! $plugin->empty_notzero($v); });
							if ( ! empty($postmeta) ) {
								$plugin->update_postmeta($post->ID, $plugin->postmeta_key, $postmeta);
								if ( $plugin->post_has_event_data($post->ID, array( 'date_start' ), $postmeta) ) {
									$plugin->update_postmeta($post->ID, $plugin->postmeta_key_time, strtotime($postmeta['date_start']));
								}
							}
						}
						echo '<div class="updated"><p><strong>' . esc_html__('Imported data from the Event Post plugin.') . '</strong></p></div>';
					} else {
						echo '<div class="error"><p><strong>' . esc_html__('No data to import from the Event Post plugin.') . '</strong></p></div>';
					}
				}
				// delete postmeta.
				if ( isset($_POST[ $plugin::$prefix . '_eventpost_delete' ]) && ! empty($_POST[ $plugin::$prefix . '_eventpost_delete' ]) ) {
					if ( ! isset($posts) ) {
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
								),
							),
						);
						$posts = get_posts($args);
					}
					if ( ! empty($posts) && ! is_wp_error($posts) ) {
						foreach ( $posts as $post ) {
							delete_post_meta($post->ID, 'event_begin');
							delete_post_meta($post->ID, 'event_end');
							delete_post_meta($post->ID, 'event_color');
							delete_post_meta($post->ID, 'geo_address');
							delete_post_meta($post->ID, 'geo_latitude');
							delete_post_meta($post->ID, 'geo_longitude');
						}
						echo '<div class="updated"><p><strong>' . esc_html__('Deleted data from the Event Post plugin.') . '</strong></p></div>';
					} else {
						echo '<div class="error"><p><strong>' . esc_html__('No data to delete from the Event Post plugin.') . '</strong></p></div>';
					}
				}
			}

			// Show the form.
			$options = $plugin->get_options_context('admin_form');
			?>

			<form id="<?php echo esc_attr($plugin::$prefix); ?>-admin-form" name="<?php echo esc_attr($plugin::$prefix); ?>-admin-form" method="post" action="<?php echo esc_attr($_SERVER['REQUEST_URI']); ?>">
			<?php
			// Use nonce for verification.
			wp_nonce_field($this->plugin_basename, $plugin->plugin_name . '::' . __FUNCTION__);
			?>
			<div id="poststuff">

			<p><label for="<?php echo esc_attr($plugin::$prefix); ?>_active"><input type="checkbox" id="<?php echo esc_attr($plugin::$prefix); ?>_active" name="<?php echo esc_attr($plugin::$prefix); ?>_active" value="1"<?php checked($options['active'], true); ?> /> <?php echo esc_html($plugin->plugin_title); ?> <?php esc_html_e('active?'); ?></label></p>

			<div class="postbox">
				<div class="inside">
					<h4><?php esc_html_e('Allowed Post Types'); ?></h4>
					<p><span class="description"><?php esc_html_e('Calendars will only display the following post types. You can override this with the "post_types" variable in the shortcode.'); ?></span></p>
					<?php
					$post_types = array();
					$arr = get_post_types(array( 'public' => true ), 'objects');
					foreach ( $arr as $key => $value ) {
						$post_types[ $key ] = $value->label;
					}
					foreach ( $post_types as $key => $value ) {
						echo '<label style="display: inline-block; width: 50%;"><input type="checkbox" name="' . esc_attr($plugin::$prefix) . '_post_types[]" value="' . esc_attr($key) . '"';
						if ( in_array($key, $options['post_types'], true) ) {
							checked($key, $key);
						}
						echo '> ' . esc_html($value) . '</label>';
					}
					?>

					<h4><?php esc_html_e('Admin Custom Column'); ?></h4>
					<p><span class="description"><?php esc_html_e('Add a sortable column for the Start Date in admin pages.'); ?></span></p>
					<?php
					foreach ( $post_types as $key => $value ) {
						if ( ! in_array($key, $options['post_types'], true) ) {
							continue;
						}
						echo '<label style="display: inline-block; width: 50%;"><input type="checkbox" name="' . esc_attr($plugin::$prefix) . '_post_types_custom_column[]" value="' . esc_attr($key) . '"';
						if ( in_array($key, $options['post_types_custom_column'], true) ) {
							checked($key, $key);
						}
						echo '> ' . esc_html($value) . '</label>';
					}
					?>

					<h4><?php esc_html_e('Update Adjacent Posts'); ?></h4>
					<p><span class="description"><?php esc_html_e('Change previous/next links to follow the event Start Date.'); ?></span></p>
					<?php
					foreach ( $post_types as $key => $value ) {
						if ( ! in_array($key, $options['post_types'], true) ) {
							continue;
						}
						echo '<label style="display: inline-block; width: 50%;"><input type="checkbox" name="' . esc_attr($plugin::$prefix) . '_post_types_adjacent[]" value="' . esc_attr($key) . '"';
						if ( in_array($key, $options['post_types_adjacent'], true) ) {
							checked($key, $key);
						}
						echo '> ' . esc_html($value) . '</label>';
					}
					?>

					<h4><?php esc_html_e('Update Post Date'); ?></h4>
					<p><span class="description"><?php esc_html_e('Update the Post Date to reflect the event Start Date. Helps with queries ordered by date.'); ?></span></p>
					<?php
					foreach ( $post_types as $key => $value ) {
						if ( ! in_array($key, $options['post_types'], true) ) {
							continue;
						}
						echo '<label style="display: inline-block; width: 50%;"><input type="checkbox" name="' . esc_attr($plugin::$prefix) . '_post_types_post_date[]" value="' . esc_attr($key) . '"';
						if ( in_array($key, $options['post_types_post_date'], true) ) {
							checked($key, $key);
						}
						echo '> ' . esc_html($value) . '</label>';
					}
					?>
				</div>
			</div>

			<div class="postbox">
				<div class="inside">
					<h4><?php esc_html_e('Automatic Calendar Inclusion'); ?></h4>
					<p><span class="description"><?php esc_html_e('If both of these are checked the modified date will take precedence. You can override these with the "include_post_date" and "include_post_modified" variables in the shortcode.'); ?></span></p>

					<p><label for="<?php echo esc_attr($plugin::$prefix); ?>_include_post_date"><input type="checkbox" id="<?php echo esc_attr($plugin::$prefix); ?>_include_post_date" name="<?php echo esc_attr($plugin::$prefix); ?>_include_post_date" value="1"<?php checked($options['include_post_date'], true); ?> /> <?php esc_html_e('Automatically include posts on the date they were created?'); ?></label></p>

					<p><label for="<?php echo esc_attr($plugin::$prefix); ?>_include_post_modified"><input type="checkbox" id="<?php echo esc_attr($plugin::$prefix); ?>_include_post_modified" name="<?php echo esc_attr($plugin::$prefix); ?>_include_post_modified" value="1"<?php checked($options['include_post_modified'], true); ?> /> <?php esc_html_e('Automatically include posts on the date they were modified?'); ?></label></p>
				</div>
			</div>

			<div class="postbox">
				<div class="inside">
					<h4><?php esc_html_e('Maps'); ?></h4>
					<?php
					$maps_providers = array(
						'openstreetmap' => 'OpenStreetMap',
						'google' => 'Google',
					);
					?>
					<p><label for="<?php echo esc_attr($plugin::$prefix); ?>_maps_provider" style="display: inline-block; width: 20em; max-width: 25%;"><?php esc_html_e('Maps Provider'); ?></label>
					<select id="<?php echo esc_attr($plugin::$prefix); ?>_maps_provider" name="<?php echo esc_attr($plugin::$prefix); ?>_maps_provider">
						<option value=""><?php esc_html_e('&mdash;&mdash;'); ?></option>
						<?php foreach ( $maps_providers as $key => $value ) : ?>
							<option value="<?php echo esc_attr($key); ?>"<?php selected($key, $options['maps_provider']); ?>><?php echo esc_html($value); ?></option>
						<?php endforeach; ?>
					</select></p>

					<p><label for="<?php echo esc_attr($plugin::$prefix); ?>_maps_google_api_key" style="display: inline-block; width: 20em; max-width: 25%;"><?php esc_html_e('Google API Key'); ?></label>
					<input type="text" name="<?php echo esc_attr($plugin::$prefix); ?>_maps_google_api_key" id="<?php echo esc_attr($plugin::$prefix); ?>_maps_google_api_key" style="width: 50%;" value="<?php echo esc_attr($options['maps_google_api_key']); ?>" /><br />
					<span class="description small" style="margin-left: 20em;"><?php esc_html_e('These details are available from Google.'); ?></span></p>

					<p><label for="<?php echo esc_attr($plugin::$prefix); ?>_maps_load_startup"><input type="checkbox" id="<?php echo esc_attr($plugin::$prefix); ?>_maps_load_startup" name="<?php echo esc_attr($plugin::$prefix); ?>_maps_load_startup" value="1"<?php checked($options['maps_load_startup'], true); ?> /> <?php esc_html_e('Load maps at startup? Disabling this can reduce the number of API calls.'); ?></label></p>
				</div>
			</div>

			<div class="postbox">
				<div class="inside">
					<h4><?php esc_html_e('Data Import'); ?></h4>
					<p><label for="<?php echo esc_attr($plugin::$prefix); ?>_postmeta_recalculate"><input type="checkbox" id="<?php echo esc_attr($plugin::$prefix); ?>_postmeta_recalculate" name="<?php echo esc_attr($plugin::$prefix); ?>_postmeta_recalculate" value="1" /> <?php esc_html_e('Recalculate all existing postmeta data.'); ?></label></p>
					<p><label for="<?php echo esc_attr($plugin::$prefix); ?>_eventpost_import"><input type="checkbox" id="<?php echo esc_attr($plugin::$prefix); ?>_eventpost_import" name="<?php echo esc_attr($plugin::$prefix); ?>_eventpost_import" value="1" /> <?php esc_html_e('Import existing postmeta data from the Event Post plugin?'); ?></label></p>
					<p><label for="<?php echo esc_attr($plugin::$prefix); ?>_eventpost_delete"><input type="checkbox" id="<?php echo esc_attr($plugin::$prefix); ?>_eventpost_delete" name="<?php echo esc_attr($plugin::$prefix); ?>_eventpost_delete" value="1" /> <?php esc_html_e('Delete existing postmeta data from the Event Post plugin?'); ?></label></p>
				</div>
			</div>

			<?php submit_button(__('Update'), array( 'primary', 'large' ), 'save'); ?>

			</div><!-- poststuff -->
			</form>

			</div><!-- wrap -->
			<?php
		}

		public function add_meta_boxes( $post_type, $post ) {
			$post_types = $this->get_options_context('db', 'post_types');
			if ( ! in_array($post_type, $post_types, true) ) {
				return;
			}
			add_meta_box(
				static::$prefix,
				$this->plugin_title,
				array( $this, 'postmeta' ),
				$post_type
			);
		}

		public function add_meta_boxes_frontend( $post = 0 ) {
			if ( empty($post) || ! is_object($post) ) {
				global $post;
			}
			$post_types = $this->get_options_context('db', 'post_types');
			// checking bbpress because $post is unreliable.
			if ( current_filter() === 'bbp_theme_after_forum_form_content' && ! in_array('forum', $post_types, true) ) {
				return;
			} elseif ( current_filter() === 'bbp_theme_after_topic_form_content' && ! in_array('topic', $post_types, true) ) {
				return;
			} elseif ( current_filter() === 'bbp_theme_after_reply_form_content' && ! in_array('reply', $post_types, true) ) {
				return;
			} elseif ( ! in_array($post->post_type, $post_types, true) ) {
				return;
			}
			$str = '<p><button class="' . esc_attr(static::$prefix) . '-toggle">Toggle ' . $this->plugin_title . ' options</button></p>';
			$str = apply_filters('eventcalendar_toggle', $str);
			echo $str;
			$this->postmeta($post);
		}

		public function postmeta( $post = 0 ) {
			// may be used in admin or frontend.
			if ( empty($post) || ! is_object($post) ) {
				global $post;
			}

			$postmeta_arr = $this->get_postmeta_array();
			$postmeta = $this->get_postmeta($post->ID, $this->postmeta_key);
			$postmeta = array_merge( array_fill_keys($postmeta_arr, null), $this->make_array($postmeta) );

			// Use nonce for verification.
			wp_nonce_field($this->plugin_basename, $this->plugin_name . '::' . __FUNCTION__);

			// datetimepicker.
			$js_handle_datetimepicker = $this->enqueue_script(static::$prefix . '-datetimepicker', plugins_url('/assets/js/datetimepicker/jquery.datetimepicker.full' . ( ! ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ) ? '.min' : '' ) . '.js', __FILE__), array( 'jquery' ), '1.3.4');
			$this->enqueue_style(static::$prefix . '-datetimepicker', plugins_url('/assets/js/datetimepicker/jquery.datetimepicker' . ( ! ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ) ? '.min' : '' ) . '.css', __FILE__), array(), '1.3.4');

			// js + css.
			wp_enqueue_script(static::$prefix . '-postmeta', plugins_url('/assets/js/postmeta' . ( ! ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ) ? '.min' : '' ) . '.js', __FILE__), array( $js_handle_datetimepicker ), $this->get_plugin_version(), true);
			wp_localize_script(static::$prefix . '-postmeta',
				'postmeta',
				array(
					'prefix' => static::$prefix,
					'ajaxurl' => admin_url() . 'admin-ajax.php',
				)
			);
			wp_enqueue_style(static::$prefix . '-postmeta', plugins_url('/assets/css/postmeta.css', __FILE__), array(), $this->get_plugin_version());

			// html.
			?>
	<div class="<?php echo esc_attr(static::$prefix); ?>">
		<div class="<?php echo esc_attr(static::$prefix); ?>-fields <?php echo esc_attr(static::$prefix); ?>-date">
			<h4><?php esc_html_e('Date'); ?></h4>

			<p><label for="<?php echo esc_attr(static::$prefix); ?>_date_start"><?php esc_html_e('Start:'); ?></label> <input type="text" name="<?php echo esc_attr(static::$prefix); ?>_date_start" id="<?php echo esc_attr(static::$prefix); ?>_date_start" value="<?php echo esc_attr($postmeta['date_start']); ?>" /></p>

			<p><label for="<?php echo esc_attr(static::$prefix); ?>_date_end"><?php esc_html_e('End:'); ?></label> <input type="text" name="<?php echo esc_attr(static::$prefix); ?>_date_end" id="<?php echo esc_attr(static::$prefix); ?>_date_end" value="<?php echo esc_attr($postmeta['date_end']); ?>" /></p>
		</div>
		<div class="<?php echo esc_attr(static::$prefix); ?>-fields <?php echo esc_attr(static::$prefix); ?>-location">
			<h4><?php esc_html_e('Location'); ?></h4>

			<p><input type="text" name="<?php echo esc_attr(static::$prefix); ?>_geo_search" id="<?php echo esc_attr(static::$prefix); ?>_geo_search" value="" placeholder="<?php esc_html_e('Search for GPS coordinates'); ?>" /> <input id="<?php echo esc_attr(static::$prefix); ?>_geo_search_button" class="button" value="Go" type="button" /></p>
			<div id="<?php echo esc_attr(static::$prefix); ?>_geo_search_result"></div>

			<p><label for="<?php echo esc_attr(static::$prefix); ?>_geo_address"><?php esc_html_e('Address:'); ?></label> <textarea name="<?php echo esc_attr(static::$prefix); ?>_geo_address" id="<?php echo esc_attr(static::$prefix); ?>_geo_address" rows="2"><?php echo $postmeta['geo_address']; ?></textarea></p>

			<p><label for="<?php echo esc_attr(static::$prefix); ?>_geo_latitude"><?php esc_html_e('Latitude:'); ?></label> <input type="text" name="<?php echo esc_attr(static::$prefix); ?>_geo_latitude" id="<?php echo esc_attr(static::$prefix); ?>_geo_latitude" value="<?php echo esc_attr($postmeta['geo_latitude']); ?>" /></p>

			<p><label for="<?php echo esc_attr(static::$prefix); ?>_geo_longitude"><?php esc_html_e('Longitude:'); ?></label> <input type="text" name="<?php echo esc_attr(static::$prefix); ?>_geo_longitude" id="<?php echo esc_attr(static::$prefix); ?>_geo_longitude" value="<?php echo esc_attr($postmeta['geo_longitude']); ?>" /></p>
		</div>
	</div>
			<?php
		}

		public function save_post( $post_id = 0, $post = 0, $update = true ) {
			// only for editors.
			if ( ! function_exists('is_user_logged_in') ) {
				return;
			}
			if ( ! is_user_logged_in() || ! current_user_can('edit_posts') ) {
				return;
			}
			// verify if this is an auto save routine.
			// If it is our form has not been submitted, so we dont want to do anything.
			if ( defined('DOING_AUTOSAVE') && DOING_AUTOSAVE ) {
				return;
			}
			if ( empty($update) ) {
				return;
			}
			// verify this came from the our screen and with proper authorization.
			// because save_post can be triggered at other times.
			if ( isset($_POST) ) {
				if ( isset($_POST[ $this->plugin_name . '::postmeta' ]) ) {
					if ( wp_verify_nonce($_POST[ $this->plugin_name . '::postmeta' ], $this->plugin_basename) ) {
						// get values.
						$postmeta_arr = $this->get_postmeta_array();
						$postmeta = array();
						foreach ( $postmeta_arr as $value ) {
							$name = static::$prefix . '_' . $value;
							if ( ! isset($_POST[ $name ]) ) {
								continue;
							}
							if ( $this->empty_notzero($_POST[ $name ]) ) {
								continue;
							}
							$postmeta[ $value ] = $_POST[ $name ];
						}
						// save it.
						$maybe_revision = wp_is_post_revision($post_id);
						if ( $maybe_revision !== false ) {
							$post_id = $maybe_revision;
						}
						if ( ! empty($postmeta) ) {
							$this->update_postmeta($post_id, $this->postmeta_key, $postmeta);

							if ( $this->post_has_event_data($post_id, array( 'date_start' ), $postmeta) ) {
								$date_start_time = strtotime($postmeta['date_start']);
								$this->update_postmeta($post_id, $this->postmeta_key_time, $date_start_time);
								// update post_date in posts table?
								$post_types_post_date = $this->get_options_context('db', 'post_types_post_date');
								if ( in_array(get_post_type($post_id), $post_types_post_date, true) ) {
									$date_start_mysql = gmdate('Y-m-d H:i:s', min($date_start_time, current_time('U')));
									if ( $date_start_mysql !== $post->post_date ) {
										$postarr = array(
											'ID' => $post_id,
											'post_date' => $date_start_mysql,
											'post_date_gmt' => get_gmt_from_date($date_start_mysql),
										);
										wp_update_post(wp_slash($postarr));
									}
								}
							} else {
								$this->delete_postmeta($post_id, $this->postmeta_key_time);
							}
						} else {
							$this->delete_postmeta($post_id, $this->postmeta_key);
							$this->delete_postmeta($post_id, $this->postmeta_key_time);
						}
					}
				}
			}
		}

		public function manage_posts_columns( $posts_columns = array() ) {
			$arr = array();
			foreach ( $posts_columns as $key => $value ) {
				if ( $key === 'title' ) {
					$arr['date_start'] = __('Start');
				}
				$arr[ $key ] = $value;
			}
			return $arr;
		}
		public function manage_posts_custom_column( $column_name, $post_id = 0 ) {
			if ( $column_name === 'date_start' ) {
				if ( $postmeta = $this->post_has_event_data($post_id) ) {
					echo gmdate('Y-m-d', strtotime($postmeta['date_start']));
				}
			}
		}
		public function manage_sortable_columns( $sortable_columns = array() ) {
			$sortable_columns['date_start'] = array( 'date_start', true );
			return $sortable_columns;
		}
		public function admin_head() {
			$post_types_custom_column = $this->get_options_context('db', 'post_types_custom_column');
			if ( ! $this->is_edit_screen($post_types_custom_column) ) {
				return;
			}
			echo '<style type="text/css">.column-date_start {width: 10%;}</style>';
		}
		public function pre_get_posts_admin( $query ) {
			if ( ! $query->is_main_query() ) {
				return;
			}
			if ( $query->is_search() ) {
				return;
			}
			$post_types_custom_column = $this->get_options_context('db', 'post_types_custom_column');
			if ( ! $this->is_edit_screen($post_types_custom_column) ) {
				return;
			}
			global $typenow;
			if ( ! in_array($typenow, $post_types_custom_column, true) ) {
				return;
			}
			if ( ! in_array($query->get('post_type'), $post_types_custom_column, true) ) {
				return;
			}
			if ( strpos($query->get('orderby'), ' ') !== false ) {
				// assume default.
				$query = $this->wp_query_orderby_date_start($query);
				$query->set('order', 'DESC');
			} elseif ( $query->get('orderby') === '' || $query->get('orderby') === 'date_start' ) {
				// assume default.
				$query = $this->wp_query_orderby_date_start($query);
			} else {
				if ( $query->get('orderby') === '' ) {
					$query->set('orderby', 'date');
				}
				if ( $query->get('order') === '' ) {
					$query->set('order', 'DESC');
				}
			}
		}

		/* shortcode */

		public function shortcode( $atts = array(), $content = '', $shortcode = '' ) {
			$str = '';
			if ( ! is_main_query() ) {
				return $str;
			}
			if ( ! in_the_loop() ) {
				return $str;
			}
			if ( ! is_singular() ) {
				return $str;
			}

			// resolve user input.
			$atts = $this->make_array($atts);
			if ( ! empty($atts) ) {
				$atts = $this->trim_quotes($atts);
				$atts = $this->get_options_context('input', null, array(), $atts);
				// options - defaults.
				$options_db = $this->get_options_context('db');
				unset($options_db['active']);
				if ( is_multisite() ) {
					$options_db['sites'] = array();
				}
				$atts = shortcode_atts($options_db, $atts, $this->shortcode);
				$atts = array_filter($atts);
			}

			// fullcalendar.
			// css.
			$css_handle_fullcalendar = $this->enqueue_style(static::$prefix . '-fullcalendar', plugins_url('/assets/js/fullcalendar/fullcalendar' . ( ! ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ) ? '.min' : '' ) . '.css', __FILE__), array(), '3.8.0');
			$css_handle_fullcalendar_print = $this->enqueue_style(static::$prefix . '-fullcalendar-print', plugins_url('/assets/js/fullcalendar/fullcalendar.print' . ( ! ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ) ? '.min' : '' ) . '.css', __FILE__), array( $css_handle_fullcalendar ), '3.8.0', 'print');
			// js.
			$js_handle_moment = $this->enqueue_script(static::$prefix . '-moment', plugins_url('/assets/js/fullcalendar/lib/moment.min.js', __FILE__));
			$js_handle_jquery = 'jquery';
			if ( ! wp_script_is('jquery', 'registered') ) {
				$js_handle_jquery = $this->enqueue_script('jquery', plugins_url('/assets/js/fullcalendar/lib/jquery.min.js', __FILE__), array(), '3.2.1');
			}
			$js_handle_fullcalendar = $this->enqueue_script(static::$prefix . '-fullcalendar', plugins_url('/assets/js/fullcalendar/fullcalendar' . ( ! ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ) ? '.min' : '' ) . '.js', __FILE__), array( $js_handle_jquery, $js_handle_moment ), '3.8.0');
			// qtip.
			// css.
			$css_handle_qtip = $this->enqueue_style(static::$prefix . '-qtip', plugins_url('/assets/js/qtip/jquery.qtip' . ( ! ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ) ? '.min' : '' ) . '.css', __FILE__), array(), '3.0.3', 'screen');
			// js.
			$js_handle_imagesloaded = $this->enqueue_script(static::$prefix . '-imagesloaded', plugins_url('/assets/js/qtip/imagesloaded.pkgd.min.js', __FILE__), array( $js_handle_jquery ), '4.1.2');
			$this->enqueue_script(static::$prefix . '-qtip', plugins_url('/assets/js/qtip/jquery.qtip' . ( ! ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ) ? '.min' : '' ) . '.js', __FILE__), array( $js_handle_imagesloaded ), '3.0.3');

			// $args - list of vars to send to fullcalendar https://fullcalendar.io/docs/
			$args = array(
				'dayPopoverFormat' => 'dddd D MMMM Y',
				'firstDay' => $this->get_option('start_of_week', null, 1),
				'listDayAltFormat' => 'D MMMM Y',
				'timeFormat' => 'HH:mm',
			);
			$args = apply_filters('eventcalendar_fullcalendar_args', $args);

			// $qtip - list of vars to send to qtip http://qtip2.com/options
			$qtip = array(
				'content' => array(
					'text' => __('Loading...'),
					'button' => __('Close'),
				),
				'hide' => array(
					'event' => 'click unfocus mouseleave',
					'fixed' => true,
					'delay' => 500,
				),
				'position' => array(
					'my' => 'top left',
					'at' => 'bottom center',
				),
				'style' => array(
					'classes' => 'qtip-blue qtip-shadow',
				),
			);
			$qtip = apply_filters('eventcalendar_qtip_args', $qtip);

			// plugin.
			// css.
			$css_handle_plugin = $this->enqueue_style(static::$prefix . '-fullcalendar-init', plugins_url('/assets/css/fullcalendar-init.css', __FILE__), array( $css_handle_fullcalendar, $css_handle_qtip ), $this->get_plugin_version());
			// js
			wp_enqueue_script(static::$prefix . '-fullcalendar-init', plugins_url('/assets/js/fullcalendar/fullcalendar-init' . ( ! ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ) ? '.min' : '' ) . '.js', __FILE__), array( $js_handle_fullcalendar ), $this->get_plugin_version(), true);
			wp_localize_script(static::$prefix . '-fullcalendar-init',
				'fullcalendar',
				array(
					'prefix' => static::$prefix,
					'ajaxurl' => admin_url() . 'admin-ajax.php',
					'data' => array_merge(array( 'action' => static::$prefix . '_fullcalendar' ), $atts),
					'args' => $args,
					'qtip' => $qtip,
				)
			);

			$str = '<div class="' . static::$prefix . '-fullcalendar"></div>';
			return apply_filters('eventcalendar_shortcode', $str);
		}

		/* ajax */

		public function ajax_fullcalendar() {
			// get post types, and more.
			$post_types = isset($_REQUEST['post_types']) ? $this->make_array($_REQUEST['post_types']) : $this->get_options_context('db', 'post_types');
			if ( empty($post_types) ) {
				wp_die();
			}
			$include_post_date = isset($_REQUEST['include_post_date']) ? $this->is_true($_REQUEST['include_post_date']) : $this->get_options_context('db', 'include_post_date');
			$include_post_modified = isset($_REQUEST['include_post_modified']) ? $this->is_true($_REQUEST['include_post_modified']) : $this->get_options_context('db', 'include_post_modified');
			// items function.
			$get_items = function ( $default_args = array() ) use ( $post_types, $include_post_date, $include_post_modified ) {
				$items = array();
				$args = array(
					'post_type' => $post_types,
					'post_status' => array( 'publish', 'inherit' ),
					'posts_per_page' => -1,
					'no_found_rows' => true,
					'nopaging' => true,
					'ignore_sticky_posts' => true,
					'orderby' => 'modified',
					'suppress_filters' => false,
				);
				$has_includes = true;
				if ( ! $include_post_date && ! $include_post_modified ) {
					$args['meta_query'] = array(
						array(
							'key' => $this->postmeta_key,
							'compare' => 'EXISTS',
						),
					);
					$has_includes = false;
				}
				$args = apply_filters('eventcalendar_posts_args', $args, $default_args);
				$posts = get_posts($args);
				if ( empty($posts) || is_wp_error($posts) ) {
					return $items;
				}
				$posts = apply_filters('eventcalendar_posts', $posts, $args, $default_args);
				foreach ( $posts as $post ) {
					$item = array(
						'title' => get_post_field('post_title', $post, 'display'),
						'url' => esc_url(get_permalink($post)),
						'post_id' => $post->ID,
					);
					$postmeta = $this->post_has_event_data($post->ID);
					if ( ! $postmeta && ! $has_includes ) {
						continue;
					} elseif ( $postmeta ) {
						$item['start'] = gmdate('Y-m-d\TH:i:s', strtotime($postmeta['date_start']));
						$has_end = false;
						if ( $this->post_has_event_data($post->ID, array( 'date_end' ), $postmeta) ) {
							$has_end = ( strtotime($postmeta['date_end']) < strtotime($postmeta['date_start']) ) ? false : true;
						}
						if ( $has_end ) {
							$item['end'] = gmdate('Y-m-d\TH:i:s', strtotime($postmeta['date_end']));
							// find all day events.
							if ( ! preg_match('/[0-9]{2}:[0-9]{2}/i', $postmeta['date_start']) || strpos($postmeta['date_start'], '00:00') !== false ) {
								if ( ( strtotime($postmeta['date_end']) - strtotime($postmeta['date_start']) ) > DAY_IN_SECONDS ) {
									$item['allDay'] = true;
								}
							}
						}
						$item['className'] = static::$prefix . '-postmeta';
					} elseif ( $include_post_modified ) {
						$item['start'] = $post->post_modified;
						$item['className'] = static::$prefix . '-modified';
					} elseif ( $include_post_date ) {
						$item['start'] = $post->post_date;
						$item['className'] = static::$prefix . '-date';
					}
					$items[] = array_merge($default_args, $item);
				}
				return $items;
			};
			// multisite or normal.
			$items = array();
			if ( is_multisite() && isset($_REQUEST['sites']) ) {
				if ( $_REQUEST['sites'] === 'all' ) {
					$sites = get_sites();
				} else {
					$sites = get_sites(array( 'site__in' => $this->make_array($_REQUEST['sites']) ));
				}
				foreach ( $sites as $key => $value ) {
					switch_to_blog($value->blog_id);
					$blog_items = $get_items(array( 'blog_id' => $value->blog_id ));
					if ( ! empty($blog_items) ) {
						$items = array_merge($items, $blog_items);
					}
					restore_current_blog();
				}
			} else {
				$items = $get_items(array( 'blog_id' => get_current_blog_id() ));
			}
			echo json_encode($items);
			wp_die();
		}

		public function ajax_qtip() {
			if ( ! isset($_REQUEST['post_id']) ) {
				wp_die();
			}
			if ( empty($_REQUEST['post_id']) ) {
				wp_die();
			}
			$post_id = absint($_REQUEST['post_id']);
			$blog_id = absint($_REQUEST['blog_id']);

			$switched = false;
			if ( is_multisite() && $blog_id > 0 ) {
				if ( get_current_blog_id() !== $blog_id ) {
					$switched = switch_to_blog($blog_id);
				}
			}

			$post = get_post($post_id);
			// title.
			$str = '<h2><a href="' . esc_url(get_permalink($post)) . '">' . get_the_title($post) . '</a></h2>';
			// thumbnail.
			if ( has_post_thumbnail($post) ) {
				$str .= '<a href="' . esc_url(get_permalink($post)) . '">' . get_the_post_thumbnail($post, 'thumbnail') . "</a>\n";
			}
			// excerpt.
			$excerpt = ! empty($post->post_excerpt) ? $post->post_excerpt : $post->post_content;
			if ( function_exists('get_the_excerpt_filtered') ) {
				$excerpt = get_the_excerpt_filtered($excerpt);
			}
			$excerpt = make_clickable($excerpt);
			$maxchars = 250;
			if ( function_exists('get_excerpt') ) {
				$args = array(
					'allowable_tags' => array( 'br', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'strong', 'b', 'em', 'i', 'ul', 'li', 'ol', 'blockquote', 'a' ),
					'plaintext' => false,
					'single_line' => false,
					'trim_urls' => false,
					'strip_shortcodes' => true,
					'add_dots' => true,
				);
				$excerpt = get_excerpt($excerpt, $maxchars, $args);
			} else {
				$excerpt = $this->strip_all_shortcodes($excerpt);
				if ( function_exists('excerpt_remove_blocks') ) {
					$excerpt = excerpt_remove_blocks($excerpt);
				}
				$excerpt = wp_strip_all_tags($excerpt, false);
				if ( strlen($excerpt) > $maxchars ) {
					$excerpt = substr($excerpt, 0, $maxchars) . wp_trim_words(substr($excerpt, $maxchars), 1);
				}
				$excerpt = force_balance_tags( wpautop( nl2br($excerpt) ) );
			}
			$str .= $excerpt;

			$str = apply_filters('eventcalendar_qtip', $str, $post_id, $post);

			if ( $switched ) {
				restore_current_blog();
			}
			echo $str;
			wp_die();
		}

		public function ajax_geo_search() {
			// only for editors.
			if ( ! function_exists('is_user_logged_in') ) {
				return;
			}
			if ( ! is_user_logged_in() || ! current_user_can('edit_posts') ) {
				return;
			}
			if ( ! isset($_REQUEST['q']) ) {
				wp_die();
			}
			if ( empty($_REQUEST['q']) ) {
				wp_die();
			}
			if ( ! headers_sent() ) {
				header('Content-Type: application/json');
			}
			$val = $this->get_geo_search($_REQUEST['q']);
			if ( empty($val) ) {
				$val = wp_json_encode('There was a problem contacting openstreetmap.org');
			}
			echo $val;
			wp_die();
		}

		/* actions */

		public function pre_get_posts( $query ) {
			// custom orderby 'date_start'.
			if ( $query->get('orderby') === 'date_start' ) {
				if ( empty(self::$active) ) {
					$query->set('orderby', 'date');
					return;
				}
				if ( $query->is_search() ) {
					$query->set('orderby', 'date');
					return;
				}
				// get post_types from query.
				$query_post_types = $this->make_array($query->get('post_type'));
				if ( empty($query_post_types) ) {
					$query->set('orderby', 'date');
					return;
				}
				// get post_types from settings.
				$post_types = $this->get_options_context('db', 'post_types');
				if ( empty($post_types) ) {
					$query->set('orderby', 'date');
					return;
				}
				// match.
				$found = false;
				foreach ( $query_post_types as $value ) {
					if ( in_array($value, $post_types, true) ) {
						$found = true;
						break;
					}
				}
				if ( ! $found ) {
					$query->set('orderby', 'date');
					return;
				}
				$query = $this->wp_query_orderby_date_start($query);
			}
		}

		public function the_content( $str = '' ) {
			if ( ! $this->the_content_conditions($str) ) {
				return $str;
			}
			if ( ! is_singular() && current_filter() === 'the_content' ) {
				return $str;
			}
			if ( is_search() ) {
				return $str;
			}
			if ( is_home() ) {
				return $str;
			}
			$post_types = $this->get_options_context('db', 'post_types');
			// checking bbpress because $post is unreliable.
			if ( current_filter() === 'bbp_template_after_single_forum' && ! in_array('forum', $post_types, true) ) {
				return;
			} elseif ( current_filter() === 'bbp_template_after_single_topic' && ! in_array('topic', $post_types, true) ) {
				return;
			} elseif ( current_filter() === 'bbp_template_after_single_reply' && ! in_array('reply', $post_types, true) ) {
				return;
			}
			$postmeta = $this->post_has_event_data(get_the_ID());
			if ( $postmeta === false ) {
				return $str;
			}

			// css.
			$this->enqueue_event_scripts('css');

			// start html.
			$res = '<div class="' . static::$prefix . '-event" itemscope itemtype="' . set_url_scheme('http://schema.org/Event') . '">' . "\n";
			$res .= '<meta itemprop="name" content="' . esc_attr(get_the_title()) . '" />' . "\n";

			// date_start.
			$has_start = true;
			// date_end.
			$has_end = false;
			if ( $this->post_has_event_data(get_the_ID(), array( 'date_end' ), $postmeta) ) {
				$has_end = ( strtotime($postmeta['date_end']) < strtotime($postmeta['date_start']) ) ? false : true;
			}

			if ( $has_start || $has_end ) {
				$date_format = $this->get_option('date_format', null, 'j F Y');
				$time_format = $this->get_option('time_format', null, 'H:i');

				$res .= '<div class="' . static::$prefix . '-fields ' . static::$prefix . '-date">
				<h4 class="' . static::$prefix . '-event-title">' . __('Event Date') . '</h4>';
				if ( $has_start ) {
					$res .= '<p><label for="' . static::$prefix . '_date_start">' . __('Start:') . '</label><span class="' . static::$prefix . '_date_start" itemprop="startDate" content="' . gmdate('Y-m-d\TH:i', strtotime($postmeta['date_start'])) . '">';
					if ( strpos($postmeta['date_start'], ' 00:00') === false ) {
						$res .= gmdate($date_format . ' - ' . $time_format, strtotime($postmeta['date_start']));
					} else {
						$res .= gmdate($date_format, strtotime($postmeta['date_start']));
					}
					$res .= '</span></p>';
				}
				if ( $has_end ) {
					$res .= '<p><label for="' . static::$prefix . '_date_end">' . __('End:') . '</label><span class="' . static::$prefix . '_date_end" itemprop="endDate" content="' . gmdate('Y-m-d\TH:i', strtotime($postmeta['date_end'])) . '">';
					if ( strpos($postmeta['date_end'], ' 00:00') === false ) {
						$res .= gmdate($date_format . ' - ' . $time_format, strtotime($postmeta['date_end']));
					} else {
						$res .= gmdate($date_format, strtotime($postmeta['date_end']));
					}
					$res .= '</span></p>';
				}
				$res .= '</div>';
			}

			$has_map = false;
			if ( $this->post_has_event_location($postmeta) ) {
				$res .= '<div class="' . static::$prefix . '-fields ' . static::$prefix . '-location" itemprop="location" itemscope itemtype="' . set_url_scheme('http://schema.org/Place') . '">
				<h4 class="' . static::$prefix . '-event-title">' . __('Event Location') . '</h4>';

				// geo_address.
				if ( isset($postmeta['geo_address']) && ! empty($postmeta['geo_address']) ) {
					$res .= '<p><label for="' . static::$prefix . '_geo_address">' . __('Address:') . '</label><span class="' . static::$prefix . '_geo_address" itemprop="address">' . nl2br($postmeta['geo_address']) . '</span></p>';
				}

				// map.
				if ( isset($postmeta['geo_latitude']) && isset($postmeta['geo_longitude']) && ! empty($postmeta['geo_latitude']) && ! empty($postmeta['geo_longitude']) && ! empty($this->get_options_context('db', 'maps_provider')) ) {
					$has_map = true;

					// js.
					$this->enqueue_event_scripts('js');

					$res .= '<span itemprop="geo" itemscope itemtype="' . set_url_scheme('http://schema.org/GeoCoordinates') . '" class="none">
					<meta itemprop="latitude" content="' . $postmeta['geo_latitude'] . '" />
					<meta itemprop="longitude" content="' . $postmeta['geo_longitude'] . '" /></span>';

					$res .= '<p><label for="' . static::$prefix . '-map">' . __('Map:') . '</label><span itemscope itemprop="hasMap" itemtype="' . set_url_scheme('http://schema.org/Map') . '">
					<link itemprop="mapType" href="' . set_url_scheme('http://schema.org/VenueMap') . '" />
					<meta itemprop="url" content="' . esc_url($this->get_current_uri() . '#' . static::$prefix . '-map-' . get_the_ID()) . '" />
					<button class="' . static::$prefix . '-map-toggle" data-latitude="' . $postmeta['geo_latitude'] . '" data-longitude="' . $postmeta['geo_longitude'] . '" data-id="' . get_the_ID() . '">' . __('Toggle Map') . '</button></span></p>';
				}
				$res .= '</div>';
			}
			if ( $has_map ) {
				$class = 'map-open';
				if ( ! $this->get_options_context('db', 'maps_load_startup') ) {
					$class = 'map-close';
				}
				$res .= '<div class="' . static::$prefix . '-map ' . $class . '" id="' . static::$prefix . '-map-' . get_the_ID() . '"></div>';
			}

			$res .= '</div>';

			$res = apply_filters('eventcalendar_the_content', $res, $postmeta, $this);
			if ( current_filter() === 'the_content' ) {
				return $str . $res;
			} else {
				echo $str . $res;
			}
		}

		public function posts_where( $where, $query ) {
			if ( ! $query->is_main_query() || ! $query->is_search() ) {
				return $where;
			}
			$post_types = $this->get_options_context('db', 'post_types');
			if ( empty($post_types) ) {
				return $where;
			}
			$query_post_types = $query->get('post_type');
			if ( empty($query_post_types) ) {
				return $where;
			}
			$query_post_types = $this->make_array($query_post_types);
			$found = false;
			foreach ( $query_post_types as $value ) {
				if ( in_array($value, $post_types, true) ) {
					$found = true;
					break;
				}
			}
			if ( ! $found ) {
				return $where;
			}
			// search meta.
			$args = array(
				'post_type' => $query_post_types,
				'post_status' => $query->get('post_status'),
				'posts_per_page' => -1,
				'no_found_rows' => true,
				'nopaging' => true,
				'ignore_sticky_posts' => true,
				'orderby' => 'modified',
				'suppress_filters' => false,
				'meta_query' => array(
					'relation' => 'AND',
					array(
						'key'     => $this->postmeta_key,
						'compare' => 'EXISTS',
					),
					array(
						'key'     => $this->postmeta_key,
						'compare' => 'LIKE',
						'value'   => $query->get('s'),
					),
				),
				'fields' => 'ids',
			);
			$posts_tmp = get_posts($args);
			if ( empty($posts_tmp) || is_wp_error($posts_tmp) ) {
				return $where;
			}
			// change where.
			global $wpdb;
			$where = preg_replace("/^( AND \()(.+)$/s", "$1 ($2 ) OR {$wpdb->posts}.ID IN (" . implode(',', $posts_tmp) . ')', $where, 1);
			return $where;
		}

		public function get_adjacent_post_where( $where = '', $in_same_term = false, $excluded_terms = '', $taxonomy = 'category', $post = 0 ) {
			if ( ! empty($in_same_term) || ! empty($excluded_terms) ) {
				return $where;
			}
			if ( empty($post) || ! is_object($post) ) {
				return $where;
			}
			if ( strpos($where, 'post_type') === false ) {
				return $where;
			}
			$post_types_adjacent = $this->get_options_context('db', 'post_types_adjacent');
			if ( ! in_array($post->post_type, $post_types_adjacent, true) ) {
				return $where;
			}
			// only change links for posts with event data.
			$postmeta = $this->post_has_event_data($post->ID);
			if ( $postmeta === false ) {
				return $where;
			}
			if ( $arr = $this->get_events_by_post_type($post->post_type) ) {
				$ids = array_column($arr, 'ID');
				$position = array_search($post->ID, $ids, true);
				if ( $position !== false ) {
					$position = (int) $position;
					if ( current_filter() === 'get_previous_post_where' ) {
						if ( $position === 0 ) {
							$where .= ! empty($where) ? ' AND 1=2' : 'WHERE 1=2';
						} else {
							$where = 'WHERE p.ID = ' . $ids[ $position - 1 ];
						}
					} elseif ( current_filter() === 'get_next_post_where' ) {
						if ( $position === ( count($ids) - 1 ) ) {
							$where .= ! empty($where) ? ' AND 1=2' : 'WHERE 1=2';
						} else {
							$where = 'WHERE p.ID = ' . $ids[ $position + 1 ];
						}
					}
				}
			}
			return $where;
		}

		/* functions */

		protected function get_options_default() {
			return apply_filters(static::$prefix . '_options_default',
				array(
					'active' => false,
					'post_types' => array(),
					'post_types_custom_column' => array(),
					'post_types_adjacent' => array(),
					'post_types_post_date' => array(),
					'include_post_date' => true,
					'include_post_modified' => false,
					'maps_provider' => 'openstreetmap',
					'maps_google_api_key' => '',
					'maps_load_startup' => false,
				)
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
			if ( strpos($language, '-') !== false ) {
				list($language, $tmp) = explode('-', strtolower($language), 2);
			}
			return $language;
		}

		public function get_post_event_time( $post_id ) {
			$postmeta = $this->get_postmeta($post_id, $this->postmeta_key_time);
			if ( empty($postmeta) ) {
				return false;
			}
			return $postmeta;
		}

		public function post_has_event_data( $post_id, $required_fields = array( 'date_start' ), $postmeta = null ) {
			if ( is_null($postmeta) ) {
				$postmeta = $this->get_postmeta($post_id, $this->postmeta_key);
			}
			if ( empty($postmeta) ) {
				return false;
			}
			foreach ( $this->make_array($required_fields) as $value ) {
				if ( ! isset($postmeta[ $value ]) ) {
					return false;
				}
				if ( empty($postmeta[ $value ]) ) {
					return false;
				}
			}
			return $postmeta;
		}

		private function post_has_event_location( $postmeta = array() ) {
			if ( isset($postmeta['geo_address']) ) {
				if ( ! empty($postmeta['geo_address']) ) {
					return true;
				}
			}
			if ( isset($postmeta['geo_latitude']) && isset($postmeta['geo_longitude']) ) {
				if ( ! empty($postmeta['geo_latitude']) && ! empty($postmeta['geo_longitude']) ) {
					return true;
				}
			}
			return false;
		}

		private function get_events_by_post_type( $post_type = '' ) {
			if ( empty($post_type) ) {
				return false;
			}
			if ( ! isset($this->events_by_post_type) ) {
				$this->events_by_post_type = array();
			}
			if ( array_key_exists($post_type, $this->events_by_post_type) ) {
				return $this->events_by_post_type[ $post_type ];
			}
			$res = false;
			$args = array(
				'post_type' => $post_type,
				'post_status' => array( 'publish', 'inherit' ),
				'posts_per_page' => -1,
				'no_found_rows' => true,
				'nopaging' => true,
				'ignore_sticky_posts' => true,
				'orderby' => 'modified',
				'suppress_filters' => false,
				'meta_query' => array(
					'relation' => 'AND',
					array(
						'key'     => $this->postmeta_key,
						'compare' => 'EXISTS',
					),
					array(
						'key'     => $this->postmeta_key,
						'compare' => 'LIKE',
						'value'   => 'date_start',
					),
				),
				'fields' => 'ids',
			);
			$posts = get_posts($args);
			if ( empty($posts) || is_wp_error($posts) ) {
				$this->events_by_post_type[ $post_type ] = $res;
				return $res;
			}
			$items = array();
			foreach ( $posts as $post_id ) {
				if ( $postmeta = $this->post_has_event_data($post_id) ) {
					$items[] = array_merge(array( 'ID' => $post_id ), $postmeta);
				}
			}
			if ( ! empty($items) ) {
				$date_start = array_column($items, 'date_start');
				$date_start = array_map('strtotime', $date_start);
				array_multisort($date_start, SORT_ASC, SORT_NUMERIC, $items);
				$res = $items;
			}
			$this->events_by_post_type[ $post_type ] = $res;
			return $res;
		}

		public function enqueue_style( $handle, $src = '', $deps = array(), $ver = null, $media = 'all' ) {
			global $wp_styles;
			// update registered array if different src or deps.
			if ( wp_style_is($handle, 'enqueued') || wp_style_is($handle, 'registered') ) {
				if ( isset($wp_styles->registered[ $handle ]) ) {
					if ( $wp_styles->registered[ $handle ]->src !== $src ) {
						$wp_styles->registered[ $handle ]->src = $src;
					}
					if ( $wp_styles->registered[ $handle ]->deps !== $deps ) {
						$wp_styles->registered[ $handle ]->deps = $deps;
					}
				}
				if ( ! wp_style_is($handle, 'enqueued') ) {
					wp_enqueue_style($handle, $src, $deps, $ver, $media);
				}
				return $handle;
			}
			// check if same filename is enqueued.
			$queue = $this->make_array($wp_styles->queue);
			$done = $this->make_array($wp_styles->done);
			$check = array_merge($queue, $done);
			$check = array_unique($check);
			$basename = basename($src);
			foreach ( $check as $value ) {
				if ( isset($wp_styles->registered[ $value ]) ) {
					if ( basename($wp_styles->registered[ $value ]->src) === $basename ) {
						return $value;
					}
				}
			}
			wp_enqueue_style($handle, $src, $deps, $ver, $media);
			return $handle;
		}

		private function enqueue_script( $handle, $src = '', $deps = array(), $ver = null, $in_footer = true ) {
			// default: $in_footer = false.
			global $wp_scripts;
			// update registered array if different src or deps.
			if ( wp_script_is($handle, 'enqueued') || wp_script_is($handle, 'registered') ) {
				if ( isset($wp_scripts->registered[ $handle ]) ) {
					if ( $wp_scripts->registered[ $handle ]->src !== $src ) {
						$wp_scripts->registered[ $handle ]->src = $src;
					}
					if ( $wp_scripts->registered[ $handle ]->deps !== $deps ) {
						$wp_scripts->registered[ $handle ]->deps = $deps;
					}
				}
				if ( ! wp_script_is($handle, 'enqueued') ) {
					wp_enqueue_script($handle, $src, $deps, $ver, $in_footer);
					if ( $in_footer && ! in_array($handle, $wp_scripts->in_footer, true) ) {
						$wp_scripts->in_footer[] = $handle;
					}
				}
				return $handle;
			}
			// check if same filename is enqueued.
			$queue = $this->make_array($wp_scripts->queue);
			$done = $this->make_array($wp_scripts->done);
			$check = array_merge($queue, $done);
			$check = array_unique($check);
			$basename = basename($src);
			foreach ( $check as $value ) {
				if ( isset($wp_scripts->registered[ $value ]) ) {
					if ( basename($wp_scripts->registered[ $value ]->src) === $basename ) {
						return $value;
					}
				}
			}
			wp_enqueue_script($handle, $src, $deps, $ver, $in_footer);
			return $handle;
		}

		public function enqueue_event_scripts( $style_or_script = 'all' ) {
			// css.
			if ( $style_or_script === 'all' || $style_or_script === 'css' ) {
				if ( wp_style_is(static::$prefix . '-event', 'enqueued') || wp_style_is(static::$prefix . '-event', 'registered') ) {
					return;
				}
				$this->enqueue_style(static::$prefix . '-event', plugins_url('/assets/css/event.css', __FILE__), array(), $this->get_plugin_version());
			}
			// js.
			if ( $style_or_script === 'all' || $style_or_script === 'js' ) {
				if ( wp_script_is(static::$prefix . '-event', 'enqueued') || wp_script_is(static::$prefix . '-event', 'registered') ) {
					return;
				}
				if ( empty($this->get_options_context('db', 'maps_provider')) ) {
					return;
				}
				$maps_provider = $this->get_options_context('db', 'maps_provider');

				// plugin js.
				$this->enqueue_script(static::$prefix . '-event', plugins_url('/assets/js/event' . ( ! ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ) ? '.min' : '' ) . '.js', __FILE__), array( 'jquery' ), $this->get_plugin_version(), true);
				$data = array(
					'prefix' => static::$prefix,
					'maps_provider' => $maps_provider,
					'maps_load_startup' => $this->get_options_context('db', 'maps_load_startup'),
				);

				// openstreetmap.
				if ( $maps_provider === 'openstreetmap' ) {
					$data['openstreetmap_src'] = esc_url(set_url_scheme('http://www.openstreetmap.org') . '/export/embed.html');
				}

				wp_localize_script(static::$prefix . '-event', 'eventcalendar', $data);

				// google js.
				if ( $maps_provider === 'google' ) {
					$args = array(
						'key' => $this->get_options_context('db', 'maps_google_api_key'),
						'language' => $this->get_language(),
						'callback' => static::$prefix . '_init',
					);
					$script = add_query_arg($args, set_url_scheme('http://maps.googleapis.com') . '/maps/api/js');
					$js_handle_googlemaps = $this->enqueue_script(static::$prefix . '-googlemaps', $script, array( static::$prefix . '-event' ));
				}
			}
		}

		public function get_geo_search( $q = '', $limit = 4 ) {
			if ( empty($q) ) {
				return false;
			}
			$transient_name = static::$prefix . '_geo_' . $q;
			// look for old query.
			$val = $this->get_transient($transient_name);
			if ( ! empty($val) ) {
				return $val;
			}
			// make a new query.
			$url = set_url_scheme('http://nominatim.openstreetmap.org') . '/search?q=' . rawurlencode($q) . '&format=json&limit=' . $limit . '&accept-language=' . $this->get_language();
			// use user_agent when available.
			$user_agent = $this->plugin_title;
			if ( isset($_SERVER['HTTP_USER_AGENT']) && ! empty($_SERVER['HTTP_USER_AGENT']) ) {
				$user_agent = $_SERVER['HTTP_USER_AGENT'];
			}
			$options = array( 'http' => array( 'user_agent' => $user_agent ) );
			$context = stream_context_create($options);
			$val = @file_get_contents($url, false, $context);
			if ( $val === false || ( is_string($val) && trim($val) === '' ) ) {
				return false;
			}
			$this->set_transient($transient_name, $val, 30 * DAY_IN_SECONDS);
			return $val;
		}

		private function wp_query_orderby_date_start( $query ) {
			$arr = array(
				'meta_query' => array(
					'relation' => 'OR',
					array(
						'key' => $this->postmeta_key_time,
						'compare' => 'NOT EXISTS',
						// must be before 'EXISTS'!
					),
					array(
						'key' => $this->postmeta_key_time,
						'compare' => 'EXISTS',
					),
				),
				'orderby' => 'meta_value_num date',
			);
			foreach ( $arr as $key => $value ) {
				$query->set($key, $value);
			}
			if ( $query->get('order') === '' ) {
				$query->set('order', 'DESC');
			}
			return $query;
		}

		/* functions-common */

		private function strip_all_shortcodes( $str = '' ) {
			if ( function_exists(__FUNCTION__) ) {
				$func = __FUNCTION__;
				return $func($str);
			}
			$go = true;
			// inline scripts.
			if ( strpos($str, '<script') !== false ) {
				$go = false;
			}
			// [] inside html tag.
			if ( $go && preg_match("/<[a-z]+ [^>\[\]]+\[[^>]+>/is", $str) ) {
				$go = false;
			}
			if ( $go ) {
				// more than 4 letters.
				$str = preg_replace("/\[[^\]]{5,}\]/is", '', $str);
			}
			return $str;
		}

		private function trim_quotes( $str = '' ) {
			if ( function_exists(__FUNCTION__) ) {
				$func = __FUNCTION__;
				return $func($str);
			}
			if ( is_string($str) ) {
				$str = trim($str, " \n\r\t\v\0'" . '"');
			} elseif ( is_array($str) ) {
				$str = array_map(array( $this, __FUNCTION__ ), $str);
			}
			return $str;
		}
	}

	// Load the plugin.
	Halftheory_Event_Calendar::get_instance(true, plugin_basename(__FILE__));
endif;
