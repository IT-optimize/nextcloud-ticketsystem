<!--
  - SPDX-FileCopyrightText: 2026 Co-Optimize
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="timepage">
		<header class="timepage__head">
			<h2>{{ t('ticketsystem', 'Time and invoicing') }}</h2>
			<div class="timepage__tabs" role="tablist">
				<NcButton v-for="item in tabs"
					:key="item.id"
					role="tab"
					:aria-selected="tab === item.id"
					:variant="tab === item.id ? 'primary' : 'tertiary'"
					@click="tab = item.id">
					{{ item.label }}
				</NcButton>
			</div>
		</header>

		<CompaniesPanel v-if="tab === 'companies'" :meta="meta" @changed="$emit('companies-changed')" />

		<template v-else>
			<div class="timepage__filters">
				<NcSelect v-model="preset"
					:options="presets"
					label="label"
					:clearable="false"
					:input-label="t('ticketsystem', 'Period')" />
				<template v-if="preset.id === 'custom'">
					<NcDateTimePickerNative id="time-from"
						v-model="customFrom"
						type="date"
						:label="t('ticketsystem', 'From')" />
					<NcDateTimePickerNative id="time-to"
						v-model="customTo"
						type="date"
						:label="t('ticketsystem', 'To')" />
				</template>
				<NcSelect v-if="meta.teams.length > 1"
					v-model="team"
					:options="[allBrands, ...meta.teams]"
					label="name"
					:clearable="false"
					:input-label="t('ticketsystem', 'Brand')" />
				<NcSelect v-model="company"
					:options="companyOptions"
					label="name"
					:clearable="false"
					:input-label="t('ticketsystem', 'Company')" />
				<NcSelect v-model="agent"
					:options="agentOptions"
					label="displayName"
					:clearable="false"
					:input-label="t('ticketsystem', 'Agent')" />
				<NcSelect v-model="billing"
					:options="billingOptions"
					label="label"
					:clearable="false"
					:input-label="t('ticketsystem', 'Show')" />
			</div>

			<NcLoadingIcon v-if="loading && !result" class="timepage__loading" :size="40" />
			<NcEmptyContent v-else-if="error" :name="error">
				<template #icon>
					<ClockIcon />
				</template>
			</NcEmptyContent>

			<template v-else-if="result">
				<section class="tiles" :aria-label="t('ticketsystem', 'Totals')">
					<div class="tile">
						<span class="tile__label">{{ t('ticketsystem', 'Time logged') }}</span>
						<span class="tile__value">{{ hoursText(totals.minutes) }}</span>
						<span class="tile__note">{{ n('ticketsystem', '%n entry', '%n entries', entries.length) }}</span>
					</div>
					<div class="tile">
						<span class="tile__label">{{ t('ticketsystem', 'Billable') }}</span>
						<span class="tile__value">{{ hoursText(totals.billableMinutes) }}</span>
						<span class="tile__note">{{ totals.minutes ? t('ticketsystem', '{p} of the time', { p: percent(totals.billableMinutes / totals.minutes) }) : '–' }}</span>
					</div>
					<div class="tile">
						<span class="tile__label">{{ t('ticketsystem', 'Amount') }}</span>
						<span class="tile__value">{{ moneyText(totals.amount) }}</span>
						<span class="tile__note">{{ totals.unpricedMinutes ? t('ticketsystem', '{time} billable without a rate', { time: hoursText(totals.unpricedMinutes) }) : t('ticketsystem', 'Billable time at its rate') }}</span>
					</div>
					<div class="tile">
						<span class="tile__label">{{ t('ticketsystem', 'Not invoiced yet') }}</span>
						<span class="tile__value">{{ moneyText(totals.openAmount) }}</span>
						<span class="tile__note">{{ hoursText(totals.openMinutes) }}</span>
					</div>
				</section>

				<div class="timepage__bar">
					<div class="timepage__group">
						<span>{{ t('ticketsystem', 'Group by') }}</span>
						<div class="timepage__group-buttons">
							<NcCheckboxRadioSwitch v-for="g in groupings"
								:key="g.id"
								v-model="groupBy"
								:value="g.id"
								name="time-group"
								type="radio"
								button-variant
								button-variant-grouped="horizontal">
								{{ g.label }}
							</NcCheckboxRadioSwitch>
						</div>
					</div>
					<div class="timepage__actions">
						<NcButton :disabled="entries.length === 0" @click="exportCsv">
							<template #icon>
								<DownloadIcon :size="20" />
							</template>
							{{ t('ticketsystem', 'Download (CSV)') }}
						</NcButton>
						<NcButton v-if="meta.isAdmin"
							variant="primary"
							:disabled="openEntries.length === 0"
							@click="confirmInvoice = true">
							<template #icon>
								<ReceiptIcon :size="20" />
							</template>
							{{ t('ticketsystem', 'Mark as invoiced') }}
						</NcButton>
					</div>
				</div>

				<NcEmptyContent v-if="entries.length === 0"
					:name="t('ticketsystem', 'No time logged in this period')"
					:description="t('ticketsystem', 'Log time from the Time card on a ticket.')">
					<template #icon>
						<ClockIcon />
					</template>
				</NcEmptyContent>

				<table v-else class="table">
					<thead>
						<tr>
							<th scope="col">{{ groupings.find(g => g.id === groupBy).label }}</th>
							<th scope="col" class="num">{{ t('ticketsystem', 'Tickets') }}</th>
							<th scope="col" class="num">{{ t('ticketsystem', 'Time') }}</th>
							<th scope="col" class="num">{{ t('ticketsystem', 'Billable') }}</th>
							<th scope="col" class="num">{{ t('ticketsystem', 'Amount') }}</th>
						</tr>
					</thead>
					<tbody v-for="group in groups" :key="group.key">
						<tr class="group-row">
							<th scope="row">
								<button class="group-row__toggle"
									:aria-expanded="expanded.has(group.key)"
									@click="toggle(group.key)">
									<ChevronIcon :size="20" :class="{ 'group-row__chevron--open': expanded.has(group.key) }" class="group-row__chevron" />
									{{ group.label }}
								</button>
							</th>
							<td class="num">{{ group.tickets }}</td>
							<td class="num">{{ hoursText(group.minutes) }}</td>
							<td class="num">{{ hoursText(group.billableMinutes) }}</td>
							<td class="num">{{ moneyText(group.amount) }}</td>
						</tr>
						<template v-if="expanded.has(group.key)">
							<tr v-for="entry in group.entries" :key="entry.id" class="entry-row">
								<td colspan="2">
									<div class="entry-row__main">
										<router-link :to="{ name: 'ticket', params: { id: entry.ticketId } }" class="entry-row__ticket">
											#{{ entry.ticketId }} {{ entry.subject }}
										</router-link>
										<span class="entry-row__meta">
											{{ dateText(entry.workDate) }} · {{ agentName(meta, entry.uid) }}<template v-if="entry.note"> · {{ entry.note }}</template>
										</span>
									</div>
								</td>
								<td class="num">{{ hoursText(entry.minutes) }}</td>
								<td class="num">
									<span v-if="!entry.billable" class="chip">{{ t('ticketsystem', 'No') }}</span>
									<span v-else-if="entry.invoicedAt" class="chip chip--done">{{ t('ticketsystem', 'Invoiced') }}</span>
									<span v-else>{{ t('ticketsystem', 'Yes') }}</span>
								</td>
								<td class="num">{{ entry.amount !== null ? formatMoney(entry.amount, entry.currency, language) : '–' }}</td>
							</tr>
						</template>
					</tbody>
				</table>
				<p class="timepage__footnote">
					{{ t('ticketsystem', 'Prices use the rate when the time was logged: the customer company\'s hourly rate, otherwise your standard rate. Invoiced time can no longer be changed.') }}
				</p>
			</template>
		</template>

		<NcDialog v-if="confirmInvoice"
			:name="t('ticketsystem', 'Mark as invoiced')"
			size="small"
			@closing="confirmInvoice = false">
			<p>
				{{ n('ticketsystem', 'Marks %n billable entry in this view as invoiced: {amount}.', 'Marks %n billable entries in this view as invoiced: {amount}.', openEntries.length, { amount: moneyText(totals.openAmount) }) }}
			</p>
			<p class="timepage__muted">{{ t('ticketsystem', 'Download the CSV first if you need it for the invoice. Invoiced time is locked.') }}</p>
			<template #actions>
				<NcButton @click="confirmInvoice = false">{{ t('ticketsystem', 'Cancel') }}</NcButton>
				<NcButton variant="primary" :disabled="invoicing" @click="markInvoiced">
					{{ t('ticketsystem', 'Mark as invoiced') }}
				</NcButton>
			</template>
		</NcDialog>
	</div>
