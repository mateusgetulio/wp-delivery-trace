<?php
/**
 * Turns HTTP responses and transport errors into outcomes.
 *
 * @package DeliveryTrace
 */

namespace DeliveryTrace\Delivery;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Classifies one delivery attempt without touching WordPress.
 */
final class OutcomeClassifier {

	/**
	 * Classify an HTTP response.
	 *
	 * Statuses outside the known groups, such as a 3xx (redirects are not
	 * followed), are treated as permanent so a human looks.
	 *
	 * @param int         $status             HTTP status code.
	 * @param string      $body               Response body.
	 * @param string|null $retry_after_header Raw Retry-After header, if any.
	 * @param int         $now                Current Unix time.
	 * @return Classification
	 */
	public function from_response( int $status, string $body, ?string $retry_after_header, int $now ): Classification {
		$is_success = $status >= 200 && $status < 300;

		if ( 409 === $status || ( $is_success && $this->is_duplicate_body( $body ) ) ) {
			return new Classification( Outcome::DELIVERED_DUPLICATE, $status );
		}

		if ( $is_success ) {
			return new Classification( Outcome::DELIVERED, $status );
		}

		if ( 408 === $status || 429 === $status || $status >= 500 ) {
			return new Classification( Outcome::RETRYABLE, $status, $this->parse_retry_after( $retry_after_header, $now ) );
		}

		if ( 401 === $status || 403 === $status ) {
			return new Classification( Outcome::AUTH_FAILED, $status );
		}

		return new Classification( Outcome::REJECTED, $status );
	}

	/**
	 * Classify a transport error, when no HTTP response arrived.
	 *
	 * A timeout may mean the CRM received the lead and only the answer was
	 * lost, so it is unknown rather than failed. Any cURL 28 counts, even a
	 * connect timeout, because assuming "maybe received" is the safe side.
	 *
	 * @param string $message Transport error message.
	 * @return Classification
	 */
	public function from_error( string $message ): Classification {
		if ( 1 === preg_match( '/^cURL error 28\b|^fsocket timed out/i', $message ) ) {
			return new Classification( Outcome::UNKNOWN );
		}

		return new Classification( Outcome::UNREACHABLE );
	}

	/**
	 * Whether a successful body says the CRM already had this lead.
	 *
	 * @param string $body Response body.
	 * @return bool
	 */
	private function is_duplicate_body( string $body ): bool {
		$decoded = json_decode( $body, true );

		return is_array( $decoded ) && true === ( $decoded['duplicate'] ?? null );
	}

	/**
	 * Parse Retry-After as delay seconds or an RFC 7231 HTTP date.
	 *
	 * @param string|null $header Raw header value.
	 * @param int         $now    Current Unix time.
	 * @return int|null
	 */
	private function parse_retry_after( ?string $header, int $now ): ?int {
		$header = trim( (string) $header );

		if ( '' === $header ) {
			return null;
		}

		if ( ctype_digit( $header ) ) {
			return (int) $header;
		}

		$date = DateTimeImmutable::createFromFormat( '!D, d M Y H:i:s \G\M\T', $header, new DateTimeZone( 'UTC' ) );

		if ( false === $date || $date->format( 'D, d M Y H:i:s \G\M\T' ) !== $header ) {
			return null;
		}

		return max( 0, $date->getTimestamp() - $now );
	}
}
