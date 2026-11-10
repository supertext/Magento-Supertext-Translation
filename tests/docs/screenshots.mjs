#!/usr/bin/env node
/**
 * Regenerates docs/images from a freshly started demo (empty database) whose module talks
 * to stand-in.mjs (SUPERTEXT_API_ENDPOINT=http://127.0.0.1:8765/v1/, no SUPERTEXT_API_KEY:
 * the script enters a key in the configuration). See docs/DEVELOPER.md -> Docs screenshots.
 *
 *   BASE_URL (default http://localhost:8091/admin)
 *   DEMO_ADMIN_EMAIL / DEMO_ADMIN_PASSWORD     configuration screens (Administrators)
 *   DEMO_EDITOR_EMAIL / DEMO_EDITOR_PASSWORD   translating (role "Supertext editor")
 *
 * Works with Magento's secret keys in admin URLs: pages are opened from menu links, and a
 * store view scope is added as /store/<id>/ before the key.
 */
import { chromium } from 'playwright'

const B = (process.env.BASE_URL || 'http://localhost:8091/admin').replace(/\/$/, '')
const OUT = new URL('../../docs/images', import.meta.url).pathname
const pad = (r, p = 8) => ({ x: Math.max(0, r.x - p), y: Math.max(0, r.y - p), width: r.width + 2 * p, height: r.height + 2 * p })
const union = (...rs) => {
  const x = Math.min(...rs.map((r) => r.x)), y = Math.min(...rs.map((r) => r.y))
  return { x, y, width: Math.max(...rs.map((r) => r.x + r.width)) - x, height: Math.max(...rs.map((r) => r.y + r.height)) - y }
}
const scoped = (url, storeId) => url.replace(/\/key\//, `/store/${storeId}/key/`)

const browser = await chromium.launch()
const only = process.env.ONLY ? new Set(process.env.ONLY.split(',')) : null // debugging: run one part ("admin", "editor")

async function session(email, password) {
  if (!email || !password) throw new Error('Set the DEMO_* email and password variables.')
  const page = await (await browser.newContext({ viewport: { width: 1280, height: 900 } })).newPage()
  page.setDefaultTimeout(180000)
  const css = '*{animation:none!important;transition:none!important;caret-color:transparent!important} .admin__data-grid-loading-mask,.loading-mask{display:none!important} .message-system,.admin__page-nav-title-messages{display:none!important}'
  // On every page before measuring: hidden system messages would otherwise move the crop.
  await page.addInitScript((style) => document.addEventListener('DOMContentLoaded', () => {
    const tag = document.createElement('style'); tag.textContent = style; document.head.appendChild(tag)
  }), css)
  await page.goto(`${B}/`)
  await page.fill('#username', email)
  await page.fill('#login', password)
  await page.click('.action-login')
  await page.waitForLoadState('networkidle')
  const shot = async (name, clip) => {
    await page.addStyleTag({ content: css })
    await page.evaluate(([origin]) => {
      // Times change with every run, so the images would too: show a fixed one.
      for (const el of document.querySelectorAll('#supertext-key-check small')) el.textContent = el.textContent.replace(/^([^:]+:).*$/s, '$1 Oct 1, 2026, 9:00:00 AM')
      // No local URLs in the docs: the demo's own address becomes an example shop.
      const walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT)
      while (walker.nextNode()) if (walker.currentNode.nodeValue.includes(origin)) walker.currentNode.nodeValue = walker.currentNode.nodeValue.replaceAll(origin, 'https://shop.example.com')
    }, [new URL(B).origin])
    await page.waitForTimeout(400)
    await page.screenshot({ path: `${OUT}/${name}.png`, ...(clip ? { clip } : {}) })
    console.log(`  ${name}.png`)
  }
  const box = async (selector, p) => pad(await page.locator(selector).first().boundingBox(), p)
  const go = async (url) => { await page.goto(url); await page.waitForLoadState('networkidle') }
  const menu = async (item) => page.locator(`li.${item} > a`).first().getAttribute('href')
  const grid = async () => { await page.locator('.data-grid tbody tr.data-row').first().waitFor(); await page.waitForTimeout(800) }
  // Dates (Created, Modified, Last Updated At) change with every run: leave those grid columns out.
  const hideDates = () => page.evaluate(() => {
    const table = [...document.querySelectorAll('.data-grid')].find((t) => t.offsetParent)
    const heads = [...table.querySelectorAll('thead th')]
    const drop = heads.map((th, i) => (/^(Created|Modified|Last Updated At)$/.test(th.textContent.replace(/\s+/g, ' ').trim()) ? i : -1)).filter((i) => i >= 0)
    for (const row of table.querySelectorAll('tr')) drop.forEach((i) => { if (row.children[i]) row.children[i].style.display = 'none' })
  })
  return { page, shot, box, go, menu, grid, hideDates }
}

