<?php
/**
 * Sanitizes and validates the tour form.
 *
 * @package DeliveryTrace
 */

namespace DeliveryTrace\Form;

use DateTimeImmutable;

/**
 * The tour form's fields: sanitize first, then validate.
 */
final class Submission {

	public const MAX_NAME_LENGTH = 100;

	public const MIN_PHONE_DIGITS = 10;

	public const MAX_PHONE_DIGITS = 15;

	public const MAX_DAYS_AHEAD = 60;

	/**
	 * Sanitize raw, already unslashed input into the stored field values.
	 *
	 * @param array $input Raw form input.
	 * @return array<string, string>
	 */
	public static function sanitize( array $input ): array {
		return array(
			'name'           => sanitize_text_field( self::scalar( $input, 'name' ) ),
			'email'          => sanitize_email( self::scalar( $input, 'email' ) ),
			'phone'          => sanitize_text_field( self::scalar( $input, 'phone' ) ),
			'floor_plan'     => sanitize_key( self::scalar( $input, 'floor_plan' ) ),
			'preferred_date' => sanitize_text_field( self::scalar( $input, 'preferred_date' ) ),
		);
	}

	/**
	 * Validate sanitized values.
	 *
	 * @param array<string, string> $values Sanitized values.
	 * @param DateTimeImmutable     $today  Midnight today in the site timezone.
	 * @return array<string, string> Error messages keyed by field; empty when valid.
	 */
	public static function validate( array $values, DateTimeImmutable $today ): array {
		$errors      = array();
		$name_length = mb_strlen( $values['name'] );

		if ( 0 === $name_length || $name_length > self::MAX_NAME_LENGTH ) {
			/* translators: %d: maximum number of characters. */
			$errors['name'] = sprintf( __( 'Please enter your name, up to %d characters.', 'delivery-trace' ), self::MAX_NAME_LENGTH );
		}

		if ( ! is_email( $values['email'] ) ) {
			$errors['email'] = __( 'Please enter a valid email address.', 'delivery-trace' );
		}

		$digits = strlen( (string) preg_replace( '/\D/', '', $values['phone'] ) );

		if ( $digits < self::MIN_PHONE_DIGITS || $digits > self::MAX_PHONE_DIGITS ) {
			/* translators: 1: minimum number of digits, 2: maximum number of digits. */
			$errors['phone'] = sprintf( __( 'Please enter a phone number with %1$d to %2$d digits.', 'delivery-trace' ), self::MIN_PHONE_DIGITS, self::MAX_PHONE_DIGITS );
		}

		if ( ! array_key_exists( $values['floor_plan'], FloorPlans::all() ) ) {
			$errors['floor_plan'] = __( 'Please choose a floor plan.', 'delivery-trace' );
		}

		$date = DateTimeImmutable::createFromFormat( '!Y-m-d', $values['preferred_date'], $today->getTimezone() );

		if (
			false === $date
			|| $date->format( 'Y-m-d' ) !== $values['preferred_date']
			|| $date < $today
			|| $date > $today->modify( '+' . self::MAX_DAYS_AHEAD . ' days' )
		) {
			/* translators: %d: number of days ahead. */
			$errors['preferred_date'] = sprintf( __( 'Please choose a date within the next %d days.', 'delivery-trace' ), self::MAX_DAYS_AHEAD );
		}

		return $errors;
	}

	/**
	 * Midnight today in the site timezone.
	 *
	 * @return DateTimeImmutable
	 */
	public static function today(): DateTimeImmutable {
		return new DateTimeImmutable( 'today', wp_timezone() );
	}

	/**
	 * A scalar field as a string, ignoring arrays sent in its place.
	 *
	 * @param array  $input Raw input.
	 * @param string $key   Field name.
	 * @return string
	 */
	private static function scalar( array $input, string $key ): string {
		return isset( $input[ $key ] ) && is_scalar( $input[ $key ] ) ? (string) $input[ $key ] : '';
	}
}
