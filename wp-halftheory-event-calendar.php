<?php
/*
Plugin Name: Half/theory Event Calendar
Plugin URI: https://github.com/halftheory/wp-halftheory-event-calendar
GitHub Plugin URI: https://github.com/halftheory/wp-halftheory-event-calendar
Description: Event Calendar
Author: Half/theory
Author URI: https://github.com/halftheory
Version: 2.0
Network: false
*/

/*
Available filters:
eventcalendar_deactivation(string $db_prefix, class $subclass)
eventcalendar_uninstall(string $db_prefix, class $subclass)
*/

// Exit if accessed directly.
defined('ABSPATH') || exit;

if (!class_exists('Event_Calendar_Plugin')) :
final class Event_Calendar_Plugin {

	public function __construct() {
		@include_once(dirname(__FILE__).'/class-event-calendar.php');
		if (class_exists('Event_Calendar')) {
			$this->subclass = new Event_Calendar(plugin_basename(__FILE__), '', true);
		}
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
		if ($plugin->subclass) {
			$plugin->subclass->delete_transient_uninstall();
			apply_filters('eventcalendar_deactivation', $plugin->subclass::$prefix, $plugin->subclass);
		}
		return;
	}

	public static function uninstall() {
		$plugin = new self;
		if ($plugin->subclass) {
			$plugin->subclass->delete_transient_uninstall();
			$plugin->subclass->delete_postmeta_uninstall();
			$plugin->subclass->delete_option_uninstall();
			apply_filters('eventcalendar_uninstall', $plugin->subclass::$prefix, $plugin->subclass);
		}
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