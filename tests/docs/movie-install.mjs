#!/usr/bin/env node
/**
 * Records the install and configuration movie (MP4 only, no GIF) from a FRESH demo whose
 * module talks to the stand-in API (SUPERTEXT_API_ENDPOINT=http://127.0.0.1:8765/v1/, no
 * SUPERTEXT_API_KEY), like screenshots.mjs. An administrator installs the module (terminal:
 * Composer, module:enable, setup:upgrade, cache:flush), enters a made-up API key in
 * Stores → Configuration → Supertext → Translation, sees the key check, sets the German store
 * view's Supertext language and shows where editors find "Translate with Supertext".
 * See "Demo movie" in docs/DEVELOPER.md.
 *
 *   BASE_URL=http://localhost:8091/admin DEMO_ADMIN_EMAIL=… DEMO_ADMIN_PASSWORD=… node movie-install.mjs
 *
 * The stand-in accepts any key; the movie types st-demo-0000-0000-0000. MOVIE_VOICE=0 makes a
 * silent movie. MOVIE_OUT: where the MP4 goes (default tests/docs/movie/out/
 * Magento-Supertext-Translation-install.mp4, git-ignored). Needs ffmpeg; the voice needs
 * `pip install piper-tts`. CHROMIUM_PATH: another Chromium than Playwright's.
 */
import { chromium } from 'playwright'
import { fileURLToPath } from 'node:url'
import fs from 'node:fs'
import os from 'node:os'
import path from 'node:path'
import { MovieKit } from './movie/movie-kit.mjs'

const B = (process.env.BASE_URL || 'http://localhost:8091/admin').replace(/\/$/, '')
const here = (p) => fileURLToPath(new URL(p, import.meta.url))
const out = process.env.MOVIE_OUT ?? here('movie/out/Magento-Supertext-Translation-install.mp4')
const need = (name) => process.env[name] ?? (() => { throw new Error(`Set ${name}`) })()
const FAKE_KEY = 'st-demo-0000-0000-0000'

const browser = await chromium.launch({ executablePath: process.env.CHROMIUM_PATH || undefined })
const page = await browser.newPage({ viewport: { width: 1280, height: 720 }, deviceScaleFactor: 1, locale: 'en-US' })
page.setDefaultTimeout(180000)

// Sign in as the demo administrator (not recorded): configuration is an administrator's task.
await page.goto(`${B}/`)
await page.fill('#username', need('DEMO_ADMIN_EMAIL'))
await page.fill('#login', need('DEMO_ADMIN_PASSWORD'))
await page.click('.action-login')
await page.waitForLoadState('networkidle')
const dashboardUrl = page.url()
// Warm up the pages the movie opens (Magento builds its caches on the first visit).
await page.goto(await page.locator('li.item-system-config > a').first().getAttribute('href'))
await page.waitForLoadState('networkidle')
await page.goto(await page.locator('li.item-catalog-products > a').first().getAttribute('href'))
await page.waitForLoadState('networkidle')

const workDir = fs.mkdtempSync(path.join(os.tmpdir(), 'magento-movie-install-'))
await page.goto('about:blank')
const kit = await MovieKit.start(page, { workDir, assets: here('movie/assets'), voiceCache: here('movie/voice-cache') })
const ripple = () => page.evaluate(([x, y]) => window.__stk?.ripple(x, y), [kit.cursor.x, kit.cursor.y])
/** Clicks a link and waits for the next page; the loading time is cut. */
async function open(locator, url, ready) {
  const b = await locator.boundingBox()
  if (!b || Math.abs(b.x + b.width / 2 - kit.cursor.x) > 2 || Math.abs(b.y + b.height / 2 - kit.cursor.y) > 2) await kit.moveTo(locator)
  await ripple()
  await kit.hold(250)
  kit.pause()
  await Promise.all([page.waitForURL(url), locator.click()])
  await page.waitForLoadState('networkidle')
  if (ready) await ready()
  await page.waitForTimeout(300)
  kit.resume()
  await kit.sync()
}
/** Opens or closes a configuration group (`api`, `language`). */
async function group(id, open) {
  if ((await page.locator(`#supertext_${id}`).isVisible()) !== open) await kit.click(page.locator(`#supertext_${id}-head`))
  await page.waitForTimeout(400)
}

