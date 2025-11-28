jQuery(document).ready(function($){

	// Get current tab from URL
	function getCurrentTabFromUrl() {
		var urlParams = new URLSearchParams(window.location.search);
		return urlParams.get('tab') || 'wcsi-export';
	}

	// Update URL without page reload
	function updateUrlTab(tabId) {
		var url = new URL(window.location.href);
		url.searchParams.set('tab', tabId);
		window.history.pushState({tab: tabId}, '', url);
	}

	// Show the correct tab content
	function showTab(tabId) {
		// Update tab styling
		$('.wcsi-exporter-tabs').removeClass('nav-tab-active');
		$('.wcsi-exporter-tabs[data-tab="' + tabId + '"]').addClass('nav-tab-active');

		// Hide all tables, show the selected one
		$('.wcsi-exporter-form table').hide();
		$('#' + tabId + '-table').show();

		// Show/hide submit buttons based on tab
		if (tabId === 'wcsi-exports') {
			$('.wcsi-exporter-form .submit').hide();
		} else {
			$('.wcsi-exporter-form .submit').show();
		}
	}

	// Initialize: show correct tab on page load
	var initialTab = getCurrentTabFromUrl();
	showTab(initialTab);

	// Handle tab clicks
	$('.wcsi-exporter-tabs').click(function(e) {
		e.preventDefault();
		
		var tabId = $(this).data('tab') || $(this).attr('id');
		
		// Update URL
		updateUrlTab(tabId);
		
		// Show the tab
		showTab(tabId);
	});

	// Handle browser back/forward buttons
	$(window).on('popstate', function(e) {
		var tabId = getCurrentTabFromUrl();
		showTab(tabId);
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
		var headersTab = $('.wcsi-exporter-tabs[data-tab="wcsi-headers"]');

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