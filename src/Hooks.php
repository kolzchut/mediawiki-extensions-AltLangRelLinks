<?php
/**
 * WikiRights AltLangRelLinks extension
 *
 * Adds a `<link rel="alternate" hreflang="">` for each language version of a
 * content page, and a `<link rel="canonical">` to wiki pages that lack one.
 */

namespace MediaWiki\Extension\AltLangRelLinks;

use MediaWiki\Config\Config;
use MediaWiki\Hook\LinksUpdateCompleteHook;
use MediaWiki\Language\LanguageCode;
use MediaWiki\Languages\LanguageConverterFactory;
use MediaWiki\Languages\LanguageFactory;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MainConfigNames;
use MediaWiki\Output\Hook\BeforePageDisplayHook;
use MediaWiki\Output\OutputPage;
use MediaWiki\Page\Hook\PageDeleteCompleteHook;
use MediaWiki\Title\NamespaceInfo;
use MediaWiki\Title\Title;
use MediaWiki\Title\TitleFactory;
use MediaWiki\WikiMap\WikiMap;
use Wikimedia\ObjectCache\WANObjectCache;
use Wikimedia\Rdbms\DBError;
use Wikimedia\Rdbms\IConnectionProvider;

class Hooks implements
	BeforePageDisplayHook,
	LinksUpdateCompleteHook,
	PageDeleteCompleteHook
{

	/** @var array<string,string> language code => wiki ID */
	private array $wikis;
	private ?string $hubLanguage;
	private ?HubLookup $hubLookup = null;

	public function __construct(
		private readonly Config $config,
		private readonly IConnectionProvider $connectionProvider,
		private readonly WANObjectCache $wanCache,
		private readonly NamespaceInfo $namespaceInfo,
		private readonly LanguageFactory $languageFactory,
		private readonly LanguageConverterFactory $languageConverterFactory,
		private readonly TitleFactory $titleFactory
	) {
		$this->wikis = (array)$config->get( 'AltLangRelLinksWikis' );
		$hub = $config->get( 'AltLangRelLinksHubLanguage' );
		$this->hubLanguage = ( is_string( $hub ) && isset( $this->wikis[$hub] ) ) ? $hub : null;
	}

	/**
	 * @inheritDoc
	 */
	public function onBeforePageDisplay( $out, $skin ): void {
		$title = $out->getTitle();
		if ( !$title || !$title->canExist() ) {
			return;
		}
		$this->addCanonicalLink( $out, $title );
		$this->addAlternateLinks( $out, $title );
	}

	/**
	 * Add a canonical link the way core does when $wgEnableCanonicalServerLink
	 * is on, for wiki pages only. A canonical URL someone already set (core sets
	 * one when following a redirect) is left alone, and so is everything when
	 * core is configured to emit the link itself.
	 */
	private function addCanonicalLink( OutputPage $out, Title $title ): void {
		if ( !$this->config->get( 'AltLangRelLinksCanonical' )
			|| $this->config->get( MainConfigNames::EnableCanonicalServerLink )
			|| $out->getCanonicalUrl() !== false
			|| !$out->isArticleRelated()
		) {
			return;
		}

		$query = [];
		$action = $out->getContext()->getActionName();
		if ( in_array( $action, [ 'history', 'info' ], true ) ) {
			// These describe the page rather than show it.
			$query['action'] = $action;
		} elseif ( !$this->languageConverterFactory->isConversionDisabled() ) {
			$variant = $this->languageConverterFactory
				->getLanguageConverter( $title->getPageLanguage() )
				->getURLVariant();
			if ( $variant ) {
				$query['variant'] = $variant;
			}
		}

		$out->setCanonicalUrl( $title->getCanonicalURL( $query ) );
	}

	private function addAlternateLinks( OutputPage $out, Title $title ): void {
		if ( !$out->isArticle()
			|| !$out->isRevisionCurrent()
			|| $out->getContext()->getActionName() !== 'view'
			|| !$this->isAlternateNamespace( $title->getNamespace() )
			|| !$title->exists()
		) {
			return;
		}

		// The wiki's language, not the reader's interface language.
		$currentLanguage = (string)$this->config->get( MainConfigNames::LanguageCode );
		$builder = new LanguageSetBuilder(
			array_keys( $this->wikis ),
			$this->hubLanguage,
			(bool)$this->config->get( 'AltLangRelLinksXDefault' )
		);
		$ownLinks = LanguageSetBuilder::parseLanguageLinks( $out->getLanguageLinks() );

		$hub = null;
		$hubTitle = $builder->getHubTitle( $currentLanguage, $ownLinks );
		if ( $hubTitle !== null ) {
			try {
				$hub = $this->getHubLookup()->lookup( $hubTitle );
			} catch ( DBError $e ) {
				// Fall back to the page's own links rather than fail the view.
				LoggerFactory::getInstance( 'AltLangRelLinks' )->warning(
					'Hub lookup failed for {title}: {error}',
					[ 'title' => $hubTitle, 'error' => $e->getMessage() ]
				);
			}
		}

		$set = $builder->build( $currentLanguage, $title->getPrefixedText(), $ownLinks, $hub );

		$urls = [];
		foreach ( $set['links'] as $lang => $text ) {
			$url = $lang === $currentLanguage
				? $title->getCanonicalURL()
				: $this->getInterlanguageUrl( $lang, $text );
			if ( $url !== null ) {
				$urls[$lang] = $url;
			}
		}
		if ( count( $urls ) < 2 ) {
			return;
		}
		foreach ( $urls as $lang => $url ) {
			$out->addLink( [
				'rel' => 'alternate',
				'hreflang' => LanguageCode::bcp47( $lang ),
				'href' => $url,
			] );
		}
		if ( $set['xDefault'] !== null && isset( $urls[$set['xDefault']] ) ) {
			$out->addLink( [
				'rel' => 'alternate',
				'hreflang' => 'x-default',
				'href' => $urls[$set['xDefault']],
			] );
		}
	}

	private function isAlternateNamespace( int $namespace ): bool {
		$namespaces = $this->config->get( 'AltLangRelLinksNamespaces' );
		return $namespaces === null
			? $this->namespaceInfo->isContent( $namespace )
			: in_array( $namespace, (array)$namespaces, true );
	}

	private function getInterlanguageUrl( string $lang, string $text ): ?string {
		$target = $this->titleFactory->newFromText( "$lang:$text" );
		if ( !$target || $target->getInterwiki() !== $lang ) {
			return null;
		}
		return $target->getCanonicalURL();
	}

	/**
	 * On the hub wiki, a page's interlanguage links may just have changed.
	 *
	 * @inheritDoc
	 */
	public function onLinksUpdateComplete( $linksUpdate, $ticket ) {
		if ( $this->isHubWiki() ) {
			$page = $linksUpdate->getTitle();
			$this->getHubLookup()->purge( $page->getNamespace(), $page->getDBkey() );
		}
	}

	/**
	 * @inheritDoc
	 */
	public function onPageDeleteComplete(
		$page, $deleter, $reason, $pageID, $deletedRev, $logEntry, $archivedRevisionCount
	) {
		if ( $this->isHubWiki() ) {
			$this->getHubLookup()->purge( $page->getNamespace(), $page->getDBkey() );
		}
	}

	private function isHubWiki(): bool {
		return $this->hubLanguage !== null
			&& WikiMap::getCurrentWikiId() === $this->wikis[$this->hubLanguage];
	}

	private function getHubLookup(): HubLookup {
		$this->hubLookup ??= new HubLookup(
			$this->connectionProvider,
			$this->wanCache,
			$this->languageFactory->getLanguage( $this->hubLanguage ),
			$this->wikis[$this->hubLanguage]
		);
		return $this->hubLookup;
	}
}
