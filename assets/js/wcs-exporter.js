jQuery(document).ready(function($){

	// show/hide tables depending on the tabs chosen
	$('.wcsi-exporter-tabs').click(function(e) {
		e.preventDefault();

		$('.wcsi-exporter-tabs').removeClass('nav-tab-active');
		$(this).addClass('nav-tab-active');

		$('.wcsi-exporter-form table').hide();
		$('#' + $(this).attr('id') + '-table').show();

		if ( $( this ).attr( 'id' ) == 'wcsi-crons' ) {
			$('.wcsi-exporter-form .submit').hide();
		} else {
			$('.wcsi-exporter-form .submit').show();
		}
	});

	// multiple action for the form
    var wcsi_exporter_form = $('.wcsi-exporter-form'),
        wcsi_exporter_form_buttons = wcsi_exporter_form.find('input[type="submit"]');

    wcsi_exporter_form_buttons.on('click', function(e){
        wcsi_exporter_form_buttons.removeAttr('clicked');
        $(this).attr('clicked', 'true');
    });

    wcsi_exporter_form.submit(function(e){
        var wcsi_exporter_form_active_button = wcsi_exporter_form.find('input[type="submit"][clicked="true"]'),
            wcsi_exporter_form_action = wcsi_exporter_form_active_button.data('action');

        wcsi_exporter_form.attr('action', wcsi_exporter_form_action);
    });

	// Show/hide Appstle format note and toggle CSV headers tab
	function toggleAppstleFormatUI() {
		var exportFormat = $('#export_format').val();
		var appstleNote = $('#appstle-format-note');
		var headersTab = $('#wcsi-headers');

		if (exportFormat === 'appstle') {
			appstleNote.show();
			headersTab.hide();
		} else {
			appstleNote.hide();
			headersTab.show();
		}
	}

	// Initial state
	toggleAppstleFormatUI();

	// On change
	$('#export_format').on('change', function() {
		toggleAppstleFormatUI();
	});
});