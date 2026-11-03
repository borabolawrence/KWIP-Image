(function ($) {
    'use strict';

    if (typeof kwipScan === 'undefined') {
        return;
    }

    var cfg = kwipScan;
    var i18n = cfg.i18n;
    var CIRC = 326.73;
    var reduceMotion = !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);

    var $form = $('#kwip-scan-form');
    var $addInput = $('#kwip-scan-url');
    var $routes = $('#kwip-routes .kwip-routes__list');
    var $button = $('#kwip-scan-btn');
    var $stop = $('#kwip-scan-stop');
    var $spinner = $form.find('.spinner');
    var $message = $('#kwip-scan-message');
    var $result = $('#kwip-scan-result');

    var state = { pages: [], failures: [], busy: false, stop: false, fixed: {}, openIndex: null, usageFilter: 'attention' };

    var icons = { webp: 'yes-alt', pending: 'info', blocked: 'warning', outside: 'info', external: 'admin-site', error: 'dismiss', other: 'yes', untested: 'minus' };
    var recIcons = { high: 'warning', medium: 'flag', low: 'info', info: 'info' };
    var BAD = ['pending', 'blocked', 'error', 'untested'];
    var CANNOT = ['outside', 'external', 'other'];


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

    function bytesParts(n) {
        var units = ['B', 'KB', 'MB', 'GB'];
        var i = 0;
        var v = Math.max(0, n);
        while (v >= 1024 && i < units.length - 1) {
            v = v / 1024;
            i++;
        }
        var decimals = i === 0 ? 0 : 1;
        return [Math.round(v * Math.pow(10, decimals)) / Math.pow(10, decimals), decimals, units[i]];
    }

    function bytes(n) {
        var p = bytesParts(n);
        return p[0].toLocaleString(undefined, { minimumFractionDigits: p[1], maximumFractionDigits: p[1] }) + ' ' + p[2];
    }

    function announce(text) {
        if (text && window.wp && wp.a11y) {
            wp.a11y.speak(text);
        }
    }

    function h(tag, props, kids) {
        var $n = $(document.createElement(tag));
        if (props) {
            Object.keys(props).forEach(function (key) {
                if (key === 'text') {
                    $n.text(props[key]);
                } else if (key === 'class') {
                    $n.addClass(props[key]);
                } else if (key === 'css') {
                    $n.css(props[key]);
                } else {
                    $n.attr(key, props[key]);
                }
            });
        }
        (kids || []).forEach(function (kid) {
            if (kid === null || kid === undefined || kid === false) {
                return;
            }
            $n.append(typeof kid === 'string' ? document.createTextNode(kid) : kid);
        });
        return $n;
    }

    function icon(name) {
        return h('span', { 'class': 'dashicons dashicons-' + name, 'aria-hidden': 'true' });
    }

    function countNode(value, decimals, suffix, finalText) {
        return h('span', {
            'data-countup': String(value),
            'data-decimals': String(decimals || 0),
            'data-suffix': suffix || '',
            text: finalText
        });
    }

    function toneFor(score) {
        return score >= 75 ? 'ok' : (score >= 50 ? 'warn' : 'bad');
    }

    function statusTone(status) {
        if (status === 'webp') {
            return 'ok';
        }
        return BAD.indexOf(status) !== -1 && status !== 'untested' ? 'warn' : 'muted';
    }

    function tag(tone, text) {
        return h('span', { 'class': 'kwip-tag kwip-tag--' + tone, text: text });
    }

    function placeLabel(place) {
        var map = { header: i18n.placeHeader, nav: i18n.placeNav, main: i18n.placeMain, footer: i18n.placeFooter, aside: i18n.placeAside };
        return map[place] || i18n.placeOther;
    }

    function thumbNode(item) {
        return item.thumb
            ? h('img', { 'class': 'kwip-img__thumb', src: item.url, alt: '', loading: 'lazy', width: '48', height: '48' })
            : h('span', { 'class': 'kwip-img__thumb kwip-img__thumb--icon' }, [icon(icons[item.status] || 'format-image')]);
    }

    function uniq(list) {
        return list.filter(function (v, i) { return list.indexOf(v) === i; });
    }


    function fixRequest(id) {
        return new Promise(function (resolve, reject) {
            $.post(cfg.ajaxUrl, { action: 'kwip_scan_fix', nonce: cfg.nonce, id: id })
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

    function fixedText(data) {
        return data.webp > 0 && data.webp < data.original
            ? fmt(i18n.optimizedDone, bytes(data.original), bytes(data.webp))
            : i18n.optimizedNothing;
    }

    function markFixed(id, data) {
        state.fixed[id] = data;
        var text = fixedText(data);
        $result.find('.kwip-img__result[data-attachment="' + id + '"]').text(text).removeClass('is-error').addClass('is-visible');
        $result.find('.kwip-img__action[data-attachment="' + id + '"]').remove();
        announce(text);
    }

    function markFailed(id, message) {
        $result.find('.kwip-img__result[data-attachment="' + id + '"]').text(message).addClass('is-visible is-error');
        $result.find('.kwip-fixone[data-attachment="' + id + '"]').prop('disabled', false).text(i18n.optimizeNow);
    }

    function fixOne(id, $btn) {
        $btn.prop('disabled', true).text(i18n.optimizing);
        return fixRequest(id).then(function (data) {
            markFixed(id, data);
        }).catch(function (error) {
            markFailed(id, error.message);
        });
    }

    async function fixMany(ids, $progress, $trigger) {
        $trigger.prop('disabled', true);
        for (var i = 0; i < ids.length; i++) {
            $progress.text(fmt(i18n.optimizeProgress, num(i + 1), num(ids.length)));
            $result.find('.kwip-fixone[data-attachment="' + ids[i] + '"]').prop('disabled', true).text(i18n.optimizing);
            try {
                markFixed(ids[i], await fixRequest(ids[i]));
            } catch (error) {
                markFailed(ids[i], error.message);
            }
        }
        $progress.text(i18n.optimizeAllDone);
        announce(i18n.optimizeAllDone);
        $trigger.remove();
    }

    function actionNode(id) {
        var $btn = h('button', { type: 'button', 'class': 'button kwip-fixone', 'data-attachment': String(id), text: i18n.optimizeNow });
        $btn.on('click', function () {
            fixOne(id, $btn);
        });
        return h('div', { 'class': 'kwip-img__action', 'data-attachment': String(id) }, [$btn]);
    }

    function resultNode(id) {
        var $r = h('span', { 'class': 'kwip-img__result', role: 'status', 'data-attachment': String(id || 0) });
        if (id && state.fixed[id]) {
            $r.text(fixedText(state.fixed[id])).addClass('is-visible');
        }
        return $r;
    }

    function pendingIds(items) {
        return uniq(items.filter(function (item) {
            return item.status === 'pending' && item.attachment > 0 && !state.fixed[item.attachment];
        }).map(function (item) { return item.attachment; }));
    }

    function renderTop(data) {
        var score = data.score;
        var offset = (CIRC * (1 - score.overall / 100)).toFixed(2);

        var $ring = $('<div class="kwip-ring" role="img"></div>').attr('aria-label', i18n.score + ': ' + score.overall + ' / 100');
        $ring.html(
            '<svg viewBox="0 0 120 120" aria-hidden="true"><circle class="kwip-ring__track" cx="60" cy="60" r="52"></circle>' +
            '<circle class="kwip-ring__bar" cx="60" cy="60" r="52" style="--kwip-offset:' + offset + '"></circle></svg>'
        );
        $ring.append(h('div', { 'class': 'kwip-ring__label' }, [countNode(score.overall, 0, '', String(score.overall))]));

        var $score = h('div', { 'class': 'kwip-score kwip-tone--' + score.tone }, [
            $ring,
            h('div', null, [
                h('span', { 'class': 'kwip-score__label', text: i18n.score + ' · ' + score.grade }),
                h('h3', { 'class': 'kwip-score__headline', text: score.headline })
            ])
        ]);

        var w = data.weight;
        var nowP = bytesParts(w.delivered);
        var $weight = h('div', { 'class': 'kwip-weight' }, [h('span', { 'class': 'kwip-weight__label', text: i18n.weightNow })]);

        if (w.delivered > 0 || w.original > 0) {
            $weight.append(h('strong', { 'class': 'kwip-weight__value' }, [countNode(nowP[0], nowP[1], ' ' + nowP[2], bytes(w.delivered))]));

            if (w.original > w.delivered) {
                var pct = Math.max(2, Math.round((w.delivered / w.original) * 100));
                $weight.append(
                    h('div', { 'class': 'kwip-weight__row' }, [
                        h('span', { text: i18n.weightBefore }),
                        h('div', { 'class': 'kwip-weight__track' }, [h('div', { 'class': 'kwip-weight__fill kwip-weight__fill--before', 'data-width': '100' })]),
                        h('span', { 'class': 'kwip-weight__num', text: bytes(w.original) })
                    ]),
                    h('div', { 'class': 'kwip-weight__row' }, [
                        h('span', { text: i18n.weightNowShort }),
                        h('div', { 'class': 'kwip-weight__track' }, [h('div', { 'class': 'kwip-weight__fill kwip-weight__fill--after', 'data-width': String(pct) })]),
                        h('span', { 'class': 'kwip-weight__num', text: bytes(w.delivered) })
                    ]),
                    h('p', {
                        'class': 'kwip-weight__line',
                        text: fmt(i18n.weightSaved, bytes(w.original - w.delivered), (((w.original - w.delivered) / w.original) * 100).toLocaleString(undefined, { maximumFractionDigits: 1 }))
                    })
                );
            }
            if (w.potential > 0) {
                $weight.append(h('p', { 'class': 'kwip-weight__line kwip-weight__line--potential', text: fmt(i18n.weightPotential, bytes(w.potential)) }));
            }
        } else {
            $weight.append(h('p', { 'class': 'kwip-weight__line', text: i18n.weightUnknown }));
        }

        return h('div', { 'class': 'kwip-report__top' }, [$score, $weight]);
    }

    function renderParts(data) {
        var parts = data.score.parts;
        var defs = [
            ['optimization', i18n.partOptimization, i18n.partOptimizationHint],
            ['sizing', i18n.partSizing, i18n.partSizingHint],
            ['loading', i18n.partLoading, i18n.partLoadingHint],
            ['access', i18n.partAccess, i18n.partAccessHint]
        ];
        var $wrap = h('div', { 'class': 'kwip-parts' });

        defs.forEach(function (def, index) {
            var value = parts[def[0]];
            $wrap.append(
                h('div', { 'class': 'kwip-part kwip-tone--' + toneFor(value), css: { 'animation-delay': (index * 70) + 'ms' } }, [
                    h('span', { 'class': 'kwip-part__title', text: def[1] }),
                    h('strong', { 'class': 'kwip-part__score' }, [countNode(value, 0, '', String(value))]),
                    h('div', { 'class': 'kwip-part__track' }, [h('div', { 'class': 'kwip-part__fill', 'data-width': String(value) })]),
                    h('span', { 'class': 'kwip-part__hint', text: def[2] })
                ])
            );
        });
        return $wrap;
    }

    function sparkline(trend) {
        var w = 120;
        var hgt = 32;
        var pad = 4;
        var step = (w - pad * 2) / (trend.length - 1);
        var points = trend.map(function (v, i) {
            return (pad + i * step).toFixed(1) + ',' + (hgt - pad - (v / 100) * (hgt - pad * 2)).toFixed(1);
        });
        var last = points[points.length - 1].split(',');
        return $('<svg class="kwip-spark" viewBox="0 0 120 32" width="120" height="32" aria-hidden="true"></svg>').html(
            '<polyline points="' + points.join(' ') + '" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"></polyline>' +
            '<circle cx="' + last[0] + '" cy="' + last[1] + '" r="3" fill="currentColor"></circle>'
        );
    }

    function renderCompare(data) {
        var prev = data.previous;
        var hasTrend = data.trend && data.trend.length > 1;
        if (!prev && !hasTrend) {
            return null;
        }

        var $box = h('div', { 'class': 'kwip-compare' });
        if (prev) {
            var diff = data.score.overall - prev.score;
            var tone = diff > 0 ? 'up' : (diff < 0 ? 'down' : 'same');
            var sign = diff > 0 ? '+' : '';
            $box.append(
                h('div', { 'class': 'kwip-compare__text' }, [
                    h('strong', { text: fmt(i18n.since, prev.ago) }),
                    h('span', { 'class': 'kwip-compare__delta kwip-compare__delta--' + tone, text: sign + diff }),
                    h('span', { text: fmt(i18n.scoreChange, prev.score, data.score.overall) }),
                    h('span', { text: fmt(i18n.weightChange, bytes(prev.delivered), bytes(data.weight.delivered)) })
                ])
            );
        }
        if (hasTrend) {
            $box.append(sparkline(data.trend));
        }
        return $box;
    }

    function renderRecs(ctx) {
        var data = ctx.data;
        var $wrap = h('div', { 'class': 'kwip-recs' }, [h('h3', { text: i18n.fixTitle })]);
        if (!data.recommendations.length) {
            $wrap.append(h('p', { 'class': 'kwip-recs__clear' }, [icon('yes-alt'), i18n.allClear]));
            return $wrap;
        }

        var $progress = h('p', { 'class': 'kwip-recs__progress', role: 'status' });
        var $list = h('ul', { 'class': 'kwip-recs__list' });
        data.recommendations.forEach(function (rec, index) {
            var $item = h('li', { 'class': 'kwip-rec kwip-rec--' + rec.severity, css: { 'animation-delay': (index * 60) + 'ms' } }, [
                icon(recIcons[rec.severity] || 'info'),
                h('div', { 'class': 'kwip-rec__body' }, [
                    h('strong', { text: rec.title }),
                    h('span', { 'class': 'description kwip-block', text: rec.detail })
                ])
            ]);

            if (rec.action === 'fix_all') {
                var ids = pendingIds(data.items);
                if (ids.length > 0) {
                    var $all = h('button', { type: 'button', 'class': 'button button-primary', text: fmt(i18n.optimizeAll, num(ids.length)) });
                    $all.on('click', function () {
                        fixMany(pendingIds(data.items), $progress, $all);
                    });
                    $item.append($all);
                } else {
                    $item.append(h('a', { 'class': 'button', href: '#', 'data-kwip-goto': 'bulk', text: rec.action_label }));
                }
            } else if (rec.action) {
                $item.append(h('a', { 'class': 'button', href: '#', 'data-kwip-goto': rec.action, text: rec.action_label }));
            }
            $list.append($item);
        });
        $wrap.append($list, $progress);
        return $wrap;
    }

    function pageVisible(ctx, item) {
        switch (ctx.filter) {
            case 'attention':
                return item.attention;
            case 'optimized':
                return item.status === 'webp';
            case 'other':
                return CANNOT.indexOf(item.status) !== -1;
            default:
                return true;
        }
    }

    function pageSorted(ctx) {
        var list = ctx.data.items.slice();
        if (ctx.sort === 'size') {
            list.sort(function (a, b) { return b.delivered - a.delivered; });
        } else if (ctx.sort === 'order') {
            list.sort(function (a, b) { return a.order - b.order; });
        }
        return list;
    }

    function renderRow(item, index) {
        var $tags = h('div', { 'class': 'kwip-img__tags' }, [tag(statusTone(item.status), item.label)]);
        if (item.main) {
            $tags.append(tag('info', i18n.mainImage));
        }
        if (item.kind === 'background') {
            $tags.append(tag('muted', i18n.background));
        }
        item.issues.forEach(function (issue) {
            $tags.append(tag(issue.tone === 'warn' ? 'warn' : 'info', issue.label));
        });

        var meta = [];
        meta.push(placeLabel(item.place) + (item.hint ? ' (' + item.hint + ')' : ''));
        if (item.nw > 0) {
            meta.push(item.shown && item.shown !== item.nw ? fmt(i18n.shownAt, num(item.nw), num(item.shown)) : fmt(i18n.naturalSize, num(item.nw), num(item.nh)));
        }
        if (item.count > 1) {
            meta.push(fmt(i18n.usedTimes, num(item.count)));
        }

        var sizeText = '';
        if (item.delivered > 0) {
            sizeText = item.original > item.delivered ? bytes(item.original) + ' → ' + bytes(item.delivered) : bytes(item.delivered);
        }

        var $row = h('li', { 'class': 'kwip-img kwip-img--' + item.status, css: { 'animation-delay': Math.min(index * 35, 500) + 'ms' } }, [
            thumbNode(item),
            h('div', { 'class': 'kwip-img__main' }, [
                h('strong', { 'class': 'kwip-img__name', text: item.name, title: item.url }),
                $tags,
                h('span', { 'class': 'description kwip-block kwip-img__note', text: item.note }),
                resultNode(item.attachment)
            ]),
            h('div', { 'class': 'kwip-img__meta' }, [
                h('strong', { text: sizeText }),
                h('span', { 'class': 'description kwip-block', text: meta.join(' · ') })
            ])
        ]);

        if (item.status === 'pending' && item.attachment > 0 && !state.fixed[item.attachment]) {
            $row.append(actionNode(item.attachment));
        }
        return $row;
    }

    function renderList(ctx) {
        var data = ctx.data;
        var $wrap = h('div', { 'class': 'kwip-list' }, [h('h3', { text: i18n.listTitle })]);

        var filters = [
            ['all', i18n.filterAll, function () { return true; }],
            ['attention', i18n.filterAttention, function (i) { return i.attention; }],
            ['optimized', i18n.filterOptimized, function (i) { return i.status === 'webp'; }],
            ['other', i18n.filterOther, function (i) { return CANNOT.indexOf(i.status) !== -1; }]
        ];

        var $filters = h('div', { 'class': 'kwip-filters', role: 'group', 'aria-label': i18n.listTitle });
        filters.forEach(function (f) {
            var count = data.items.filter(f[2]).length;
            if (f[0] !== 'all' && count === 0) {
                return;
            }
            $filters.append(
                h('button', { type: 'button', 'class': 'kwip-filter' + (ctx.filter === f[0] ? ' is-active' : ''), 'data-filter': f[0], 'aria-pressed': ctx.filter === f[0] ? 'true' : 'false' }, [
                    f[1] + ' ',
                    h('span', { 'class': 'kwip-filter__count', text: String(count) })
                ])
            );
        });

        var $sort = h('select', { 'class': 'kwip-sort', 'aria-label': i18n.sortLabel }, [
            h('option', { value: 'impact', text: i18n.sortImpact }),
            h('option', { value: 'size', text: i18n.sortSize }),
            h('option', { value: 'order', text: i18n.sortOrder })
        ]);
        $sort.val(ctx.sort);

        var $list = h('ul', { 'class': 'kwip-img-list' });
        $wrap.append(h('div', { 'class': 'kwip-list__bar' }, [$filters, $sort]), $list);

        function draw() {
            $list.empty();
            var rows = pageSorted(ctx).filter(function (item) { return pageVisible(ctx, item); });
            if (!rows.length) {
                $list.append(h('li', { 'class': 'kwip-img kwip-img--empty', text: i18n.emptyFilter }));
                return;
            }
            rows.forEach(function (item, index) {
                $list.append(renderRow(item, index));
            });
        }

        $filters.on('click', '.kwip-filter', function () {
            ctx.filter = $(this).data('filter');
            $filters.find('.kwip-filter').each(function () {
                var on = $(this).data('filter') === ctx.filter;
                $(this).toggleClass('is-active', on).attr('aria-pressed', on ? 'true' : 'false');
            });
            draw();
        });
        $sort.on('change', function () {
            ctx.sort = $(this).val();
            draw();
        });

        draw();
        return $wrap;
    }

    function csvCell(value) {
        var text = String(value === null || value === undefined ? '' : value);
        if (/^[=+\-@]/.test(text)) {
            text = "'" + text;
        }
        return /[",\n]/.test(text) ? '"' + text.replace(/"/g, '""') + '"' : text;
    }

    function downloadCsv(rows, filename) {
        var csv = '\ufeff' + rows.map(function (row) { return row.map(csvCell).join(','); }).join('\r\n');
        var blob = new Blob([csv], { type: 'text/csv;charset=utf-8' });
        var link = document.createElement('a');
        link.href = URL.createObjectURL(blob);
        link.download = filename;
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
        setTimeout(function () { URL.revokeObjectURL(link.href); }, 1000);
    }

    function pageCsv(d) {
        var rows = [['Image', 'Status', 'Type', 'Location', 'Width', 'Height', 'Shown width', 'Current bytes', 'Original bytes', 'Issues', 'Note']];
        d.items.forEach(function (item) {
            rows.push([
                item.url, item.label, item.kind, placeLabel(item.place) + (item.hint ? ' ' + item.hint : ''), item.nw || '', item.nh || '', item.shown || '', item.delivered, item.original,
                item.issues.map(function (i) { return i.label; }).join('; '), item.note
            ]);
        });
        downloadCsv(rows, 'kwip-image-report.csv');
    }

    function summaryText(d) {
        var lines = [
            i18n.scannedPage + ': ' + d.page,
            i18n.score + ': ' + d.score.overall + '/100 (' + d.score.grade + ')',
            i18n.weightNow + ': ' + bytes(d.weight.delivered) + ' (' + i18n.weightBefore + ': ' + bytes(d.weight.original) + ')'
        ];
        d.recommendations.slice(0, 5).forEach(function (rec) {
            lines.push('- ' + rec.title);
        });
        return lines.join('\n');
    }

    function copyText(text, $btn) {
        var done = function () {
            var original = $btn.text();
            $btn.text(i18n.copied);
            announce(i18n.copied);
            setTimeout(function () { $btn.text(original); }, 1600);
        };
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(done);
            return;
        }
        var area = document.createElement('textarea');
        area.value = text;
        document.body.appendChild(area);
        area.select();
        try {
            document.execCommand('copy');
            done();
        } catch (e) {
            // Copy is a convenience only.
        }
        document.body.removeChild(area);
    }

    function renderFoot(data, index) {
        var $csv = h('button', { type: 'button', 'class': 'button', text: i18n.exportCsv }).on('click', function () { pageCsv(data); });
        var $copy = h('button', { type: 'button', 'class': 'button', text: i18n.copySummary });
        $copy.on('click', function () { copyText(summaryText(data), $copy); });
        var $again = h('button', { type: 'button', 'class': 'button', text: i18n.rescan }).on('click', function () { rescanOne(index); });

        var $how = h('details', { 'class': 'kwip-how' }, [
            h('summary', { text: i18n.howTitle }),
            h('ul', null, ['how1', 'how2', 'how3', 'how4', 'how5'].map(function (key) { return h('li', { text: i18n[key] }); }))
        ]);

        return h('div', { 'class': 'kwip-report__foot' }, [h('div', { 'class': 'kwip-actions' }, [$again, $csv, $copy]), $how]);
    }

    function renderPageReport(data, index) {
        var ctx = { data: data, filter: 'all', sort: 'impact' };
        return h('div', { 'class': 'kwip-report' }, [
            renderTop(data),
            renderParts(data),
            renderCompare(data),
            renderRecs(ctx),
            renderList(ctx),
            renderFoot(data, index)
        ]);
    }

    function scored() {
        return state.pages.filter(function (p) { return p.score; });
    }

    function renderSummary(entries) {
        var pages = scored();
        var avg = pages.length ? Math.round(pages.reduce(function (sum, p) { return sum + p.score.overall; }, 0) / pages.length) : 0;
        var weight = pages.reduce(function (sum, p) { return sum + p.weight.delivered; }, 0);
        var attention = entries.filter(function (e) { return e.bad > 0; }).length;
        var wp = bytesParts(weight);

        return h('div', { 'class': 'kwip-tiles kwip-sitetiles' }, [
            h('div', { 'class': 'kwip-tile' }, [
                h('span', { 'class': 'kwip-tile__label', text: i18n.sitePages }),
                h('span', { 'class': 'kwip-tile__value' }, [countNode(state.pages.length, 0, '', String(state.pages.length))])
            ]),
            h('div', { 'class': 'kwip-tile' }, [
                h('span', { 'class': 'kwip-tile__label', text: i18n.siteScore }),
                h('span', { 'class': 'kwip-tile__value kwip-text--' + toneFor(avg) }, [countNode(avg, 0, '', String(avg))]),
                h('span', { 'class': 'kwip-tile__sub', text: pages.length ? '/ 100' : '' })
            ]),
            h('div', { 'class': 'kwip-tile' }, [
                h('span', { 'class': 'kwip-tile__label', text: i18n.siteUnopt }),
                h('span', { 'class': 'kwip-tile__value' }, [countNode(attention, 0, '', String(attention))])
            ]),
            h('div', { 'class': 'kwip-tile' }, [
                h('span', { 'class': 'kwip-tile__label', text: i18n.siteWeight }),
                h('span', { 'class': 'kwip-tile__value' }, [countNode(wp[0], wp[1], ' ' + wp[2], bytes(weight))])
            ])
        ]);
    }

    function renderPages() {
        var $wrap = h('div', { 'class': 'kwip-pages' }, [h('h3', { text: i18n.pagesTitle })]);
        $wrap.append(h('div', { 'class': 'kwip-pagerow kwip-pagerow--head', 'aria-hidden': 'true' }, [
            h('span', { text: i18n.scannedPage }),
            h('span', { text: i18n.colScore }),
            h('span', { text: i18n.colWeight }),
            h('span', { text: i18n.colProblems }),
            h('span')
        ]));

        state.pages.forEach(function (page, index) {
            var has = !!page.score;
            var attention = page.items.filter(function (i) { return i.attention; }).length;
            var $btn = h('button', { type: 'button', 'class': 'button kwip-pageview', 'data-index': String(index), text: i18n.viewDetails });
            if (!has) {
                $btn.prop('disabled', true);
            }
            $btn.on('click', function () {
                toggleDetail(index);
            });

            $wrap.append(
                h('div', { 'class': 'kwip-pagerow', 'data-index': String(index), css: { 'animation-delay': (index * 50) + 'ms' } }, [
                    h('div', { 'class': 'kwip-pagerow__title' }, [
                        h('strong', { text: page.title || page.route }),
                        h('small', { text: page.route })
                    ]),
                    has ? h('span', { 'class': 'kwip-pill kwip-tone--' + page.score.tone, text: String(page.score.overall) }) : h('span', { 'class': 'description', text: i18n.noImagesShort }),
                    h('span', { text: has ? bytes(page.weight.delivered) : '—' }),
                    h('span', { text: has ? String(attention) : '—' }),
                    $btn
                ])
            );
        });

        if (state.failures.length) {
            var $fails = h('ul', { 'class': 'kwip-pagefails' });
            state.failures.forEach(function (f) {
                $fails.append(h('li', { text: fmt(i18n.pageFailed, f.url, f.message) }));
            });
            $wrap.append($fails);
        }
        return $wrap;
    }

    function buildUsage() {
        var map = {};
        var list = [];

        state.pages.forEach(function (page) {
            (page.items || []).forEach(function (item) {
                var entry = map[item.url];
                if (!entry) {
                    entry = map[item.url] = {
                        url: item.url, name: item.name, thumb: item.thumb, nw: item.nw, nh: item.nh,
                        delivered: item.delivered, original: item.original, attachment: 0, kind: item.kind,
                        routes: [], issues: {}
                    };
                    list.push(entry);
                }
                if (!entry.attachment && item.attachment) {
                    entry.attachment = item.attachment;
                }
                item.issues.forEach(function (issue) {
                    entry.issues[issue.label] = issue.tone;
                });
                entry.routes.push({
                    page: page.page, route: page.route, title: page.title, status: item.status, label: item.label,
                    note: item.note, place: item.place, hint: item.hint
                });
            });
        });

        list.forEach(function (entry) {
            entry.bad = entry.routes.filter(function (r) { return BAD.indexOf(r.status) !== -1; }).length;
            entry.pending = entry.routes.filter(function (r) { return r.status === 'pending'; }).length;
            entry.allWebp = entry.routes.every(function (r) { return r.status === 'webp'; });
            entry.cannot = entry.routes.every(function (r) { return CANNOT.indexOf(r.status) !== -1; });
            entry.impact = Math.max(entry.delivered, entry.original) * entry.routes.length;
        });
        list.sort(function (a, b) { return b.impact - a.impact; });
        return list;
    }

    function usageMatches(entry) {
        switch (state.usageFilter) {
            case 'attention':
                return entry.bad > 0;
            case 'optimized':
                return entry.allWebp;
            case 'other':
                return entry.cannot;
            default:
                return true;
        }
    }

    function routeLine(route, extra) {
        var where = placeLabel(route.place) + (route.hint ? ' (' + route.hint + ')' : '');
        var $body = h('div', { 'class': 'kwip-routeline__body' }, [
            h('span', null, [i18n.renderedOn + ' ', h('strong', { text: route.route }), ' · ' + where + ' ', tag(statusTone(route.status), route.label)])
        ]);
        if (['webp', 'other'].indexOf(route.status) === -1 && route.note) {
            $body.append(h('span', { 'class': 'description kwip-block', text: route.note }));
        }
        return h('li', { 'class': 'kwip-routeline kwip-routeline--' + route.status + (extra ? ' is-extra' : '') }, [icon(icons[route.status] || 'info'), $body]);
    }

    function renderUsageRow(entry, number, index) {
        var total = entry.routes.length;
        var $tags = h('div', { 'class': 'kwip-img__tags' });

        if (entry.bad > 0) {
            $tags.append(tag('warn', fmt(i18n.problemOn, num(entry.bad), num(total))));
        } else if (entry.allWebp) {
            $tags.append(tag('ok', i18n.usageOptimized));
        } else {
            $tags.append(tag('muted', entry.routes[0].label));
        }
        Object.keys(entry.issues).forEach(function (label) {
            $tags.append(tag(entry.issues[label] === 'warn' ? 'warn' : 'info', label));
        });

        var subtitle = [total === 1 ? i18n.onOnePage : fmt(i18n.onManyPages, num(total))];
        if (entry.nw > 0) {
            subtitle.push(fmt(i18n.naturalSize, num(entry.nw), num(entry.nh)));
        }
        var sizeText = entry.delivered > 0 ? (entry.original > entry.delivered ? bytes(entry.original) + ' → ' + bytes(entry.delivered) : bytes(entry.delivered)) : '';

        var $head = h('div', { 'class': 'kwip-use__head' }, [
            thumbNode({ thumb: entry.thumb, url: entry.url, status: entry.routes[0].status }),
            h('div', { 'class': 'kwip-img__main' }, [
                h('strong', { 'class': 'kwip-img__name', text: fmt(i18n.imageNumber, num(number)) + ' · ' + entry.name, title: entry.url }),
                $tags,
                h('span', { 'class': 'description kwip-block', text: subtitle.join(' · ') }),
                resultNode(entry.attachment)
            ]),
            h('div', { 'class': 'kwip-img__meta' }, [h('strong', { text: sizeText })])
        ]);
        if (entry.pending > 0 && entry.attachment > 0 && !state.fixed[entry.attachment]) {
            $head.append(actionNode(entry.attachment));
        }

        var $routes = h('ul', { 'class': 'kwip-use__routes' });
        entry.routes.forEach(function (route, i) {
            $routes.append(routeLine(route, i >= 3));
        });

        var $row = h('li', { 'class': 'kwip-use', css: { 'animation-delay': Math.min(index * 40, 500) + 'ms' } }, [$head, $routes]);

        if (total > 3) {
            var $more = h('button', { type: 'button', 'class': 'kwip-use__more', text: fmt(i18n.moreRoutes, num(total - 3)) });
            $more.on('click', function () {
                var open = $row.toggleClass('is-open').hasClass('is-open');
                $more.text(open ? i18n.fewerRoutes : fmt(i18n.moreRoutes, num(total - 3)));
            });
            $row.append($more);
        }
        return $row;
    }

    function usageCsv(entries) {
        var rows = [['Image', 'Image URL', 'Page', 'Page title', 'Location', 'Status', 'Current bytes', 'Original bytes', 'Note']];
        entries.forEach(function (entry, i) {
            entry.routes.forEach(function (r) {
                rows.push([
                    i + 1, entry.url, r.route, r.title, placeLabel(r.place) + (r.hint ? ' ' + r.hint : ''), r.label,
                    entry.delivered, entry.original, r.note
                ]);
            });
        });
        downloadCsv(rows, 'kwip-image-usage.csv');
    }

    function renderUsage(entries) {
        var $wrap = h('div', { 'class': 'kwip-usage' }, [
            h('h3', { text: i18n.usageTitle }),
            h('p', { 'class': 'description', text: i18n.usageHint })
        ]);

        var filters = [
            ['attention', i18n.usageAttention, function (e) { return e.bad > 0; }],
            ['optimized', i18n.usageOptimized, function (e) { return e.allWebp; }],
            ['other', i18n.usageOther, function (e) { return e.cannot; }],
            ['all', i18n.usageAll, function () { return true; }]
        ];
        if (!entries.some(filters[0][2])) {
            state.usageFilter = 'all';
        }

        var $filters = h('div', { 'class': 'kwip-filters', role: 'group', 'aria-label': i18n.usageTitle });
        filters.forEach(function (f) {
            var count = entries.filter(f[2]).length;
            if (f[0] !== 'all' && count === 0) {
                return;
            }
            $filters.append(
                h('button', { type: 'button', 'class': 'kwip-filter' + (state.usageFilter === f[0] ? ' is-active' : ''), 'data-filter': f[0], 'aria-pressed': state.usageFilter === f[0] ? 'true' : 'false' }, [
                    f[1] + ' ',
                    h('span', { 'class': 'kwip-filter__count', text: String(count) })
                ])
            );
        });

        var $progress = h('p', { 'class': 'kwip-recs__progress', role: 'status' });
        var $actions = h('div', { 'class': 'kwip-actions' });
        var ids = uniq(entries.filter(function (e) { return e.pending > 0 && e.attachment > 0 && !state.fixed[e.attachment]; }).map(function (e) { return e.attachment; }));
        if (ids.length) {
            var $all = h('button', { type: 'button', 'class': 'button button-primary', text: fmt(i18n.optimizeAll, num(ids.length)) });
            $all.on('click', function () {
                fixMany(ids.filter(function (id) { return !state.fixed[id]; }), $progress, $all);
            });
            $actions.append($all);
        }
        $actions.append(h('button', { type: 'button', 'class': 'button', text: i18n.exportUsage }).on('click', function () { usageCsv(entries); }));

        var $list = h('ul', { 'class': 'kwip-use-list' });
        $wrap.append(h('div', { 'class': 'kwip-list__bar' }, [$filters, $actions]), $progress, $list);

        function draw() {
            $list.empty();
            var rows = entries.filter(usageMatches);
            if (!rows.length) {
                $list.append(h('li', { 'class': 'kwip-use kwip-use--empty', text: i18n.emptyFilter }));
                return;
            }
            rows.forEach(function (entry, index) {
                $list.append(renderUsageRow(entry, index + 1, index));
            });
        }

        $filters.on('click', '.kwip-filter', function () {
            state.usageFilter = $(this).data('filter');
            $filters.find('.kwip-filter').each(function () {
                var on = $(this).data('filter') === state.usageFilter;
                $(this).toggleClass('is-active', on).attr('aria-pressed', on ? 'true' : 'false');
            });
            draw();
        });

        draw();
        return $wrap;
    }

    function syncViewButtons() {
        $result.find('.kwip-pageview').each(function () {
            var on = Number($(this).attr('data-index')) === state.openIndex;
            $(this).text(on ? i18n.hideDetails : i18n.viewDetails);
        });
        $result.find('.kwip-pagerow[data-index]').each(function () {
            $(this).toggleClass('is-open', Number($(this).attr('data-index')) === state.openIndex);
        });
    }

    function openDetail(index, scroll) {
        var page = state.pages[index];
        var $detail = $result.find('#kwip-detail');
        if (!page || !page.score || !$detail.length) {
            return;
        }
        state.openIndex = index;
        $detail.empty().append(
            h('h3', { 'class': 'kwip-detail__title', text: i18n.detailsHeading + ': ' + (page.title || page.route) }),
            renderPageReport(page, index)
        );
        animateIn($detail);
        syncViewButtons();
        if (scroll && $detail[0].scrollIntoView) {
            $detail[0].scrollIntoView({ behavior: reduceMotion ? 'auto' : 'smooth', block: 'start' });
        }
    }

    function toggleDetail(index) {
        if (state.openIndex === index) {
            state.openIndex = null;
            $result.find('#kwip-detail').empty();
            syncViewButtons();
            return;
        }
        openDetail(index, true);
    }

    function animateIn($scope) {
        window.requestAnimationFrame(function () {
            window.requestAnimationFrame(function () {
                $scope.find('[data-width]').each(function () {
                    $(this).css('width', $(this).attr('data-width') + '%');
                });
            });
        });
        if (window.kwipRunCountUps && $scope[0]) {
            window.kwipRunCountUps($scope[0]);
        }
    }

    function renderResults() {
        var $wrap = h('div', { 'class': 'kwip-report kwip-site' });

        if (!state.pages.length) {
            $result.empty();
            return;
        }
        if (!scored().length) {
            $wrap.append(
                h('div', { 'class': 'kwip-scan__verdict' }, [
                    icon('info'),
                    h('div', null, [h('strong', { text: i18n.noImages }), h('p', { text: i18n.noImagesDetail })])
                ]),
                renderPages()
            );
            $result.empty().append($wrap);
            return;
        }

        var entries = buildUsage();
        $wrap.append(renderSummary(entries), renderPages(), renderUsage(entries), h('div', { 'class': 'kwip-detail', id: 'kwip-detail' }));
        $result.empty().append($wrap);
        animateIn($wrap);

        if (state.openIndex === null && state.pages.length === 1) {
            state.openIndex = 0;
        }
        if (state.openIndex !== null) {
            openDetail(state.openIndex, false);
        }
        announce(i18n.siteScore + ' ' + ($wrap.find('.kwip-sitetiles .kwip-tile').eq(1).find('[data-countup]').attr('data-final') || ''));
    }

    function skeleton() {
        return h('div', { 'class': 'kwip-skeleton', 'aria-hidden': 'true' }, [
            h('div', { 'class': 'kwip-skeleton__block kwip-skeleton__block--wide' }),
            h('div', { 'class': 'kwip-skeleton__row' }, [
                h('div', { 'class': 'kwip-skeleton__block' }),
                h('div', { 'class': 'kwip-skeleton__block' }),
                h('div', { 'class': 'kwip-skeleton__block' }),
                h('div', { 'class': 'kwip-skeleton__block' })
            ])
        ]);
    }

    function progressNode() {
        return h('div', { 'class': 'kwip-scanning' }, [
            h('div', { 'class': 'kwip-progress is-running' }, [
                h('div', { 'class': 'kwip-bar', role: 'progressbar', 'aria-valuemin': '0', 'aria-valuemax': '100', 'aria-valuenow': '0' }, [h('div', { 'class': 'kwip-bar__fill', id: 'kwip-scan-fill' })]),
                h('span', { id: 'kwip-scan-percent', text: '0%' })
            ]),
            h('p', { id: 'kwip-scan-status', role: 'status', 'class': 'description', text: i18n.scanningNote }),
            skeleton()
        ]);
    }

    function updateProgress(done, total, route) {
        var pct = Math.round((done / total) * 100);
        $('#kwip-scan-fill').css('width', pct + '%');
        $('#kwip-scan-percent').text(pct + '%');
        $('#kwip-scan-status').text(fmt(i18n.progress, num(Math.min(done + 1, total)), num(total), route));
        $('#kwip-scan-fill').closest('.kwip-bar').attr('aria-valuenow', pct);
    }

    function fail(text) {
        $message.text(text || i18n.error);
        announce(text || i18n.error);
    }

    function scanOne(url) {
        return new Promise(function (resolve, reject) {
            $.ajax({
                url: cfg.ajaxUrl,
                method: 'POST',
                timeout: 90000,
                data: { action: 'kwip_scan', nonce: cfg.nonce, url: url }
            })
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

    function setBusy(on) {
        state.busy = on;
        $button.prop('disabled', on).text(on ? i18n.scanning : i18n.scanSelected);
        $stop.prop('hidden', !on).prop('disabled', false);
        $spinner.toggleClass('is-active', on);
        $result.attr('aria-busy', on ? 'true' : 'false');
    }

    async function scanPages(urls) {
        setBusy(true);
        state.stop = false;
        state.pages = [];
        state.failures = [];
        state.openIndex = null;
        state.fixed = {};
        state.usageFilter = 'attention';
        $message.text('');
        $result.empty().append(progressNode());

        for (var i = 0; i < urls.length; i++) {
            if (state.stop) {
                break;
            }
            updateProgress(i, urls.length, urls[i]);
            try {
                state.pages.push(await scanOne(urls[i]));
            } catch (error) {
                state.failures.push({ url: urls[i], message: error.message });
            }
        }

        var stopped = state.stop;
        setBusy(false);
        if (!state.pages.length) {
            $result.empty();
            fail(state.failures.length ? fmt(i18n.pageFailed, state.failures[0].url, state.failures[0].message) : i18n.error);
            return;
        }
        renderResults();
        if (stopped) {
            announce(fmt(i18n.stopped, num(state.pages.length)));
        }
    }

    async function rescanOne(index) {
        var page = state.pages[index];
        if (!page || state.busy) {
            return;
        }
        setBusy(true);
        state.fixed = {};
        $message.text('');
        try {
            state.pages[index] = await scanOne(page.page);
            state.openIndex = index;
        } catch (error) {
            fail(error.message);
        }
        setBusy(false);
        renderResults();
    }

    function addRoute() {
        var value = $.trim($addInput.val());
        if (!value) {
            return;
        }
        var exists = false;
        $routes.find('input').each(function () {
            if ($(this).val() === value) {
                exists = true;
                $(this).prop('checked', true);
            }
        });
        if (!exists) {
            $routes.append(
                h('label', { 'class': 'kwip-route kwip-route--custom' }, [
                    h('input', { type: 'checkbox', value: value, checked: 'checked' }),
                    h('span', { 'class': 'kwip-route__body' }, [h('strong', { text: value })])
                ])
            );
        }
        $addInput.val('');
    }

    $('#kwip-add-route').on('click', addRoute);
    $addInput.on('keydown', function (event) {
        if (event.key === 'Enter') {
            event.preventDefault();
            addRoute();
        }
    });

    $stop.on('click', function () {
        state.stop = true;
        $stop.prop('disabled', true);
    });

    $form.on('submit', function (event) {
        event.preventDefault();
        if (state.busy) {
            return;
        }
        var urls = $routes.find('input:checked').map(function () { return $(this).val(); }).get();
        if (!urls.length) {
            fail(i18n.noPages);
            return;
        }
        scanPages(urls);
    });
}(jQuery));