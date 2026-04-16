<?php
/**
 * Variation JSON Resolver class.
 */

namespace Extended_Block_Variations;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RecursiveRegexIterator;
use RegexIterator;
use WP_Theme_JSON_Resolver;

/**
 * Resolves and processes extended block style variations from theme.json partials.
 *
 * Extends WordPress core's WP_Theme_JSON_Resolver to handle custom properties
 * in theme.json style variation files, specifically:
 * - "stylesheet": file reference or handle for external CSS
 * - "default": boolean to mark variation as default for the block
 */
class Variation_JSON_Resolver extends WP_Theme_JSON_Resolver {

	/**
	 * Cached extended variations array.
	 *
	 * @var array|null
	 */
	private static ?array $variations = null;

	/**
	 * Returns an array of all nested JSON files within a given directory.
	 *
	 * Copied from WP_Theme_JSON_Resolver::recursively_iterate_json.
	 * Redeclares a private base class method so we can use it here.
	 *
	 * @param string $dir The directory to recursively iterate and list files of.
	 * @return array The merged array.
	 */
	private static function recursively_iterate_json( $dir ) {
		$nested_files      = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir ) );
		$nested_json_files = iterator_to_array( new RegexIterator( $nested_files, '/^.+\.json$/i', RecursiveRegexIterator::GET_MATCH ) );
		return $nested_json_files;
	}

	/**
	 * Find and return the full parsed JSON of any "extended" block variations,
	 * as in those which define "stylesheet" or "default" custom properties.
	 *
	 * Re-runs Core's theme.json styles partial detection, which will reload
	 * parsed JSON data from an internal cache.
	 *
	 * Borrows logic from WP_Theme_JSON_Resolver::get_style_variations, without
	 * the final step of casting to WP_Theme_JSON (which removes our non-standard
	 * property extensions.)
	 *
	 * @return array Array of extended block style variations.
	 */
	public static function get_extended_block_variations(): array {
		if ( is_array( self::$variations ) ) {
			return static::$variations;
		}

		$base_directory     = get_stylesheet_directory() . '/styles';
		$template_directory = get_template_directory() . '/styles';
		$variation_files    = [];

		if ( is_dir( $base_directory ) ) {
			$variation_files = static::recursively_iterate_json( $base_directory );
		}

		if ( is_dir( $template_directory ) && $template_directory !== $base_directory ) {
			$variation_files_parent = static::recursively_iterate_json( $template_directory );
			// If the child and parent variation file basename are the same, only include the child theme's.
			foreach ( $variation_files_parent as $parent_path => $parent ) {
				foreach ( $variation_files as $child_path => $child ) {
					if ( basename( $parent_path ) === basename( $child_path ) ) {
						unset( $variation_files_parent[ $parent_path ] );
					}
				}
			}
			$variation_files = array_merge( $variation_files, $variation_files_parent );
		}

		// Read and filter the JSON files down to only those describing extended
		// variations. A variation is considered "extended" if it defines any of
		// our custom properties.
		$variations = [];
		foreach ( $variation_files as $file_path => $file ) {
			// read_json_file caches data so it is only read from disk once.
			$variation = parent::read_json_file( $file_path );
			if ( isset( $variation['stylesheet'] ) || isset( $variation['default'] ) ) {
				// Translate the style variation title appropriately.
				$translated = parent::translate( $variation, wp_get_theme()->get( 'TextDomain' ) );
				// Keep track of file_path so we can resolve relative "file:"
				// references for external stylesheets.
				$translated['json_path'] = $file_path;
				$variations[] = $translated;
			}
		}

		static::$variations = $variations;
		return $variations;
	}

	/**
	 * Resolves and registers a stylesheet from a variation's stylesheet property.
	 *
	 * Handles "file:" references by resolving them relative to the JSON file's directory,
	 * or returns the handle directly if already registered.
	 *
	 * @param string  $json_path      The filesystem path to the JSON file containing the reference.
	 * @param ?string $stylesheet_ref The stylesheet property value (e.g., "file:./style.css" or "my-handle").
	 * @return string The registered style handle, or empty string if not set/invalid.
	 */
	protected static function get_variation_style_handle( string $json_path, ?string $stylesheet_ref ): string {
		if ( empty( $stylesheet_ref ) ) {
			return '';
		}

		// Not a file reference - assume it's an already-registered handle.
		if ( ! str_starts_with( $stylesheet_ref, 'file:' ) ) {
			return $stylesheet_ref;
		}

		// Remove the "file:" prefix using WordPress core function.
		$relative_path = remove_block_asset_path_prefix( $stylesheet_ref );

		// Resolve relative to the JSON file's directory.
		$json_dir = dirname( $json_path );
		$stylesheet_path = wp_normalize_path( realpath( $json_dir . '/' . $relative_path ) );

		// Verify the file exists before registering.
		if ( ! $stylesheet_path || ! file_exists( $stylesheet_path ) ) {
			return '';
		}

		// Generate a unique handle based on the file path.
		$theme_dir = wp_normalize_path( get_stylesheet_directory() );
		$relative_to_theme = str_replace( trailingslashit( $theme_dir ), '', $stylesheet_path );
		$style_handle = 'block-style-' . str_replace( [ '/', '.' ], '-', $relative_to_theme );

		// Register the stylesheet with file modification time as version.
		$stylesheet_uri = get_theme_file_uri( $relative_to_theme );
		$registered = wp_register_style(
			$style_handle,
			$stylesheet_uri,
			[],
			filemtime( $stylesheet_path )
		);

		return $registered ? $style_handle : '';
	}

	/**
	 * Registers extended block styles with custom stylesheet and default properties.
	 *
	 * Re-registers variations in PHP to apply stylesheet handles and is_default flags,
	 * which aren't supported in theme.json partials. WordPress will merge these
	 * definitions with the theme.json styles.
	 */
	public static function register_extended_block_styles(): void {
		foreach ( static::get_extended_block_variations() as $variation ) {
			$json_path = $variation['json_path'] ?? '';
			$style_handle = static::get_variation_style_handle( $json_path, $variation['stylesheet'] ?? null );
			$variation_slug = $variation['slug'] ?? '';

			// Register for each block type this variation applies to.
			foreach ( $variation['blockTypes'] ?? [] as $block_name ) {
				$args = [
					'name'  => $variation_slug,
					'label' => $variation['title'] ?? $variation_slug,
				];

				// Add style handle if stylesheet was resolved.
				if ( ! empty( $style_handle ) ) {
					$args['style_handle'] = $style_handle;
				}

				// Set as default if specified.
				if ( ! empty( $variation['default'] ) ) {
					$args['is_default'] = true;
				}

				register_block_style( $block_name, $args );
			}
		}
	}
}
