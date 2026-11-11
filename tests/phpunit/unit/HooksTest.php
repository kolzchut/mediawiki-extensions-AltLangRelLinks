<?php

namespace MediaWiki\Extension\AltLangRelLinks\Tests\Unit;

use LocalisationCache;
use MediaWiki\Config\HashConfig;
use MediaWiki\Context\IContextSource;
use MediaWiki\Extension\AltLangRelLinks\Hooks;
use MediaWiki\Language\ILanguageConverter;
use MediaWiki\Language\Language;
use MediaWiki\Languages\LanguageConverterFactory;
use MediaWiki\Languages\LanguageFactory;
use MediaWiki\Output\OutputPage;
use MediaWiki\Title\NamespaceInfo;
use MediaWiki\Title\Title;
use MediaWiki\Title\TitleFactory;
use MediaWikiUnitTestCase;
use Skin;
use Wikimedia\ObjectCache\HashBagOStuff;
use Wikimedia\ObjectCache\WANObjectCache;
use Wikimedia\Rdbms\IConnectionProvider;
use Wikimedia\Rdbms\IReadableDatabase;

/**
 * @covers \MediaWiki\Extension\AltLangRelLinks\Hooks
 * @covers \MediaWiki\Extension\AltLangRelLinks\HubLookup
 * @covers \MediaWiki\Extension\AltLangRelLinks\WikiNamespaces
 * @covers \MediaWiki\Extension\AltLangRelLinks\LanguageSetBuilder
 */
class HooksTest extends MediaWikiUnitTestCase {
	use HubTestTrait;

	private const SERVER = 'https://example.org';

	/** @var array[] Links added to the page */
	private array $links;

	/** @var string|false */
	private $canonical;

	private static function url( string $lang, string $text, array $query = [] ): string {
		$url = self::SERVER . "/$lang/" . str_replace( ' ', '_', $text );
		return $query ? $url . '?' . http_build_query( $query ) : $url;
	}

	private function newHooks(
		array $config = [],
		?IReadableDatabase $hubDb = null,
		bool $conversion = false
	): Hooks {
		$config = new HashConfig( $config + [
			'AltLangRelLinksWikis' => [ 'he' => 'kz_he', 'ar' => 'kz_ar', 'ru' => 'kz_ru' ],
			'AltLangRelLinksHubLanguage' => 'he',
			'AltLangRelLinksNamespaceNames' => [
				'he' => [ NS_PROJECT => 'כל-זכות' ],
				'ar' => [ NS_PROJECT => 'كل_الحق' ],
				'ru' => [ NS_PROJECT => 'Коль_Зхут' ],
			],
			'AltLangRelLinksNamespaces' => null,
			'AltLangRelLinksXDefault' => true,
			'AltLangRelLinksCanonical' => true,
			'EnableCanonicalServerLink' => false,
			'LanguageCode' => 'ar',
			'CanonicalServer' => self::SERVER,
		] );

		$provider = $this->createMock( IConnectionProvider::class );
		if ( $hubDb ) {
			$provider->method( 'getReplicaDatabase' )->with( 'kz_he' )->willReturn( $hubDb );
		} else {
			$provider->expects( $this->never() )->method( 'getReplicaDatabase' );
		}

		$nsInfo = $this->createMock( NamespaceInfo::class );
		$nsInfo->method( 'isContent' )->willReturnCallback( static fn ( $ns ) => $ns === NS_MAIN );
		$nsInfo->method( 'getCanonicalNamespaces' )->willReturn( self::canonicalNames() );

		$languageFactory = $this->createMock( LanguageFactory::class );
		$languageFactory->method( 'getLanguage' )->willReturn( $this->newCaselessLanguage() );

		$converter = $this->createMock( ILanguageConverter::class );
		$converter->method( 'getURLVariant' )->willReturn( 'sr-el' );
		$converterFactory = $this->createMock( LanguageConverterFactory::class );
		$converterFactory->method( 'isConversionDisabled' )->willReturn( !$conversion );
		$converterFactory->method( 'getLanguageConverter' )->willReturn( $converter );

		$titleFactory = $this->createMock( TitleFactory::class );
		$titleFactory->method( 'newFromText' )->willReturnCallback( function ( $text ) {
			[ $prefix, $rest ] = explode( ':', $text, 2 );
			$title = $this->createMock( Title::class );
			$title->method( 'getInterwiki' )->willReturn( $prefix );
			$title->method( 'getCanonicalURL' )->willReturn( self::url( $prefix, $rest ) );
			return $title;
		} );

		$lc = $this->createMock( LocalisationCache::class );
		$lc->method( 'getItem' )->willReturnCallback(
			static fn ( $lang, $key ) => $lang !== 'he' ? [] : (
				$key === 'namespaceNames' ? self::hebrewLocalisedNames() : self::hebrewLocalisedAliases()
			)
		);

		return new Hooks(
			$config,
			$provider,
			new WANObjectCache( [ 'cache' => new HashBagOStuff() ] ),
			$nsInfo,
			$languageFactory,
			$converterFactory,
			$titleFactory,
			$lc
		);
	}

