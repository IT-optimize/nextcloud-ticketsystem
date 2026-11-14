<!--
  - SPDX-FileCopyrightText: 2026 Co-Optimize
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<NcSelect :model-value="selected"
		class="tag-picker"
		:options="options"
		label="name"
		:multiple="true"
		:taggable="allowCreate"
		:push-tags="false"
		:close-on-select="false"
		:input-label="label"
		:placeholder="placeholder"
		:disabled="disabled || creating"
		:create-option="name => ({ id: null, name, color: null })"
		@update:model-value="onChange">
		<template #selected-option="option">
			<TagChip :tag="option" />
		</template>
		<template #option="option">
			<span class="tag-picker__option">
				<span class="tag-picker__dot" :style="{ background: option.color || 'var(--color-text-maxcontrast)' }" />
				{{ option.id === null ? t('ticketsystem', 'Create tag “{name}”', { name: option.name }) : option.name }}
			</span>
		</template>
	</NcSelect>
</template>

<script setup>
import { computed, ref } from 'vue'
import { t } from '@nextcloud/l10n'
import { showError } from '@nextcloud/dialogs'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import TagChip from './TagChip.vue'
import { api, errorMessage } from '../api.js'

const props = defineProps({
	/** Selected tag ids. */
	modelValue: { type: Array, required: true },
	/** All tags ({id, name, color}). */
	tags: { type: Array, required: true },
	label: { type: String, default: '' },
	placeholder: { type: String, default: '' },
	allowCreate: { type: Boolean, default: true },
	disabled: { type: Boolean, default: false },
})
// created: (tag) — a new tag exists now, so the caller can add it to its list
const emit = defineEmits(['update:modelValue', 'created'])

const creating = ref(false)
const options = computed(() => props.tags)
const selected = computed(() => props.modelValue.map(id => props.tags.find(tag => tag.id === id)).filter(Boolean))

async function onChange(value) {
	const list = value ?? []
	const fresh = list.filter(tag => tag.id === null)
	const ids = list.filter(tag => tag.id !== null).map(tag => tag.id)
	if (fresh.length === 0) {
		emit('update:modelValue', ids)
		return
	}
	creating.value = true
	try {
		for (const tag of fresh) {
			const created = (await api.createTag(tag.name)).tag
			emit('created', created)
			if (!ids.includes(created.id)) {
				ids.push(created.id)
			}
		}
		emit('update:modelValue', ids)
	} catch (error) {
		showError(errorMessage(error, t('ticketsystem', 'Could not create the tag')))
	} finally {
		creating.value = false
	}
}
</script>

<style scoped lang="scss">
.tag-picker {
	width: 100%;
	min-width: 0;

	&__option {
		display: inline-flex;
		align-items: center;
		gap: 8px;
	}

	&__dot {
		width: 10px;
		height: 10px;
		border-radius: 50%;
		flex-shrink: 0;
	}
}
</style>