/** More commands in the terminal kit.terminal() opened (same look; kit.terminal() would start a new one). */
async function typeMore(lines) {
  await page.evaluate(() => document.querySelector('#x > .p.c:last-child')?.remove())
  for (const l of lines) {
    if (l.cmd !== undefined) {
      await page.evaluate(() => { const x = document.getElementById('x')
        const p = document.createElement('span'); p.className = 'p'; p.textContent = '$ '; x.appendChild(p)
        const c = document.createElement('span'); c.className = 'c'; x.appendChild(c) })
      for (const ch of l.cmd) { await page.evaluate((ch) => { document.querySelector('#x .c').textContent += ch }, ch); await page.waitForTimeout(38) }
      await page.waitForTimeout(450)
      await page.evaluate(() => { document.querySelector('#x .c').classList.remove('c'); document.getElementById('x').append('\n') })
    } else {
      await page.evaluate((o) => { const s = document.createElement('span'); s.className = 'o'; s.textContent = o + '\n'; document.getElementById('x').appendChild(s) }, l.out ?? '')
      await page.waitForTimeout(l.wait ?? 180)
    }
  }
  await page.evaluate(() => { const c = document.createElement('span'); c.className = 'p c'; c.textContent = '$ '; document.getElementById('x').appendChild(c) })
}

// 1. Terminal: the commands from docs/INSTALLATION.md (output shortened).
await kit.say("First, add the module to your Magento installation with Composer. Tell Composer where to find Supertext's repository on GitHub,", {
  caption: 'Add the module with <b>Composer</b>',
  during: () => kit.terminal({ title: 'my-shop', lines: [
    { cmd: 'composer config repositories.supertext vcs https://github.com/Supertext/Magento-Supertext-Translation' },
  ] }),
  after: 200,
})
await kit.say('then require the package. Composer downloads and installs it.', {
  caption: '<b>composer require supertext/magento-supertext-translation</b>',
  during: () => typeMore([
    { cmd: 'composer require supertext/magento-supertext-translation:^0.1' },
    { out: './composer.json has been updated' },
    { out: 'Package operations: 1 install, 0 updates, 0 removals' },
    { out: '  - Installing supertext/magento-supertext-translation (0.1.0)' },
  ]),
})
await kit.say('Next, enable the module in Magento.', {
  caption: 'Enable it: <b>module:enable</b>',
  during: () => kit.terminal({ title: 'my-shop', lines: [
    { cmd: 'bin/magento module:enable Supertext_Translation' },
    { out: 'The following modules have been enabled:' },
    { out: '- Supertext_Translation' },
  ] }),
  after: 200,
})
await kit.say('Update the database with setup upgrade.', {
  caption: 'Update the database: <b>setup:upgrade</b>',
  during: () => typeMore([
    { cmd: 'bin/magento setup:upgrade' },
    { out: 'Updating modules:' },
    { out: "Module 'Supertext_Translation':" },
    { out: 'Nothing to import.' },
  ]),
  after: 200,
})
await kit.say('And flush the cache.', {
  caption: 'Flush the cache: <b>cache:flush</b>',
  during: () => typeMore([
    { cmd: 'bin/magento cache:flush' },
    { out: 'Flushed cache types: config, layout, block_html, …' },
  ]),
})
await kit.hold(300)

// 2. The admin as administrator: Stores → Configuration → Supertext → Translation (loading time cut).
kit.pause()
await page.goto(dashboardUrl)
await page.waitForLoadState('networkidle')
await page.waitForTimeout(1500)
await kit.sync()
kit.resume()
await kit.say('In the admin, signed in as an administrator, open Stores, Configuration.', {
  caption: 'Open <b>Stores → Configuration</b>',
  during: async () => {
    await kit.hold(500)
    await kit.click(page.locator('#menu-magento-backend-stores > a'))
    await kit.hold(300)
    await kit.moveTo(page.locator('li.item-system-config > a').first())
  },
  after: 0,
})
await open(page.locator('li.item-system-config > a').first(), /system_config/)
await kit.say('Under Supertext, choose Translation.', {
  caption: '<b>Supertext → Translation</b>',
  during: async () => {
    await kit.click(page.locator('.admin__page-nav-title', { hasText: 'Supertext' }).first())
    await kit.moveTo(page.locator('a[href*="/section/supertext/"]').first())
  },
  after: 0,
})
await open(page.locator('a[href*="/section/supertext/"]').first(), /section\/supertext/, async () => {
  // Groups as an administrator first sees them: only the connection open (the ping address further down stays out of view).
  for (const [id, want] of [['api', false], ['language', false], ['cms', false], ['api', true]]) {
    if ((await page.locator(`#supertext_${id}`).isVisible()) !== want) await page.click(`#supertext_${id}-head`)
    await page.waitForTimeout(300)
  }
  await page.evaluate(() => window.scrollTo(0, 0))
})

