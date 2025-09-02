<?php
/**
 * Plugin Name: Scheduled Content Block
 * Description: A simple container block that enables the easy scheduleing of content on WordPress pages or posts.
 * Version: 0.0.1
 * Requires PHP: 8.2
 * Author: h.b Plugins
 * Author URI: https://hancock.build
 * License: GPL-3.0-or-later
 * Text Domain: scheduled-content-block
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'SCB_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'SCB_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

add_action( 'init', function() {
	// Register block from block.json (build-less, editor.js ships unbundled).
	register_block_type( __DIR__ . '/block', array(
		'render_callback' => 'scb_render_callback',
	) );
} );

// Inline the editor script to avoid HTTP fetch issues (e.g., WAF/404 serving HTML).
add_action('enqueue_block_editor_assets', function () {
    // Ensure required editor globals are present.
    $deps = array('wp-blocks','wp-element','wp-components','wp-editor','wp-i18n','wp-block-editor');

    // Register a dummy handle, enqueue it, then inject our JS inline.
    wp_register_script('scb-inline-editor', false, $deps, '1.0.1', true);
    wp_enqueue_script('scb-inline-editor');

    // Read the JS directly from disk to bypass any HTTP layer.
    $path = SCB_PLUGIN_DIR . 'block/editor.js';
    $js = @file_get_contents($path);

    if ($js === false) {
        // Fallback minimal registration so the inserter still shows something.
        $js = "(function(wp){var el=wp.element.createElement,be=wp.blockEditor||wp.editor;var Inner=be.InnerBlocks;wp.blocks.registerBlockType('h-b/scheduled-container',{edit:function(){return el('div',null,'Scheduled Container (inline fallback)');},save:function(){return el(Inner.Content,null);}});})(window.wp);";
    }

    wp_add_inline_script('scb-inline-editor', $js);
});


/**
 * Server render callback: decides whether to output inner content based on schedule.
 *
 * @param array  $attributes
 * @param string $content (the saved InnerBlocks markup)
 * @param WP_Block $block
 * @return string
 */
function scb_render_callback( $attributes, $content, $block ) {
	$defaults = array(
		'start'            => '',   // ISO8601 e.g. 2025-09-02T09:00:00
		'end'              => '',   // ISO8601
		'showForAdmins'    => true, // Always show to logged-in admins
		'showPlaceholder'  => false,// Output a placeholder <div> when hidden
		'placeholderText'  => '',
	);
	$atts = wp_parse_args( $attributes, $defaults );

	// In the editor canvas (is_admin + block editor), always show for authoring clarity.
	if ( is_admin() && function_exists( 'get_current_screen' ) ) {
		$screen = get_current_screen();
		if ( $screen && method_exists( $screen, 'is_block_editor' ) && $screen->is_block_editor() ) {
			return scb_wrap_editor_notice( $atts, $content );
		}
	}

	// Always show to admins on the frontend if enabled.
	if ( ! empty( $atts['showForAdmins'] ) && current_user_can( 'manage_options' ) ) {
		return scb_wrap_admin_notice_front( $atts, $content );
	}

	$now_ts    = scb_now_site_ts();
	$start_ts  = scb_parse_site_ts( $atts['start'] );
	$end_ts    = scb_parse_site_ts( $atts['end'] );

	// Visibility rules:
	// - If both empty => always visible
	// - If start only  => visible when now >= start
	// - If end only    => visible when now <= end
	// - If both        => visible when start <= now <= end
	$visible = true;

	if ( $atts['start'] !== '' && $atts['end'] !== '' ) {
		$visible = ( $now_ts >= $start_ts ) && ( $now_ts <= $end_ts );
	} elseif ( $atts['start'] !== '' ) {
		$visible = ( $now_ts >= $start_ts );
	} elseif ( $atts['end'] !== '' ) {
		$visible = ( $now_ts <= $end_ts );
	}

	if ( $visible ) {
		return $content;
	}

	// Hidden:
	if ( ! empty( $atts['showPlaceholder'] ) ) {
		$txt = trim( (string) $atts['placeholderText'] );
		return '<div class="scb-placeholder" aria-hidden="true">' . esc_html( $txt ) . '</div>';
	}

	return '';
}

/**
 * Parse ISO datetime in the site timezone to UNIX timestamp.
 *
 * @param string $iso
 * @return int|null
 */
function scb_parse_site_ts( $iso ) {
	if ( ! $iso ) return null;

	try {
		$tz = wp_timezone(); // Site TZ
		// Allow either bare local ISO or offset/Z. If bare, treat as site tz.
		$dt = new DateTime( $iso, $tz );
		return $dt->getTimestamp();
	} catch ( Exception $e ) {
		return null;
	}
}

/** Get "now" in site timezone as a timestamp. */
function scb_now_site_ts() {
	// current_time('timestamp') returns site-local timestamp respecting timezone_string.
	return (int) current_time( 'timestamp' );
}

/** Editor-only wrapper with a visible schedule badge */
function scb_wrap_editor_notice( $atts, $content ) {
	$badge = scb_schedule_badge_html( $atts, true );
	return '<div class="scb-editor-wrap">' . $badge . $content . '</div>';
}

/** Frontend admin notice wrapper */
function scb_wrap_admin_notice_front( $atts, $content ) {
	$badge = scb_schedule_badge_html( $atts, false );
	return '<div class="scb-admin-visible">' . $badge . $content . '</div>';
}

/** Render a small schedule badge */
function scb_schedule_badge_html( $atts, $is_editor ) {
	$tz   = wp_timezone_string() ?: 'UTC';
	$fmt  = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
	$start = $atts['start'] ? wp_date( $fmt, scb_parse_site_ts( $atts['start'] ) ) : '—';
	$end   = $atts['end']   ? wp_date( $fmt, scb_parse_site_ts( $atts['end'] ) )   : '—';
	$who   = ( ! empty( $atts['showForAdmins'] ) ) ? ' (visible to admins always)' : '';

	$context = $is_editor ? 'Editor preview' : 'Frontend admin view';

	$html = sprintf(
		'<div class="scb-badge"><strong>Scheduled Content:</strong> %s | <strong>Start:</strong> %s | <strong>End:</strong> %s | <strong>TZ:</strong> %s%s</div>',
		esc_html( $context ),
		esc_html( $start ),
		esc_html( $end ),
		esc_html( $tz ),
		esc_html( $who )
	);
	return $html;
}
