/**
 * SPDX-FileCopyrightText: 2026 Co-Optimize
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { getLanguage, n, t } from '@nextcloud/l10n'

/** Lookup helpers shared by the components. */

export function optionLabel(options, key) {
	return options?.find(o => o.key === key)?.label ?? key
}

export function agentName(meta, uid) {
	if (!uid) {
		return ''
	}
	return meta?.agents?.find(a => a.uid === uid)?.displayName ?? uid
}

export function teamOf(meta, id) {
	return meta?.teams?.find(team => team.id === id) ?? null
}

export function tagOf(meta, id) {
	return meta?.tags?.find(tag => tag.id === id) ?? null
}

/** Agents who work on a brand (admins work on all). */
export function agentsForTeam(meta, teamId) {
	return (meta?.agents ?? []).filter(agent => agent.teamIds === null || agent.teamIds.includes(teamId))
}

/** Readable text color (black or white) on a background color like #0082c9. */
export function textOn(color) {
	const hex = /^#([0-9a-f]{6})$/i.exec(color || '')
	if (!hex) {
		return 'inherit'
	}
	const value = parseInt(hex[1], 16)
	const [r, g, b] = [value >> 16, (value >> 8) & 255, value & 255]
	return (r * 299 + g * 587 + b * 114) / 1000 > 150 ? '#000' : '#fff'
}

/**
 * Snooze choices: later today, tomorrow morning, next Monday morning.
 * Each is a unix timestamp, computed from "now" in the agent's own timezone.
 */
export function snoozePresets(now = new Date()) {
	const at = (date, hours) => {
		const d = new Date(date)
		d.setHours(hours, 0, 0, 0)
		return Math.floor(d.getTime() / 1000)
	}
	const tomorrow = new Date(now)
	tomorrow.setDate(now.getDate() + 1)
	const monday = new Date(now)
	monday.setDate(now.getDate() + ((8 - now.getDay()) % 7 || 7))
	const nextWeek = new Date(now)
	nextWeek.setDate(now.getDate() + 7)
	return [
		{ id: 'later', label: t('ticketsystem', 'In 3 hours'), until: Math.floor(now.getTime() / 1000) + 3 * 3600 },
		{ id: 'tomorrow', label: t('ticketsystem', 'Tomorrow morning'), until: at(tomorrow, 8) },
		{ id: 'monday', label: t('ticketsystem', 'Next Monday morning'), until: at(monday, 8) },
		{ id: 'week', label: t('ticketsystem', 'In a week'), until: at(nextWeek, 8) },
	]
}

export function isClosedStatus(meta, key) {
	return !!meta?.statuses?.find(s => s.key === key)?.closed
}

export function customerLabel(ticket) {
	if (ticket.customerName && ticket.customerEmail) {
		return `${ticket.customerName} <${ticket.customerEmail}>`
	}
	return ticket.customerName || ticket.customerEmail || ''
}

/** Placeholders agents can use in canned replies, with what each becomes. */
export const PLACEHOLDERS = [
	'customer_name', 'customer_first_name', 'customer_email',
	'ticket_ref', 'ticket_subject', 'agent_name', 'agent_first_name', 'company',
]

export const firstWord = (name) => (name || '').trim().split(/\s+/)[0] || ''

/**
 * Fills {placeholder} fields of a canned reply for one ticket. Unknown
 * placeholders stay as typed; an empty value leaves no stray space before
 * punctuation ("Hej {customer_first_name}," → "Hej,").
 */
export function fillPlaceholders(text, ticket, meta) {
	const agent = agentName(meta, meta?.currentUser)
	const values = {
		customer_name: ticket?.customerName || '',
		customer_first_name: firstWord(ticket?.customerName),
		customer_email: ticket?.customerEmail || '',
		ticket_ref: ticket?.reference || '',
		ticket_subject: ticket?.subject || '',
		agent_name: agent,
		agent_first_name: firstWord(agent),
		company: meta?.senderName || '',
	}
	// Empty values become a marker first, so only spaces next to them are tidied.
	const EMPTY = '\u0000'
	return text
		.replace(/\{([a-z_]+)\}/g, (match, key) => (key in values ? (values[key] || EMPTY) : match))
		.replace(/[ \t]*\u0000[ \t]*(?=[,.!?;:])/g, '')
		.replace(/^[ \t]*\u0000[ \t]*/gm, '')
		.replace(/[ \t]*\u0000[ \t]*/g, ' ')
}

