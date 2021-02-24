<?php
/*
Available filters:
halftheory_admin_menu_parent
{static::$prefix}_admin_menu_parent
*/

// Exit if accessed directly.
defined('ABSPATH') || exit;

if (!class_exists('Halftheory_Helper_Plugin')) :
class Halftheory_Helper_Plugin {

	public static $plugin_basename;
	public static $prefix;
	public $options = array();
	public $postmeta = array();
	public $menu_page_tabs = array();
	public $menu_page_tab_active = '';
	public static $plugin_version = null;

	/* setup */

	public function __construct($plugin_basename = '', $prefix = '', $load_actions = false) {
		$this->init($plugin_basename, $prefix);
		if ($load_actions) {
			$this->setup_actions();
		}
	}

	public function init($plugin_basename = '', $prefix = '') {
		if (isset($this->plugin_is_network)) {
			unset($this->plugin_is_network);
		}
		if (!empty($plugin_basename)) {
			static::$plugin_basename = $plugin_basename;
		}
		$this->plugin_name = get_called_class();
		$this->plugin_title = ucwords(str_replace('_', ' ', $this->plugin_name));
		if (!empty($prefix)) {
			static::$prefix = $prefix;
		}
		else {
			static::$prefix = sanitize_key($this->plugin_name);
			static::$prefix = preg_replace("/[^a-z0-9]/", "", static::$prefix);
		}
		$this->options = array();
		// only on our menu_page
		if ($this->is_menu_page()) {
			$this->menu_page_tab_active = (isset($_GET['tab']) ? $_GET['tab'] : '');
			/* // child class set in init
			$this->menu_page_tabs = array(
				'' => array(
					'name' => __('Settings'),
					'callback' => 'menu_page',
				),
			);
			*/
		}
	}

	protected function setup_actions() {
		// admin options
		if (!$this->is_front_end()) {
			if ($this->is_plugin_network()) {
				add_action('network_admin_menu', array($this,'admin_menu'));
				if (is_main_site()) {
					add_action('admin_menu', array($this,'admin_menu'));
				}
			}
			else {
				add_action('admin_menu', array($this,'admin_menu'));
			}
		}
	}

	/* admin */

	public function admin_menu() {
		if (empty(static::$prefix)) {
			return;
		}
		if (!is_array($GLOBALS['menu'])) {
			return;
		}

		$has_parent = false;
		$parent_slug = static::$prefix;
		$parent_name = apply_filters('halftheory_admin_menu_parent', 'Halftheory');
		$parent_name = apply_filters(static::$prefix.'_admin_menu_parent', $parent_name);

		// set parent to nothing to skip parent menu creation
		if (empty($parent_name)) {
			add_options_page(
				$this->plugin_title,
				$this->plugin_title,
				'manage_options',
				static::$prefix,
				array($this,'menu_page')
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
				array($this,'menu_page')
			);
		}

		// add the menu
		add_submenu_page(
			$parent_slug,
			$this->plugin_title,
			$this->plugin_title,
			'manage_options',
			static::$prefix,
			array($this,'menu_page')
		);
	}

	public function menu_page() {
 		$plugin = new static(static::$plugin_basename, static::$prefix, false);

		// redirect to tab functions
		if ($plugin->load_menu_page_tab()) {
			return;
		}

 		global $title;
		?>
		<div class="wrap">
		<h2><?php echo $title; ?></h2>

		<?php
		if ($plugin->save_menu_page(__FUNCTION__)) {
			// save
		}
 		?>

		<?php $plugin->print_menu_page_tabs(); ?>

	    <form id="<?php echo $plugin::$prefix; ?>-admin-form" name="<?php echo $plugin::$prefix; ?>-admin-form" method="post" action="<?php echo $_SERVER['REQUEST_URI']; ?>">
		<?php
		// Use nonce for verification
		wp_nonce_field($plugin::$plugin_basename, $plugin->plugin_name.'::'.__FUNCTION__);
		?>
	    <div id="poststuff">

        <?php submit_button(__('Update'), array('primary','large'), 'save'); ?>

        </div><!-- poststuff -->
    	</form>

 		</div><!-- wrap -->
 		<?php
 	}