</template>

<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { getLanguage, n, t } from '@nextcloud/l10n'
import { showError, showSuccess } from '@nextcloud/dialogs'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcDateTimePickerNative from '@nextcloud/vue/components/NcDateTimePickerNative'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import ChevronIcon from 'vue-material-design-icons/ChevronRight.vue'
import ClockIcon from 'vue-material-design-icons/ClockOutline.vue'
import DownloadIcon from 'vue-material-design-icons/Download.vue'
import ReceiptIcon from 'vue-material-design-icons/ReceiptTextCheckOutline.vue'
import CompaniesPanel from './CompaniesPanel.vue'
import { api, errorMessage } from '../api.js'
import { agentName, teamOf } from '../format.js'
import { decimalHours, formatMoney, splitMinutes } from '../time.js'

const props = defineProps({
	meta: { type: Object, required: true },
})
defineEmits(['companies-changed'])

const language = getLanguage()
const tabs = [
	{ id: 'time', label: t('ticketsystem', 'Time') },
	{ id: 'companies', label: t('ticketsystem', 'Companies') },
]
const tab = ref('time')

const iso = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
const presets = [
	{ id: 'month', label: t('ticketsystem', 'This month'), range: () => { const d = new Date(); return [new Date(d.getFullYear(), d.getMonth(), 1), d] } },
	{ id: 'last-month', label: t('ticketsystem', 'Last month'), range: () => { const d = new Date(); return [new Date(d.getFullYear(), d.getMonth() - 1, 1), new Date(d.getFullYear(), d.getMonth(), 0)] } },
	{ id: 'week', label: t('ticketsystem', 'This week'), range: () => { const d = new Date(); const monday = new Date(d); monday.setDate(d.getDate() - ((d.getDay() + 6) % 7)); return [monday, d] } },
	{ id: 'year', label: t('ticketsystem', 'This year'), range: () => { const d = new Date(); return [new Date(d.getFullYear(), 0, 1), d] } },
	{ id: 'custom', label: t('ticketsystem', 'Choose dates…'), range: null },
]
const allBrands = { id: null, name: t('ticketsystem', 'All my brands') }
const allCompanies = { id: null, name: t('ticketsystem', 'All companies') }
const noCompany = { id: 'none', name: t('ticketsystem', 'No company') }
const allAgents = { uid: null, displayName: t('ticketsystem', 'Everyone') }
const billingOptions = [
	{ id: 'all', label: t('ticketsystem', 'All time') },
	{ id: 'open', label: t('ticketsystem', 'Billable, not invoiced') },
	{ id: 'billable', label: t('ticketsystem', 'Billable') },
	{ id: 'invoiced', label: t('ticketsystem', 'Invoiced') },
	{ id: 'internal', label: t('ticketsystem', 'Not billable') },
]
const groupings = [
	{ id: 'company', label: t('ticketsystem', 'Company') },
	{ id: 'agent', label: t('ticketsystem', 'Agent') },
	{ id: 'ticket', label: t('ticketsystem', 'Ticket') },
]

