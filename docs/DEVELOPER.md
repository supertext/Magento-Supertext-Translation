# Developer guide — Supertext Translation for Magento

How the module works, how to run it locally, how it's tested, deployed and released.

## Architecture

Magento 2 module `Supertext_Translation` (PHP 8.2+, Magento Open Source / Adobe Commerce 2.4.7+, Mage-OS). The repository root is the module: Composer installs it from GitHub as `supertext/magento-supertext-translation`, a hand install copies it to `app/code/Supertext/Translation`.

```
├── registration.php, composer.json (version), etc/module.xml
├── lib/supertext-sdk/                    the Supertext SDK for PHP, vendored (don't edit; see *Supertext SDK*)
├── lib/supertext-plugin-health/          Supertext Plugin Health for PHP, vendored (don't edit; see *Supertext Plugin Health*)
├── Api/                                  no Magento classes (unit-tested on their own)
│   ├── Supertext.php                     the SDK client with the module's defaults, SDK errors → messages
│   ├── SupertextClients.php              direct client + review service client (Translation review)
│   ├── LanguageCodes.php                 locales → Supertext codes (the SDK's Languages + the store view setting; only the SDK's defaults)
│   ├── Reporting.php                     Commando Center: Plugin Health's InstallationReporter with the module's state
│   ├── ReportTransport.php               Plugin Health's HTTP over an SDK transport (the tests' fake)
│   └── SupertextException.php            translatable message (English template, %1 parameters, detail)
├── Model/
│   ├── Config.php                        settings, env variables, store views and their languages, version
│   ├── Reporting.php                     Commando Center glue: site URL, ping URL, state in the flag table, key check messages
│   ├── Translation/
│   │   ├── EntityTypes.php               what is translated: product, category, cms_page, cms_block
│   │   ├── FieldPlanner.php              which fields to send, what to write back (no Magento classes)
│   │   ├── Directives.php                protects {{…}} directives in rich text (no Magento classes)
│   │   ├── EavTranslator.php             products, categories: store-view values, URL key, rewrites
│   │   ├── CmsTranslator.php             pages, blocks: copies per store view + supertext_translation_link
│   │   ├── Translator.php                entry point for the admin and the console command (also importTexts() for files)
│   │   ├── FileExchange.php, FileItems.php   Export for translation / Import translation (no Magento classes)
│   │   └── TranslationFileItems.php      FileItems for Magento: Translator, store views, ACL
│   └── Config/Backend|Source/            API key (encrypted), Supertext language (list + saved code), option lists
├── Controller/Adminhtml/Translate/       Index (the page), Mass (grid mass action → page), Run (JSON), Export (file download), Import (JSON)
├── Controller/Adminhtml/System/Test.php  "Test connection" (the API key check)
├── Controller/Ping/Index.php             public GET /supertext/ping (Commando Center)
├── Observer/                             SettingsSaved (key check + report after a save), SettingsPage (onRequest)
├── Block/Adminhtml/                      translate page, edit-page buttons, config widgets (key check, store view overview, …)
├── Console/Command/TranslateCommand.php  bin/magento supertext:translate
├── etc/                                  acl, routes (admin, frontend), events, system.xml, config.xml, db_schema.xml, di.xml
├── view/adminhtml/
│   ├── ui_component/                     mass actions (product, cms_page, cms_block listings), form buttons
│   ├── layout/supertext_translate_index.xml
│   └── templates/                        translate.phtml (page + script), system/test-button.phtml
├── i18n/<locale>.csv                      German, French, Italian admin phrases (English is the source)
├── scripts/vendor-sdk.sh                 updates lib/supertext-sdk from the SDK repository
├── scripts/vendor-plugin-health.sh       updates lib/supertext-plugin-health from the Commando Center repository
├── tools/sync-translations.php           writes the regional copies (de_CH, fr_CH, it_CH, …)
└── Test/Unit/                            PHPUnit, runs without Magento (Test/bootstrap.php)
```

Entry points:

| Where | How |
| --- | --- |
| *Catalog → Products*, *Content → Pages*, *Content → Blocks* | `view/adminhtml/ui_component/*_listing.xml` adds the mass action `supertext/translate/mass/type/<type>`. `Mass` reads the selection with Magento's `Ui\Component\MassAction\Filter` (selected, excluded and filters) and redirects to `supertext/translate/index/type/<type>/ids/1,2` (100 items at most). |
| Edit pages (product, category, page, block) | `*_form.xml` adds a button (`Block\Adminhtml\Button\*`) that opens the translate page for the saved item. Categories have no grid, only this button. |
| Command line | `supertext:translate <product|category|cms_page|cms_block> <ids…> [--store=<code or id>]… [--overwrite]` |

The translate page (`Block\Adminhtml\Translate` + `translate.phtml`) shows the items and, per store view, a state from `FieldPlanner::state()`. Its script then posts to `Run` once per item and store view, one after the other (Supertext rate-limits per second), with `form_key`, `type`, `id`, `store`, `overwrite`. `Run` checks the ACL resource of the type (`Magento_Catalog::products`, `Magento_Catalog::categories`, `Magento_Cms::save`, `Magento_Cms::block`) on top of `Supertext_Translation::translate`.

### Translation files

*Export for translation* and *Import translation* on the translate page: the items' texts as an XLIFF 1.2 or HTML file, translated outside the API (a CAT tool, a translator, Supertext's web app), and the translated file written back. File formats, checksums and the safety checks of imported files are the SDK's `Supertext\Sdk\Files\TranslationFiles` (SDK 0.5.0, integration guide → *Translation files: XLIFF and HTML*); the module only collects, finds and writes back.

