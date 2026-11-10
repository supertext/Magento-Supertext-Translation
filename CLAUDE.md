# Working on this repository

Part of Supertext's translation plugins project: Supertext AI translation for the top open source CMS, PIM and shop systems. Each system has its own repo named `Supertext/<System>-Supertext-Translation`. This one is the **Magento** module (PHP, Magento 2 module `Supertext_Translation`; the repository root is the module). It works with Magento Open Source, Adobe Commerce and Mage-OS; local development, CI and the demo use Mage-OS, which installs without Adobe Marketplace keys.

## Documentation rule (always)

Every plugin repo keeps three guides, and **every change that affects behaviour, settings, installation or the code structure updates them in the same commit**:

| File | Audience | Must cover |
| --- | --- | --- |
| `docs/INSTALLATION.md` | Administrators | Requirements, install/update/uninstall, API key, language setup, all settings, troubleshooting |
| `docs/USER_GUIDE.md` | Editors | How to translate and review in the CMS's own UI, what is and isn't translated, what errors mean |
| `docs/DEVELOPER.md` | Developers | Architecture, Supertext API protocol, local setup, tests, CI/deploy, releasing, known limitations/roadmap |

Also: `README.md` stays a short overview linking the three guides, and `CHANGELOG.md` gets an entry under *Unreleased* for every user-visible change. Before finishing any task, check the docs still match the code.

## Supertext account and API key links (always)

Everywhere an administrator enters or is told about the API key — the settings field's help text, the "no API key" / "authentication failed" messages, `docs/INSTALLATION.md`, `README.md` and the demo's `.env.example` — show both links (same as the WordPress plugin):

- Create a Supertext account (or log in): https://www.supertext.com/person/en/account/signin
- Generate the AI API key: https://www.supertext.com/en/integrations/api (supertext.com → Integrations → API; requires the **Admin** role)

Wording: "No Supertext account yet? Create one at supertext.com. Generate your API key at supertext.com → Integrations → API (requires the Admin role)." In the UI, links open in a new tab (`target="_blank" rel="noopener"`); where the CMS shows plain text only, use the bare URLs. New screens or messages that mention the key get the links too.

## UI languages (always)

The plugin's own UI (buttons, panels, dialogs, settings, permissions, messages) is available in English, German, French and Italian through the CMS's own translation mechanism, so it follows the user's back-end language. New or changed strings get all four languages in the same commit. Formal address (Sie, vous, Lei), the CMS's own terms in each language, "Supertext", placeholders and URLs never translated.

## Plugin list (always)

`README.md` ends with the shared list of all Supertext plugins (between the `<!-- supertext-plugins:start -->` and `<!-- supertext-plugins:end -->` markers). It is identical in every Supertext plugin repo: when a plugin is added, renamed or its description changes, update the list in **all** repos, not just this one.

## Releases (always)

Releases are published by `.github/workflows/release.yml`: never tag or create a GitHub release by hand. To release, follow `docs/DEVELOPER.md` → *Releasing* (new version section in `CHANGELOG.md`, same number in `composer.json`) and push to `main`. A push without a new version releases nothing. The configuration page shows the version from `composer.json` via Magento's `PackageInfo` (never a second copy) and links it to the release.

## Repo setup (always)

Every Supertext plugin repo has, and a new one gets from the start:

