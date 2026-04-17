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
	add_action( 'after_setup_theme', __NAMESPACE__ . '\\register_extended_block_styles' );
}

/**
 * Enqueues stylesheets for extended block style variations.
 *
 * Block styles are registered via theme.json partials by WordPress core.
 * This function only handles enqueueing the associated stylesheets, either
 * immediately or on-demand when blocks render.
 */
function register_extended_block_styles() {
	Variation_JSON_Resolver::register_extended_block_styles();
}
