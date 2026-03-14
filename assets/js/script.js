jQuery(function ($) {
	function collectValues(selector) {
		return $(selector)
			.map(function () {
				return $(this).val();
			})
			.get();
	}

	function setProgress($container, value) {
		const progress = Math.max(0, Math.min(100, Number(value) || 0));
		$container.prop("hidden", false);
		$container.find(".trpl-progress-bar span").css("width", progress + "%");
		$container.find(".trpl-progress-text").text(progress + "%");
	}

	function hideProgress($container) {
		$container.prop("hidden", true);
		$container.find(".trpl-progress-bar span").css("width", "0%");
		$container.find(".trpl-progress-text").text("0%");
	}

	function renderNotice($container, message, type) {
		const cssClass = type === "error" ? "notice notice-error" : "notice notice-success";
		$container.html('<div class="' + cssClass + '"><p>' + message + "</p></div>");
	}

	function ajaxPost(action, data) {
		return $.ajax({
			url: thumbnailManager.ajax_url,
			type: "POST",
			dataType: "json",
			data: $.extend(
				{
					action: action,
					nonce: thumbnailManager.nonce,
				},
				data || {}
			),
		});
	}

	function runJob(options) {
		return ajaxPost(options.startAction, options.startData)
			.then(function (response) {
				if (!response.success) {
					throw new Error(response.data && response.data.message ? response.data.message : thumbnailManager.i18n.error);
				}

				const jobId = response.data.job_id;

				function tick() {
					return ajaxPost(options.processAction, { job_id: jobId }).then(function (processResponse) {
						if (!processResponse.success) {
							throw new Error(processResponse.data && processResponse.data.message ? processResponse.data.message : thumbnailManager.i18n.error);
						}

						const data = processResponse.data;
						if (typeof options.onProgress === "function") {
							options.onProgress(data);
						}

						if (data.complete) {
							if (typeof options.onComplete === "function") {
								options.onComplete(data, response.data);
							}
							return data;
						}

						return tick();
					});
				}

				return tick();
			})
			.catch(function (error) {
				if (typeof options.onError === "function") {
					options.onError(error);
				}
			});
	}

	function formatBytes(rawBytes) {
		const bytes = Number(rawBytes) || 0;
		if (bytes === 0) {
			return "0 B";
		}

		const units = ["B", "KB", "MB", "GB", "TB"];
		const exponent = Math.min(Math.floor(Math.log(bytes) / Math.log(1024)), units.length - 1);
		const value = bytes / Math.pow(1024, exponent);
		return value.toFixed(value >= 10 || exponent === 0 ? 0 : 1) + " " + units[exponent];
	}

	function renderAnalysis(summary) {
		const cards = [
			{ label: "Attachments scanned", value: summary.attachments || 0 },
			{ label: "Thumbnail files", value: summary.thumbnail_files || 0 },
			{ label: "Thumbnail storage", value: formatBytes(summary.thumbnail_bytes || 0) },
			{ label: "Orphan thumbnails", value: summary.orphans || 0 },
			{ label: "Missing size records", value: summary.missing_sizes || 0 },
			{ label: "Probably unused media", value: summary.unused_media || 0 },
		];

		let html = '<div class="trpl-cards">';
		cards.forEach(function (card) {
			html += '<div class="trpl-card"><strong>' + card.value + '</strong><span>' + card.label + "</span></div>";
		});
		html += "</div>";

		html += '<h3>Per-size analytics</h3>';
		html += '<table class="widefat striped"><thead><tr><th>Size</th><th>Files</th><th>Storage</th><th>Missing</th><th>Orphans</th><th>Last seen</th></tr></thead><tbody>';

		const sizeRows = Object.keys(summary.size_analytics || {}).sort();
		if (!sizeRows.length) {
			html += "<tr><td colspan='6'>No size data found.</td></tr>";
		} else {
			sizeRows.forEach(function (key) {
				const row = summary.size_analytics[key];
				html += "<tr>";
				html += "<td>" + row.label + (row.dimensions ? " <code>" + row.dimensions + "</code>" : "") + "</td>";
				html += "<td>" + (row.count || 0) + "</td>";
				html += "<td>" + formatBytes(row.bytes || 0) + "</td>";
				html += "<td>" + (row.missing || 0) + "</td>";
				html += "<td>" + (row.orphans || 0) + "</td>";
				html += "<td>" + (row.last_seen || "—") + "</td>";
				html += "</tr>";
			});
		}
		html += "</tbody></table>";

		html += '<h3>Probably unused media</h3>';
		html += '<p>' + (summary.unused_media || 0) + " item(s), " + formatBytes(summary.unused_media_bytes || 0) + " total.</p>";
		html += '<table class="widefat striped"><thead><tr><th>Attachment</th><th>Path</th><th>Size</th><th>Date</th></tr></thead><tbody>';
		if (!summary.unused_items || !summary.unused_items.length) {
			html += "<tr><td colspan='4'>No unused media found in this scan.</td></tr>";
		} else {
			summary.unused_items.forEach(function (item) {
				const title = item.edit_link ? '<a href="' + item.edit_link + '">' + (item.title || ("#" + item.attachment_id)) + "</a>" : (item.title || ("#" + item.attachment_id));
				html += "<tr>";
				html += "<td>" + title + "</td>";
				html += "<td><code>" + (item.relative_path || "") + "</code></td>";
				html += "<td>" + formatBytes(item.bytes || 0) + "</td>";
				html += "<td>" + (item.date || "—") + "</td>";
				html += "</tr>";
			});
		}
		html += "</tbody></table>";

		return html;
	}

	function renderPreview(summary) {
		if (!summary || !summary.total_files) {
			return '<div class="notice notice-warning"><p>' + thumbnailManager.i18n.previewEmpty + "</p></div>";
		}

		let html = '<div class="trpl-cards">';
		html += '<div class="trpl-card"><strong>' + summary.total_files + '</strong><span>Matching files</span></div>';
		html += '<div class="trpl-card"><strong>' + summary.total_size + '</strong><span>Recoverable storage</span></div>';
		html += '<div class="trpl-card"><strong>' + (summary.orphans || 0) + '</strong><span>Orphan thumbnails</span></div>';
		html += "</div>";

		html += '<table class="widefat striped"><thead><tr><th>Size</th><th>Files</th><th>Storage</th></tr></thead><tbody>';
		Object.keys(summary.sizes || {}).forEach(function (sizeName) {
			const row = summary.sizes[sizeName];
			html += "<tr>";
			html += "<td>" + sizeName + "</td>";
			html += "<td>" + row.count + "</td>";
			html += "<td>" + formatBytes(row.bytes) + "</td>";
			html += "</tr>";
		});
		html += "</tbody></table>";

		return html;
	}

	$("#trpl-run-analysis").on("click", function () {
		const $progress = $("#trpl-analysis-progress");
		const $results = $("#trpl-analysis-results");
		$results.empty();
		setProgress($progress, 0);

		runJob({
			startAction: "trpl_start_analysis",
			processAction: "trpl_process_analysis",
			startData: {},
			onProgress: function (data) {
				setProgress($progress, data.progress);
			},
			onComplete: function (data) {
				hideProgress($progress);
				$results.html(renderAnalysis(data.summary));
			},
			onError: function (error) {
				hideProgress($progress);
				renderNotice($results, error.message || thumbnailManager.i18n.error, "error");
			},
		});
	});

	$("#trpl-preview-delete").on("click", function () {
		const sizes = collectValues('#trpl-delete-form input[name="sizes[]"]:checked');
		const folders = collectValues('#trpl-delete-form input[name="folders[]"]:checked');
		const $results = $("#trpl-preview-results");
		$results.html('<p>' + thumbnailManager.i18n.processing + "</p>");

		ajaxPost("trpl_preview_delete", { sizes: sizes, folders: folders })
			.done(function (response) {
				if (!response.success) {
					renderNotice($results, response.data && response.data.message ? response.data.message : thumbnailManager.i18n.error, "error");
					return;
				}
				$results.html(renderPreview(response.data.summary));
			})
			.fail(function () {
				renderNotice($results, thumbnailManager.i18n.error, "error");
			});
	});

	$("#trpl-delete-form").on("submit", function (event) {
		event.preventDefault();
		if (!window.confirm(thumbnailManager.i18n.confirmTrash)) {
			return;
		}

		const sizes = collectValues('#trpl-delete-form input[name="sizes[]"]:checked');
		const folders = collectValues('#trpl-delete-form input[name="folders[]"]:checked');
		const $progress = $("#trpl-delete-progress");
		const $results = $("#trpl-delete-results");
		setProgress($progress, 0);
		$results.empty();

		runJob({
			startAction: "trpl_start_delete",
			processAction: "trpl_process_delete",
			startData: { sizes: sizes, folders: folders },
			onProgress: function (data) {
				setProgress($progress, data.progress);
			},
			onComplete: function (data, startData) {
				hideProgress($progress);
				const message =
					"Moved " +
					(data.result.moved || 0) +
					" file(s) to Trash, recovered " +
					formatBytes(data.result.bytes || 0) +
					", orphan thumbnails: " +
					(data.result.orphans || 0) +
					". Trash batch: <code>" +
					(startData.trash_batch_id || data.trash_batch_id || "") +
					"</code>.";
				renderNotice($results, message, "success");
			},
			onError: function (error) {
				hideProgress($progress);
				renderNotice($results, error.message || thumbnailManager.i18n.error, "error");
			},
		});
	});

	$(document).on("click", ".trpl-restore-trash", function () {
		const $button = $(this);
		const batchId = $button.data("batch-id");
		const $results = $("#trpl-trash-results");

		if (!window.confirm(thumbnailManager.i18n.confirmRestore)) {
			return;
		}

		$button.prop("disabled", true);
		ajaxPost("trpl_restore_trash", { batch_id: batchId })
			.done(function (response) {
				if (!response.success) {
					renderNotice($results, response.data && response.data.message ? response.data.message : thumbnailManager.i18n.error, "error");
					$button.prop("disabled", false);
					return;
				}

				renderNotice($results, response.data.message, "success");
				const $row = $button.closest("tr");
				$row.find("td").eq(4).text("Restored");
				$row.find("td").eq(5).text("Already restored");
			})
			.fail(function () {
				renderNotice($results, thumbnailManager.i18n.error, "error");
				$button.prop("disabled", false);
			});
	});

	$("#trpl-regenerate-form").on("submit", function (event) {
		event.preventDefault();
		if (!window.confirm(thumbnailManager.i18n.confirmRegenerate)) {
			return;
		}

		const sizes = collectValues('#trpl-regenerate-form input[name="regen_sizes[]"]:checked');
		const folders = collectValues('#trpl-regenerate-form input[name="regen_folders[]"]:checked');
		const $progress = $("#trpl-regenerate-progress");
		const $results = $("#trpl-regenerate-results");
		setProgress($progress, 0);
		$results.empty();

		runJob({
			startAction: "trpl_start_regenerate",
			processAction: "trpl_process_regenerate",
			startData: { sizes: sizes, folders: folders },
			onProgress: function (data) {
				setProgress($progress, data.progress);
			},
			onComplete: function (data) {
				hideProgress($progress);
				const message =
					"Processed " +
					(data.result.attachments || 0) +
					" attachment(s) and generated " +
					(data.result.generated || 0) +
					" missing size(s).";
				renderNotice($results, message, "success");
			},
			onError: function (error) {
				hideProgress($progress);
				renderNotice($results, error.message || thumbnailManager.i18n.error, "error");
			},
		});
	});

	const $backupYear = $("#backup_year");
	const $backupMonth = $("#backup_month");

	$('input[name="backup_type"]').on("change", function () {
		if ($(this).val() === "date") {
			$backupYear.prop("disabled", false);
			updateMonthOptions();
		} else {
			$backupYear.prop("disabled", true).val("");
			$backupMonth.prop("disabled", true).val("");
		}
	});

	$backupYear.on("change", function () {
		updateMonthOptions();
	});

	function updateMonthOptions() {
		const availableDates = thumbnailManager.availableDates || {};
		const selectedYear = $backupYear.val();
		$backupMonth.empty().append($("<option>", { value: "", text: "Select Month" }));

		if (selectedYear && availableDates[selectedYear]) {
			$.each(availableDates[selectedYear], function (_, month) {
				$backupMonth.append($("<option>", { value: month, text: month }));
			});
			$backupMonth.prop("disabled", false);
		} else {
			$backupMonth.prop("disabled", true);
		}
	}

	$("#backup-images-form").on("submit", function (event) {
		event.preventDefault();

		const backupType = $('input[name="backup_type"]:checked').val();
		const backupYear = $backupYear.val();
		const backupMonth = $backupMonth.val();
		const $progress = $("#backup-progress");
		const $results = $("#backup-result");

		if (backupType === "date" && (!backupYear || !backupMonth)) {
			renderNotice($results, thumbnailManager.i18n.selectYearMonth, "error");
			return;
		}

		setProgress($progress, 25);
		$results.empty();

		ajaxPost("backup_images", {
			backup_type: backupType,
			backup_year: backupYear,
			backup_month: backupMonth,
		})
			.done(function (response) {
				if (!response.success) {
					hideProgress($progress);
					renderNotice($results, response.data && response.data.message ? response.data.message : thumbnailManager.i18n.error, "error");
					return;
				}

				setProgress($progress, 100);
				let message = response.data.message;
				if (response.data.download_url) {
					message += ' <a class="button button-secondary" href="' + response.data.download_url + '">Download Backup</a>';
				}
				renderNotice($results, message, "success");
				hideProgress($progress);
			})
			.fail(function () {
				hideProgress($progress);
				renderNotice($results, thumbnailManager.i18n.error, "error");
			});
	});
});
