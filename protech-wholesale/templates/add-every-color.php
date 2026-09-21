<?php
/**
 * "Add one display of every color", right under the wholesale price table
 * on a variable product's page, for a wholesale customer (see
 * StarterKit::render_every_color()). One button that puts one display of each color into the cart, so a buyer who
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

$protech_colors   = count( $composition['lines'] );
$protech_out      = (array) ( $composition['out_colors'] ?? array() );
$protech_out_names = array_column( $protech_out, 'name' );
?>
<div class="protech-everycolor<?php echo empty( $protech_out ) ? '' : ' has-out-of-stock'; ?>" data-product-id="<?php echo esc_attr( (string) $product_id ); ?>">
	<div class="protech-everycolor-head">
		<p class="protech-everycolor-title"><?php esc_html_e( 'Want every color?', 'protech-wholesale' ); ?></p>
		<ul class="protech-everycolor-swatches" aria-hidden="true">
			<?php foreach ( $composition['lines'] as $protech_line ) : ?>
				<li<?php echo '' !== $protech_line['color'] ? ' style="background-color:' . esc_attr( $protech_line['color'] ) . '"' : ''; ?> title="<?php echo esc_attr( $protech_line['name'] ); ?>"></li>
			<?php endforeach; ?>
			<?php foreach ( $protech_out as $protech_gone ) : ?>
				<li class="is-out"<?php echo '' !== $protech_gone['color'] ? ' style="background-color:' . esc_attr( $protech_gone['color'] ) . '"' : ''; ?> title="<?php echo esc_attr( sprintf( /* translators: %s: color name. */ __( '%s: out of stock', 'protech-wholesale' ), $protech_gone['name'] ) ); ?>"></li>
			<?php endforeach; ?>
		</ul>
	</div>

	<?php if ( ! empty( $protech_out_names ) ) : ?>
		<div class="protech-everycolor-alert" role="status">
			<span class="protech-everycolor-alert-icon" aria-hidden="true">!</span>
			<p class="protech-everycolor-alert-text">
				<strong>
					<?php
					echo esc_html(
						sprintf(
							/* translators: %s: comma-separated color names. */
							__( 'Out of stock: %s', 'protech-wholesale' ),
							implode( ', ', $protech_out_names )
						)
					);
					?>
				</strong>
				<?php
				echo esc_html( _n( 'It will not be added.', 'They will not be added.', count( $protech_out_names ), 'protech-wholesale' ) );
				echo ' ';
				echo esc_html(
					sprintf(
						/* translators: %d: number of colors that will be added. */
						_n( 'The other %d color is added.', 'The other %d colors are added.', $protech_colors, 'protech-wholesale' ),
						$protech_colors
					)
				);
				?>
			</p>
		</div>
	<?php endif; ?>

	<p class="protech-everycolor-detail">
		<?php
		echo esc_html(
			sprintf(
				empty( $protech_out_names )
					/* translators: 1: number of colors, 2: number of displays, 3: number of packs. */
					? __( 'One display of each of the %1$d colors: %2$d displays, %3$d packs.', 'protech-wholesale' )
					/* translators: 1: number of colors in stock, 2: number of displays, 3: number of packs. */
					: __( 'One display of each of the %1$d colors in stock: %2$d displays, %3$d packs.', 'protech-wholesale' ),
				$protech_colors,
				$composition['displays'],
				$composition['packs']
			)
		);
		?>
		<span class="protech-everycolor-total" data-protech-everycolor-total><?php echo wp_kses_post( $quote['total_html'] ); ?></span>
		<span class="protech-everycolor-note" data-protech-everycolor-note><?php echo esc_html( $quote['note'] ); ?></span>
	</p>

	<form class="protech-everycolor-form" method="post" action="<?php echo esc_url( $post_url ); ?>">
		<input type="hidden" name="action" value="<?php echo esc_attr( \ProtechWholesale\StarterKit::POST_ACTION ); ?>" />
		<input type="hidden" name="kit_id" value="<?php echo esc_attr( (string) $product_id ); ?>" />
		<input type="hidden" name="kits" value="1" />
		<?php wp_nonce_field( \ProtechWholesale\StarterKit::NONCE_ACTION, 'protech_kit_nonce' ); ?>
		<button type="submit" class="button protech-everycolor-add"><?php echo esc_html( empty( $protech_out_names ) ? __( 'Add one display of every color', 'protech-wholesale' ) : __( 'Add one display of each color in stock', 'protech-wholesale' ) ); ?></button>
	</form>

	<p class="protech-everycolor-message" aria-live="polite"></p>
</div>
