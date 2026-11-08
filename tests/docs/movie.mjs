#!/usr/bin/env node
/**
 * Records the demo movie (docs/images/demo.gif and an MP4) from a demo whose module talks to
 * the stand-in API (stand-in.mjs) with an API key set, like screenshots.mjs (run it after the
 * screenshots or the install movie, which enter a key). The editor opens the product "Dark
 * chocolate praline box" in English, clicks Translate with Supertext, translates it into German
 * and French and checks the German store view. See "Demo movie" in docs/DEVELOPER.md.
 *
 *   BASE_URL=http://localhost:8091/admin DEMO_EDITOR_EMAIL=… DEMO_EDITOR_PASSWORD=… node movie.mjs
 *
 * Voice: Piper (pip install piper-tts), voice "alba" downloaded once into movie/voice-cache/
 * (git-ignored); MOVIE_VOICE=0 makes a silent movie. MOVIE_OUT: where the MP4 goes (default
 * tests/docs/movie/out/Magento-Supertext-Translation.mp4, git-ignored; the MP4 is not
 * committed). Needs ffmpeg. CHROMIUM_PATH: another Chromium than Playwright's.
 */
import { chromium } from 'playwright'
import { fileURLToPath } from 'node:url'
import fs from 'node:fs'
import os from 'node:os'
import path from 'node:path'
import { MovieKit } from './movie/movie-kit.mjs'

const B = (process.env.BASE_URL || 'http://localhost:8091/admin').replace(/\/$/, '')
const here = (p) => fileURLToPath(new URL(p, import.meta.url))
const out = process.env.MOVIE_OUT ?? here('movie/out/Magento-Supertext-Translation.mp4')
const gif = here('../../docs/images/demo.gif')
const need = (name) => process.env[name] ?? (() => { throw new Error(`Set ${name}`) })()
const PRODUCT = 'Dark chocolate praline box'

const browser = await chromium.launch({ executablePath: process.env.CHROMIUM_PATH || undefined })
const page = await browser.newPage({ viewport: { width: 1280, height: 720 }, deviceScaleFactor: 1, locale: 'en-US' })
page.setDefaultTimeout(180000)

// Sign in as the demo editor (role "Supertext editor"; not recorded).
await page.goto(`${B}/`)
await page.fill('#username', need('DEMO_EDITOR_EMAIL'))
await page.fill('#login', need('DEMO_EDITOR_PASSWORD'))
await page.click('.action-login')
await page.waitForLoadState('networkidle')

// The product's edit page, from the product grid (admin URLs carry secret keys).
await page.goto(await page.locator('li.item-catalog-products > a').first().getAttribute('href'))
await page.locator('.data-grid tbody tr.data-row').first().waitFor()
const editUrl = await page.locator('tr.data-row', { hasText: 'ST-DEMO-PRALINES' }).locator('a', { hasText: 'Edit' }).first().getAttribute('href')
await page.goto(editUrl)
await page.locator('input[name="product[name]"]').waitFor()
await page.waitForLoadState('networkidle')
await page.locator('.loading-mask, .admin__form-loading-mask').first().waitFor({ state: 'hidden' }).catch(() => {})
await page.waitForTimeout(2000)

const workDir = fs.mkdtempSync(path.join(os.tmpdir(), 'magento-movie-'))
const kit = await MovieKit.start(page, { workDir, assets: here('movie/assets'), voiceCache: here('movie/voice-cache') })
const ripple = () => page.evaluate(([x, y]) => window.__stk?.ripple(x, y), [kit.cursor.x, kit.cursor.y])
/** Clicks a link or button and waits for the next page; the loading time is cut. */
async function open(locator, url, ready) {
  const b = await locator.boundingBox()
  if (!b || Math.abs(b.x + b.width / 2 - kit.cursor.x) > 2 || Math.abs(b.y + b.height / 2 - kit.cursor.y) > 2) await kit.moveTo(locator)
  await ripple()
  await kit.hold(250)
  kit.pause()
  await Promise.all([page.waitForURL(url), locator.click()])
  await page.waitForLoadState('networkidle')
  if (ready) await ready()
  await page.locator('.loading-mask, .admin__form-loading-mask').first().waitFor({ state: 'hidden' }).catch(() => {})
  await page.waitForTimeout(600)
  kit.resume()
  await kit.sync()
}
const productForm = () => page.locator('input[name="product[name]"]').waitFor()

