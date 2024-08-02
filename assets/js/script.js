jQuery(document).ready(function ($) {
	$("#remove-thumbnails-form").on("submit", function (e) {
		e.preventDefault();
		var form = $(this);
		var progressBar = $("#progress-bar");
		var progressText = $("#progress-text");
		var submitButton = form.find('input[type="submit"]');

		// Calculate total images to process
		var totalImages = 0;
		form.find('input[name="sizes[]"]:checked').each(function () {
			var count = parseInt(
				$(this)
					.parent()
					.text()
					.match(/\((\d+) images\)/)[1]
			);
			totalImages += count;
		});

		progressBar.show();
		progressText.show();
		submitButton.prop("disabled", true);

		function processImages(processedImages) {
			$.ajax({
				url: thumbnailManager.ajax_url,
				type: "POST",
				data: {
					action: "remove_thumbnails",
					nonce: thumbnailManager.nonce,
					sizes: form
						.find('input[name="sizes[]"]:checked')
						.map(function () {
							return $(this).val();
						})
						.get(),
					folders: form
						.find('input[name="folders[]"]:checked')
						.map(function () {
							return $(this).val();
						})
						.get(),
					total_images: totalImages,
					processed_images: processedImages,
				},
				success: function (response) {
					if (response.success) {
						var progress = response.data.progress;
						$("#progress").css("width", progress + "%");
						progressText.text(
							"Processed " +
								response.data.processed_images +
								" out of " +
								totalImages +
								" images. " +
								response.data.removed_count +
								" thumbnails removed, freeing up " +
								response.data.total_size +
								"."
						);

						if (response.data.is_complete) {
							progressText.append(" Process complete!");
							submitButton.prop("disabled", false);
						} else {
							processImages(response.data.processed_images);
						}
					} else {
						progressText.text("Error: " + response.data);
						submitButton.prop("disabled", false);
					}
				},
				error: function () {
					progressText.text("An error occurred. Please try again.");
					submitButton.prop("disabled", false);
				},
			});
		}

		processImages(0);
	});
	$("#select-all-sizes").on("click", function () {
		$('input[name="sizes[]"]').prop("checked", true);
	});
	$("#select-all-folders").on("click", function () {
		$('input[name="folders[]"]').prop("checked", true);
	});
});
