# Changelog

All notable changes to the AltLangRelLinks extension will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [2.0.0] - 2026-11-11

### Added
- The language set of a page comes from its hub page (`$wgAltLangRelLinksHubLanguage`):
  a page on another language wiki takes every language version from the hub
  page's interlanguage links, so it lists versions it does not link to itself
- `$wgAltLangRelLinksWikis` limits the alternates to the farm's own languages
- `hreflang="x-default"`, pointing at the hub page
- A `<link rel="canonical">` on every wiki page that has none (`$wgAltLangRelLinksCanonical`)
- `$wgAltLangRelLinksNamespaces`; unit tests

### Changed
- **BREAKING**: Minimum MediaWiki version is now 1.43
- Alternates are added only to current-revision views in the alternate namespaces
  (the content namespaces by default), and only when there is more than one language
- The current page's alternate uses the wiki's content language. It used the
  reader's interface language, so `?uselang=en` produced `hreflang="en"`
- A page's link to its own language no longer produces a second alternate

## [1.0.0] - 2026-01-15

### Changed
- **BREAKING**: Minimum MediaWiki version is now 1.39
- **BREAKING**: Removed PHP entry point file (`AltLangRelLinks.php`) - extension now loads purely via `extension.json`
- Complete rewrite using modern MediaWiki extension standards
- Migrated from legacy PHP-based registration to extension.json
- Updated to use proper namespaced classes (`MediaWiki\Extension\AltLangRelLinks`)
- Moved hook handler to dedicated `includes/Hooks.php` file
- Updated to use modern hook registration system

### Added
- Better error handling for invalid titles and interwiki codes
- extension.json for extension registration
- Comprehensive documentation in README.md

### Fixed
- Proper handling of edge cases where titles or interwiki codes might be invalid
- More robust BCP-47 language code conversion

## [0.1.1] - 2015-01-19

### Fixed
- Description text in MediaWiki extension credits

## [0.1.0] - 2014

### Added
- Initial release
- Adds `<link rel="alternate" hreflang="...">` elements for inter-language links
- Support for multiple language variants per page
- Automatic BCP-47 language code conversion