const preset = ref(presets[0])
const customFrom = ref(new Date())
const customTo = ref(new Date())
const team = ref(allBrands)
const company = ref(allCompanies)
const agent = ref(allAgents)
const billing = ref(billingOptions[0])
const groupBy = ref('company')
const result = ref(null)
const loading = ref(false)
const error = ref('')
const expanded = reactive(new Set())
const confirmInvoice = ref(false)
const invoicing = ref(false)

const companies = computed(() => result.value?.companies ?? props.meta.companies ?? [])
const companyOptions = computed(() => [allCompanies, ...companies.value, noCompany])
const agentOptions = computed(() => [allAgents, ...props.meta.agents])

const params = computed(() => {
	const [from, to] = preset.value.range ? preset.value.range() : [customFrom.value, customTo.value]
	const b = billing.value.id
	return {
		from: iso(from ?? new Date()),
		to: iso(to ?? new Date()),
		teamId: team.value.id ?? undefined,
		companyId: company.value.id ?? undefined,
		uid: agent.value.uid ?? undefined,
		billable: b === 'internal' ? '0' : (b === 'all' ? undefined : '1'),
		invoiced: b === 'open' ? '0' : (b === 'invoiced' ? '1' : undefined),
	}
})

const entries = computed(() => result.value?.entries ?? [])
const openEntries = computed(() => entries.value.filter(e => e.billable && !e.invoicedAt))

/** Sums per currency, so a mix of currencies never adds up into nonsense. */
const addMoney = (sums, amount, currency) => {
	if (amount !== null) {
		sums[currency] = (sums[currency] ?? 0) + amount
	}
	return sums
}
const totals = computed(() => {
	const sum = { minutes: 0, billableMinutes: 0, unpricedMinutes: 0, openMinutes: 0, amount: {}, openAmount: {} }
	for (const e of entries.value) {
		sum.minutes += e.minutes
		if (e.billable) {
			sum.billableMinutes += e.minutes
			if (e.amount === null) {
				sum.unpricedMinutes += e.minutes
			}
			addMoney(sum.amount, e.amount, e.currency)
			if (!e.invoicedAt) {
				sum.openMinutes += e.minutes
				addMoney(sum.openAmount, e.amount, e.currency)
			}
		}
	}
	return sum
})

