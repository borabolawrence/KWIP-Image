(function ($) {
    'use strict';

    if (typeof kwipScan === 'undefined') {
        return;
    }

    var i18n = kwipScan.i18n;
    var $form = $('#kwip-scan-form');
    var $url = $('#kwip-scan-url');
    var $button = $('#kwip-scan-btn');
    var $spinner = $form.find('.spinner');
    var $message = $('#kwip-scan-message');
    var $result = $('#kwip-scan-result');
    var busy = false;
    var icons = {
        webp: 'yes-alt',
        pending: 'info',
        blocked: 'warning',
        outside: 'info',
        external: 'admin-site',
        error: 'dismiss'
    };

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
        var units = ['KB', 'MB', 'GB'];
        var i = -1;
        if (n < 1024) {
            return n + ' B';
        }
        do {
            n = n / 1024;
            i++;
        } while (n >= 1024 && i < units.length - 1);
        return n.toLocaleString(undefined, { maximumFractionDigits: 1 }) + ' ' + units[i];
    }

    function announce(text) {
        if (text && window.wp && wp.a11y) {
            wp.a11y.speak(text);
        }
    }

    function verdict(s) {
        if (0 === s.found) {
            return { tone: 'info', icon: 'info', title: i18n.foundNone, detail: i18n.foundNoneDetail };
        }
        if (s.blocked > 0) {
            return { tone: 'warn', icon: 'warning', title: i18n.blocked, detail: i18n.blockedDetail };
        }
        if (s.pending > 0 && 0 === s.webp) {
            return { tone: 'warn', icon: 'warning', title: i18n.noneOptimized, detail: i18n.pendingDetail };
        }
        if (s.pending > 0) {
            return { tone: 'warn', icon: 'warning', title: i18n.some, detail: i18n.pendingDetail };
        }
        if (s.webp > 0) {
            return { tone: 'success', icon: 'yes-alt', title: i18n.allGood, detail: i18n.allGoodDetail };
        }
        return { tone: 'info', icon: 'info', title: i18n.nothingToDo, detail: i18n.nothingToDoDetail };
    }

    function render(data) {
        var s = data.summary;
        var v = verdict(s);
        var $wrap = $('<div class="kwip-scan__out"></div>');

        var $text = $('<div></div>').append($('<strong></strong>').text(v.title));
        if (v.detail) {
            $text.append($('<p></p>').text(v.detail));
        }
        $wrap.append(
            $('<div class="kwip-scan__verdict kwip-scan__verdict--' + v.tone + '"></div>')
                .append($('<span class="dashicons dashicons-' + v.icon + '" aria-hidden="true"></span>'), $text)
        );

        var saved = data.savings.original - data.savings.webp;
        if (data.savings.original > 0 && saved > 0) {
            var percent = (saved / data.savings.original) * 100;
            $wrap.append($('<p class="kwip-scan__saving"></p>').text(fmt(i18n.savings, bytes(saved), percent.toLocaleString(undefined, { maximumFractionDigits: 1 }))));
        }

        var chips = [
            [s.found, i18n.chipFound],
            [s.webp, i18n.chipWebp],
            [s.pending, i18n.chipPending],
            [s.blocked, i18n.chipBlocked],
            [s.outside, i18n.chipOutside],
            [s.external, i18n.chipExternal],
            [s.other, i18n.chipOther],
            [s.error, i18n.chipError],
            [s.untested, i18n.chipUntested]
        ];
        var $chips = $('<div class="kwip-scan__chips"></div>');
        chips.forEach(function (chip, index) {
            if (chip[0] > 0 || 0 === index) {
                $chips.append($('<span class="kwip-chip kwip-chip--plain"></span>').text(fmt(chip[1], num(chip[0]))));
            }
        });
        $wrap.append($chips);

        if (data.items.length) {
            var $list = $('<ul class="kwip-scan__list"></ul>');
            data.items.forEach(function (item, index) {
                var $row = $('<li class="kwip-scan__item kwip-scan__item--' + item.status + '"></li>');
                $row.css('animation-delay', Math.min(index * 40, 600) + 'ms');
                $row.append($('<span class="dashicons dashicons-' + (icons[item.status] || 'info') + '" aria-hidden="true"></span>'));
                $row.append(
                    $('<span class="kwip-scan__body"></span>').append(
                        $('<strong></strong>').text(item.name).attr('title', item.url),
                        $('<span class="screen-reader-text"></span>').text(' (' + item.label + ')'),
                        $('<span class="description kwip-block"></span>').text(item.note)
                    )
                );
                $list.append($row);
            });
            $wrap.append($list);
        }

        if (s.pending > 0) {
            $wrap.append(
                $('<p class="kwip-scan__cta"></p>').append(
                    $('<a class="button button-primary"></a>').attr({ href: kwipScan.bulkUrl, 'data-kwip-goto': 'bulk' }).text(i18n.optimize)
                )
            );
        }

        announce(v.title);
        return $wrap;
    }

    function fail(text) {
        $message.text(text || i18n.error);
        announce(text || i18n.error);
    }

    $form.on('submit', function (event) {
        event.preventDefault();
        if (busy) {
            return;
        }

        busy = true;
        $button.prop('disabled', true).text(i18n.scanning);
        $spinner.addClass('is-active');
        $message.text('');
        $result.attr('aria-busy', 'true');

        $.ajax({
            url: kwipScan.ajaxUrl,
            method: 'POST',
            timeout: 90000,
            data: {
                action: 'kwip_scan',
                nonce: kwipScan.nonce,
                url: $url.val()
            }
        })
            .done(function (response) {
                if (response && response.success) {
                    $result.empty().append(render(response.data));
                    return;
                }
                fail(response && response.data && response.data.message);
            })
            .fail(function (xhr) {
                var json = xhr && xhr.responseJSON;
                fail(json && json.data && json.data.message);
            })
            .always(function () {
                busy = false;
                $button.prop('disabled', false).text(i18n.scan);
                $spinner.removeClass('is-active');
                $result.removeAttr('aria-busy');
            });
    });
}(jQuery));