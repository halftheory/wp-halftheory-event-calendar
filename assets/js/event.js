(function($, undefined) {
/** Note: $() will work as an alias for jQuery() inside of this function */
if (typeof event === 'object') {

	eventcalendar_initialize = function() {
		if (typeof google.maps.Map === 'function') {
			// chrome needs this
			if (typeof event === 'undefined' && typeof window['event'] !== 'undefined') {
				var event = window['event'];
			}
			// frontend toggle
		    $('.'+event.prefix+'-map-toggle').click(function(e) {
		    	e.preventDefault();
		    	var canvas_id = event.prefix+'-map-'+$(this).data('id');
		    	if ($('#'+canvas_id).length) {
		    		// add the map
					if ($('#'+canvas_id).children().length === 0) {
						var location = {lat: parseFloat($(this).data('latitude')), lng: parseFloat($(this).data('longitude'))};
						var map = new google.maps.Map(document.getElementById(canvas_id),
							{
								zoom: 10,
								center: location,
								mapTypeId: google.maps.MapTypeId.ROADMAP,
								zoomControl: true
							}
						);
						var marker = new google.maps.Marker({
							position: location,
							map: map
						});
						$('#'+canvas_id).css('display', 'block').css('position', 'relative').css('left', 0);
						return false;
					}
					// show or hide
					if ($('#'+canvas_id).is(':visible')) {
						$('#'+canvas_id).slideUp('fast');
					}
					else {
						$('#'+canvas_id).slideDown('fast');
					}
		    	}
		    	return false;
		    });

		}
	};

}
})(jQuery);
