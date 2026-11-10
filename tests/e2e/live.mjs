#!/usr/bin/env node
/**
 * End-to-end test against the REAL Supertext API: a fresh demo (nothing translated yet)
 * with a real SUPERTEXT_API_KEY. The editor (role "Supertext editor") translates the demo's
 * sample products, category, CMS page and block into every store view through the admin
 * (grid mass actions, the category's edit-page button, the translate page), then the
 * database is checked: every item has its store-view values (products, categories) or its
 * copy per store view (pages, blocks), the texts are really in the target language (de-CH
 * without "ß"), formatting, links and Magento directives survived, nothing is left of the
 * protocol, and translating again with "Overwrite existing translations" updates the same
 * records instead of adding new ones. See "End-to-end tests" in docs/DEVELOPER.md.
 *
 *   MAGENTO_URL=http://127.0.0.1:8081 DATABASE_URL=mysql://root:root@127.0.0.1:3306/magento_live \
 *   EDITOR_EMAIL=… EDITOR_PASSWORD=… node tests/e2e/live.mjs
 */
import { chromium } from 'playwright';
import mysql from 'mysql2/promise';
import { mkdirSync, readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';

const B = (process.env.MAGENTO_URL ?? 'http://127.0.0.1:8081').replace(/\/$/, '');
const need = (name) => process.env[name] || (() => { throw new Error(`Set ${name}`); })();
const artifacts = fileURLToPath(new URL('./artifacts/', import.meta.url));
mkdirSync(artifacts, { recursive: true });
const sample = JSON.parse(readFileSync(new URL('../../demo/sample-content.json', import.meta.url), 'utf8'));

const failures = [];
function check(name, ok, detail = '') {
	console.log(`${ok ? 'ok  ' : 'FAIL'} ${name}${detail ? ` — ${detail}` : ''}`);
	if (!ok) failures.push(`${name}${detail ? ` — ${detail}` : ''}`);
	return ok;
}
const note = (text) => console.log(`note ${text}`);

// Common words, to tell the target languages apart. Lower case, whole words.
const STOPWORDS = {
	en: ['the', 'and', 'is', 'are', 'with', 'for', 'you', 'your', 'our', 'of', 'to', 'we', 'who', 'what', 'from', 'in', 'no'],
	de: ['der', 'die', 'das', 'und', 'ist', 'sind', 'mit', 'für', 'sie', 'ihre', 'ihr', 'unser', 'unsere', 'wir', 'eine', 'ein', 'den', 'zu', 'von', 'was', 'aus', 'im', 'kein', 'keine'],
	fr: ['le', 'la', 'les', 'et', 'est', 'sont', 'avec', 'pour', 'vous', 'votre', 'vos', 'notre', 'nos', 'nous', 'des', 'une', 'un', 'du', 'de', 'qui', 'que', 'en', 'au', 'aux'],
	it: ['il', 'lo', 'la', 'gli', 'le', 'e', 'è', 'sono', 'con', 'per', 'lei', 'sua', 'suo', 'nostro', 'nostra', 'nostri', 'noi', 'una', 'un', 'del', 'della', 'di', 'che', 'chi', 'da', 'in', 'nel'],
};
const words = (text) => text.toLowerCase().match(/[\p{L}]+/gu) ?? [];
const score = (text, lang) => words(text).filter((w) => STOPWORDS[lang].includes(w)).length;
const plain = (v) => String(v ?? '').replace(/<[^>]+>/g, ' ').replace(/\{\{[^}]*\}\}/g, ' ');
const count = (haystack, needle) => String(haystack ?? '').split(needle).length - 1;
const directives = (v) => String(v ?? '').match(/\{\{[^}]*\}\}/g) ?? [];

const LEFTOVERS = ['data-st-id', 'data-st-ph', 'st-ph-', 'data-st-keep'];
const KEPT = [['links', '<a '], ['bold', '<strong'], ['italics', '<em'], ['list items', '<li'], ['headings', '<h2'], ['paragraphs', '<p'],
	['Page Builder elements', 'data-content-type'], ['link targets', 'href="https://www.supertext.com"']];

// What the module translates (Model/Translation/EntityTypes.php).
const FIELDS = {
	product: ['name', 'short_description', 'description', 'meta_title', 'meta_keyword', 'meta_description'],
	category: ['name', 'description', 'meta_title', 'meta_keywords', 'meta_description'],
	cms_page: ['title', 'content_heading', 'content', 'meta_title', 'meta_keywords', 'meta_description'],
	cms_block: ['title', 'content'],
};

