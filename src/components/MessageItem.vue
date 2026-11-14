<!--
  - SPDX-FileCopyrightText: 2026 Co-Optimize
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<li v-if="message.authorType === 'system'" class="event">
		<HistoryIcon :size="16" class="event__icon" />
		<span class="event__text">
			<template v-for="(line, i) in eventLines" :key="i">
				{{ line }}<br v-if="i < eventLines.length - 1">
			</template>
		</span>
		<NcDateTime class="event__time" :timestamp="message.createdAt * 1000" />
	</li>
	<li v-else class="message" :class="[`message--${message.authorType}`, `message--${message.visibility}`, { 'message--failed': message.deliveryStatus === 'failed', 'message--mine': mine, 'message--colleague': message.authorType === 'agent' && !mine }]">
		<div class="message__meta">
			<NcAvatar v-if="message.authorUid"
				:user="message.authorUid"
				:display-name="author"
				:size="24"
				hide-status />
			<AccountCircleIcon v-else :size="24" />
			<strong>{{ author }}</strong>
			<span v-if="message.authorType === 'customer'" class="message__badge message__badge--customer">{{ t('ticketsystem', 'Customer') }}</span>
			<span v-else-if="mine" class="message__badge message__badge--muted">{{ t('ticketsystem', 'You') }}</span>
			<span v-if="message.visibility === 'internal'" class="message__badge">
				<LockIcon :size="14" />
				{{ t('ticketsystem', 'Internal note') }}
			</span>
			<template v-else-if="message.authorType === 'agent'">
				<span v-if="message.deliveryStatus === 'failed'"
					class="message__badge message__badge--failed"
					:title="message.deliveryError">
					<AlertIcon :size="14" />
					{{ t('ticketsystem', 'Email not delivered') }}
				</span>
				<span v-else-if="message.deliveryStatus === 'skipped'" class="message__badge message__badge--muted">
					{{ t('ticketsystem', 'Not emailed (customer emails are off)') }}
				</span>
				<span v-else class="message__badge message__badge--public">
					{{ t('ticketsystem', 'Sent to customer') }}
				</span>
			</template>
			<NcDateTime class="message__time" :timestamp="message.createdAt * 1000" />
		</div>
		<!-- Plain text with preserved line breaks; never rendered as HTML. -->
		<p v-if="message.meta?.erased" class="message__erased">
			{{ t('ticketsystem', 'Removed when the customer data was erased') }}
		</p>
		<div v-else-if="message.body" class="message__body"><template v-for="(part, i) in bodyParts" :key="i"><span v-if="part.mention" class="message__mention" :title="'@' + part.mention">@{{ agentName(meta, part.mention) }}</span><template v-else>{{ part.text }}</template></template></div>
		<AttachmentList :attachments="message.attachments ?? []" saveable />
		<div v-if="message.deliveryStatus === 'failed' || message.deliveryStatus === 'skipped'" class="message__delivery">
			<span v-if="message.deliveryError">{{ message.deliveryError }}</span>
			<NcButton variant="secondary" size="small" :disabled="resending" @click="resend">
				<template #icon>
					<NcLoadingIcon v-if="resending" :size="16" />
					<RefreshIcon v-else :size="16" />
				</template>
				{{ t('ticketsystem', 'Send email again') }}
			</NcButton>
		</div>
	</li>
</template>

<script setup>
import { computed, ref } from 'vue'
import { t } from '@nextcloud/l10n'
import { showError, showSuccess } from '@nextcloud/dialogs'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import AlertIcon from 'vue-material-design-icons/AlertCircleOutline.vue'
import RefreshIcon from 'vue-material-design-icons/Refresh.vue'
import { api, errorMessage } from '../api.js'
import AttachmentList from './AttachmentList.vue'
import NcAvatar from '@nextcloud/vue/components/NcAvatar'
import NcDateTime from '@nextcloud/vue/components/NcDateTime'
import AccountCircleIcon from 'vue-material-design-icons/AccountCircle.vue'
import HistoryIcon from 'vue-material-design-icons/History.vue'
import LockIcon from 'vue-material-design-icons/Lock.vue'
import { getLanguage } from '@nextcloud/l10n'
import { agentName, optionLabel, tagOf, teamOf } from '../format.js'

