<!--
  - SPDX-FileCopyrightText: 2026 Co-Optimize
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<ul v-if="attachments.length" class="attachments" :aria-label="t('ticketsystem', 'Files')">
		<li v-for="file in attachments" :key="file.id" class="attachment" :class="{ 'attachment--image': file.isImage }">
			<a v-if="file.isImage"
				class="attachment__preview"
				:href="file.url"
				:title="t('ticketsystem', 'Download {name}', { name: file.name })"
				download>
				<img :src="file.url + (file.url.includes('?') ? '&' : '?') + 'inline=1'" :alt="file.name" loading="lazy">
			</a>
			<div class="attachment__row">
				<FileIcon v-if="!file.isImage" :size="20" class="attachment__icon" />
				<a class="attachment__name"
					:href="file.url"
					:title="t('ticketsystem', 'Download {name}', { name: file.name })"
					download>{{ file.name }}</a>
				<span class="attachment__size">{{ formatBytes(file.size) }}</span>
				<NcButton v-if="removable"
					variant="tertiary"
					size="small"
					:aria-label="t('ticketsystem', 'Remove {name}', { name: file.name })"
					:title="t('ticketsystem', 'Remove {name}', { name: file.name })"
					@click="$emit('remove', file)">
					<template #icon>
						<CloseIcon :size="16" />
					</template>
				</NcButton>
				<NcButton v-if="saveable"
					variant="tertiary"
					size="small"
					:disabled="saving === file.id"
					:aria-label="t('ticketsystem', 'Save {name} in my Files', { name: file.name })"
					:title="t('ticketsystem', 'Save in my Files')"
					@click="save(file)">
					<template #icon>
						<NcLoadingIcon v-if="saving === file.id" :size="16" />
						<FolderIcon v-else :size="16" />
					</template>
				</NcButton>
			</div>
		</li>
	</ul>
</template>

<script setup>
import { ref } from 'vue'
import { t } from '@nextcloud/l10n'
import { showError, showSuccess } from '@nextcloud/dialogs'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import CloseIcon from 'vue-material-design-icons/Close.vue'
import FileIcon from 'vue-material-design-icons/FileOutline.vue'
import FolderIcon from 'vue-material-design-icons/FolderDownloadOutline.vue'
import { api, errorMessage } from '../api.js'
import { formatBytes } from '../format.js'

defineProps({
	attachments: { type: Array, default: () => [] },
	/** Agents can copy files into their own Files (needs the agent API). */
	saveable: { type: Boolean, default: false },
	/** Files waiting in the composer can be taken off again. */
	removable: { type: Boolean, default: false },
})
defineEmits(['remove'])

const saving = ref(null)
async function save(file) {
	saving.value = file.id
	try {
		const result = await api.saveAttachment(file.id)
		showSuccess(t('ticketsystem', 'Saved in your Files: {path}', { path: result.path }))
	} catch (error) {
		showError(errorMessage(error, t('ticketsystem', 'Could not save the file')))
	} finally {
		saving.value = null
	}
}
</script>

<style scoped lang="scss">
.attachments {
	display: flex;
	flex-wrap: wrap;
	align-items: flex-start;
	gap: 8px;
	margin: 8px 0 0;
	padding: 0;
	list-style: none;
}

.attachment {
	display: flex;
	flex-direction: column;
	gap: 4px;
	max-width: 100%;
	padding: 4px 4px 4px 8px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	background: var(--color-main-background);

	&--image {
		width: 220px;
		padding: 4px;
	}

	&__preview {
		display: block;
		overflow: hidden;
		border-radius: var(--border-radius);
		background: var(--color-background-dark);

		img {
			display: block;
			width: 100%;
			max-height: 160px;
			object-fit: contain;
		}
	}

	&__row {
		display: flex;
		align-items: center;
		gap: 6px;
		min-width: 0;
		min-height: 32px;
	}

	&__icon {
		flex: none;
		color: var(--color-text-maxcontrast);
	}

	&__name {
		overflow: hidden;
		text-overflow: ellipsis;
		white-space: nowrap;
		min-width: 0;

		&:hover,
		&:focus-visible {
			text-decoration: underline;
		}
	}

	&__size {
		flex: none;
		margin-inline-start: auto;
		color: var(--color-text-maxcontrast);
		font-size: var(--font-size-small, 13px);
	}
}
</style>
