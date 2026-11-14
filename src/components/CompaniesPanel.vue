<!--
  - SPDX-FileCopyrightText: 2026 Co-Optimize
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="companies">
		<div class="companies__bar">
			<p class="companies__intro">
				{{ t('ticketsystem', 'Time on a company\'s tickets is invoiced to it. Tickets from its email domains are linked automatically; agents can also pick the company on a ticket.') }}
			</p>
			<NcButton variant="primary" @click="startNew">
				<template #icon>
					<PlusIcon :size="20" />
				</template>
				{{ t('ticketsystem', 'New company') }}
			</NcButton>
		</div>

		<NcLoadingIcon v-if="loading && companies.length === 0" />
		<NcEmptyContent v-else-if="companies.length === 0"
			:name="t('ticketsystem', 'No companies yet')"
			:description="t('ticketsystem', 'Add the companies you invoice, with their email domains and hourly rate.')">
			<template #icon>
				<OfficeIcon />
			</template>
		</NcEmptyContent>
		<table v-else class="table">
			<thead>
				<tr>
					<th scope="col">{{ t('ticketsystem', 'Company') }}</th>
					<th scope="col">{{ t('ticketsystem', 'Email domains') }}</th>
					<th scope="col" class="num">{{ t('ticketsystem', 'Hourly rate') }}</th>
					<th scope="col" class="num">{{ t('ticketsystem', 'Tickets') }}</th>
					<th scope="col"><span class="hidden-visually">{{ t('ticketsystem', 'Actions') }}</span></th>
				</tr>
			</thead>
			<tbody>
				<tr v-for="company in companies" :key="company.id">
					<th scope="row">
						<span class="companies__name">{{ company.name }}</span>
						<span v-if="company.notes" class="companies__notes">{{ company.notes }}</span>
					</th>
					<td>{{ company.domains.join(', ') || '–' }}</td>
					<td class="num">{{ company.hourlyRate !== null ? money(company.hourlyRate) : t('ticketsystem', 'Brand rate') }}</td>
					<td class="num">{{ ticketCounts[company.id] ?? 0 }}</td>
					<td class="companies__actions">
						<NcButton variant="tertiary" :aria-label="t('ticketsystem', 'Edit {name}', { name: company.name })" @click="startEdit(company)">
							<template #icon>
								<PencilIcon :size="20" />
							</template>
						</NcButton>
					</td>
				</tr>
			</tbody>
		</table>

		<NcDialog v-if="form"
			:name="form.id ? t('ticketsystem', 'Edit company') : t('ticketsystem', 'New company')"
			size="normal"
			@closing="form = null">
			<form class="companies__form" @submit.prevent="save">
				<NcTextField v-model="form.name" :label="t('ticketsystem', 'Name')" autofocus />
				<NcTextField v-model="form.domains"
					:label="t('ticketsystem', 'Email domains')"
					:helper-text="t('ticketsystem', 'Separate with commas, e.g. kunde.dk, kunde.com. Public services like gmail.com cannot be used.')" />
				<NcTextField v-model="form.rate"
					:label="t('ticketsystem', 'Hourly rate ({currency})', { currency: defaultCurrency })"
					:disabled="!meta.isAdmin"
					:error="Number.isNaN(parseMoney(form.rate))"
					:helper-text="meta.isAdmin ? t('ticketsystem', 'Leave empty to use your standard rate.') : t('ticketsystem', 'Only administrators can change rates.')" />
				<NcTextArea v-model="form.notes" :label="t('ticketsystem', 'Notes for the team (optional)')" resize="vertical" />
			</form>
			<template #actions>
				<NcButton v-if="form.id && meta.isAdmin" variant="error" :disabled="saving" @click="remove">
					{{ t('ticketsystem', 'Delete') }}
				</NcButton>
				<NcButton @click="form = null">{{ t('ticketsystem', 'Cancel') }}</NcButton>
				<NcButton variant="primary" :disabled="saving || !form.name.trim() || Number.isNaN(parseMoney(form.rate))" @click="save">
					{{ t('ticketsystem', 'Save') }}
				</NcButton>
			</template>
		</NcDialog>
	</div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import { getLanguage, t } from '@nextcloud/l10n'
