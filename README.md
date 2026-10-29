# KWIP Image

A WordPress image optimization plugin focused on WebP conversion, efficient image delivery, and website performance.

KWIP Image is designed to help WordPress websites reduce image file sizes through WebP conversion and deliver optimized images to compatible browsers while preserving original files.

The project is actively being developed, with plans to expand into a more comprehensive image optimization toolkit for WordPress.

## Features

- **WebP Conversion** — Convert supported JPEG and PNG images into WebP format.
- **Automatic Optimization** — Process supported images automatically when uploaded, when enabled.
- **Bulk Optimization** — Optimize existing images in the WordPress Media Library.
- **Configurable Quality** — Adjust WebP quality to balance image quality and file size.
- **Original File Preservation** — Keep original images available for fallback and restoration.
- **WebP Delivery** — Serve generated WebP files to compatible browsers.
- **Optimization Statistics** — Review conversion results and file-size savings.
- **Server Configuration** — Support automatic WebP delivery configuration on compatible Apache and LiteSpeed environments.
- **Nginx Configuration Guidance** — Provide an example configuration for manual setup.

## Requirements

- WordPress
- PHP 8.2 or later
- A server with compatible image-processing support for WebP generation

Refer to the plugin configuration and your hosting environment for specific compatibility requirements.

## Installation

### Manual Installation

1. Download or clone this repository.
2. Place the plugin folder inside your WordPress installation's `wp-content/plugins/` directory.
3. Open your WordPress administration dashboard.
4. Navigate to **Plugins**.
5. Activate **KWIP Image**.
6. Open the plugin settings and configure your preferred optimization options.

### Clone with Git

```bash
git clone https://github.com/borabolawrence/KWIP-Image.git
```

Place the cloned folder in:

```text
wp-content/plugins/KWIP-Image/
```

Then activate the plugin through the WordPress dashboard.

## WebP Delivery

KWIP Image can serve generated WebP files when a browser supports WebP and a corresponding optimized file exists.

For example:

```text
product.jpg
product.jpg.webp
```

When WebP delivery is configured correctly, a request for `product.jpg` can return the WebP version without requiring changes to the image URL in the page markup.

### Apache and LiteSpeed

KWIP Image can manage WebP rewrite rules in `.htaccess` on supported configurations.

Existing server rules should be preserved, and the resulting configuration should be tested with your hosting environment.

### Nginx

Nginx does not support `.htaccess`. WebP delivery therefore requires manual server-level configuration.

The provided Nginx configuration is an example and should be reviewed and tested before being used in production.

### Compatibility Notes

- A generated WebP file must exist for automatic delivery to work.
- Correct MIME types and cache behavior are important.
- Browser support and server configuration determine which image format is delivered.
- Back up configuration files before modifying server rules.

## Original Image Preservation

KWIP Image is designed to retain original images rather than overwrite them during WebP conversion.

Keeping originals allows the website to fall back to the original format when an optimized version is unavailable and makes restoration possible.

## Roadmap

Potential future improvements include:

- Additional modern image formats, including AVIF
- More comprehensive bulk optimization tools
- Expanded optimization statistics and reporting
- Improved server and CDN compatibility
- Additional image delivery and performance controls

Roadmap items are planned possibilities and are not necessarily available in the current release.

## Contributing

KWIP Image is actively being developed.

Bug reports, suggestions, and feature requests can be submitted through [GitHub Issues](https://github.com/borabolawrence/KWIP-Image/issues).

If you would like to contribute code, submit a Pull Request describing the changes and how they were tested.

## License

No license has been specified yet.

Unless the copyright holder grants permission or a license is added to this repository, all rights are reserved. Public availability of this repository does not automatically grant permission to copy, modify, or redistribute its code.

## Repository

[KWIP Image on GitHub](https://github.com/borabolawrence/KWIP-Image)