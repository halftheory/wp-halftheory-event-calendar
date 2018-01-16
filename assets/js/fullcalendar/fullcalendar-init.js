jQuery(document).ready(function ($) {
/** Note: $() will work as an alias for jQuery() inside of this function */
if (typeof fullcalendar === 'object') {

	// fullcalendar
	if (typeof $.fn.fullCalendar !== 'undefined') {
		var qtip = {};
		if (typeof fullcalendar.qtip === 'object') {
			$.extend(true, qtip, fullcalendar.qtip);
		}

		var args = {
			editable: false,
			eventLimit: true,
			eventSources: [{
				url: fullcalendar.ajaxurl,
				data: fullcalendar.data,
				allDayDefault: false,
				className: fullcalendar.prefix
			}],
			// qtip
		    eventRender: function(event, element, view) {
		    	if (typeof $.fn.qtip === 'function' && view.name == 'month') {
					if (event.blog_id && event.post_id > 0) {
						var event_id = fullcalendar.prefix+'-'+event.blog_id+'-'+event.post_id;
						
						var eventClassName = fullcalendar.prefix;
						if (event.className) {
							eventClassName += ' '+event.className;
						}
						if (qtip.style.classes) {
							eventClassName += ' '+qtip.style.classes;
						}

						var event_data = {
							action: fullcalendar.prefix+'_qtip',
							blog_id: event.blog_id,
							post_id: event.post_id
						};

						var event_qtip = {
							id: event_id, // id already exists, will return an int
							content:{
								text: function(event, api) {
									// use existing content
									if (event_id !== api.get('id') && $('#qtip-'+event_id).length) {
										var content = $('#qtip-'+event_id+' .qtip-content').html();
										if (content !== '') {
											return content;
										}
									}
									// create new content
							        $.post(fullcalendar.ajaxurl, event_data, function(data) {
										api.set('content.text', data);
									},'html');
							        // loading text
									if (qtip.content.text) {
										return qtip.content.text;
									}
									return '';
								}
							},
							style: {
								classes: eventClassName
							}
						};
						event_qtip = $.extend(true, {}, qtip, event_qtip);
						element.qtip(event_qtip);
					}
				}
		    },
			firstDay: 1,
			header: {
				left: 'prev,next today',
				center: 'title',
				right: 'month,listWeek,listDay'
			},
			loading: function(bool) {
				if (bool) {
					$(this).addClass('loading');
				}
				else {
					$(this).removeClass('loading');
				}
			},
			navLinks: true,
			views: {
				month: { buttonText: 'month', displayEventTime: false },
				listWeek: { buttonText: 'week', titleFormat: 'D MMMM Y' },
				listDay: { buttonText: 'day', titleFormat: 'D MMMM Y' }
			}
      	};
		if (typeof fullcalendar.args === 'object') {
			$.extend(true, args, fullcalendar.args);
		}
		$('.'+fullcalendar.prefix+'-fullcalendar').fullCalendar(args);
	}

}
});