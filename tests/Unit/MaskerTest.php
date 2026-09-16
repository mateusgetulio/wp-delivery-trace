<?php

namespace DeliveryTrace\Tests\Unit;

use DeliveryTrace\Privacy\Masker;
use PHPUnit\Framework\TestCase;

final class MaskerTest extends TestCase {

	private Masker $masker;

	protected function setUp(): void {
		$this->masker = new Masker();
	}

	/**
	 * @dataProvider names
	 */
	public function test_name_keeps_first_name_and_last_initial( string $name, string $expected ): void {
		$this->assertSame( $expected, $this->masker->name( $name ) );
	}

	public static function names(): array {
		return array(
			'two words'        => array( 'Maria Silva', 'Maria S.' ),
			'three words'      => array( 'Maria Clara Souza', 'Maria S.' ),
			'one word'         => array( 'Maria', 'Maria' ),
			'extra spaces'     => array( "  Maria \t silva ", 'Maria S.' ),
			'accented initial' => array( 'João Ávila', 'João Á.' ),
			'empty'            => array( '   ', '' ),
		);
	}

	/**
	 * @dataProvider emails
	 */
	public function test_email_keeps_first_character_and_domain( string $email, string $expected ): void {
		$this->assertSame( $expected, $this->masker->email( $email ) );
	}

	public static function emails(): array {
		return array(
			'normal'           => array( 'maria.silva@gmail.com', 'm***@gmail.com' ),
			'one character'    => array( 'm@example.com', 'm***@example.com' ),
			'no local part'    => array( '@example.com', '***' ),
			'not an email'     => array( 'maria', '***' ),
			'at sign in local' => array( '"a@b"@example.com', '"***@example.com' ),
		);
	}

	/**
	 * @dataProvider phones
	 */
	public function test_phone_keeps_last_four_digits( string $phone, string $expected ): void {
		$this->assertSame( $expected, $this->masker->phone( $phone ) );
	}

	public static function phones(): array {
		return array(
			'formatted' => array( '(303) 555-4812', '***-***-4812' ),
			'plain'     => array( '3035554812', '***-***-4812' ),
			'with code' => array( '+1 303 555 4812', '***-***-4812' ),
			'too short' => array( '12', '***' ),
			'no digits' => array( 'call me', '***' ),
		);
	}
}
