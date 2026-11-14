<!--
  - SPDX-FileCopyrightText: 2026 Co-Optimize
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<NcModal :name="t('ticketsystem', 'New ticket')" size="normal" @close="$emit('close')">
		<form class="new-ticket" @submit.prevent="submit">
			<h2>{{ t('ticketsystem', 'New ticket') }}</h2>
			<p class="new-ticket__hint">
				{{ t('ticketsystem', 'For requests that came in by phone, chat or in person. Email requests will create tickets on their own once email intake is set up.') }}
			</p>

			<NcTextField v-model="form.subject" :label="t('ticketsystem', 'Subject')" required />
			<div class="new-ticket__row">
				<NcTextField v-model="form.customerName" :label="t('ticketsystem', 'Customer name')" />
				<NcTextField v-model="form.customerEmail" type="email" :label="t('ticketsystem', 'Customer email')" />
			</div>
			<NcTextArea v-model="form.body"
				:label="t('ticketsystem', 'What does the customer need?')"
				resize="vertical" />
			<div class="new-ticket__row">
				<NcSelect v-if="meta.teams.length > 1"
					v-model="team"
					:options="meta.teams"
					label="name"
					:input-label="t('ticketsystem', 'Brand')"
					:clearable="false" />
				<NcSelect v-model="priority"
					:options="meta.priorities"
					label="label"
					:input-label="t('ticketsystem', 'Priority')"
					:clearable="false" />
				<NcSelect v-model="assignee"
					:options="teamAgents"
					label="displayName"
					:input-label="t('ticketsystem', 'Assignee')"
					:placeholder="t('ticketsystem', 'Unassigned')" />
			</div>
			<TagPicker v-model="tagIds"
				:tags="meta.tags"
				:label="t('ticketsystem', 'Tags')"
				:placeholder="t('ticketsystem', 'Add tags')"
				@created="tag => $emit('tag-created', tag)" />

			<div class="new-ticket__actions">
				<NcButton variant="tertiary" @click="$emit('close')">
					{{ t('ticketsystem', 'Cancel') }}
				</NcButton>
				<NcButton type="submit" variant="primary" :disabled="!form.subject.trim() || saving">
					{{ t('ticketsystem', 'Create ticket') }}
				</NcButton>
			</div>
		</form>
	</NcModal>
</template>

<script setup>
import { computed, reactive, ref, watch } from 'vue'
import { t } from '@nextcloud/l10n'
import { showError } from '@nextcloud/dialogs'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcModal from '@nextcloud/vue/components/NcModal'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import TagPicker from './TagPicker.vue'
import { api, errorMessage } from '../api.js'
import { agentsForTeam } from '../format.js'

const props = defineProps({
	meta: { type: Object, required: true },
})
const emit = defineEmits(['close', 'created', 'tag-created'])

const form = reactive({ subject: '', customerName: '', customerEmail: '', body: '' })
const priority = ref(props.meta.priorities.find(p => p.key === 'normal') ?? props.meta.priorities[0])
const team = ref(props.meta.teams.find(x => x.id === props.meta.defaultTeamId) ?? props.meta.teams[0] ?? null)
const tagIds = ref([])
const teamAgents = computed(() => agentsForTeam(props.meta, team.value?.id))
const assignee = ref(teamAgents.value.find(a => a.uid === props.meta.currentUser) ?? null)

// The assignee must work on the chosen brand.
watch(team, () => {
	if (assignee.value && !teamAgents.value.some(a => a.uid === assignee.value.uid)) {
		assignee.value = null
	}
})
const saving = ref(false)

async function submit() {
	saving.value = true
	try {
		const result = await api.createTicket({
			subject: form.subject,
			body: form.body,
			customerName: form.customerName || null,
			customerEmail: form.customerEmail || null,
			priority: priority.value?.key,
			assigneeUid: assignee.value?.uid ?? null,
			teamId: team.value?.id ?? null,
			tagIds: tagIds.value,
		})
		emit('created', result.ticket)
	} catch (error) {
		showError(errorMessage(error, t('ticketsystem', 'Could not create the ticket')))
	} finally {
		saving.value = false
	}
}
</script>

<style scoped lang="scss">
.new-ticket {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 3);
	padding: calc(var(--default-grid-baseline) * 5);

	h2 {
		margin: 0;
	}

	&__hint {
		color: var(--color-text-maxcontrast);
	}

	&__row {
		display: grid;
		grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
		gap: calc(var(--default-grid-baseline) * 3);
		align-items: end;
	}

	&__actions {
		display: flex;
		justify-content: flex-end;
		gap: calc(var(--default-grid-baseline) * 2);
	}
}
</style>
