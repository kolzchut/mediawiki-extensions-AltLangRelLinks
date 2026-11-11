AltLangRelLinks extension for MediaWiki
=======================================

This extension adds a `<link rel="alternate" hreflang="xx">` element for each
language version of a page, per
[Google's recommendations](https://developers.google.com/search/docs/specialty/international/localized-versions),
and a `<link rel="canonical">` to wiki pages that lack one.

## Installation

```php
wfLoadExtension( 'WikiRights/AltLangRelLinks' );
```

## What it does

### hreflang alternates

On a page in an alternate namespace (the content namespaces by default), viewed
at its current revision, the extension adds one alternate per language version,
including the page itself, plus `x-default`:

```html
<link rel="alternate" hreflang="he" href="https://example.org/he/Page">
<link rel="alternate" hreflang="ar" href="https://example.org/ar/Page_in_Arabic">
<link rel="alternate" hreflang="ru" href="https://example.org/ru/Page_in_Russian">
<link rel="alternate" hreflang="x-default" href="https://example.org/he/Page">
```

The language set comes from a **hub** wiki. The hub page links to every other
language version. A page on another language wiki finds its hub page through
its own interlanguage link to the hub language, and takes the rest of the set
from the hub page's links. An Arabic page that links only to Hebrew therefore
still gets its Russian alternate. The hub page is read from the hub wiki's
database through the load balancer, following one redirect. The result is held
in the WAN cache for an hour, and the hub wiki clears an entry when that page's
links are updated or the page is deleted.

When the page has no link to the hub, its own interlanguage links are used. When
the hub page does not exist, its own links are used without the dead hub link.
Only the languages in `$wgAltLangRelLinksWikis` get an alternate, so a link to a
language the farm does not run (an old `en:` link, say) never becomes one.
`x-default` points at the hub page.

### Canonical link

Every page with a wiki page behind it (not special pages) gets a
`<link rel="canonical">` with the page's canonical URL, computed the way core
does when `$wgEnableCanonicalServerLink` is on: the redirect target when the
page was reached through a redirect, no query string (so `?rdfrom=` and
`?oldid=` views point at the page), `action=` kept only for `history` and
`info`, and the language variant when the page language has one. A canonical
URL that someone else already set is kept. Nothing happens when core itself is
configured to add the link.

## Configuration

| Setting | Default | Meaning |
|---|---|---|
| `$wgAltLangRelLinksWikis` | `[]` | Language code => wiki ID (database domain) for each language wiki of the farm. Only these languages get an alternate. Empty keeps the old behaviour: every interlanguage link, unfiltered, no hub. |
| `$wgAltLangRelLinksHubLanguage` | `null` | The hub language. Must be a key of `$wgAltLangRelLinksWikis`. |
| `$wgAltLangRelLinksNamespaces` | `null` | Namespaces that get alternates. `null` means `$wgContentNamespaces`. |
| `$wgAltLangRelLinksXDefault` | `true` | Add `hreflang="x-default"` pointing at the hub page. |
| `$wgAltLangRelLinksCanonical` | `true` | Add the canonical link. |

Example for a farm with one database per language:

```php
$wgAltLangRelLinksWikis = [ 'he' => 'kz_he', 'ar' => 'kz_ar', 'ru' => 'kz_ru' ];
$wgAltLangRelLinksHubLanguage = 'he';
```

Every wiki in the farm must share one WAN cache for the hub wiki's
invalidation to reach the others; otherwise entries simply expire after an hour.

## Tests

```sh
# from the MediaWiki root
vendor/bin/phpunit extensions/WikiRights/AltLangRelLinks/tests/phpunit/unit
```

## Changelog

See [CHANGELOG.md](CHANGELOG.md) for version history and release notes.