const dateFormat = new Intl.DateTimeFormat(getLanguage(), { dateStyle: 'medium', timeStyle: 'short' })
const dateTime = (ts) => dateFormat.format(new Date(ts * 1000))

const emit = defineEmits(['update'])
const resending = ref(false)

async function resend() {
	resending.value = true
	try {
		const result = await api.resendMessage(props.message.ticketId, props.message.id)
		emit('update', result.message)
		if (result.message.deliveryStatus === 'sent') {
			showSuccess(t('ticketsystem', 'Email sent'))
		} else {
			showError(t('ticketsystem', 'The email still could not be sent: {error}', { error: result.message.deliveryError || t('ticketsystem', 'customer emails are turned off') }))
		}
	} catch (error) {
		showError(errorMessage(error, t('ticketsystem', 'Could not send the email')))
	} finally {
		resending.value = false
	}
}

const props = defineProps({
	message: { type: Object, required: true },
	ticket: { type: Object, default: null },
	meta: { type: Object, required: true },
})

/** Written by the agent looking at the ticket, as opposed to a colleague. */
const mine = computed(() => props.message.authorType === 'agent' && props.message.authorUid === props.meta.currentUser)

/** One sentence per change in a history entry. */
const eventLines = computed(() => {
	const m = props.message
	const meta = m.meta
	if (!meta) {
		return [m.body === 'The customer marked this request as solved.'
			? t('ticketsystem', 'The customer marked this request as solved in the portal')
			: m.body]
	}
	if (meta.type === 'bounced') {
		return [t('ticketsystem', 'An email to {recipient} could not be delivered: {reason}', {
			recipient: meta.recipient || t('ticketsystem', 'the customer'),
			reason: meta.reason || '',
		})]
	}
	if (meta.type === 'erased') {
		return [m.authorUid
			? t('ticketsystem', '{actor} erased the customer data of this ticket', { actor: agentName(props.meta, m.authorUid) })
			: t('ticketsystem', 'The customer data of this ticket was erased automatically')]
	}
	if (meta.type === 'attachmentsFailed') {
		return [t('ticketsystem', 'These attachments could not be saved and are only in the support inbox: {names}', { names: (meta.names ?? []).join(', ') })]
	}
	const actor = m.authorUid ? agentName(props.meta, m.authorUid) : null
	const name = (uid) => agentName(props.meta, uid)
	const lines = []
	for (const [field, change] of Object.entries(meta.changes || {})) {
		const from = change.old
		const to = change.new
		switch (field) {
		case 'status': {
			const values = { actor, from: optionLabel(props.meta.statuses, from), to: optionLabel(props.meta.statuses, to) }
			lines.push(actor
				? t('ticketsystem', '{actor} changed the status from {from} to {to}', values)
				: t('ticketsystem', 'Status changed automatically from {from} to {to}', values))
			break
		}
		case 'priority': {
			const values = { actor, from: optionLabel(props.meta.priorities, from), to: optionLabel(props.meta.priorities, to) }
			lines.push(actor
				? t('ticketsystem', '{actor} changed the priority from {from} to {to}', values)
				: t('ticketsystem', 'Priority changed from {from} to {to}', values))
			break
		}
		case 'assigneeUid':
			if (!to) {
				lines.push(actor ? t('ticketsystem', '{actor} unassigned the ticket', { actor }) : t('ticketsystem', 'The ticket was unassigned'))
			} else if (to === m.authorUid) {
				lines.push(t('ticketsystem', '{actor} took the ticket', { actor }))
			} else {
				lines.push(actor
					? t('ticketsystem', '{actor} assigned the ticket to {name}', { actor, name: name(to) })
					: t('ticketsystem', 'The ticket was assigned to {name}', { name: name(to) }))
			}
			break
		case 'teamId':
			lines.push(t('ticketsystem', '{actor} moved the ticket from {from} to {to}', {
				actor: actor || t('ticketsystem', 'Someone'),
				from: teamOf(props.meta, from)?.name ?? t('ticketsystem', 'another brand'),
				to: teamOf(props.meta, to)?.name ?? t('ticketsystem', 'another brand'),
			}))
			break
		case 'companyId': {
			const company = (id) => props.meta.companies?.find(c => c.id === id)?.name ?? t('ticketsystem', 'a deleted company')
			lines.push(to
				? (actor
					? t('ticketsystem', '{actor} set the company to {to}', { actor, to: company(to) })
					: t('ticketsystem', 'The ticket was linked to {to} by its email domain', { to: company(to) }))
				: t('ticketsystem', '{actor} removed the company', { actor: actor || t('ticketsystem', 'Someone') }))
			break
		}
		case 'snoozedUntil':
			if (to) {
				lines.push(t('ticketsystem', '{actor} snoozed the ticket until {date}', { actor: actor || t('ticketsystem', 'Someone'), date: dateTime(to) }))
			} else {
				lines.push(actor
					? t('ticketsystem', '{actor} woke the ticket up', { actor })
					: t('ticketsystem', 'The snooze ended'))
			}
			break
		case 'tagIds': {
			const added = (to || []).filter(id => !(from || []).includes(id))
			const removed = (from || []).filter(id => !(to || []).includes(id))
			const tagNames = (ids) => ids.map(id => tagOf(props.meta, id)?.name ?? t('ticketsystem', 'a deleted tag')).join(', ')
			if (added.length) {
				lines.push(t('ticketsystem', '{actor} added the tag {tags}', { actor: actor || t('ticketsystem', 'Someone'), tags: tagNames(added) }))
			}
			if (removed.length) {
				lines.push(t('ticketsystem', '{actor} removed the tag {tags}', { actor: actor || t('ticketsystem', 'Someone'), tags: tagNames(removed) }))
			}
			break
		}
		case 'cc': {
			const added = (to || []).filter(a => !(from || []).includes(a))
			const removed = (from || []).filter(a => !(to || []).includes(a))
			if (added.length) {
				lines.push(actor
					? t('ticketsystem', '{actor} copied {emails} on this ticket', { actor, emails: added.join(', ') })
					: t('ticketsystem', '{emails} copied on this ticket by email', { emails: added.join(', ') }))
			}
			if (removed.length) {
				lines.push(t('ticketsystem', '{actor} stopped copying {emails}', { actor: actor || t('ticketsystem', 'Someone'), emails: removed.join(', ') }))
			}
			break
		}
		case 'subject':
			lines.push(t('ticketsystem', '{actor} renamed the ticket to “{to}”', { actor: actor || t('ticketsystem', 'Someone'), to }))
			break
		default:
			lines.push(t('ticketsystem', '{actor} changed the customer details', { actor: actor || t('ticketsystem', 'Someone') }))
		}
	}
	return lines.length ? lines : [m.body]
})