	/**
	 * @param array $opts Page state: lang, text, ns, links, action, exists,
	 *  canExist, current, article, related, canonical
	 */
	private function newOutput( array $opts ): OutputPage {
		$opts += [
			'lang' => 'ar',
			'text' => 'A',
			'ns' => NS_MAIN,
			'links' => [],
			'action' => 'view',
			'exists' => true,
			'canExist' => true,
			'current' => true,
			'article' => true,
			'related' => true,
			'canonical' => false,
		];
		$this->links = [];
		$this->canonical = $opts['canonical'];

		$title = $this->createMock( Title::class );
		$title->method( 'canExist' )->willReturn( $opts['canExist'] );
		$title->method( 'exists' )->willReturn( $opts['exists'] );
		$title->method( 'getNamespace' )->willReturn( $opts['ns'] );
		$title->method( 'getPrefixedText' )->willReturn( $opts['text'] );
		$title->method( 'getPageLanguage' )->willReturn( $this->createMock( Language::class ) );
		$title->method( 'getCanonicalURL' )->willReturnCallback(
			static fn ( $query = '' ) => self::url( $opts['lang'], $opts['text'], $query ?: [] )
		);

		$context = $this->createMock( IContextSource::class );
		$context->method( 'getActionName' )->willReturn( $opts['action'] );

		$out = $this->createMock( OutputPage::class );
		$out->method( 'getTitle' )->willReturn( $title );
		$out->method( 'getContext' )->willReturn( $context );
		$out->method( 'isArticle' )->willReturn( $opts['article'] );
		$out->method( 'isArticleRelated' )->willReturn( $opts['related'] );
		$out->method( 'isRevisionCurrent' )->willReturn( $opts['current'] );
		$out->method( 'getLanguageLinks' )->willReturn( $opts['links'] );
		$out->method( 'getCanonicalUrl' )->willReturnCallback( fn () => $this->canonical );
		$out->method( 'setCanonicalUrl' )->willReturnCallback( function ( $url ) {
			$this->canonical = $url;
		} );
		$out->method( 'addLink' )->willReturnCallback( function ( $link ) {
			$this->links[] = $link;
		} );
		return $out;
	}

	private function runHook( Hooks $hooks, array $opts ): void {
		$hooks->onBeforePageDisplay( $this->newOutput( $opts ), $this->createMock( Skin::class ) );
	}

	/** @return array<string,string> hreflang => href */
	private function alternates(): array {
		$result = [];
		foreach ( $this->links as $link ) {
			$this->assertSame( 'alternate', $link['rel'] );
			$result[$link['hreflang']] = $link['href'];
		}
		return $result;
	}

	// Canonical rules

	public function testCanonicalOnAPlainView() {
		$this->runHook( $this->newHooks(), [] );
		$this->assertSame( self::url( 'ar', 'A' ), $this->canonical );
	}

	public static function provideCanonicalActions() {
		return [
			'history keeps its action' => [ 'history', [ 'action' => 'history' ] ],
			'info keeps its action' => [ 'info', [ 'action' => 'info' ] ],
			'edit points at the page' => [ 'edit', [] ],
		];
	}