// 1. The English product.
await kit.say(`Here's a product in English, the ${PRODUCT.toLowerCase()}, in the Magento admin.`, {
  caption: `A product in English: “${PRODUCT}”`,
  during: async () => { await kit.hold(500); await kit.moveTo(page.locator('input[name="product[name]"]')) },
})

// 2. Translate with Supertext.
const button = page.locator('button', { hasText: 'Translate with Supertext' }).first()
await kit.say('At the top, click Translate with Supertext.', {
  caption: 'Click <b>Translate with Supertext</b>',
  during: () => kit.moveTo(button),
  after: 0,
})
await open(button, /supertext/)

// 3. Pick the store views and start.
await kit.say('Keep German and French, and untick Italian.', {
  caption: 'Translate into <b>Deutsch (Schweiz)</b> and <b>Français (Suisse)</b>',
  during: async () => {
    await kit.hold(700)
    await kit.click(page.locator('label[for="supertext-store-4"]'))
  },
})
const go = page.locator('.supertext-go')
await kit.say('Then click Translate.', { caption: 'Click <b>Translate</b>', during: () => kit.moveTo(go), after: 0 })

// The stand-in answers at once: let the first request through only after the progress state has been seen.
let release
const released = new Promise((resolve) => (release = resolve))
await page.route(/supertext\/translate\/run/, async (route) => { await released; await route.continue() })
await ripple()
const done = page.locator('.supertext-summary.message-success, .supertext-summary.message-warning').waitFor({ timeout: 300000 })
await kit.say('Supertext translates the content.', {
  caption: 'Supertext translates the content…',
  during: async () => {
    await go.click()
    await kit.hold(1300)
    release()
  },
})
kit.pause()
await done
await page.waitForTimeout(300)
kit.resume()
await kit.sync()

// 4. The result: the German store view of the product.
await kit.say('A few seconds later, the German and French texts are saved in their store views.', {
  caption: 'Done: German and French saved in their store views',
  during: async () => { await kit.hold(300); await kit.moveTo(page.locator('.supertext-table td[data-store="2"]').first()) },
})
const link = page.locator('.supertext-table a', { hasText: PRODUCT }).first()
await kit.say("Let's open the product to check it.", {
  caption: 'Open the product',
  during: () => kit.moveTo(link),
  after: 0,
})
await open(link, /catalog\/product\/edit/, productForm)
const ok = page.locator('.modal-popup._show .action-accept, .modal-popup._show button.action-primary').first()
await kit.say('And switch the scope to the German store view.', {
  caption: 'Scope: <b>Deutsch (Schweiz)</b>',
  during: async () => {
    await kit.click(page.locator('#store-change-button'))
    await kit.click(page.locator('a[data-role="store-view-id"][data-value="2"]'))
    await ok.waitFor()
    await kit.moveTo(ok)
  },
  after: 0,
})
await open(ok, /store\/2/, productForm)
await kit.say('The German version is ready. Review it, and publish it as usual.', {
  caption: 'The German version is ready – review it and publish',
  during: async () => { await kit.hold(300); await kit.moveTo(page.locator('input[name="product[name]"]')) },
})
await kit.caption('')

fs.mkdirSync(path.dirname(out), { recursive: true })
await kit.finish(out, { system: 'Magento', repo: 'Magento-Supertext-Translation', gif })
await browser.close()
fs.rmSync(workDir, { recursive: true, force: true })
console.log(`Saved ${out} and ${gif} (${(fs.statSync(gif).size / 1e6).toFixed(2)} MB)`)
