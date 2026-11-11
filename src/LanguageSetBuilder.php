<?php

namespace MediaWiki\Extension\AltLangRelLinks;

/**
 * Decides which language versions of a page get an hreflang alternate.
 *
 * The hub language's page is the one that links to every other language
 * version, so it defines the set. A page on any other language wiki reaches
 * the hub through its own link to the hub language, and takes the hub's
 * links from there. An Arabic page therefore gets its Russian counterpart
 * (and the other way round) without linking to it itself.
 *
 * Pure logic, with no MediaWiki services, so it can be unit tested.
 */
class LanguageSetBuilder {

	/** @var string[] */
	private array $languages;

	/**
	 * @param string[] $languages Languages that may get an alternate, in output
	 *  order. Empty means any language.
	 * @param string|null $hubLanguage The hub language, or null for no hub
	 * @param bool $xDefault Whether to point x-default at the hub page
	 */
	public function __construct(
		array $languages,
		private readonly ?string $hubLanguage,
		private readonly bool $xDefault
	) {
		$this->languages = array_values( $languages );
	}

	/**
	 * Split interlanguage links ("ar:Title") into a language => title map.
	 * The first link for a language wins, which is also what the skin shows.
	 *
	 * @param string[] $languageLinks As returned by OutputPage::getLanguageLinks()
	 * @return array<string,string>
	 */
	public static function parseLanguageLinks( array $languageLinks ): array {
		$links = [];
		foreach ( $languageLinks as $link ) {
			$parts = explode( ':', $link, 2 );
			if ( count( $parts ) !== 2 ) {
				continue;
			}
			$lang = strtolower( trim( $parts[0] ) );
			$title = trim( $parts[1] );
			if ( $lang === '' || $title === '' || isset( $links[$lang] ) ) {
				continue;
			}
			$links[$lang] = $title;
		}
		return $links;
	}

	/**
	 * The hub page to look up for the current page.
	 *
	 * @param string $currentLanguage
	 * @param array<string,string> $ownLinks The page's own interlanguage links
	 * @return string|null The hub page's title, or null when the page is on the
	 *  hub wiki, there is no hub, or the page has no link to it
	 */
	public function getHubTitle( string $currentLanguage, array $ownLinks ): ?string {
		if ( $this->hubLanguage === null || $currentLanguage === $this->hubLanguage ) {
			return null;
		}
		return $ownLinks[$this->hubLanguage] ?? null;
	}

	/**
	 * Build the alternate set.
	 *
	 * @param string $currentLanguage Content language of the current wiki
	 * @param string $currentTitle Prefixed text of the current page
	 * @param array<string,string> $ownLinks The page's own interlanguage links
	 * @param array|null $hub What the hub lookup found:
	 *  - null when no lookup was made, or it could not be completed: the page's
	 *    own links are used as they are;
	 *  - [] when the hub page does not exist: own links, minus the dead link to
	 *    the hub;
	 *  - [ 'title' => string, 'links' => array<string,string> ] for the hub page
	 *    (after following a redirect) and its interlanguage links.
	 * @return array{links: array<string,string>, xDefault: ?string} `links` maps
	 *  each language to the title of its version (the current page included).
	 *  `xDefault` is the language whose entry x-default repeats, or null. Both
	 *  are empty when the page has no other language version.
	 */
	public function build(
		string $currentLanguage,
		string $currentTitle,
		array $ownLinks,
		?array $hub
	): array {
		$hubLanguage = $this->hubLanguage;
		$hubTitle = null;

		if ( $hubLanguage !== null && $currentLanguage === $hubLanguage ) {
			$candidates = $ownLinks;
			$hubTitle = $currentTitle;
		} elseif ( $hubLanguage !== null && isset( $hub['title'] ) ) {
			$hubTitle = $hub['title'];
			$candidates = [ $hubLanguage => $hubTitle ] + ( $hub['links'] ?? [] );
		} elseif ( $hubLanguage !== null && $hub === [] ) {
			$candidates = $ownLinks;
			unset( $candidates[$hubLanguage] );
		} else {
			$candidates = $ownLinks;
			if ( $hubLanguage !== null && isset( $candidates[$hubLanguage] ) ) {
				$hubTitle = $candidates[$hubLanguage];
			}
		}

		// The hub may hold a link back to this language that names some other
		// page, and some pages link to their own language. The current page is
		// always its own language's version.
		$candidates[$currentLanguage] = $currentTitle;

		$links = [];
		if ( $this->languages ) {
			foreach ( $this->languages as $lang ) {
				if ( isset( $candidates[$lang] ) ) {
					$links[$lang] = $candidates[$lang];
				}
			}
		} else {
			$links = $candidates;
		}

		if ( count( $links ) < 2 ) {
			return [ 'links' => [], 'xDefault' => null ];
		}

		$xDefault = null;
		if ( $this->xDefault && $hubTitle !== null && isset( $links[$hubLanguage] ) ) {
			$xDefault = $hubLanguage;
		}

		return [ 'links' => $links, 'xDefault' => $xDefault ];
	}
}
