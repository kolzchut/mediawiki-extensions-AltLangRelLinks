<?php
/**
 * WikiRights AltLangRelLinks extension - adds <link rel="alternate"> links to langlinks
 * @author Dror Snir
 * @copyright (C) 2014 Dror S. (Kol-Zchut)
 * @license http://www.gnu.org/copyleft/gpl.html GNU General Public License 2.0 or later
 *
 * @todo Parameters for share urls!
 */

if ( !defined( 'MEDIAWIKI' ) ) {
	die( 'This file is a MediaWiki extension, it is not a valid entry point' );
}

/* Setup */
$wgExtensionCredits['other'][] = array(
    'path'           => __FILE__,
    'name'           => 'WikiRights AltLangRelLinks',
    'author'         => 'Dror S. ([http://www.kolzchut.org.il Kol-Zchut])',
    'version'        => '0.1.0',
    'url'            => 'http://www.kolzchut.org.il/he/כל-זכות:Extensions/AltLangRelLinks',
    'descriptionmsg' => 'ext-altlangrellinks-desc',
);



$wgHooks['BeforePageDisplay'][] = 'AltLangRelLinks::onBeforePageDisplay';


class AltLangRelLinks {

	function onBeforePageDisplay( OutputPage &$out, Skin &$skin ) {
		$languageLinks = $out->getLanguageLinks();

		if ( empty( $languageLinks ) ) { return true; }

		// this is partly a ripoff from SkinTemplate::getLanguages()
		foreach ($languageLinks as $langLink) {
			$languageLinkTitle = Title::newFromText( $langLink );
			$interwikiCode = $languageLinkTitle->getInterwiki();

			$out->addLink( array(
				'rel' => 'alternate',
				'hreflang' => wfBCP47($interwikiCode),
				'href' => wfExpandIRI( $languageLinkTitle->getFullURL() )
			) );
		}

		// We also must add the current language
		$currentPageLangCode = $out->getLanguage()->getCode();
		$currentPageTitle = $out->getTitle();
		$out->addLink( array(
			'rel' => 'alternate',
			'hreflang' => wfBCP47( $currentPageLangCode ),
			'href' => wfExpandIRI( $currentPageTitle->getFullURL() )
		) );

		return true;
	}
}
