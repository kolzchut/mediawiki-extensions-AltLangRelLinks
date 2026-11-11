<?php

namespace MediaWiki\Extension\AltLangRelLinks;

use MediaWiki\Language\Language;
use Wikimedia\ObjectCache\WANObjectCache;
use Wikimedia\Rdbms\IConnectionProvider;
use Wikimedia\Rdbms\IReadableDatabase;

/**
 * Reads a hub page's interlanguage links from the hub wiki's database.
 *
 * Every language of the farm is its own database on the same server, so the
 * read goes through the load balancer with the hub's wiki ID as the domain,
 * as RealLastUpdate does for its cross-wiki reads. Results are cached in the
 * WAN cache, which every wiki of a site shares, and the hub wiki clears an
 * entry when the page's links are updated or the page is deleted.
 */
class HubLookup {

	/** Upper bound on staleness, e.g. for an entry reached through a redirect */
	private const TTL = WANObjectCache::TTL_HOUR;

	private const CACHE_VERSION = 1;

	public function __construct(
		private readonly IConnectionProvider $connectionProvider,
		private readonly WANObjectCache $wanCache,
		private readonly Language $hubLanguage,
		private readonly string $hubWiki
	) {
	}

	/**
	 * @param string $titleText Title of the hub page, as a link to it names it
	 * @return array The hub page, in the shape LanguageSetBuilder::build() takes:
	 *  [] when it does not exist, otherwise [ 'title' => ..., 'links' => ... ]
	 */
	public function lookup( string $titleText ): array {
		[ $namespace, $dbKey ] = self::splitTitle( $titleText, $this->hubLanguage );
		if ( $dbKey === '' ) {
			return [];
		}

		$result = $this->wanCache->getWithSetCallback(
			$this->makeKey( $namespace, $dbKey ),
			self::TTL,
			function () use ( $namespace, $dbKey ) {
				$dbr = $this->connectionProvider->getReplicaDatabase( $this->hubWiki );
				// WANObjectCache treats false as a miss, so cache "missing" as
				// an array too.
				return $this->fetch( $dbr, $namespace, $dbKey ) ?? [ 'missing' => true ];
			}
		);

		return isset( $result['title'] ) ? $result : [];
	}

	/**
	 * Clear the cached entry for a hub wiki page.
	 */
	public function purge( int $namespace, string $dbKey ): void {
		$this->wanCache->delete( $this->makeKey( $namespace, $dbKey ) );
	}

	/**
	 * Find the hub page, following one redirect, and read its interlanguage links.
	 *
	 * @return array|null [ 'title' => string, 'links' => array<string,string> ],
	 *  or null when there is no such page
	 */
	public function fetch( IReadableDatabase $dbr, int $namespace, string $dbKey ): ?array {
		$page = $this->fetchPage( $dbr, $namespace, $dbKey );
		if ( $page && $page->page_is_redirect ) {
			$target = $dbr->newSelectQueryBuilder()
				->select( [ 'rd_namespace', 'rd_title', 'rd_interwiki' ] )
				->from( 'redirect' )
				->where( [ 'rd_from' => (int)$page->page_id ] )
				->caller( __METHOD__ )
				->fetchRow();
			$page = ( $target && (string)$target->rd_interwiki === '' )
				? $this->fetchPage( $dbr, (int)$target->rd_namespace, (string)$target->rd_title )
				: null;
			// A double redirect is not a hub.
			if ( $page && $page->page_is_redirect ) {
				$page = null;
			}
		}
		if ( !$page ) {
			return null;
		}

		$res = $dbr->newSelectQueryBuilder()
			->select( [ 'll_lang', 'll_title' ] )
			->from( 'langlinks' )
			->where( [ 'll_from' => (int)$page->page_id ] )
			->caller( __METHOD__ )
			->fetchResultSet();
		$links = [];
		foreach ( $res as $row ) {
			$lang = strtolower( (string)$row->ll_lang );
			if ( !isset( $links[$lang] ) && (string)$row->ll_title !== '' ) {
				$links[$lang] = (string)$row->ll_title;
			}
		}

		return [
			'title' => $this->formatTitle( (int)$page->page_namespace, (string)$page->page_title ),
			'links' => $links,
		];
	}

	/**
	 * Turn a link target on the hub wiki into a namespace and DB key, reading the
	 * namespace prefix in the hub's language. The hub's links are written in its
	 * own language, so the local wiki's namespace names do not apply.
	 *
	 * @return array{0: int, 1: string}
	 */
	public static function splitTitle( string $text, Language $hubLanguage ): array {
		$dbKey = trim( str_replace( ' ', '_', $text ), '_' );
		$namespace = NS_MAIN;
		$colon = strpos( $dbKey, ':' );
		if ( $colon !== false ) {
			$index = $hubLanguage->getNsIndex( substr( $dbKey, 0, $colon ) );
			if ( $index !== false && $index !== null ) {
				$namespace = (int)$index;
				$dbKey = trim( substr( $dbKey, $colon + 1 ), '_' );
			}
		}
		if ( $dbKey !== '' ) {
			$dbKey = $hubLanguage->ucfirst( $dbKey );
		}
		return [ $namespace, $dbKey ];
	}

	private function fetchPage( IReadableDatabase $dbr, int $namespace, string $dbKey ): ?\stdClass {
		$row = $dbr->newSelectQueryBuilder()
			->select( [ 'page_id', 'page_namespace', 'page_title', 'page_is_redirect' ] )
			->from( 'page' )
			->where( [ 'page_namespace' => $namespace, 'page_title' => $dbKey ] )
			->caller( __METHOD__ )
			->fetchRow();
		return $row ?: null;
	}

	private function formatTitle( int $namespace, string $dbKey ): string {
		$text = str_replace( '_', ' ', $dbKey );
		if ( $namespace === NS_MAIN ) {
			return $text;
		}
		return $this->hubLanguage->getFormattedNsText( $namespace ) . ':' . $text;
	}

	private function makeKey( int $namespace, string $dbKey ): string {
		return $this->wanCache->makeGlobalKey(
			'altlangrellinks-hub',
			self::CACHE_VERSION,
			$this->hubWiki,
			$namespace,
			md5( $dbKey )
		);
	}
}
