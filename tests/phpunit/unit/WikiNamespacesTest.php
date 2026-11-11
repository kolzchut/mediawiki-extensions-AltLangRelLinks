<?php

namespace MediaWiki\Extension\AltLangRelLinks\Tests\Unit;

use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\AltLangRelLinks\WikiNamespaces
 */
class WikiNamespacesTest extends MediaWikiUnitTestCase {
	use HubTestTrait;

	public function testProjectNamespaceUsesTheConfiguredName() {
		$ns = self::newHebrewNamespaces();
		$this->assertSame( 'כל-זכות', $ns->getName( NS_PROJECT ) );
		$this->assertSame( NS_PROJECT, $ns->getIndex( 'כל-זכות' ) );
		$this->assertSame( NS_PROJECT, $ns->getIndex( 'כל זכות' ) );
		$this->assertSame( NS_PROJECT, $ns->getIndex( 'Project' ) );
		$this->assertSame( NS_PROJECT, $ns->getIndex( 'PROJECT' ) );
	}

	public function testProjectTalkGetsTheConfiguredName() {
		$ns = self::newHebrewNamespaces();
		$this->assertSame( 'שיחת_כל-זכות', $ns->getName( NS_PROJECT_TALK ) );
		$this->assertSame( NS_PROJECT_TALK, $ns->getIndex( 'שיחת כל-זכות' ) );
	}

	public function testWithoutConfigurationProjectIsCanonical() {
		$ns = self::newHebrewNamespaces( [] );
		$this->assertSame( 'Project', $ns->getName( NS_PROJECT ) );
		$this->assertNull( $ns->getIndex( 'כל-זכות' ) );
		$this->assertNull( $ns->getName( NS_PROJECT_TALK ) );
		$this->assertNull( $ns->getIndex( 'שיחת_$1' ) );
	}

	public function testLocalisedNamesWinOverCanonical() {
		$ns = self::newHebrewNamespaces();
		$this->assertSame( 'קטגוריה', $ns->getName( NS_CATEGORY ) );
		$this->assertSame( NS_CATEGORY, $ns->getIndex( 'Category' ) );
	}

	public function testConfiguredExtraNamespace() {
		$ns = self::newHebrewNamespaces( [ NS_PROJECT => 'כל-זכות', 126 => 'כל_שירות' ] );
		$this->assertSame( 'כל_שירות', $ns->getName( 126 ) );
		$this->assertSame( 126, $ns->getIndex( 'כל שירות' ) );
	}

	public function testMainNamespace() {
		$ns = self::newHebrewNamespaces();
		$this->assertSame( '', $ns->getName( NS_MAIN ) );
		$this->assertNull( $ns->getIndex( '' ) );
		$this->assertNull( $ns->getIndex( 'not a namespace' ) );
		$this->assertNull( $ns->getName( 9999 ) );
	}

	public static function provideNormaliseTitle() {
		return [
			'canonical prefix' => [ 'Project:אודות', 'כל-זכות:אודות' ],
			'alias with a space' => [ 'כל זכות:אודות', 'כל-זכות:אודות' ],
			'already the wiki name' => [ 'כל-זכות:אודות', 'כל-זכות:אודות' ],
			'localised over canonical' => [ 'Category:X', 'קטגוריה:X' ],
			'main namespace with a colon' => [ 'הסכם: נוסח', 'הסכם: נוסח' ],
			'no colon' => [ 'דמי אבטלה', 'דמי אבטלה' ],
		];
	}

	/**
	 * @dataProvider provideNormaliseTitle
	 */
	public function testNormaliseTitle( string $text, string $expected ) {
		$this->assertSame( $expected, self::newHebrewNamespaces()->normaliseTitle( $text ) );
	}
}
