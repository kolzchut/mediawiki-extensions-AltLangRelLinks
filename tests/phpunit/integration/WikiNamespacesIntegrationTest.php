<?php

namespace MediaWiki\Extension\AltLangRelLinks\Tests\Integration;

use MediaWiki\Extension\AltLangRelLinks\WikiNamespaces;
use MediaWiki\MainConfigNames;
use MediaWikiIntegrationTestCase;

/**
 * The hub's namespace names come from the real LocalisationCache, and the local
 * wiki's own project and extra namespace names must not leak into them. The
 * local project namespace is set to the Arabic main wiki's. ($wgLanguageCode
 * itself cannot change mid-test: Semantic MediaWiki refuses.)
 *
 * @covers \MediaWiki\Extension\AltLangRelLinks\WikiNamespaces
 */
class WikiNamespacesIntegrationTest extends MediaWikiIntegrationTestCase {

	private function newWikiNamespaces(): WikiNamespaces {
		$this->overrideConfigValues( [
			MainConfigNames::MetaNamespace => 'كل_الحق',
			MainConfigNames::ExtraNamespaces => [ 3000 => 'محلي', 3001 => 'نقاش_محلي' ],
		] );
		$services = $this->getServiceContainer();
		return WikiNamespaces::newFromServices(
			$services->getLocalisationCache(),
			$services->getNamespaceInfo(),
			'he',
			[ NS_PROJECT => 'כל-זכות' ]
		);
	}

	public function testALocalHebrewLanguageObjectIsWrong() {
		// What the first version relied on, and why it broke on ar/ru.
		$this->overrideConfigValue( MainConfigNames::MetaNamespace, 'كل_الحق' );
		$he = $this->getServiceContainer()->getLanguageFactory()->getLanguage( 'he' );
		$this->assertSame( 'كل_الحق', $he->getNamespaces()[NS_PROJECT] );
		$this->assertFalse( $he->getNsIndex( 'כל-זכות' ) );
	}

	public static function provideProjectSpellings() {
		return [
			'hyphen, as $wgMetaNamespace on he' => [ 'כל-זכות', false ],
			'lower-case canonical' => [ 'project', false ],
			'canonical' => [ 'Project', false ],
			'space, a WRMessages alias' => [ 'כל זכות', true ],
			'underscore, the same alias' => [ 'כל_זכות', true ],
		];
	}

	/**
	 * @dataProvider provideProjectSpellings
	 */
	public function testProjectSpellingsResolveToTheHubsName( string $prefix, bool $needsWRMessages ) {
		$ns = $this->newWikiNamespaces();
		if ( $needsWRMessages && !$this->getServiceContainer()->getLocalisationCache()
			->getSubitem( 'he', 'namespaceAliases', 'כל_זכות' )
		) {
			$this->markTestSkipped( 'WRMessages (the alias) is not loaded on this site' );
		}
		$this->assertSame( NS_PROJECT, $ns->getIndex( $prefix ) );
		$this->assertSame( 'כל-זכות', $ns->getName( NS_PROJECT ) );
	}

	public function testLocalNamesDoNotApplyToTheHub() {
		$ns = $this->newWikiNamespaces();
		$this->assertNotSame( NS_PROJECT, $ns->getIndex( 'كل_الحق' ) );
		$this->assertSame( 'קטגוריה', $ns->getName( NS_CATEGORY ) );
		$this->assertSame( NS_CATEGORY, $ns->getIndex( 'קטגוריה' ) );
		$this->assertSame( NS_CATEGORY, $ns->getIndex( 'Category' ) );
	}
}
