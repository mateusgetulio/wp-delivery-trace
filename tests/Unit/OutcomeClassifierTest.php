<?php

namespace DeliveryTrace\Tests\Unit;

use DeliveryTrace\Delivery\Outcome;
use DeliveryTrace\Delivery\OutcomeClassifier;
use PHPUnit\Framework\TestCase;

final class OutcomeClassifierTest extends TestCase {

	private const NOW = 1_800_000_000;

	private OutcomeClassifier $classifier;

	protected function setUp(): void {
		$this->classifier = new OutcomeClassifier();
	}

	/**
	 * @dataProvider responses
	 */
	public function test_classifies_http_responses( int $status, string $body, string $expected ): void {
		$classification = $this->classifier->from_response( $status, $body, null, self::NOW );

		$this->assertSame( $expected, $classification->outcome() );
		$this->assertSame( $status, $classification->http_status() );
	}

	public static function responses(): array {
		return array(
			'204 no content'            => array( 204, '', Outcome::DELIVERED ),
			'201 created'               => array( 201, '{"id":42}', Outcome::DELIVERED ),
			'202 accepted'              => array( 202, '', Outcome::DELIVERED ),
			'200 with json'             => array( 200, '{"id":42}', Outcome::DELIVERED ),
			'200 duplicate'             => array( 200, '{"duplicate":true}', Outcome::DELIVERED_DUPLICATE ),
			'200 duplicate false'       => array( 200, '{"duplicate":false}', Outcome::DELIVERED ),
			'200 duplicate as a string' => array( 200, '{"duplicate":"true"}', Outcome::DELIVERED ),
			'200 not json'              => array( 200, 'duplicate', Outcome::DELIVERED ),
			'409 conflict'              => array( 409, '', Outcome::DELIVERED_DUPLICATE ),
			'408 request timeout'       => array( 408, '', Outcome::RETRYABLE ),
			'429 too many requests'     => array( 429, '', Outcome::RETRYABLE ),
			'500 server error'          => array( 500, '', Outcome::RETRYABLE ),
			'503 unavailable'           => array( 503, '', Outcome::RETRYABLE ),
			'401 unauthorized'          => array( 401, '', Outcome::AUTH_FAILED ),
			'403 forbidden'             => array( 403, '', Outcome::AUTH_FAILED ),
			'400 bad request'           => array( 400, '', Outcome::REJECTED ),
			'422 unprocessable'         => array( 422, '{"error":"phone"}', Outcome::REJECTED ),
			'302 left after redirects'  => array( 302, '', Outcome::REJECTED ),
		);
	}

	public function test_retry_after_in_seconds(): void {
		$classification = $this->classifier->from_response( 503, '', '120', self::NOW );

		$this->assertSame( 120, $classification->retry_after() );
	}

	public function test_retry_after_as_http_date(): void {
		$date           = gmdate( 'D, d M Y H:i:s', self::NOW + 90 ) . ' GMT';
		$classification = $this->classifier->from_response( 429, '', $date, self::NOW );

		$this->assertSame( 90, $classification->retry_after() );
	}

	public function test_retry_after_in_the_past_is_zero(): void {
		$date           = gmdate( 'D, d M Y H:i:s', self::NOW - 90 ) . ' GMT';
		$classification = $this->classifier->from_response( 503, '', $date, self::NOW );

		$this->assertSame( 0, $classification->retry_after() );
	}

	public function test_missing_or_invalid_retry_after_is_null(): void {
		$this->assertNull( $this->classifier->from_response( 503, '', null, self::NOW )->retry_after() );
		$this->assertNull( $this->classifier->from_response( 503, '', '  ', self::NOW )->retry_after() );
		$this->assertNull( $this->classifier->from_response( 503, '', 'soon', self::NOW )->retry_after() );
	}

	/**
	 * @dataProvider loose_dates
	 */
	public function test_only_seconds_or_strict_http_dates_are_accepted( string $header ): void {
		$this->assertNull( $this->classifier->from_response( 503, '', $header, self::NOW )->retry_after() );
	}

	public static function loose_dates(): array {
		return array(
			'timezone letter'  => array( 'a' ),
			'relative'         => array( '+1 hour' ),
			'weekday only'     => array( 'Mon' ),
			'date without GMT' => array( 'Fri, 15 Jan 2027 08:00:00' ),
			'negative seconds' => array( '-30' ),
			'impossible date'  => array( 'Fri, 31 Feb 2027 08:00:00 GMT' ),
		);
	}

	public function test_retry_after_edges(): void {
		$this->assertSame( 0, $this->classifier->from_response( 503, '', '0', self::NOW )->retry_after() );
		$this->assertSame( 900, $this->classifier->from_response( 503, '', '900', self::NOW )->retry_after() );
		$this->assertSame( 30, $this->classifier->from_response( 408, '', '30', self::NOW )->retry_after() );
		$this->assertNull( $this->classifier->from_response( 409, '', '30', self::NOW )->retry_after() );
	}

	public function test_retry_after_is_ignored_on_permanent_failures(): void {
		$this->assertNull( $this->classifier->from_response( 422, '', '120', self::NOW )->retry_after() );
	}

	/**
	 * @dataProvider timeouts
	 */
	public function test_timeouts_are_unknown_because_the_crm_may_have_the_lead( string $message ): void {
		$classification = $this->classifier->from_error( $message );

		$this->assertSame( Outcome::UNKNOWN, $classification->outcome() );
		$this->assertNull( $classification->http_status() );
	}

	public static function timeouts(): array {
		return array(
			'curl'      => array( 'cURL error 28: Operation timed out after 3001 milliseconds with 0 bytes received' ),
			'fsockopen' => array( 'fsocket timed out' ),
		);
	}

	/**
	 * @dataProvider unreachable
	 */
	public function test_other_transport_errors_are_unreachable( string $message ): void {
		$this->assertSame( Outcome::UNREACHABLE, $this->classifier->from_error( $message )->outcome() );
	}

	public static function unreachable(): array {
		return array(
			'connection refused' => array( 'cURL error 7: Failed to connect to crm.example.com port 443: Connection refused' ),
			'dns'                => array( 'cURL error 6: Could not resolve host: crm.example.com' ),
			'error 280'          => array( 'cURL error 280: made up' ),
			'connect timed out'  => array( 'cURL error 7: Failed to connect to crm.example.com port 443: Connection timed out' ),
		);
	}
}