- `LICENSE` matching the license its manifest declares (`composer.json`, `package.json`, `pyproject.toml`, `.csproj`, plugin header).
- `SECURITY.md`: report vulnerabilities privately through GitHub's private vulnerability reporting or support@supertext.com, never in public issues.
- `.github/dependabot.yml`: weekly updates for its package ecosystem and GitHub Actions, minor and patch updates grouped into one pull request.
- `.github/workflows/checks.yml` (actionlint + zizmor on every push and PR, dependency review on PRs) and `.github/workflows/links.yml` (lychee weekly and on docs changes; broken links open the issue "Broken links in the docs"). Third-party actions are pinned to commit SHAs.
- `.github/workflows/dependabot-merge.yml`: merges Dependabot's minor and patch package updates once all other checks pass (built-in token, so GitHub Actions updates and majors stay for a person). See `docs/DEVELOPER.md` → *Dependency updates*.
- PHP repos: PHPStan in CI (`phpstan.neon`, baseline in `phpstan-baseline.neon`).
- On GitHub: the About box filled in (one-sentence description, website https://www.supertext.com, topics), `main` protected against force-pushes and deletion, Wiki and Projects off, Dependabot alerts and private vulnerability reporting on, secret scanning with push protection and CodeQL default setup on, and the Supertext social preview image.
- A row in the plugin list (see *Plugin list*) and in the org profile (`Supertext/.github` → `profile/README.md`).

Claude sessions can't change GitHub repo settings (HTTP 403): add a new repo to Remy's setup script (`set-github-about`) instead of trying.

## Checks and alerts (always)

Before starting work in a repo, look at its open findings and fix what the task touches or what is quick: code scanning alerts (`gh api 'repos/Supertext/<Repo>/code-scanning/alerts?state=open'`), secret scanning alerts (`…/secret-scanning/alerts?state=open`), open Dependabot PRs and the issue "Broken links in the docs". New workflows and workflow changes must pass actionlint and zizmor; PHP code must pass PHPStan at the repo's level. See `docs/DEVELOPER.md` → *Code quality and security checks*.

## Demo accounts rule (always)

Every demo must be usable right after deployment, without anyone registering in a browser. On **every start**, the demo creates these accounts if they don't exist yet:

| Variables | Account |
| --- | --- |
| `DEMO_ADMIN_EMAIL`, `DEMO_ADMIN_PASSWORD` | Full administrator (for Supertext staff) |
| `DEMO_EDITOR_EMAIL`, `DEMO_EDITOR_PASSWORD` | Editor-level account that can translate content in every demo language; used for automated tests and screenshots. Where the CMS has no editor role that works out of the box, use the closest role and document it. |

- Existing accounts are never modified: no password resets from variables, no duplicates on restart.
- A password that doesn't meet the CMS's own password rules skips that account with a clear warning in the log. The demo still starts.
- Values live only in the hosting platform's variables (Railway). Never in the repo, in chat or in logs. Log the variable name, never the password.
- If the CMS has a first-run "create admin" screen, these accounts replace it. Document that once `DEMO_*` is set, the screen no longer appears.
- If a demo already used CMS-specific names (e.g. `TYPO3_ADMIN_*`, `PAYLOAD_ADMIN_*`), keep them as fallbacks for `DEMO_ADMIN_*`.
- The demo also seeds its target languages and at least one sample entry in the source language, and makes sure the editor account can access every target language.
- Document the variables in `docs/DEVELOPER.md` (demo section) and in the demo's `.env.example`.

## Screenshots rule (always)

The user guide and installation guide of every plugin include screenshots of the real UI: at least the translate action before and after translating, a translated result, the overwrite or retranslate warning if there is one, the plugin's settings or configuration screen, and the CMS's language setup. Screenshots are taken from the repo's own demo with the headless browser, by a committed script (e.g. `npm run docs:screenshots`), against a stand-in API that returns real translations for the sample content, so the guides never show placeholder text. Use no real customer data, no secrets, no local URLs (show the live API endpoint). Keep the images small (1× scale, cropped to the relevant part), store them in `docs/images/`, give each one descriptive alt text, and regenerate them in the same commit whenever the UI they show changes.

## Shared Supertext protocol

AI file translation API v1, same as the WordPress plugin: POST HTML file → poll status → GET translation → DELETE. Details in `docs/DEVELOPER.md`. Never commit API keys; use the `SUPERTEXT_API_KEY` environment variable or the module's API key setting (stored encrypted).

Lessons from the live API, apply them here: header `Authorization: Supertext-Auth-Key <key>` (strip a pasted prefix), retry HTTP 429 (per-second rate limit), keep a whole text in one `data-st-id` element (each one is translated on its own). **Languages:** only the codes Supertext's `GET features` lists (the SDK's `V1\Languages`): bare sources (`en`); targets regional only for de/en/fr/it/pt (`de-CH`, `en-US`), `zh-Hans`/`zh-Hant`, `sr-Latn`/`sr-Cyrl`, `gsw-u-sd-chzh`/`chbe`, bare for the rest (`es`, `nl`, `sl`). **Defaults only where obvious:** the store view's *Supertext language* (`supertext/language/code`, the mapping: a Magento select of the SDK's `targetOptions()` in store view scope under *Stores → Configuration → Supertext → Translation → Language*, its empty entry naming `suggestTarget()` of the locale), else an exact match of its locale, else the SDK default (de→de-DE, en→en-US, es_ES→es, zh_Hans_CN→zh-Hans …), else the error `unsupported_language` (nothing sent; en_IE, de_LI, fr_BE need a choice); see `docs/DEVELOPER.md` → *Language codes*.

**Commando Center:** version reports, the automatic API key check and the public ping (`/supertext/ping`) go through Supertext Plugin Health's `InstallationReporter` (vendored in `lib/supertext-plugin-health/`, namespace `Supertext\PluginHealth`; key check: the SDK's `checkApiKey()`) (`Api/Reporting.php`, `Model/Reporting.php`, `Observer/`, `Controller/Ping/Index.php`, state in the `flag` table as `supertext_reporter`); see `docs/DEVELOPER.md` → *Commando Center*. Reporting must never break a save or a translation.

## This repo

