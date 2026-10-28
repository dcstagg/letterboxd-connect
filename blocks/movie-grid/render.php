<?php
/**
 * Movie Grid block. The markup is built in includes/block.php.
 *
 * @var array $attributes
 */

defined( 'ABSPATH' ) || exit;

echo lbc_render_movie_grid( $attributes ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped as it's built.
