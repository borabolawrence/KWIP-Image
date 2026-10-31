(function ($) {
    'use strict';

    if (typeof kwipScan === 'undefined') {
        return;
    }

    var cfg = kwipScan;
    var i18n = cfg.i18n;
    var CIRC = 326.73;
    var $form = $('#kwip-scan-form');
    var $url = $('#kwip-scan-url');
    var $button = $('#kwip-scan-btn');
    var $spinner = $form.find('.spinner');
    var $message = $('#kwip-scan-message');
    var $result = $('#kwip-scan-result');
    var state = { data: null, filter: 'all', sort: 'impact', busy: false };
    var icons = { webp: 'yes-alt', pending: 'info', blocked: 'warning', outside: 'info', external: 'admin-site', error: 'dismiss', other: 'yes', untested: 'minus' };
    var recIcons = { high: 'warning', medium: 'flag', low: 'info', info: 'info' };


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


    function renderTop(data) {
        var score = data.score;
        var offset = (CIRC * (1 - score.overall / 100)).toFixed(2);

        var $ring = $('<div class="kwip-ring" role="img"></div>').attr('aria-label', i18n.score + ': ' + score.overall + ' / 100');
        $ring.html(
            '<svg viewBox="0 0 120 120" aria-hidden="true"><circle class="kwip-ring__track" cx="60" cy="60" r="52"></circle>' +
            '<circle class="kwip-ring__bar" cx="60" cy="60" r="52" style="--kwip-offset:' + offset + '"></circle></svg>'
        );
        $ring.append(
            h('div', { 'class': 'kwip-ring__label' }, [
                countNode(score.overall, 0, '', String(score.overall))
            ])
        );

        var $score = h('div', { 'class': 'kwip-score kwip-tone--' + score.tone }, [
            $ring,
            h('div', null, [
                h('span', { 'class': 'kwip-score__label', text: i18n.score + ' · ' + score.grade }),
                h('h3', { 'class': 'kwip-score__headline', text: score.headline })
            ])
        ]);

        var w = data.weight;
        var nowP = bytesParts(w.delivered);
        var $weight = h('div', { 'class': 'kwip-weight' }, [
            h('span', { 'class': 'kwip-weight__label', text: i18n.weightNow })
        ]);

        if (w.delivered > 0 || w.original > 0) {
            $weight.append(
                h('strong', { 'class': 'kwip-weight__value' }, [
                    countNode(nowP[0], nowP[1], ' ' + nowP[2], bytes(w.delivered))
                ])
            );

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

    function fixable() {
        return state.data.items.filter(function (item) {
            return item.status === 'pending' && item.attachment > 0 && !item.fixed;
        });
    }

    function renderRecs(data) {
        var $wrap = h('div', { 'class': 'kwip-recs' }, [h('h3', { text: i18n.fixTitle })]);
        if (!data.recommendations.length) {
            $wrap.append(h('p', { 'class': 'kwip-recs__clear' }, [icon('yes-alt'), i18n.allClear]));
            return $wrap;
        }

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
                var count = fixable().length;
                if (count > 0) {
                    $item.append(
                        h('button', { type: 'button', 'class': 'button button-primary kwip-fixall', text: fmt(i18n.optimizeAll, num(count)) })
                    );
                } else {
                    $item.append(h('a', { 'class': 'button', href: '#', 'data-kwip-goto': 'bulk', text: rec.action_label }));
                }
            } else if (rec.action) {
                $item.append(h('a', { 'class': 'button', href: '#', 'data-kwip-goto': rec.action, text: rec.action_label }));
            }
            $list.append($item);
        });
        $wrap.append($list, h('p', { 'class': 'kwip-recs__progress', role: 'status' }));
        return $wrap;
    }

    function visible(item) {
        switch (state.filter) {
            case 'attention':
                return item.attention;
            case 'optimized':
                return item.status === 'webp';
            case 'other':
                return ['outside', 'external', 'other'].indexOf(item.status) !== -1;
            default:
                return true;
        }
    }

    function sortedItems() {
        var list = state.data.items.slice();
        if (state.sort === 'size') {
            list.sort(function (a, b) { return b.delivered - a.delivered; });
        } else if (state.sort === 'order') {
            list.sort(function (a, b) { return a.order - b.order; });
        }
        return list;
    }

    function statusTone(status) {
        if (status === 'webp') {
            return 'ok';
        }
        if (['pending', 'blocked', 'error'].indexOf(status) !== -1) {
            return 'warn';
        }
        return 'muted';
    }

    function renderRow(item, index) {
        var $thumb = item.thumb
            ? h('img', { 'class': 'kwip-img__thumb', src: item.url, alt: '', loading: 'lazy', width: '48', height: '48' })
            : h('span', { 'class': 'kwip-img__thumb kwip-img__thumb--icon' }, [icon(icons[item.status] || 'format-image')]);

        var $tags = h('div', { 'class': 'kwip-img__tags' }, [
            h('span', { 'class': 'kwip-tag kwip-tag--' + statusTone(item.status), text: item.label })
        ]);
        if (item.main) {
            $tags.append(h('span', { 'class': 'kwip-tag kwip-tag--info', text: i18n.mainImage }));
        }
        if (item.kind === 'background') {
            $tags.append(h('span', { 'class': 'kwip-tag kwip-tag--muted', text: i18n.background }));
        }
        item.issues.forEach(function (issue) {
            $tags.append(h('span', { 'class': 'kwip-tag kwip-tag--' + (issue.tone === 'warn' ? 'warn' : 'info'), text: issue.label }));
        });

        var meta = [];
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
            $thumb,
            h('div', { 'class': 'kwip-img__main' }, [
                h('strong', { 'class': 'kwip-img__name', text: item.name, title: item.url }),
                $tags,
                h('span', { 'class': 'description kwip-block kwip-img__note', text: item.note }),
                h('span', { 'class': 'kwip-img__result', role: 'status' })
            ]),
            h('div', { 'class': 'kwip-img__meta' }, [
                h('strong', { text: sizeText }),
                meta.length ? h('span', { 'class': 'description kwip-block', text: meta.join(' · ') }) : null
            ])
        ]);

        if (item.status === 'pending' && item.attachment > 0 && !item.fixed) {
            var $fix = h('button', { type: 'button', 'class': 'button kwip-fixone', text: i18n.optimizeNow });
            $fix.on('click', function () {
                fixOne(item, $row, $fix);
            });
            $row.append(h('div', { 'class': 'kwip-img__action' }, [$fix]));
            item.$row = $row;
            item.$fix = $fix;
        }
        return $row;
    }

    function renderList(data) {
        var $wrap = h('div', { 'class': 'kwip-list' }, [h('h3', { text: i18n.listTitle })]);

        var filters = [
            ['all', i18n.filterAll, function () { return true; }],
            ['attention', i18n.filterAttention, function (i) { return i.attention; }],
            ['optimized', i18n.filterOptimized, function (i) { return i.status === 'webp'; }],
            ['other', i18n.filterOther, function (i) { return ['outside', 'external', 'other'].indexOf(i.status) !== -1; }]
        ];

        var $filters = h('div', { 'class': 'kwip-filters', role: 'group', 'aria-label': i18n.listTitle });
        filters.forEach(function (f) {
            var count = data.items.filter(f[2]).length;
            if (f[0] !== 'all' && count === 0) {
                return;
            }
            var $chip = h('button', { type: 'button', 'class': 'kwip-filter' + (state.filter === f[0] ? ' is-active' : ''), 'data-filter': f[0], 'aria-pressed': state.filter === f[0] ? 'true' : 'false' }, [
                f[1] + ' ',
                h('span', { 'class': 'kwip-filter__count', text: String(count) })
            ]);
            $filters.append($chip);
        });

        var $sort = h('select', { 'class': 'kwip-sort', 'aria-label': i18n.sortLabel }, [
            h('option', { value: 'impact', text: i18n.sortImpact }),
            h('option', { value: 'size', text: i18n.sortSize }),
            h('option', { value: 'order', text: i18n.sortOrder })
        ]);
        $sort.val(state.sort);

        var $list = h('ul', { 'class': 'kwip-img-list' });
        $wrap.append(h('div', { 'class': 'kwip-list__bar' }, [$filters, $sort]), $list);

        function draw() {
            $list.empty();
            var rows = sortedItems().filter(visible);
            if (!rows.length) {
                $list.append(h('li', { 'class': 'kwip-img kwip-img--empty', text: i18n.emptyFilter }));
                return;
            }
            rows.forEach(function (item, index) {
                $list.append(renderRow(item, index));
            });
        }

        $filters.on('click', '.kwip-filter', function () {
            state.filter = $(this).data('filter');
            $filters.find('.kwip-filter').each(function () {
                var on = $(this).data('filter') === state.filter;
                $(this).toggleClass('is-active', on).attr('aria-pressed', on ? 'true' : 'false');
            });
            draw();
        });
        $sort.on('change', function () {
            state.sort = $(this).val();
            draw();
        });

        draw();
        return $wrap;
    }

    function fixRequest(item) {
        return new Promise(function (resolve, reject) {
            $.post(cfg.ajaxUrl, { action: 'kwip_scan_fix', nonce: cfg.nonce, id: item.attachment })
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

    function markFixed(item, data) {
        item.fixed = true;
        if (!item.$row) {
            return;
        }
        var text = data.webp > 0 && data.webp < data.original
            ? fmt(i18n.optimizedDone, bytes(data.original), bytes(data.webp))
            : i18n.optimizedNothing;
        item.$row.find('.kwip-img__result').text(text).addClass('is-visible');
        item.$row.find('.kwip-img__action').remove();
        announce(text);
    }

    function fixOne(item, $row, $fix) {
        $fix.prop('disabled', true).text(i18n.optimizing);
        return fixRequest(item).then(function (data) {
            markFixed(item, data);
        }).catch(function (error) {
            $fix.prop('disabled', false).text(i18n.optimizeNow);
            $row.find('.kwip-img__result').text(error.message).addClass('is-visible is-error');
        });
    }

    async function fixAll() {
        var list = fixable();
        var $progress = $result.find('.kwip-recs__progress');
        var $all = $result.find('.kwip-fixall').prop('disabled', true);

        for (var i = 0; i < list.length; i++) {
            $progress.text(fmt(i18n.optimizeProgress, num(i + 1), num(list.length)));
            if (list[i].$fix) {
                list[i].$fix.prop('disabled', true).text(i18n.optimizing);
            }
            try {
                markFixed(list[i], await fixRequest(list[i]));
            } catch (error) {
                if (list[i].$row) {
                    list[i].$row.find('.kwip-img__result').text(error.message).addClass('is-visible is-error');
                }
                if (list[i].$fix) {
                    list[i].$fix.prop('disabled', false).text(i18n.optimizeNow);
                }
            }
        }
        $progress.text(i18n.optimizeAllDone);
        announce(i18n.optimizeAllDone);
        $all.remove();
    }


    function csvCell(value) {
        var text = String(value === null || value === undefined ? '' : value);
        if (/^[=+\-@]/.test(text)) {
            text = "'" + text;
        }
        return /[",\n]/.test(text) ? '"' + text.replace(/"/g, '""') + '"' : text;
    }

    function downloadCsv() {
        var d = state.data;
        var rows = [['Image', 'Status', 'Type', 'Width', 'Height', 'Shown width', 'Current bytes', 'Original bytes', 'Issues', 'Note']];
        d.items.forEach(function (item) {
            rows.push([
                item.url, item.label, item.kind, item.nw || '', item.nh || '', item.shown || '', item.delivered, item.original,
                item.issues.map(function (i) { return i.label; }).join('; '), item.note
            ]);
        });
        var csv = '\ufeff' + rows.map(function (row) { return row.map(csvCell).join(','); }).join('\r\n');
        var blob = new Blob([csv], { type: 'text/csv;charset=utf-8' });
        var link = document.createElement('a');
        link.href = URL.createObjectURL(blob);
        link.download = 'kwip-image-report.csv';
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
        setTimeout(function () { URL.revokeObjectURL(link.href); }, 1000);
    }

    function summaryText() {
        var d = state.data;
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

    function renderFoot() {
        var $csv = h('button', { type: 'button', 'class': 'button', text: i18n.exportCsv }).on('click', downloadCsv);
        var $copy = h('button', { type: 'button', 'class': 'button', text: i18n.copySummary });
        $copy.on('click', function () { copyText(summaryText(), $copy); });
        var $again = h('button', { type: 'button', 'class': 'button', text: i18n.rescan }).on('click', function () {
            $form.trigger('submit');
        });

        var $how = h('details', { 'class': 'kwip-how' }, [
            h('summary', { text: i18n.howTitle }),
            h('ul', null, ['how1', 'how2', 'how3', 'how4', 'how5'].map(function (key) {
                return h('li', { text: i18n[key] });
            }))
        ]);

        return h('div', { 'class': 'kwip-report__foot' }, [
            h('div', { 'class': 'kwip-actions' }, [$again, $csv, $copy]),
            $how
        ]);
    }


    function render(data) {
        state.data = data;
        state.filter = 'all';
        state.sort = 'impact';

        var $wrap = h('div', { 'class': 'kwip-report' });
        if (!data.score) {
            $wrap.append(
                h('div', { 'class': 'kwip-scan__verdict' }, [
                    icon('info'),
                    h('div', null, [
                        h('strong', { text: i18n.noImages }),
                        h('p', { text: i18n.noImagesDetail })
                    ])
                ])
            );
            announce(i18n.noImages);
            return $wrap;
        }

        $wrap.append(renderTop(data), renderParts(data), renderCompare(data), renderRecs(data), renderList(data), renderFoot());
        $wrap.on('click', '.kwip-fixall', fixAll);
        announce(data.score.headline + '. ' + i18n.score + ' ' + data.score.overall);
        return $wrap;
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

    function skeleton() {
        return h('div', { 'class': 'kwip-skeleton', 'aria-hidden': 'true' }, [
            h('div', { 'class': 'kwip-skeleton__block kwip-skeleton__block--wide' }),
            h('div', { 'class': 'kwip-skeleton__row' }, [
                h('div', { 'class': 'kwip-skeleton__block' }),
                h('div', { 'class': 'kwip-skeleton__block' }),
                h('div', { 'class': 'kwip-skeleton__block' }),
                h('div', { 'class': 'kwip-skeleton__block' })
            ]),
            h('p', { 'class': 'description', text: i18n.scanningNote })
        ]);
    }

    function fail(text) {
        $message.text(text || i18n.error);
        announce(text || i18n.error);
    }

    $form.on('submit', function (event) {
        event.preventDefault();
        if (state.busy) {
            return;
        }

        state.busy = true;
        $button.prop('disabled', true).text(i18n.scanning);
        $spinner.addClass('is-active');
        $message.text('');
        $result.attr('aria-busy', 'true').empty().append(skeleton());

        $.ajax({
            url: cfg.ajaxUrl,
            method: 'POST',
            timeout: 90000,
            data: { action: 'kwip_scan', nonce: cfg.nonce, url: $url.val() }
        })
            .done(function (response) {
                if (response && response.success) {
                    var $report = render(response.data);
                    $result.empty().append($report);
                    animateIn($report);
                    return;
                }
                $result.empty();
                fail(response && response.data && response.data.message);
            })
            .fail(function (xhr) {
                var json = xhr && xhr.responseJSON;
                $result.empty();
                fail(json && json.data && json.data.message);
            })
            .always(function () {
                state.busy = false;
                $button.prop('disabled', false).text(i18n.scan);
                $spinner.removeClass('is-active');
                $result.removeAttr('aria-busy');
            });
    });
}(jQuery));