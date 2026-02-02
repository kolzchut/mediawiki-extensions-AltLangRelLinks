AltLangRelLinks extension for MediaWiki
=======================================

This extension adds an alternate `<link>` element for each inter-language link,
per [Google's recommendations](https://support.google.com/webmasters/answer/189077).

## Requirements

## Installation

1. Place the extension files in your MediaWiki installation's `extensions/WikiRights/AltLangRelLinks/` directory
2. Add the following line to your `LocalSettings.php`:

```php
wfLoadExtension( 'WikiRights/AltLangRelLinks' );
```

## What it does

The extension automatically adds `<link rel="alternate" hreflang="xx">` elements to the HTML head
for each language variant of the current page. This helps search engines understand the relationship
between different language versions of your content.

For example, if a page has Hebrew and English versions, the extension will add:
```html
<link rel="alternate" hreflang="he" href="https://example.com/wiki/PageName_in_Hebrew">
<link rel="alternate" hreflang="en" href="https://example.com/wiki/PageName_in_English">
```

## Changelog

See [CHANGELOG.md](CHANGELOG.md) for version history and release notes.

