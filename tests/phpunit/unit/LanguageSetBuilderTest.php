<?php

namespace MediaWiki\Extension\AltLangRelLinks\Tests\Unit;

use MediaWiki\Extension\AltLangRelLinks\LanguageSetBuilder;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\AltLangRelLinks\LanguageSetBuilder
 */
class LanguageSetBuilderTest extends MediaWikiUnitTestCase {

	private function newMainBuilder( bool $xDefault = true ): LanguageSetBuilder {
		return new LanguageSetBuilder( [ 'he', 'ar', 'ru' ], 'he', $xDefault );
	}

	public function testParseLanguageLinks() {
		$this->assertSame(
			[ 'ar' => 'مخصصات البطالة', 'en' => 'Unemployment', 'ru' => 'Пособие: часть 2' ],
			LanguageSetBuilder::parseLanguageLinks( [
				'ar:مخصصات البطالة',
				'en:Unemployment',
				'ar:Second link for the same language',
				'ru:Пособие: часть 2',
				'no-colon',
				':',
			] )
		);
	}

	public static function provideHubTitle() {
		return [
			'page on the hub wiki is its own hub' => [ 'he', [ 'ar' => 'A' ], null ],
			'other wiki links to the hub' => [ 'ar', [ 'he' => 'H', 'ru' => 'R' ], 'H' ],
			'other wiki without a hub link' => [ 'ru', [ 'ar' => 'A' ], null ],
		];
	}

	/**
	 * @dataProvider provideHubTitle
	 */
	public function testGetHubTitle( string $current, array $ownLinks, ?string $expected ) {
		$this->assertSame( $expected, $this->newMainBuilder()->getHubTitle( $current, $ownLinks ) );
	}

	public function testGetHubTitleWithoutHub() {
		$builder = new LanguageSetBuilder( [ 'he', 'ar' ], null, true );
		$this->assertNull( $builder->getHubTitle( 'ar', [ 'he' => 'H' ] ) );
	}

	public function testHubPageUsesOwnLinksAndDropsEnglish() {
		$set = $this->newMainBuilder()->build(
			'he',
			'דמי אבטלה',
			[ 'ar' => 'A', 'en' => 'Unemployment', 'ru' => 'R', 'am' => 'Amharic' ],
			null
		);
		$this->assertSame( [ 'he' => 'דמי אבטלה', 'ar' => 'A', 'ru' => 'R' ], $set['links'] );
		$this->assertSame( 'he', $set['xDefault'] );
	}

	public function testHubPageWithOnlyEnglishLinkGetsNothing() {
		$set = $this->newMainBuilder()->build( 'he', 'H', [ 'en' => 'E' ], null );
		$this->assertSame( [], $set['links'] );
		$this->assertNull( $set['xDefault'] );
	}

	public function testHebrewOnlyPageGetsNothing() {
		$set = $this->newMainBuilder()->build( 'he', 'H', [], null );
		$this->assertSame( [ 'links' => [], 'xDefault' => null ], $set );
	}

	public function testArabicPageGetsRussianThroughTheHub() {
		$set = $this->newMainBuilder()->build(
			'ar',
			'A',
			[ 'he' => 'H', 'en' => 'E' ],
			[ 'title' => 'H', 'links' => [ 'ar' => 'A', 'en' => 'E', 'ru' => 'R' ] ]
		);
		$this->assertSame( [ 'he' => 'H', 'ar' => 'A', 'ru' => 'R' ], $set['links'] );
		$this->assertSame( 'he', $set['xDefault'] );
	}

	public function testRussianPageGetsArabicThroughTheHub() {
		$set = $this->newMainBuilder()->build(
			'ru',
			'R',
			[ 'he' => 'H' ],
			[ 'title' => 'H', 'links' => [ 'ar' => 'A', 'ru' => 'R' ] ]
		);
		$this->assertSame( [ 'he' => 'H', 'ar' => 'A', 'ru' => 'R' ], $set['links'] );
	}

	public function testHubFoundThroughRedirectUsesItsTarget() {
		$set = $this->newMainBuilder()->build(
			'ar',
			'A',
			[ 'he' => 'Old spelling' ],
			[ 'title' => 'New spelling', 'links' => [ 'ar' => 'A' ] ]
		);
		$this->assertSame( [ 'he' => 'New spelling', 'ar' => 'A' ], $set['links'] );
	}

	public function testCurrentPageWinsOverTheHubsLinkToItsLanguage() {
		$set = $this->newMainBuilder()->build(
			'ar',
			'A',
			[ 'he' => 'H' ],
			[ 'title' => 'H', 'links' => [ 'ar' => 'Some other page', 'ru' => 'R' ] ]
		);
		$this->assertSame( 'A', $set['links']['ar'] );
	}

	public function testSelfLinkToOwnLanguageIsIgnored() {
		$set = $this->newMainBuilder()->build( 'he', 'H', [ 'he' => 'Elsewhere', 'ar' => 'A' ], null );
		$this->assertSame( [ 'he' => 'H', 'ar' => 'A' ], $set['links'] );
	}

	public function testMissingHubPageDropsTheDeadHubLink() {
		$set = $this->newMainBuilder()->build( 'ar', 'A', [ 'he' => 'Gone', 'ru' => 'R' ], [] );
		$this->assertSame( [ 'ar' => 'A', 'ru' => 'R' ], $set['links'] );
		$this->assertNull( $set['xDefault'] );
	}

	public function testMissingHubPageAndNoOtherLinkGetsNothing() {
		$set = $this->newMainBuilder()->build( 'ar', 'A', [ 'he' => 'Gone', 'en' => 'E' ], [] );
		$this->assertSame( [], $set['links'] );
	}

	public function testNoHubLinkUsesOwnLinks() {
		$set = $this->newMainBuilder()->build( 'ru', 'R', [ 'ar' => 'A', 'en' => 'E' ], null );
		$this->assertSame( [ 'ar' => 'A', 'ru' => 'R' ], $set['links'] );
		$this->assertNull( $set['xDefault'] );
	}

	public function testFailedLookupFallsBackToOwnLinks() {
		$set = $this->newMainBuilder()->build( 'ar', 'A', [ 'he' => 'H' ], null );
		$this->assertSame( [ 'he' => 'H', 'ar' => 'A' ], $set['links'] );
		$this->assertSame( 'he', $set['xDefault'] );
	}

	public function testXDefaultCanBeTurnedOff() {
		$set = $this->newMainBuilder( false )->build( 'he', 'H', [ 'ar' => 'A' ], null );
		$this->assertNull( $set['xDefault'] );
	}

	public function testTeenHasNoRussian() {
		$builder = new LanguageSetBuilder( [ 'he', 'ar' ], 'he', true );
		$set = $builder->build(
			'ar',
			'A',
			[ 'he' => 'H' ],
			[ 'title' => 'H', 'links' => [ 'ar' => 'A', 'ru' => 'R', 'en' => 'E' ] ]
		);
		$this->assertSame( [ 'he' => 'H', 'ar' => 'A' ], $set['links'] );
	}

	public function testEmptyLanguageListKeepsTheLegacyBehaviour() {
		$builder = new LanguageSetBuilder( [], null, true );
		$set = $builder->build( 'he', 'H', [ 'ar' => 'A', 'en' => 'E' ], null );
		$this->assertSame( [ 'ar' => 'A', 'en' => 'E', 'he' => 'H' ], $set['links'] );
		$this->assertNull( $set['xDefault'] );
	}
}
