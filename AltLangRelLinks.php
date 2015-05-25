<?php
/**
 * WikiRights AltLangRelLinks extension
 * Adds a <link rel="alternate" hreflang=""> link for each langlink
 *
 * @author Dror S. [FFS]
 * @copyright (C) 2014 Dror S. & Kol-Zchut Ltd.
 * @license http://www.gnu.org/copyleft/gpl.html GNU General Public License 2.0 or later
 *
 */

if ( !defined( 'MEDIAWIKI' ) ) {
	die( 'This file is a MediaWiki extension, it is not a valid entry point' );
}

/* Setup */
$GLOBALS['wgExtensionCredits']['other'][] = array(
	'path'           => __FILE__,
	'name'           => 'WikiRights AltLangRelLinks',
	'author'         => 'Dror S. [FFS] ([http://www.kolzchut.org.il Kol-Zchut])',
	'version'        => '0.1.1',
	'license-name'   => 'GPL-2.0+',
	//'url'            => 'http://www.kolzchut.org.il/he/כל-זכות:Extensions/AltLangRelLinks',
	'descriptionmsg' => 'ext-altlangrellinks-desc',
);


$GLOBALS['wgMessagesDirs']['AltLangRelLinks'] = __DIR__ . '/i18n';


$GLOBALS['wgHooks']['BeforePageDisplay'][] = 'AltLangRelLinks::onBeforePageDisplay';


class AltLangRelLinks {

	static function onBeforePageDisplay( OutputPage &$out, Skin &$skin ) {
		$languageLinks = $out->getLanguageLinks();

		if ( empty( $languageLinks ) ) {
			return true;
		}

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
