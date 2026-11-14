<!--
  - SPDX-FileCopyrightText: 2026 Co-Optimize
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<section v-if="replies.length" class="scheduled" :aria-label="t('ticketsystem', 'Scheduled replies')">
		<article v-for="reply in replies"
			:key="reply.id"
			class="scheduled__item"
			:class="{ 'scheduled__item--failed': reply.state === 'failed' }">
			<div class="scheduled__head">
				<SendClockIcon v-if="reply.state !== 'failed'" :size="18" />
				<AlertIcon v-else :size="18" />
				<strong v-if="reply.state === 'failed'">{{ t('ticketsystem', 'Scheduled reply not sent') }}</strong>
				<strong v-else-if="reply.state === 'sending'">{{ t('ticketsystem', 'Sending…') }}</strong>
				<strong v-else>{{ t('ticketsystem', 'Reply scheduled for {when}', { when: format(reply.sendAt) }) }}</strong>
				<span class="scheduled__by">· {{ agentName(meta, reply.uid) }}</span>
				<span v-if="reply.thenStatus" class="scheduled__then">· {{ t('ticketsystem', 'then {status}', { status: optionLabel(meta.statuses, reply.thenStatus) }) }}</span>
			</div>
			<p v-if="reply.state === 'failed' && reply.error" class="scheduled__error">{{ reply.error }}</p>
			<p v-if="newerCustomerMessage(reply)" class="scheduled__warning">
				{{ t('ticketsystem', 'The customer wrote after you scheduled this. Check that it still fits.') }}
			</p>
			<p v-if="reply.body" class="scheduled__body" :class="{ 'scheduled__body--open': open.has(reply.id) }" @click="toggle(reply.id)">
				{{ reply.body }}
			</p>
			<AttachmentList :attachments="reply.attachments ?? []" />
			<div v-if="canChange(reply) && reply.state !== 'sending'" class="scheduled__actions">
				<NcButton size="small" variant="secondary" :disabled="busy" @click="$emit('send-now', reply)">
					<template #icon>
						<SendIcon :size="16" />
					</template>
					{{ reply.state === 'failed' ? t('ticketsystem', 'Try again now') : t('ticketsystem', 'Send now') }}
				</NcButton>
				<NcButton size="small" variant="tertiary" :disabled="busy" @click="$emit('reschedule', reply)">
					{{ t('ticketsystem', 'Change time') }}
				</NcButton>
				<NcButton size="small" variant="tertiary" :disabled="busy" @click="$emit('edit', reply)">
					{{ t('ticketsystem', 'Edit text') }}
				</NcButton>
				<NcButton size="small" variant="tertiary" :disabled="busy" @click="$emit('cancel', reply)">
					{{ t('ticketsystem', 'Cancel') }}
				</NcButton>
			</div>
		</article>
	</section>
</template>

<script setup>
import { reactive } from 'vue'
import { getLanguage, t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import AlertIcon from 'vue-material-design-icons/AlertCircleOutline.vue'
import SendClockIcon from 'vue-material-design-icons/SendClockOutline.vue'
import SendIcon from 'vue-material-design-icons/Send.vue'
import { agentName, optionLabel } from '../format.js'
import AttachmentList from './AttachmentList.vue'

const props = defineProps({
	replies: { type: Array, required: true },
	messages: { type: Array, default: () => [] },
	meta: { type: Object, required: true },
	busy: { type: Boolean, default: false },
})
defineEmits(['send-now', 'reschedule', 'edit', 'cancel'])

const open = reactive(new Set())
const toggle = (id) => (open.has(id) ? open.delete(id) : open.add(id))
const formatter = new Intl.DateTimeFormat(getLanguage(), { weekday: 'short', day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' })
const format = (ts) => formatter.format(new Date(ts * 1000))
const canChange = (reply) => reply.uid === props.meta.currentUser || props.meta.isAdmin
const newerCustomerMessage = (reply) => props.messages.some(m => m.authorType === 'customer' && m.createdAt > reply.createdAt)
</script>

<style scoped lang="scss">
.scheduled {
	display: flex;
	flex-direction: column;
	gap: 8px;

	&__item {
		display: flex;
		flex-direction: column;
		gap: 6px;
		padding: 10px 12px;
		border: 1px dashed var(--color-primary-element);
		border-radius: var(--border-radius-large);
		background: var(--color-primary-element-light);

		&--failed {
			border-color: var(--color-error);
			background: color-mix(in srgb, var(--color-error) 10%, transparent);
		}

		p {
			margin: 0;
		}
	}

	&__head {
		display: flex;
		flex-wrap: wrap;
		align-items: center;
		gap: 6px;
	}

	&__by,
	&__then {
		color: var(--color-text-maxcontrast);
	}

	&__error {
		color: var(--color-error-text, var(--color-error));
	}

	&__warning {
		color: var(--color-warning-text, inherit);
		font-weight: 600;
	}

	&__body {
		display: -webkit-box;
		overflow: hidden;
		-webkit-line-clamp: 2;
		-webkit-box-orient: vertical;
		white-space: pre-wrap;
		cursor: pointer;

		&--open {
			display: block;
		}
	}

	&__actions {
		display: flex;
		flex-wrap: wrap;
		gap: 4px;
	}
}
</style>
