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
	 * Cached block-level stylesheets array.
	 *
	 * @var array|null
	 */
	private static ?array $block_stylesheets = null;

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
	 * Find and return block-level stylesheet definitions from theme.json.
	 *
	 * Reads the active theme's theme.json and returns any blocks under
	 * styles.blocks that define a custom "stylesheet" property.
	 *
	 * @return array Array of entries with 'block_name', 'stylesheet', and 'json_path' keys.
	 */
	public static function get_extended_block_stylesheets(): array {
		if ( is_array( self::$block_stylesheets ) ) {
			return self::$block_stylesheets;
		}

		$theme_json_path = get_stylesheet_directory() . '/theme.json';

		if ( ! file_exists( $theme_json_path ) ) {
			self::$block_stylesheets = [];
			return self::$block_stylesheets;
		}

		$theme_json = parent::read_json_file( $theme_json_path );
		$blocks     = $theme_json['styles']['blocks'] ?? [];

		$block_stylesheets = [];
		foreach ( $blocks as $block_name => $block_data ) {
			if ( isset( $block_data['stylesheet'] ) ) {
				$block_stylesheets[] = [
					'block_name' => $block_name,
					'stylesheet' => $block_data['stylesheet'],
					'json_path'  => $theme_json_path,
				];
			}
		}

		self::$block_stylesheets = $block_stylesheets;
		return self::$block_stylesheets;
	}

}