- **Core** (`Model/Translation/FileExchange.php`, no Magento classes, `Test/Unit/FileExchangeTest.php`): `export()` builds one SDK `FileJob` per item and store view from exactly the segments an API translation sends (`FileItems::segments()` = `Translator::fileSegments()` = `FieldPlanner::segments()` of `EavTranslator::planner()` / `CmsTranslator::planner()`: the same fields, rich text with its `{{…}}` directives protected, existing translations left out unless *overwrite*); keys are the attribute codes / CMS fields. XLIFF holds every selected item and store view; HTML exactly one item in one store view. `import()` refuses files over 10,000,000 bytes, `read()`s the file and per job checks `plugin` (`Magento-Supertext-Translation`, else *another system*), parses `reference` (else *no longer exists*), checks the store view exists and the admin's ACL for the type, loads the item (missing → *no longer exists*), collects the current segments the same way and calls `importTranslations()`. `ok` results (and `source_changed` with *include changed*) go through `Translator::importTexts()`, which runs `EavTranslator::translate()` / `CmsTranslator::translate()` with a closure returning the file's texts instead of the SDK client: the same write-back (store-view values via `saveAttribute`, URL key and rewrites, CMS copies per store view and `supertext_translation_link`, *disabled* for review). `untranslated`, `invalid_markup` and `missing` are only counted; *kept* counts the file's fields that got a translation since the export.
- **Reference** of a job: `<type>:<id>:store-<store view id>`, e.g. `product:42:store-2`, `cms_page:5:store-3` (`FileExchange::reference()` / `parseReference()`; ids, never titles or store codes).
- **Languages in the file:** source = `Config::sourceCodeOrNull()`, target = `Config::targetCodeOrNull($storeId)`; a store view with neither a Supertext language nor a default is written with its locale and `-` (`en_IE` → `en-IE`), so it never blocks the export.
- **File names:** `magento-<type>-<id>-store-<id>.xlf` / `.html`, `magento-<type>-<id>.xlf` for several store views, `magento-<type>-<id>-and-<n>-more.xlf` for several items. `Content-Type` `application/x-xliff+xml` / `text/html; charset=utf-8`, `Content-Disposition: attachment`.
- **Controllers:** `Export` (POST form with `form_key`, `type`, `ids`, `stores[]`, `format`, `overwrite`; the page's script copies the ticked store views and *Overwrite* into the form) answers the file or redirects back with the error; `Import` (POST `multipart/form-data` with `form_key`, `file`, `include_changed`, `overwrite`, sent by the page's script) checks the upload size before reading, reads PHP's temporary upload in memory and answers a JSON summary per job, which the script shows. Both need `Supertext_Translation::translate`; `Export` also the type's ACL, `Import` the ACL of each job's type.

### Languages

The source is always the **default values** (store id 0), in the language of the default locale (`general/locale/code` at default scope; only the language is sent, e.g. `en`). Targets are the store views: `supertext/language/code` (store scope, the *Supertext language*) if set, else the store view's `general/locale/code` when it equals a Supertext code, else the SDK's default for it (`es_ES` → `es`); see *Language codes*. Store views whose language equals the source's are listed but not ticked, and the console command skips them without `--store`; store views with neither a Supertext language nor a default can't be ticked. Tone: `supertext/language/tone` (store scope), sent only where `Languages::supportsPoliteness()` is true (`Config::tone()`).

### What is translated

From `Model/Translation/EntityTypes.php` (keep `docs/USER_GUIDE.md` → *What is translated* in sync):

| Type | Kind | Fields (rich text in bold) | URL |
| --- | --- | --- | --- |
| `product` | EAV | `name`, **`short_description`**, **`description`**, `meta_title`, `meta_keyword`, `meta_description` | `url_key` from `name` |
| `category` | EAV | `name`, **`description`**, `meta_title`, `meta_keywords`, `meta_description` | `url_key` (and `url_path`) from `name` |
| `cms_page` | CMS copy | `title`, `content_heading`, **`content`**, `meta_title`, `meta_keywords`, `meta_description` | same `identifier` |
| `cms_block` | CMS copy | `title`, **`content`** | same `identifier` |

**EAV (products, categories)** — `EavTranslator`:

- Reads values with the resource's `getAttributeRawValue($id, $codes, $storeId)`, which falls back to the default value: a store view without its own value returns the default, so it counts as untranslated (`FieldPlanner`: translated = has text that differs from the source, ignoring whitespace).
- Writes each translated attribute with `$resource->saveAttribute($object, $code)` on the object loaded with the store id — the same per-store write Magento uses, without the "save everything in store scope" side effect of a full `save()`.
- URL key: when the name was translated and the store view's URL key is still the default one (or with overwrite), sets `url_key` via `Product\Url::formatUrlKey()` (categories also `url_path` via `CategoryUrlPathGenerator`), then regenerates the store view's rewrites (`ProductUrlRewriteGenerator` / `CategoryUrlRewriteGenerator` → `UrlPersistInterface::replace()`; the old URL becomes a 301). If the URL already exists in the store view, the old key is restored and a note is returned.
- Then reindexes the product in `catalogsearch_fulltext` (unless the indexer is scheduled) and cleans the item's cache tags (`clean_cache_by_tags`).
- Children of a category keep their stored `url_path` until they are translated themselves: translate categories from the top down.

**CMS (pages, blocks)** — `CmsTranslator`:

- The translation of an original for a store view is found in `supertext_translation_link` (`entity_type`, `source_id`, `store_id` → `target_id`), else adopted: a page/block with the same identifier assigned only to that store view.
- New copy: all data of the original except ids, dates and stores, then the translated fields; `store_id` = the target store view; `is_active` = the original's, or 0 with *Status of new translations: Disabled*.
- Pages only: Magento allows one page per URL and store view (URL rewrites, `PageRepository` check), so the target store view is first removed from the original's stores (`[0]` becomes all other store views). If saving the copy fails, the original's stores are restored. Blocks don't need this: a store-view block wins over an *All Store Views* block with the same identifier.
- Adobe Commerce's `row_id` (content staging) is handled when looking up copies; saving goes through the repositories.

Each item × store view is one call to the SDK's `translateTexts()`: `FieldPlanner::segments()` gives one segment per field (plain text, or a whole rich-text field as one HTML segment), which the SDK packs into one document with a `<div data-st-id="N">` each. `Directives` wraps Magento directives in text (`{{widget …}}`, `{{config …}}`) in `<span translate="no" data-st-keep>` and `FieldPlanner::apply()` removes the wrapper afterwards; directives in attributes (`src="{{media url=…}}"`) are not touched. The SDK cuts the translated document apart as text, not re-serialised through `DOMDocument`, which would URL-encode such attributes and rewrite Page Builder's attribute quoting. If a field is missing from the translation, nothing is saved for that item (`incomplete_response`).

### Interface strings

Every phrase goes through Magento's own mechanism: `__()` in PHP and templates (including the texts the translate page's script uses, passed as `window.supertextTexts`), `translate="label comment"` in `system.xml`, `translate="title"` in `acl.xml`, `<label translate="true">` in UI components. Translations live in `i18n/<locale>.csv` (`"English","translation"`, placeholders `%1`, `%2`). Magento reads the file of the user's exact interface locale and doesn't fall back from de_CH to de_DE, so:

