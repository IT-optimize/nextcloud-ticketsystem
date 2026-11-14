/**
 * SPDX-FileCopyrightText: 2026 Co-Optimize
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/**
 * Unsent replies per ticket, kept in this browser so switching tickets or
 * reloading doesn't lose them. Storage can be unavailable (private windows,
 * blocked site data); then drafts simply aren't kept.
 */
const key = (ticketId) => `ticketsystem-draft-${ticketId}`

export function loadDraft(ticketId) {
	try {
		const stored = JSON.parse(window.localStorage.getItem(key(ticketId)) || 'null')
		if (stored && typeof stored.text === 'string') {
			return { text: stored.text, mode: stored.mode === 'internal' ? 'internal' : 'public' }
		}
	} catch {
		// No draft then.
	}
	return null
}

export function saveDraft(ticketId, text, mode) {
	try {
		if (text.trim() === '') {
			window.localStorage.removeItem(key(ticketId))
		} else {
			window.localStorage.setItem(key(ticketId), JSON.stringify({ text, mode, savedAt: Date.now() }))
		}
	} catch {
		// Not kept; nothing else to do.
	}
}

export function clearDraft(ticketId) {
	saveDraft(ticketId, '', 'public')
}
