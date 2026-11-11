<?php

namespace MediaWiki\Extension\AltLangRelLinks\Tests\Unit;

use MediaWiki\Extension\AltLangRelLinks\HubLookup;
use MediaWiki\Language\Language;
use MediaWikiUnitTestCase;
use Wikimedia\ObjectCache\HashBagOStuff;
use Wikimedia\ObjectCache\WANObjectCache;
use Wikimedia\Rdbms\FakeResultWrapper;
use Wikimedia\Rdbms\IConnectionProvider;
use Wikimedia\Rdbms\IReadableDatabase;
use Wikimedia\Rdbms\SelectQueryBuilder;

/**
 * @covers \MediaWiki\Extension\AltLangRelLinks\HubLookup
 */
class HubLookupTest extends MediaWikiUnitTestCase {

	private function newHebrew(): Language {
		$lang = $this->createMock( Language::class );
		$lang->method( 'getNsIndex' )->willReturnCallback(
			static fn ( $text ) => [ 'קטגוריה' => NS_CATEGORY, 'Category' => NS_CATEGORY ][$text] ?? false
		);
		$lang->method( 'getFormattedNsText' )->willReturnCallback(
			static fn ( $ns ) => $ns === NS_CATEGORY ? 'קטגוריה' : ''
		);
		$lang->method( 'ucfirst' )->willReturnArgument( 0 );
		return $lang;
	}

	public static function provideSplitTitle() {
		return [
			'main namespace' => [ 'דמי אבטלה', NS_MAIN, 'דמי_אבטלה' ],
			'Hebrew namespace name' => [ 'קטגוריה:זכויות', NS_CATEGORY, 'זכויות' ],
			'canonical namespace name' => [ 'Category:Rights', NS_CATEGORY, 'Rights' ],
			'colon that is not a namespace' => [ 'הסכם: נוסח', NS_MAIN, 'הסכם:_נוסח' ],
			'surrounding spaces' => [ ' דמי אבטלה ', NS_MAIN, 'דמי_אבטלה' ],
		];
	}

	/**
	 * @dataProvider provideSplitTitle
	 */
	public function testSplitTitle( string $text, int $ns, string $dbKey ) {
		$this->assertSame( [ $ns, $dbKey ], HubLookup::splitTitle( $text, $this->newHebrew() ) );
	}

	/**
	 * A fake hub database: page rows by "ns:dbkey", redirect rows and langlinks
	 * by page ID.
	 */
	private function newDb( array $pages, array $redirects, array $langlinks ): IReadableDatabase {
		$db = $this->createMock( IReadableDatabase::class );
		$db->method( 'newSelectQueryBuilder' )->willReturnCallback(
			static fn () => new SelectQueryBuilder( $db )
		);
		$db->method( 'selectRow' )->willReturnCallback(
			static function ( $table, $vars, $conds ) use ( $pages, $redirects ) {
				$table = (array)$table;
				if ( reset( $table ) === 'page' ) {
					$row = $pages[$conds['page_namespace'] . ':' . $conds['page_title']] ?? null;
				} else {
					$row = $redirects[$conds['rd_from']] ?? null;
				}
				return $row ? (object)$row : false;
			}
		);
		$db->method( 'select' )->willReturnCallback(
			static function ( $table, $vars, $conds ) use ( $langlinks ) {
				$rows = [];
				foreach ( $langlinks[$conds['ll_from']] ?? [] as $lang => $title ) {
					$rows[] = (object)[ 'll_lang' => $lang, 'll_title' => $title ];
				}
				return new FakeResultWrapper( $rows );
			}
		);
		return $db;
	}

	private static function page( int $id, int $ns, string $title, bool $redirect = false ): array {
		return [
			'page_id' => $id,
			'page_namespace' => $ns,
			'page_title' => $title,
			'page_is_redirect' => $redirect ? 1 : 0,
		];
	}

	private function newLookup( ?IReadableDatabase $db = null ): HubLookup {
		$provider = $this->createMock( IConnectionProvider::class );
		if ( $db ) {
			$provider->method( 'getReplicaDatabase' )->with( 'kz_he' )->willReturn( $db );
		}
		return new HubLookup(
			$provider,
			new WANObjectCache( [ 'cache' => new HashBagOStuff() ] ),
			$this->newHebrew(),
			'kz_he'
		);
	}

	public function testFetchReadsTheHubsLanguageLinks() {
		$db = $this->newDb(
			[ '0:דמי_אבטלה' => self::page( 1, NS_MAIN, 'דמי_אבטלה' ) ],
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
		$db = $this->newDb(
			[
				'0:הריון' => self::page( 1, NS_MAIN, 'הריון', true ),
				'0:היריון' => self::page( 2, NS_MAIN, 'היריון' ),
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
		$db = $this->newDb(
			[
				'0:A' => self::page( 1, NS_MAIN, 'A', true ),
				'0:B' => self::page( 2, NS_MAIN, 'B', true ),
				'0:C' => self::page( 3, NS_MAIN, 'C', true ),
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
		$this->assertNull( $this->newLookup()->fetch( $this->newDb( [], [], [] ), NS_MAIN, 'Nope' ) );
	}

	public function testLookupReportsMissingAsEmptyArray() {
		$this->assertSame( [], $this->newLookup( $this->newDb( [], [], [] ) )->lookup( 'Nope' ) );
	}

	public function testLookupReadsTheHubWikiInItsNamespace() {
		$db = $this->newDb(
			[ '14:זכויות' => self::page( 7, NS_CATEGORY, 'זכויות' ) ],
			[],
			[ 7 => [ 'ar' => 'تصنيف:حقوق' ] ]
		);
		$this->assertSame(
			[ 'title' => 'קטגוריה:זכויות', 'links' => [ 'ar' => 'تصنيف:حقوق' ] ],
			$this->newLookup( $db )->lookup( 'קטגוריה:זכויות' )
		);
	}
}
