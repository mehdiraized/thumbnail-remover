jQuery(document).ready(function ($) {
	$("#select-all-sizes").click(function () {
		$('#list_sizes input[type="checkbox"]').prop("checked", true);
	});

	$("#select-all-folders").click(function () {
		$('#list_folders input[type="checkbox"]').prop("checked", true);
	});

	$("#remove-thumbnails-form").submit(function (e) {
		e.preventDefault();

		if (!confirm(thumbnailManager.confirm_message)) {
			return;
		}

		var sizes = [];
		var folders = [];

		$('input[name="sizes[]"]:checked').each(function () {
			sizes.push($(this).val());
		});

		$('input[name="folders[]"]:checked').each(function () {
			folders.push($(this).val());
		});

		if (sizes.length === 0 || folders.length === 0) {
			alert("Please select at least one size and one folder.");
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
});