- Edit `de_DE.csv`, `fr_FR.csv` and `it_IT.csv` by hand (formal address: Sie, vous, Lei; Magento's own terms: *Store View* / *vue magasin* / *visualizzazione negozio*, *Stores → Konfiguration*, *Magasins → Configuration*, *Negozi → Configurazione*; never translate "Supertext", placeholders, tags or URLs), then run `php tools/sync-translations.php` to write de_AT, de_CH (ß → ss), fr_BE, fr_CA, fr_CH and it_CH.
- A new or changed phrase goes into all three files in the same commit. `Test/Unit/TranslationFilesTest.php` collects the phrases like `bin/magento i18n:collect-phrases` (`Test/ModuleStrings.php`) and fails if one is missing, unused, has different placeholders, or a regional copy is out of date.
- `SupertextException` keeps its English template and `%1` parameters (`template()`, `parameters()`) apart from Supertext's own detail (`detail()`); `Run` translates it with `__()`, so `Api/` and the models throw English and stay testable. The console command prints English.
- Errors from the SDK (`Supertext\Sdk\V1\SupertextException`, with an `errorCode`) are turned into a `SupertextException` by `Api\Supertext::error()`: one message per SDK error code (`unsupported_language` names the locale and where to map it), listed there as `['template', parameters]` so the phrase scanner finds them. `Translator` and `Api\LanguageCodes` do this. A new SDK error code needs an arm there and its phrase in the three edited CSV files (`SupertextTest::testEveryErrorCodeHasItsOwnMessage` checks).
- The API key check messages are in `Model\Reporting::describe()` (same `['template', parameters]` form, also scanned), with `%1`/`%2` for the links: the HTML version links *supertext.com* and *supertext.com → Integrations → API* in a new tab, the text version uses the bare URLs.

## Supertext API protocol

AI file translation API v1, same as the WordPress, PrestaShop and other Supertext plugins. The module doesn't implement it itself: the Supertext SDK does (see *Supertext SDK*). For reference:

1. `POST {base}/translate/ai/file` — multipart: `file` (`content.html`, part `Content-Type: text/html` exactly, or the API answers 415), `target_lang` (e.g. `de-CH`), `source_lang` (bare language, e.g. `en`; see *Language codes*), optional `politeness` (`more` formal, `less` informal). Answers `{"file_id": "…"}`.
2. `GET {base}/translate/ai/file/{id}/status` every 2 s until `done` (or `error`, `limit_exceeded`, `deleted`), up to the timeout (default 180 s).
3. `GET {base}/translate/ai/file/{id}/translation` — the translated HTML.
4. `DELETE {base}/translate/ai/file/{id}` — always, also after errors.

- Base URLs: live `https://api.supertext.com/v1/`, staging `https://api.staging.supertext.com/v1/`, testing `https://api.testing.supertext.com/v1/`, or a custom one (setting, or `SUPERTEXT_API_ENDPOINT`).
- Header `Authorization: Supertext-Auth-Key <key>` (the name must be `Authorization`). A pasted `Supertext-Auth-Key ` prefix is stripped, so exactly one is sent.
- HTTP 429 (`RATE_LIMIT_EXCEEDED`, per second per key) is retried up to 4 times, after `Retry-After` or 1/2/4/8 s with jitter.
- The API key check (*Save Config*, *Test connection*, see *Commando Center*) calls `GET {base}/features` (free).
- Documents stay below 900,000 characters (API limit 1,000,000); the SDK splits larger items into several documents.
- The SDK identifies the module in the `User-Agent` (`supertext-sdk-php Magento-Supertext-Translation/<version>`, the version from `composer.json` as in the reports) and keeps the key out of error messages.

**Translation review** (`supertext/language/review`, a Yes/No select per store view next to the *Supertext language*, default *No* in `etc/config.xml`; no migration, existing shops start with review off): `Translator::translate()` takes `Config::clients()->forReview(Config::review($storeId))`. `Api\SupertextClients` (no Magento classes) holds the direct client and, created on first use, a second SDK client with the same key, timeout, transport and User-Agent whose base URL is `Config::reviewUrl()`: `SUPERTEXT_REVIEW_URL` when not empty, else `supertext/api/review_url` (*Review service URL*, default `Supertext::REVIEW_URL`, the translation proxy of Supertext Localization Review). Every translation is one `translateTexts()` round trip (upload, status, download, delete) with one client, so a job is always collected with the client that submitted it. The proxy speaks the same API v1, forwards to the live Supertext API with the module's key, stores every segment per key and serves reviewers' corrections on the next upload, so nothing else changes in the module. A review URL that is empty or the same as the API's (`Supertext::reviewBaseUrl()`) means no second client. The key check, *Test connection*, reports and translation files never use the review service (they use `Config::baseUrl()`). The *Store views* overview marks store views with review on. Tests: `SupertextClientsTest` (review store views: all four calls to the review URL with the same key and User-Agent, others directly, one review client, empty or same URL turns it off, `etc/config.xml` defaults to Supertext's review service with review off).

### Supertext SDK

