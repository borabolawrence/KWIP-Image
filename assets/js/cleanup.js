(function ($) {
    'use strict';

    if (typeof kwipCleanup === 'undefined') {
        return;
    }

    var cfg = kwipCleanup;
    var i18n = cfg.i18n;
    var $button = $('#kwip-cleanup-btn');
    var $wrap = $('#kwip-cleanup-progress');
    var $fill = $('#kwip-cleanup-fill');
    var $percent = $('#kwip-cleanup-percent');
    var $status = $('#kwip-cleanup-status');
    var busy = false;
    var total = cfg.total;

    function fmt(template) {
        var args = Array.prototype.slice.call(arguments, 1);
        var next = 0;
        return template.replace(/%(?:(\d+)\$)?s/g, function (match, pos) {
            return String(pos ? args[pos - 1] : args[next++]);
        });
    }

    function num(n) {
        return Number(n).toLocaleString();
    }

    function announce(text) {
        if (text && window.wp && wp.a11y) {
            wp.a11y.speak(text);
        }
    }

    function post(after) {
        return new Promise(function (resolve, reject) {
            $.post(cfg.ajaxUrl, { action: 'kwip_cleanup', nonce: cfg.nonce, after: after })
                .done(function (response) {
                    if (response && response.success) {
                        resolve(response.data);
                    } else {
                        reject(new Error((response && response.data && response.data.message) || i18n.error));
                    }
                })
                .fail(function (xhr) {
                    var json = xhr && xhr.responseJSON;
                    reject(new Error((json && json.data && json.data.message) || i18n.error));
                });
        });
    }

    function setProgress(done) {
        var pct = total > 0 ? Math.min(100, Math.round((done / total) * 100)) : 0;
        $fill.css('width', pct + '%');
        $percent.text(pct + '%');
        $fill.closest('.kwip-bar').attr('aria-valuenow', pct);
    }

    async function run() {
        busy = true;
        $button.prop('disabled', true).text(i18n.working);
        $status.removeClass('is-error is-done').text('');
        $wrap.addClass('is-running').prop('hidden', false);
        setProgress(0);

        var after = 0;
        var images = 0;
        var files = 0;

        try {
            while (true) {
                var data = await post(after);
                after = data.last_id;
                images += data.images;
                files += data.removed;
                setProgress(images);
                $status.text(fmt(i18n.progress, num(images)));
                if (data.done) {
                    break;
                }
            }
            setProgress(total);
            var text = fmt(i18n.done, num(images), num(files));
            $status.addClass('is-done').text(text);
            announce(text);
            $button.text(i18n.button);
        } catch (error) {
            $status.addClass('is-error').text(error.message);
            announce(error.message);
            $button.prop('disabled', false).text(i18n.button);
        }
        $wrap.removeClass('is-running');
        busy = false;
        document.dispatchEvent(new CustomEvent('kwip:data-changed'));
    }

    function refresh() {
        if (busy) {
            return;
        }
        $.post(cfg.ajaxUrl, { action: 'kwip_cleanup', nonce: cfg.nonce, op: 'status' })
            .done(function (response) {
                if (busy || !response || !response.success) {
                    return;
                }
                total = Number(response.data.total) || 0;
                $('#kwip-cleanup-count').text(response.data.text);
                $button.prop('disabled', total === 0);

                if (total > 0 && $status.hasClass('is-done')) {
                    $status.removeClass('is-done').text('');
                    $wrap.prop('hidden', true);
                }
            });
    }

    document.addEventListener('kwip:data-changed', refresh);

    $button.on('click', async function () {
        if (busy) {
            return;
        }
        var ok = await window.kwipConfirm({
            title: i18n.confirmTitle,
            message: i18n.confirm,
            confirmLabel: i18n.confirmButton,
            tone: 'danger'
        });
        if (ok) {
            run();
        }
    });
}(jQuery));