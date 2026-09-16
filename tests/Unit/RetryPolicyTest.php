<?php

namespace DeliveryTrace\Tests\Unit;

use DeliveryTrace\Delivery\Classification;
use DeliveryTrace\Delivery\Decision;
use DeliveryTrace\Delivery\Outcome;
use DeliveryTrace\Delivery\RetryPolicy;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class RetryPolicyTest extends TestCase {

	private const NOW = 1_800_000_000;

	private RetryPolicy $policy;

	protected function setUp(): void {
		$this->policy = new RetryPolicy();
	}

	/**
	 * @dataProvider delivered
	 */
	public function test_delivered_leads_are_done( string $outcome ): void {
		$decision = $this->policy->decide( new Classification( $outcome, 204 ), 1, self::NOW );

		$this->assertSame( Decision::DONE, $decision->action() );
		$this->assertNull( $decision->retry_at() );
	}

	public static function delivered(): array {
		return array(
			'delivered' => array( Outcome::DELIVERED ),
			'duplicate' => array( Outcome::DELIVERED_DUPLICATE ),
		);
	}

	public function test_schedule_is_one_then_five_then_fifteen_minutes(): void {
		$failure = new Classification( Outcome::RETRYABLE, 500 );

		$this->assertSame( self::NOW + 60, $this->policy->decide( $failure, 1, self::NOW )->retry_at() );
		$this->assertSame( self::NOW + 300, $this->policy->decide( $failure, 2, self::NOW )->retry_at() );
		$this->assertSame( self::NOW + 900, $this->policy->decide( $failure, 3, self::NOW )->retry_at() );
	}

	/**
	 * @dataProvider retried
	 */
	public function test_uncertain_and_transient_failures_are_retried( string $outcome ): void {
		$decision = $this->policy->decide( new Classification( $outcome ), 1, self::NOW );

		$this->assertSame( Decision::RETRY, $decision->action() );
	}

	public static function retried(): array {
		return array(
			'retryable'   => array( Outcome::RETRYABLE ),
			'unknown'     => array( Outcome::UNKNOWN ),
			'unreachable' => array( Outcome::UNREACHABLE ),
		);
	}

	/**
	 * INV-3: permanent outcomes are never retried automatically.
	 *
	 * @dataProvider permanent
	 */
	public function test_permanent_failures_are_never_retried( string $outcome ): void {
		foreach ( array( 1, 2, 3 ) as $attempts ) {
			$decision = $this->policy->decide( new Classification( $outcome, 422, 30 ), $attempts, self::NOW );

			$this->assertSame( Decision::NEEDS_ATTENTION, $decision->action() );
		}
	}

	public static function permanent(): array {
		return array(
			'auth failed'   => array( Outcome::AUTH_FAILED ),
			'rejected'      => array( Outcome::REJECTED ),
			'misconfigured' => array( Outcome::MISCONFIGURED ),
		);
	}

	/**
	 * INV-4: at most 4 automatic attempts per lead.
	 */
	public function test_fourth_failure_needs_attention(): void {
		$failure = new Classification( Outcome::UNKNOWN );

		$this->assertSame( 4, $this->policy->max_automatic_attempts() );
		$this->assertSame( Decision::RETRY, $this->policy->decide( $failure, 3, self::NOW )->action() );
		$this->assertSame( Decision::NEEDS_ATTENTION, $this->policy->decide( $failure, 4, self::NOW )->action() );
		$this->assertSame( Decision::NEEDS_ATTENTION, $this->policy->decide( $failure, 9, self::NOW )->action() );
	}

	public function test_a_longer_retry_after_is_honoured(): void {
		$decision = $this->policy->decide( new Classification( Outcome::RETRYABLE, 503, 240 ), 1, self::NOW );

		$this->assertSame( self::NOW + 240, $decision->retry_at() );
	}

	public function test_a_shorter_retry_after_does_not_shorten_the_schedule(): void {
		$decision = $this->policy->decide( new Classification( Outcome::RETRYABLE, 503, 5 ), 2, self::NOW );

		$this->assertSame( self::NOW + 300, $decision->retry_at() );
	}

	public function test_retry_after_is_capped_at_fifteen_minutes(): void {
		$decision = $this->policy->decide( new Classification( Outcome::RETRYABLE, 429, 86400 ), 1, self::NOW );

		$this->assertSame( self::NOW + 900, $decision->retry_at() );
	}

	public function test_custom_schedule_changes_the_attempt_cap(): void {
		$policy  = new RetryPolicy( array( 10 ) );
		$failure = new Classification( Outcome::RETRYABLE, 500 );

		$this->assertSame( 2, $policy->max_automatic_attempts() );
		$this->assertSame( self::NOW + 10, $policy->decide( $failure, 1, self::NOW )->retry_at() );
		$this->assertSame( Decision::NEEDS_ATTENTION, $policy->decide( $failure, 2, self::NOW )->action() );
	}

	public function test_a_longer_schedule_cannot_raise_the_attempt_cap(): void {
		$policy  = new RetryPolicy( array( 10, 20, 30, 40, 50 ) );
		$failure = new Classification( Outcome::RETRYABLE, 500 );

		$this->assertSame( 4, $policy->max_automatic_attempts() );
		$this->assertSame( Decision::NEEDS_ATTENTION, $policy->decide( $failure, 4, self::NOW )->action() );
	}

	public function test_non_integer_delay_is_refused(): void {
		$this->expectException( InvalidArgumentException::class );

		new RetryPolicy( array( 60, 'abc' ) );
	}

	public function test_unknown_outcome_is_refused(): void {
		$this->expectException( InvalidArgumentException::class );

		new Classification( 'retyable' );
	}

	public function test_a_decision_needs_an_attempt(): void {
		$this->expectException( InvalidArgumentException::class );

		$this->policy->decide( new Classification( Outcome::RETRYABLE, 500 ), 0, self::NOW );
	}

	public function test_empty_schedule_is_refused(): void {
		$this->expectException( InvalidArgumentException::class );

		new RetryPolicy( array() );
	}

	public function test_negative_delay_is_refused(): void {
		$this->expectException( InvalidArgumentException::class );

		new RetryPolicy( array( 60, -1 ) );
	}
}
