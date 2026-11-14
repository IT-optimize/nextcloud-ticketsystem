<!--
  - SPDX-FileCopyrightText: 2026 Co-Optimize
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="filters">
		<NcSelect :model-value="statusChoice"
			:options="statusOptions"
			label="label"
			:clearable="false"
			:input-label="t('ticketsystem', 'Status')"
			@update:model-value="setStatus" />
		<NcSelect :model-value="assigneeChoice"
			:options="assigneeOptions"
			label="label"
			:clearable="false"
			:input-label="t('ticketsystem', 'Assignee')"
			@update:model-value="o => patch({ assignee: o.value })" />
		<NcSelect v-if="meta.teams.length > 1"
			:model-value="meta.teams.filter(team => modelValue.teamIds.includes(team.id))"
			:options="meta.teams"
			label="name"
			:multiple="true"
			:input-label="t('ticketsystem', 'Brand')"
			:placeholder="t('ticketsystem', 'All my brands')"
			@update:model-value="list => patch({ teamIds: list.map(team => team.id) })" />
		<TagPicker :model-value="modelValue.tagIds"
			:tags="meta.tags"
			:allow-create="false"
			:label="t('ticketsystem', 'Tags (any of)')"
			:placeholder="t('ticketsystem', 'Any tags')"
			@update:model-value="ids => patch({ tagIds: ids })" />
		<NcSelect :model-value="meta.priorities.filter(p => modelValue.priorities.includes(p.key))"
			:options="meta.priorities"
			label="label"
			:multiple="true"
			:input-label="t('ticketsystem', 'Priority')"
			:placeholder="t('ticketsystem', 'Any priority')"
			@update:model-value="list => patch({ priorities: list.map(p => p.key) })" />
		<NcSelect :model-value="sortOptions.find(o => o.value === modelValue.sort)"
			:options="sortOptions"
			label="label"
			:clearable="false"
			:input-label="t('ticketsystem', 'Sort by')"
			@update:model-value="o => patch({ sort: o.value })" />
		<div class="filters__switches">
			<NcCheckboxRadioSwitch :model-value="modelValue.needsReply" @update:model-value="v => patch({ needsReply: v })">
				{{ t('ticketsystem', 'Only tickets that need a reply') }}
			</NcCheckboxRadioSwitch>
			<NcCheckboxRadioSwitch :model-value="modelValue.snoozed === 'only'"
				@update:model-value="v => patch({ snoozed: v ? 'only' : null })">
				{{ t('ticketsystem', 'Only snoozed tickets') }}
			</NcCheckboxRadioSwitch>
		</div>
	</div>
</template>

<script setup>
import { computed } from 'vue'
import { t } from '@nextcloud/l10n'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import TagPicker from './TagPicker.vue'

const props = defineProps({
	/** A filter as ViewService::normalize() returns it. */
	modelValue: { type: Object, required: true },
	meta: { type: Object, required: true },
})
const emit = defineEmits(['update:modelValue'])

const patch = (changes) => emit('update:modelValue', { ...props.modelValue, ...changes })

const statusOptions = computed(() => [
	{ id: 'scope:active', label: t('ticketsystem', 'Open, not waiting for the customer'), scope: 'active', statuses: [] },
	{ id: 'scope:open', label: t('ticketsystem', 'Open tickets'), scope: 'open', statuses: [] },
	{ id: 'scope:closed', label: t('ticketsystem', 'Done tickets'), scope: 'closed', statuses: [] },
	{ id: 'scope:any', label: t('ticketsystem', 'Any status'), scope: 'any', statuses: [] },
	...props.meta.statuses.map(s => ({ id: 'status:' + s.key, label: s.label, scope: 'any', statuses: [s.key] })),
])
const statusChoice = computed(() => {
	const f = props.modelValue
	if (f.statuses.length === 1) {
		return statusOptions.value.find(o => o.id === 'status:' + f.statuses[0])
	}
	return statusOptions.value.find(o => o.id === 'scope:' + f.scope)
})
function setStatus(option) {
	patch({ scope: option.scope, statuses: option.statuses })
}

const assigneeOptions = computed(() => [
	{ value: null, label: t('ticketsystem', 'Anyone') },
	{ value: 'me', label: t('ticketsystem', 'Me') },
	{ value: 'none', label: t('ticketsystem', 'Nobody (unassigned)') },
	...props.meta.agents
		.filter(agent => agent.uid !== props.meta.currentUser)
		.map(agent => ({ value: agent.uid, label: agent.displayName })),
])
const assigneeChoice = computed(() => assigneeOptions.value.find(o => o.value === props.modelValue.assignee)
	?? { value: props.modelValue.assignee, label: props.modelValue.assignee })

const sortOptions = [
	{ value: 'updated', label: t('ticketsystem', 'Recently updated') },
	{ value: 'created', label: t('ticketsystem', 'Newest first') },
	{ value: 'waiting', label: t('ticketsystem', 'Customer waiting longest') },
]
</script>

<style scoped lang="scss">
.filters {
	display: flex;
	flex-direction: column;
	gap: 10px;
	width: min(340px, 85vw);
	padding: 12px;

	:deep(.select) {
		width: 100%;
		min-width: 0;
	}

	&__switches {
		display: flex;
		flex-direction: column;
	}
}
</style>
