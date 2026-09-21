<?php
/**
 * The starter kit panel on a kit product's page, in place of the usual
 * add-to-cart form — see class-starter-kit.php. Lists what the kit
 * contains for this customer right now, what it will cost once it is in
 * their cart, and adds it.
 *
 * The form posts to admin-post.php so it works without JavaScript;
 * assets/js/starter-kit.js takes it over to add in place instead.
 *
 * Override by copying to yourtheme/woocommerce/starter-kit.php.
 *
 * @package ProtechWholesale
 *
 * @var int $kit_id
 * @var array{lines: array<int, array{variation_id: int, name: string, color: string, displays: int, packs: int}>, unavailable: string[], displays: int, packs: int, target: int} $composition
 * @var array{total_html: string, summary: string, note: string, displays: int, cases: float} $quote
 * @var string $post_url
 * @var int $max_kits
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( empty( $composition['lines'] ) ) :
	?>
	<div class="protech-kit protech-kit--empty">
		<p class="protech-kit-note"><?php esc_html_e( 'Nothing in this starter kit is available right now. Please check back soon.', 'protech-wholesale' ); ?></p>
	</div>
	<?php
	return;
endif;
?>
<div
	class="protech-kit"
	data-kit-id="<?php echo esc_attr( (string) $kit_id ); ?>"
	data-displays="<?php echo esc_attr( (string) $composition['displays'] ); ?>"
	data-cases="<?php echo esc_attr( (string) $quote['cases'] ); ?>"
>
	<p class="protech-kit-legend"><?php esc_html_e( "What's in the kit", 'protech-wholesale' ); ?></p>

	<ul class="protech-kit-lines">
		<?php foreach ( $composition['lines'] as $protech_line ) : ?>
			<li class="protech-kit-line">
				<span class="protech-kit-swatch"<?php echo '' !== $protech_line['color'] ? ' style="background-color:' . esc_attr( $protech_line['color'] ) . '"' : ''; ?> aria-hidden="true"></span>
				<span class="protech-kit-line-name"><?php echo esc_html( $protech_line['name'] ); ?></span>
				<?php if ( $protech_line['displays'] > 1 ) : ?>
					<span class="protech-kit-line-count">
						<?php
						/* translators: %d: number of displays of this color in the kit. */
						echo esc_html( sprintf( __( '×%d', 'protech-wholesale' ), $protech_line['displays'] ) );
						?>
					</span>
				<?php endif; ?>
			</li>
		<?php endforeach; ?>
	</ul>

	<?php if ( ! empty( $composition['unavailable'] ) ) : ?>
		<p class="protech-kit-note">
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

	<div class="protech-kit-totals">
		<div class="protech-kit-total">
			<span class="protech-kit-total-label"><?php esc_html_e( 'Kit total', 'protech-wholesale' ); ?></span>
			<span class="protech-kit-total-price" id="protech-kit-total" data-protech-kit-total><?php echo wp_kses_post( $quote['total_html'] ); ?></span>
		</div>
		<p class="protech-kit-summary">
			<span id="protech-kit-summary"><?php echo esc_html( $quote['summary'] ); ?></span>
			<span class="protech-kit-tier-note" id="protech-kit-tier-note"><?php echo wp_kses_post( $quote['note'] ); ?></span>
		</p>
	</div>

	<form class="protech-kit-form" method="post" action="<?php echo esc_url( $post_url ); ?>">
		<input type="hidden" name="action" value="<?php echo esc_attr( \ProtechWholesale\StarterKit::POST_ACTION ); ?>" />
		<input type="hidden" name="kit_id" value="<?php echo esc_attr( (string) $kit_id ); ?>" />
		<?php wp_nonce_field( \ProtechWholesale\StarterKit::NONCE_ACTION, 'protech_kit_nonce' ); ?>

		<div class="protech-kit-qty-row">
			<label class="protech-kit-legend" for="protech-kit-qty"><?php esc_html_e( 'How many', 'protech-wholesale' ); ?></label>
			<div class="protech-kit-qty-controls">
				<div class="protech-stepper">
					<button type="button" class="protech-stepper-btn" data-protech-step="-1" aria-label="<?php esc_attr_e( 'Fewer kits', 'protech-wholesale' ); ?>">&minus;</button>
					<input type="number" id="protech-kit-qty" name="kits" value="1" min="1" max="<?php echo esc_attr( (string) $max_kits ); ?>" step="1" inputmode="numeric" autocomplete="off" />
					<button type="button" class="protech-stepper-btn" data-protech-step="1" aria-label="<?php esc_attr_e( 'More kits', 'protech-wholesale' ); ?>">+</button>
				</div>
				<span class="protech-unit-word" id="protech-kit-word"><?php esc_html_e( 'kit', 'protech-wholesale' ); ?></span>
			</div>
		</div>

		<button type="submit" class="button alt protech-kit-add" id="protech-kit-add"><?php esc_html_e( 'Add starter kit to cart', 'protech-wholesale' ); ?></button>
	</form>

	<p class="protech-kit-message" id="protech-kit-message" aria-live="polite"></p>
</div>
