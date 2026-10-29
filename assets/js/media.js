(function ($) {
	'use strict';

	if (typeof gwipMedia === 'undefined') {
		return;
	}

	function announce(message) {
		if (message && window.wp && wp.a11y) {
			wp.a11y.speak(message);
		}
	}

	function fail($box, message) {
		message = message || gwipMedia.i18n.error;
		$box.removeClass('is-busy').removeAttr('aria-busy');
		$box.find('.kwip-action').prop('disabled', false);
		$box.find('.spinner').removeClass('is-active');
		$box.find('.kwip-media__message').text(message);
		announce(message);
	}

	function send($button, $box) {
		$box.addClass('is-busy').attr('aria-busy', 'true');
		$box.find('.kwip-action').prop('disabled', true);
		$box.find('.spinner').addClass('is-active');
		$box.find('.kwip-media__message').text('');
		announce(gwipMedia.i18n.working);

		$.post(gwipMedia.ajaxUrl, {
			action: 'gwip_media',
			op: $button.data('op'),
			id: $box.data('id'),
			nonce: gwipMedia.nonce
		})
			.done(function (response) {
				if (response && response.success) {
					$box.replaceWith(response.data.html);
					announce(response.data.message);
					return;
				}
				fail($box, response && response.data && response.data.message);
			})
			.fail(function (xhr) {
				var json = xhr && xhr.responseJSON;
				fail($box, json && json.data && json.data.message);
			});
	}

	$(document).on('click', '.kwip-media .kwip-action', function (event) {
		event.preventDefault();

		var $button = $(this);
		var $box = $button.closest('.kwip-media');

		if ($box.hasClass('is-busy')) {
			return;
		}

		if ('restore' === $button.data('op') && window.gwipConfirm) {
			window.gwipConfirm({
				title: gwipMedia.i18n.restoreTitle,
				message: gwipMedia.i18n.restoreMessage,
				confirmLabel: gwipMedia.i18n.restoreButton,
				tone: 'danger'
			}).then(function (confirmed) {
				if (confirmed) {
					send($button, $box);
				}
			});
			return;
		}

		send($button, $box);
	});
}(jQuery));