// 3. The API key.
const keyField = page.locator('#supertext_api_api_key')
await kit.say('Generate your API key at supertext.com, under Integrations, A P I. No account yet? Create one at supertext.com.', {
  caption: 'API key: <b>supertext.com → Integrations → API</b> (Admin role)<br>No account yet? Create one at <b>supertext.com</b>',
  during: async () => { await kit.hold(600); await kit.moveTo(page.locator('#row_supertext_api_api_key .note').first()) },
})
await kit.say('Paste it into Supertext API key, and click Save Config.', {
  caption: 'Paste it into <b>Supertext API key</b>, click <b>Save Config</b>',
  during: async () => {
    await kit.click(keyField)
    await keyField.fill('')
    await keyField.pressSequentially(FAKE_KEY, { delay: 45 })
    await kit.hold(300)
    await kit.moveTo(page.locator('#save'))
  },
  after: 0,
})
await open(page.locator('#save'), /section\/supertext/)
const checked = page.locator('.message-success', { hasText: 'API key checked' }).first()
await kit.say('Saving checks the key right away: the connection to Supertext works.', {
  caption: '<b>API key checked: the connection to Supertext works.</b>',
  during: async () => { await kit.hold(300); await kit.moveTo(checked) },
})

// 4. Languages: the store views and their Supertext language.
await kit.say('Under Language, each store view has its Supertext language.', {
  caption: '<b>Language</b>: one Supertext language per store view',
  during: async () => {
    await group('api', false)
    await group('language', true)
    const overview = page.locator('#row_supertext_language_overview')
    await overview.evaluate((el) => window.scrollBy({ top: el.getBoundingClientRect().top - 120, behavior: 'smooth' }))
    await kit.hold(900)
    await kit.moveTo(overview.locator('tr', { hasText: 'Deutsch (Schweiz)' }).first())
  },
})
await kit.say('To change one, switch the scope to Deutsch, Schweiz.', {
  caption: 'Scope: <b>Deutsch (Schweiz)</b>',
  during: async () => {
    await page.evaluate(() => window.scrollTo({ top: 0, behavior: 'smooth' }))
    await kit.hold(500)
    await kit.click(page.locator('#store-change-button'))
    await kit.click(page.locator('a[data-role="store-view-id"][data-value="2"]'))
    const ok = page.locator('.modal-popup._show .action-accept, .modal-popup._show button.action-primary').first()
    await ok.waitFor()
    await kit.moveTo(ok)
  },
  after: 0,
})
await open(page.locator('.modal-popup._show .action-accept, .modal-popup._show button.action-primary').first(), /store\/2/, async () => {
  if (!(await page.locator('#supertext_language').isVisible())) await page.click('#supertext_language-head')
})
const code = page.locator('#supertext_language_code')
await kit.say('It comes from the locale: German, Switzerland. Choose another one if you need to.', {
  caption: '<b>Supertext language</b>: <b>German (Switzerland) (de-CH)</b>',
  during: async () => {
    await code.evaluate((el) => window.scrollBy({ top: el.getBoundingClientRect().top - 220, behavior: 'smooth' }))
    await kit.hold(700)
    await kit.click(code)
    await code.selectOption({ label: 'German (Switzerland) (de-CH)' })
    await kit.hold(300)
  },
})
await kit.say('Translation review can be switched on per store view. We leave it off.', {
  caption: '<b>Translation review</b>: optional, per store view (left at <b>No</b>)',
  during: async () => { await kit.hold(300); await kit.moveTo(page.locator('#supertext_language_review')) },
})
await kit.say('Click Save Config.', { caption: 'Click <b>Save Config</b>', during: () => kit.moveTo(page.locator('#save')), after: 0 })
await open(page.locator('#save'), /section\/supertext/)

// 5. Where editors find it.
await kit.say("That's it. Editors find Translate with Supertext under Catalog, Products.", {
  caption: 'Done: editors find it in <b>Catalog → Products</b>',
  during: async () => {
    await kit.moveTo(page.locator('.message-success').first())
    await kit.hold(400)
    await kit.click(page.locator('#menu-magento-catalog-catalog > a'))
    await kit.hold(300)
    await kit.moveTo(page.locator('li.item-catalog-products > a').first())
  },
  after: 0,
})
await open(page.locator('li.item-catalog-products > a').first(), /catalog\/product/, () => page.locator('.data-grid tbody tr.data-row').first().waitFor())
await kit.say('Select products, and choose it in the Actions menu.', {
  caption: 'Select products → <b>Actions</b> → <b>Translate with Supertext</b>',
  during: async () => {
    const row = page.locator('tr.data-row', { hasText: 'ST-DEMO-PRALINES' }).first()
    await kit.moveTo(row.locator('input[type=checkbox]'))
    await ripple()
    await row.locator('input[type=checkbox]').check({ force: true })
    const actions = page.locator('button.action-select', { hasText: 'Actions' }).first()
    await kit.click(actions)
    const item = page.locator('.action-menu-item:visible', { hasText: 'Translate with Supertext' }).first()
    await item.waitFor()
    await kit.moveTo(item)
    await item.hover()
  },
  after: 900,
})
await kit.caption('')

fs.mkdirSync(path.dirname(out), { recursive: true })
await kit.finish(out, { system: 'Magento', repo: 'Magento-Supertext-Translation', kind: 'install' })
await browser.close()
fs.rmSync(workDir, { recursive: true, force: true })
console.log(`Saved ${out}`)
