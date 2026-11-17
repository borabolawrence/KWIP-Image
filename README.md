# KWIP Image

A WordPress plugin that converts JPG and PNG images to WebP, so pages load faster, while keeping your original files untouched.

> Status: pre-release. Compatibility with page builders and WooCommerce has not been fully tested yet. See [Known limitations](#known-limitations).

## Features

- **WebP conversion**: JPEG and PNG images, including every WordPress-generated size, with transparency kept.
- **Automatic optimization**: new uploads are queued and converted in the background (WP-Cron), with up to three retries.
- **Bulk optimization**: convert the existing Media Library with a progress bar you can pause and resume.
- **Quality presets**: Highest (100), Balanced (85), Smallest (70), or any value from 1 to 100.
- **Size check**: a WebP is only used when it is smaller than the original by at least a minimum saving (5% by default).
- **Originals are never changed**: the WebP is saved next to the original as `<file>.webp` and verified before it is used.
- **Restore**: one click in the Media Library removes an image's WebP files. A "Delete all WebP files" tool is in Settings.
- **Two delivery methods**: a server method and a page method (below), chosen automatically for your server.
- **Page report**: scan pages of your site for a score, the image weight, where each image is used, and whether visitors really get the WebP version.
- **Health check**: server requirements, a list of images that failed with a plain-language reason and a Try again button, and a delivery test.

## Requirements

- WordPress 6.0 or later
- PHP 8.2 or later
- GD or Imagick with WebP support (the Health check tells you what your server has)

## Installation

1. Download or clone this repository into `wp-content/plugins/` (the folder name is not important).
2. Activate **KWIP Image** in **Plugins**.
3. Open **KWIP Image** in the admin menu, then **Optimize images** to convert your existing library.

```bash
git clone https://github.com/borabolawrence/KWIP-Image.git
```

## How WebP reaches your visitors

Generating a WebP file is not enough: the browser has to be given it. KWIP Image offers two ways.

**Server method** (Apache and LiteSpeed). The plugin adds rewrite rules to `.htaccess`. A request for `photo.jpg` from a browser that accepts WebP is answered with `photo.jpg.webp`. Page markup is not changed. Some CDNs ignore the `Vary: Accept` header, so with a CDN the page method is safer.

**Page method** (any server, CDN or cache). Images in the page are wrapped in `<picture>` with a WebP `<source>`, and the visitor's browser chooses. Every visitor gets the same HTML, so caches are safe. A very small script (`assets/js/picture.js`) removes the WebP `<source>` of an image when another script changes that image, for example WooCommerce variation swatches.

The default is **Automatic**: the server method where `.htaccess` works, the page method everywhere else.

Add `?kwip_off=1` to any page address to see it without the page method, for comparison.

**Nginx** cannot be configured by a plugin. Example rules are shown in the Health check, but they are untested.

## Safe by design

- Originals are never overwritten or deleted. Restoring only removes WebP files.
- A generated file is written to a temporary name, verified (valid WebP, expected dimensions), then renamed into place.
- If conversion fails, visitors keep getting the original and nothing on the page breaks.
- Animated PNG and GIF files are skipped, and SVG is never touched.
- Uninstalling removes the settings and records. Your WebP files are kept unless you switched on "Also delete the WebP files when the plugin is deleted".
- Quality 100 is the default, but it is not lossless WebP.

## For developers

- `data-kwip-skip` on an `<img>` leaves that image alone in the page method.
- The `kwip_picture_skip` filter does the same from code:

```php
add_filter( 'kwip_picture_skip', function ( $skip, $tag, $attrs ) {
	return $skip || str_contains( $attrs['class'] ?? '', 'wp-post-image' );
}, 10, 3 );
```

### Tests

The suite runs inside your own WordPress install. Use a local or development site only:

```bash
php tests/run.php --yes
```

It creates temporary images, restores the plugin settings afterwards and removes what it made. It needs the plugin to be active and a PHP with GD and WebP. Add `-v` to list every test, or `--filter=picture` to run one group.

It does not cover the browser side (JavaScript behaviour), real `.htaccess` delivery, or page-builder and WooCommerce pages. Those are checked by hand.

## Known limitations

- Not yet tested on WooCommerce, Elementor, Divi, Beaver Builder or Flatsome UX Builder pages.
- The page method cannot change CSS background images, or images that a script adds after the page loads.
- Images outside the uploads folder, and images on other websites, are not optimized.
- Nginx rules are untested.
- WebP only. There is no AVIF and no lossless option yet.

## Roadmap

Possible future work, not promised: AVIF, a lossless option, WP-CLI commands and more developer hooks.

## Contributing

Bug reports and suggestions are welcome through [GitHub Issues](https://github.com/borabolawrence/KWIP-Image/issues). For code changes, open a pull request that says what changed and how it was tested, and run the test suite first.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).