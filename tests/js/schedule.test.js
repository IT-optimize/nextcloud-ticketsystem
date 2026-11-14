/**
 * SPDX-FileCopyrightText: 2026 Co-Optimize
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { test } from 'node:test'
import assert from 'node:assert/strict'
import { schedulePresets } from '../../src/format.js'

const local = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')} ${String(d.getHours()).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')}`

test('at 2 at night the morning suggestion is the same day', () => {
	const now = new Date(2026, 9, 29, 2, 13) // Thursday 02:13
	const presets = schedulePresets(now)
	assert.equal(local(presets.find(p => p.id === 'tomorrow-morning').at), '2026-10-29 07:00')
	assert.equal(local(presets.find(p => p.id === 'monday').at), '2026-11-02 07:00')
	assert.equal(local(presets.find(p => p.id === 'week').at), '2026-11-05 07:00')
})

test('no duplicates', () => {
	const now = new Date(2026, 10, 1, 10, 0) // Sunday 10:00: tomorrow is Monday
	const presets = schedulePresets(now)
	const times = presets.map(p => p.at.getTime())
	assert.equal(new Set(times).size, times.length)
	assert.ok(!presets.some(p => p.id === 'monday'), 'Monday 07:00 is tomorrow morning already')
})
