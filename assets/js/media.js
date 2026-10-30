(function ($) {
	'use strict';

	if (typeof kwipMedia === 'undefined') {
		return;
	}

	function announce(message) {
		if (message && window.wp && wp.a11y) {
			wp.a11y.speak(message);
		}
	}

	function fail($box, message) {
		message = message || kwipMedia.i18n.error;
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
		announce(kwipMedia.i18n.working);

		$.post(kwipMedia.ajaxUrl, {
			action: 'kwip_media',
			op: $button.data('op'),
			id: $box.data('id'),
			nonce: kwipMedia.nonce
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

		if ('restore' === $button.data('op') && window.kwipConfirm) {
			window.kwipConfirm({
				title: kwipMedia.i18n.restoreTitle,
				message: kwipMedia.i18n.restoreMessage,
				confirmLabel: kwipMedia.i18n.restoreButton,
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