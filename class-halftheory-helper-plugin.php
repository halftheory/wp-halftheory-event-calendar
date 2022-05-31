<?php
/*
Available filters:
{static::$prefix}_activation(string $db_prefix, class $instance)
{static::$prefix}_deactivation(string $db_prefix, class $instance)
{static::$prefix}_uninstall(string $db_prefix, class $instance)
halftheory_admin_menu_parent(string $name)
{static::$prefix}_admin_menu_parent(string $name)
{static::$prefix}_register_post_type(array $args, string $post_type)
{static::$prefix}_register_taxonomy(array $args, string $taxonomy, array $object_type)
{static::$prefix}_options_default(array $options)
*/

// Exit if accessed directly.
defined('ABSPATH') || exit;

if ( ! class_exists('Halftheory_Helper_Plugin', false) ) :
	class Halftheory_Helper_Plugin {

		protected static $instance;
		public static $prefix = 'halftheory';
		public $options = array();
		public $postmeta = array();
		public $menu_page_tabs = array();
		public $menu_page_tab_active = '';

		/* setup */

		public function __construct( $load_actions = false, $plugin_basename = null, $prefix = null ) {
			$this->setup_globals($plugin_basename, $prefix);
			if ( $load_actions === true ) {
				$this->setup_actions();
			}
		}

		final private function __clone() {
		}

		final public static function get_instance( $load_actions = false, $plugin_basename = null, $prefix = null ) {
			if ( ! isset(static::$instance) || $load_actions === true ) {
				static::$instance = new static($load_actions, $plugin_basename, $prefix);
			}
			return static::$instance;
		}

		protected function setup_globals( $plugin_basename = null, $prefix = null ) {
			$this->plugin_name = get_called_class();
			$this->plugin_title = preg_replace("/^Halftheory[^A-Za-z0-9]*/", '', $this->plugin_name);
			$this->plugin_title = ucwords(str_replace('_', ' ', $this->plugin_title));
			if ( ! empty($plugin_basename) ) {
				$this->plugin_basename = $plugin_basename;
			} else {
				$this->plugin_basename = plugin_basename(__FILE__);
			}
			if ( ! empty($prefix) ) {
				static::$prefix = $prefix;
			} else {
				static::$prefix = preg_replace("/^Halftheory[^A-Za-z0-9]*/", '', $this->plugin_name);
			}
			static::$prefix = preg_replace("/[^a-z0-9]/", '', sanitize_key(static::$prefix));
			$this->options = array();
			$this->postmeta = array();
			if ( isset($this->plugin_is_network) ) {
				unset($this->plugin_is_network);
			}
			if ( isset($this->plugin_version) ) {
				unset($this->plugin_version);
			}
			// Only on our menu_page.
			if ( $this->is_menu_page() ) {
				/*
				// child class set in setup_globals.
				$this->menu_page_tabs = array(
					'' => array(
						'name' => __('Settings'),
						'callback' => 'menu_page',
					),
				);
				*/
				$this->menu_page_tab_active = isset($_GET['tab']) ? $_GET['tab'] : '';
			}
		}

		protected function setup_actions() {
			// plugin activation/deactivation.
			$plugin_file = $this->get_plugin_file();
			register_activation_hook($plugin_file, array( $this, 'plugin_activation' ));
			register_deactivation_hook($plugin_file, array( $this, 'plugin_deactivation' ));
			register_uninstall_hook($plugin_file, array( $this->plugin_name, 'plugin_uninstall' ));
			unset($plugin_file);

			// admin options.
			if ( ! $this->is_front_end() ) {
				if ( $this->is_plugin_network() ) {
					add_action('network_admin_menu', array( $this, 'admin_menu' ));
					if ( is_main_site() ) {
						add_action('admin_menu', array( $this, 'admin_menu' ));
					}
				} else {
					add_action('admin_menu', array( $this, 'admin_menu' ));
				}
			}
		}

		public function plugin_activation( $network_wide ) {
			apply_filters(static::$prefix . '_activation', static::$prefix, static::$instance);
		}

		public function plugin_deactivation( $network_wide ) {
			apply_filters(static::$prefix . '_deactivation', static::$prefix, static::$instance);
		}

		public static function plugin_uninstall() {
			apply_filters(static::$prefix . '_uninstall', static::$prefix, static::$instance);
		}

		/* admin */

		public function admin_notices() {
			// This provides an easy way of returning notices after forms are submitted, but you must insert the following in the child theme:
			// add_action('admin_notices', array( $this, 'admin_notices' ));
			// add_action('network_admin_notices', array( $this, 'admin_notices' ));
			global $current_screen;
			if ( empty($current_screen) ) {
				return;
			}
			if ( ! is_object($current_screen) ) {
				return;
			}
			if ( $arr = $this->get_transient(static::$prefix . '_admin_notices') ) {
				$this->delete_transient(static::$prefix . '_admin_notices');
				foreach ( $arr as $value ) {
					$classes = array( 'notice' );
					if ( $value['class'] ) {
						$classes[] = 'notice-' . str_replace('notice-', '', $value['class']);
					}
					if ( $value['is_dismissible'] ) {
						$classes[] = 'is-dismissible';
					}
					echo '<div class="' . esc_attr(implode(' ', $classes)) . '"><p>' . $value['message'] . '</p></div>' . "\n";
				}
			}
		}

		public function admin_menu() {
			if ( empty(static::$prefix) ) {
				return;
			}
			if ( ! is_array($GLOBALS['menu']) ) {
				return;
			}

			$has_parent = false;
			$parent_slug = static::$prefix;
			$parent_name = apply_filters('halftheory_admin_menu_parent', 'Halftheory');
			$parent_name = apply_filters(static::$prefix . '_admin_menu_parent', $parent_name);

			// set parent to nothing to skip parent menu creation.
			if ( empty($parent_name) ) {
				add_options_page(
					$this->plugin_title,
					$this->plugin_title,
					'manage_options',
					static::$prefix,
					array( $this, 'menu_page' )
				);
				return;
			}

			// find top level menu if it exists.
			foreach ( $GLOBALS['menu'] as $value ) {
				if ( $value[0] === $parent_name ) {
					$parent_slug = $value[2];
					$has_parent = true;
					break;
				}
			}

			// add top level menu if it doesn't exist.
			if ( ! $has_parent ) {
				add_menu_page(
					$this->plugin_title,
					$parent_name,
					'manage_options',
					$parent_slug,
					array( $this, 'menu_page' )
				);
			}

			// add the menu.
			add_submenu_page(
				$parent_slug,
				$this->plugin_title,
				$this->plugin_title,
				'manage_options',
				static::$prefix,
				array( $this, 'menu_page' )
			);
		}

		public function menu_page() {
			$plugin = static::$instance;

			// Redirect to tab functions.
			if ( $plugin->load_menu_page_tab() ) {
				return;
			}

			global $title;
			?>
			<div class="wrap">
			<h2><?php echo esc_html($title); ?></h2>

			<?php
			if ( $plugin->save_menu_page(__FUNCTION__) ) {
				// save.
			}

			// Show the form.
			$options = $plugin->get_options_context('admin_form');
			?>

			<?php $plugin->print_menu_page_tabs(); ?>

			<form id="<?php echo esc_attr($plugin::$prefix); ?>-admin-form" name="<?php echo esc_attr($plugin::$prefix); ?>-admin-form" method="post" action="<?php echo esc_attr($_SERVER['REQUEST_URI']); ?>">
			<?php
			// Use nonce for verification.
			wp_nonce_field($plugin->plugin_basename, $plugin->plugin_name . '::' . __FUNCTION__);
			?>
			<div id="poststuff">

			<?php submit_button(__('Update'), array( 'primary', 'large' ), 'save'); ?>

			</div><!-- poststuff -->
			</form>

			</div><!-- wrap -->
			<?php
		}

		public function menu_page_tab( $plugin ) {
			global $title;
			?>
			<div class="wrap">
			<h2><?php echo $title; ?></h2>

			<?php
			if ( $plugin->save_menu_page(__FUNCTION__) ) {
				// save.
			}
			?>

			<?php $plugin->print_menu_page_tabs(); ?>

			<form id="<?php echo esc_attr($plugin::$prefix); ?>-admin-form" name="<?php echo esc_attr($plugin::$prefix); ?>-admin-form" method="post" action="<?php echo esc_attr($_SERVER['REQUEST_URI']); ?>">
			<?php
			// Use nonce for verification.
			wp_nonce_field($plugin->plugin_basename, $plugin->plugin_name . '::' . __FUNCTION__);
			?>
			<div id="poststuff">

			<?php submit_button(__('Update'), array( 'primary', 'large' ), 'save'); ?>

			</div><!-- poststuff -->
			</form>

			</div><!-- wrap -->
			<?php
		}

		/* functions */

		public function admin_notice_add( $class = 'success', $message = '', $is_dismissible = null ) {
			if ( ! isset($this->admin_notices) ) {
				$this->admin_notices = array();
			}
			$this->admin_notices[] = array('class' => $class, 'message' => $message, 'is_dismissible' => $is_dismissible);
		}

		public function admin_notices_set() {
			if ( isset($this->admin_notices) ) {
				if ( ! empty($this->admin_notices) ) {
					$this->set_transient(static::$prefix . '_admin_notices', $this->admin_notices, '1 hour');
					return;
				}
			}
			$this->delete_transient(static::$prefix . '_admin_notices');
		}

		private function get_plugin_file() {
			$res = __FILE__;
			if ( isset($this->plugin_basename) ) {
				if ( strpos($this->plugin_basename, WP_PLUGIN_DIR) === false && strpos($this->plugin_basename, WPMU_PLUGIN_DIR) === false ) {
					$res = WP_PLUGIN_DIR . '/' . ltrim($this->plugin_basename, '/ ');
				} else {
					$res = $this->plugin_basename;
				}
			}
			return $res;
		}

		public function get_plugin_data_field( $plugin_file = null, $field = null ) {
			if ( empty($plugin_file) || empty($field) ) {
				return null; // better to return null rather than false - better for wp_enqueue_scripts etc.
			}
			if ( strpos($plugin_file, WP_PLUGIN_DIR) === false && strpos($plugin_file, WPMU_PLUGIN_DIR) === false ) {
				$plugin_file = WP_PLUGIN_DIR . '/' . ltrim($plugin_file, '/ ');
			}
			if ( ! file_exists($plugin_file) ) {
				return null;
			}
			if ( ! function_exists('get_plugin_data') && is_readable(ABSPATH . 'wp-admin/includes/plugin.php') ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}
			$plugin_data = get_plugin_data($plugin_file);
			if ( ! is_array($plugin_data) ) {
				return null;
			}
			if ( ! isset($plugin_data[ $field ]) ) {
				return null;
			}
			return $plugin_data[ $field ];
		}

		public function get_plugin_version( $plugin_file = null ) {
			$this_plugin = is_null($plugin_file);
			if ( $this_plugin ) {
				if ( property_exists($this, 'plugin_version') ) {
					return $this->plugin_version;
				}
				$plugin_file = $this->get_plugin_file();
			}
			$res = $this->get_plugin_data_field($plugin_file, 'Version');
			if ( $this_plugin ) {
				$this->plugin_version = $res;
			}
			return $res;
		}

		public function is_menu_page( $str = null ) {
			if ( ! $this->is_front_end() ) {
				if ( empty($str) ) {
					$str = static::$prefix;
				}
				global $current_screen;
				if ( is_object($current_screen) ) {
					if ( strpos($current_screen->id, $str) !== false ) {
						return true;
					}
				} elseif ( isset($_SERVER['QUERY_STRING']) ) {
					if ( strpos($_SERVER['QUERY_STRING'], $str) !== false ) {
						return true;
					}
				}
			}
			return false;
		}

		public function is_edit_screen( $post_types = array() ) {
			if ( ! function_exists('get_current_screen') ) {
				return false;
			}
			if ( ! is_object(get_current_screen()) ) {
				return false;
			}
			if ( strpos(get_current_screen()->id, 'edit-') === false ) {
				return false;
			} elseif ( strpos(get_current_screen()->id, 'edit-') !== false && empty($post_types) ) {
				return true;
			}
			if ( ! empty($post_types) ) {
				$post_types = $this->make_array($post_types);
				foreach ( $post_types as $post_type ) {
					if ( get_current_screen()->id === 'edit-' . $post_type ) {
						return true;
					}
				}
			}
			return false;
		}

		public function save_menu_page( $function_name = 'menu_page', $post_key = 'save' ) {
			if ( ! isset($_POST[ $post_key ]) ) {
				return false;
			}
			if ( empty($_POST[ $post_key ]) ) {
				return false;
			}
			// verify this came from the our screen and with proper authorization.
			if ( ! isset($_POST[ $this->plugin_name . '::' . $function_name ]) ) {
				return false;
			}
			if ( ! wp_verify_nonce($_POST[ $this->plugin_name . '::' . $function_name ], $this->plugin_basename) ) {
				return false;
			}
			return true;
		}

		public function load_menu_page_tab() {
			if ( ! empty($this->menu_page_tabs) && ! empty($this->menu_page_tab_active) ) {
				if ( isset($this->menu_page_tabs[ $this->menu_page_tab_active ]) ) {
					if ( isset($this->menu_page_tabs[ $this->menu_page_tab_active ]['callback']) ) {
						if ( is_string($this->menu_page_tabs[ $this->menu_page_tab_active ]['callback']) ) {
							if ( method_exists($this, $this->menu_page_tabs[ $this->menu_page_tab_active ]['callback']) ) {
								$callback = $this->menu_page_tabs[ $this->menu_page_tab_active ]['callback'];
								$this->$callback($this);
								return true;
							} elseif ( function_exists($this->menu_page_tabs[ $this->menu_page_tab_active ]['callback']) ) {
								$callback = $this->menu_page_tabs[ $this->menu_page_tab_active ]['callback'];
								$callback($this);
								return true;
							}
						} elseif ( is_array($this->menu_page_tabs[ $this->menu_page_tab_active ]['callback']) ) {
							if ( is_callable($this->menu_page_tabs[ $this->menu_page_tab_active ]['callback']) ) {
								$callback = $this->menu_page_tabs[ $this->menu_page_tab_active ]['callback'];
								$callback($this);
								return true;
							}
						}
					}
				}
			}
			return false;
		}

		public function print_menu_page_tabs() {
			if ( ! empty($this->menu_page_tabs) ) :
				?>
				<h2 class="nav-tab-wrapper">
					<?php
					global $pagenow;
					foreach ( $this->menu_page_tabs as $key => $value ) {
						if ( ! isset($value['name']) || empty($value['name']) ) {
							continue;
						}
						if ( empty($key) ) {
							echo '<a class="nav-tab' . esc_attr($this->menu_page_tab_active === $key ? ' nav-tab-active' : '') . '" href="' . esc_url( admin_url($pagenow . '?page=' . static::$prefix) ) . '">' . $value['name'] . '</a> ';
						} else {
							echo '<a class="nav-tab' . esc_attr($this->menu_page_tab_active === $key ? ' nav-tab-active' : '') . '" href="' . esc_url( admin_url($pagenow . '?page=' . static::$prefix . '&tab=' . $key) ) . '">' . $value['name'] . '</a> ';
						}
					}
					?>
				</h2>
				<?php
			endif;
		}

		public function is_plugin_network() {
			if ( isset($this->plugin_is_network) ) {
				return $this->plugin_is_network;
			}
			$res = false;
			if ( is_multisite() ) {
				if ( ! function_exists('is_plugin_active_for_network') && is_readable(ABSPATH . '/wp-admin/includes/plugin.php') ) {
					require_once ABSPATH . '/wp-admin/includes/plugin.php';
				}
				if ( function_exists('is_plugin_active_for_network') ) {
					if ( is_plugin_active_for_network($this->plugin_basename) ) {
						$res = true;
					}
				}
			}
			$this->plugin_is_network = $res;
			return $res;
		}

		public function get_template_types() {
			// https://developer.wordpress.org/reference/functions/get_query_template/
			$types = array( 'index', '404', 'archive', 'author', 'category', 'tag', 'taxonomy', 'date', 'embed', 'home', 'frontpage', 'privacypolicy', 'page', 'paged', 'search', 'single', 'singular', 'attachment' );
			return $types;
		}

		public function get_template_tags() {
			$tag_templates = array(
				'is_embed'             => 'get_embed_template',
				'is_404'               => 'get_404_template',
				'is_search'            => 'get_search_template',
				'is_front_page'        => 'get_front_page_template',
				'is_home'              => 'get_home_template',
				'is_privacy_policy'    => 'get_privacy_policy_template',
				'is_post_type_archive' => 'get_post_type_archive_template',
				'is_tax'               => 'get_taxonomy_template',
				'is_attachment'        => 'get_attachment_template',
				'is_single'            => 'get_single_template',
				'is_page'              => 'get_page_template',
				'is_singular'          => 'get_singular_template',
				'is_category'          => 'get_category_template',
				'is_tag'               => 'get_tag_template',
				'is_author'            => 'get_author_template',
				'is_date'              => 'get_date_template',
				'is_archive'           => 'get_archive_template',
			);
			return $tag_templates;
		}

		public function get_template( $wp_query = null ) {
			// adapted from wp-includes/template-loader.php
			// try to respect the WordPress Template Hierarchy - https://wphierarchy.com/
			$template = false;
			// Loop through each of the template conditionals, and find the appropriate template file.
			foreach ( $this->get_template_tags() as $tag => $template_getter ) {
				if ( is_object($wp_query) ) {
					if ( $wp_query->$tag ) {
						$template = call_user_func($template_getter);
					}
				} elseif ( call_user_func($tag) ) {
					$template = call_user_func($template_getter);
				}
				if ( $template ) {
					break;
				}
			}
			if ( ! $template ) {
				$template = get_index_template();
			}
			$template = apply_filters('template_include', $template);
			return $template;
		}

		public function add_shortcode_wpautop_control( $shortcode = 'code', $actions = array( 'the_content', 'the_excerpt', 'widget_text_content' ) ) {
			// see: https://github.com/chiedolabs/shortcode-wpautop-control/blob/master/shortcode-wpautop-control.php
			// this function should be called by the 'init' action or later.
			if ( ! shortcode_exists($shortcode) ) {
				return;
			}

			$func_action = function ( $str = '' ) use ( $shortcode ) {
				if ( ! has_shortcode($str, $shortcode) ) {
					return $str;
				}
				remove_filter(current_filter(), 'wpautop');
				$parts_old = preg_split('/' . get_shortcode_regex(array( $shortcode )) . '/is', $str, -1, PREG_SPLIT_NO_EMPTY);
				$parts_new = array_map('trim', $parts_old);
				$parts_new = array_map('wpautop', $parts_new);
				$str = strtr($str, array_combine($parts_old, $parts_new));
				return $str;
			};

			$actions = $this->make_array($actions);
			foreach ( $actions as $action ) {
				$priority_do_shortcode = has_filter($action, 'do_shortcode');
				if ( $priority_do_shortcode === false ) {
					continue;
				}
				$priority_wpautop = has_filter($action, 'wpautop');
				if ( $priority_wpautop === false ) {
					continue;
				}
				// usual priority: wpautop (10) do_shortcode (11), so we need to get ahead of both to change them.
				$priority_before = intval( max( ( min( $priority_do_shortcode - 1, $priority_wpautop - 1 ) ), 0) ); // 9?
				add_filter($action, $func_action, $priority_before);
			}
		}

		public function check_wp_query_args( $args = array() ) {
			// replace common field errors, make arrays where needed.
			$fields = array(
				'replace' => array(
					'ID' => 'p',
					'id' => 'p',
					'post_id' => 'p',
					'type' => 'post_type',
					'post_name' => 'name',
					'search' => 's',
					'category' => 'category_name',
				),
				'is_array' => array(
					'author__in',
					'author__not_in',
					'category__and',
					'category__in',
					'category__not_in',
					'tag__and',
					'tag__in',
					'tag__not_in',
					'tag_slug__and',
					'tag_slug__in',
					'post_parent__in',
					'post_parent__not_in',
					'post__in',
					'post__not_in',
					'post_name__in',
					'post_type',
				),
				'query' => array(
					'tax_query',
					'meta_query',
					'date_query',
				),
			);
			foreach ( $args as $key => $value ) {
				if ( isset($fields['replace'][ $key ]) ) {
					unset($args[ $key ]);
					$key = $fields['replace'][ $key ];
					$args[ $key ] = $value;
				}
				if ( in_array($key, $fields['is_array'], true) ) {
					$args[ $key ] = $this->make_array($value);
				} elseif ( in_array($key, $fields['query'], true) ) {
					$args[ $key ] = $this->make_array($value);
					// if no arrays found, nest inside next level array.
					$found = false;
					foreach ( $args[ $key ] as $v ) {
						if ( is_array($v) ) {
							$found = true;
							break;
						}
					}
					if ( ! $found ) {
						$args[ $key ] = array( $args[ $key ] );
					}
				}
			}
			// if the blog is switched we may need to add the post_types, taxonomies.
			if ( is_multisite() && ms_is_switched() ) {
				$object_type = null;
				if ( isset($args['post_type']) ) {
					$object_type = $args['post_type'];
					$this->register_post_type($args['post_type']);
				}
				if ( isset($args['tax_query']) && is_array($args['tax_query']) ) {
					$arr = array();
					foreach ( $args['tax_query'] as $value ) {
						if ( ! is_array( $value ) ) {
							continue;
						}
						if ( isset($value['taxonomy']) ) {
							$arr[] = $value['taxonomy'];
						}
					}
					$this->register_taxonomy($arr, $object_type);
				}
			}
			return $args;
		}

		public function register_post_type( $post_type, $args = null ) {
			$res = array();
			foreach ( $this->make_array($post_type) as $value ) {
				if ( $value === 'any' ) {
					$res[ $value ] = false;
					continue;
				}
				$res[ $value ] = post_type_exists($value);
				if ( ! $res[ $value ] ) {
					if ( is_null($args) ) {
						$plural_name = rtrim($value, 's') . 's';
						$post_type_args = array(
							'public' => true,
							'show_ui' => false,
							'show_in_nav_menus' => false,
							'show_in_rest' => false,
							'has_archive' => true,
							'hierarchical' => true,
							'query_var' => false,
							'rewrite' => array(
								'slug' => $plural_name,
							),
							'labels' => array(
								'name' => ucfirst($plural_name),
								'singular_name' => ucfirst($value),
							),
						);
					} else {
						$post_type_args = $this->make_array($args);
					}
					$post_type_args = apply_filters(static::$prefix . '_register_post_type', $post_type_args, $value);
					$res[ $value ] = register_post_type($value, $post_type_args);
				}
			}
			return $res;
		}

		public function register_taxonomy( $taxonomy, $object_type = null, $args = null ) {
			$res = array();
			if ( is_null($object_type) ) {
				$object_type = get_post_types(array( 'public' => true ), 'names');
				global $typenow;
				if ( ! empty($typenow) && ! in_array($typenow, $object_type, true) ) {
					$object_type[] = $typenow;
				}
			} else {
				$object_type = $this->make_array($object_type);
			}
			foreach ( $this->make_array($taxonomy) as $value ) {
				$res[ $value ] = taxonomy_exists($value);
				if ( ! $res[ $value ] ) {
					if ( is_null($args) ) {
						$args = array(
							'description' => $value,
							'public' => true,
							'show_ui' => false,
							'show_in_nav_menus' => false,
							'show_in_rest' => false,
							'hierarchical' => true,
							'query_var' => false,
							'rewrite' => false,
						);
					} else {
						$args = $this->make_array($args);
					}
					$args = apply_filters(static::$prefix . '_register_taxonomy', $args, $value, $object_type);
					$res[ $value ] = register_taxonomy($value, $object_type, $args);
				}
			}
			return $res;
		}

		protected function get_options_default() {
			return apply_filters(static::$prefix . '_options_default',
				array(
					'active' => false,
				)
			);
		}

		public function get_options_context( $context = 'db', $key = null, $default = null, $input = array() ) {
			$options_default = (array) $this->get_options_default();
			// default handling.
			if ( is_null($default) ) {
				if ( ! $this->empty_notzero($key) && array_key_exists($key, $options_default) ) {
					$default = $options_default[ $key ];
				} elseif ( $this->empty_notzero($key) ) {
					$default = array();
				}
			}

			// data type checking.
			$func_data_check = function ( $res, $check = array( 'bool', 'int', 'float', 'array' ) ) use ( $key, $options_default ) {
				if ( is_array($res) ) {
					foreach ( $options_default as $k => $v ) {
						if ( array_key_exists($k, $res) ) {
							if ( in_array('bool', $check, true) && is_bool($v) && ! is_bool($res[ $k ]) ) {
								$res[ $k ] = $this->is_true($res[ $k ]);
							} elseif ( in_array('int', $check, true) && is_int($v) && ! is_int($res[ $k ]) ) {
								$res[ $k ] = (int) $res[ $k ];
							} elseif ( in_array('float', $check, true) && is_float($v) && ! is_float($res[ $k ]) ) {
								$res[ $k ] = (float) $res[ $k ];
							} elseif ( in_array('array', $check, true) && is_array($v) && ! is_array($res[ $k ]) ) {
								$res[ $k ] = $this->make_array($res[ $k ]);
							}
						}
					}
				} elseif ( ! $this->empty_notzero($key) && array_key_exists($key, $options_default) ) {
					if ( in_array('bool', $check, true) && is_bool($options_default[ $key ]) && ! is_bool($res) ) {
						$res = $this->is_true($res);
					} elseif ( in_array('int', $check, true) && is_int($options_default[ $key ]) && ! is_int($res) ) {
						$res = (int) $res;
					} elseif ( in_array('float', $check, true) && is_float($options_default[ $key ]) && ! is_float($res) ) {
						$res = (float) $res;
					} elseif ( in_array('array', $check, true) && is_array($options_default[ $key ]) && ! is_array($res) ) {
						$res = $this->make_array($res);
					}
				}
				return $res;
			};

			$res = $default;
			switch ( $context ) {
				case 'db':
				default:
					$res = $this->get_option(static::$prefix, $key, $default);
					$res = $func_data_check($res);
					break;

				case 'default':
					$res = $options_default;
					break;

				case 'input':
					$res = $func_data_check($input);
					break;

				case 'admin_form':
				case 'admin-form':
					$options_db = $this->get_options_context('db', $key, $default);
					if ( empty($options_db) ) {
						$res = $options_default;
					} else {
						$res = array_merge( array_fill_keys(array_keys($options_default), null), (array) $options_db );
						$res = $func_data_check($res, array( 'array' ));
					}
					break;
			}

			if ( ! $this->empty_notzero($key) && is_array($res) && $context !== 'db' ) {
				if ( array_key_exists($key, $res) ) {
					return $res[ $key ];
				}
				return $default;
			}
			return $res;
		}

		// options
		private function get_option_name( $name = '', $is_network = null ) {
			if ( empty($name) ) {
				$name = static::$prefix;
			}
			if ( is_null($is_network) ) {
				$is_network = $this->is_plugin_network();
			}
			if ( $is_network ) {
				$name = substr($name, 0, 255);
			} else {
				$name = substr($name, 0, 191);
			}
			return $name;
		}
		public function get_option( $name = '', $key = null, $default = array() ) {
			$name = $this->get_option_name($name);
			if ( ! isset($this->options[ $name ]) ) {
				if ( $this->is_plugin_network() ) {
					$option = get_site_option($name, array());
				} else {
					$option = get_option($name, array());
				}
				$this->options[ $name ] = $option;
			}
			if ( ! $this->empty_notzero($key) && is_array($this->options[ $name ]) ) {
				if ( array_key_exists($key, $this->options[ $name ]) ) {
					return $this->options[ $name ][ $key ];
				}
				return $default;
			}
			return $this->options[ $name ];
		}
		public function update_option( $name = '', $value = null ) {
			$name = $this->get_option_name($name);
			if ( $this->is_plugin_network() ) {
				$bool = update_site_option($name, $value);
			} else {
				$bool = update_option($name, $value);
			}
			// cache.
			if ( $bool !== false ) {
				$this->options[ $name ] = $value;
			}
			// is it false because there were no changes?
			if ( $bool === false ) {
				if ( $tmp = $this->get_option($name) ) {
					if ( is_array($tmp) && is_array($value) ) {
						ksort($tmp);
						ksort($value);
					}
					if ( $tmp === $value ) {
						$bool = true;
					}
				}
			}
			return $bool;
		}
		public function delete_option( $name = '' ) {
			$name = $this->get_option_name($name);
			if ( $this->is_plugin_network() ) {
				$bool = delete_site_option($name);
			} else {
				$bool = delete_option($name);
			}
			if ( $bool !== false && isset($this->options[ $name ]) ) {
				unset($this->options[ $name ]);
			}
			return $bool;
		}
		public function delete_option_uninstall( $name = '' ) {
			$name_single = $this->get_option_name($name, false);
			global $wpdb;
			if ( $this->is_plugin_network() ) {
				$name_network = $this->get_option_name($name, true);
				$wpdb->query("DELETE FROM $wpdb->sitemeta WHERE meta_key LIKE '" . $name_network . "%'");
				$sites = get_sites();
				foreach ( $sites as $key => $value ) {
					switch_to_blog($value->blog_id);
					$wpdb->query("DELETE FROM $wpdb->options WHERE option_name LIKE '" . $name_single . "%'");
					restore_current_blog();
				}
			} else {
				$wpdb->query("DELETE FROM $wpdb->options WHERE option_name LIKE '" . $name_single . "%'");
			}
		}

		// transients
		private function get_transient_name( $name = '', $is_network = null ) {
			if ( empty($name) ) {
				$name = static::$prefix;
			}
			if ( is_null($is_network) ) {
				$is_network = $this->is_plugin_network();
			}
			if ( $is_network ) {
				$name = substr($name, 0, 167);
			} else {
				$name = substr($name, 0, 172);
			}
			return $name;
		}
		public function get_transient( $transient = '' ) {
			$transient = $this->get_transient_name($transient);
			if ( $this->is_plugin_network() ) {
				$value = get_site_transient($transient);
			} else {
				$value = get_transient($transient);
			}
			return $value;
		}
		public function set_transient( $transient = '', $value = null, $expiration = 0 ) {
			$transient = $this->get_transient_name($transient);
			if ( is_string($expiration) ) {
				$expiration = strtotime('+' . trim($expiration, ' -+')) - time();
				if ( ! $expiration || $expiration < 0 ) {
					$expiration = 0;
				}
			}
			if ( $this->is_plugin_network() ) {
				$bool = set_site_transient($transient, $value, $expiration);
			} else {
				$bool = set_transient($transient, $value, $expiration);
			}
			// is it false because there were no changes?
			if ( $bool === false ) {
				if ( $tmp = $this->get_transient($transient) ) {
					if ( is_array($tmp) && is_array($value) ) {
						ksort($tmp);
						ksort($value);
					}
					if ( $tmp === $value ) {
						$bool = true;
					}
				}
			}
			return $bool;
		}
		public function delete_transient( $transient = '' ) {
			$transient = $this->get_transient_name($transient);
			if ( $this->is_plugin_network() ) {
				$bool = delete_site_transient($transient);
			} else {
				$bool = delete_transient($transient);
			}
			return $bool;
		}
		public function delete_transient_uninstall( $transient = '' ) {
			$transient_single = $this->get_transient_name($transient, false);
			global $wpdb;
			if ( $this->is_plugin_network() ) {
				$transient_network = $this->get_transient_name($transient, true);
				$wpdb->query("DELETE FROM $wpdb->sitemeta WHERE meta_key LIKE '_site_transient_" . $transient_network . "%' OR meta_key LIKE '_site_transient_timeout_" . $transient_network . "%'");
				$sites = get_sites();
				foreach ( $sites as $key => $value ) {
					switch_to_blog($value->blog_id);
					$wpdb->query("DELETE FROM $wpdb->options WHERE option_name LIKE '_transient_" . $transient_single . "%' OR option_name LIKE '_transient_timeout_" . $transient_single . "%'");
					restore_current_blog();
				}
			} else {
				$wpdb->query("DELETE FROM $wpdb->options WHERE option_name LIKE '_transient_" . $transient_single . "%' OR option_name LIKE '_transient_timeout_" . $transient_single . "%'");
			}
		}

		// postmeta
		private function get_postmeta_name( $name = '' ) {
			if ( empty($name) ) {
				$name = static::$prefix;
			}
			$name = substr($name, 0, 255);
			return $name;
		}
		public function get_postmeta( $post_id = 0, $name = '', $key = null, $default = array() ) {
			$post_id = (int) $post_id;
			$name = $this->get_postmeta_name($name);
			$db_fetch = false;
			if ( ! isset($this->postmeta[ $post_id ]) ) {
				$this->postmeta[ $post_id ] = array();
				$db_fetch = true;
			} else {
				if ( ! isset($this->postmeta[ $post_id ][ $name ]) ) {
					$db_fetch = true;
				}
			}
			if ( $db_fetch ) {
				$this->postmeta[ $post_id ][ $name ] = get_post_meta($post_id, $name, true);
			}
			if ( ! $this->empty_notzero($key) && is_array($this->postmeta[ $post_id ][ $name ]) ) {
				if ( array_key_exists($key, $this->postmeta[ $post_id ][ $name ]) ) {
					return $this->postmeta[ $post_id ][ $name ][ $key ];
				}
				return $default;
			}
			return $this->postmeta[ $post_id ][ $name ];
		}
		public function update_postmeta( $post_id = 0, $name = '', $value = null ) {
			$post_id = (int) $post_id;
			$name = $this->get_postmeta_name($name);
			$bool = update_post_meta($post_id, $name, $value);
			if ( $bool !== false ) {
				if ( ! isset($this->postmeta[ $post_id ]) ) {
					$this->postmeta[ $post_id ] = array();
				}
				$this->postmeta[ $post_id ][ $name ] = $value;
			}
			return $bool;
		}
		public function delete_postmeta( $post_id = 0, $name = '' ) {
			$post_id = (int) $post_id;
			$name = $this->get_postmeta_name($name);
			$bool = delete_post_meta($post_id, $name);
			if ( $bool !== false && isset($this->postmeta[ $post_id ]) ) {
				if ( isset($this->postmeta[ $post_id ][ $name ]) ) {
					unset($this->postmeta[ $post_id ][ $name ]);
				}
			}
			return $bool;
		}
		public function delete_postmeta_uninstall( $name = '' ) {
			$name = $this->get_postmeta_name($name);
			global $wpdb;
			if ( $this->is_plugin_network() ) {
				$sites = get_sites();
				foreach ( $sites as $key => $value ) {
					switch_to_blog($value->blog_id);
					$wpdb->query("DELETE FROM $wpdb->postmeta WHERE meta_key LIKE '" . $name . "%'");
					restore_current_blog();
				}
			} else {
				$wpdb->query("DELETE FROM $wpdb->postmeta WHERE meta_key LIKE '" . $name . "%'");
			}
		}

		// usermeta
		private function get_usermeta_name( $name = '' ) {
			if ( empty($name) ) {
				$name = static::$prefix;
			}
			$name = substr($name, 0, 255);
			return $name;
		}
		public function delete_usermeta_uninstall( $name = '' ) {
			$name = $this->get_usermeta_name($name);
			global $wpdb;
			$wpdb->query("DELETE FROM $wpdb->usermeta WHERE meta_key LIKE '" . $name . "%'");
		}

		/* functions-common */

		public function empty_notzero( $value ) {
			if ( function_exists(__FUNCTION__) ) {
				$func = __FUNCTION__;
				return $func($value);
			}
			if ( is_numeric($value) ) {
				if ( (int) $value === 0 ) {
					return false;
				}
			}
			if ( empty($value) ) {
				return true;
			}
			return false;
		}

		public function get_current_uri( $keep_query = false ) {
			if ( function_exists(__FUNCTION__) ) {
				$func = __FUNCTION__;
				return $func($keep_query);
			}
			$res  = is_ssl() ? 'https://' : 'http://';
			$res .= isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '';
			$res .= isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : $_SERVER['PHP_SELF'];
			if ( wp_doing_ajax() && isset($_SERVER['HTTP_REFERER']) ) {
				if ( ! empty($_SERVER["HTTP_REFERER"]) ) {
					$res = $_SERVER["HTTP_REFERER"];
				}
			}
			if ( ! $keep_query ) {
				$remove = array();
				if ( $str = wp_parse_url($res, PHP_URL_QUERY) ) {
					$remove[] = '?' . $str;
				}
				if ( $str = wp_parse_url($res, PHP_URL_FRAGMENT) ) {
					$remove[] = '#' . $str;
				}
				$res = str_replace($remove, '', $res);
			}
			return $res;
		}

		public function in_array_int( $needle, $haystack = array(), $strict = true ) {
			if ( function_exists(__FUNCTION__) ) {
				$func = __FUNCTION__;
				return $func($needle, $haystack, $strict);
			}
			$func = function ( $v ) {
				return (int) $v;
			};
			$haystack = array_map($func, make_array($haystack));
			return in_array( (int) $needle, $arr, $strict);
		}

		public function is_front_end() {
			if ( function_exists(__FUNCTION__) ) {
				$func = __FUNCTION__;
				return $func();
			}
			if ( is_admin() && ! wp_doing_ajax() ) {
				return false;
			}
			if ( wp_doing_ajax() ) {
				if ( strpos($this->get_current_uri(), admin_url()) !== false ) {
					return false;
				}
			}
			return true;
		}

		public function is_true( $value ) {
			if ( function_exists(__FUNCTION__) ) {
				$func = __FUNCTION__;
				return $func($value);
			}
			if ( is_bool($value) ) {
				return $value;
			} elseif ( is_numeric($value) ) {
				if ( (int) $value === 1 ) {
					return true;
				} elseif ( (int) $value === 0 ) {
					return false;
				}
			} elseif ( is_string($value) ) {
				if ( $value === '1' || $value === 'true' ) {
					return true;
				} elseif ( $value === '0' || $value === 'false' ) {
					return false;
				}
			} elseif ( empty($value) ) {
				return false;
			}
			return false;
		}

		public function make_array( $str = '', $sep = ',' ) {
			if ( function_exists(__FUNCTION__) ) {
				$func = __FUNCTION__;
				return $func($str, $sep);
			}
			if ( is_array($str) ) {
				return $str;
			}
			if ( $this->empty_notzero($str) ) {
				return array();
			}
			$arr = explode($sep, $str);
			$arr = array_map('trim', $arr);
			$arr = array_filter($arr,
				function ( $v ) {
					return ! $this->empty_notzero($v);
				}
			);
			return $arr;
		}

		public function link_terms( $str = '', $links = array(), $args = array() ) {
			if ( function_exists(__FUNCTION__) ) {
				$func = __FUNCTION__;
				return $func($str, $links, $args);
			}
			if ( empty($str) ) {
				return $str;
			}
			$text_tags = array(
				'b',
				'blockquote',
				'br',
				'del',
				'div',
				'em',
				'i',
				'p',
				'strong',
				'u',
			);
			$defaults = array(
				'limit' => 1,
				'count_existing_links' => true,
				'in_html_tags' => $text_tags,
				'exclude_current_uri' => true,
				'minify' => true,
			);
			$args = wp_parse_args($args, $defaults);
			$args['limit'] = (int) $args['limit'];
			$args['in_html_tags'] = $this->make_array($args['in_html_tags']);
			$current_uri = ! empty($args['exclude_current_uri']) ? $this->get_current_uri() : '';
			$count_key = '###COUNT###';
			$wptext_functions = array(
				'wptexturize',
				'convert_smilies',
				'convert_chars',
			);
			$sort_longest_first = function ( $a, $b ) {
				return strlen($b) - strlen($a);
			};

			// get all term/link pairs.
			$links = apply_filters('link_terms_links_before', $links, $str, $args, $count_key);
			if ( empty($links) ) {
				return $str;
			}
			foreach ( $links as $k => $v ) {
				if ( $v === $current_uri || esc_url($v) === $current_uri ) {
					unset($links[ $k ]);
					continue;
				}
				// unlimited - single level array.
				if ( $args['limit'] === -1 ) {
					$links[ $k ] = '<a href="' . esc_url($v) . '">' . esc_html($k) . '</a>';
					$k_wp = $k;
					foreach ( $wptext_functions as $func ) {
						$k_wp = $func($k_wp);
						if ( ! isset($links[ $k_wp ]) ) {
							$links[ $k_wp ] = '<a href="' . esc_url($v) . '">' . esc_html($k_wp) . '</a>';
						}
					}
				} else {
					$links[ $k ] = array(
						$count_key => 0,
						$k => '<a href="' . esc_url($v) . '">' . esc_html($k) . '</a>',
					);
					$k_wp = $k;
					foreach ( $wptext_functions as $func ) {
						$k_wp = $func($k_wp);
						if ( ! isset($links[ $k ][ $k_wp ]) ) {
							$links[ $k ][ $k_wp ] = '<a href="' . esc_url($v) . '">' . esc_html($k_wp) . '</a>';
						}
					}
					// longest key first.
					uasort($links[ $k ], $sort_longest_first);
					// existing links.
					if ( ! empty($args['count_existing_links']) && strpos($str, esc_url($v)) !== false ) {
						if ( preg_match_all("/<a [^>]*?href=\"" . preg_quote(esc_url($v), '/') . "\"/is", $str, $matches) ) {
							$links[ $k ][ $count_key ] = count($matches);
						}
					}
				}
			}
			$links = apply_filters('link_terms_links_after', $links, $str, $args, $count_key);
			if ( empty($links) ) {
				return $str;
			}
			if ( $args['limit'] >= 1 ) {
				// longest key first - not needed with strtr.
				$links_keys = array_keys($links);
				usort($links_keys, $sort_longest_first);
				$links_old = $links;
				$links = array();
				foreach ( $links_keys as $key ) {
					$links[ $key ] = $links_old[ $key ];
				}
				unset($links_keys);
				unset($links_old);
			}

			// find / replace.
			$textarr = wp_html_split($str);
			$link_open = false;
			$changed = false;
			// Loop through delimiters (elements) only.
			for ( $i = 0, $c = count($textarr); $i < $c; $i += 2 ) {
				// check the previous tag.
				if ( $i > 0 ) {
					// skip link text.
					if ( strpos($textarr[ $i - 1 ], '<a ') === 0 ) {
						$link_open = true;
						continue;
					} elseif ( strpos($textarr[ $i - 1 ], '</a>') === 0 ) {
						// after a link is fine.
						$link_open = false;
					} elseif ( ! empty($args['in_html_tags']) ) {
						if ( ! preg_match("/^<(" . implode('|', $args['in_html_tags']) . ")( |\/|>)/is", $textarr[ $i - 1 ]) ) {
							continue;
						}
					}
				}
				if ( $link_open ) {
					continue;
				}
				// unlimited.
				if ( $args['limit'] === -1 ) {
					foreach ( $links as $search => $replace ) {
						if ( strpos($textarr[ $i ], $search) !== false ) {
							$textarr[ $i ] = strtr($textarr[ $i ], $links);
							$changed = true;
							// After one strtr() break out of the foreach loop and look at next element.
							break;
						}
					}
				} else {
					foreach ( $links as $key => $pairs ) {
						foreach ( $pairs as $k => $v ) {
							if ( $k === $count_key ) {
								continue;
							}
							if ( strpos($textarr[ $i ], $k) !== false ) {
								$limit = absint($args['limit'] - $links[ $key ][ $count_key ]);
								$count = 1;
								$line_new = preg_replace('/' . preg_quote($k, '/') . '/', $v, $textarr[ $i ], $limit, $count);
								// send changes back to the main array to avoid keywords inside urls.
								$textarr = array_merge( array_slice($textarr, 0, $i), wp_html_split($line_new), array_slice($textarr, $i + 1));
								$c = count($textarr);
								$changed = true;
								$links[ $key ][ $count_key ] += $count;
								// this pair is done.
								if ( $links[ $key ][ $count_key ] >= $args['limit'] ) {
									unset($links[ $key ]);
									break;
								}
							}
						}
					}
				}
			}
			if ( $changed ) {
				if ( ! empty($args['minify']) ) {
					$func = function ( $v ) {
						return trim($v, "\t\r");
					};
					$textarr = array_map($func, $textarr);
				}
				$str = implode($textarr);
			}
			return $str;
		}

		public function the_content_conditions( $str = '' ) {
			if ( function_exists(__FUNCTION__) ) {
				$func = __FUNCTION__;
				return $func($str);
			}
			$res = true;
			if ( empty($str) ) {
				$res = false;
			}
			if ( did_action('get_header') === 0 && ! wp_doing_ajax() && ! is_feed() ) {
				$res = false;
			}
			if ( is_404() ) {
				$res = false;
			}
			if ( function_exists('is_signup_page') ) {
				if ( is_signup_page() ) {
					$res = false;
				}
			}
			if ( function_exists('is_signup_page') ) {
				if ( is_login_page() ) {
					$res = false;
				}
			}
			if ( ! is_main_query() && ! wp_doing_ajax() ) {
				$res = false;
			}
			if ( ! in_the_loop() && current_filter() === 'the_content' ) {
				// allow term_description().
				if ( ! is_tax() && ! is_tag() && ! is_category() ) {
					$res = false;
				}
			}
			if ( ! is_singular() ) {
				if ( ! is_tax() && ! is_tag() && ! is_category() && ! is_posts_page() && ! is_search() ) {
					$res = false;
				}
			}
			return apply_filters('the_content_conditions', $res);
		}
	}
endif;
