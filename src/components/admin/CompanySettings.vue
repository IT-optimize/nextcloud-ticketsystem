<!--
  - SPDX-FileCopyrightText: 2026 Co-Optimize
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<form v-if="form" class="company" @submit.prevent="save">
		<p class="company__intro">
			{{ t('ticketsystem', 'Your company as customers see it, and who works on the tickets. Sender name, reply address and language are under Email.') }}
		</p>
		<div class="company__grid">
			<NcTextField :model-value="form.name"
				:label="t('ticketsystem', 'Company name')"
				required
				@update:model-value="v => form.name = v" />
			<NcSelect :model-value="groups.filter(g => form.agentGroups.includes(g.id))"
				:options="groups"
				label="displayName"
				:multiple="true"
				:input-label="t('ticketsystem', 'Agent groups')"
				:placeholder="t('ticketsystem', 'Members of these groups work on tickets')"
				@update:model-value="list => form.agentGroups = list.map(g => g.id)" />
			<NcTextField v-model="rateDraft"
				:label="t('ticketsystem', 'Hourly rate for invoicing ({currency})', { currency: form.currency || 'DKK' })"
				:error="Number.isNaN(parseMoney(rateDraft))"
				:helper-text="t('ticketsystem', 'Used for time on tickets unless the customer\'s company has its own rate')" />
			<NcTextField :model-value="form.currency ?? 'DKK'"
				:label="t('ticketsystem', 'Currency')"
				maxlength="3"
				@update:model-value="v => form.currency = v.toUpperCase()" />
			<div class="company__color">
				<span>{{ t('ticketsystem', 'Color in customer emails') }}</span>
				<NcColorPicker :model-value="form.color || '#0082c9'" @update:model-value="c => form.color = c">
					<NcButton variant="secondary">
						<template #icon>
							<span class="company__swatch" :style="{ background: form.color || '#0082c9' }" />
						</template>
						{{ form.color || t('ticketsystem', 'Pick a color') }}
					</NcButton>
				</NcColorPicker>
			</div>
		</div>
		<NcTextArea :model-value="form.signature ?? ''"
			:label="t('ticketsystem', 'Signature added below every reply to the customer')"
			resize="vertical"
			@update:model-value="v => form.signature = v" />
		<div class="company__actions">
			<NcButton type="submit" variant="primary" :disabled="busy || !form.name.trim() || Number.isNaN(parseMoney(rateDraft))">
				{{ t('ticketsystem', 'Save') }}
			</NcButton>
		</div>
	</form>
</template>

<script setup>
import { computed, ref, watch } from 'vue'
import { getLanguage, t } from '@nextcloud/l10n'
import { showError, showSuccess } from '@nextcloud/dialogs'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcColorPicker from '@nextcloud/vue/components/NcColorPicker'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { api, errorMessage } from '../../api.js'
import { parseMoney } from '../../time.js'

const props = defineProps({
	teams: { type: Array, required: true },
	defaultTeamId: { type: Number, default: null },
	groups: { type: Array, required: true },
})
// updated: (allSettings) — the server's new state after a change
const emit = defineEmits(['updated'])

/** Ticket system works for one company: the default brand. */
const company = computed(() => props.teams.find(team => team.id === props.defaultTeamId) ?? props.teams[0] ?? null)
const form = ref(null)
const rateDraft = ref('')
const busy = ref(false)

const rateText = (minor) => minor != null
	? new Intl.NumberFormat(getLanguage(), { maximumFractionDigits: 2, useGrouping: false }).format(minor / 100)
	: ''

watch(company, (team) => {
	form.value = team ? { ...team, agentGroups: [...team.agentGroups] } : null
	rateDraft.value = rateText(team?.hourlyRate)
}, { immediate: true })

async function save() {
	busy.value = true
	try {
		const { id, name, agentGroups, color, signature, currency } = form.value
		// Sender name, reply address and language come from the Email tab, so
		// clear any the company still had from when brands had their own.
		const result = await api.updateTeam(id, {
			name, agentGroups, color, signature, currency, hourlyRate: parseMoney(rateDraft.value),
			senderName: '', replyTo: '', language: null,
		})
		emit('updated', result)
		showSuccess(t('ticketsystem', 'Company saved'))
	} catch (error) {
		showError(errorMessage(error, t('ticketsystem', 'Could not save the company')))
	} finally {
		busy.value = false
	}
}
</script>

<style scoped lang="scss">
.company {
	display: flex;
	flex-direction: column;
	gap: 12px;
	max-width: 800px;

	&__intro {
		color: var(--color-text-maxcontrast);
	}

	&__grid {
		display: grid;
		grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
		gap: 12px;
		align-items: start;

		> * {
			min-width: 0;
		}

		:deep(.select) {
			width: 100%;
			min-width: 0;
		}
	}

	&__color {
		display: flex;
		flex-direction: column;
		gap: 4px;
	}

	&__swatch {
		display: inline-block;
		width: 16px;
		height: 16px;
		border-radius: 50%;
	}

	&__actions {
		display: flex;
		justify-content: flex-end;
	}
}
</style>
