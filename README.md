# Assist My Shop (WordPress Plugin)

Minimal developer documentation for this repository.

## Requirements

- WordPress `>= 5.0`
- PHP `>= 7.4`
- WooCommerce recommended

## Local Development

Plugin main file: `ams.php`

Typical dev flow:

1. Edit source assets:
   - `assets/chat.js`
   - `assets/chat.css`
   - `assets/admin/js/*.js`
   - `assets/admin/css/*.css`
2. Regenerate minified assets (for production loading):

```bash
node scripts/minify-assets.js
```

## Asset Loading Behavior

- In production (default): plugin loads `*.min.js` / `*.min.css` when present.
- In debug mode (`SCRIPT_DEBUG = true`): plugin loads non-minified source files.
- Asset versions use `filemtime()` for cache busting.

## Constants

### Internal (defined by plugin)

- `AMS_PATH` - absolute plugin path (filesystem)
- `AMS_URL` - plugin base URL

These are defined during plugin bootstrap in `ams.php`.

### Optional override

- `AMS_API_BASE_URL` - override backend API base URL

Example (in wp-config or environment bootstrap):

```php
define( 'AMS_API_BASE_URL', 'https://your-api.example.com/api/v1' );
```

## Notes

- WP.org readme is `readme.txt` (kept separately for directory requirements).
- If you changed JS/CSS and don’t see updates on prod, regenerate minified files and clear caches.
