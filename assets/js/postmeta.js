var geo_search_apply;

jQuery(document).ready(function ($) {
/** Note: $() will work as an alias for jQuery() inside of this function */
if (typeof postmeta === 'object') {

	// datetimepicker
	if (typeof $.fn.datetimepicker !== 'undefined') {
		var args = {
			datepicker:true,
			timepicker:true,
			format:'d-m-Y H:i'
		};
		$('#'+postmeta.prefix+'_date_start').datetimepicker(args);
		$('#'+postmeta.prefix+'_date_end').datetimepicker(args);
	}

	// geo_search
	$('#'+postmeta.prefix+'_geo_search').keydown(function(e) {
		if (e.keyCode === 13) {
			e.preventDefault();
			$('#'+postmeta.prefix+'_geo_search_button').click();
			return false;
		}
	});
    $('#'+postmeta.prefix+'_geo_search_button').click(function(e) {
    	e.preventDefault();

    	var q = $('#'+postmeta.prefix+'_geo_search').val();
    	var r = $('#'+postmeta.prefix+'_geo_search_result');
    	if (q === '') {
    		r.fadeOut('fast');
    		return false;
    	}

        var data = {
            action: postmeta.prefix+'_geo_search',
            q: q
        };

		r.fadeOut('fast', function() {
			$(this).html('Loading...');
		}).fadeIn('fast');

        $.post(postmeta.ajaxurl, data, function(data) {
            var res = '';
            for (var row in data) {
                row = data[row];
                if (row.lat!=undefined && row.lon!=undefined && row.display_name!=undefined) {
                    res += '<p><a onclick="geo_search_apply(\''+row.display_name.replace('\'','\\&apos;').replace('"','&quot;')+'\',\''+row.lat+'\',\''+row.lon+'\');">';
                    if (row.icon!=undefined) {
                        res += '<img src="'+row.icon+'" alt="'+row.type+'" /> ';
                    }
                    res += row.display_name+'</a></p>';
                }
            }
			r.fadeOut('fast', function() {
				$(this).html(res);
			}).fadeIn('fast');
        },'json');

		return false;
	});
	geo_search_apply = function (address, latitude, longitude) {
		$('#'+postmeta.prefix+'_geo_address').val(address);
		$('#'+postmeta.prefix+'_geo_latitude').val(latitude);
		$('#'+postmeta.prefix+'_geo_longitude').val(longitude);
		$('#'+postmeta.prefix+'_geo_search_result').fadeOut('fast');
	};

	// frontend toggle
    $('.'+postmeta.prefix+'-toggle').click(function(e) {
    	e.preventDefault();
    	elem = $(this).parent().parent().find('.'+postmeta.prefix);
    	if (typeof elem === 'object') {
	    	if (elem.is(':visible')) {
	    		elem.slideUp('fast');
	    	}
	    	else {
	    		elem.slideDown('fast');
	    	}
    	}
    	return false;
    });

}
});