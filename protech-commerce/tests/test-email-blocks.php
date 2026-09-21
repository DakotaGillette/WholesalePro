<?php
/**
 * The drawing helpers shared by the welcome email and the email composer.
 *
 * @package ProtechWholesale
 */

use ProtechWholesale\EmailBlocks;

/**
 * Class Test_Email_Blocks
 */
class Test_Email_Blocks extends WP_UnitTestCase {

	public function test_a_swatch_grid_draws_one_cell_per_item_and_wraps_rows(): void {
		$html = EmailBlocks::swatch_grid( 10, 5, 8, 11, array( '#42649d' ), '#42649d' );

		$this->assertSame( 10, substr_count( $html, '<td ' ) );
		$this->assertSame( 2, substr_count( $html, '<tr>' ), 'Ten cells in rows of five is two rows.' );
		$this->assertStringContainsString( 'role="presentation"', $html );
	}

	public function test_a_swatch_grid_cycles_its_colors_and_defaults_when_given_none(): void {
		$html = EmailBlocks::swatch_grid( 3, 3, 10, 10, array( '#111111', '#222222' ), '#000000' );

		$this->assertSame( 2, substr_count( $html, 'background:#111111' ), 'The first color comes round again for the third cell.' );
		$this->assertSame( 1, substr_count( $html, 'background:#222222' ) );

		$this->assertStringContainsString( 'background:#42649d', EmailBlocks::swatch_grid( 1, 1, 10, 10, array(), '#000000' ) );
	}

	public function test_a_swatch_grid_survives_a_zero_column_count(): void {
		$this->assertSame( 4, substr_count( EmailBlocks::swatch_grid( 4, 0, 10, 10, array( '#111111' ), '#000000' ), '<td ' ) );
	}

	public function test_the_output_is_escaped(): void {
		$html = EmailBlocks::swatch_grid( 1, 1, 10, 10, array( '"><script>' ), '"><script>' );

		$this->assertStringNotContainsString( '<script>', $html );
	}

	public function test_a_step_number_carries_its_number_and_color(): void {
		$html = EmailBlocks::step_number( '2', '#42649d' );

		$this->assertStringContainsString( '>2</span>', $html );
		$this->assertStringContainsString( 'background:#42649d', $html );
	}
}
