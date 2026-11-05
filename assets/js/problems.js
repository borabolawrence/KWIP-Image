(function ($) {
    'use strict';

    if (typeof kwipProblems === 'undefined') {
        return;
    }

    var cfg = kwipProblems;
    var i18n = cfg.i18n;
    var busy = false;

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

    function bytes(n) {
        var units = ['B', 'KB', 'MB', 'GB'];
        var i = 0;
        var v = Math.max(0, n);
        while (v >= 1024 && i < units.length - 1) {
            v = v / 1024;
            i++;
        }
        return (Math.round(v * 10) / 10).toLocaleString() + ' ' + units[i];
    }

    function announce(text) {
        if (text && window.wp && wp.a11y) {
            wp.a11y.speak(text);
        }
    }

    function request(id) {
        return new Promise(function (resolve, reject) {
            $.post(cfg.ajaxUrl, { action: 'kwip_problem_retry', nonce: cfg.nonce, id: id })
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

    function updateHeader(remaining) {
        var $title = $('#kwip-problems-title');
        if (remaining > 0) {
            $title.text(fmt(remaining === 1 ? i18n.titleOne : i18n.titleMany, num(remaining)));
            return;
        }
        $title.text(i18n.titleNone);
        $('#kwip-problems-text').text(i18n.noneText);
        $('#kwip-problems-retry-all').remove();
        $('.kwip-problems .kwip-health__warn').removeClass('dashicons-warning').addClass('dashicons-yes-alt').css('color', '#00a32a');
    }

    function showReasons($row, reasons) {
        var $box = $row.find('.kwip-problem__reasons').empty();
        (reasons || []).forEach(function (reason) {
            $box.append(
                $('<p class="kwip-problem__reason"></p>').append(
                    $('<strong></strong>').text(reason[0]),
                    $('<span class="description kwip-block"></span>').text(reason[1])
                )
            );
        });
    }

    function leave($row) {
        window.setTimeout(function () {
            $row.addClass('is-leaving');
            window.setTimeout(function () { $row.remove(); }, 500);
        }, 1400);
    }

    async function retryRow($row) {
        var id = Number($row.attr('data-id'));
        var $btn = $row.find('.kwip-problem-retry');
        var $result = $row.find('.kwip-problem__result');

        $btn.prop('disabled', true).text(i18n.retrying);
        $result.removeClass('is-error is-visible').text('');

        try {
            var data = await request(id);
            if (data.ok) {
                var text = data.webp > 0 && data.webp < data.original ? fmt(i18n.fixed, bytes(data.original), bytes(data.webp)) : i18n.fixedSkip;
                $result.text(text).addClass('is-visible');
                $row.find('.kwip-img__action').remove();
                announce(text);
                leave($row);
            } else {
                showReasons($row, data.reasons);
                $btn.prop('disabled', false).text(i18n.retry);
            }
            updateHeader(data.remaining);
        } catch (error) {
            $result.text(error.message).addClass('is-visible is-error');
            $btn.prop('disabled', false).text(i18n.retry);
        }
    }

    $(document).on('click', '.kwip-problem-retry', function () {
        if (!busy) {
            retryRow($(this).closest('.kwip-problem'));
        }
    });

    $(document).on('click', '#kwip-problems-retry-all', async function () {
        if (busy) {
            return;
        }
        busy = true;
        var $all = $(this).prop('disabled', true);
        var $progress = $('#kwip-problems-progress');
        var rows = $('.kwip-problem').not('.is-leaving').toArray();

        for (var i = 0; i < rows.length; i++) {
            $progress.text(fmt(i18n.progress, num(i + 1), num(rows.length)));
            await retryRow($(rows[i]));
        }
        $progress.text(i18n.allDone);
        announce(i18n.allDone);
        $all.prop('disabled', false);
        busy = false;
    });
}(jQuery));