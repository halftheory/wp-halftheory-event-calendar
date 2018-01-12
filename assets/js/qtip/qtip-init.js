if (typeof GCA_Theme !== 'undefined') {
(function($, undefined) {
	/** Note: $() will work as an alias for jQuery() inside of this function */

	// create the function if it doesn't exist
	GCA_Theme.qtip = function() {
		if (typeof $.fn.qtip === 'function') {
			var name = 'qtip';

        	function normal(elem, opt) {
        		if (GCA_Theme.is_mobile === 'phone') {
        			return;
        		}
		        if (typeof elem !== 'string') {
		            elem = $(elem).toString();
		        }
        		if (!$(elem)) {
        			return;
        		}
				var options = {
					position: {
						at: 'bottom left',
						my: 'top left',
						adjust: {
							x: GCA_Theme.em2px,
							y: 0
						}
		            },
		            style: {
						classes: 'qtip-blue qtip-shadow'
					}
	        	};
        		if (opt) {
    				$.extend(true, options, opt);
        		}
        		$(elem).qtip(options);
        	}

        	function popup(elem, popupClass, opt, mobile) {
        		if (!mobile) {
        			mobile = false;
        		}
        		if (!mobile && GCA_Theme.is_mobile === 'phone') {
        			return;
        		}
		        if (typeof elem !== 'string') {
		            elem = $(elem).toString();
		        }
		        // maybe a url
		        if (elem.indexOf('http://') !== -1 || elem.indexOf('https://') !== -1) { // todo: check relative urls
        			elem = $('<a href="'+elem+'"></a>').appendTo('body');
		        }
        		if (!$(elem)) {
        			return;
        		}
        		if (!popupClass) {
        			popupClass = GCA_Theme.prefix_input+'-popup';
        		}
        		var options = {
        			"ready" : false,
        			"y" : $('header > *').first().outerHeight(true)
        		};
        		if (opt) {
    				$.extend(true, options, opt);
        		}
	     		$(elem).each(function() {
			    	// check that is this domain!
			    	if (location.hostname !== this.hostname) {
			        	return;
			        }
	     			var href = $(this).attr('href');
			        $(this).qtip({
			            content: {
		            		text: '<iframe src="' + GCA_Theme.addClassToUrl(href,popupClass) + '"><p>Your browser does not support iframes.</p></iframe>',
			                button: 'Close'
			            },
			            position: {
							target: $(window),
							at: 'top center',
							my: 'top center',
			                viewport: $('body'),
			                adjust: {
		            			scroll: false,
		            			y: options.y
		        			}
			            },
				        show: {
				        	ready: options.ready,
				        	event: 'click',
				        	solo: true,
				            modal: {
				                on: true,
				                blur: true,
				                escape: true
				            }
				        },
				        hide: {
				        	event: 'click unfocus'
				        },
			            style: {
			            	classes: popupClass,
			            	def: false
			            },
					    events: {
					        render: function(event, api) {
					        	iframe = api.elements.tooltip.find('iframe:eq(0)');
					        	if (!$(iframe)) {
					        		return;
					        	}
					        	// bind iframe
					        	var iframe_id = popupClass+'-'+api.get('id');
					        	$(iframe).attr('id', iframe_id);

					        	// set arbitrary height
					        	var wrapper = $(iframe).parent();
					        	var h_padding = parseInt(wrapper.css("paddingTop"), 10) + parseInt(wrapper.css("paddingBottom"), 10);
					        	var h = $(window).height() - (options.y * 2) - h_padding;
					        	$(iframe).height(h);
								api.set({
								    'style.height': h
								});

								$(iframe).load(function() {
						        	// iframe didn't make it
					        		if (document.getElementById(iframe_id).contentDocument === null) {
					        			return;
					        		}
						        	// set height
						        	iframe_body = $(this).contents().find('body');
									$(iframe_body).css("height", "auto");
									h = $(iframe_body).outerHeight(true) + (h_padding * 0.5);
									$(this).height(h);
									api.set({
									    'style.height': h
									});
									// poll for changes
									setInterval(function(elem, iframe_id) {
							        	// iframe didn't make it
						        		if (document.getElementById(iframe_id).contentDocument === null) {
						        			return;
						        		}
										if (!$(elem).is(':visible')) {
											return;
										}
										var h_new = $(elem).contents().find('body').outerHeight(true) + (h_padding * 0.5);
										if (h_new != h && h_new > GCA_Theme.em2px) {
											$(elem).animate({height: h_new + 'px'}, 'fast');
											h = h_new;
										}
									}, 500, iframe, iframe_id);
		    					});
					        }
					    }
					}).click(function(e){
		        		if (!mobile && GCA_Theme.is_mobile === 'phone') {
		        			return;
		        		}
						e.preventDefault();
						return false;
					});
				});
			}

			// iterate over vars
			// array
			var i = 0;
			if (typeof window[name+i] !== 'undefined') {
				while (typeof window[name+i] !== 'undefined') {
					if (window[name+i].selector !== undefined) {
						normal(window[name+i].selector, window[name+i].options);
					}
					else if (window[name+i].popup !== undefined) {
						popup(window[name+i].popup, window[name+i].popupClass, window[name+i].options, window[name+i].mobile);
					}
					++i;
				}
			}
			// single
			else if (typeof window[name] !== 'undefined') {
				if (window[name].selector !== undefined) {
					normal(window[name].selector, window[name].options);
				}
				else if (window[name].popup !== undefined) {
					popup(window[name].popup, window[name].popupClass, window[name].options, window[name].mobile);
				}
			}
		}
	};

	// start
	GCA_Theme.events.ready.qtip = 'qtip';

})(jQuery);
}