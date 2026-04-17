# Extended Block Variations

Extends WordPress theme.json block style variations with custom properties for external stylesheets.

## Overview

WordPress supports defining block style variations in theme.json partials located in the `/styles/` directory. However, the native implementation doesn't support linking external CSS files to style variations, either by style_handle or by file: reference.

This plugin adds support for this feature through a custom property in your theme.json variation files.

## Usage

### Custom Property

Add this custom property to your block style variation JSON files in `themes/your-theme/styles/.../*.json`:

#### `stylesheet` (string)

Path to an external stylesheet or a registered style handle.

- **File references**: Use `"file:./path/to/file.css"` to reference a CSS file relative to the JSON file's location
- **Style handles**: Use a string matching a registered WordPress style handle

### Example

**File**: `themes/your-theme/styles/blocks/button/primary.json`

```json
{
  "$schema": "https://schemas.wp.org/trunk/theme.json",
  "version": 3,
  "title": "Primary",
  "slug": "primary",
  "blockTypes": ["core/button"],
  "stylesheet": "file:./primary-button.css",
  "styles": {
    "color": {
      "background": "var:preset|color|primary",
      "text": "var:preset|color|white"
    }
  }
}
```

**File**: `themes/your-theme/styles/blocks/button/primary-button.css`

```css
.is-style-primary {
  /* Additional styles requiring media queries or other CSS-only features */
  @media (max-width: 768px) {
    padding: 1em 2em;
  }
}
```

### File Path Resolution

File paths are resolved relative to the JSON file's directory:

- JSON at: `themes/your-theme/styles/blocks/button/primary.json`
- Reference: `"stylesheet": "file:./styles.css"`
- Resolves to: `themes/your-theme/styles/blocks/button/styles.css`

Use `../` to reference parent directories:

- Reference: `"stylesheet": "file:../../shared/button-base.css"`
- Resolves to: `themes/your-theme/styles/shared/button-base.css`

### Using Pre-registered Handles

If you've already registered a style handle via `wp_register_style()`, reference it directly:

```json
{
  "stylesheet": "my-custom-handle"
}
```

## How It Works

1. WordPress core reads theme.json partials from `/styles/` and registers block style variations
2. This plugin scans the same directory for JSON files containing a `stylesheet` property
3. For `file:` references:
   - The path is resolved relative to the JSON file's location
   - A unique style handle is generated based on the file path
   - The stylesheet is registered with WordPress (including RTL support and path data for inlining)
4. Stylesheets are enqueued via `wp_enqueue_block_style()`:
   - If block assets load on-demand: Enqueued when blocks with the variation class render
   - Otherwise: Enqueued immediately
5. WordPress handles merging the styles with theme.json definitions and potential inlining

## Compatibility

- **WordPress**: 6.6+
- **PHP**: 8.0+

This plugin extends core WordPress functionality and follows the same file structure conventions as WordPress core's theme.json parser.

## API Reference

### `Extended_Block_Variations\register_extended_block_styles(): void`

Enqueues stylesheets for block style variations that define a custom `stylesheet` property. Handles both immediate and on-demand loading based on theme configuration.

## Performance Optimizations

The plugin integrates with WordPress's block asset loading strategies:

- **On-demand loading**: When enabled, stylesheets only load when blocks with the variation are actually rendered
- **Automatic inlining**: Small stylesheets (under 40KB by default) may be inlined automatically
- **RTL support**: Automatically detects and loads RTL versions of stylesheets when available
- **File versioning**: Uses file modification time for cache busting

## Integration with Theme

## Integration with Theme

WordPress core automatically registers block style variations from theme.json partials in the `/styles/` directory. This plugin enhances that process by:

1. Detecting variations with custom `stylesheet` properties
2. Resolving and registering the referenced stylesheets
3. Enqueueing them alongside the core-registered block styles

The plugin hooks into `after_setup_theme`, running before block registration to ensure stylesheets are ready when blocks render. No additional configuration is required - simply add the `stylesheet` property to your variation JSON files.

## Setting Default Block Styles

To configure default block styling, use theme.json to set styles at the block level rather than the variation level. This is the WordPress-native approach and ensures proper inheritance and overrides.
