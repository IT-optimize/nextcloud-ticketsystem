<!--
  - SPDX-FileCopyrightText: 2026 Co-Optimize
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="tags">
		<p class="tags__intro">
			{{ t('ticketsystem', 'Agents create tags while they work. Here you can rename, recolor or delete them. Deleting a tag removes it from every ticket.') }}
		</p>
		<p v-if="tags.length === 0" class="tags__empty">
			{{ t('ticketsystem', 'No tags yet.') }}
		</p>
		<ul v-else class="tags__list">
			<li v-for="tag in tags" :key="tag.id" class="tag-row">
				<NcColorPicker :model-value="tag.color || '#757575'" @update:model-value="c => update(tag, { color: c })">
					<button type="button"
						class="tag-row__swatch"
						:style="{ background: tag.color || '#757575' }"
						:aria-label="t('ticketsystem', 'Change color of {name}', { name: tag.name })" />
				</NcColorPicker>
				<NcTextField :model-value="names[tag.id] ?? tag.name"
					:label="t('ticketsystem', 'Name')"
					:label-outside="true"
					@update:model-value="v => names[tag.id] = v"
					@keydown.enter.prevent="rename(tag)"
					@blur="rename(tag)" />
				<NcButton variant="tertiary" :aria-label="t('ticketsystem', 'Delete {name}', { name: tag.name })" @click="confirmDelete = tag">
					<template #icon>
						<DeleteIcon :size="20" />
					</template>
				</NcButton>
			</li>
		</ul>
		<NcDialog v-if="confirmDelete"
			:name="t('ticketsystem', 'Delete tag')"
			size="small"
			:message="t('ticketsystem', 'Delete the tag “{name}”? It is removed from every ticket.', { name: confirmDelete.name })"
			:buttons="[
				{ label: t('ticketsystem', 'Cancel'), callback: () => { confirmDelete = null } },
				{ label: t('ticketsystem', 'Delete'), variant: 'error', callback: () => remove(confirmDelete) },
			]"
			@closing="confirmDelete = null" />
	</div>
</template>

<script setup>
import { reactive, ref } from 'vue'
import { t } from '@nextcloud/l10n'
import { showError } from '@nextcloud/dialogs'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcColorPicker from '@nextcloud/vue/components/NcColorPicker'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import DeleteIcon from 'vue-material-design-icons/Delete.vue'
import { api, errorMessage } from '../../api.js'

defineProps({
	tags: { type: Array, required: true },
})
const emit = defineEmits(['updated'])

const names = reactive({})
const confirmDelete = ref(null)

async function update(tag, changes) {
	try {
		emit('updated', await api.updateTag(tag.id, changes))
	} catch (error) {
		showError(errorMessage(error, t('ticketsystem', 'Could not change the tag')))
	}
}

function rename(tag) {
	const name = names[tag.id]
	delete names[tag.id]
	if (name !== undefined && name.trim() && name.trim() !== tag.name) {
		update(tag, { name: name.trim() })
	}
}

async function remove(tag) {
	confirmDelete.value = null
	try {
		emit('updated', await api.deleteTag(tag.id))
	} catch (error) {
		showError(errorMessage(error, t('ticketsystem', 'Could not delete the tag')))
	}
}
</script>

<style scoped lang="scss">
.tags {
	display: flex;
	flex-direction: column;
	gap: 12px;
	max-width: 600px;

	&__intro,
	&__empty {
		color: var(--color-text-maxcontrast);
		margin: 0;
	}

	&__list {
		display: flex;
		flex-direction: column;
		gap: 6px;
	}
}

.tag-row {
	display: flex;
	align-items: center;
	gap: 8px;

	&__swatch {
		width: 28px;
		height: 28px;
		min-height: 0;
		margin: 0;
		padding: 0;
		border: none;
		border-radius: 50%;
		cursor: pointer;
	}
}
</style>
