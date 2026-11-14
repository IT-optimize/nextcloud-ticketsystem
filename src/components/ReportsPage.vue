<!--
  - SPDX-FileCopyrightText: 2026 Co-Optimize
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="reports">
		<header class="reports__head">
			<h2>{{ t('ticketsystem', 'Reports') }}</h2>
			<div class="reports__filters">
				<NcSelect v-model="preset"
					:options="presets"
					label="label"
					:clearable="false"
					:input-label="t('ticketsystem', 'Period')" />
				<template v-if="preset.id === 'custom'">
					<NcDateTimePickerNative id="report-from"
						v-model="customFrom"
						type="date"
						:label="t('ticketsystem', 'From')" />
					<NcDateTimePickerNative id="report-to"
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
			</div>
		</header>

		<NcLoadingIcon v-if="loading && !report" class="reports__loading" :size="40" />
		<NcEmptyContent v-else-if="error" :name="error">
			<template #icon>
				<ChartIcon />
			</template>
		</NcEmptyContent>

		<template v-else-if="report">
			<section class="tiles" :aria-label="t('ticketsystem', 'Key numbers')">
				<div class="tile">
					<span class="tile__label">{{ t('ticketsystem', 'New tickets') }}</span>
					<span class="tile__value">{{ report.totals.created }}</span>
					<span class="tile__note">{{ t('ticketsystem', '{n} resolved in the same period', { n: report.totals.resolved }) }}</span>
				</div>
				<div class="tile">
					<span class="tile__label">{{ t('ticketsystem', 'Open now') }}</span>
					<span class="tile__value">{{ report.totals.open }}</span>
					<span class="tile__note">
						{{ t('ticketsystem', '{u} unassigned', { u: report.totals.unassigned }) }}
					</span>
				</div>
				<div class="tile">
					<span class="tile__label">{{ t('ticketsystem', 'First reply (median)') }}</span>
					<span class="tile__value">{{ formatDuration(report.firstResponse.median) }}</span>
				</div>
				<div class="tile">
					<span class="tile__label">{{ t('ticketsystem', 'Time to resolve (median)') }}</span>
					<span class="tile__value">{{ formatDuration(report.resolution.median) }}</span>
				</div>
			</section>

			<section class="card card--wide">
				<h3>{{ t('ticketsystem', 'Tickets per day') }}</h3>
				<VolumeChart :series="report.series" />
			</section>

			<div class="cards">
				<section class="card card--wide">
					<h3>{{ t('ticketsystem', 'Per agent') }}</h3>
					<p v-if="report.byAgent.length === 0" class="card__empty">{{ t('ticketsystem', 'Nothing in this period') }}</p>
					<table v-else class="table">
						<thead>
							<tr>
								<th scope="col">{{ t('ticketsystem', 'Agent') }}</th>
								<th scope="col" class="num">{{ t('ticketsystem', 'Resolved') }}</th>
								<th scope="col" class="num">{{ t('ticketsystem', 'First reply (median)') }}</th>
								<th scope="col" class="num">{{ t('ticketsystem', 'Time to resolve (median)') }}</th>
							</tr>
						</thead>
						<tbody>
							<tr v-for="row in report.byAgent" :key="row.uid ?? '-'">
								<th scope="row">
									<span class="agent">
										<NcAvatar v-if="row.uid"
											:user="row.uid"
											:size="24"
											hide-status
											disable-menu
											disable-tooltip />
										{{ row.uid ? agentName(meta, row.uid) : t('ticketsystem', 'Unassigned') }}
									</span>
								</th>
								<td class="num">{{ row.resolved }}</td>
								<td class="num">{{ formatDuration(row.medianFirstResponse) }}</td>
								<td class="num">{{ formatDuration(row.medianResolution) }}</td>
							</tr>
						</tbody>
					</table>
				</section>

			</div>

			<p class="reports__footnote">
				{{ t('ticketsystem', 'Times are measured in calendar time, as the customer experiences them.') }}
			</p>
		</template>
	</div>
</template>

<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { t } from '@nextcloud/l10n'
import NcAvatar from '@nextcloud/vue/components/NcAvatar'
import NcDateTimePickerNative from '@nextcloud/vue/components/NcDateTimePickerNative'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import ChartIcon from 'vue-material-design-icons/ChartBar.vue'
import VolumeChart from './VolumeChart.vue'
import { api, errorMessage } from '../api.js'
import { agentName, formatDuration } from '../format.js'

