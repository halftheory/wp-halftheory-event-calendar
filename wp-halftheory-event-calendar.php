<?php
/*
Plugin Name: Event Calendar
Plugin URI: https://github.com/halftheory/wp-halftheory-event-calendar
Description: Event Calendar
Author: Half/theory
Author URI: https://github.com/halftheory
Version: 1.0
Network: true
*/

/*
Available filters:
eventcalendar_deactivation(string $db_prefix)
eventcalendar_uninstall(string $db_prefix)
*/

// Exit if accessed directly.
defined('ABSPATH') || exit;

if (!class_exists('Event_Calendar_Plugin')) :
class Event_Calendar_Plugin {

	public function __construct() {
		@include_once(dirname(__FILE__).'/class-event-calendar.php');
		$this->subclass = new Event_Calendar();
	}

	public static function init() {
		$plugin = new self;
		return $plugin;
	}

	public static function activation() {
		$plugin = new self;
		return $plugin;
	}

	public static function deactivation() {
		$plugin = new self;

		// remove transients
		global $wpdb;
		$query_single = "DELETE FROM $wpdb->options WHERE option_name LIKE '_transient_".$plugin->subclass->prefix."%' OR option_name LIKE '_transient_timeout_".$plugin->subclass->prefix."%'";
		if (is_multisite()) {
			$wpdb->query("DELETE FROM $wpdb->sitemeta WHERE meta_key LIKE '_site_transient_".$plugin->subclass->prefix."%' OR meta_key LIKE '_site_transient_timeout_".$plugin->subclass->prefix."%'");
			$current_blog_id = get_current_blog_id();
			$sites = get_sites();
			foreach ($sites as $key => $value) {
				switch_to_blog($value->blog_id);
				$wpdb->query($query_single);
			}
			switch_to_blog($current_blog_id);
		}
		else {
			$wpdb->query($query_single);
		}
		apply_filters('eventcalendar_deactivation', $plugin->subclass->prefix);
		return;
	}

	public static function uninstall() {
		$plugin = new self;

		// remove options + postmeta
		global $wpdb;
		$query_options = "DELETE FROM $wpdb->options WHERE option_name LIKE '".$plugin->subclass->prefix."_%'";
		$query_postmeta = "DELETE FROM $wpdb->postmeta WHERE meta_key LIKE '".$plugin->subclass->prefix."_%'";
		if (is_multisite()) {
			delete_site_option($plugin->subclass->prefix);
			$wpdb->query("DELETE FROM $wpdb->sitemeta WHERE meta_key LIKE '".$plugin->subclass->prefix."_%'");
			$current_blog_id = get_current_blog_id();
			$sites = get_sites();
			foreach ($sites as $key => $value) {
				switch_to_blog($value->blog_id);
				delete_option($plugin->subclass->prefix);
				$wpdb->query($query_options);
				$wpdb->query($query_postmeta);
			}
			switch_to_blog($current_blog_id);
		}
		else {
			delete_option($plugin->subclass->prefix);
			$wpdb->query($query_options);
			$wpdb->query($query_postmeta);
		}
		apply_filters('eventcalendar_uninstall', $plugin->subclass->prefix);
		return;
	}

}
// Load the plugin.
add_action('init', array('Event_Calendar_Plugin', 'init'));
endif;

register_activation_hook(__FILE__, array('Event_Calendar_Plugin', 'activation'));
register_deactivation_hook(__FILE__, array('Event_Calendar_Plugin', 'deactivation'));
function Event_Calendar_Plugin_uninstall() {
	Event_Calendar_Plugin::uninstall();
};
register_uninstall_hook(__FILE__, 'Event_Calendar_Plugin_uninstall');
?>