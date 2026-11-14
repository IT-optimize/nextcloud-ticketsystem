/**
 * SPDX-FileCopyrightText: 2026 Co-Optimize
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { test } from 'node:test'
import assert from 'node:assert/strict'
import { agentsForTeam, EMPTY_FILTER, filterChips, normalizeFilter, sameFilter, snoozePresets, textOn } from '../../src/format.js'

const meta = {
	currentUser: 'alice',
	agents: [
		{ uid: 'alice', displayName: 'Alice Agent', teamIds: [1] },
		{ uid: 'dina', displayName: 'Dina Coop', teamIds: [2] },
		{ uid: 'admin', displayName: 'Admin', teamIds: null },
	],
	teams: [{ id: 1, name: 'Support' }, { id: 2, name: 'Co-Optimize' }],
	tags: [{ id: 7, name: 'Faktura', color: '#c62828' }],
	statuses: [{ key: 'open', label: 'Open' }],
	priorities: [{ key: 'high', label: 'High' }],
	types: [],
}

test('normalizeFilter fills in defaults and sameFilter compares meaningfully', () => {
	assert.deepEqual(normalizeFilter({ assignee: 'me' }), { ...EMPTY_FILTER, assignee: 'me' })
	assert.ok(sameFilter({}, EMPTY_FILTER))
	assert.ok(!sameFilter({ tagIds: [7] }, {}))
})

test('filterChips shows only what differs from the view', () => {
	const view = { scope: 'any', snoozed: 'any' }
	assert.deepEqual(filterChips(view, meta, view), [])
	const chips = filterChips({ ...view, tagIds: [7], assignee: 'dina' }, meta, view)
	assert.deepEqual(chips.map(c => c.label), ['Assigned to Dina Coop', 'Faktura'])
	// Removing a chip goes back to the view's own value.
	const tag = chips.find(c => c.id === 'tag7')
	assert.deepEqual(tag.reset, { tagIds: [] })
})

test('agentsForTeam includes admins and the brand\'s own agents only', () => {
	assert.deepEqual(agentsForTeam(meta, 2).map(a => a.uid), ['dina', 'admin'])
})

test('snoozePresets are in the future and in order', () => {
	const now = new Date(2026, 9, 29, 17, 0) // Thursday afternoon
	const presets = snoozePresets(now)
	const ts = presets.map(p => p.until)
	assert.ok(ts.every(x => x > now.getTime() / 1000))
	const monday = new Date(presets.find(p => p.id === 'monday').until * 1000)
	assert.equal(monday.getDay(), 1)
	assert.equal(monday.getHours(), 8)
})

test('textOn picks readable text', () => {
	assert.equal(textOn('#ffffff'), '#000')
	assert.equal(textOn('#0082c9'), '#fff')
	assert.equal(textOn('nope'), 'inherit')
})

test('formatDuration', async () => {
	const { formatDuration } = await import('../../src/format.js')
	assert.equal(formatDuration(45 * 60), '45 min')
	assert.equal(formatDuration(3 * 3600 + 20 * 60), '3 h 20 min')
	assert.equal(formatDuration(2 * 86400 + 4 * 3600), '2 d 4 h')
})
