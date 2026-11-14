<!--
  - SPDX-FileCopyrightText: 2026 Co-Optimize
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<NcModal :name="t('ticketsystem', 'Canned replies')" size="large" @close="$emit('close')">
		<div class="canned">
			<aside class="canned__list">
				<div class="canned__list-head">
					<h2>{{ t('ticketsystem', 'Canned replies') }}</h2>
					<NcButton variant="primary" @click="startNew">
						<template #icon>
							<PlusIcon :size="20" />
						</template>
						{{ t('ticketsystem', 'New') }}
					</NcButton>
				</div>
				<NcLoadingIcon v-if="loading" />
				<p v-else-if="replies.length === 0" class="canned__empty">
					{{ t('ticketsystem', 'No canned replies yet. Save the answers you write again and again.') }}
				</p>
				<ul v-else>
					<NcListItem v-for="reply in replies"
						:key="reply.id"
						:name="reply.title"
						:active="editing && editing.id === reply.id"
						compact
						@click="edit(reply)">
						<template #subname>
							{{ reply.shared ? t('ticketsystem', 'Shared with the team') : t('ticketsystem', 'Only me') }}<template v-if="meta && meta.teams.length > 1 && reply.teamId"> · {{ teamOf(meta, reply.teamId)?.name }}</template>
						</template>
					</NcListItem>
				</ul>
			</aside>

			<form v-if="editing" ref="form" class="canned__form" @submit.prevent="save">
				<NcTextField v-model="editing.title" :label="t('ticketsystem', 'Title')" required />
				<NcTextArea v-model="editing.body"
					:label="t('ticketsystem', 'Text')"
					resize="vertical"
					class="canned__body" />
				<p class="canned__help">
					{{ t('ticketsystem', 'Placeholders are filled in when you insert the reply:') }}
					<template v-for="(key, i) in PLACEHOLDERS" :key="key">
						<code>{{ '{' + key + '}' }}</code>{{ i < PLACEHOLDERS.length - 1 ? ' ' : '' }}
					</template>
				</p>
				<NcSelect v-if="meta && meta.teams.length > 1"
					:model-value="meta.teams.find(team => team.id === editing.teamId) ?? allBrands"
					:options="[allBrands, ...meta.teams]"
					label="name"
					:clearable="false"
					:input-label="t('ticketsystem', 'Use on tickets of')"
					@update:model-value="o => editing.teamId = o.id" />
				<NcCheckboxRadioSwitch v-model="editing.shared" type="switch">
					{{ t('ticketsystem', 'Share with all agents') }}
				</NcCheckboxRadioSwitch>
				<div class="canned__actions">
					<NcButton v-if="editing.id"
						variant="error"
						:disabled="busy"
						@click="remove">
						<template #icon>
							<DeleteIcon :size="20" />
						</template>
						{{ t('ticketsystem', 'Delete') }}
					</NcButton>
					<span class="canned__spacer" />
					<NcButton variant="tertiary" @click="editing = null">
						{{ t('ticketsystem', 'Cancel') }}
					</NcButton>
					<NcButton type="submit"
						variant="primary"
						:disabled="busy || !editing.title.trim() || !editing.body.trim()">
						{{ t('ticketsystem', 'Save') }}
					</NcButton>
				</div>
			</form>
			<NcEmptyContent v-else
				class="canned__placeholder"
				:name="t('ticketsystem', 'Pick a reply to edit, or create a new one')">
				<template #icon>
					<TextBoxIcon />
				</template>
			</NcEmptyContent>
		</div>
	</NcModal>
</template>

<script setup>
import { nextTick, onMounted, ref } from 'vue'
import { t } from '@nextcloud/l10n'
import { showError, showSuccess } from '@nextcloud/dialogs'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcListItem from '@nextcloud/vue/components/NcListItem'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcModal from '@nextcloud/vue/components/NcModal'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import DeleteIcon from 'vue-material-design-icons/Delete.vue'
import PlusIcon from 'vue-material-design-icons/Plus.vue'
import TextBoxIcon from 'vue-material-design-icons/TextBoxOutline.vue'
import { api, errorMessage } from '../api.js'
import { PLACEHOLDERS, teamOf } from '../format.js'