const companyName = (id) => id === null ? t('ticketsystem', 'No company') : (companies.value.find(c => c.id === id)?.name ?? t('ticketsystem', 'Deleted company'))
const groups = computed(() => {
	const map = new Map()
	for (const e of entries.value) {
		let key, label
		if (groupBy.value === 'agent') {
			key = 'a:' + e.uid
			label = agentName(props.meta, e.uid)
		} else if (groupBy.value === 'ticket') {
			key = 't:' + e.ticketId
			label = `#${e.ticketId} ${e.subject}`
		} else {
			key = 'c:' + (e.companyId ?? '-')
			label = companyName(e.companyId)
		}
		if (!map.has(key)) {
			map.set(key, { key, label, entries: [], tickets: new Set(), minutes: 0, billableMinutes: 0, amount: {} })
		}
		const g = map.get(key)
		g.entries.push(e)
		g.tickets.add(e.ticketId)
		g.minutes += e.minutes
		if (e.billable) {
			g.billableMinutes += e.minutes
			addMoney(g.amount, e.amount, e.currency)
		}
	}
	return [...map.values()]
		.map(g => ({ ...g, tickets: g.tickets.size }))
		.sort((a, b) => b.minutes - a.minutes)
})

function toggle(key) {
	if (expanded.has(key)) {
		expanded.delete(key)
	} else {
		expanded.add(key)
	}
}

function hoursText(minutes) {
	const { hours, minutes: rest } = splitMinutes(minutes)
	return `${hours}:${String(rest).padStart(2, '0')}`
}
const moneyText = (sums) => {
	const parts = Object.entries(sums).map(([currency, amount]) => formatMoney(amount, currency, language))
	return parts.length ? parts.join(' + ') : '–'
}
const percentFormat = new Intl.NumberFormat(language, { style: 'percent', maximumFractionDigits: 0 })
const percent = (v) => percentFormat.format(v)
const dateFormat = new Intl.DateTimeFormat(language, { day: 'numeric', month: 'short', year: 'numeric' })
const dateText = (d) => dateFormat.format(new Date(d + 'T12:00:00'))

let requestNo = 0
async function load() {
	const mine = ++requestNo
	loading.value = true
	error.value = ''
	try {
		const data = await api.timeOverview(params.value)
		if (mine === requestNo) {
			result.value = data
		}
	} catch (e) {
		if (mine === requestNo) {
			error.value = errorMessage(e, t('ticketsystem', 'Could not load the time'))
		}
	} finally {
		if (mine === requestNo) {
			loading.value = false
		}
	}
}