/** The body split into text and @mentions of agents, for highlighting. */
const bodyParts = computed(() => {
	const body = props.message.body
	if (props.message.authorType !== 'agent' || !body.includes('@')) {
		return [{ text: body }]
	}
	const uids = (props.meta.agents ?? []).map(a => a.uid).sort((a, b) => b.length - a.length)
	if (uids.length === 0) {
		return [{ text: body }]
	}
	const escape = (s) => s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')
	const pattern = new RegExp('(^|[^\\w.@-])@(' + uids.map(escape).join('|') + ')(?![\\w@-])', 'g')
	const parts = []
	let last = 0
	for (const match of body.matchAll(pattern)) {
		const at = match.index + match[1].length
		if (at > last) {
			parts.push({ text: body.slice(last, at) })
		}
		parts.push({ mention: match[2] })
		last = at + 1 + match[2].length
	}
	if (last < body.length) {
		parts.push({ text: body.slice(last) })
	}
	return parts
})

const author = computed(() => {
	const m = props.message
	if (m.authorType === 'customer') {
		if (props.ticket?.customerName && m.authorEmail === props.ticket.customerEmail) {
			return props.ticket.customerName
		}
		return m.authorEmail || t('ticketsystem', 'Customer')
	}
	if (m.authorType === 'system') {
		return t('ticketsystem', 'System')
	}
	return agentName(props.meta, m.authorUid)
})
</script>

