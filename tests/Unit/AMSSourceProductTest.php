<?php

declare( strict_types=1 );

use PHPUnit\Framework\TestCase;

if ( ! class_exists( 'AMS_Test_Product_Double' ) ) {
	class AMS_Test_Product_Double {
		private string $type;
		private array $children;

		public function __construct( string $type, array $children = [] ) {
			$this->type = $type;
			$this->children = $children;
		}

		public function is_type( string $type ): bool {
			return $this->type === $type;
		}

		public function get_children(): array {
			return $this->children;
		}
	}
}

if ( ! function_exists( 'wc_get_products' ) ) {
	function wc_get_products( array $args = [] ): array {
		$products = $GLOBALS['ams_test_wc_products'] ?? [];

		if ( isset( $args['include'] ) && is_array( $args['include'] ) ) {
			return array_values( array_map( 'intval', $args['include'] ) );
		}

		return is_array( $products ) ? $products : [];
	}
}

if ( ! function_exists( 'wc_get_product' ) ) {
	function wc_get_product( int $id ) {
		$map = $GLOBALS['ams_test_wc_product_map'] ?? [];
		return $map[ $id ] ?? null;
	}
}

require_once __DIR__ . '/../../includes/source/class-ams-source-interface.php';
require_once __DIR__ . '/../../includes/source/implements/class-ams-source-product.php';

final class AMSSourceProductTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['ams_test_wc_products'] = [];
		$GLOBALS['ams_test_wc_product_map'] = [];
	}

	public function test_get_item_ids_expands_variable_products_to_unique_variations(): void {
		$GLOBALS['ams_test_wc_products'] = [ 10, 20, 10, 30 ];
		$GLOBALS['ams_test_wc_product_map'] = [
			10 => new AMS_Test_Product_Double( 'variable', [ 11, 12 ] ),
			20 => new AMS_Test_Product_Double( 'variable', [ 12, 13 ] ),
			30 => new AMS_Test_Product_Double( 'simple' ),
		];

		$source = new AMS_Source_Product();
		$item_ids = $source->get_item_ids();

		$this->assertSame( [ 11, 12, 13, 30 ], $item_ids );
	}
}
