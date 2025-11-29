/**
 * WooCommerce Subscriptions Exporter - AJAX Batch Processing
 * 
 * Handles export functionality with real-time progress updates
 */
jQuery(document).ready(function($) {

	// Only run on export page
	if (!$('.wcsi-exporter-form').length) {
		return;
	}

	// State variables
	var isProcessing = false;
	var isCancelled = false;
	var sessionId = null;
	var importStartTime = null;
	var totalItems = 0;
	var processedItems = 0;
	var elapsedTimerInterval = null;

	// Cache DOM elements
	var $form = $('.wcsi-exporter-form');
	var $exportBtn = $form.find('input[type="submit"][data-action*="download"]');
	var $cronBtn = $form.find('input[type="submit"][data-action*="cron"]');
	var $progressSection = $('#wcs-export-progress-section');
	var $progressBar = $('#wcs-export-progress-bar');
	var $progressText = $('#wcs-export-progress-text');
	var $cancelBtn = $('#wcs-export-cancel');
	var $statusText = $('#wcs-export-status-text');

	// Stats elements
	var $statTotal = $('#wcs-stat-total');
	var $statProcessed = $('#wcs-stat-processed');
	var $statElapsed = $('#wcs-stat-elapsed');
	var $statEstimated = $('#wcs-stat-estimated');
	var $statRemaining = $('#wcs-stat-remaining');

	// Handle Export button click - intercept form submission
	$exportBtn.on('click', function(e) {
		e.preventDefault();
		e.stopPropagation();

		if (isProcessing) {
			return false;
		}

		startAjaxExport();
		return false;
	});

	// Prevent default form submission for the export button
	$form.on('submit', function(e) {
		var $clickedBtn = $form.find('input[type="submit"][clicked="true"]');
		
		// Only intercept the download button, let cron button work normally
		if ($clickedBtn.data('action') && $clickedBtn.data('action').indexOf('download') !== -1) {
			e.preventDefault();
			return false;
		}
	});

	// Mark which button was clicked
	$form.find('input[type="submit"]').on('click', function(e) {
		$form.find('input[type="submit"]').removeAttr('clicked');
		$(this).attr('clicked', 'true');
	});

	// Cancel button handler
	$cancelBtn.on('click', function() {
		if (!confirm(wcsExporterAjax.strings.confirmCancel || 'Are you sure you want to cancel the export?')) {
			return;
		}

		isCancelled = true;
		$cancelBtn.prop('disabled', true).text(wcsExporterAjax.strings.cancelling || 'Cancelling...');

		$.ajax({
			url: wcsExporterAjax.ajaxUrl,
			type: 'POST',
			data: {
				action: 'wcs_export_cancel',
				nonce: wcsExporterAjax.nonce,
				session_id: sessionId
			},
			success: function(response) {
				if (response.success) {
					showMessage('warning', wcsExporterAjax.strings.cancelled || 'Export cancelled.');
				}
			},
			complete: function() {
				resetUI(true); // Hide progress on cancel
			}
		});
	});

	/**
	 * Start AJAX export process
	 */
	function startAjaxExport() {
		// Serialize form data
		var formData = $form.serialize();

		// Reset state
		isProcessing = true;
		isCancelled = false;
		sessionId = null;
		importStartTime = Date.now();
		totalItems = 0;
		processedItems = 0;

		// Hide any previous message
		$('#wcs-export-message').hide();

		// Update UI
		$exportBtn.prop('disabled', true);
		$cronBtn.prop('disabled', true);
		$cancelBtn.show().prop('disabled', false).text(wcsExporterAjax.strings.cancel || 'Cancel Export');
		$progressSection.show();
		$progressBar.css('width', '0%').removeClass('complete');
		$progressText.text('0%');
		$statusText.text(wcsExporterAjax.strings.initializing || 'Initializing export...');
		
		// Reset stats
		$statTotal.text('...');
		$statProcessed.text('0');
		$statElapsed.text('00:00');
		$statEstimated.text('--:--');
		$statRemaining.text('--:--');

		// Start elapsed time updater
		startElapsedTimer();

		// Start export
		$.ajax({
			url: wcsExporterAjax.ajaxUrl,
			type: 'POST',
			data: {
				action: 'wcs_export_start',
				nonce: wcsExporterAjax.nonce,
				form_data: formData
			},
			success: function(response) {
				if (response.success) {
					sessionId = response.data.session_id;
					totalItems = response.data.total;
					$statTotal.text(totalItems);

					if (totalItems === 0) {
						showMessage('warning', wcsExporterAjax.strings.noSubscriptions || 'No subscriptions found matching your criteria.');
						resetUI(true); // Hide progress when no items
						return;
					}

					$statusText.text(response.data.message || 'Processing subscriptions...');
					
					// Start processing batches
					processBatch();
				} else {
					showMessage('error', response.data.message || wcsExporterAjax.strings.error || 'An error occurred.');
					resetUI(true); // Hide progress on error
				}
			},
			error: function(jqXHR) {
				var errorMsg = wcsExporterAjax.strings.error || 'An error occurred during export.';
				if (jqXHR.status === 403) {
					errorMsg = wcsExporterAjax.strings.permissionDenied || 'Permission denied. Please refresh the page and try again.';
				} else if (jqXHR.responseJSON && jqXHR.responseJSON.data && jqXHR.responseJSON.data.message) {
					errorMsg = jqXHR.responseJSON.data.message;
				}
				showMessage('error', errorMsg);
				resetUI(true); // Hide progress on error
			}
		});
	}

	/**
	 * Process a single batch
	 */
	function processBatch() {
		if (!isProcessing || isCancelled || !sessionId) {
			return;
		}

		$.ajax({
			url: wcsExporterAjax.ajaxUrl,
			type: 'POST',
			data: {
				action: 'wcs_export_batch',
				nonce: wcsExporterAjax.nonce,
				session_id: sessionId,
				batch_size: 50
			},
			success: function(response) {
				if (response.success) {
					processedItems = response.data.processed;
					updateProgress(response.data);

					if (response.data.complete) {
						isProcessing = false;
						completeExport();
					} else if (!isCancelled) {
						// Continue processing
						$statusText.text(response.data.message || 'Processing subscriptions...');
						processBatch();
					}
				} else {
					showMessage('error', response.data.message || wcsExporterAjax.strings.batchError || 'Error processing batch.');
					resetUI(true); // Hide progress on error
				}
			},
			error: function() {
				showMessage('error', wcsExporterAjax.strings.connectionError || 'Connection error. Please try again.');
				resetUI(true); // Hide progress on error
			}
		});
	}

	/**
	 * Update progress display
	 */
	function updateProgress(data) {
		var total = data.total || totalItems;
		var processed = data.processed || 0;
		var percent = total > 0 ? Math.round((processed / total) * 100) : 0;

		$progressBar.css('width', percent + '%');
		$progressText.text(percent + '%');
		$statProcessed.text(processed);

		// Time estimates
		updateTimeEstimates(processed, total);
	}

	/**
	 * Start the elapsed time timer
	 */
	function startElapsedTimer() {
		if (elapsedTimerInterval) {
			clearInterval(elapsedTimerInterval);
		}
		
		elapsedTimerInterval = setInterval(function() {
			if (!importStartTime) return;
			
			var elapsedMs = Date.now() - importStartTime;
			var elapsedSeconds = Math.floor(elapsedMs / 1000);
			$statElapsed.text(formatTime(elapsedSeconds));
			
			// Update remaining time based on current progress
			if (processedItems > 0 && totalItems > 0) {
				var rate = processedItems / elapsedSeconds;
				var totalEstimatedSeconds = rate > 0 ? Math.ceil(totalItems / rate) : 0;
				var remainingSeconds = Math.max(0, totalEstimatedSeconds - elapsedSeconds);
				
				$statEstimated.text(formatTime(totalEstimatedSeconds));
				$statRemaining.text(formatTime(remainingSeconds));
			}
		}, 1000);
	}

	/**
	 * Stop the elapsed time timer
	 */
	function stopElapsedTimer() {
		if (elapsedTimerInterval) {
			clearInterval(elapsedTimerInterval);
			elapsedTimerInterval = null;
		}
	}

	/**
	 * Update time estimates
	 */
	function updateTimeEstimates(processed, total) {
		if (!importStartTime || processed === 0) {
			$statEstimated.text('--:--');
			$statRemaining.text('--:--');
			return;
		}

		var elapsedMs = Date.now() - importStartTime;
		var elapsedSeconds = Math.floor(elapsedMs / 1000);
		var rate = processed / elapsedSeconds;
		var totalEstimatedSeconds = rate > 0 ? Math.ceil(total / rate) : 0;
		var remainingSeconds = Math.max(0, totalEstimatedSeconds - elapsedSeconds);

		$statElapsed.text(formatTime(elapsedSeconds));
		$statEstimated.text(formatTime(totalEstimatedSeconds));
		$statRemaining.text(formatTime(remainingSeconds));
	}

	/**
	 * Format seconds to MM:SS or HH:MM:SS
	 */
	function formatTime(totalSeconds) {
		if (totalSeconds <= 0) return '00:00';

		var hours = Math.floor(totalSeconds / 3600);
		var minutes = Math.floor((totalSeconds % 3600) / 60);
		var seconds = totalSeconds % 60;

		if (hours > 0) {
			return String(hours).padStart(2, '0') + ':' + 
				   String(minutes).padStart(2, '0') + ':' + 
				   String(seconds).padStart(2, '0');
		}

		return String(minutes).padStart(2, '0') + ':' + String(seconds).padStart(2, '0');
	}

	/**
	 * Complete export and trigger download
	 */
	function completeExport() {
		stopElapsedTimer();
		
		$progressBar.css('width', '100%').addClass('complete');
		$progressText.text('100%');
		$statusText.text(wcsExporterAjax.strings.completed || 'Export completed!');
		$statRemaining.text('00:00');
		
		// Hide cancel button since export is done
		$cancelBtn.hide();

		// Get download URL
		$.ajax({
			url: wcsExporterAjax.ajaxUrl,
			type: 'POST',
			data: {
				action: 'wcs_export_complete',
				nonce: wcsExporterAjax.nonce,
				session_id: sessionId
			},
			success: function(response) {
				if (response.success && response.data.download_url) {
					// Show success message with download link
					var downloadLink = '<a href="' + response.data.download_url + '" class="button button-primary" style="margin-left: 10px;">Download CSV</a>';
					showMessage('success', (wcsExporterAjax.strings.exportComplete || 'Export completed successfully!') + ' ' + downloadLink);
					
					// Trigger automatic download
					window.location.href = response.data.download_url;
				} else {
					showMessage('error', response.data.message || 'Could not generate download link.');
				}
			},
			error: function() {
				showMessage('error', 'Error generating download link.');
			},
			complete: function() {
				// Re-enable buttons but keep progress section visible
				resetUI(false); // Don't hide progress section
			}
		});
	}

	/**
	 * Show message to user
	 */
	function showMessage(type, message) {
		var $messageDiv = $('#wcs-export-message');
		
		if (!$messageDiv.length) {
			$messageDiv = $('<div id="wcs-export-message" class="notice" style="margin-top: 15px;"></div>');
			$progressSection.after($messageDiv);
		}

		$messageDiv
			.removeClass('notice-success notice-warning notice-error')
			.addClass('notice-' + type)
			.html('<p>' + message + '</p>')
			.show();

		// Auto-hide success messages after 5 seconds
		if (type === 'success') {
			setTimeout(function() {
				$messageDiv.fadeOut();
			}, 5000);
		}
	}

	/**
	 * Reset UI to initial state
	 */
	function resetUI(hideProgress) {
		isProcessing = false;
		isCancelled = false;
		sessionId = null;
		stopElapsedTimer();
		
		$exportBtn.prop('disabled', false);
		$cronBtn.prop('disabled', false);
		$cancelBtn.prop('disabled', false).text(wcsExporterAjax.strings.cancel || 'Cancel Export');
		
		// Only hide progress section if explicitly requested (e.g., on cancel or error)
		if (hideProgress) {
			$progressSection.slideUp();
		}
	}

	// Window beforeunload warning
	$(window).on('beforeunload', function() {
		if (isProcessing) {
			return wcsExporterAjax.strings.closeWarning || 'Export is in progress. Are you sure you want to leave?';
		}
	});

});