	public function menu_page_tab($plugin) {
 		global $title;
		?>
		<div class="wrap">
		<h2><?php echo $title; ?></h2>

		<?php
		if ($plugin->save_menu_page(__FUNCTION__)) {
			// save
		}
 		?>

		<?php $plugin->print_menu_page_tabs(); ?>

	    <form id="<?php echo $plugin::$prefix; ?>-admin-form" name="<?php echo $plugin::$prefix; ?>-admin-form" method="post" action="<?php echo $_SERVER['REQUEST_URI']; ?>">
		<?php
		// Use nonce for verification
		wp_nonce_field($plugin::$plugin_basename, $plugin->plugin_name.'::'.__FUNCTION__);
		?>
	    <div id="poststuff">

        <?php submit_button(__('Update'), array('primary','large'), 'save'); ?>

        </div><!-- poststuff -->
    	</form>

 		</div><!-- wrap -->
 		<?php
 	}

	/* functions */

	public static function get_plugin_version() {
		if (!is_null(static::$plugin_version)) {
			return static::$plugin_version;
		}
		$file = WP_PLUGIN_DIR.'/'.static::$plugin_basename;
		if (!file_exists($file)) {
			return null;
		}
		if (!function_exists('get_plugin_data')) {
        	require_once(ABSPATH.'wp-admin/includes/plugin.php');
    	}
		$plugin_data = get_plugin_data($file);
		if (!is_array($plugin_data)) {
			return null;
		}
		if (isset($plugin_data['Version'])) {
			static::$plugin_version = $plugin_data['Version'];
			return static::$plugin_version;
		}
		return null;
	}

	public function is_menu_page($str = null) {
		if (!$this->is_front_end()) {
			if (empty($str)) {
				$str = static::$prefix;
			}
	    	global $current_screen;
	    	if (is_object($current_screen)) {
		    	if (strpos($current_screen->id, $str) !== false) {
					return true;
				}
			}
			elseif (isset($_SERVER['QUERY_STRING'])) {
		    	if (strpos($_SERVER['QUERY_STRING'], $str) !== false) {
					return true;
				}
			}
		}
		return false;
	}

	public function is_edit_screen($post_types = array()) {
		if (!function_exists('get_current_screen')) {
			return false;
		}
		if (!is_object(get_current_screen())) {
			return false;
		}
		if (strpos(get_current_screen()->id, 'edit-') === false) {
			return false;
		}
		elseif (strpos(get_current_screen()->id, 'edit-') !== false && empty($post_types)) {
			return true;
		}
		if (!empty($post_types)) {
			$post_types = $this->make_array($post_types);
			foreach ($post_types as $post_type) {
				if (get_current_screen()->id == 'edit-'.$post_type) {
					return true;
				}
			}
		}
		return false;
	}

	public function save_menu_page($function_name = 'menu_page', $post_key = 'save') {
		if (!isset($_POST[$post_key])) {
			return false;
		}
		if (empty($_POST[$post_key])) {
			return false;
		}
		// verify this came from the our screen and with proper authorization
		if (!isset($_POST[$this->plugin_name.'::'.$function_name])) {
			return false;
		}
		if (!wp_verify_nonce($_POST[$this->plugin_name.'::'.$function_name], static::$plugin_basename)) {
			return false;
		}
		return true;
	}