const db = await mysql.createConnection(need('DATABASE_URL'));
const q = async (sql, params = []) => (await db.query(sql, params))[0];

// --- The demo's content ------------------------------------------------------------------
const stores = await q("SELECT store_id, code, name FROM store WHERE code IN ('de_ch', 'fr_ch', 'it_ch') ORDER BY store_id");
const locales = Object.fromEntries((await q("SELECT scope_id, value FROM core_config_data WHERE path = 'general/locale/code' AND scope = 'stores'")).map((r) => [r.scope_id, r.value]));
for (const s of stores) s.lang = String(locales[s.store_id] ?? s.code).replace('_', '-');
const storeIds = stores.map((s) => s.store_id);
const typeId = async (code) => (await q('SELECT entity_type_id FROM eav_entity_type WHERE entity_type_code = ?', [code]))[0].entity_type_id;
const productType = await typeId('catalog_product');
const categoryType = await typeId('catalog_category');

const products = await q('SELECT entity_id, sku FROM catalog_product_entity WHERE sku IN (?) ORDER BY entity_id', [sample.products.map((p) => p.sku)]);
const [category] = await q("SELECT v.entity_id FROM catalog_category_entity_varchar v JOIN eav_attribute a USING (attribute_id) WHERE a.attribute_code = 'url_key' AND a.entity_type_id = ? AND v.store_id = 0 AND v.value = ?", [categoryType, sample.category.url_key]);
const [cmsPage] = await q('SELECT p.* FROM cms_page p JOIN cms_page_store s USING (page_id) WHERE p.identifier = ? AND s.store_id = 0', [sample.page.identifier]);
const [cmsBlock] = await q('SELECT b.* FROM cms_block b JOIN cms_block_store s USING (block_id) WHERE b.identifier = ? AND s.store_id = 0', [sample.block.identifier]);
console.log(`Demo: store views ${stores.map((s) => `${s.code} (${s.lang})`).join(', ')}; ${products.length} products, category #${category?.entity_id}, page #${cmsPage?.page_id}, block #${cmsBlock?.block_id}`);
if (!check('demo has its store views, products, category, page and block', stores.length === 3 && products.length === sample.products.length && category && cmsPage && cmsBlock)) {
	throw new Error(failures.join('\n'));
}
check('fresh demo (nothing translated yet)', (await q('SELECT COUNT(*) AS n FROM supertext_translation_link'))[0].n === 0
	&& (await q("SELECT COUNT(*) AS n FROM catalog_product_entity_varchar v JOIN eav_attribute a USING (attribute_id) WHERE a.attribute_code = 'name' AND a.entity_type_id = ? AND v.store_id IN (?) AND v.entity_id IN (?)", [productType, storeIds, products.map((p) => p.entity_id)]))[0].n === 0);

/** EAV values: map "<entity id>|<store id>|<attribute code>" → value. */
async function eav(entity, entityTypeId, ids, codes, storeList) {
	const attrs = await q("SELECT attribute_id, attribute_code, backend_type FROM eav_attribute WHERE entity_type_id = ? AND attribute_code IN (?) AND backend_type != 'static'", [entityTypeId, codes]);
	const out = new Map();
	for (const type of new Set(attrs.map((a) => a.backend_type))) {
		const ofType = attrs.filter((a) => a.backend_type === type);
		const rows = await q(`SELECT entity_id, store_id, attribute_id, value FROM catalog_${entity}_entity_${type} WHERE entity_id IN (?) AND store_id IN (?) AND attribute_id IN (?)`, [ids, storeList, ofType.map((a) => a.attribute_id)]);
		for (const r of rows) out.set(`${r.entity_id}|${r.store_id}|${ofType.find((a) => a.attribute_id === r.attribute_id).attribute_code}`, r.value);
	}
	return out;
}

