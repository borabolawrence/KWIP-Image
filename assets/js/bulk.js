(function ($) {
    'use strict';

    if (typeof kwipBulk === 'undefined') {
        return;
    }

    var cfg = kwipBulk;
    var els = {
        start: $('#kwip-bulk-start'),
        resume: $('#kwip-bulk-resume'),
        stop: $('#kwip-bulk-stop'),
        cancel: $('#kwip-bulk-cancel'),
        scope: $('#kwip-bulk-scope'),
        wrap: $('#kwip-bulk-progress-wrap'),
        bar: $('#kwip-bulk-progress'),
        fill: $('#kwip-bulk-fill'),
        percent: $('#kwip-bulk-percent'),
        status: $('#kwip-bulk-status'),
        message: $('#kwip-bulk-message'),
        link: $('#kwip-bulk-link')
    };

    var state = {
        running: false,
        stop: false,
        done: 0,
        remaining: cfg.counts.queued,
        stats: { converted: 0, skipped: 0, failed: 0 }
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

    function say(text) {
        if (text && window.wp && wp.a11y) {
            wp.a11y.speak(text);
        }
    }

    function sleep(ms) {
        return new Promise(function (resolve) {
            setTimeout(resolve, ms);
        });
    }

    function setStatus(text) {
        els.status.text(text || '');
    }

    function setMessage(text) {
        els.message.text(text || '');
    }


    function setBadge(n) {
        var badge = document.getElementById('kwip-tab-badge-bulk');
        if (badge) {
            badge.textContent = num(n);
            badge.hidden = n < 1;
        }
    }

    function setCounts(c) {
        if (!c) {
            return;
        }
        $('#kwip-count-total').text(num(c.total));
        $('#kwip-count-unprocessed').text(num(c.unprocessed));
        $('#kwip-count-queued').text(num(c.queued));
    }

    function post(op, data) {
        return new Promise(function (resolve, reject) {
            $.post(cfg.ajaxUrl, $.extend({ action: 'kwip_bulk', op: op, nonce: cfg.nonce }, data || {}))
                .done(function (response) {
                    if (response && response.success) {
                        resolve(response.data);
                    } else {
                        reject(new Error((response && response.data && response.data.message) || cfg.i18n.error));
                    }
                })
                .fail(function (xhr) {
                    var json = xhr && xhr.responseJSON;
                    reject(new Error((json && json.data && json.data.message) || cfg.i18n.error));
                });
        });
    }

    function updateProgress() {
        var total = state.done + state.remaining;
        var pct = total > 0 ? Math.min(100, Math.round((state.done / total) * 100)) : 0;
        els.fill.css('width', pct + '%');
        els.bar.attr('aria-valuenow', pct);
        els.percent.text(pct + '%');
    }

    function render() {
        els.wrap.toggleClass('is-running', state.running);
        var queued = state.remaining > 0;
        els.start.prop('hidden', state.running || queued);
        els.scope.prop('hidden', state.running || queued);
        els.resume.prop('hidden', state.running || !queued);
        els.cancel.prop('hidden', state.running || !queued);
        els.stop.prop('hidden', !state.running).prop('disabled', false);
        els.wrap.prop('hidden', !(state.running || state.done > 0));
    }

    async function prepare(scope) {
        var after = 0;
        while (!state.stop) {
            var d = await post('prepare', { scope: scope, after: after });
            after = d.last_id;
            state.remaining = d.pending;
            setStatus(fmt(cfg.i18n.preparing, num(d.pending)));
            render();
            if (d.done) {
                return true;
            }
        }
        return false;
    }

    function finished(d) {
        var s = state.stats;
        var text = fmt(cfg.i18n.finished, num(s.converted), num(s.skipped), num(s.failed));
        if (d.remaining > 0) {
            text += ' ' + fmt(cfg.i18n.retryLater, num(d.remaining));
        }
        setStatus(text);
        say(text);
        els.link.prop('hidden', s.converted === 0);
    }

    async function convert() {
        while (!state.stop) {
            var d = await post('process');

            state.stats.converted += d.stats.converted;
            state.stats.skipped += d.stats.skipped;
            state.stats.failed += d.stats.failed;
            state.done = state.stats.converted + state.stats.skipped + state.stats.failed;
            state.remaining = d.remaining;

            updateProgress();
            setStatus(fmt(cfg.i18n.converting, num(state.done), num(state.done + state.remaining)));
            if (d.counts) {
                setCounts(d.counts);
            }

            if (d.due === 0) {
                finished(d);
                return;
            }
            if (d.processed === 0) {
                await sleep(3000);
            }
        }

        var stoppedText = fmt(cfg.i18n.stopped, num(state.remaining));
        setStatus(stoppedText);
        say(stoppedText);
        try {
            setCounts(await post('status'));
        } catch (e) {
            // The numbers are informational only.
        }
    }

    async function run(scope) {
        if (state.running) {
            return;
        }
        state.running = true;
        state.stop = false;
        setMessage('');
        els.link.prop('hidden', true);

        if (scope) {
            state.done = 0;
            state.stats = { converted: 0, skipped: 0, failed: 0 };
        }
        render();

        try {
            if (scope) {
                var prepared = await prepare(scope);
                if (!prepared) {
                    var stoppedText = fmt(cfg.i18n.stopped, num(state.remaining));
                    setStatus(stoppedText);
                    say(stoppedText);
                    return;
                }
            }
            await convert();
        } catch (error) {
            setMessage(error && error.message ? error.message : cfg.i18n.error);
        } finally {
            state.running = false;
            render();
        }
    }

    els.start.on('click', async function () {
        var scope = $('input[name="kwip-bulk-scope"]:checked').val() === 'all' ? 'all' : 'new';
        if (scope === 'all') {
            var ok = await window.kwipConfirm({
                title: cfg.i18n.confirmAllTitle,
                message: cfg.i18n.confirmAll,
                confirmLabel: cfg.i18n.confirmAllButton
            });
            if (!ok) {
                return;
            }
        }
        run(scope);
    });

    els.resume.on('click', function () {
        run(null);
    });

    els.stop.on('click', function () {
        state.stop = true;
        els.stop.prop('disabled', true);
        setStatus(cfg.i18n.stopping);
    });

    els.cancel.on('click', async function () {
        var ok = await window.kwipConfirm({
            title: cfg.i18n.confirmCancelTitle,
            message: cfg.i18n.confirmCancel,
            confirmLabel: cfg.i18n.confirmCancelButton,
            tone: 'danger'
        });
        if (!ok) {
            return;
        }
    });


    if (state.remaining > 0) {
        setStatus(fmt(cfg.i18n.waiting, num(state.remaining)));
    }
    render();
}(jQuery));