	public function load_menu_page_tab() {
		if (!empty($this->menu_page_tabs) && !empty($this->menu_page_tab_active)) {
			if (isset($this->menu_page_tabs[$this->menu_page_tab_active])) {
				if (isset($this->menu_page_tabs[$this->menu_page_tab_active]['callback'])) {
					if (is_string($this->menu_page_tabs[$this->menu_page_tab_active]['callback'])) {
						if (method_exists($this, $this->menu_page_tabs[$this->menu_page_tab_active]['callback'])) {
							$callback = $this->menu_page_tabs[$this->menu_page_tab_active]['callback'];
							$this->$callback($this);
							return true;
						}
						elseif (function_exists($this->menu_page_tabs[$this->menu_page_tab_active]['callback'])) {
							$callback = $this->menu_page_tabs[$this->menu_page_tab_active]['callback'];
							$callback($this);
							return true;
						}
					}
					elseif (is_array($this->menu_page_tabs[$this->menu_page_tab_active]['callback'])) {
						if (is_callable($this->menu_page_tabs[$this->menu_page_tab_active]['callback'])) {
							$callback = $this->menu_page_tabs[$this->menu_page_tab_active]['callback'];
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
		if (!empty($this->menu_page_tabs)) : ?>
		<h2 class="nav-tab-wrapper"><?php
			global $pagenow;
			foreach ($this->menu_page_tabs as $key => $value) {
				if (empty($key)) {
					echo '<a class="nav-tab'.($this->menu_page_tab_active == $key ? ' nav-tab-active' : '').'" href="'.esc_url( admin_url($pagenow.'?page='.static::$prefix) ).'">'.$value['name'].'</a> ';
				}
				else {
					echo '<a class="nav-tab'.($this->menu_page_tab_active == $key ? ' nav-tab-active' : '').'" href="'.esc_url( admin_url($pagenow.'?page='.static::$prefix.'&tab='.$key) ).'">'.$value['name'].'</a> ';
				}
			}
		?></h2>
		<?php endif;
	}

	public function is_plugin_network() {
		if (isset($this->plugin_is_network)) {
			return $this->plugin_is_network;
		}
		$res = false;
		if (is_multisite()) {
			if (!function_exists('is_plugin_active_for_network')) {
				@require_once(ABSPATH.'/wp-admin/includes/plugin.php');
			}
			if (function_exists('is_plugin_active_for_network')) {
				if (is_plugin_active_for_network(static::$plugin_basename)) {
					$res = true;
				}
			}
		}
		$this->plugin_is_network = $res;
		return $res;
	}

	public static function get_template_tags() {
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

	public static function get_template($wp_query = null) {
		// adapted from wp-includes/template-loader.php
		// try to respect the WordPress Template Hierarchy - https://wphierarchy.com/
		$template = false;
		// Loop through each of the template conditionals, and find the appropriate template file.
		foreach (self::get_template_tags() as $tag => $template_getter) {
			if (is_object($wp_query)) {
				if ($wp_query->$tag) {
					$template = call_user_func($template_getter);
				}
			}
			elseif (call_user_func($tag)) {
				$template = call_user_func($template_getter);
			}
			if ($template) {
				break;
			}
		}
		if (!$template) {
			$template = get_index_template();
		}
		$template = apply_filters('template_include', $template);
		return $template;
	}

	public function add_shortcode_wpautop_control($shortcode = 'code', $actions = array('the_content','the_excerpt','widget_text_content')) {
		// see: https://github.com/chiedolabs/shortcode-wpautop-control/blob/master/shortcode-wpautop-control.php
		if (!shortcode_exists($shortcode)) {
			return;
		}

		$func_action = function($str = '') use ($shortcode) {
			if (!has_shortcode($str, $shortcode)) {
				return $str;
			}
			remove_filter(current_filter(), 'wpautop');
			$parts_old = preg_split("/".get_shortcode_regex(array($shortcode))."/is", $str, -1, PREG_SPLIT_NO_EMPTY);
			$parts_new = array_map('trim', $parts_old);
			$parts_new = array_map('wpautop', $parts_new);
			$str = strtr($str, array_combine($parts_old, $parts_new));
			return $str;
		};

		$actions = $this->make_array($actions);
		foreach ($actions as $action) {
			$priority_do_shortcode = has_filter($action, 'do_shortcode');
			if ($priority_do_shortcode === false) {
				continue;
			}
			$priority_wpautop = has_filter($action, 'wpautop');
			if ($priority_wpautop === false) {
				continue;
			}
			// usual priority: wpautop (10) do_shortcode (11), so we need to get ahead of both to change them
			$priority_before = intval(max((min($priority_do_shortcode-1, $priority_wpautop-1)), 0)); // 9?
			add_filter($action, $func_action, $priority_before);
		}
	}

	// options
	private function get_option_name($name = '', $is_network = null) {
		if (empty($name)) {
			$name = static::$prefix;
		}
		if (is_null($is_network)) {
			$is_network = $this->is_plugin_network();
		}
		if ($is_network) {
			$name = substr($name, 0, 255);
		}
		else {
			$name = substr($name, 0, 191);
		}
		return $name;
	}
	public function get_option($name = '', $key = '', $default = array()) {
		$name = $this->get_option_name($name);
		if (!isset($this->options[$name])) {
			if ($this->is_plugin_network()) {
				$option = get_site_option($name, array());
			}
			else {
				$option = get_option($name, array());
			}
			$this->options[$name] = $option;
		}
		if (!$this->empty_notzero($key) && is_array($this->options[$name])) {
			if (array_key_exists($key, $this->options[$name])) {
				return $this->options[$name][$key];
			}
			return $default;
		}
		return $this->options[$name];
	}
	public function update_option($name = '', $value) {
		$name = $this->get_option_name($name);
		if ($this->is_plugin_network()) {
			$bool = update_site_option($name, $value);
		}
		else {
			$bool = update_option($name, $value);
		}
		if ($bool !== false) {
			$this->options[$name] = $value;
		}
		return $bool;
	}
	public function delete_option($name = '') {
		$name = $this->get_option_name($name);
		if ($this->is_plugin_network()) {
			$bool = delete_site_option($name);
		}
		else {
			$bool = delete_option($name);
		}
		if ($bool !== false && isset($this->options[$name])) {
			unset($this->options[$name]);
		}
		return $bool;
	}
	public function delete_option_uninstall($name = '') {
		$name_single = $this->get_option_name($name, false);
		global $wpdb;
		if (is_multisite()) {
			$name_network = $this->get_option_name($name, true);
			$wpdb->query("DELETE FROM $wpdb->sitemeta WHERE meta_key LIKE '".$name_network."%'");
			$sites = get_sites();
			foreach ($sites as $key => $value) {
				switch_to_blog($value->blog_id);
				$wpdb->query("DELETE FROM $wpdb->options WHERE option_name LIKE '".$name_single."%'");
				restore_current_blog();
			}
		}
		else {
			$wpdb->query("DELETE FROM $wpdb->options WHERE option_name LIKE '".$name_single."%'");
		}
	}

	// transients
	private function get_transient_name($name = '', $is_network = null) {
		if (empty($name)) {
			$name = static::$prefix;
		}
		if (is_null($is_network)) {
			$is_network = $this->is_plugin_network();
		}
		if ($is_network) {
			$name = substr($name, 0, 167);
		}
		else {
			$name = substr($name, 0, 172);
		}
		return $name;
	}
	public function get_transient($transient = '') {
		$transient = $this->get_transient_name($transient);
		if ($this->is_plugin_network()) {
			$value = get_site_transient($transient);
		}
		else {
			$value = get_transient($transient);
		}
		return $value;
	}
	public function set_transient($transient = '', $value, $expiration = 0) {
		$transient = $this->get_transient_name($transient);
		if (is_string($expiration)) {
			$expiration = strtotime('+'.trim($expiration, " -+")) - time();
			if (!$expiration || $expiration < 0) {
				$expiration = 0;
			}
		}
		if ($this->is_plugin_network()) {
			$bool = set_site_transient($transient, $value, $expiration);
		}
		else {
			$bool = set_transient($transient, $value, $expiration);
		}
		return $bool;
	}
	public function delete_transient($transient = '') {
		$transient = $this->get_transient_name($transient);
		if ($this->is_plugin_network()) {
			$bool = delete_site_transient($transient);
		}
		else {
			$bool = delete_transient($transient);
		}
		return $bool;
	}
	public function delete_transient_uninstall($transient = '') {
		$transient_single = $this->get_transient_name($transient, false);
		global $wpdb;
		if (is_multisite()) {
			$transient_network = $this->get_transient_name($transient, true);
			$wpdb->query("DELETE FROM $wpdb->sitemeta WHERE meta_key LIKE '_site_transient_".$transient_network."%' OR meta_key LIKE '_site_transient_timeout_".$transient_network."%'");
			$sites = get_sites();
			foreach ($sites as $key => $value) {
				switch_to_blog($value->blog_id);
				$wpdb->query("DELETE FROM $wpdb->options WHERE option_name LIKE '_transient_".$transient_single."%' OR option_name LIKE '_transient_timeout_".$transient_single."%'");
				restore_current_blog();
			}
		}
		else {
			$wpdb->query("DELETE FROM $wpdb->options WHERE option_name LIKE '_transient_".$transient_single."%' OR option_name LIKE '_transient_timeout_".$transient_single."%'");
		}
	}

	// postmeta
	private function get_postmeta_name($name = '') {
		if (empty($name)) {
			$name = static::$prefix;
		}
		$name = substr($name, 0, 255);
		return $name;
	}
	public function get_postmeta($post_id = 0, $name = '', $key = '', $default = array()) {
		$post_id = (int)$post_id;
		$name = $this->get_postmeta_name($name);
		$db_fetch = false;
		if (!isset($this->postmeta[$post_id])) {
			$this->postmeta[$post_id] = array();
			$db_fetch = true;
		}
		else {
			if (!isset($this->postmeta[$post_id][$name])) {
				$db_fetch = true;
			}
		}
		if ($db_fetch) {
			$this->postmeta[$post_id][$name] = get_post_meta($post_id, $name, true);
		}
		if (!$this->empty_notzero($key) && is_array($this->postmeta[$post_id][$name])) {
			if (array_key_exists($key, $this->postmeta[$post_id][$name])) {
				return $this->postmeta[$post_id][$name][$key];
			}
			return $default;
		}
		return $this->postmeta[$post_id][$name];
	}
	public function update_postmeta($post_id = 0, $name = '', $value) {
		$post_id = (int)$post_id;
		$name = $this->get_postmeta_name($name);
		$bool = update_post_meta($post_id, $name, $value);
		if ($bool !== false) {
			if (!isset($this->postmeta[$post_id])) {
				$this->postmeta[$post_id] = array();
			}
			$this->postmeta[$post_id][$name] = $value;
		}
		return $bool;
	}
	public function delete_postmeta($post_id = 0, $name = '') {
		$post_id = (int)$post_id;
		$name = $this->get_postmeta_name($name);
		$bool = delete_post_meta($post_id, $name);
		if ($bool !== false && isset($this->postmeta[$post_id])) {
			if (isset($this->postmeta[$post_id][$name])) {
				unset($this->postmeta[$post_id][$name]);
			}
		}
		return $bool;
	}
	public function delete_postmeta_uninstall($name = '') {
		$name = $this->get_postmeta_name($name);
		global $wpdb;
		if (is_multisite()) {
			$sites = get_sites();
			foreach ($sites as $key => $value) {
				switch_to_blog($value->blog_id);
				$wpdb->query("DELETE FROM $wpdb->postmeta WHERE meta_key LIKE '".$name."%'");
				restore_current_blog();
			}
		}
		else {
			$wpdb->query("DELETE FROM $wpdb->postmeta WHERE meta_key LIKE '".$name."%'");
		}
	}

	// usermeta
	private function get_usermeta_name($name = '') {
		if (empty($name)) {
			$name = static::$prefix;
		}
		$name = substr($name, 0, 255);
		return $name;
	}
	public function delete_usermeta_uninstall($name = '') {
		$name = $this->get_usermeta_name($name);
		global $wpdb;
		$wpdb->query("DELETE FROM $wpdb->usermeta WHERE meta_key LIKE '".$name."%'");
	}

	/* functions-common */

	public function is_true($value) {
		if (function_exists(__FUNCTION__)) {
			$func = __FUNCTION__;
			return $func($value);
		}
		if (is_bool($value)) {
			return $value;
		}
		elseif (is_numeric($value)) {
			if ((int)$value === 1) {
				return true;
			}
			elseif ((int)$value === 0) {
				return false;
			}
		}
		elseif (is_string($value)) {
			if ($value == '1' || $value == 'true') {
				return true;
			}
			elseif ($value == '0' || $value == 'false') {
				return false;
			}
		}
		elseif (empty($value)) {
			return false;
		}
		return false;
	}

	public function empty_notzero($value) {
		if (function_exists(__FUNCTION__)) {
			$func = __FUNCTION__;
			return $func($value);
		}
		if (is_numeric($value)) {
			if ((int)$value === 0) {
				return false;
			}
		}
		if (empty($value)) {
			return true;
		}
		return false;
	}

	public function make_array($str = '', $sep = ',') {
		if (function_exists(__FUNCTION__)) {
			$func = __FUNCTION__;
			return $func($str, $sep);
		}
		if (is_array($str)) {
			return $str;
		}
		if ($this->empty_notzero($str)) {
			return array();
		}
		$arr = explode($sep, $str);
		$arr = array_map('trim', $arr);
		$arr = array_filter($arr, function($v) { return !$this->empty_notzero($v); });
		return $arr;
	}

	public function is_front_end() {
		if (function_exists(__FUNCTION__)) {
			$func = __FUNCTION__;
			return $func();
		}
		if (is_admin() && !wp_doing_ajax()) {
			return false;
		}
		if (wp_doing_ajax()) {
			if (strpos($this->get_current_uri(), admin_url()) !== false) {
				return false;
			}
		}
		return true;
	}

	public function get_current_uri($keep_query = false) {
		if (function_exists(__FUNCTION__)) {
			$func = __FUNCTION__;
			return $func($keep_query);
		}
	 	$res  = is_ssl() ? 'https://' : 'http://';
	 	$res .= $_SERVER['HTTP_HOST'];
	 	$res .= $_SERVER['REQUEST_URI'];
		if (wp_doing_ajax()) {
			if (!empty($_SERVER["HTTP_REFERER"])) {
				$res = $_SERVER["HTTP_REFERER"];
			}
		}
		if (!$keep_query) {
			$remove = array();
			if ($str = parse_url($res, PHP_URL_QUERY)) {
				$remove[] = '?'.$str;
			}
			if ($str = parse_url($res, PHP_URL_FRAGMENT)) {
				$remove[] = '#'.$str;
			}
			$res = str_replace($remove, '', $res);
		}
		return $res;
	}

	public function the_content_conditions($str = '') {
		if (function_exists(__FUNCTION__)) {
			$func = __FUNCTION__;
			return $func($str);
		}
		if (empty($str)) {
			return false;
		}
		if (did_action('get_header') == 0 && !wp_doing_ajax()) {
			return false;
		}
		if (is_404()) {
			return false;
		}
		if (function_exists('is_signup_page')) {
			if (is_signup_page()) {
				return false;
			}
		}
		if (function_exists('is_signup_page')) {
			if (is_login_page()) {
				return false;
			}
		}
		if (!is_main_query() && !wp_doing_ajax()) {
			return false;
		}
		if (!in_the_loop() && current_filter() == 'the_content') {
			if (!is_tax() && !is_tag() && !is_category()) { // allow term_description()
				return false;
			}
		}
		if (!is_singular()) {
			if (!is_tax() && !is_tag() && !is_category()) {
				return false;
			}
		}
		return true;
	}

	public function link_terms($str = '', $links = array(), $args = array()) {
		if (function_exists(__FUNCTION__)) {
			$func = __FUNCTION__;
			return $func($str, $links, $args);
		}
		if (empty($str)) {
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
		$args['limit'] = (int)$args['limit'];
		$args['in_html_tags'] = $this->make_array($args['in_html_tags']);
		$current_uri = !empty($args['exclude_current_uri']) ? $this->get_current_uri() : '';
		$count_key = "###COUNT###";
		$wptext_functions = array(
			'wptexturize',
			'convert_smilies',
			'convert_chars',
		);
		$sort_longest_first = function($a, $b) {
    		return strlen($b) - strlen($a);
		};

		// get all term/link pairs
		$links = apply_filters('link_terms_links_before', $links, $str, $args);
		if (empty($links)) {
			return $str;
		}
		foreach ($links as $k => $v) {
			if ($v == $current_uri || esc_url($v) == $current_uri) {
				unset($links[$k]);
				continue;
			}
			// unlimited - single level array
			if ($args['limit'] === -1) {
				$links[$k] = '<a href="'.esc_url($v).'">'.esc_html($k).'</a>';
				$k_wp = $k;
				foreach ($wptext_functions as $func) {
					$k_wp = $func($k_wp);
					if (!isset($links[$k_wp])) {
						$links[$k_wp] = '<a href="'.esc_url($v).'">'.esc_html($k_wp).'</a>';
					}
				}
			}
			else {
				$links[$k] = array(
					$count_key => 0,
					$k => '<a href="'.esc_url($v).'">'.esc_html($k).'</a>',
				);
				$k_wp = $k;
				foreach ($wptext_functions as $func) {
					$k_wp = $func($k_wp);
					if (!isset($links[$k][$k_wp])) {
						$links[$k][$k_wp] = '<a href="'.esc_url($v).'">'.esc_html($k_wp).'</a>';
					}
				}
				// longest key first
				uasort($links[$k], $sort_longest_first);
				// existing links
				if (!empty($args['count_existing_links']) && strpos($str, esc_url($v)) !== false) {
					if (preg_match_all("/<a [^>]*?href=\"".preg_quote(esc_url($v), '/')."\"/is", $str, $matches)) {
						$links[$k][$count_key] = count($matches);
					}
				}
			}
		}
		$links = apply_filters('link_terms_links_after', $links, $str, $args);
		if (empty($links)) {
			return $str;
		}
		if ($args['limit'] >= 1) {
			// longest key first - not needed with strtr
			$links_keys = array_keys($links);
			usort($links_keys, $sort_longest_first);
			$links_old = $links;
			$links = array();
			foreach ($links_keys as $key) {
				$links[$key] = $links_old[$key];
			}
			unset($links_keys);
			unset($links_old);
		}

		// find / replace
		$textarr = wp_html_split($str);
		$link_open = false;
		$changed = false;
		// Loop through delimiters (elements) only.
		for ($i = 0, $c = count($textarr); $i < $c; $i += 2) {
			// check the previous tag
			if ($i > 0) {
				if (strpos($textarr[$i-1], '<a ') === 0) { // skip link text
					$link_open = true;
					continue;
				}
				elseif (strpos($textarr[$i-1], '</a>') === 0) { // after a link is fine
					$link_open = false;
				}
				elseif (!empty($args['in_html_tags'])) {
					if (!preg_match("/^<(".implode("|",$args['in_html_tags']).")( |\/|>)/is", $textarr[$i-1])) {
						continue;
					}
				}
			}
			if ($link_open) {
				continue;
			}
			// unlimited
			if ($args['limit'] === -1) {
				foreach ($links as $search => $replace) {
					if (strpos($textarr[$i], $search) !== false) {
						$textarr[$i] = strtr($textarr[$i], $links);
						$changed = true;
						// After one strtr() break out of the foreach loop and look at next element.
						break;
					}
				}
			}
			else {
				foreach ($links as $key => $pairs) {
					foreach ($pairs as $k => $v) {
						if ($k === $count_key) {
							continue;
						}
						if (strpos($textarr[$i], $k) !== false) {
							$limit = absint($args['limit'] - $links[$key][$count_key]);
							$count = 1;
							$line_new = preg_replace('/'.preg_quote($k,'/').'/', $v, $textarr[$i], $limit, $count);
							// send changes back to the main array to avoid keywords inside urls
							$textarr = array_merge( array_slice($textarr, 0, $i), wp_html_split($line_new), array_slice($textarr, $i+1));
							$c = count($textarr);
							$changed = true;
							$links[$key][$count_key] += $count;
							// this pair is done
							if ($links[$key][$count_key] >= $args['limit']) {
								unset($links[$key]);
								break;
							}
						}
					}
				}
			}
		}
		if ($changed) {
			if (!empty($args['minify'])) {
				$func = function($v){
					return trim($v, "\t\r");
				};
				$textarr = array_map($func, $textarr);
			}
			$str = implode($textarr);
		}
		return $str;
	}

}
endif;
?>