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
 * Enqueues stylesheets for extended block style variations.
 *
 * Block styles are registered via theme.json partials by WordPress core.
 * This function only handles enqueueing the associated stylesheets, either
 * immediately or on-demand when blocks render.
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
		$style_handle = get_variation_style_handle( $json_path, $stylesheet_ref );

		// Skip if stylesheet couldn't be resolved.
		if ( empty( $style_handle ) ) {
			continue;
		}

		// Enqueue for each block type this variation applies to.
		foreach ( $variation['blockTypes'] ?? [] as $block_name ) {
			enqueue_variation_style_for_block( $block_name, $variation_slug, $style_handle, $load_on_demand );
		}
	}
}

/**
 * Derive and return the version string for a stylesheet.
 *
 * Uses the ?ver= query from a stylesheet reference string when present, falling
 * back to a file hash and then to filemtime if hashing fails. Version string is
 * preferred for maximum performance.
 *
 * @param string $asset_file_path Path to an asset on disk.
 * @return string|false Hash or filemtime of the specified file.
 */
function get_version_hash( string $asset_file_path, string $stylesheet_ref ): string|false {
	if ( preg_match( '/[?&]ver=([^&]+)/', $stylesheet_ref, $matches ) ) {
		return rawurldecode( $matches[1] );
	}

	$file_hash = false;
	if ( function_exists( 'hash_file' ) ) {
		$file_hash = hash_file( 'crc32', $asset_file_path );
	}
	return ( $file_hash ?: (string) filemtime( $asset_file_path ) ) ?: false;
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
function get_variation_style_handle( string $json_path, ?string $stylesheet_ref ): string {
	if ( empty( $stylesheet_ref ) ) {
		return '';
	}

	// Not a file reference - assume it's an already-registered handle.
	if ( ! str_starts_with( $stylesheet_ref, 'file:' ) ) {
		return $stylesheet_ref;
	}

	// Remove the "file:" prefix using WordPress core function.
	$relative_path = remove_block_asset_path_prefix( $stylesheet_ref );

	// Remove any version string or query arguments.
	$relative_path = preg_replace( '/\?.*$/', '', $relative_path );

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

	// Register the stylesheet with a file-derived version.
	$stylesheet_uri = get_theme_file_uri( $relative_to_theme );
	$registered = wp_register_style(
		$style_handle,
		$stylesheet_uri,
		[],
		get_version_hash( $stylesheet_path, $stylesheet_ref )
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
 * Enqueues a variation stylesheet for a specific block type.
 *
 * Handles both immediate enqueueing and on-demand loading via render_block hooks.
 *
 * @param string $block_name     The block type name (e.g., 'core/button').
 * @param string $variation_slug The variation slug (e.g., 'outline').
 * @param string $style_handle   The registered stylesheet handle.
 * @param bool   $load_on_demand Whether to load on-demand or immediately.
 */
function enqueue_variation_style_for_block( string $block_name, string $variation_slug, string $style_handle, bool $load_on_demand ): void {
	// The style is already registered; only pass handle and path (for potential inlining).
	$enqueue_args = [ 'handle' => $style_handle ];

	$stylesheet_path = get_stylesheet_path( $style_handle );
	if ( $stylesheet_path ) {
		$enqueue_args['path'] = $stylesheet_path;
	}

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
			static function ( $block_content, $block ) use ( $variation_slug, $block_name, $enqueue_args ) {
				// Check if this is the right block type with the variation class applied.
				if (
					! empty( $block['blockName'] ) &&
					$block_name === $block['blockName'] &&
					! empty( $block['attrs']['className'] ) &&
					str_contains( $block['attrs']['className'], "is-style-{$variation_slug}" )
				) {
					wp_enqueue_block_style( $block_name, $enqueue_args );
				}
				return $block_content;
			},
			1,
			2
		);
	} else {
		// Enqueue immediately.
		wp_enqueue_block_style( $block_name, $enqueue_args );
	}
}

/**
 * Retrieves the filesystem path for a registered stylesheet handle.
 *
 * @param string $handle The stylesheet handle.
 * @return string|null The filesystem path or null if not found.
 */
function get_stylesheet_path( string $handle ): ?string {
	$wp_styles = wp_styles();
	if ( isset( $wp_styles->registered[ $handle ]->extra['path'] ) ) {
		return $wp_styles->registered[ $handle ]->extra['path'];
	}
	return null;
}

