(function($, undefined) {
/** Note: $() will work as an alias for jQuery() inside of this function */
if (typeof eventcalendar === 'object') {

	eventcalendar_loadMap = function(provider, toggle) {
		var canvas_id = eventcalendar.prefix+'-map-'+toggle.data('id');
		if (!$('#'+canvas_id).length) {
			return false;
		}
		if ($('#'+canvas_id).children().length > 0) {
			return canvas_id;
		}
		var canvas_elem = $('#'+canvas_id).first();

		if (provider == 'openstreetmap' && eventcalendar.openstreetmap_src) {
			canvas_elem.addClass('loading');
			var bbox_margin = 0.02;
			var queryArgs = {
				bbox: (parseFloat(toggle.data('longitude')) - bbox_margin)+','+(parseFloat(toggle.data('latitude')) - bbox_margin)+','+(parseFloat(toggle.data('longitude')) + bbox_margin)+','+(parseFloat(toggle.data('latitude')) + bbox_margin),
				layer: 'mapnik'
			};
			$('<iframe></iframe>').attr('src', eventcalendar.openstreetmap_src+'?'+$.param(queryArgs)).appendTo(canvas_elem);
			canvas_elem.removeClass('loading');
		}
		else if (provider == 'google' && typeof google !== 'undefined' && typeof google.maps.Map === 'function') {
			canvas_elem.addClass('loading');
			var location = {lat: parseFloat(toggle.data('latitude')), lng: parseFloat(toggle.data('longitude'))};
			var map = new google.maps.Map(document.getElementById(canvas_id),
				{
					zoom: 12,
					center: location,
					mapTypeId: google.maps.MapTypeId.ROADMAP,
					zoomControl: true
				}
			);
			var marker = new google.maps.Marker({
				position: location,
				map: map
			});
			canvas_elem.removeClass('loading');
		}
		else {
			return false;
		}
		return canvas_id;
	};

	eventcalendar_startup = function() {
		if (eventcalendar.maps_load_startup) {
			$('.'+eventcalendar.prefix+'-map-toggle').each(function(i) {
  				eventcalendar_loadMap(eventcalendar.maps_provider, $(this));
			});
		}
	};

	var clicksToToggle = {
		'openstreetmap': 1,
		'google': 2 // bug: google clicking twice! even with ".one" or .first()!
	};
	var clicksPerId = {};

	eventcalendar_init = function() {
		// chrome needs this
		if (typeof eventcalendar === 'undefined' && typeof window['eventcalendar'] !== 'undefined') {
			var eventcalendar = window['eventcalendar'];
		}

		// startup
		eventcalendar_startup();

		// frontend toggle
		$('body').on('click', '.'+eventcalendar.prefix+'-map-toggle', function(e) {
	    	e.preventDefault();
	    	var canvas_id = eventcalendar_loadMap(eventcalendar.maps_provider, $(this));
	    	if (canvas_id !== false) {
	    		if (!clicksPerId[canvas_id]) {
	    			clicksPerId[canvas_id] = 0;
	    		}
	    		clicksPerId[canvas_id]++;
	    		if (clicksPerId[canvas_id] < clicksToToggle[eventcalendar.maps_provider]) {
	    			return false;
	    		}
				// show or hide
	    		var canvas_elem = $('#'+canvas_id).first();
				if (canvas_elem.hasClass('map-open')) {
					canvas_elem.removeClass('map-open').slideUp('fast').addClass('map-close');
				}
				else {
					canvas_elem.removeClass('map-close').slideDown('fast').addClass('map-open');
				}
				clicksPerId[canvas_id] = 0;
	    	}
	    	return false;
	    });
	};

	$(document).ready(function() {
		eventcalendar_init();
	});//document.ready

}
})(jQuery);