jQuery(document).ready(function($) {
	
	$('#add-new-group-wrap').insertAfter($('table:last-of-type'));
	
	$('.emma-add-group-button').click(function(){
		var data = {
			'action': 'emma_add_group',
			'groupName': $('input[name="emma_add_new_group"]').val(),
		};
		// We can also pass the url value separately from ajaxurl for front end AJAX implementations
		jQuery.post(ajax_object.ajax_url, data, function(response) {
			response = $.parseJSON(response);
			
			if (response['status'] == 'success') {
				$('input#refresh').trigger('click');
			} else {
				// probably give the user some feedback here about the group not being added.
				$('span.add-group-response').html('Something went wrong! Group not added!');
				
				// Then print out the full response in the console so we can figure out what's up
				console.log(response);
			}
		});
	});
});