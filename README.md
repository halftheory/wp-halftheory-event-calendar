# wp-halftheory-event-calendar
Wordpress plugin for shortcode [event-calendar] and adding calendar/geolocation metadata to any post.

This plugin enables the simple display and population of a monthly/weekly/daily calendar.

Features:
- Add calendar/geolocation metadata to any post type.
- Simply display a calendar using shortcode [event-calendar].
- Supports OpenStreetMap and Google Maps (a Server API Key must be obtained from Google's Webmaster Tools).
- Multisite: Ability to include events across all sites.
- Fully themable via CSS selectors.
- Bbpress compatible.

# Shortcode examples

[event-calendar]

[event-calendar sites=1,2 post_types=post]

[event-calendar sites=all post_types=post,page include_post_date=false include_post_modified=true]

# Custom filters

The following filters are available for plugin/theme customization:

- halftheory_admin_menu_parent
- eventcalendar_admin_menu_parent
- eventcalendar_deactivation
- eventcalendar_uninstall
- eventcalendar_shortcode
- eventcalendar_fullcalendar_args
- eventcalendar_qtip
- eventcalendar_qtip_args
- eventcalendar_posts_args
- eventcalendar_posts
- eventcalendar_the_content
- eventcalendar_toggle
- eventcalendar_post_has_event_data_required

# Credits

Included scripts:
- https://fullcalendar.io
- http://qtip2.com
- https://xdsoft.net/jqplugins/datetimepicker/

Some code is adapted from the following:
- https://wordpress.org/plugins/wp-fullcalendar/
- https://wordpress.org/plugins/events-manager/
- https://wordpress.org/plugins/event-post/