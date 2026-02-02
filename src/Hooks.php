<?php
/**
 * WikiRights AltLangRelLinks extension
 * Adds a `<link rel="alternate" hreflang="">` link for each language link
 */

namespace MediaWiki\Extension\AltLangRelLinks;

use MediaWiki\Language\LanguageCode;
use MediaWiki\MediaWikiServices;
use MediaWiki\Output\Hook\BeforePageDisplayHook;
use MediaWiki\Title\Title;

class Hooks implements BeforePageDisplayHook {

	/**
	 * Adds alternate language links to the page head
	 * @inheritDoc
	 */
	public function onBeforePageDisplay( $out, $skin ): void {
		$languageLinks = $out->getLanguageLinks();

		if ( empty( $languageLinks ) ) {
			return;
		}

		$urlUtils = MediaWikiServices::getInstance()->getUrlUtils();

		// Add alternate language links for each interwiki language link
		foreach ( $languageLinks as $langLink ) {
			$languageLinkTitle = Title::newFromText( $langLink );
			if ( !$languageLinkTitle ) {
				continue;
			}

			$interwikiCode = $languageLinkTitle->getInterwiki();
			if ( !$interwikiCode ) {
				continue;
			}

			// Convert language code to BCP 47 format
			$bcp47Code = LanguageCode::bcp47( $interwikiCode );

			$out->addLink( [
				'rel' => 'alternate',
				'hreflang' => $bcp47Code,
				'href' => $urlUtils->expand( $languageLinkTitle->getFullURL(), PROTO_CANONICAL )
			] );
		}

		// Add alternate language link for the current page
		$currentPageLangCode = $out->getLanguage()->getCode();
		$currentPageTitle = $out->getTitle();

		if ( $currentPageTitle ) {
			$bcp47Code = LanguageCode::bcp47( $currentPageLangCode );
			$out->addLink( [
				'rel' => 'alternate',
				'hreflang' => $bcp47Code,
				'href' => $urlUtils->expand( $currentPageTitle->getFullURL(), PROTO_CANONICAL )
			] );
		}
	}
}
