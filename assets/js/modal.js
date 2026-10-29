(function () {
	'use strict';

	var counter = 0;

	function labels() {
		var i18n = (window.gwipDialog && window.gwipDialog.i18n) || {};
		return {
			cancel: i18n.cancel || 'Cancel',
			confirm: i18n.confirm || 'Confirm'
		};
	}

	function el(tag, className, text) {
		var node = document.createElement(tag);
		if (className) {
			node.className = className;
		}
		if (text) {
			node.textContent = text;
		}
		return node;
	}

	/**
	 * Ask for confirmation in a modal dialog.
	 *
	 * options: title, message, confirmLabel, cancelLabel, tone ('danger' for destructive actions).
	 * Returns a Promise that resolves to true (confirmed) or false (cancelled, Escape, backdrop click).
	 */
	window.gwipConfirm = function (options) {
		options = options || {};
		var text = labels();

		return new Promise(function (resolve) {
			if (typeof HTMLDialogElement === 'undefined' || typeof HTMLDialogElement.prototype.showModal !== 'function') {
				resolve(window.confirm((options.title ? options.title + '\n\n' : '') + (options.message || '')));
				return;
			}

			counter += 1;
			var id = 'kwip-dialog-' + counter;
			var danger = 'danger' === options.tone;
			var confirmed = false;

			var dialog = el('dialog', 'kwip-dialog');
			dialog.setAttribute('role', 'alertdialog');
			dialog.setAttribute('aria-labelledby', id + '-title');
			dialog.setAttribute('aria-describedby', id + '-message');

			var body = el('div', 'kwip-dialog__body');
			var title = el('h2', 'kwip-dialog__title', options.title || '');
			var message = el('p', 'kwip-dialog__message', options.message || '');
			title.id = id + '-title';
			message.id = id + '-message';
			body.appendChild(title);
			body.appendChild(message);

			var actions = el('div', 'kwip-dialog__actions');
			var cancelButton = el('button', 'button', options.cancelLabel || text.cancel);
			var confirmButton = el('button', 'button ' + (danger ? 'button-danger' : 'button-primary'), options.confirmLabel || text.confirm);
			cancelButton.type = 'button';
			confirmButton.type = 'button';

			(danger ? cancelButton : confirmButton).setAttribute('autofocus', '');

			actions.appendChild(cancelButton);
			actions.appendChild(confirmButton);
			dialog.appendChild(body);
			dialog.appendChild(actions);

			cancelButton.addEventListener('click', function () {
				dialog.close();
			});
			confirmButton.addEventListener('click', function () {
				confirmed = true;
				dialog.close();
			});
			dialog.addEventListener('click', function (event) {
				if (event.target === dialog) {
					dialog.close();
				}
			});
			dialog.addEventListener('close', function () {
				dialog.remove();
				resolve(confirmed);
			});

			document.body.appendChild(dialog);
			dialog.showModal();
		});
	};
}());