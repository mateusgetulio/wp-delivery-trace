<?php

namespace DeliveryTrace\Tests\Unit;

use DeliveryTrace\Privacy\Scrubber;
use PHPUnit\Framework\TestCase;

final class ScrubberTest extends TestCase {

	private const NAME  = 'Maria Silva';
	private const EMAIL = 'maria.tour+b2@gmail.com';
	private const PHONE = '(303) 555-4812';

	private Scrubber $scrubber;

	protected function setUp(): void {
		$this->scrubber = new Scrubber();
	}

	private function scrub( string $text, string $phone = self::PHONE ): string {
		return $this->scrubber->scrub( $text, self::NAME, self::EMAIL, $phone );
	}

	public function test_removes_the_full_name_in_any_case(): void {
		$this->assertSame( '{"error":"[name] already has a tour"}', $this->scrub( '{"error":"MARIA SILVA already has a tour"}' ) );
	}

	public function test_removes_the_email_in_any_case(): void {
		$this->assertSame( '{"error":"[email] already exists"}', $this->scrub( '{"error":"MARIA.TOUR+B2@GMAIL.COM already exists"}' ) );
	}

	public function test_removes_every_occurrence_of_the_email(): void {
		$this->assertSame( '[email] and [email]', $this->scrub( self::EMAIL . ' and ' . self::EMAIL ) );
	}

	/**
	 * @dataProvider encoded_emails
	 */
	public function test_removes_the_url_encoded_email( string $encoded ): void {
		$this->assertSame( 'email=[email]&ok=0', $this->scrub( "email={$encoded}&ok=0" ) );
	}

	public static function encoded_emails(): array {
		return array(
			'uppercase hex' => array( 'maria.tour%2Bb2%40gmail.com' ),
			'lowercase hex' => array( 'maria.tour%2bb2%40gmail.com' ),
		);
	}

	/**
	 * @dataProvider echoed_phones
	 */
	public function test_removes_the_phone_in_any_format( string $echoed ): void {
		$scrubbed = $this->scrub( "invalid phone {$echoed} for lead" );

		$this->assertStringContainsString( '[phone]', $scrubbed );
		$this->assertStringNotContainsString( '4812', $scrubbed );
	}

	public static function echoed_phones(): array {
		return array(
			'digits only'  => array( '3035554812' ),
			'same format'  => array( '(303) 555-4812' ),
			'dashes'       => array( '303-555-4812' ),
			'dots'         => array( '303.555.4812' ),
			'with country' => array( '+13035554812' ),
		);
	}

	public function test_removes_a_national_echo_of_a_number_stored_with_country_code(): void {
		$this->assertSame( 'phone [phone] bad', $this->scrub( 'phone 3035554812 bad', '+1 303 555 4812' ) );
	}

	public function test_leaves_unrelated_numbers_alone(): void {
		$body = 'lead 3035559999 rejected at 14:02';

		$this->assertSame( $body, $this->scrub( $body ) );
	}

	public function test_scrubs_before_truncating(): void {
		$scrubbed = $this->scrub( str_repeat( 'x', 490 ) . self::EMAIL );

		$this->assertSame( str_repeat( 'x', 490 ) . '[email]', $scrubbed );
	}

	public function test_truncates_to_five_hundred_characters(): void {
		$this->assertSame( Scrubber::MAX_LENGTH, mb_strlen( $this->scrub( str_repeat( 'é', 800 ) ) ) );
	}

	public function test_empty_values_change_nothing(): void {
		$this->assertSame( 'plain text 123', $this->scrubber->scrub( 'plain text 123', ' ', '', '' ) );
	}
}