/** Everything a translation run left in the database. */
async function snapshot() {
	const productIds = products.map((p) => p.entity_id);
	const all = [0, ...storeIds];
	const rowCount = async (table, ids) => (await q(`SELECT COUNT(*) AS n FROM ${table} WHERE entity_id IN (?) AND store_id IN (?)`, [ids, storeIds]))[0].n;
	return {
		product: await eav('product', productType, productIds, [...FIELDS.product, 'url_key', 'status'], all),
		category: await eav('category', categoryType, [category.entity_id], [...FIELDS.category, 'url_key', 'is_active'], all),
		links: await q('SELECT entity_type, source_id, store_id, target_id FROM supertext_translation_link ORDER BY entity_type, store_id'),
		pages: await q('SELECT p.*, s.store_id AS store FROM cms_page p JOIN cms_page_store s USING (page_id) WHERE p.identifier = ? ORDER BY p.page_id', [sample.page.identifier]),
		blocks: await q('SELECT b.*, s.store_id AS store FROM cms_block b JOIN cms_block_store s USING (block_id) WHERE b.identifier = ? ORDER BY b.block_id', [sample.block.identifier]),
		counts: {
			'product values': (await rowCount('catalog_product_entity_varchar', productIds)) + (await rowCount('catalog_product_entity_text', productIds)),
			'category values': (await rowCount('catalog_category_entity_varchar', [category.entity_id])) + (await rowCount('catalog_category_entity_text', [category.entity_id])),
			'CMS pages': (await q('SELECT COUNT(*) AS n FROM cms_page'))[0].n,
			'CMS blocks': (await q('SELECT COUNT(*) AS n FROM cms_block'))[0].n,
			'translation links': (await q('SELECT COUNT(*) AS n FROM supertext_translation_link'))[0].n,
		},
	};
}

// --- The admin, as the editor -------------------------------------------------------------
const browser = await chromium.launch({ executablePath: process.env.CHROMIUM_PATH || undefined });
const page = await browser.newPage({ viewport: { width: 1280, height: 1000 }, locale: 'en-US' });
page.setDefaultTimeout(180_000);
const go = async (url) => { await page.goto(url); await page.waitForLoadState('networkidle'); };
const menu = async (item) => page.locator(`li.${item} > a`).first().getAttribute('href');
const grid = async () => { await page.locator('.data-grid tbody tr.data-row').first().waitFor(); await page.waitForTimeout(800); };
const pageErrors = async () => (await page.locator('#messages .message-error, .messages .message-error').allTextContents()).map((t) => t.replace(/\s+/g, ' ').trim()).filter(Boolean);

await page.goto(`${B}/admin/`);
await page.fill('#username', need('EDITOR_EMAIL'));
await page.fill('#login', need('EDITOR_PASSWORD'));
await Promise.all([page.waitForNavigation(), page.click('.action-login')]);
await page.waitForLoadState('networkidle');
if (!check('editor can log in (DEMO_EDITOR_*)', (await page.locator('#login').count()) === 0 && (await page.locator('li.item-catalog-products > a').count()) > 0)) {
	await page.screenshot({ path: `${artifacts}login.png` });
	throw new Error(failures.join('\n'));
}
const urls = {
	products: await menu('item-catalog-products'),
	categories: await menu('item-catalog-categories'),
	pages: await menu('item-cms-page'),
	blocks: await menu('item-cms-block'),
};

/** Selects grid rows and runs the mass action "Translate with Supertext". */
async function massAction(listUrl, rowFilters) {
	await go(listUrl);
	await grid();
	for (const [what, filter] of rowFilters) {
		const row = page.locator('tr.data-row').filter(filter);
		check(`grid shows ${what} once`, (await row.count()) === 1, `${await row.count()} rows`);
		await row.first().locator('input[type=checkbox]').check({ force: true });
	}
	await page.locator('button.action-select', { hasText: 'Actions' }).first().click();
	await page.locator('.action-menu-item:visible', { hasText: 'Translate with Supertext' }).first().click();
	await page.waitForURL(/supertext\/translate\/index/);
	await page.waitForLoadState('networkidle');
}
const byId = (what, id) => [`${what} #${id}`, { has: page.locator('td', { hasText: new RegExp(`^\\s*${id}\\s*$`) }) }];

