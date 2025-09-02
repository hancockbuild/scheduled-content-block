<?php
/**
 * Plugin Name: Scheduled Content Block
 * Description: A simple container block that enables the easy scheduleing of content on WordPress pages or posts.
 * Version: 0.0.3
 * Requires PHP: 8.2
 * Author: h.b Plugins
 * Author URI: https://hancock.build
 * License: GPL-3.0-or-later
 * Text Domain: scheduled-content-block
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'SCB_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'SCB_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * Register the block (metadata) and attach server render callback.
 */
add_action( 'init', function() {
	register_block_type( __DIR__ . '/block', array(
		'render_callback' => 'scb_render_callback',
	) );
} );

/**
 * Inline the editor script to avoid HTTP fetch issues (e.g. WAF/404 serving HTML).
 * We still keep the block.json for styles; this only inlines JS.
 */
add_action('enqueue_block_editor_assets', function () {
	$deps = array('wp-blocks','wp-element','wp-components','wp-editor','wp-i18n','wp-block-editor');
	wp_register_script('scb-inline-editor', false, $deps, '1.1.1', true);
	wp_enqueue_script('scb-inline-editor');

	$path = SCB_PLUGIN_DIR . 'block/editor.js';
	$js = @file_get_contents($path);

	if ($js === false) {
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
		'start'            => '',   // ISO8601, preferably UTC with Z
		'end'              => '',   // ISO8601, preferably UTC with Z
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

	$now     = scb_now_site_ts();
	$startTs = scb_parse_site_ts( $atts['start'] );
	$endTs   = scb_parse_site_ts( $atts['end'] );

	$hasStart = ( $atts['start'] !== '' && $startTs !== null );
	$hasEnd   = ( $atts['end']   !== '' && $endTs   !== null );

	// If user set a value but it failed to parse, play it safe: hide.
	$invalid = ( $atts['start'] !== '' && $startTs === null ) || ( $atts['end'] !== '' && $endTs === null );
	if ( $invalid ) {
		return ! empty( $atts['showPlaceholder'] ) ? '<div class="scb-placeholder" aria-hidden="true">' . esc_html( (string) $atts['placeholderText'] ) . '</div>' : '';
	}

	// Visibility rules:
	$visible = true;
	if ( $hasStart && $hasEnd ) {
		$visible = ( $now >= $startTs ) && ( $now <= $endTs );
	} elseif ( $hasStart ) {
		$visible = ( $now >= $startTs );
	} elseif ( $hasEnd ) {
		$visible = ( $now <= $endTs );
	} else {
		$visible = true; // no schedule set
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
 * Return UNIX timestamp for a given ISO string.
 * - If the string has a timezone (Z or ±HH:MM), parse as an absolute moment.
 * - If the string has no timezone, interpret in the site timezone.
 * Returns null if parsing fails.
 */
function scb_parse_site_ts( $iso ) {
	if ( ! is_string( $iso ) || $iso === '' ) return null;
	$iso = trim( $iso );

	// Detect explicit TZ marker (Z or +HH:MM / -HH:MM)
	$has_tz = (bool) preg_match( '/(Z|[+\-]\d{2}:?\d{2})$/i', $iso );

	try {
		if ( $has_tz ) {
			$dt = new DateTime( $iso ); // honor explicit timezone in string
		} else {
			$dt = new DateTime( $iso, wp_timezone() ); // assume site TZ
		}
		return $dt->getTimestamp();
	} catch ( Exception $e ) {
		return null;
	}
}

/** "Now" as epoch seconds (absolute moment). */
function scb_now_site_ts() {
	return time(); // epoch is timezone-agnostic
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
	// Use a friendlier format in the EDITOR: "January 1 2025 at 11:30am"
	$fmt  = $is_editor ? 'F j Y \a\t g:ia' : ( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) );

	$start_ts = scb_parse_site_ts( $atts['start'] );
	$end_ts   = scb_parse_site_ts( $atts['end'] );

	$start = ($atts['start'] && $start_ts !== null) ? wp_date( $fmt, $start_ts ) : '—';
	$end   = ($atts['end'] && $end_ts   !== null)   ? wp_date( $fmt, $end_ts )   : '—';
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
