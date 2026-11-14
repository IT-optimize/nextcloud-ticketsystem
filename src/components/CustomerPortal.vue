<!--
  - SPDX-FileCopyrightText: 2026 Co-Optimize
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<!-- The public layout's #content does not scroll, so the page scrolls itself. -->
	<div class="portal-page">
		<main class="portal">
			<header class="portal__header">
				<p class="portal__company">{{ data.companyName }}</p>
				<h1>{{ data.subject }}</h1>
				<p class="portal__meta">
					<span class="portal__status" :class="{ 'portal__status--closed': data.closed }">{{ data.status }}</span>
					<span>{{ t('ticketsystem', 'Reference {reference}', { reference: data.reference }) }}</span>
					<span>·</span>
					<span>{{ t('ticketsystem', 'Opened {date}', { date: formatDate(data.createdAt) }) }}</span>
				</p>
			</header>

			<ol class="portal__thread" :aria-label="t('ticketsystem', 'Conversation')">
				<li v-for="message in data.messages"
					:key="message.id"
					class="portal__message"
					:class="`portal__message--${message.kind}`">
					<template v-if="message.kind === 'system'">
						{{ systemText(message.body) }} · {{ formatDate(message.createdAt) }}
					</template>
					<template v-else>
						<div class="portal__author">
							<strong>{{ message.kind === 'customer' ? t('ticketsystem', 'You') : (message.authorName || data.companyName) }}</strong>
							<span class="portal__time">{{ formatDate(message.createdAt) }}</span>
						</div>
						<div v-if="message.body" class="portal__body">{{ message.body }}</div>
						<AttachmentList :attachments="message.attachments ?? []" />
					</template>
				</li>
			</ol>

			<form class="portal__reply" @submit.prevent="send">
				<h2>{{ data.closed ? t('ticketsystem', 'Need more help?') : t('ticketsystem', 'Add a reply') }}</h2>
				<p v-if="data.closed" class="portal__hint">
					{{ t('ticketsystem', 'This request is closed. Writing a reply opens it again.') }}
				</p>
				<NcTextArea v-model="draft"
					:label="t('ticketsystem', 'Your message')"
					resize="vertical"
					:disabled="busy" />
				<div class="portal__files">
					<input ref="filesInput"
						type="file"
						multiple
						class="hidden-visually"
						tabindex="-1"
						aria-hidden="true"
						@change="onFiles">
					<NcButton variant="tertiary" :disabled="busy" @click="filesInput.click()">
						<template #icon>
							<PaperclipIcon :size="18" />
						</template>
						{{ t('ticketsystem', 'Add files (optional)') }}
					</NcButton>
					<span v-for="(file, i) in files" :key="i" class="portal__file">
						{{ file.name }}
						<NcButton variant="tertiary"
							size="small"
							:aria-label="t('ticketsystem', 'Remove {name}', { name: file.name })"
							@click="files.splice(i, 1)">
							<template #icon>
								<CloseIcon :size="16" />
							</template>
						</NcButton>
					</span>
				</div>
				<div class="portal__actions">
					<NcButton v-if="!data.closed && data.canResolve"
						variant="tertiary"
						:disabled="busy"
						@click="resolve">
						{{ t('ticketsystem', 'My problem is solved') }}
					</NcButton>
					<NcButton type="submit" variant="primary" :disabled="busy || (draft.trim() === '' && files.length === 0)">
						{{ t('ticketsystem', 'Send') }}
					</NcButton>
				</div>
				<p class="portal__privacy">
					{{ t('ticketsystem', 'Keep this link private: anyone who has it can read and answer this request.') }}
				</p>
			</form>
		</main>
	</div>
</template>

