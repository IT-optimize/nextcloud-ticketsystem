/**
 * SPDX-FileCopyrightText: 2026 Co-Optimize
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { test } from 'node:test'
import assert from 'node:assert/strict'
import { readdirSync, readFileSync, statSync } from 'node:fs'
import { join } from 'node:path'

const app = new URL('../../', import.meta.url).pathname.replace(/^\/([A-Za-z]:)/, '$1')
const read = (file) => readFileSync(join(app, file), 'utf8')
const json = JSON.parse(read('l10n/da.json')).translations
let js = {}
// da.js calls OC.L10N.register(app, translations, plural).
new Function('OC', read('l10n/da.js'))({ L10N: { register: (_, translations) => { js = translations } } })

function sourceFiles(dir) {
	return readdirSync(join(app, dir)).flatMap((name) => {
		const path = join(dir, name)
		return statSync(join(app, path)).isDirectory() ? sourceFiles(path) : (/\.(vue|js)$/.test(name) ? [path] : [])
	})
}

test('every interface text has a Danish translation', () => {
	const missing = []
	for (const file of sourceFiles('src')) {
		for (const match of read(file).matchAll(/\bt\(\s*'ticketsystem',\s*'((?:[^'\\]|\\.)*)'/g)) {
			const key = match[1].replace(/\\'/g, '\'')
			if (!(key in json)) {
				missing.push(`${key}  (${file})`)
			}
		}
	}
	assert.deepEqual(missing, [])
})

test('da.json and da.js hold the same translations', () => {
	assert.deepEqual(Object.keys(js).sort(), Object.keys(json).sort())
	for (const key of Object.keys(json)) {
		assert.deepEqual(js[key], json[key], key)
	}
})

test('no text is translated twice', () => {
	for (const file of ['l10n/da.json', 'l10n/da.js']) {
		const keys = [...read(file).matchAll(/^\s*("(?:[^"\\]|\\.)*")\s*:/gm)].map(m => m[1])
		assert.deepEqual(keys.filter((k, i) => keys.indexOf(k) !== i), [], file)
	}
})
