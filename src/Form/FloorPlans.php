<?php
/**
 * The floor plans a visitor can choose.
 *
 * @package DeliveryTrace
 */

namespace DeliveryTrace\Form;

/**
 * Fixed demo floor plans, keyed by the value the form submits.
 */
final class FloorPlans {

	/**
	 * Floor plan labels keyed by their submitted value.
	 *
	 * @return array<string, string>
	 */
	public static function all(): array {
		return array(
			'a1' => __( 'A1 Studio', 'delivery-trace' ),
			'b2' => __( 'B2 One Bedroom', 'delivery-trace' ),
			'c3' => __( 'C3 Two Bedroom', 'delivery-trace' ),
		);
	}
}
