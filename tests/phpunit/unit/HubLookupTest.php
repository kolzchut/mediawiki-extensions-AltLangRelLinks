<?php

namespace MediaWiki\Extension\AltLangRelLinks\Tests\Unit;

use MediaWiki\Extension\AltLangRelLinks\HubLookup;
use MediaWikiUnitTestCase;
use Wikimedia\ObjectCache\HashBagOStuff;
use Wikimedia\ObjectCache\WANObjectCache;
use Wikimedia\Rdbms\IConnectionProvider;
use Wikimedia\Rdbms\IReadableDatabase;

/**
 * @covers \MediaWiki\Extension\AltLangRelLinks\HubLookup
 */
class HubLookupTest extends MediaWikiUnitTestCase {
	use HubTestTrait;

	public static function provideSplitTitle() {
		return [
			'main namespace' => [ 'דמי אבטלה', NS_MAIN, 'דמי_אבטלה' ],
			'localised namespace name' => [ 'קטגוריה:זכויות', NS_CATEGORY, 'זכויות' ],
			'canonical namespace name' => [ 'Category:Rights', NS_CATEGORY, 'Rights' ],
			'configured project name' => [ 'כל-זכות:שאלות ותשובות', NS_PROJECT, 'שאלות_ותשובות' ],
			'localised project alias' => [ 'כל זכות:אודות המיזם', NS_PROJECT, 'אודות_המיזם' ],
			'canonical project name' => [ 'Project:תרומה לכל זכות', NS_PROJECT, 'תרומה_לכל_זכות' ],
			'lower-case canonical name' => [ 'project:X', NS_PROJECT, 'X' ],
			'local wiki project name is not the hub one' => [ 'كل الحق:X', NS_MAIN, 'كل_الحق:X' ],
			'colon that is not a namespace' => [ 'הסכם: נוסח', NS_MAIN, 'הסכם:_נוסח' ],
			'surrounding spaces' => [ ' דמי אבטלה ', NS_MAIN, 'דמי_אבטלה' ],
		];
	}

	/**
	 * @dataProvider provideSplitTitle
	 */
	public function testSplitTitle( string $text, int $ns, string $dbKey ) {
		$this->assertSame(
			[ $ns, $dbKey ],
			HubLookup::splitTitle( $text, self::newHebrewNamespaces(), $this->newCaselessLanguage() )
		);
	}

	private function newLookup(
		?IReadableDatabase $db = null,
		array $configuredNames = [ NS_PROJECT => 'כל-זכות' ]
	): HubLookup {
		$provider = $this->createMock( IConnectionProvider::class );
		if ( $db ) {
			$provider->method( 'getReplicaDatabase' )->with( 'kz_he' )->willReturn( $db );
		}
		return new HubLookup(
			$provider,
			new WANObjectCache( [ 'cache' => new HashBagOStuff() ] ),
			$this->newCaselessLanguage(),
			self::newHebrewNamespaces( $configuredNames ),
			'kz_he',
			'site'
		);
	}

	public function testFetchReadsTheHubsLanguageLinks() {
		$db = $this->newHubDb(
			[ '0:דמי_אבטלה' => self::pageRow( 1, NS_MAIN, 'דמי_אבטלה' ) ],
			[],
			[ 1 => [ 'ar' => 'مخصصات البطالة', 'en' => 'Unemployment', 'ru' => 'Пособие' ] ]
		);
		$this->assertSame(
			[
				'title' => 'דמי אבטלה',
				'links' => [ 'ar' => 'مخصصات البطالة', 'en' => 'Unemployment', 'ru' => 'Пособие' ],
			],
			$this->newLookup()->fetch( $db, NS_MAIN, 'דמי_אבטלה' )
		);
	}

	public function testFetchFollowsOneRedirect() {
		$db = $this->newHubDb(
			[
				'0:הריון' => self::pageRow( 1, NS_MAIN, 'הריון', true ),
				'0:היריון' => self::pageRow( 2, NS_MAIN, 'היריון' ),
			],
			[ 1 => [ 'rd_namespace' => NS_MAIN, 'rd_title' => 'היריון', 'rd_interwiki' => '' ] ],
			[ 2 => [ 'ru' => 'Беременность' ] ]
		);
		$this->assertSame(
			[ 'title' => 'היריון', 'links' => [ 'ru' => 'Беременность' ] ],
			$this->newLookup()->fetch( $db, NS_MAIN, 'הריון' )
		);
	}

