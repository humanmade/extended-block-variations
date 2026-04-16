<?php
/**
 * Extended Block Variations namespace functions.
 */

namespace Extended_Block_Variations;

/**
 * Bootstrap the plugin functionality.
 *
 * Hooks into WordPress to register extended block style variations
 * that include custom stylesheet and default properties.
 */
function bootstrap() {
	add_action( 'after_setup_theme', __NAMESPACE__ . '\\register_extended_block_styles' );
}

/**
 * Registers extended block styles with custom stylesheet and default properties.
 *
 * Processes theme.json partials in /styles/ directory that include custom
 * "stylesheet" or "default" properties, to augment WordPress-registered block
 * styles with external stylesheet handles or is_default flags.
 */
function register_extended_block_styles() {
	Variation_JSON_Resolver::register_extended_block_styles();
}
