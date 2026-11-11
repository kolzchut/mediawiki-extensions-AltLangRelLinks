<?php

namespace MediaWiki\Extension\AltLangRelLinks;

use LocalisationCache;
use MediaWiki\Title\NamespaceInfo;

/**
 * The namespace names of another language wiki of the farm, read without the
 * local wiki's configuration.
 *
 * A Language object built on one wiki of the farm is no use for another: it
 * lays the LOCAL wiki's $wgMetaNamespace and $wgExtraNamespaces over that
 * language's names, so on the Arabic wiki "he" NS_PROJECT is called "كل_الحق".
 * This class instead combines the parts that are the same on every wiki:
 *  - the language's localised names and aliases (core and extension namespace
 *    files, from the LocalisationCache);
 *  - the canonical (English) names, such as "Project";
 * with the names that come from that wiki's own configuration, which the site
 * states in $wgAltLangRelLinksNamespaceNames (NS_PROJECT's $wgMetaNamespace,
 * any $wgExtraNamespaces).
 */
class WikiNamespaces {

	/** @var array<string,int> normalised name => namespace ID */
	private array $ids = [];

	/** @var array<int,string> namespace ID => the name the hub prints */
	private array $names = [];

	/**
	 * @param array<int,string> $localisedNames The language's namespace names;
	 *  "$1" stands for the project name
	 * @param array<string,int> $localisedAliases The language's aliases
	 * @param array<int,string> $canonicalNames Canonical namespace names
	 * @param array<int,string> $configuredNames Names from that wiki's
	 *  configuration. They win over the others.
	 */
	public function __construct(
		array $localisedNames,
		array $localisedAliases,
		array $canonicalNames,
		array $configuredNames
	) {
		$project = $configuredNames[NS_PROJECT] ?? null;
		$localised = [];
		foreach ( $localisedNames as $id => $name ) {
			$name = (string)$name;
			if ( str_contains( $name, '$1' ) ) {
				if ( $project === null ) {
					continue;
				}
				$name = str_replace( '$1', $project, $name );
			}
			$localised[(int)$id] = $name;
		}

		foreach ( [ $configuredNames, $localised, $canonicalNames ] as $source ) {
			foreach ( $source as $id => $name ) {
				$id = (int)$id;
				$name = (string)$name;
				if ( $id === NS_MAIN || $name === '' ) {
					continue;
				}
				$this->names[$id] ??= self::normaliseName( $name );
				$this->ids[self::lookupKey( $name )] ??= $id;
			}
		}
		foreach ( $localisedAliases as $alias => $id ) {
			$this->ids[self::lookupKey( (string)$alias )] ??= (int)$id;
		}
	}

	/**
	 * @param LocalisationCache $localisationCache
	 * @param NamespaceInfo $namespaceInfo
	 * @param string $language
	 * @param array<int,string> $configuredNames
	 * @return self
	 */
	public static function newFromServices(
		LocalisationCache $localisationCache,
		NamespaceInfo $namespaceInfo,
		string $language,
		array $configuredNames
	): self {
		return new self(
			(array)$localisationCache->getItem( $language, 'namespaceNames' ),
			(array)$localisationCache->getItem( $language, 'namespaceAliases' ),
			$namespaceInfo->getCanonicalNamespaces(),
			$configuredNames
		);
	}

	/**
	 * @param string $name A namespace prefix as written in a link
	 * @return int|null The namespace ID on that wiki, or null if it is not one
	 */
	public function getIndex( string $name ): ?int {
		return $this->ids[self::lookupKey( $name )] ?? null;
	}

	/**
	 * @param int $namespace
	 * @return string|null That wiki's name for the namespace (with underscores),
	 *  '' for the main namespace, or null when it is unknown
	 */
	public function getName( int $namespace ): ?string {
		if ( $namespace === NS_MAIN ) {
			return '';
		}
		return $this->names[$namespace] ?? null;
	}

	/**
	 * Rewrite "Prefix:Title" with this wiki's own name for the namespace, so
	 * that "Project:X" and "כל זכות:X" both become "כל-זכות:X". Text without a
	 * known namespace prefix is returned as it is.
	 */
	public function normaliseTitle( string $text ): string {
		$colon = strpos( $text, ':' );
		if ( $colon === false ) {
			return $text;
		}
		$index = $this->getIndex( substr( $text, 0, $colon ) );
		$name = $index === null ? null : $this->getName( $index );
		if ( $name === null || $name === '' ) {
			return $text;
		}
		return str_replace( '_', ' ', $name ) . ':' . ltrim( substr( $text, $colon + 1 ), ' _' );
	}

	private static function normaliseName( string $name ): string {
		return trim( str_replace( ' ', '_', $name ), '_' );
	}

	private static function lookupKey( string $name ): string {
		return mb_strtolower( self::normaliseName( $name ) );
	}
}