const props = defineProps({
	/** Text to prefill a new reply with, e.g. "save this reply as canned". */
	initialBody: { type: String, default: '' },
	meta: { type: Object, default: null },
	/** Brand a new reply is for by default (the open ticket's). */
	teamId: { type: Number, default: null },
})

const allBrands = { id: null, name: t('ticketsystem', 'All brands') }
const emit = defineEmits(['close', 'changed'])

const replies = ref([])
const loading = ref(true)
const busy = ref(false)
const editing = ref(null)

async function load() {
	try {
		replies.value = (await api.listCanned()).replies
	} catch (error) {
		showError(errorMessage(error, t('ticketsystem', 'Could not load canned replies')))
	} finally {
		loading.value = false
	}
}

const form = ref(null)

async function startNew(body = '') {
	editing.value = { id: null, title: '', body: typeof body === 'string' ? body : '', shared: true, teamId: null }
	await nextTick()
	form.value?.querySelector('input')?.focus()
}

function edit(reply) {
	editing.value = { ...reply }
}

async function save() {
	busy.value = true
	try {
		const { id, title, body, shared, teamId } = editing.value
		const result = id
			? await api.updateCanned(id, { title, body, shared, teamId: teamId ?? null })
			: await api.createCanned({ title, body, shared, teamId: teamId ?? null })
		editing.value = { ...result.reply }
		await load()
		emit('changed')
		showSuccess(t('ticketsystem', 'Canned reply saved'))
	} catch (error) {
		showError(errorMessage(error, t('ticketsystem', 'Could not save the canned reply')))
	} finally {
		busy.value = false
	}
}

async function remove() {
	busy.value = true
	try {
		await api.deleteCanned(editing.value.id)
		editing.value = null
		await load()
		emit('changed')
	} catch (error) {
		showError(errorMessage(error, t('ticketsystem', 'Could not delete the canned reply')))
	} finally {
		busy.value = false
	}
}

onMounted(() => {
	load()
	if (props.initialBody) {
		startNew(props.initialBody)
	}
})
</script>

<style scoped lang="scss">
.canned {
	display: grid;
	grid-template-columns: minmax(200px, 1fr) 2fr;
	gap: calc(var(--default-grid-baseline) * 4);
	padding: calc(var(--default-grid-baseline) * 5);
	min-height: 60vh;

	&__list {
		border-inline-end: 1px solid var(--color-border);
		padding-inline-end: calc(var(--default-grid-baseline) * 3);
		overflow-y: auto;
		max-height: 70vh;
	}

	&__list-head {
		display: flex;
		align-items: center;
		justify-content: space-between;
		gap: 8px;
		margin-bottom: 8px;

		h2 {
			margin: 0;
			font-size: 1.2em;
		}
	}

	&__empty,
	&__help {
		color: var(--color-text-maxcontrast);
	}

	&__help code {
		font-size: 0.9em;
		background: var(--color-background-dark);
		padding: 1px 4px;
		border-radius: 4px;
		white-space: nowrap;
		line-height: 1.9;
	}

	&__form {
		// Keep the fields clear of the modal's close button.
		padding-inline-end: calc(var(--default-clickable-area) - 8px);
		display: flex;
		flex-direction: column;
		gap: calc(var(--default-grid-baseline) * 3);
	}

	&__body :deep(textarea) {
		min-height: 220px;
	}

	&__actions {
		display: flex;
		gap: 8px;
		flex-wrap: wrap;
	}

	&__spacer {
		flex: 1;
	}

	&__placeholder {
		align-self: center;
	}
}

@media (max-width: 700px) {
	.canned {
		grid-template-columns: 1fr;

		&__list {
			border-inline-end: none;
			max-height: 30vh;
		}
	}
}
</style>
