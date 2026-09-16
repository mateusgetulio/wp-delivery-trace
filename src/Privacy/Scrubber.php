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

	private const MIN_NAME_PART_LENGTH = 3;

	/**
	 * Replace the lead's name and its parts, email and phone, then truncate.
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

		// Name parts can appear inside the email, so the name goes last.
		$name = trim( $name );

		if ( '' !== $name ) {
			$text = str_ireplace( $name, '[name]', $text );
		}

		foreach ( (array) preg_split( '/\s+/u', $name, -1, PREG_SPLIT_NO_EMPTY ) as $part ) {
			if ( mb_strlen( $part ) >= self::MIN_NAME_PART_LENGTH ) {
				$text = (string) preg_replace( '/(?<!\pL)' . preg_quote( $part, '/' ) . '(?!\pL)/iu', '[name]', $text );
			}
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