/**
 * Who wrote the newest message of a ticket, as the list preview shows it:
 * "You", the agent's first name, or the customer's first name / address.
 */
export function previewAuthor(ticket, meta) {
	const last = ticket.lastMessage
	if (!last) {
		return ''
	}
	if (last.authorType === 'customer') {
		return firstWord(ticket.customerName) || ticket.customerEmail || ''
	}
	if (last.authorUid && last.authorUid === meta?.currentUser) {
		return t('ticketsystem', 'You')
	}
	return firstWord(agentName(meta, last.authorUid))
}

/** The filter a fresh list starts from: open tickets, newest activity first. */
export const EMPTY_FILTER = {
	scope: 'open',
	statuses: [],
	assignee: null,
	teamIds: [],
	tagIds: [],
	priorities: [],
	needsReply: false,
	snoozed: null,
	sort: 'updated',
}

/** Fills in missing filter keys, like the server's ViewService::normalize(). */
export function normalizeFilter(filter) {
	return { ...EMPTY_FILTER, ...(filter || {}) }
}

export function sameFilter(a, b) {
	const x = normalizeFilter(a)
	const y = normalizeFilter(b)
	return Object.keys(EMPTY_FILTER).every(key => JSON.stringify(x[key]) === JSON.stringify(y[key]))
}

/**
 * The parts of a filter worth showing as removable chips above the list,
 * each with the change that removes it.
 */
export function filterChips(filter, meta, base = EMPTY_FILTER) {
	const f = normalizeFilter(filter)
	const b = normalizeFilter(base)
	const same = (...keys) => keys.every(key => JSON.stringify(f[key]) === JSON.stringify(b[key]))
	return allChips(f, meta).filter(chip => !same(...chip.keys))
}

function allChips(f, meta) {
	const chips = []
	if (f.statuses.length) {
		chips.push({ keys: ['statuses', 'scope'], id: 'statuses', label: f.statuses.map(key => optionLabel(meta?.statuses, key)).join(', '), reset: { statuses: [], scope: 'open' } })
	} else if (f.scope === 'active') {
		chips.push({ keys: ['statuses', 'scope'], id: 'scope', label: t('ticketsystem', 'Not waiting for the customer'), reset: { scope: 'open' } })
	} else if (f.scope === 'open') {
		// Only differs from views that hide tickets waiting for the customer.
		chips.push({ keys: ['statuses', 'scope'], id: 'scope', label: t('ticketsystem', 'Also waiting for the customer'), reset: { scope: 'active' } })
	} else if (f.scope === 'closed') {
		chips.push({ keys: ['statuses', 'scope'], id: 'scope', label: t('ticketsystem', 'Done tickets'), reset: { scope: 'open' } })
	} else if (f.scope === 'any') {
		chips.push({ keys: ['statuses', 'scope'], id: 'scope', label: t('ticketsystem', 'Any status'), reset: { scope: 'open' } })
	}
	if (f.assignee === 'me') {
		chips.push({ keys: ['assignee'], id: 'assignee', label: t('ticketsystem', 'Assigned to me'), reset: { assignee: null } })
	} else if (f.assignee === 'none') {
		chips.push({ keys: ['assignee'], id: 'assignee', label: t('ticketsystem', 'Unassigned'), reset: { assignee: null } })
	} else if (f.assignee) {
		chips.push({ keys: ['assignee'], id: 'assignee', label: t('ticketsystem', 'Assigned to {name}', { name: agentName(meta, f.assignee) }), reset: { assignee: null } })
	}
	if (f.teamIds.length) {
		chips.push({ keys: ['teamIds'], id: 'teams', label: f.teamIds.map(id => teamOf(meta, id)?.name ?? '#' + id).join(', '), reset: { teamIds: [] } })
	}
	for (const id of f.tagIds) {
		chips.push({ keys: ['tagIds'], id: 'tag' + id, label: tagOf(meta, id)?.name ?? t('ticketsystem', 'Deleted tag'), tag: tagOf(meta, id), reset: { tagIds: f.tagIds.filter(x => x !== id) } })
	}
	if (f.priorities.length) {
		chips.push({ keys: ['priorities'], id: 'priorities', label: f.priorities.map(key => optionLabel(meta?.priorities, key)).join(', '), reset: { priorities: [] } })
	}
	if (f.needsReply) {
		chips.push({ keys: ['needsReply'], id: 'needsReply', label: t('ticketsystem', 'Needs reply'), reset: { needsReply: false } })
	}
	if (f.snoozed === 'only') {
		chips.push({ keys: ['snoozed'], id: 'snoozed', label: t('ticketsystem', 'Snoozed'), reset: { snoozed: null } })
	}
	if (f.sort === 'created') {
		chips.push({ keys: ['sort'], id: 'sort', label: t('ticketsystem', 'Newest first'), reset: { sort: 'updated' } })
	} else if (f.sort === 'waiting') {
		chips.push({ keys: ['sort'], id: 'sort', label: t('ticketsystem', 'Waiting longest first'), reset: { sort: 'updated' } })
	}
	return chips
}