/** On the translate page: tick every store view, translate, check the result. */
async function translate(label, items, overwrite) {
	const root = page.locator('[data-role="supertext-translate"]');
	await root.waitFor();
	const warnings = (await root.locator(':scope > .message').allTextContents()).map((t) => t.replace(/\s+/g, ' ').trim());
	check(`${label}: translate page without warnings`, warnings.length === 0, warnings.join(' | '));
	check(`${label}: ${items} item(s) listed`, (await root.locator('.supertext-table tbody tr').count()) === items);
	for (const s of stores) {
		const box = root.locator(`#supertext-store-${s.store_id}`);
		const usable = (await box.count()) === 1 && !(await box.isDisabled());
		const code = usable ? (await root.locator(`label[for="supertext-store-${s.store_id}"] code`).textContent())?.trim() : '';
		check(`${label}: ${s.code} has the Supertext language ${s.lang}`, usable && code === s.lang, code);
		if (usable) await box.check();
	}
	if (overwrite) await root.locator('#supertext-overwrite').check();
	const started = Date.now();
	await root.locator('.supertext-go').click();
	await root.locator('.supertext-summary.message-success, .supertext-summary.message-warning').waitFor({ timeout: 1_200_000 });
	await page.screenshot({ path: `${artifacts}${label.replace(/\W+/g, '-')}.png`, fullPage: true });
	const summary = (await root.locator('.supertext-summary').textContent()).replace(/\s+/g, ' ').trim();
	console.log(`${label}: ${Math.round((Date.now() - started) / 1000)} s — ${summary}`);
	const errors = (await root.locator('.supertext-table .supertext-state.error').allTextContents()).map((t) => t.replace(/\s+/g, ' ').trim());
	check(`${label}: no errors from Supertext`, errors.length === 0, errors.join(' | '));
	let ok = 0;
	for (const s of stores) ok += await root.locator(`.supertext-table td[data-store="${s.store_id}"] .supertext-state.done, .supertext-table td[data-store="${s.store_id}"] .supertext-state.translated`).count();
	check(`${label}: every item translated into every store view`, ok === items * stores.length, `${ok}/${items * stores.length}`);
	const shown = await pageErrors();
	check(`${label}: no error message shown`, shown.length === 0, shown.join(' | '));
}

async function translateEverything(run, overwrite) {
	await massAction(urls.products, sample.products.map((p) => [`product ${p.sku}`, { hasText: p.sku }]));
	await translate(`${run} products`, sample.products.length, overwrite);

	await go(urls.categories);
	await page.locator('.jstree a', { hasText: sample.category.name }).first().click();
	await page.waitForLoadState('networkidle');
	await page.locator('button', { hasText: 'Translate with Supertext' }).click();
	await page.waitForURL(/supertext\/translate\/index/);
	await page.waitForLoadState('networkidle');
	await translate(`${run} category`, 1, overwrite);

	await massAction(urls.pages, [byId('CMS page', cmsPage.page_id)]);
	await translate(`${run} CMS page`, 1, overwrite);

	await massAction(urls.blocks, [byId('CMS block', cmsBlock.block_id)]);
	await translate(`${run} CMS block`, 1, overwrite);
}

// --- First translation -------------------------------------------------------------------
await translateEverything('first run', false);
const first = await snapshot();

/** Checks one translated item's fields against the source; returns its visible text. */
function compare(tag, kind, source, target, stats) {
	let text = '';
	for (const f of FIELDS[kind]) {
		const s = String(source[f] ?? '');
		if (!plain(s).trim()) continue;
		const v = String(target[f] ?? '');
		stats.fields++;
		if (!check(`${tag}: ${f} filled`, plain(v).trim() !== '')) continue;
		if (v.trim() !== s.trim()) stats.changed++;
		else note(`${tag}: ${f} unchanged ("${plain(s).trim().slice(0, 60)}")`);
		text += ` ${plain(v)}`;
		const left = LEFTOVERS.filter((l) => v.includes(l));
		if (!s.includes('translate="no"') && v.includes('translate="no"')) left.push('translate="no"');
		check(`${tag}: ${f} has nothing of the protocol left`, left.length === 0, left.join(', '));
		for (const [what, needle] of KEPT) {
			if (count(s, needle)) check(`${tag}: ${f} keeps its ${what}`, count(v, needle) === count(s, needle), `${count(v, needle)}/${count(s, needle)}`);
		}
		for (const d of directives(s)) check(`${tag}: ${f} keeps the directive ${d}`, v.includes(d));
	}
	return text;
}