/** Every entry as a spreadsheet row, ready for invoicing, in the agent's number format. */
function exportCsv() {
	const decimalComma = new Intl.NumberFormat(language).format(1.5).includes(',')
	const separator = decimalComma ? ';' : ','
	const number = (v) => v === null || v === undefined ? '' : (decimalComma ? String(v).replace('.', ',') : String(v))
	const header = [
		t('ticketsystem', 'Date'), t('ticketsystem', 'Company'), t('ticketsystem', 'Ticket'), t('ticketsystem', 'Subject'), t('ticketsystem', 'Customer email'),
		t('ticketsystem', 'Brand'), t('ticketsystem', 'Agent'), t('ticketsystem', 'Minutes'), t('ticketsystem', 'Hours'), t('ticketsystem', 'Billable'),
		t('ticketsystem', 'Rate per hour'), t('ticketsystem', 'Amount'), t('ticketsystem', 'Currency'), t('ticketsystem', 'Invoiced'), t('ticketsystem', 'Note'),
	]
	const lines = entries.value.map(e => [
		e.workDate, companyName(e.companyId), '#' + e.ticketId, e.subject, e.customerEmail ?? '',
		teamOf(props.meta, e.teamId)?.name ?? '', agentName(props.meta, e.uid), e.minutes, number(decimalHours(e.minutes)),
		e.billable ? t('ticketsystem', 'yes') : t('ticketsystem', 'no'), e.rate !== null ? number(e.rate / 100) : '',
		e.amount !== null ? number(e.amount / 100) : '', e.currency ?? '', e.invoicedAt ? t('ticketsystem', 'yes') : t('ticketsystem', 'no'), e.note ?? '',
	])
	const cell = (v) => {
		const text = String(v ?? '')
		return /["\n\r;,]/.test(text) ? '"' + text.replace(/"/g, '""') + '"' : text
	}
	const csv = '﻿' + [header, ...lines].map(line => line.map(cell).join(separator)).join('\r\n')
	const link = document.createElement('a')
	link.href = URL.createObjectURL(new Blob([csv], { type: 'text/csv;charset=utf-8' }))
	link.download = `ticketsystem-time-${params.value.from}-${params.value.to}.csv`
	link.click()
	setTimeout(() => URL.revokeObjectURL(link.href), 1000)
}

async function markInvoiced() {
	invoicing.value = true
	try {
		const { marked } = await api.markInvoiced(openEntries.value.map(e => e.id))
		showSuccess(n('ticketsystem', '%n entry marked as invoiced', '%n entries marked as invoiced', marked))
		confirmInvoice.value = false
		await load()
	} catch (e) {
		showError(errorMessage(e, t('ticketsystem', 'Could not mark the time as invoiced')))
	} finally {
		invoicing.value = false
	}
}

watch(params, load, { deep: true })
watch(tab, (value) => {
	if (value === 'time') {
		load()
	}
})
onMounted(load)
</script>

<style scoped lang="scss">
.timepage {
	display: flex;
	flex-direction: column;
	gap: 16px;
	max-width: 1240px;
	margin: 0 auto;
	padding: 16px 20px 40px;

	&__head {
		display: flex;
		flex-wrap: wrap;
		align-items: center;
		justify-content: space-between;
		gap: 12px;
		// Room for the navigation toggle in the top corner.
		padding-inline-start: calc(var(--default-clickable-area) + 8px);

		h2 {
			margin: 0;
			font-size: 1.4em;
		}
	}

	&__tabs {
		display: flex;
		gap: 4px;
	}

	&__filters,
	&__bar,
	&__actions,
	&__group {
		display: flex;
		flex-wrap: wrap;
		align-items: end;
		gap: 8px;
	}

	&__bar {
		align-items: center;
		justify-content: space-between;
	}

	&__group {
		align-items: center;
		color: var(--color-text-maxcontrast);
	}

	// The grouped buttons must touch each other: no gap between them.
	&__group-buttons {
		display: flex;
		color: var(--color-main-text);
	}

	&__loading {
		margin-top: 20vh;
	}

	&__footnote,
	&__muted {
		color: var(--color-text-maxcontrast);
		font-size: var(--font-size-small, 13px);
	}
}

.tiles {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(190px, 1fr));
	gap: 12px;
}

.tile {
	display: flex;
	flex-direction: column;
	gap: 2px;
	padding: 14px 16px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);

	&__label,
	&__note {
		color: var(--color-text-maxcontrast);
		font-size: var(--font-size-small, 13px);
	}

	&__value {
		font-size: 1.8em;
		font-weight: 600;
		font-variant-numeric: tabular-nums;
		line-height: 1.3;
	}
}

.table {
	width: 100%;
	border-collapse: collapse;

	th,
	td {
		padding: 8px 10px;
		border-bottom: 1px solid var(--color-border);
		text-align: start;
		vertical-align: top;
	}

	thead th {
		color: var(--color-text-maxcontrast);
		font-weight: normal;
		font-size: var(--font-size-small, 13px);
	}

	.num {
		text-align: end;
		font-variant-numeric: tabular-nums;
		white-space: nowrap;
	}
}

.group-row {
	th,
	td {
		font-weight: 600;
	}

	&__toggle {
		display: inline-flex;
		align-items: center;
		gap: 4px;
		margin: 0;
		padding: 0;
		border: none;
		background: none;
		font: inherit;
		color: inherit;
		cursor: pointer;
		text-align: start;
	}

	&__chevron {
		transition: transform 0.15s ease;

		&--open {
			transform: rotate(90deg);
		}
	}
}

.entry-row {
	td {
		background: var(--color-background-hover);
	}

	&__main {
		display: flex;
		flex-direction: column;
		padding-inline-start: 24px;
	}

	&__ticket:hover {
		text-decoration: underline;
	}

	&__meta {
		color: var(--color-text-maxcontrast);
		font-size: var(--font-size-small, 13px);
	}
}

.chip {
	padding: 0 6px;
	border-radius: var(--border-radius-pill);
	background: var(--color-background-dark);
	font-size: 12px;

	&--done {
		background: color-mix(in srgb, var(--color-success) 18%, transparent);
	}
}
</style>
