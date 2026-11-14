/**
 * SPDX-FileCopyrightText: 2026 Co-Optimize
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { test } from 'node:test'
import assert from 'node:assert/strict'
import { fillPlaceholders, formatBytes, ticketState } from '../../src/format.js'

const meta = {
	currentUser: 'alice',
	agents: [{ uid: 'alice', displayName: 'Alice Agent' }],
	senderName: 'Ticket system Support',
}
const ticket = { customerName: 'Karen Kunde', customerEmail: 'karen@kunde.dk', reference: '#7', subject: 'Faktura' }

test('fills every known placeholder', () => {
	assert.equal(
		fillPlaceholders('Hej {customer_first_name} ({customer_email}), {ticket_ref} {ticket_subject} – {agent_first_name}, {company}', ticket, meta),
		'Hej Karen (karen@kunde.dk), #7 Faktura – Alice, Ticket system Support',
	)
})

test('file sizes in English and Danish', () => {
	assert.equal(formatBytes(512, 'en'), '512 B')
	assert.equal(formatBytes(3584, 'en'), '3.5 KB')
	assert.equal(formatBytes(1258291, 'da'), '1,2 MB')
	assert.equal(formatBytes(20 * 1024 * 1024, 'en'), '20 MB')
	assert.equal(formatBytes(0, 'en'), '0 B')
})

test('ticket state: paused or nothing', () => {
	const m = {
		statuses: [{ key: 'open', closed: false }, { key: 'pending', closed: false }, { key: 'resolved', closed: true }],
		priorities: [{ key: 'low' }, { key: 'normal' }, { key: 'high' }, { key: 'urgent' }],
	}
	const now = Date.UTC(2026, 10, 4, 12) // seconds below are relative to this
	const s = now / 1000
	assert.equal(ticketState({ status: 'open', priority: 'normal' }, m, now), null)
	assert.equal(ticketState({ status: 'pending', priority: 'normal' }, m, now), 'paused')
	assert.equal(ticketState({ status: 'open', priority: 'normal', snoozedUntil: s + 3600 }, m, now), 'paused')
	assert.equal(ticketState({ status: 'resolved', priority: 'urgent' }, m, now), null, 'done tickets are neither')
})

test('leaves unknown placeholders as typed', () => {
	assert.equal(fillPlaceholders('{order_number}', ticket, meta), '{order_number}')
})

test('an empty name leaves no stray space before punctuation', () => {
	assert.equal(fillPlaceholders('Hej {customer_first_name}, tak!', {}, meta), 'Hej, tak!')
	assert.equal(fillPlaceholders('{customer_name}\nLinje 2', {}, meta), '\nLinje 2')
})

test('does not touch the agent\'s own spacing elsewhere', () => {
	assert.equal(fillPlaceholders('Pris : 100 kr .', ticket, meta), 'Pris : 100 kr .')
})

test('previewAuthor names who wrote last', async () => {
	const { previewAuthor } = await import('../../src/format.js')
	const meta = { currentUser: 'alice', agents: [{ uid: 'alice', displayName: 'Alice Agent' }, { uid: 'carl', displayName: 'Carl Agent' }] }
	const ticket = (lastMessage) => ({ customerName: 'Karen Kunde', customerEmail: 'karen@kunde.dk', lastMessage })
	assert.equal(previewAuthor(ticket({ authorType: 'customer' }), meta), 'Karen')
	assert.equal(previewAuthor({ ...ticket({ authorType: 'customer' }), customerName: null }, meta), 'karen@kunde.dk')
	assert.equal(previewAuthor(ticket({ authorType: 'agent', authorUid: 'alice' }), meta), 'You')
	assert.equal(previewAuthor(ticket({ authorType: 'agent', authorUid: 'carl' }), meta), 'Carl')
	assert.equal(previewAuthor(ticket(null), meta), '')
})