	/**
	 * @dataProvider provideCanonicalActions
	 */
	public function testCanonicalForActions( string $action, array $query ) {
		$this->runHook( $this->newHooks(), [ 'action' => $action, 'article' => false ] );
		$this->assertSame( self::url( 'ar', 'A', $query ), $this->canonical );
	}

	public function testCanonicalCarriesTheVariant() {
		$this->runHook( $this->newHooks( [], null, true ), [] );
		$this->assertSame( self::url( 'ar', 'A', [ 'variant' => 'sr-el' ] ), $this->canonical );
	}

	public function testCanonicalSetElsewhereIsKept() {
		$this->runHook( $this->newHooks(), [ 'canonical' => 'https://example.org/ar/Target' ] );
		$this->assertSame( 'https://example.org/ar/Target', $this->canonical );
	}

	public function testNoCanonicalWhenCoreAddsIt() {
		$this->runHook( $this->newHooks( [ 'EnableCanonicalServerLink' => true ] ), [] );
		$this->assertFalse( $this->canonical );
	}

	public function testNoCanonicalWhenTurnedOff() {
		$this->runHook( $this->newHooks( [ 'AltLangRelLinksCanonical' => false ] ), [] );
		$this->assertFalse( $this->canonical );
	}

	public function testNoCanonicalOffArticleRelatedPages() {
		$this->runHook( $this->newHooks(), [ 'related' => false, 'article' => false ] );
		$this->assertFalse( $this->canonical );
	}

	public function testSpecialPagesGetNothing() {
		$this->runHook( $this->newHooks(), [ 'canExist' => false, 'lang' => 'he', 'links' => [ 'ar:A' ] ] );
		$this->assertFalse( $this->canonical );
		$this->assertSame( [], $this->links );
	}

	// Scope gating

	public static function provideOutOfScope() {
		return [
			'old revision' => [ [ 'current' => false ] ],
			'not the article view' => [ [ 'article' => false ] ],
			'another action' => [ [ 'action' => 'purge' ] ],
			'non-content namespace' => [ [ 'ns' => NS_PROJECT, 'text' => 'כל-זכות:X' ] ],
			'missing page' => [ [ 'exists' => false ] ],
		];
	}

	/**
	 * @dataProvider provideOutOfScope
	 */
	public function testNoAlternatesOutOfScope( array $opts ) {
		$hooks = $this->newHooks( [ 'LanguageCode' => 'he' ] );
		$this->runHook( $hooks, $opts + [ 'lang' => 'he', 'text' => 'H', 'links' => [ 'ar:A', 'ru:R' ] ] );
		$this->assertSame( [], $this->links );
	}

	public function testConfiguredNamespacesReplaceContentNamespaces() {
		$hooks = $this->newHooks( [
			'LanguageCode' => 'he',
			'AltLangRelLinksNamespaces' => [ NS_PROJECT ],
		] );
		$this->runHook( $hooks, [
			'lang' => 'he',
			'ns' => NS_PROJECT,
			'text' => 'כל-זכות:X',
			'links' => [ 'ar:كل الحق:X' ],
		] );
		$this->assertSame( [ 'he', 'ar', 'x-default' ], array_keys( $this->alternates() ) );

		$this->runHook( $hooks, [ 'lang' => 'he', 'ns' => NS_MAIN, 'text' => 'H', 'links' => [ 'ar:A' ] ] );
		$this->assertSame( [], $this->links );
	}

	// The alternate set and x-default

	public function testHubPageDropsEnglishAndAddsXDefault() {
		$hooks = $this->newHooks( [ 'LanguageCode' => 'he' ] );
		$this->runHook( $hooks, [ 'lang' => 'he', 'text' => 'H', 'links' => [ 'ar:A', 'en:E', 'ru:R' ] ] );
		$this->assertSame(
			[
				'he' => self::url( 'he', 'H' ),
				'ar' => self::url( 'ar', 'A' ),
				'ru' => self::url( 'ru', 'R' ),
				'x-default' => self::url( 'he', 'H' ),
			],
			$this->alternates()
		);
	}

