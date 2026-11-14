/**
 * SPDX-FileCopyrightText: 2026 Co-Optimize
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/** Durations and money for time registration. Pure functions, so they are easy to test. */

/**
 * Minutes from what an agent typed: "45", "1:30", "1h 30m", "1t 30m", "1.5h",
 * "1,5 t", "90m". Plain numbers are minutes. Returns null for anything else.
 */
export function parseDuration(text) {
	const value = String(text ?? '').trim().toLowerCase().replace(/\s+/g, ' ')
	if (value === '') {
		return null
	}
	let minutes = null
	let m
	if ((m = /^(\d+):([0-5]?\d)$/.exec(value))) {
		minutes = Number(m[1]) * 60 + Number(m[2])
	} else if ((m = /^(\d+(?:[.,]\d+)?) ?(?:h|t|timer?|hours?|hrs?)$/.exec(value))) {
		minutes = Math.round(Number(m[1].replace(',', '.')) * 60)
	} else if ((m = /^(?:(\d+) ?(?:h|t|timer?|hours?|hrs?))? ?(?:(\d+) ?(?:m|min|minutter|minutes?|mins?))?$/.exec(value)) && (m[1] || m[2])) {
		minutes = Number(m[1] ?? 0) * 60 + Number(m[2] ?? 0)
	} else if (/^\d+$/.test(value)) {
		minutes = Number(value)
	}
	return minutes !== null && minutes > 0 && minutes <= 24 * 60 ? minutes : null
}

/** "1 t 30 min" style, short: 0:45 → "45 min", 90 → "1 h 30 min" (units translated by the caller). */
export function splitMinutes(minutes) {
	const total = Math.max(0, Math.round(minutes || 0))
	return { hours: Math.floor(total / 60), minutes: total % 60 }
}

/** Hours with two decimals for spreadsheets: 90 → 1.5 */
export function decimalHours(minutes) {
	return Math.round((minutes || 0) / 60 * 100) / 100
}

/** Price of minutes at a rate per hour (both in minor units). */
export function amountFor(minutes, rate) {
	return rate == null ? null : Math.round(minutes * rate / 60)
}

/** Minor units (øre, cents) as money in the agent's language. */
export function formatMoney(minor, currency, language = 'en') {
	if (minor == null) {
		return '–'
	}
	try {
		return new Intl.NumberFormat(language, { style: 'currency', currency: currency || 'DKK' }).format(minor / 100)
	} catch {
		return (minor / 100).toFixed(2) + ' ' + (currency || '')
	}
}

/** "950" or "950,50" typed in a rate field → minor units; null for empty, NaN for nonsense. */
export function parseMoney(text) {
	const value = String(text ?? '').trim().replace(/\s/g, '')
	if (value === '') {
		return null
	}
	// Accept both 1.234,50 and 1,234.50 and plain 950,5.
	let normalized
	if (/,\d{1,2}$/.test(value)) {
		normalized = value.replace(/\./g, '').replace(',', '.')
	} else if (/^\d{1,3}(\.\d{3})+$/.test(value)) {
		// 1.250 is Danish for one thousand two hundred and fifty.
		normalized = value.replace(/\./g, '')
	} else {
		normalized = value.replace(/,/g, '')
	}
	const number = Number(normalized)
	return Number.isFinite(number) && number >= 0 ? Math.round(number * 100) : NaN
}

/** Elapsed whole minutes of a running timer, at least 1. */
export function timerMinutes(startedAt, now = Date.now()) {
	return Math.max(1, Math.ceil((now - startedAt) / 60000))
}