// --- Installation guide (Administrators): enters the API key the user guide needs ------
if (!only || only.has('admin')) {
  const { page, shot, box, go, menu } = await session(process.env.DEMO_ADMIN_EMAIL, process.env.DEMO_ADMIN_PASSWORD)
  const configUrl = await menu('item-system-config')
  const storesUrl = await menu('item-system-store')

  await go(configUrl)
  await go(await page.locator('a[href*="/section/supertext/"]').first().getAttribute('href'))
  const sectionUrl = page.url()
  for (const g of ['api', 'language', 'cms']) {
    if (!(await page.locator(`#supertext_${g}`).isVisible())) await page.click(`#supertext_${g}-head`)
  }
  await page.fill('#supertext_api_api_key', 'Supertext-Auth-Key docs-demo-key')
  await page.click('#save')
  await page.waitForLoadState('networkidle')
  await page.click('#supertext-test-connection')
  await page.locator('#supertext-test-result:visible').waitFor()
  await shot('module-settings', union(await box('#supertext_api-head', 4), await box('#supertext_cms', 4)))

  // Per store view: the language code and tone (German store view, store id 2).
  await go(scoped(sectionUrl, 2))
  if (!(await page.locator('#supertext_language').isVisible())) await page.click('#supertext_language-head')
  await shot('module-language-store', union(await box('.store-switcher', 4), await box('#supertext_language', 4)))

  await go(storesUrl)
  await page.locator('#storeGrid_table, table.data-grid').first().waitFor()
  await shot('store-views', await box('#storeGrid_table, table.data-grid'))

  // The store view's locale: Stores → Configuration → General → Locale Options.
  await go(scoped(configUrl, 2))
  if (!(await page.locator('#general_locale').isVisible())) await page.click('#general_locale-head')
  await shot('store-locale', union(await box('#general_locale-head', 4), await box('#row_general_locale_code', 4)))
}

// --- User guide (editor account) -------------------------------------------------------
if (!only || only.has('editor')) {
  const { page, shot, box, go, menu, grid, hideDates } = await session(process.env.DEMO_EDITOR_EMAIL, process.env.DEMO_EDITOR_PASSWORD)
  const productsUrl = await menu('item-catalog-products')
  const categoriesUrl = await menu('item-catalog-categories')
  const pagesUrl = await menu('item-cms-page')

  // Products: select both sample products, open the Actions menu.
  await go(productsUrl)
  await grid()
  for (const row of await page.locator('tr.data-row', { hasText: 'ST-DEMO-' }).all()) await row.locator('input[type=checkbox]').check({ force: true })
  await hideDates()
  await page.locator('button.action-select', { hasText: 'Actions' }).first().click()
  const item = page.locator('.action-menu-item:visible', { hasText: 'Translate with Supertext' }).first()
  await item.waitFor()
  await shot('products-mass-action', pad(union(await page.locator('button.action-select', { hasText: 'Actions' }).first().boundingBox(), await item.boundingBox(), await page.locator('tr.data-row', { hasText: 'ST-DEMO-PRALINES' }).boundingBox())))

  await item.click()
  await page.waitForLoadState('networkidle')
  await shot('translate-page', await box('.supertext-page section'))

  await page.click('.supertext-go')
  await page.locator('.supertext-summary.message-success, .supertext-summary.message-warning').waitFor({ timeout: 300000 })
  await shot('translate-results', await box('.supertext-page section'))

  // The German store view of the product.
  const productUrl = await page.locator('.supertext-table a', { hasText: 'Dark chocolate praline box' }).getAttribute('href')
  await go(scoped(productUrl, 2))
  await page.locator('input[name="product[name]"]').waitFor()
  await page.waitForTimeout(1500)
  await shot('translated-product', { x: 0, y: 0, width: 1280, height: 520 })
  await shot('product-button', await box('.page-actions-buttons, .page-actions', 6))

  // A CMS page: copies per store view.
  await go(pagesUrl)
  await grid()
  await page.locator('tr.data-row', { hasText: 'delivery-and-returns' }).first().locator('input[type=checkbox]').check({ force: true })
  await page.locator('button.action-select', { hasText: 'Actions' }).first().click()
  await page.locator('.action-menu-item:visible', { hasText: 'Translate with Supertext' }).first().click()
  await page.waitForLoadState('networkidle')
  await page.click('.supertext-go')
  await page.locator('.supertext-summary.message-success, .supertext-summary.message-warning').waitFor({ timeout: 300000 })
  await shot('page-results', await box('.supertext-page section'))
  await go(pagesUrl)
  await grid()
  await page.setViewportSize({ width: 1280, height: 1300 })
  // Filter by URL key (the keyword search doesn't find "delivery-and-returns": MySQL's
  // full-text search reads the hyphens as "without").
  await page.locator('button[data-action="grid-filter-expand"]').first().click()
  await page.locator('.admin__data-grid-filters input[name="identifier"]').fill('delivery-and-returns')
  await page.locator('button[data-action="grid-filter-apply"]').first().click()
  await page.waitForFunction(() => {
    const rows = [...document.querySelectorAll('.data-grid tr.data-row')]
    return rows.length >= 4 && rows.every((r) => r.textContent.includes('delivery-and-returns'))
  })
  await page.waitForTimeout(1500)
  await hideDates()
  await shot('pages-translated', await box('.data-grid:visible'))
  await page.setViewportSize({ width: 1280, height: 900 })

  // A category from its edit page; then the overwrite warning.
  await go(categoriesUrl)
  await page.locator('.jstree a', { hasText: 'Swiss chocolate' }).first().click()
  await page.waitForLoadState('networkidle')
  await page.locator('button', { hasText: 'Translate with Supertext' }).waitFor()
  await page.waitForTimeout(1000)
  await shot('category-button', union(await box('.page-title-wrapper, .page-title', 4), await box('.page-actions-buttons, .page-actions', 4)))
  await page.locator('button', { hasText: 'Translate with Supertext' }).click()
  await page.waitForLoadState('networkidle')
  for (const id of ['3', '4']) await page.locator(`#supertext-store-${id}`).uncheck()
  await page.click('.supertext-go')
  await page.locator('.supertext-summary.message-success, .supertext-summary.message-warning').waitFor({ timeout: 300000 })
  await page.reload()
  await page.waitForLoadState('networkidle')
  await page.check('#supertext-overwrite')
  await shot('overwrite-warning', await box('.supertext-page section'))
}

await browser.close()
console.log(`Screenshots written to ${OUT}`)
