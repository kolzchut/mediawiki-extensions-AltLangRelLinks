# Changelog

All notable changes to the AltLangRelLinks extension will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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

