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

1. The plugin scans the theme's `/styles/` directory for JSON files
2. Files containing a `stylesheet` property are identified as extended variations
3. For `file:` references:
   - The path is resolved relative to the JSON file's location
   - A unique style handle is generated based on the file path
   - The stylesheet is registered with WordPress
4. Block styles are registered via `register_block_style()` with the resolved handles
5. WordPress merges these registrations with the theme's existing style definitions

## Compatibility

- **WordPress**: 6.6+
- **PHP**: 8.0+

This plugin extends core WordPress functionality and follows the same file structure conventions as WordPress core's theme.json parser.

## API Reference

### `Extended_Block_Variations\register_extended_block_styles(): void`

Processes and registers all extended block style variations with WordPress.

## Integration with Theme

The plugin automatically hooks into `after_setup_theme`, so extended variations are processed whenever your theme loads. No additional configuration is required.

Simply add the custom "stylesheet" property to your theme's existing `/styles/` directory JSON files, and they'll be automatically detected and processed.

## Setting Default Block Styles

To configure default block styling, use theme.json to set styles at the block level rather than the variation level. This is the WordPress-native approach and ensures proper inheritance and overrides.