import { showError, showSuccess } from '@nextcloud/dialogs'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import OfficeIcon from 'vue-material-design-icons/OfficeBuildingOutline.vue'
import PencilIcon from 'vue-material-design-icons/PencilOutline.vue'
import PlusIcon from 'vue-material-design-icons/Plus.vue'
import { api, errorMessage } from '../api.js'
import { formatMoney, parseMoney } from '../time.js'

const props = defineProps({
	meta: { type: Object, required: true },
})
const emit = defineEmits(['changed'])

const companies = ref([])
const ticketCounts = ref({})
const loading = ref(false)
const saving = ref(false)
const form = ref(null)

const defaultCurrency = computed(() => props.meta.teams[0]?.currency ?? 'DKK')
const money = (minor) => formatMoney(minor, defaultCurrency.value, getLanguage())
const rateText = (minor) => minor === null ? '' : new Intl.NumberFormat(getLanguage(), { maximumFractionDigits: 2, useGrouping: false }).format(minor / 100)

function apply(result) {
	companies.value = result.companies
	ticketCounts.value = result.ticketCounts ?? {}
}

async function load() {
	loading.value = true
	try {
		apply(await api.listCompanies())
	} catch (error) {
		showError(errorMessage(error, t('ticketsystem', 'Could not load the companies')))
	} finally {
		loading.value = false
	}
}

function startNew() {
	form.value = { id: null, name: '', domains: '', rate: '', notes: '' }
}

function startEdit(company) {
	form.value = {
		id: company.id,
		name: company.name,
		domains: company.domains.join(', '),
		rate: rateText(company.hourlyRate),
		notes: company.notes ?? '',
	}
}

async function save() {
	saving.value = true
	const body = {
		name: form.value.name,
		domains: form.value.domains.split(/[\s,;]+/).filter(Boolean),
		notes: form.value.notes,
	}
	if (props.meta.isAdmin) {
		body.hourlyRate = parseMoney(form.value.rate)
	}
	try {
		apply(form.value.id ? await api.updateCompany(form.value.id, body) : await api.createCompany(body))
		showSuccess(t('ticketsystem', 'Company saved'))
		form.value = null
		emit('changed')
	} catch (error) {
		showError(errorMessage(error, t('ticketsystem', 'Could not save the company')))
	} finally {
		saving.value = false
	}
}

async function remove() {
	saving.value = true
	try {
		apply(await api.deleteCompany(form.value.id))
		showSuccess(t('ticketsystem', 'Company deleted. Its tickets and time are kept.'))
		form.value = null
		emit('changed')
	} catch (error) {
		showError(errorMessage(error, t('ticketsystem', 'Could not delete the company')))
	} finally {
		saving.value = false
	}
}

onMounted(load)
</script>

<style scoped lang="scss">
.companies {
	display: flex;
	flex-direction: column;
	gap: 16px;

	&__bar {
		display: flex;
		flex-wrap: wrap;
		align-items: center;
		justify-content: space-between;
		gap: 12px;
	}

	&__intro {
		max-width: 70ch;
		margin: 0;
		color: var(--color-text-maxcontrast);
	}

	&__name {
		display: block;
		font-weight: 600;
	}

	&__notes {
		display: block;
		color: var(--color-text-maxcontrast);
		font-size: var(--font-size-small, 13px);
		font-weight: normal;
	}

	&__actions {
		text-align: end;
		width: 1%;
	}

	&__form {
		display: flex;
		flex-direction: column;
		gap: 12px;
		padding-bottom: 8px;
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
		vertical-align: middle;
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
</style>
