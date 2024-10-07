jQuery(document).ready(function ($) {
	$("#select-all-sizes").click(function () {
		$('#list_sizes input[type="checkbox"]').prop("checked", true);
	});

	$("#select-all-folders").click(function () {
		$('#list_folders input[type="checkbox"]').prop("checked", true);
	});

	$("#remove-thumbnails-form").submit(function (e) {
		e.preventDefault();

		var sizes = [];
		var folders = [];

		$('input[name="sizes[]"]:checked').each(function () {
			sizes.push($(this).val());
		});

		$('input[name="folders[]"]:checked').each(function () {
			folders.push($(this).val());
		});

		if (sizes.length === 0 && folders.length === 0) {
			alert("Please select at least one size or one folder.");
			return;
		}

		var confirmMessage = "";
		if (sizes.length === 0) {
			confirmMessage =
				"Are you sure you want to remove ALL thumbnail sizes from the selected folders?";
		} else if (folders.length === 0) {
			confirmMessage =
				"Are you sure you want to remove the selected sizes from ALL folders?";
		} else {
			confirmMessage =
				"Are you sure you want to remove the selected thumbnail sizes from the selected folders?";
		}

		if (!confirm(confirmMessage)) {
			return;
		}

		$("#progress-bar, #progress-text").show();
		$("#result-message").hide();

		$.ajax({
			url: thumbnailManager.ajax_url,
			type: "POST",
			data: {
				action: "remove_thumbnails",
				nonce: thumbnailManager.nonce,
				sizes: sizes,
				folders: folders,
			},
			success: function (response) {
				if (response.success) {
					$("#progress").css("width", "100%");
					$("#progress-text").text("100% Complete");
					$("#result-message")
						.html(response.data.message)
						.removeClass("notice-error")
						.addClass("notice-success")
						.show();
				} else {
					$("#result-message")
						.html(response.data.message)
						.removeClass("notice-success")
						.addClass("notice-error")
						.show();
				}
			},
			error: function () {
				$("#result-message")
					.html("An error occurred. Please try again.")
					.removeClass("notice-success")
					.addClass("notice-error")
					.show();
			},
			complete: function () {
				$("#progress-bar, #progress-text").hide();
			},
		});
	});

	// optimize
	$("#image-optimizer-form").submit(function (e) {
		e.preventDefault();
		var optimizationLevel = $("#optimization-level").val();

		$("#optimization-progress").show();
		$("#optimization-result").empty();

		$.ajax({
			url: thumbnailManager.ajax_url,
			type: "POST",
			data: {
				action: "optimize_images",
				nonce: thumbnailManager.nonce,
				optimization_level: optimizationLevel,
			},
			success: function (response) {
				if (response.success) {
					$("#optimization-progress-bar").val(response.data.progress);
					$("#optimization-progress-text").text(
						response.data.progress.toFixed(2) + "%"
					);

					if (response.data.message) {
						$("#optimization-result").html(
							"<p>" + response.data.message + "</p>"
						);
						$("#optimization-progress").hide();
					} else {
						// Continue optimizing
						$("#image-optimizer-form").submit();
					}
				} else {
					$("#optimization-result").html(
						'<p class="error">' + response.data.message + "</p>"
					);
					$("#optimization-progress").hide();
				}
			},
			error: function () {
				$("#optimization-result").html(
					'<p class="error">An error occurred. Please try again.</p>'
				);
				$("#optimization-progress").hide();
			},
		});
	});

	// backup
	var $backupYear = $("#backup_year");
	var $backupMonth = $("#backup_month");

	$('input[name="backup_type"]').change(function () {
		if ($(this).val() === "date") {
			$backupYear.prop("disabled", false);
			updateMonthOptions();
		} else {
			$backupYear.prop("disabled", true);
			$backupMonth.prop("disabled", true);
		}
	});

	$backupYear.change(function () {
		updateMonthOptions();
	});

	function updateMonthOptions() {
		var availableDates = thumbnailManager.availableDates;
		// var availableDates = JSON.parse(thumbnailManager.availableDates);
		var selectedYear = $backupYear.val();
		$backupMonth.empty().append(
			$("<option>", {
				value: "",
				text: "Select Month",
			})
		);

		if (selectedYear && availableDates[selectedYear]) {
			$.each(availableDates[selectedYear], function (index, month) {
				$backupMonth.append(
					$("<option>", {
						value: month,
						text: month,
					})
				);
			});
			$backupMonth.prop("disabled", false);
		} else {
			$backupMonth.prop("disabled", true);
		}
	}

	$("#backup-images-form").submit(function (e) {
		e.preventDefault();

		var backupType = $('input[name="backup_type"]:checked').val();
		var backupYear = $("#backup_year").val();
		var backupMonth = $("#backup_month").val();

		if (backupType === "date" && (!backupYear || !backupMonth)) {
			alert("Please select both year and month for date-specific backup.");
			return;
		}

		$("#backup-progress").show();
		$("#backup-result").empty();

		$.ajax({
			url: thumbnailManager.ajax_url,
			type: "POST",
			data: {
				action: "backup_images",
				nonce: thumbnailManager.nonce,
				backup_type: backupType,
				backup_year: backupYear,
				backup_month: backupMonth,
			},
			success: function (response) {
				if (response.success) {
					$("#backup-progress-bar").val(100);
					$("#backup-progress-text").text("100%");
					$("#backup-result").html("<p>" + response.data.message + "</p>");
					if (response.data.download_url) {
						$("#backup-result").append(
							'<p><a href="' +
								response.data.download_url +
								'" class="button">Download Backup</a></p>'
						);
					}
				} else {
					$("#backup-result").html(
						'<p class="error">' + response.data.message + "</p>"
					);
				}
				$("#backup-progress").hide();
			},
			error: function (jqXHR, textStatus, errorThrown) {
				console.error("AJAX error:", textStatus, errorThrown);
				$("#backup-result").html(
					'<p class="error">An error occurred. Please try again. Error details: ' +
						textStatus +
						" - " +
						errorThrown +
						"</p>"
				);
				$("#backup-progress").hide();
			},
		});
	});
});