- Supertext calls go through the **Supertext SDK for PHP** (`Supertext\Sdk\V1`), vendored in `lib/supertext-sdk/` from https://github.com/Supertext/Supertext-API-SDK-Guide and loaded by `registration.php`; reach it through `Api/Supertext.php` (`Config::client()`). Never edit the vendored files: fix the SDK upstream and run `scripts/vendor-sdk.sh <commit>`. SDK errors become translatable messages in `Api\Supertext::error()` (one per SDK error code).
- Version reports, the key check result and the ping use **Supertext Plugin Health** (`Supertext\PluginHealth`), vendored the same way in `lib/supertext-plugin-health/` from https://github.com/Supertext/Commando-Center-Supertext-Translation (`plugin-health/`) and loaded by `registration.php`. Never edit it: fix it upstream and run `scripts/vendor-plugin-health.sh <tag>`.
- Before committing: PHP lint, `php phpunit.phar` (PHPUnit 11; the unit tests run without Magento, see `Test/bootstrap.php`), `./build.sh`. CI also runs PHPStan from a Mage-OS root (`docs/DEVELOPER.md` → *Code quality and security checks*), installs Mage-OS with MySQL and Elasticsearch and translates the demo content against the stand-in.
- End-to-end tests against the real Supertext API: `.github/workflows/e2e.yml` with `tests/e2e/` (see "End-to-end tests" in `docs/DEVELOPER.md`). The key is only the GitHub secret `SUPERTEXT_API_KEY`, set by Remy; sessions don't use a live key. Behaviour changes need matching checks in `tests/e2e/live.mjs`.
- Test UI changes in a local Mage-OS with the module **copied** to `app/code/Supertext/Translation` (Magento refuses templates outside its root, so no symlink; see `docs/DEVELOPER.md` → Local development) and regenerate the screenshots they affect (`tests/docs/screenshots.mjs`).
- Demo movie (`docs/images/demo.gif` in the README): `tests/docs/movie.mjs` (`npm run movie`, see "Demo movie" in `docs/DEVELOPER.md`); re-record it when the translate page changes. Install and configuration movie (MP4 only, not committed): `tests/docs/movie-install.mjs` (`npm run movie:install`); re-record it when the installation steps or the *Supertext* configuration change.
- New settings go in `etc/adminhtml/system.xml`, `etc/config.xml`, `Model/Config.php`, the German, French and Italian phrases in `i18n/{de_DE,fr_FR,it_IT}.csv` **and** the settings table in `docs/INSTALLATION.md`.
- UI phrases: `__()` / `translate` in XML, English source, translations in `i18n/de_DE.csv`, `fr_FR.csv`, `it_IT.csv`; run `php tools/sync-translations.php` for the regional copies. `Test/Unit/TranslationFilesTest.php` checks they are complete.
- Export for translation / Import translation (XLIFF and HTML, SDK `TranslationFiles`): core in `Model/Translation/FileExchange.php` (no Magento classes; reference `<type>:<id>:store-<store id>`, plugin `Magento-Supertext-Translation`), write-back through `Translator::importTexts()`, controllers `Translate/Export` and `Translate/Import`, UI at the bottom of `translate.phtml`; see `docs/DEVELOPER.md` → *Translation files*.
- *Translation review* per store view: `supertext/language/review` (Yes/No select next to the *Supertext language*, store view scope), review service `supertext/api/review_url` / `SUPERTEXT_REVIEW_URL` (default `Supertext::REVIEW_URL`, '' = off); `Config::clients()->forReview()` (`Api/SupertextClients.php`), used only by `Translator::translate()` (never for the key check, reports or translation files).
- Translated fields live in `Model/Translation/EntityTypes.php`; keep "What is translated" in `docs/DEVELOPER.md` and `docs/USER_GUIDE.md` in sync.
- Keep `Api/` and `Model/Translation/FieldPlanner.php` free of Magento classes (unit tests run without Magento).
- Products and categories get store-view values (`saveAttribute`, never a full save in store scope); CMS pages and blocks get copies per store view, linked in `supertext_translation_link`. Changing `etc/db_schema.xml` means regenerating `etc/db_schema_whitelist.json` (`bin/magento setup:db-declaration:generate-whitelist --module-name=Supertext_Translation`).
- `demo/` is the Railway demo (service *Magento* in *supertext-cms-demos-php*, building `demo/Dockerfile` with context = repo root; set on the service, Railway ignores `railway.json`). `demo/setup.php` seeds store views, sample content, the editor role and accounts. Demo secrets live only in Railway variables. Don't export-ignore `demo/` or anything the Dockerfile copies in `.gitattributes`: Railway builds from a `git archive` snapshot.
- Mage-OS quirks the demo depends on: admin passwords need 12+ characters with letters and digits; `setup:install` rejects an `http://` secure base URL; `Magento_TwoFactorAuth` is disabled in the demo only; admin URLs carry secret keys per controller action (the screenshot script opens pages from menu links and adds `/store/<id>/` before the key).
- Composer in a Claude session: GitHub downloads are blocked, so install Mage-OS with `preferred-install: source` and `use-github-api: false`, without dev packages (`phpstan/phpstan` is download-only).