<script setup>
import { ref } from 'vue'
import axios from '@nextcloud/axios'
import { loadState } from '@nextcloud/initial-state'
import { getLanguage, t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { showError, showSuccess } from '@nextcloud/dialogs'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import AttachmentList from './AttachmentList.vue'
import CloseIcon from 'vue-material-design-icons/Close.vue'
import PaperclipIcon from 'vue-material-design-icons/Paperclip.vue'

const data = ref(loadState('ticketsystem', 'portal'))
const draft = ref('')
const busy = ref(false)
const token = window.location.pathname.split('/').filter(Boolean).pop()
const url = (path) => generateUrl('/apps/ticketsystem/portal/{token}' + path, { token })

const dateFormat = new Intl.DateTimeFormat(getLanguage(), { dateStyle: 'medium', timeStyle: 'short' })
const formatDate = (ts) => dateFormat.format(new Date(ts * 1000))

function systemText(body) {
	return body === 'The customer marked this request as solved.'
		? t('ticketsystem', 'You marked this request as solved')
		: body
}

// Same limits as PortalController, checked here to fail fast.
const MAX_FILES = 5
const MAX_FILES_SIZE = 10 * 1024 * 1024
const files = ref([])
const filesInput = ref(null)

function onFiles(event) {
	files.value.push(...Array.from(event.target.files ?? []))
	event.target.value = ''
}

async function send() {
	if (files.value.length > MAX_FILES || files.value.reduce((sum, f) => sum + f.size, 0) > MAX_FILES_SIZE) {
		showError(t('ticketsystem', 'Up to {count} files of at most {size} MB together can be added.', { count: MAX_FILES, size: MAX_FILES_SIZE / 1024 / 1024 }))
		return
	}
	busy.value = true
	try {
		const body = new FormData()
		body.append('body', draft.value)
		for (const file of files.value) {
			body.append('files[]', file)
		}
		const response = await axios.post(url('/messages'), body)
		data.value = response.data
		draft.value = ''
		files.value = []
		showSuccess(t('ticketsystem', 'Your message was sent'))
	} catch (error) {
		showError(error?.response?.data?.message || t('ticketsystem', 'Your message could not be sent. Please try again in a few minutes.'))
	} finally {
		busy.value = false
	}
}

async function resolve() {
	busy.value = true
	try {
		const response = await axios.post(url('/resolve'))
		data.value = response.data
		showSuccess(t('ticketsystem', 'Thank you! The request is marked as solved.'))
	} catch (error) {
		showError(error?.response?.data?.message || t('ticketsystem', 'That did not work. Please try again.'))
	} finally {
		busy.value = false
	}
}
</script>

<style scoped lang="scss">
.portal-page {
	height: 100%;
	overflow-y: auto;
	background: var(--color-main-background);
	border-radius: var(--border-radius-container-large, var(--border-radius-large));
}

.portal {
	max-width: 760px;
	margin: 0 auto;
	padding: calc(var(--default-grid-baseline) * 6) calc(var(--default-grid-baseline) * 4) calc(var(--default-grid-baseline) * 12);
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 6);

	&__company {
		color: var(--color-text-maxcontrast);
		margin: 0;
	}

	h1 {
		font-size: 1.6em;
		line-height: 1.3;
		margin: 4px 0 8px;
	}

	&__meta {
		display: flex;
		flex-wrap: wrap;
		align-items: center;
		gap: 8px;
		color: var(--color-text-maxcontrast);
	}

	&__status {
		padding: 2px 10px;
		border-radius: var(--border-radius-pill);
		background: var(--color-primary-element-light);
		color: var(--color-primary-element-light-text);
		font-weight: bold;

		&--closed {
			background: var(--color-success);
			color: var(--color-success-text, #fff);
		}
	}

	&__thread {
		list-style: none;
		margin: 0;
		padding: 0;
		display: flex;
		flex-direction: column;
		gap: calc(var(--default-grid-baseline) * 3);
	}

	&__message {
		padding: calc(var(--default-grid-baseline) * 4);
		border-radius: var(--border-radius-large);
		border: 1px solid var(--color-border);
		background: var(--color-main-background);

		&--customer {
			background: var(--color-primary-element-light);
			border-color: transparent;
			margin-inline-start: 10%;
		}

		&--agent {
			margin-inline-end: 10%;
		}

		&--system {
			border: none;
			padding: 4px;
			text-align: center;
			color: var(--color-text-maxcontrast);
			font-size: var(--font-size-small, 13px);
		}
	}

	&__author {
		display: flex;
		justify-content: space-between;
		gap: 8px;
		margin-bottom: 8px;
	}

	&__time {
		color: var(--color-text-maxcontrast);
		font-size: var(--font-size-small, 13px);
	}

	&__body {
		white-space: pre-wrap;
		overflow-wrap: anywhere;
		line-height: 1.5;
	}

	&__reply {
		display: flex;
		flex-direction: column;
		gap: calc(var(--default-grid-baseline) * 3);
		padding: calc(var(--default-grid-baseline) * 4);
		border: 1px solid var(--color-border);
		border-radius: var(--border-radius-large);
		background: var(--color-main-background);

		h2 {
			margin: 0;
			font-size: 1.2em;
		}

		:deep(textarea) {
			min-height: 140px;
		}
	}

	&__hint,
	&__privacy {
		color: var(--color-text-maxcontrast);
		margin: 0;
	}

	&__privacy {
		font-size: var(--font-size-small, 13px);
	}

	&__actions {
		display: flex;
		flex-wrap: wrap;
		justify-content: flex-end;
		gap: calc(var(--default-grid-baseline) * 2);
	}

	&__files {
		display: flex;
		flex-wrap: wrap;
		align-items: center;
		gap: 4px 8px;
	}

	&__file {
		display: inline-flex;
		align-items: center;
		gap: 2px;
		padding-inline-start: 10px;
		border: 1px solid var(--color-border);
		border-radius: var(--border-radius-pill);
		font-size: var(--font-size-small, 13px);
	}
}

@media (max-width: 600px) {
	.portal__message--customer,
	.portal__message--agent {
		margin-inline: 0;
	}
}
</style>
