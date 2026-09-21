<?php
/**
 * "Add one display of every color", under a variable product's add-to-cart
 * form for a wholesale customer — see StarterKit::render_every_color(). One
 * button that puts one display of each color into the cart, so a buyer who
 * wants the whole range does not pick fourteen colors by hand. The list is
 * built from the product's live variations each time the page loads, so a
 * color added later appears with no change here.
 *
 * The form posts to admin-post.php so it works without JavaScript;
 * assets/js/every-color.js takes it over to add in place.
 *
 * Override by copying to yourtheme/woocommerce/add-every-color.php.
 *
 * @package ProtechWholesale
 *
 * @var int $product_id
 * @var array{lines: array<int, array{variation_id: int, name: string, color: string, displays: int, packs: int}>, unavailable: string[], displays: int, packs: int} $composition
 * @var array{total_html: string, summary: string, note: string, displays: int, cases: float} $quote
 * @var string $post_url
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$protech_colors = count( $composition['lines'] );
?>
<div class="protech-everycolor" data-product-id="<?php echo esc_attr( (string) $product_id ); ?>">
	<div class="protech-everycolor-head">
		<p class="protech-everycolor-title"><?php esc_html_e( 'Want every color?', 'protech-wholesale' ); ?></p>
		<ul class="protech-everycolor-swatches" aria-hidden="true">
			<?php foreach ( $composition['lines'] as $protech_line ) : ?>
				<li<?php echo '' !== $protech_line['color'] ? ' style="background-color:' . esc_attr( $protech_line['color'] ) . '"' : ''; ?> title="<?php echo esc_attr( $protech_line['name'] ); ?>"></li>
			<?php endforeach; ?>
		</ul>
	</div>

	<p class="protech-everycolor-detail">
		<?php
		echo esc_html(
			sprintf(
				/* translators: 1: number of colors, 2: number of displays, 3: number of packs. */
				_n( 'One display of the %1$d color: %2$d display, %3$d packs.', 'One display of each of the %1$d colors: %2$d displays, %3$d packs.', $protech_colors, 'protech-wholesale' ),
				$protech_colors,
				$composition['displays'],
				$composition['packs']
			)
		);
		?>
		<span class="protech-everycolor-total" data-protech-everycolor-total><?php echo wp_kses_post( $quote['total_html'] ); ?></span>
		<span class="protech-everycolor-note" data-protech-everycolor-note><?php echo wp_kses_post( $quote['note'] ); ?></span>
	</p>

	<?php if ( ! empty( $composition['unavailable'] ) ) : ?>
		<p class="protech-everycolor-note protech-everycolor-note--stock">
			<?php
			echo esc_html(
				sprintf(
					/* translators: %s: comma-separated color names. */
					__( 'Out of stock and left out for now: %s.', 'protech-wholesale' ),
					implode( ', ', $composition['unavailable'] )
				)
			);
			?>
		</p>
	<?php endif; ?>

	<form class="protech-everycolor-form" method="post" action="<?php echo esc_url( $post_url ); ?>">
		<input type="hidden" name="action" value="<?php echo esc_attr( \ProtechWholesale\StarterKit::POST_ACTION ); ?>" />
		<input type="hidden" name="kit_id" value="<?php echo esc_attr( (string) $product_id ); ?>" />
		<input type="hidden" name="kits" value="1" />
		<?php wp_nonce_field( \ProtechWholesale\StarterKit::NONCE_ACTION, 'protech_kit_nonce' ); ?>
		<button type="submit" class="button protech-everycolor-add"><?php esc_html_e( 'Add one display of every color', 'protech-wholesale' ); ?></button>
	</form>

	<p class="protech-everycolor-message" aria-live="polite"></p>
</div>