Supertext calls go through the [Supertext SDK for PHP](https://github.com/Supertext/Supertext-API-SDK-and-Guide/tree/main/sdk/php) 0.3.0, which provides the API client and the API key check (`Client::checkApiKey()`, `Protocol::apiKeyFingerprint()`; version reports are Supertext Plugin Health's, see below) (namespace `Supertext\Sdk`: the API classes in `Supertext\Sdk\V1`, the HTTP transport in `Supertext\Sdk\Http`), shared by all Supertext integrations and tested against the same spec vectors and stand-in. It isn't on Packagist yet, so it is vendored in `lib/supertext-sdk/` (`COMMIT` names the SDK commit, `README.md` where it comes from):

- `registration.php` loads `lib/supertext-sdk/autoload.php`, so the SDK works for Composer and app/code installs alike; `./build.sh` puts `lib/` in the zip, `phpstan.neon` scans it without analysing it.
- Reach it through `Api/Supertext.php` (`Supertext::client()`, `Config::client()`): it sets the base URL, the timeout and the `User-Agent`. Its built-in transport uses ext-curl, which Magento requires anyway; cURL honours `HTTPS_PROXY`.
- Never edit the vendored files: fix the SDK upstream, then update with `scripts/vendor-sdk.sh [commit-or-branch]` (default `main`; `SDK_DIR=/path/to/clone scripts/vendor-sdk.sh` uses a local clone as it is; it replaces the folder entirely) and run the tests. Once the SDK is on Packagist, require `supertext/supertext-sdk` in `composer.json` instead (keeping the autoloader for app/code installs).

### Supertext Plugin Health

[Supertext Plugin Health](https://github.com/Supertext/Commando-Center-Supertext-Translation/tree/main/plugin-health) 0.1.0 is the plugin side of the Supertext Commando Center: version reports, the report of the API key check result and the answer of the ping endpoint (`InstallationReporter`, `ReporterState`, `KeyCheck`, `PingResponse`, namespace `Supertext\PluginHealth`). It has no dependencies and doesn't need the SDK: `Api/Reporting.php` passes the SDK's key check in (`CallableKeyChecker` with `Client::checkApiKey()` and `Protocol::apiKeyFingerprint()`) and `Supertext::SDK_VERSION` as `sdkVersion`. It is vendored like the SDK in `lib/supertext-plugin-health/` (namespace unchanged; `VERSION`, `COMMIT` and `README.md` say where it comes from):

- `registration.php` (and `Test/bootstrap.php`) load `lib/supertext-plugin-health/autoload.php`, written by the vendor script; `./build.sh` puts it in the zip with `lib/`, `phpstan.neon` scans it without analysing it.
- Its stored state is the same JSON the SDK 0.1–0.2 reporter stored, so shops that update keep their history (no new `installed` report). Its built-in transport uses ext-curl; in the tests `Api/ReportTransport.php` runs its reports through the same fake SDK transport as the key check.
- Never edit the vendored files: fix it in Commando-Center-Supertext-Translation → `plugin-health/`, then update with `scripts/vendor-plugin-health.sh <tag>` (e.g. `plugin-health-v0.1.0`; default `main`; `PLUGIN_HEALTH_DIR=/path/to/clone` uses a local clone as it is; it replaces the folder entirely) and run the tests.

### Language codes

Supertext accepts exactly the codes its `GET features` lists (the SDK's `Supertext\Sdk\V1\Languages`, from the live API): bare sources (`en`, `de`); targets in one exact form each, regional for German, English, French, Italian and Portuguese (`de-CH`, `en-US`, `pt-BR`), with a script for Chinese and Serbian (`zh-Hans`, `sr-Latn`), a dialect for Swiss German (`gsw-u-sd-chzh`), bare for the others (`es`, `nl`, `sl`). Anything else is HTTP 404 `INVALID_LANGUAGE_PAIR`; formal/informal for a target without it (`en-US`, `rm`, …) is HTTP 400 `FEATURE_ERROR` (`unsupported_feature`).

`Api/LanguageCodes.php` (unit-tested, no Magento classes) turns a store view into Supertext codes with the SDK's `Languages::target()`/`source()`, the store view's *Supertext language* (`supertext/language/code`, store scope) being the SDK's mapping. The setting is a Magento select: `Model\Config\Source\TargetLanguage` lists `LanguageCodes::options()` (the SDK's `targetOptions()` as *Name (code)*, the name from intl's `Locale::getDisplayName()` in the admin's interface locale, English for English and the Swiss German dialects, sorted with `Collator`); `Block\Adminhtml\System\Config\LanguageSelect` relabels the empty option for the store view shown (`Languages::suggestTarget()` of its locale: *Default: Spanish (es)*, or *– Choose –* when there is none); `Model\Config\Backend\LanguageCode` accepts only codes from the list (stored as Supertext writes them), except an unchanged older value.

- **Target** (`Config::targetCode()`): the setting (sent as it is, even a code the SDK's list doesn't know); else the locale when it equals a Supertext code apart from case and `_`/`-` (`de_CH` → `de-CH`, `fr_FR` → `fr-FR`); else the SDK's default (`defaultTarget()`: a region of a bare target → the bare target, `es_ES` → `es`, `nb_NO` → `nb`; `zh_Hans_CN` → `zh-Hans`, `zh_Hant_TW` → `zh-Hant`, `sr_Latn_RS` → `sr-Latn`; bare `de`/`en`/`fr`/`it`/`pt` → `de-DE`/`en-US`/`fr-FR`/`it-IT`/`pt-PT`); else `SupertextException` from the SDK code `unsupported_language` (*The language "%1" has no Supertext language yet. … in the Supertext language list …*), and nothing is sent. Regions Supertext doesn't offer (`en_IE`, `de_LI`, `fr_BE`, `sr_RS`) are never guessed. An empty setting therefore means "the default"; there is no stored copy of it, so a later SDK default applies without migration. `Translator` resolves both codes before it creates the client.
- **Source** (`Config::sourceCode()`): the default locale's language (`en_US`, `en_GB` → `en`); one mapping serves both directions, so the default store view's setting counts when that store view has the default locale (`de-CH` → `de`). Unknown: `unsupported_language` with `sourceLang`.
- `Config::storeViews()` gives each store view's `target` (null only without setting, exact match or default) and `origin` (`setting`, `locale` or `default`), which the *Store views* overview (`Block\Adminhtml\System\Config\LanguageOverview`, default scope), the store-view field's note (`LanguageSelect`, which also keeps a saved code that isn't in the list as an extra option) and the translate page (unmapped store views can't be ticked) show.
- Tone: `Config::tone()` sends `more`/`less` only where `LanguageCodes::supportsPoliteness()` is true; elsewhere `default`, and the overview says so.
- Translating within one language (source `en`, target `en-GB`) is allowed.

The stand-in (`tests/docs/stand-in.mjs`) accepts only Supertext's target codes and bare sources, like the live API.

### Commando Center: version reports, key check, ping

Protocol: [Commando-Center-Supertext-Translation](https://github.com/Supertext/Commando-Center-Supertext-Translation) → `docs/DEVELOPER.md` → *Report protocol*. Supertext Plugin Health's `InstallationReporter` implements it, with the SDK's `checkApiKey()` as the key check; `Api/Reporting.php` (no Magento classes) loads the state, runs one action and stores the new state, and never throws (storage errors included). `Model/Reporting.php` gives it what Magento knows:

- **Setting:** `supertext/api/version_reports` (*Send version information to Supertext*, Yes/No, default Yes in `etc/config.xml`). The environment variable `SUPERTEXT_VERSION_REPORTS` wins (`InstallationReporter::reportsEnabled()`); `Block\Adminhtml\System\Config\VersionReports` then disables the field and says so, and shows the ping URL while reports are on. Off: no reports, ping 404. `SUPERTEXT_REPORT_URL` sends reports elsewhere (CI, screenshots: the stand-in's `POST /report`).
- **Report fields:** plugin `Magento-Supertext-Translation` (the GitHub repository name) with the version from `composer.json` (`PackageInfo`, `Config::version()`), system `ProductMetadataInterface::getName()` (`Magento`) and `getVersion()`, runtime PHP, `sdk_version` = the bundled SDK's `Supertext::SDK_VERSION`; `site_url` = the default store view's base URL (`getBaseUrl(URL_TYPE_WEB, isFrontUrlSecure())`); `callback.url` = its link URL (`URL_TYPE_LINK`, with `index.php/` when URL rewrites are off) + `supertext/ping`.
- **When:** `afterSettingsSaved` on `admin_system_config_changed_section_supertext` (`Observer\SettingsSaved`, any scope; its key check becomes the success or warning message, HTML with the links); `testConnection` for *Test connection* (`Controller\Adminhtml\System\Test`, returns the message as text and HTML); `onRequest` when the configuration section is opened (`Observer\SettingsPage` on `controller_action_predispatch_adminhtml_system_config_edit`), on the *Translate with Supertext* page, on every `Run` and in `supertext:translate`; `authenticationFailed` when a translation gets 401/403 (`Translator`). In web requests `Model\Reporting` runs `onRequest` and `authenticationFailed` in a shutdown function that first calls `fastcgi_finish_request()` where available (under PHP-FPM after the response, under mod_php at the end of the request), at most once per request; on the command line right away. `onRequest` only makes a request when the key changed or a report is due. Never on storefront pages.
- **State:** the reporter's JSON (reported versions, last key status, last failure, the latest key check with the key's SHA-256 fingerprint; never the key) in Magento's `flag` table, code `supertext_reporter` (`FlagManager`). The configuration's *API key check* field (`Block\Adminhtml\System\Config\KeyCheck`) shows the latest check with its time.
- **Ping:** `Controller/Ping/Index.php` on the frontend route `supertext` (`etc/frontend/routes.xml`): an `HttpGetActionInterface` that returns a raw result with exactly `InstallationReporter::pingResponse()`'s status, headers (`Content-Type: application/json`, `Cache-Control: no-store`, so the full-page cache and Varnish never keep it) and body. No login, no form key; other methods get Magento's 404.

## Local development

Mage-OS installs without Adobe Marketplace keys (Magento Open Source from `repo.magento.com` needs them). With Docker for MySQL and Elasticsearch:

```bash
docker run -d --name mysql -p 3306:3306 -e MYSQL_ROOT_PASSWORD=root -e MYSQL_DATABASE=magento mysql:8.4
docker run -d --name es -p 9200:9200 -e discovery.type=single-node -e xpack.security.enabled=false elasticsearch:8.17.10
composer create-project --repository-url=https://repo.mage-os.org/ mage-os/project-community-edition:3.6.0 magento
cd magento
bin/magento setup:install --base-url=http://127.0.0.1:8082/ --db-host=127.0.0.1 --db-name=magento --db-user=root \
  --db-password=root --search-engine=elasticsearch8 --elasticsearch-host=127.0.0.1 --admin-user=admin \
  --admin-password=… --admin-email=… --admin-firstname=Local --admin-lastname=Admin --language=en_US --use-rewrites=1
mkdir -p app/code/Supertext && cp -R /path/to/this/repo app/code/Supertext/Translation
bin/magento module:enable Supertext_Translation && bin/magento setup:upgrade
bin/magento module:disable Magento_TwoFactorAuth && bin/magento setup:upgrade      # local only
DEMO_ADMIN_EMAIL=… DEMO_ADMIN_PASSWORD=… DEMO_EDITOR_EMAIL=… DEMO_EDITOR_PASSWORD=… php /path/to/this/repo/demo/setup.php "$PWD"
PHP_CLI_SERVER_WORKERS=8 php -S 127.0.0.1:8082 -t pub/ phpserver/router.php
```

Admin: http://127.0.0.1:8082/admin/. Copy the module rather than symlinking it: Magento refuses templates outside its root. After changing PHP files, flush the cache; after changing `etc/*.xml` or constructors, also run `setup:upgrade` (or delete `generated/code/Supertext`). Static admin files are generated on demand; with the PHP server, deploy them once (`bin/magento setup:static-content:deploy -f --area adminhtml en_US`) to keep pages fast.

To translate without a Supertext key, run the stand-in API (`node tests/docs/stand-in.mjs`, any key) and set `SUPERTEXT_API_ENDPOINT=http://127.0.0.1:8765/v1/` for the PHP server and the console. Also set `SUPERTEXT_REPORT_URL=http://127.0.0.1:8765/report`, so version reports go to the stand-in (`GET /reports` lists them) instead of the live Commando Center.

## Tests

```bash
php phpunit.phar        # PHPUnit 10 (phar): SDK use and error messages, field planner, language codes, reporting, i18n files — no Magento needed
./build.sh              # dist/magento-supertext-translation-<version>.zip
```

The module's `composer.json` requires `magento/*` packages, which aren't on Packagist, so the unit tests don't use `composer install`: `Test/bootstrap.php` autoloads the Magento-free classes, the vendored SDK and Plugin Health. The protocol itself (multipart, polling, retries, packing) is tested in the SDK's repository.

CI (`.github/workflows/ci.yml`):

- **unit** (PHP 8.2, 8.3, 8.4, 8.5): lint (`.php`, `.phtml`), PHPUnit, XML well-formedness, `sh -n demo/entrypoint.sh`, `./build.sh`.
- **phpstan**: PHPStan on the module's code with Mage-OS's classes, once with Mage-OS 3.6.0 and once with 4.0.0 (see *Code quality and security checks*).
- **magento** (Mage-OS 3.6.0 and 4.0.0): downloads Mage-OS, installs it with MySQL 8.4 and Elasticsearch 8, adds the module in `app/code`, runs `demo/setup.php` twice (the second run must change nothing, and no password may appear in the log), translates the sample product, category, page and block with `supertext:translate` against the stand-in and checks the store values, URL key and rewrite, page copy with Page Builder markup, block directives, link table and accounts; a second run must skip everything. Then it checks the Commando Center: exactly one `installed` report (plugin, key check `ok`, callback URL; never the key) reached the stand-in's fake center, the state is in the `flag` table, and the storefront's ping (through PHP's built-in server and `phpserver/router.php`) answers the challenge with `Cache-Control: no-store` (400 for a bad challenge).

Unit tests worth knowing: `FileExchangeTest` (translation files: reference and file names, export with the planner's segments and the codes, HTML with one item and one store view, errors, import with another plugin, an unknown reference or store view, no ACL, `ok` written through the planner with directives unprotected, `source_changed` only with the option, `untranslated`/`invalid_markup`/`missing` never written, kept fields, a save error, files too large, invalid or without metadata), `LanguageCodesTest` (exact matches, the SDK defaults, no guessing without a default, the select's options and localized names, the setting wins, sources, `unsupported_language`), `ReportingTest` (the report and the stored state never contain the key, the SDK version reported, a state stored by the SDK 0.2 reporter kept without a new report, `installed` → `settings_saved`, `upgraded` only after a version change, a 401 reported once without a request to Supertext, nothing sent with reports off, storage errors swallowed, ping answers, `SUPERTEXT_VERSION_REPORTS`), `SupertextClientsTest` (Translation review, see *Supertext API protocol*), `SupertextTest` (codes sent as given, one message per SDK error code).

## Demo (Railway)

`demo/` is a Mage-OS 3.6 shop with English sample content and store views for German (Switzerland), French (Switzerland) and Italian (Switzerland), plus this module. The Railway service *Magento* (project *supertext-cms-demos-php*) builds `demo/Dockerfile` with the repository root as context, from `main`. Railway no longer reads `railway.json`, so the Dockerfile path, healthcheck (`/health`, a static file) and restart policy are set on the service itself; `railway.json` documents the same values.

The image downloads Mage-OS with Composer at build time (no keys needed), copies the module to `app/code`, enables all modules except `Magento_TwoFactorAuth` (demo convenience: Supertext staff log in with the `DEMO_*` accounts only) and runs Apache with `pub/` as document root. The container keeps **no files** between deploys:

- The shop is in MySQL: `DATABASE_URL` (`mysql://…`, the shared Railway MySQL 8.4 service, as root so the demo can create its database) and its own database `MAGENTO_DB_NAME` (default `magento`).
- `app/etc/env.php` (database access and Magento's encryption key) is stored in that database (`supertext_demo_state`, `demo/state.php`) and restored on every start.
- The search index lives in the shared Elasticsearch 8 service (`ELASTICSEARCH_HOST`, index prefix `supertext_magento_demo`); it has no volume either, so every start reindexes.
- On the **first start** (empty database) `demo/entrypoint.sh` runs `setup:install` with a throwaway admin (random name and `@supertext-demo.invalid` address and password, never shown). The `DEMO_*` accounts replace it: `demo/setup.php` deletes it as soon as the `DEMO_ADMIN` account exists.
- On **every start** the entrypoint restores `env.php`, runs `setup:upgrade` (module updates), sets the base URLs to the public domain (`MAGENTO_DOMAIN`, else `RAILWAY_PUBLIC_DOMAIN`; HTTPS except for localhost) and the search engine, turns off the admin captcha, runs `demo/setup.php` and reindexes. `demo/setup.php` adds the store views (`de_ch`, `fr_ch`, `it_ch` with their locales), the sample content (`demo/sample-content.json`, with a Page Builder page and a block with directives), the role *Supertext editor* and the accounts, if missing. It never changes existing content, translations or accounts.
- Uploaded images and generated static files are lost on redeploy (the sample products have no images). Every push to `main` deploys the demo; if one doesn't, check Railway's GitHub access and reconnect the service's source once.

Variables (Railway service variables; template in `demo/.env.example`):

| Variable | Purpose |
| --- | --- |
| `DATABASE_URL` | MySQL server, `mysql://user:password@host:port/any`. On Railway: `mysql://root:${{MySQL-8.MYSQL_ROOT_PASSWORD}}@${{MySQL-8.RAILWAY_PRIVATE_DOMAIN}}:3306/mysql` |
| `MAGENTO_DB_NAME` | The demo's database on it (default `magento`) |
| `MAGENTO_DOMAIN` | Public host name; default `RAILWAY_PUBLIC_DOMAIN` |
| `ELASTICSEARCH_HOST`, `ELASTICSEARCH_PORT`, `ELASTICSEARCH_INDEX_PREFIX` | Search engine; default `elasticsearch.railway.internal`, `9200`, `supertext_magento_demo` |
| `SUPERTEXT_API_KEY` | Supertext API key. No Supertext account yet? Create one at https://www.supertext.com/person/en/account/signin. Generate your API key at https://www.supertext.com/en/integrations/api (requires the Admin role). |
| `SUPERTEXT_API_ENDPOINT` | Optional: another API, e.g. the stand-in (to try the review service, set a store view's *Translation review* to *Yes* instead) |
| `SUPERTEXT_REVIEW_URL` | Optional: another review service than Supertext's (wins over *Review service URL*; empty keeps the setting) |
| `SUPERTEXT_VERSION_REPORTS` | Optional: `0` turns *Send version information to Supertext* off (wins over the setting) |
| `SUPERTEXT_REPORT_URL` | Tests only: where version reports go (the stand-in's `http://127.0.0.1:8765/report`) |
| `DEMO_ADMIN_EMAIL`, `DEMO_ADMIN_PASSWORD` | Admin in the *Administrators* role. `MAGENTO_ADMIN_EMAIL` / `MAGENTO_ADMIN_PASSWORD` are read as fallbacks. |
| `DEMO_EDITOR_EMAIL`, `DEMO_EDITOR_PASSWORD` | Editor for tests and screenshots. Magento has no editor role out of the box, so the demo creates the role **Supertext editor**: dashboard, products, categories, pages, blocks, media gallery and *Translate with Supertext* (no configuration, sales or system). |

- Accounts log in with their **email address as user name**. They are created only if that user name or email doesn't exist yet; existing accounts are never modified (no password resets).
- A password that fails Magento's rules (at least 12 characters with letters and digits in Mage-OS 3) skips that account with a warning in the log naming the rule; the demo still starts. The log names variables, never passwords.

Build and run it locally:

```bash
docker build -f demo/Dockerfile -t supertext-magento-demo .
docker run --rm -p 8091:80 -e MAGENTO_DOMAIN=localhost:8091 \
  -e DATABASE_URL=mysql://root:root@host.docker.internal:3306/mysql -e ELASTICSEARCH_HOST=host.docker.internal \
  -e DEMO_ADMIN_EMAIL=… -e DEMO_ADMIN_PASSWORD=… -e DEMO_EDITOR_EMAIL=… -e DEMO_EDITOR_PASSWORD=… \
  supertext-magento-demo
# shop http://localhost:8091/  admin http://localhost:8091/admin/
```

The first start takes a few minutes (Magento's installer and the first reindex); later starts about a minute.

## Docs screenshots

`tests/docs/screenshots.mjs` regenerates `docs/images/` with Playwright from a freshly started demo (empty database) whose module talks to `tests/docs/stand-in.mjs`. The stand-in answers like the Supertext API and returns real German, French and Italian for the sample content (`tests/docs/samples.json`, keyed by the HTML the module sends, directives protected); the settings screenshots show the live API, because the endpoint comes from `SUPERTEXT_API_ENDPOINT`. The script works with Magento's secret keys in admin URLs: it opens pages from the admin menu's links and adds `/store/<id>/` before the key for a store view's scope.

```bash
cd tests/docs && npm install && npx playwright install chromium
node stand-in.mjs &
# a fresh demo running with SUPERTEXT_API_ENDPOINT=http://127.0.0.1:8765/v1/,
# SUPERTEXT_REPORT_URL=http://127.0.0.1:8765/report (version reports go to the stand-in),
# no SUPERTEXT_API_KEY (the script enters a key) and DEMO_* set, on port 8091:
BASE_URL=http://localhost:8091/admin DEMO_ADMIN_EMAIL=… DEMO_ADMIN_PASSWORD=… \
  DEMO_EDITOR_EMAIL=… DEMO_EDITOR_PASSWORD=… node screenshots.mjs
```

`ONLY=admin` or `ONLY=editor` runs one part. Run it in the same commit as any UI change the images show.

## Demo movie

`docs/images/demo.gif` (shown at the top of the README) is recorded by `tests/docs/movie.mjs` with Supertext's movie kit (`tests/docs/movie/`: Playwright screencast → ffmpeg; intro and outro cards, captions, cursor; Inter font under the SIL Open Font License in `movie/assets/fonts/`). Like the screenshots, it needs a demo whose module talks to the stand-in API, `ffmpeg` and, for the voice, Piper:

```bash
pip install piper-tts                       # the voice; without it (or with MOVIE_VOICE=0) the movie is silent
cd tests/docs && npm install
node stand-in.mjs &                         # http://127.0.0.1:8765/v1/
# a fresh demo as for the screenshots (SUPERTEXT_API_ENDPOINT=http://127.0.0.1:8765/v1/,
# SUPERTEXT_REPORT_URL=http://127.0.0.1:8765/report, no SUPERTEXT_API_KEY), on port 8091:
BASE_URL=http://localhost:8091/admin DEMO_ADMIN_EMAIL=… DEMO_ADMIN_PASSWORD=… npm run movie:install   # enters the key
BASE_URL=http://localhost:8091/admin DEMO_EDITOR_EMAIL=… DEMO_EDITOR_PASSWORD=… npm run movie
```

**Demo movie** (`npm run movie`, about 48 seconds, 1280×720, an English voice and English captions): the demo editor opens the English product *Dark chocolate praline box*, clicks **Translate with Supertext** on its edit page, keeps *Deutsch (Schweiz)* and *Français (Suisse)* (unticks Italian), clicks **Translate** (the waiting time is cut), opens the product again and switches the scope to *Deutsch (Schweiz)*. It needs an API key in the configuration (the install movie or the screenshots enter one; the stand-in accepts any). The script writes the GIF (800 px, 12 fps, keep it under 3 MB) to `docs/images/demo.gif` and the MP4 to `tests/docs/movie/out/Magento-Supertext-Translation.mp4` (git-ignored; `MOVIE_OUT` changes the path). The MP4 is not committed; the GIF is silent. Re-record it in the same commit when the translate page changes.

**Install and configuration movie** (`npm run movie:install`, about 93 seconds, MP4 only): the same kit, voice and look, for administrators, signed in as the demo **administrator** on a demo without an API key. A terminal (`kit.terminal()`) with the commands from `docs/INSTALLATION.md` (`composer config repositories…`, `composer require`, `module:enable`, `setup:upgrade`, `cache:flush`; output shortened), then *Stores → Configuration → Supertext → Translation*: where the key comes from (supertext.com → Integrations → API; create an account at supertext.com), the made-up key `st-demo-0000-0000-0000` in **Supertext API key**, **Save Config** and *API key checked: the connection to Supertext works.*, the *Store views* overview under **Language**, the scope *Deutsch (Schweiz)* with **Supertext language** *German (Switzerland) (de-CH)* and **Translation review** left at *No*, **Save Config**, and where editors find **Translate with Supertext** (*Catalog → Products* → *Actions*). The script keeps the *Supertext connection* group's lower part (with the ping address, a local URL in the demo) out of view. The MP4 goes to `tests/docs/movie/out/Magento-Supertext-Translation-install.mp4` (git-ignored, not committed; `MOVIE_OUT` changes the path). Re-record it when the installation steps or the *Supertext* configuration change.

Page loads are cut from both movies (Magento's admin is slow on a local PHP server). The voice model (63 MB, pinned revision, checked by SHA-256) is downloaded once into `tests/docs/movie/voice-cache/` (git-ignored, never committed); the time spent generating speech is cut too. `CHROMIUM_PATH` points the scripts at another Chromium than Playwright's.

Voice: Piper voice “alba” (en_GB), trained on a dataset from the University of Edinburgh's Centre for Speech Technology Research, CC BY 4.0 (https://creativecommons.org/licenses/by/4.0/)

## Code quality and security checks

Before starting work in this repo, look at its open findings: code scanning alerts, secret scanning alerts, Dependabot PRs and the "Broken links in the docs" issue.

- **Checks** (`.github/workflows/checks.yml`): actionlint and zizmor lint the workflows on every push and pull request; dependency review fails a pull request that adds a package with a known vulnerability (moderate or worse). Third-party actions are pinned to commit SHAs (Dependabot keeps them current).
- **Links** (`.github/workflows/links.yml`): lychee checks the links in all Markdown files weekly and when docs change on `main`. Broken links open (or update) the issue "Broken links in the docs"; links that can't work from CI go in `.lycheeignore` (one regex per line).
- **PHPStan** (job **phpstan** in `ci.yml`, configuration `phpstan.neon`): level 5 on `Api/`, `Block/`, `Console/`, `Controller/`, `Model/`, `Observer/` and `registration.php` (not the templates, `demo/` or the tests). PHPStan has to know Magento's classes, so it runs from a Mage-OS root (no database needed) with [bitexpert/phpstan-magento](https://github.com/bitExpert/phpstan-magento), which also understands the generated factories and proxies. Locally:

  ```bash
  composer create-project --no-dev --repository-url=https://repo.mage-os.org/ mage-os/project-community-edition:3.6.0 /tmp/mageos
  cd /tmp/mageos
  composer config allow-plugins.phpstan/extension-installer true
  composer require --dev --with-all-dependencies phpstan/phpstan:^2.1 phpstan/extension-installer bitexpert/phpstan-magento
  vendor/bin/phpstan analyse -c /path/to/magento-supertext-translation/phpstan.neon --memory-limit=2G
  ```

  Known findings that aren't fixed yet go in `phpstan-baseline.neon` (add `--generate-baseline /path/to/magento-supertext-translation/phpstan-baseline.neon`); it holds 20 findings in 9 groups that come from Magento's own typing (repository `save()` taking the model, `getResource()->saveAttribute()`, deprecated `getResource()`, `Zend_Db_Select` from `having()`, `Phrase` titles). Fix new findings instead of adding them.
- **GitHub settings** (set by Remy's setup script, not in the repo): secret scanning with push protection (a push containing a known token format is rejected; findings under *Security → Secret scanning*) and CodeQL default setup (findings under *Security → Code scanning* and as pull request comments). CodeQL doesn't cover PHP, which is why this repo runs PHPStan.
- `.github/workflows/support-triage.yml`: support requests from the Supertext integrations website arrive as issues labelled `support` (contact details stay in the Supertext CMS). Claude classifies each one: answers questions (marked `<!-- supertext:reply -->`, emailed to the sender by the CMS) and closes them, fixes bugs as pull requests for review (never merged automatically), labels feature requests `feature-request`. Only people with triage rights can add labels, so outsiders can't start it. Needs the organisation secret `ANTHROPIC_API_KEY`; the workflow is the same in every Supertext repo.

## Dependency updates

Dependabot (`.github/dependabot.yml`) opens weekly pull requests: minor and patch updates grouped into one, GitHub Actions in another, each major update on its own.

`.github/workflows/dependabot-merge.yml` merges a minor or patch package update by itself once every other check on its pull request has passed (it waits up to 85 minutes and leaves the pull request alone if a check fails). GitHub Actions updates and majors stay for a person: the workflow's built-in token can't merge changes to workflow files, and a major needs a decision. Merges by the workflow don't start CI on `main`; the weekly health check looks at `main`.

## Releasing

Releases are published by `.github/workflows/release.yml` when the version is officially bumped; nobody tags or creates releases by hand.

1. Check that the unit tests, PHPStan (CI job **phpstan**) and `./build.sh` pass.
2. Move the *Unreleased* entries in `CHANGELOG.md` under a new `## X.Y.Z — YYYY-MM-DD` section, and keep an empty *Unreleased* above it.
3. Set the same version in `composer.json` (`version`). Magento reads it through `PackageInfo`: the configuration page shows it and links it to the GitHub release, and the console command prints it.
4. Push to `main`. The workflow checks that `composer.json` matches `CHANGELOG.md`, then tags `vX.Y.Z` and creates the GitHub release with the CHANGELOG section as notes (0.x versions as pre-releases). A push that adds no new version does nothing, and a version that is already released is skipped. After fixing a failed run, start it again with *Run workflow* on the *Release* workflow.

The release attaches `magento-supertext-translation-X.Y.Z.zip`, built by `./build.sh` (for `app/code/Supertext/Translation`). Composer installs use the tag.

## Conventions

- Magento coding standard (PSR-12 based), constructor property promotion, `declare(strict_types=1)`.
- Keep `Api/` and `Model/Translation/FieldPlanner.php` free of Magento classes so they stay unit-testable.
- User-visible strings go through `__()` (or `translate` in XML), with German, French and Italian in `i18n/` (see *Interface strings*); the API client and models throw English templates that the controllers translate.
- New settings go in `etc/adminhtml/system.xml`, `etc/config.xml` (default), `Model/Config.php` and the settings table in `docs/INSTALLATION.md`.
- Keep the three docs in `docs/` current with every change (see `CLAUDE.md`).

## Known limitations / roadmap

- Translation files: no console commands for export and import yet; directives (`{{…}}`) in rich text reach translators as protected inline tags in XLIFF and as `translate="no"` spans in HTML, but a translator who edits them changes them.
- The source is always the default values (*All Store Views*); translating from one store view into another isn't offered.
- Not translated yet: other product attributes (custom text attributes, dropdown option labels), image labels, custom options, widget instances, emails.
- Subcategory URL paths in a store view follow a translated parent only once the subcategory is translated too.
- Mage-OS's own automatic translation module, when switched on with periodic re-translation, can overwrite store-view values.
- Translation runs in the browser, one item and store view at a time; selections are cut to 100 items. A queue (Magento's message queue) would allow more.
- Professional (human) translation orders, as in the WordPress plugin, are not offered.
- Re-translating with *Overwrite* replaces manual changes; no change detection. A translation memory and reviewers' corrections come from the review service when a store view has *Translation review* on; corrections reach Magento only with the next translation (no push).
- The *Supertext language* list is the SDK's copy of Supertext's `features` list (October 2026) with English language names; a language Supertext adds later appears with the next SDK update (a code saved before is kept and sent as it is).
- Under mod_php (no `fastcgi_finish_request()`), the automatic key check and a due version report run at the end of the request instead of after the response; they only make a request when the key changed or a report is due (at most 10 s for the check, 3 s for the report).
- The docs screenshots of the configuration (`module-settings.png`, `module-language-store.png`) still show version 0.1 (free-text language code, *Connected* message, no *Translation review*, no *Review service URL*); regenerate them with `tests/docs/screenshots.mjs` against a fresh demo.
