(function () {
    'use strict';

    /*
     * Page method safety. A <source type="image/webp"> beats the <img> inside
     * the same <picture>, so when another script changes the image (variation
     * swatches, gallery thumbnails, sliders) the old picture would stay on
     * screen. When that happens, drop our <source> so the browser uses the
     * <img> the other script set.
     */
    if (!window.MutationObserver || !window.WeakMap) {
        return;
    }

    var seen = new WeakMap();

    function pictureOf(img) {
        var parent = img.parentNode;
        return parent && 'PICTURE' === parent.nodeName && parent.classList.contains('kwip-picture') ? parent : null;
    }

    function snapshot(img) {
        seen.set(img, { src: img.getAttribute('src'), srcset: img.getAttribute('srcset') });
    }

    function dropSources(picture) {
        var sources = picture.querySelectorAll('source[type="image/webp"]');
        for (var i = 0; i < sources.length; i++) {
            if (sources[i].parentNode) {
                sources[i].parentNode.removeChild(sources[i]);
            }
        }
    }

    var images = document.querySelectorAll('picture.kwip-picture > img');
    if (!images.length) {
        return;
    }
    for (var i = 0; i < images.length; i++) {
        snapshot(images[i]);
    }

    new MutationObserver(function (records) {
        for (var r = 0; r < records.length; r++) {
            var record = records[r];

            if ('attributes' === record.type) {
                var img = record.target;
                var before = 'IMG' === img.nodeName ? seen.get(img) : null;
                var picture = before ? pictureOf(img) : null;

                if (picture && (img.getAttribute('src') !== before.src || img.getAttribute('srcset') !== before.srcset)) {
                    dropSources(picture);
                    snapshot(img);
                }
                continue;
            }

            for (var n = 0; n < record.addedNodes.length; n++) {
                var node = record.addedNodes[n];
                var holder = (1 === node.nodeType && 'IMG' === node.nodeName && !seen.has(node)) ? pictureOf(node) : null;
                if (holder) {
                    dropSources(holder);
                    snapshot(node);
                }
            }
        }
    }).observe(document.documentElement, {
        subtree: true,
        childList: true,
        attributes: true,
        attributeFilter: ['src', 'srcset']
    });
}());