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
 		global $title;
		?>
		<div class="wrap">
			<h2><?php echo $title; ?></h2>
		<?php
 		$plugin = new static(static::$plugin_basename, static::$prefix, false);

		if ($plugin->save_menu_page()) {
			// save
		}
 		?>

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

	public function save_menu_page() {
		if (!isset($_POST['save'])) {
			return false;
		}
		if (empty($_POST['save'])) {
			return false;
		}
		// verify this came from the our screen and with proper authorization
		if (!isset($_POST[$this->plugin_name.'::menu_page'])) {
			return false;
		}
		if (!wp_verify_nonce($_POST[$this->plugin_name.'::menu_page'], static::$plugin_basename)) {
			return false;
		}
		return true;
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
			$current_blog_id = get_current_blog_id();
			$sites = get_sites();
			foreach ($sites as $key => $value) {
				switch_to_blog($value->blog_id);
				$wpdb->query("DELETE FROM $wpdb->options WHERE option_name LIKE '".$name_single."%'");
			}
			switch_to_blog($current_blog_id);
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
			$current_blog_id = get_current_blog_id();
			$sites = get_sites();
			foreach ($sites as $key => $value) {
				switch_to_blog($value->blog_id);
				$wpdb->query("DELETE FROM $wpdb->options WHERE option_name LIKE '_transient_".$transient_single."%' OR option_name LIKE '_transient_timeout_".$transient_single."%'");
			}
			switch_to_blog($current_blog_id);
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
	public function delete_postmeta_uninstall($name = '') {
		$name = $this->get_postmeta_name($name);
		global $wpdb;
		if (is_multisite()) {
			$current_blog_id = get_current_blog_id();
			$sites = get_sites();
			foreach ($sites as $key => $value) {
				switch_to_blog($value->blog_id);
				$wpdb->query("DELETE FROM $wpdb->postmeta WHERE meta_key LIKE '".$name."%'");
			}
			switch_to_blog($current_blog_id);
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
		$arr = array_filter($arr);
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

	public function get_current_uri() {
		if (function_exists(__FUNCTION__)) {
			$func = __FUNCTION__;
			return $func();
		}
	 	$res  = is_ssl() ? 'https://' : 'http://';
	 	$res .= $_SERVER['HTTP_HOST'];
	 	$res .= $_SERVER['REQUEST_URI'];
		if (wp_doing_ajax()) {
			if (!empty($_SERVER["HTTP_REFERER"])) {
				$res = $_SERVER["HTTP_REFERER"];
			}
		}
		return $res;
	}

}
endif;
?>