	public function testFetchRefusesDoubleAndInterwikiRedirects() {
		$db = $this->newHubDb(
			[
				'0:A' => self::pageRow( 1, NS_MAIN, 'A', true ),
				'0:B' => self::pageRow( 2, NS_MAIN, 'B', true ),
				'0:C' => self::pageRow( 3, NS_MAIN, 'C', true ),
			],
			[
				1 => [ 'rd_namespace' => NS_MAIN, 'rd_title' => 'B', 'rd_interwiki' => '' ],
				3 => [ 'rd_namespace' => NS_MAIN, 'rd_title' => 'X', 'rd_interwiki' => 'ar' ],
			],
			[]
		);
		$lookup = $this->newLookup();
		$this->assertNull( $lookup->fetch( $db, NS_MAIN, 'A' ) );
		$this->assertNull( $lookup->fetch( $db, NS_MAIN, 'C' ) );
	}

	public function testFetchMissingPage() {
		$this->assertNull( $this->newLookup()->fetch( $this->newHubDb( [], [], [] ), NS_MAIN, 'Nope' ) );
	}

	public function testLookupReportsMissingAsEmptyArray() {
		$this->assertSame( [], $this->newLookup( $this->newHubDb( [], [], [] ) )->lookup( 'Nope' ) );
	}

	public function testLookupReadsTheHubWikiInItsNamespace() {
		$db = $this->newHubDb(
			[ '14:זכויות' => self::pageRow( 7, NS_CATEGORY, 'זכויות' ) ],
			[],
			[ 7 => [ 'ar' => 'تصنيف:حقوق' ] ]
		);
		$this->assertSame(
			[ 'title' => 'קטגוריה:זכויות', 'links' => [ 'ar' => 'تصنيف:حقوق' ] ],
			$this->newLookup( $db )->lookup( 'קטגוריה:זכויות' )
		);
	}

	public static function provideProjectSpellings() {
		return [
			'hyphen, as $wgMetaNamespace' => [ 'כל-זכות:שאלות ותשובות', 'כל-זכות:שאלות ותשובות' ],
			'space, a WRMessages alias' => [ 'כל זכות:אודות המיזם', 'כל-זכות:אודות המיזם' ],
			'canonical' => [ 'Project:תרומה לכל זכות', 'כל-זכות:תרומה לכל זכות' ],
		];
	}

	/**
	 * Every spelling of the hub's project namespace finds the page, and the hub
	 * title always carries the hub's own name for it.
	 *
	 * @dataProvider provideProjectSpellings
	 */
	public function testProjectHubIsNamedTheHubsWay( string $link, string $expectedTitle ) {
		$result = $this->newLookup( $this->newProjectHubDb() )->lookup( $link );
		$this->assertSame( $expectedTitle, $result['title'] ?? null );
	}

	public function testUnconfiguredProjectNameFallsBackToCanonical() {
		$result = $this->newLookup( $this->newProjectHubDb(), [] )->lookup( 'Project:תרומה לכל זכות' );
		$this->assertSame( 'Project:תרומה לכל זכות', $result['title'] ?? null );
	}

	public function testHubInAnUnnamedNamespaceIsNotUsed() {
		$db = $this->newHubDb(
			[ '0:X' => self::pageRow( 1, NS_MAIN, 'X', true ), '126:Y' => self::pageRow( 2, 126, 'Y' ) ],
			[ 1 => [ 'rd_namespace' => 126, 'rd_title' => 'Y', 'rd_interwiki' => '' ] ],
			[ 2 => [ 'ar' => 'A' ] ]
		);
		$this->assertSame( [], $this->newLookup( $db )->lookup( 'X' ) );
	}

	public function testCacheKeysAreSeparatedBySite() {
		$wan = new WANObjectCache( [ 'cache' => new HashBagOStuff() ] );
		$calls = 0;
		$db = $this->newHubDb( [ '0:X' => self::pageRow( 1, NS_MAIN, 'X' ) ], [], [ 1 => [ 'ar' => 'A' ] ] );
		$provider = $this->createMock( IConnectionProvider::class );
		$provider->method( 'getReplicaDatabase' )->willReturnCallback(
			static function () use ( $db, &$calls ) {
				$calls++;
				return $db;
			}
		);
		$make = fn ( string $site ) => new HubLookup(
			$provider, $wan, $this->newCaselessLanguage(), self::newHebrewNamespaces(), 'kz_he', $site
		);
		$make( 'main' )->lookup( 'X' );
		$make( 'main' )->lookup( 'X' );
		$make( 'teen' )->lookup( 'X' );
		$this->assertSame( 2, $calls );
	}
}