for (const s of stores) {
	const lang = s.lang.split('-')[0];
	const tag = `${s.code} (${s.lang})`;
	const stats = { fields: 0, changed: 0 };
	let text = '';

	for (const p of products) {
		const value = (store, f) => first.product.get(`${p.entity_id}|${store}|${f}`);
		const src = Object.fromEntries(FIELDS.product.map((f) => [f, value(0, f)]));
		const dst = Object.fromEntries(FIELDS.product.map((f) => [f, value(s.store_id, f)]));
		check(`${tag}: product ${p.sku} has store-view values`, FIELDS.product.some((f) => dst[f] !== undefined));
		text += compare(`${tag}: product ${p.sku}`, 'product', src, dst, stats);
		if (value(s.store_id, 'url_key') === undefined || value(s.store_id, 'url_key') === value(0, 'url_key')) note(`${tag}: product ${p.sku} keeps the URL key ${value(0, 'url_key')}`);
		check(`${tag}: product ${p.sku} status not changed in the store view`, value(s.store_id, 'status') === undefined);
	}

	const cv = (store, f) => first.category.get(`${category.entity_id}|${store}|${f}`);
	const csrc = Object.fromEntries(FIELDS.category.map((f) => [f, cv(0, f)]));
	const cdst = Object.fromEntries(FIELDS.category.map((f) => [f, cv(s.store_id, f)]));
	check(`${tag}: category has store-view values`, FIELDS.category.some((f) => cdst[f] !== undefined));
	text += compare(`${tag}: category`, 'category', csrc, cdst, stats);
	check(`${tag}: category status not changed in the store view`, cv(s.store_id, 'is_active') === undefined);

	for (const [kind, label, source, rows, idCol] of [['cms_page', 'page', cmsPage, first.pages, 'page_id'], ['cms_block', 'block', cmsBlock, first.blocks, 'block_id']]) {
		const link = first.links.find((l) => l.entity_type === kind && l.source_id === source[idCol] && l.store_id === s.store_id);
		const copy = link && rows.find((r) => r[idCol] === link.target_id);
		if (!check(`${tag}: CMS ${label} has a copy for the store view`, !!copy, link ? `link → #${link.target_id}` : 'no link')) continue;
		check(`${tag}: CMS ${label} copy is a separate ${label}`, copy[idCol] !== source[idCol]);
		check(`${tag}: CMS ${label} copy is assigned to this store view only`, rows.filter((r) => r[idCol] === copy[idCol]).map((r) => r.store).join(',') === String(s.store_id));
		// Default setting "Status of new translations: Same as the original".
		check(`${tag}: CMS ${label} copy has the original's status`, Number(copy.is_active) === Number(source.is_active), `${copy.is_active}/${source.is_active}`);
		text += compare(`${tag}: CMS ${label}`, kind, source, copy, stats);
	}

	check(`${tag}: texts were changed by the translation`, stats.fields > 0 && stats.changed / stats.fields >= 0.8, `${stats.changed}/${stats.fields} fields`);
	if (STOPWORDS[lang]) {
		const scores = Object.fromEntries(Object.keys(STOPWORDS).map((l) => [l, score(text, l)]));
		const best = Object.entries(scores).sort((a, b) => b[1] - a[1])[0][0];
		check(`${tag}: text is in the target language`, best === lang, JSON.stringify(scores));
	}
	if (/^de-ch$/i.test(s.lang)) check(`${tag}: Swiss German spelling (no "ß")`, !text.includes('ß'));
}
check('the original CMS page is still there', first.pages.some((r) => r.page_id === cmsPage.page_id));
check('the original CMS block is still there', first.blocks.some((r) => r.block_id === cmsBlock.block_id));

// --- Translating again (overwrite) updates in place ---------------------------------------
await translateEverything('second run', true);
const second = await snapshot();
for (const [what, n] of Object.entries(first.counts)) {
	check(`translating again updates the ${what} in place (no duplicates)`, second.counts[what] === n, `${n} → ${second.counts[what]}`);
}
const linkIds = (s) => s.links.map((l) => `${l.entity_type}:${l.source_id}:${l.store_id}→${l.target_id}`).join(',');
check('translating again keeps the same CMS copies', linkIds(first) === linkIds(second), `${linkIds(first)} → ${linkIds(second)}`);
for (const s of stores) {
	for (const p of products) {
		const filled = FIELDS.product.every((f) => !plain(first.product.get(`${p.entity_id}|0|${f}`)).trim() || plain(second.product.get(`${p.entity_id}|${s.store_id}|${f}`)).trim());
		check(`${s.code}: product ${p.sku} still translated after the second run`, filled);
	}
}

await browser.close();
await db.end();

if (failures.length) {
	console.error(`\n${failures.length} check(s) failed:\n- ${failures.join('\n- ')}`);
	process.exit(1);
}
console.log('\nAll end-to-end checks passed.');
