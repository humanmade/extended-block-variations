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
	 * as in those which define a "stylesheet" custom property.
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
		// variations. A variation is considered "extended" if it defines a stylesheet.
		$variations = [];
		foreach ( $variation_files as $file_path => $file ) {
			// read_json_file caches data so it is only read from disk once.
			$variation = parent::read_json_file( $file_path );
			if ( isset( $variation['stylesheet'] ) ) {
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

		if ( ! $registered ) {
			return '';
		}

		// Add path data for potential inlining.
		wp_style_add_data( $style_handle, 'path', $stylesheet_path );

		// Check for RTL version.
		$rtl_file_path = str_replace( '.css', '-rtl.css', $stylesheet_path );
		if ( file_exists( $rtl_file_path ) ) {
			wp_style_add_data( $style_handle, 'rtl', 'replace' );
			if ( is_rtl() ) {
				wp_style_add_data( $style_handle, 'path', $rtl_file_path );
			}
		}

		return $style_handle;
	}

	/**
	 * Enqueues stylesheets for extended block style variations.
	 *
	 * Block styles are registered via theme.json partials by WordPress core.
	 * This method only handles enqueueing the associated stylesheets, either
	 * immediately or on-demand when blocks render.
	 */
	public static function register_extended_block_styles(): void {
		$load_on_demand = wp_should_load_block_assets_on_demand();

		foreach ( static::get_extended_block_variations() as $variation ) {
			$json_path = $variation['json_path'] ?? '';
			$stylesheet_ref = $variation['stylesheet'] ?? null;

			// Skip if no stylesheet is defined.
			if ( empty( $stylesheet_ref ) ) {
				continue;
			}

			$variation_slug = $variation['slug'] ?? '';
			$style_handle = static::get_variation_style_handle( $json_path, $stylesheet_ref );

			// Skip if stylesheet couldn't be resolved.
			if ( empty( $style_handle ) ) {
				continue;
			}

			// Get stylesheet details for wp_enqueue_block_style.
			$stylesheet_path = static::get_stylesheet_path_from_handle( $style_handle );
			$stylesheet_url = static::get_stylesheet_url_from_handle( $style_handle );

			// Enqueue for each block type this variation applies to.
			foreach ( $variation['blockTypes'] ?? [] as $block_name ) {
				$enqueue_args = [
					'handle' => $style_handle,
					'src'    => $stylesheet_url,
					'deps'   => [],
					'ver'    => $stylesheet_path ? filemtime( $stylesheet_path ) : false,
					'media'  => 'all',
				];

				// Add path for potential inlining.
				if ( $stylesheet_path ) {
					$enqueue_args['path'] = $stylesheet_path;
				}

				if ( $load_on_demand ) {
					// Enqueue on-demand when block renders with this variation class.
					$hook_name = "render_block_{$block_name}";
					add_filter(
						$hook_name,
						static function ( $block_content, $block ) use ( $variation_slug, $block_name, $enqueue_args ) {
							// Check if block has the variation class.
							if ( ! empty( $block_content ) && str_contains( $block_content, "is-style-{$variation_slug}" ) ) {
								wp_enqueue_block_style( $block_name, $enqueue_args );
							}
							return $block_content;
						},
						10,
						2
					);
				} else {
					// Enqueue immediately.
					wp_enqueue_block_style( $block_name, $enqueue_args );
				}
			}
		}
	}

	/**
	 * Retrieves the filesystem path for a registered stylesheet handle.
	 *
	 * @param string $handle The stylesheet handle.
	 * @return string|null The filesystem path or null if not found.
	 */
	private static function get_stylesheet_path_from_handle( string $handle ): ?string {
		$wp_styles = wp_styles();
		if ( isset( $wp_styles->registered[ $handle ]->extra['path'] ) ) {
			return $wp_styles->registered[ $handle ]->extra['path'];
		}
		return null;
	}

	/**
	 * Retrieves the URL for a registered stylesheet handle.
	 *
	 * @param string $handle The stylesheet handle.
	 * @return string|false The stylesheet URL or false if not found.
	 */
	private static function get_stylesheet_url_from_handle( string $handle ) {
		$wp_styles = wp_styles();
		if ( isset( $wp_styles->registered[ $handle ]->src ) ) {
			return $wp_styles->registered[ $handle ]->src;
		}
		return false;
	}
}
