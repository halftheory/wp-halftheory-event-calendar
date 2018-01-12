if (typeof GCA_Theme !== 'undefined') {
(function($, undefined) {
	/** Note: $() will work as an alias for jQuery() inside of this function */

	GCA_Theme.events.ready.popup = function() {
		var name = 'popup';
		// single
		if (typeof window[name] !== 'undefined') {
			if (window[name].popupClass !== undefined) {
				var popupClass = window[name].popupClass;
				// store for later
				if (window[name].home_url !== undefined) {
					var home_url = window[name].home_url;
				}

				// handle links inside popup
				$('body.'+popupClass+' a').click(function() {
					if ($(this).attr('target') == '_self' || $(this).attr('target') === '' || $(this).attr('target') === undefined) {
					    var href = $(this).attr('href');
					    if (href) {
					    	if (home_url !== undefined) {
					    		if (href.indexOf(home_url) === -1 || href === home_url) {
						        	$(this).attr('target', '_top');
					    		}
					    		else {
						        	$(this).attr('href', GCA_Theme.addClassToUrl(href, popupClass));
					    		}
					    	}
					    	// check that is this domain! - could check full domain+path with rest api?
					    	else if (location.hostname === this.hostname || !this.hostname.length) {
					        	$(this).attr('href', GCA_Theme.addClassToUrl(href, popupClass));
					        }
					        // external
					        else {
					        	$(this).attr('target', '_top');
					        }
					    }
					}
				});
				// handle forms inside popup
				$('body.'+popupClass+' form').submit(function() {
					if ($(this).attr('target') == '_self' || $(this).attr('target') === '' || $(this).attr('target') === undefined) {
					    var action = $(this).attr('action');
					    if (action) {
					        $(this).attr('action', GCA_Theme.addClassToUrl(action, popupClass));
					    }
					}
				});

			}
		}
	};

})(jQuery);
}