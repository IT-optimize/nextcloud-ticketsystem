/**
 * SPDX-FileCopyrightText: 2026 Co-Optimize
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { test } from 'node:test'
import assert from 'node:assert/strict'
import { amountFor, decimalHours, parseDuration, parseMoney, timerMinutes } from '../../src/time.js'

test('reads the ways agents type time', () => {
	const cases = { 45: 45, '1:30': 90, '0:05': 5, '1h 30m': 90, '1t 30m': 90, '2t': 120, '1.5h': 90, '1,5 t': 90, '90m': 90, '90 min': 90, '1 time': 60, '2 timer': 120, '15 minutter': 15 }
	for (const [typed, minutes] of Object.entries(cases)) {
		assert.equal(parseDuration(typed), minutes, typed)
	}
})

test('rejects nonsense and impossible durations', () => {
	for (const typed of ['', 'abc', '0', '-5', '25h', '1:75', 'h']) {
		assert.equal(parseDuration(typed), null, typed)
	}
})

test('prices time and converts to decimal hours', () => {
	assert.equal(amountFor(45, 95000), 71250)
	assert.equal(amountFor(45, null), null)
	assert.equal(decimalHours(90), 1.5)
	assert.equal(decimalHours(20), 0.33)
})

test('reads money in Danish and English notation', () => {
	assert.equal(parseMoney('950'), 95000)
	assert.equal(parseMoney('950,50'), 95050)
	assert.equal(parseMoney('1.250,00'), 125000)
	assert.equal(parseMoney('1,250.00'), 125000)
	assert.equal(parseMoney('1.250'), 125000)
	assert.equal(parseMoney('950.5'), 95050)
	assert.equal(parseMoney(''), null)
	assert.ok(Number.isNaN(parseMoney('abc')))
})

test('timer rounds up to whole minutes', () => {
	assert.equal(timerMinutes(0, 1000), 1)
	assert.equal(timerMinutes(0, 61000), 2)
})