/** A file size for people: "512 B", "3.5 KB", "1.2 MB" (Danish: "1,2 MB"). */
export function formatBytes(bytes, language = getLanguage()) {
	const units = ['B', 'KB', 'MB', 'GB']
	let value = Math.max(0, bytes || 0)
	let unit = 0
	while (value >= 1024 && unit < units.length - 1) {
		value /= 1024
		unit++
	}
	const digits = unit === 0 || value >= 10 ? 0 : 1
	return new Intl.NumberFormat(language, { maximumFractionDigits: digits }).format(value) + ' ' + units[unit]
}

/** A duration for people: "45 min", "3 h 20 min", "2 d 4 h". */
export function formatDuration(seconds) {
	if (seconds === null || seconds === undefined) {
		return '–'
	}
	const minutes = Math.max(0, Math.round(seconds / 60))
	if (minutes < 60) {
		return n('ticketsystem', '%n min', '%n min', minutes)
	}
	const hours = Math.floor(minutes / 60)
	if (hours < 24) {
		const rest = minutes % 60
		return rest ? t('ticketsystem', '{h} h {m} min', { h: hours, m: rest }) : t('ticketsystem', '{h} h', { h: hours })
	}
	const days = Math.floor(hours / 24)
	const restHours = hours % 24
	return restHours ? t('ticketsystem', '{d} d {h} h', { d: days, h: restHours }) : n('ticketsystem', '%n day', '%n days', days)
}

/**
 * How a ticket stands at a glance: 'paused' (snoozed or waiting for the
 * customer), or null. Done tickets are not paused.
 *
 * @param {object} ticket
 * @param {object} meta
 * @return {'paused'|null}
 */
export function ticketState(ticket, meta, now = Date.now()) {
	if (!ticket || isClosedStatus(meta, ticket.status)) {
		return null
	}
	if ((ticket.snoozedUntil && ticket.snoozedUntil * 1000 > now) || ticket.status === 'pending') {
		return 'paused'
	}
	return null
}

/**
 * Suggested times to send a reply later.
 *
 * @param {Date} now
 * @return {Array<{id: string, at: Date}>}
 */
export function schedulePresets(now = new Date()) {
	const at = (daysAhead, hour, minute = 0) => {
		const d = new Date(now)
		d.setDate(d.getDate() + daysAhead)
		d.setHours(hour, minute, 0, 0)
		return d
	}
	const list = []
	// Early morning hours still count as "tonight": 02:00 suggests 07:00 the same day.
	const early = now.getHours() < 6
	list.push({ id: 'tomorrow-morning', at: at(early ? 0 : 1, 7) })
	list.push({ id: 'tomorrow-afternoon', at: at(early ? 0 : 1, 13) })
	const toMonday = ((8 - now.getDay()) % 7) || 7
	list.push({ id: 'monday', at: at(toMonday, 7) })
	// Same time next week, or 07:00 if that would be at night.
	const week = new Date(now.getTime() + 7 * 86400 * 1000)
	if (week.getHours() < 7 || week.getHours() >= 20) {
		week.setHours(7, 0, 0, 0)
	} else {
		week.setMinutes(Math.ceil(week.getMinutes() / 15) * 15, 0, 0)
	}
	list.push({ id: 'week', at: week })
	// Drop duplicates and anything not in the future.
	const seen = new Set()
	return list.filter(p => {
		const key = p.at.getTime()
		if (key <= now.getTime() + 60 * 1000 || seen.has(key)) {
			return false
		}
		seen.add(key)
		return true
	})
}
