<?php
/**
 * Removes a lead's personal data from free text before it is stored.
 *
 * @package DeliveryTrace
 */

namespace DeliveryTrace\Privacy;

/**
 * Scrubs response bodies and error messages that may echo the payload.
 */
final class Scrubber {

	public const MAX_LENGTH = 500;

	private const NATIONAL_DIGITS = 10;

	/**
	 * Replace the lead's name, email and phone, then truncate.
	 *
	 * Scrubbing happens before truncation so a value cut in half at the
	 * limit can never survive.
	 *
	 * @param string $text  Text that may contain the values.
	 * @param string $name  The lead's full name.
	 * @param string $email The lead's email.
	 * @param string $phone The lead's phone, in any format.
	 * @return string
	 */
	public function scrub( string $text, string $name, string $email, string $phone ): string {
		$name = trim( $name );

		if ( '' !== $name ) {
			$text = str_ireplace( $name, '[name]', $text );
		}

		if ( '' !== $email ) {
			$text = str_ireplace( array( $email, rawurlencode( $email ) ), '[email]', $text );
		}

		$digits = preg_replace( '/\D/', '', $phone );

		if ( strlen( $digits ) >= 4 ) {
			$text = $this->replace_digits( $digits, $text );
		}

		// A CRM may echo the number without the country code the visitor typed.
		if ( strlen( $digits ) > self::NATIONAL_DIGITS ) {
			$text = $this->replace_digits( substr( $digits, -self::NATIONAL_DIGITS ), $text );
		}

		return mb_substr( $text, 0, self::MAX_LENGTH );
	}

	/**
	 * Replace a digit sequence, allowing short separators between digits.
	 *
	 * @param string $digits Digits to find.
	 * @param string $text   Text to scrub.
	 * @return string
	 */
	private function replace_digits( string $digits, string $text ): string {
		$pattern = '/' . implode( '\D{0,3}', str_split( $digits ) ) . '/';

		return (string) preg_replace( $pattern, '[phone]', $text );
	}
}