<style scoped lang="scss">
.event {
	display: flex;
	align-items: flex-start;
	gap: 8px;
	padding: 2px calc(var(--default-grid-baseline) * 3);
	color: var(--color-text-maxcontrast);
	font-size: var(--font-size-small, 13px);
	line-height: 1.5;

	&__icon {
		margin-top: 2px;
	}

	&__text {
		flex: 1;
		overflow-wrap: anywhere;
	}

	&__time {
		white-space: nowrap;
	}

	&__actions {
		margin-block: -8px;
	}
}

.message {
	// Who wrote it, at a glance: a coloured edge and a light tint (labels say it in words too).
	--hd-customer: #2f80d1;
	--hd-colleague: #7950f2;
	--hd-note: var(--color-warning);
	padding: calc(var(--default-grid-baseline) * 3);
	border-radius: var(--border-radius-large);
	border: 1px solid var(--color-border);
	border-inline-start-width: 4px;
	background: var(--color-main-background);

	&--customer {
		border-inline-start-color: var(--hd-customer);
		background: color-mix(in srgb, var(--hd-customer) 7%, var(--color-main-background));
	}

	&--colleague {
		border-inline-start-color: var(--hd-colleague);
		background: color-mix(in srgb, var(--hd-colleague) 5%, var(--color-main-background));
	}

	// Your own messages sit a little to the right, as in a chat.
	&--mine {
		margin-inline-start: 40px;
	}

	&--internal {
		border-color: var(--hd-note);
		background: color-mix(in srgb, var(--hd-note) 14%, var(--color-main-background));
	}

	&--failed {
		border-color: var(--color-error);
	}

	@container (max-width: 600px) {
		&--mine {
			margin-inline-start: 16px;
		}
	}

	&__meta {
		display: flex;
		align-items: center;
		flex-wrap: wrap;
		gap: 8px;
		margin-bottom: 8px;
	}

	&__badge {
		display: inline-flex;
		align-items: center;
		gap: 4px;
		padding: 0 8px;
		border-radius: var(--border-radius-pill);
		font-size: var(--font-size-small, 13px);
		background: var(--color-warning);
		color: var(--color-warning-text, #000);

		&--public {
			background: var(--color-primary-element-light);
			color: var(--color-primary-element-light-text);
		}

		&--customer {
			background: color-mix(in srgb, var(--hd-customer) 16%, var(--color-main-background));
			color: var(--color-main-text);
		}

		&--failed {
			background: var(--color-error);
			color: var(--color-error-text, #fff);
		}

		&--muted {
			background: var(--color-background-dark);
			color: var(--color-text-maxcontrast);
		}
	}

	&__time {
		margin-inline-start: auto;
		color: var(--color-text-maxcontrast);
		font-size: var(--font-size-small, 13px);
	}

	&__delivery {
		display: flex;
		flex-wrap: wrap;
		align-items: center;
		gap: 8px;
		margin-top: 12px;
		padding-top: 8px;
		border-top: 1px solid var(--color-border);
		color: var(--color-text-error, var(--color-error));
		font-size: var(--font-size-small, 13px);
	}

	&__mention {
		padding: 0 4px;
		border-radius: var(--border-radius-pill);
		background: var(--color-primary-element-light);
		color: var(--color-primary-element-light-text);
		font-weight: 600;
	}

	&__erased {
		margin: 0;
		color: var(--color-text-maxcontrast);
		font-style: italic;
	}

	&__body {
		white-space: pre-wrap;
		overflow-wrap: anywhere;
		line-height: 1.5;
	}
}
</style>