const props = defineProps({
	meta: { type: Object, required: true },
})

const iso = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
const daysAgo = (days) => {
	const d = new Date()
	d.setDate(d.getDate() - days)
	return d
}

const presets = [
	{ id: '7', label: t('ticketsystem', 'Last 7 days'), range: () => [daysAgo(6), new Date()] },
	{ id: '30', label: t('ticketsystem', 'Last 30 days'), range: () => [daysAgo(29), new Date()] },
	{ id: '90', label: t('ticketsystem', 'Last 90 days'), range: () => [daysAgo(89), new Date()] },
	{ id: 'month', label: t('ticketsystem', 'This month'), range: () => { const d = new Date(); return [new Date(d.getFullYear(), d.getMonth(), 1), d] } },
	{ id: 'last-month', label: t('ticketsystem', 'Last month'), range: () => { const d = new Date(); return [new Date(d.getFullYear(), d.getMonth() - 1, 1), new Date(d.getFullYear(), d.getMonth(), 0)] } },
	{ id: 'custom', label: t('ticketsystem', 'Choose dates…'), range: null },
]
const allBrands = { id: null, name: t('ticketsystem', 'All my brands') }

const preset = ref(presets[1])
const customFrom = ref(daysAgo(29))
const customTo = ref(new Date())
const team = ref(allBrands)
const report = ref(null)
const loading = ref(false)
const error = ref('')

const timezone = Intl.DateTimeFormat().resolvedOptions().timeZone || 'Europe/Copenhagen'
const range = computed(() => {
	const [from, to] = preset.value.range ? preset.value.range() : [customFrom.value, customTo.value]
	return { from: iso(from ?? new Date()), to: iso(to ?? new Date()) }
})
const params = computed(() => ({ ...range.value, teamId: team.value.id ?? undefined, tz: timezone }))

let requestNo = 0
async function load() {
	const mine = ++requestNo
	loading.value = true
	error.value = ''
	try {
		const result = await api.report(params.value)
		if (mine === requestNo) {
			report.value = result
		}
	} catch (e) {
		if (mine === requestNo) {
			error.value = errorMessage(e, t('ticketsystem', 'Could not load the report'))
		}
	} finally {
		if (mine === requestNo) {
			loading.value = false
		}
	}
}

watch(params, load, { deep: true })
onMounted(load)
</script>

<style scoped lang="scss">
.reports {
	display: flex;
	flex-direction: column;
	gap: 16px;
	max-width: 1240px;
	margin: 0 auto;
	padding: 16px 20px 40px;

	&__head {
		display: flex;
		flex-wrap: wrap;
		align-items: end;
		justify-content: space-between;
		gap: 12px;
		// Room for the navigation toggle in the top corner.
		padding-inline-start: calc(var(--default-clickable-area) + 8px);

		h2 {
			margin: 0;
			font-size: 1.4em;
		}
	}

	&__filters {
		display: flex;
		flex-wrap: wrap;
		align-items: end;
		gap: 8px;
	}

	&__loading {
		margin-top: 25vh;
	}

	&__footnote {
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

	&__label {
		color: var(--color-text-maxcontrast);
		font-size: var(--font-size-small, 13px);
	}

	&__value {
		font-size: 1.8em;
		font-weight: 600;
		font-variant-numeric: tabular-nums;
		line-height: 1.3;
	}

	&__note {
		color: var(--color-text-maxcontrast);
		font-size: var(--font-size-small, 13px);
	}

	&__bad {
		color: var(--color-error-text, var(--color-error));
		font-weight: 600;
	}
}

.cards {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
	gap: 12px;
}

.card {
	min-width: 0;
	padding: 14px 16px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);

	&--wide {
		grid-column: 1 / -1;
	}

	h3 {
		margin: 0 0 10px;
		font-size: 1em;
		font-weight: 600;
	}

	&__empty {
		color: var(--color-text-maxcontrast);
	}
}

.table {
	width: 100%;
	border-collapse: collapse;

	th,
	td {
		padding: 6px 8px;
		border-bottom: 1px solid var(--color-border);
		text-align: start;
		font-weight: normal;
	}

	thead th {
		color: var(--color-text-maxcontrast);
		font-size: var(--font-size-small, 13px);
	}

	.num {
		text-align: end;
		font-variant-numeric: tabular-nums;
		white-space: nowrap;
	}
}

.agent {
	display: inline-flex;
	align-items: center;
	gap: 8px;
}
</style>