	public function testXDefaultCanBeTurnedOff() {
		$hooks = $this->newHooks( [ 'LanguageCode' => 'he', 'AltLangRelLinksXDefault' => false ] );
		$this->runHook( $hooks, [ 'lang' => 'he', 'text' => 'H', 'links' => [ 'ar:A' ] ] );
		$this->assertSame( [ 'he', 'ar' ], array_keys( $this->alternates() ) );
	}

	public function testArabicPageGetsRussianThroughTheHub() {
		$db = $this->newHubDb(
			[ '0:H' => self::pageRow( 1, NS_MAIN, 'H' ) ],
			[],
			[ 1 => [ 'ar' => 'A', 'en' => 'E', 'ru' => 'R' ] ]
		);
		$this->runHook( $this->newHooks( [], $db ), [ 'links' => [ 'he:H', 'en:E' ] ] );
		$this->assertSame(
			[
				'he' => self::url( 'he', 'H' ),
				'ar' => self::url( 'ar', 'A' ),
				'ru' => self::url( 'ru', 'R' ),
				'x-default' => self::url( 'he', 'H' ),
			],
			$this->alternates()
		);
	}

	public function testMissingHubGivesNoXDefault() {
		$db = $this->newHubDb( [], [], [] );
		$this->runHook( $this->newHooks( [], $db ), [ 'links' => [ 'he:Gone', 'ru:R' ] ] );
		$this->assertSame( [ 'ar', 'ru' ], array_keys( $this->alternates() ) );
	}

	public function testOnlyLanguageIsNotAnAlternateSet() {
		$this->runHook( $this->newHooks(), [ 'links' => [ 'en:E' ] ] );
		$this->assertSame( [], $this->links );
	}

	public function testAlternatesUseEachWikisOwnNamespaceName() {
		$hooks = $this->newHooks( [
			'LanguageCode' => 'he',
			'AltLangRelLinksNamespaces' => [ NS_PROJECT ],
		] );
		$this->runHook( $hooks, [
			'lang' => 'he',
			'ns' => NS_PROJECT,
			'text' => 'כל-זכות:X',
			'links' => [ 'ar:Project:Y', 'ru:project:Z' ],
		] );
		$alternates = $this->alternates();
		$this->assertSame( self::url( 'ar', 'كل الحق:Y' ), $alternates['ar'] );
		$this->assertSame( self::url( 'ru', 'Коль Зхут:Z' ), $alternates['ru'] );
	}

	public static function provideArabicProjectPages() {
		return [
			'hyphen' => [ 'كل الحق:اسئلة واجوبة', 'כל-זכות:שאלות ותשובות', 'כל-זכות:שאלות ותשובות' ],
			'space' => [ 'كل الحق:حول المشروع', 'כל זכות:אודות המיזם', 'כל-זכות:אודות המיזם' ],
			'canonical' => [
				'كل الحق:كيف بالإمكان التبرع لكل الحق',
				'Project:תרומה לכל זכות',
				'כל-זכות:תרומה לכל זכות',
			],
		];
	}

	/**
	 * An Arabic project page reaches its Hebrew hub with every spelling of the
	 * hub's project namespace, and points at it with the hub's own name.
	 *
	 * @dataProvider provideArabicProjectPages
	 */
	public function testArabicProjectPage( string $arTitle, string $heLink, string $heTitle ) {
		$hooks = $this->newHooks(
			[ 'AltLangRelLinksNamespaces' => [ NS_MAIN, NS_PROJECT ] ],
			$this->newProjectHubDb()
		);
		$this->runHook( $hooks, [ 'ns' => NS_PROJECT, 'text' => $arTitle, 'links' => [ "he:$heLink" ] ] );
		$alternates = $this->alternates();
		$this->assertSame( [ 'he', 'ar', 'ru', 'x-default' ], array_keys( $alternates ) );
		$this->assertSame( self::url( 'he', $heTitle ), $alternates['he'] ?? null );
		$this->assertSame( self::url( 'he', $heTitle ), $alternates['x-default'] ?? null );
		$this->assertSame( self::url( 'ar', $arTitle ), $alternates['ar'] ?? null );
	}
}
