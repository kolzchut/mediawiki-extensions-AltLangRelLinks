<?php

namespace MediaWiki\Extension\AltLangRelLinks\Tests\Unit;

use MediaWiki\Extension\AltLangRelLinks\WikiNamespaces;
use MediaWiki\Language\Language;
use Wikimedia\Rdbms\FakeResultWrapper;
use Wikimedia\Rdbms\IReadableDatabase;
use Wikimedia\Rdbms\SelectQueryBuilder;

/**
 * A fake hub database and the Hebrew main wiki's namespace data.
 */
trait HubTestTrait {

	/**
	 * The parts of LocalisationCache 'he' that matter here, as on dev main
	 * (core plus WRMessages): NS_PROJECT has no localised name, its talk
	 * namespace is "שיחת_$1", and "כל_זכות" is an alias.
	 */
	private static function hebrewLocalisedNames(): array {
		return [ NS_PROJECT_TALK => 'שיחת_$1', NS_CATEGORY => 'קטגוריה', 124 => 'נחיתה' ];
	}

	private static function hebrewLocalisedAliases(): array {
		return [ 'כל_זכות' => NS_PROJECT, 'כלזכות' => NS_PROJECT, 'תב' => NS_TEMPLATE ];
	}

	private static function canonicalNames(): array {
		return [ NS_MAIN => '', NS_PROJECT => 'Project', NS_CATEGORY => 'Category' ];
	}

	/**
	 * @param array $configured $wgAltLangRelLinksNamespaceNames['he']
	 */
	private static function newHebrewNamespaces(
		array $configured = [ NS_PROJECT => 'כל-זכות' ]
	): WikiNamespaces {
		return new WikiNamespaces(
			self::hebrewLocalisedNames(),
			self::hebrewLocalisedAliases(),
			self::canonicalNames(),
			$configured
		);
	}

	private function newCaselessLanguage(): Language {
		$lang = $this->createMock( Language::class );
		$lang->method( 'ucfirst' )->willReturnArgument( 0 );
		return $lang;
	}

	/**
	 * @param array $pages Page rows keyed by "ns:dbkey"
	 * @param array $redirects Redirect rows keyed by page ID
	 * @param array $langlinks lang => title maps keyed by page ID
	 */
	private function newHubDb( array $pages, array $redirects, array $langlinks ): IReadableDatabase {
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

	private static function pageRow( int $id, int $ns, string $title, bool $redirect = false ): array {
		return [
			'page_id' => $id,
			'page_namespace' => $ns,
			'page_title' => $title,
			'page_is_redirect' => $redirect ? 1 : 0,
		];
	}

	/**
	 * The Hebrew project pages that the dev main ar/ru project pages link to.
	 */
	private function newProjectHubDb(): IReadableDatabase {
		return $this->newHubDb(
			[
				'4:שאלות_ותשובות' => self::pageRow( 1, NS_PROJECT, 'שאלות_ותשובות' ),
				'4:אודות_המיזם' => self::pageRow( 2, NS_PROJECT, 'אודות_המיזם' ),
				'4:תרומה_לכל_זכות' => self::pageRow( 3, NS_PROJECT, 'תרומה_לכל_זכות' ),
			],
			[],
			[
				1 => [ 'ar' => 'كل الحق:اسئلة واجوبة', 'ru' => 'Коль Зхут:Вопросы и ответы' ],
				2 => [ 'ar' => 'كل الحق:حول المشروع', 'ru' => 'Коль Зхут:О проекте' ],
				3 => [ 'ar' => 'كل الحق:كيف بالإمكان التبرع لكل الحق', 'ru' => 'Project:Пожертвования' ],
			]
		);
	}
}
