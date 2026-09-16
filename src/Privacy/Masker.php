<?php
/**
 * Masks personal data for display.
 *
 * @package DeliveryTrace
 */

namespace DeliveryTrace\Privacy;

/**
 * Shows enough of a name, email or phone to recognise a lead, and no more.
 */
final class Masker {

	/**
	 * First name plus the initial of the last name.
	 *
	 * @param string $name Full name.
	 * @return string
	 */
	public function name( string $name ): string {
		$parts = preg_split( '/\s+/u', trim( $name ), -1, PREG_SPLIT_NO_EMPTY );

		if ( empty( $parts ) ) {
			return '';
		}

		$first = array_shift( $parts );

		if ( array() === $parts ) {
			return $first;
		}

		return $first . ' ' . mb_strtoupper( mb_substr( (string) end( $parts ), 0, 1 ) ) . '.';
	}

	/**
	 * First character of the local part and the full domain.
	 *
	 * @param string $email Email address.
	 * @return string
	 */
	public function email( string $email ): string {
		$at = strrpos( $email, '@' );

		if ( false === $at || 0 === $at ) {
			return '***';
		}

		return mb_substr( $email, 0, 1 ) . '***' . substr( $email, $at );
	}

	/**
	 * Last four digits only.
	 *
	 * @param string $phone Phone number in any format.
	 * @return string
	 */
	public function phone( string $phone ): string {
		$digits = preg_replace( '/\D/', '', $phone );

		if ( strlen( $digits ) < 4 ) {
			return '***';
		}

		return '***-***-' . substr( $digits, -4 );
	}
}
