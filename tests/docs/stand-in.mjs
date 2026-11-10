#!/usr/bin/env node
/**
 * Docs screenshots and CI only: answers like the Supertext AI file translation API v1 and
 * returns German, French and Italian for the demo's sample products, category, page and block
 * (samples.json: human translations of demo/sample-content.json, keyed by the target
 * language's primary subtag).
 * Unknown text comes back as "[<target>] text" (logged as {"unknown": …}). Listens on :8765 (any API key).
 *
 * Also a fake Commando Center, so screenshots and CI never report to the live one: run Magento
 * with SUPERTEXT_REPORT_URL=http://127.0.0.1:8765/report. POST /report answers 202 and keeps the
 * report; GET /reports lists them.
 */
import http from 'node:http'
import { randomBytes } from 'node:crypto'
import { readFileSync } from 'node:fs'

const norm = (s) => s.replace(/\s+/g, ' ').replace(/> </g, '><').trim()
const decode = (s) => s.replace(/&quot;/g, '"').replace(/&#0?39;|&apos;/g, "'").replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&amp;/g, '&')
const encode = (s) => s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
const samples = Object.fromEntries(
  Object.entries(JSON.parse(readFileSync(new URL('samples.json', import.meta.url), 'utf8'))).map(([lang, pairs]) => [
    lang,
    new Map(Object.entries(pairs).map(([k, v]) => [norm(k), v])),
  ]),
)
const files = new Map()
const reports = []
// The target codes of Supertext's `GET features` (the SDK's Languages::TARGETS, October 2026).
const TARGETS = new Set(['bg', 'cs', 'da', 'de-AT', 'de-CH', 'de-DE', 'el', 'en-GB', 'en-US', 'es', 'fi', 'fr-CH', 'fr-FR',
  'gsw-u-sd-chbe', 'gsw-u-sd-chzh', 'hr', 'hu', 'it-CH', 'it-IT', 'ja', 'ko', 'nb', 'nl', 'pl', 'pt-BR', 'pt-PT', 'rm', 'ru',
  'sk', 'sl', 'sq', 'sr-Cyrl', 'sr-Latn', 'sv', 'tr', 'zh-Hans', 'zh-Hant'])

http
  .createServer(async (req, res) => {
    const path = new URL(req.url, 'http://x').pathname.replace(/^\/v1/, '')
    const send = (status, body, type = 'application/json') => {
      res.writeHead(status, { 'Content-Type': type })
      res.end(type === 'application/json' ? JSON.stringify(body) : body)
    }
    if (path === '/report' && req.method === 'POST') {
      const chunks = []
      for await (const chunk of req) chunks.push(chunk)
      reports.push(JSON.parse(Buffer.concat(chunks).toString('utf8')))
      return send(202, { ok: true })
    }
    if (path === '/reports') return send(200, reports)
    if (!/^Supertext-Auth-Key \S+$/.test(req.headers.authorization || '')) return send(401, { detail: 'missing key' })
    if (path === '/features') return send(200, {})
    if (req.method === 'POST') {
      const chunks = []
      for await (const chunk of req) chunks.push(chunk)
      const form = await new Request('http://x', { method: 'POST', headers: req.headers, body: Buffer.concat(chunks) }).formData()
      const file = form.get('file')
      if (!file || file.type !== 'text/html') return send(415, { detail: 'FILETYPE_NOT_ALLOWED' })
      // Like the live API: only Supertext's exact target codes (de-CH, es; not de, es-ES), bare sources (en).
      const target = String(form.get('target_lang') ?? '')
      const source = form.get('source_lang')
      if (!TARGETS.has(target) || (source !== null && !/^[a-z]{2,3}$/.test(String(source)))) {
        return send(404, { error_code: 'INVALID_LANGUAGE_PAIR', source_lang: source, target_lang: target, detail: `Invalid language pair ${source ?? 'auto'} → ${target}` })
      }
      const id = randomBytes(6).toString('hex')
      files.set(id, { html: await file.text(), target })
      return send(200, { file_id: id })
    }
    const match = path.match(/file\/([a-f0-9]+)(\/status|\/translation)?$/)
    const file = match && files.get(match[1])
    if (!file) return send(404, {})
    if (req.method === 'DELETE') { files.delete(match[1]); return send(200, {}) }
    if (match[2] === '/status') return send(200, { status: 'done' })
    const known = samples[file.target.split('-')[0]] ?? new Map()
    const html = file.html.replace(/(<div data-st-id="\d+">)([\s\S]*?)(<\/div>\n)/g, (all, open, inner, close) => {
      const hit = known.get(norm(inner)) ?? known.get(norm(decode(inner)))
      if (hit !== undefined) return open + (known.has(norm(inner)) ? hit : encode(hit)) + close
      console.log(JSON.stringify({ unknown: norm(inner), target: file.target }))
      return open + inner.replace(/>([^<]+)</g, (m, t) => (t.trim() ? `>[${file.target}] ${t}<` : m)).replace(/^([^<]+)/, `[${file.target}] $1`) + close
    })
    send(200, html, 'text/html')
  })
  .listen(Number(process.env.PORT || 8765), '127.0.0.1', () => console.log(`Stand-in API on http://127.0.0.1:${process.env.PORT || 8765}/v1/`))
