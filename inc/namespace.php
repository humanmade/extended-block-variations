<?php
/**
 * Extended Block Variations namespace functions.
 */

namespace Extended_Block_Variations;

/**
 * Bootstrap the plugin functionality.
 *
 * Hooks into WordPress to enqueue stylesheets for block style variations
 * defined in theme.json partials with the custom "stylesheet" property.
 */
function bootstrap() {
	add_action( 'after_setup_theme', __NAMESPACE__ . '\\register_variation_stylesheets' );
}

/**
 * Sets up render_block filters or enqueue actions for extended block style variations.
 *
 * Block styles are registered via theme.json partials by WordPress core.
 * This function sets up an enqueueing mechanism for associated stylesheets,
 * which are enqueued while rendering a relevant variation (or globally on
 * wp_enqueue_scripts if on-demand loading is not enabled).
 */
function register_variation_stylesheets() {
	$load_on_demand = wp_should_load_block_assets_on_demand();

	foreach ( Variation_JSON_Resolver::get_extended_block_variations() as $variation ) {
		$json_path = $variation['json_path'] ?? '';
		$stylesheet_ref = $variation['stylesheet'] ?? null;

		// Skip if no stylesheet is defined.
		if ( empty( $stylesheet_ref ) ) {
			continue;
		}

		$variation_slug = $variation['slug'] ?? '';
		$style_args = get_variation_stylesheet_args( $json_path, $stylesheet_ref );

		// Skip if stylesheet couldn't be resolved.
		if ( empty( $style_args ) ) {
			continue;
		}

		// Enqueue for each block type this variation applies to.
		foreach ( $variation['blockTypes'] ?? [] as $block_name ) {
			enqueue_variation_style_for_block( $block_name, $variation_slug, $style_args, $load_on_demand );
		}
	}
}

/**
 * Compute a hash for an asset file, with a fallback to filemtime if hashing fails.
 *
 * @param string $asset_file_path Path to an asset on disk.
 * @return string|false Hash or filemtime of the specified file.
 */
function get_version_hash( string $asset_file_path ): string|false {
	$file_hash = false;
	if ( function_exists( 'hash_file' ) ) {
		$file_hash = hash_file( 'crc32', $asset_file_path );
	}
	return ( $file_hash ?: (string) filemtime( $asset_file_path ) ) ?: false;
}

/**
 * Resolves a variation's stylesheet reference into enqueueable args.
 *
 * Handles "file:" references by resolving them relative to the JSON file's directory,
 * or returns a handle-only array for already-registered handles.
 *
 * @param string  $json_path      The filesystem path to the JSON file containing the reference.
 * @param ?string $stylesheet_ref The stylesheet property value (e.g., "file:./style.css" or "my-handle").
 * @return array Stylesheet args (handle, and optionally src/path/ver/deps/media), or empty array if invalid.
 */
function get_variation_stylesheet_args( string $json_path, ?string $stylesheet_ref ): array {
	if ( empty( $stylesheet_ref ) ) {
		return [];
	}

	// Not a file reference - assume it's an already-registered handle.
	if ( ! str_starts_with( $stylesheet_ref, 'file:' ) ) {
		return [ 'handle' => $stylesheet_ref ];
	}

	// Remove the "file:" prefix using WordPress core function.
	$relative_path = remove_block_asset_path_prefix( $stylesheet_ref );

	// Resolve relative to the JSON file's directory.
	$json_dir = dirname( $json_path );
	$stylesheet_path = wp_normalize_path( realpath( $json_dir . '/' . $relative_path ) );

	// Verify the file exists before registering.
	if ( ! $stylesheet_path || ! file_exists( $stylesheet_path ) ) {
		return [];
	}

	// Generate a unique handle based on the file path.
	$theme_dir = wp_normalize_path( get_stylesheet_directory() );
	$relative_to_theme = str_replace( trailingslashit( $theme_dir ), '', $stylesheet_path );
	$style_handle = 'block-style-' . str_replace( [ '/', '.' ], '-', $relative_to_theme );

	return [
		'handle' => $style_handle,
		'src'    => get_theme_file_uri( $relative_to_theme ),
		'path'   => $stylesheet_path,
		'ver'    => get_version_hash( $stylesheet_path ),
	];
}

/**
 * Enqueues a variation stylesheet for a specific block type.
 *
 * Handles both immediate enqueueing and on-demand loading via render_block hooks.
 * For on-demand loading, mirrors core's wp_enqueue_block_style behavior and
 * registers the sheet inside a render_block callback. For immediate loading,
 * delegates to wp_enqueue_block_style which defers to wp_enqueue_scripts.
 *
 * @param string $block_name     The block type name (e.g., 'core/button').
 * @param string $variation_slug The variation slug (e.g., 'outline').
 * @param array  $style_args     Stylesheet args from get_variation_stylesheet_args().
 * @param bool   $load_on_demand Whether to load on-demand or immediately.
 */
function enqueue_variation_style_for_block( string $block_name, string $variation_slug, array $style_args, bool $load_on_demand ): void {
	if ( $load_on_demand ) {
		/*
		 * Hook into render_block (not render_block_{name}, which fires too late)
		 * at priority 1 so that our styles are registered before core enqueues
		 * stylesheets later on within the render_block hook.
		 *
		 * Using a named function is not possible in this case, so this logic
		 * cannot be unhooked. However, the stylesheets can be dequeued if needed
		 * which is why an anonymous function on a hook was deemed acceptable.
		 */
		add_filter(
			'render_block',
			function ( $block_content, $block ) use ( $variation_slug, $block_name, $style_args ) {
				// Check if this is the right block type with the variation class applied.
				if (
					! empty( $block['blockName'] ) &&
					$block_name === $block['blockName'] &&
					! empty( $block['attrs']['className'] ) &&
					str_contains( $block['attrs']['className'], "is-style-{$variation_slug}" )
				) {
					enqueue_variation_style( $style_args );
				}
				return $block_content;
			},
			1,
			2
		);
	} else {
		// Enqueue immediately.
		wp_enqueue_block_style( $block_name, $style_args );
	}
}

/**
 * Registers and enqueues a variation stylesheet.
 *
 * Called from within a render_block callback, mirroring the internal callback
 * structure used by wp_enqueue_block_style.
 *
 * @param array $args Stylesheet args array (handle, source, path, version).
 */
function enqueue_variation_style( array $args ): void {
	if ( ! wp_style_is( $args['handle'], 'registered' ) && isset( $args['src'] ) && isset( $args['path'] ) ) {
		wp_register_style( $args['handle'], $args['src'], $args['deps'] ?? [], $args['ver'] ?? false );
		wp_style_add_data( $args['handle'], 'path', $args['path'] );

		$rtl_file_path = str_replace( '.css', '-rtl.css', $args['path'] );
		if ( file_exists( $rtl_file_path ) ) {
			wp_style_add_data( $args['handle'], 'rtl', 'replace' );
			if ( is_rtl() ) {
				wp_style_add_data( $args['handle'], 'path', $rtl_file_path );
			}
		}
	}

	wp_enqueue_style( $args['handle'] );
}
