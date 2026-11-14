<!--
  - SPDX-FileCopyrightText: 2026 Co-Optimize
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="ticket">
		<NcLoadingIcon v-if="!ticket && !loadError" class="ticket__loading" :size="40" />
		<NcEmptyContent v-else-if="loadError" :name="loadError">
			<template #icon>
				<TicketIcon />
			</template>
		</NcEmptyContent>

		<template v-else>
			<header class="ticket__header">
				<div class="ticket__title">
					<span class="ticket__reference">{{ ticket.reference }}</span>
					<h2>{{ ticket.subject }}</h2>
				</div>
				<div class="ticket__header-actions">
					<div v-if="others.length" class="ticket__viewers" role="status">
						<NcAvatar v-for="other in others"
							:key="other.uid"
							:user="other.uid"
							:display-name="agentName(meta, other.uid)"
							:size="24"
							hide-status
							disable-menu
							disable-tooltip />
						<span>{{ viewersText }}</span>
					</div>
					<NcActions :aria-label="t('ticketsystem', 'More ticket actions')" :inline="0">
						<NcActionCaption :name="t('ticketsystem', 'Snooze until')" />
						<NcActionButton v-for="preset in snoozePresets()"
							:key="preset.id"
							close-after-click
							@click="save({ snoozedUntil: preset.until })">
							<template #icon>
								<AlarmSnoozeIcon :size="20" />
							</template>
							{{ preset.label }}
						</NcActionButton>
						<NcActionInput type="datetime-local"
							:model-value="customSnooze"
							:label="t('ticketsystem', 'Pick a date and time')"
							:min="new Date()"
							@update:model-value="d => customSnooze = d"
							@submit="snoozeCustom">
							<template #icon>
								<CalendarIcon :size="20" />
							</template>
						</NcActionInput>
						<NcActionButton v-if="portalUrl" close-after-click @click="copyPortalLink">
							<template #icon>
								<ContentCopyIcon :size="20" />
							</template>
							{{ t('ticketsystem', 'Copy customer link') }}
						</NcActionButton>
					</NcActions>
				</div>
			</header>

			<div v-if="state" class="ticket__state" :class="'ticket__state--' + state" role="status">
				<PauseIcon :size="20" />
				<strong>{{ t('ticketsystem', 'Paused') }}</strong>
				<span>{{ stateDetails }}</span>
			</div>

			<div class="ticket__body">
				<TicketSidebar class="ticket__side"
					:ticket="ticket"
					:details="details"
					:meta="meta"
					:portal-url="portalUrl"
					:saving="saving"
					@save="save"
					@copy-portal-link="copyPortalLink"
					@tag-created="tag => $emit('tag-created', tag)" />

				<div class="ticket__main">
					<ol class="ticket__thread" :aria-label="t('ticketsystem', 'Conversation')">
						<MessageItem v-for="message in messages"
							:key="message.id"
							:message="message"
							:ticket="ticket"
							:meta="meta"
							@update="replaceMessage" />
					</ol>

					<ScheduledReplies :replies="scheduled"
						:messages="messages"
						:meta="meta"
						:busy="scheduleBusy"
						@send-now="sendScheduledNow"
						@reschedule="r => scheduleDialog = { reply: r }"
						@edit="editScheduled"
						@cancel="cancelScheduled" />

					<NcNoteCard v-if="writers.length" type="warning" class="ticket__collision">
						{{ writersText }}
					</NcNoteCard>

					<form
						ref="composer"
						class="ticket__composer"
						:class="{ 'ticket__composer--internal': mode === 'internal', 'ticket__composer--dropping': dropping }"
						@dragover.prevent="onDragOver"
						@dragleave="onDragLeave"
						@drop.prevent="onDrop"
						@submit.prevent="send()">
						<div class="ticket__modes" role="radiogroup" :aria-label="t('ticketsystem', 'Message type')">
							<NcButton :variant="mode === 'public' ? 'primary' : 'secondary'"
								:disabled="!ticket.customerEmail"
								:aria-pressed="mode === 'public'"
								:title="t('ticketsystem', 'Shortcut: r')"
								@click="mode = 'public'">
								<template #icon>
									<SendIcon :size="18" />
								</template>
								{{ t('ticketsystem', 'Reply to customer') }}
							</NcButton>
							<NcButton :variant="mode === 'internal' ? 'primary' : 'secondary'"
								:aria-pressed="mode === 'internal'"
								:title="t('ticketsystem', 'Shortcut: n')"
								@click="mode = 'internal'">
								<template #icon>
									<LockIcon :size="18" />
								</template>
								{{ t('ticketsystem', 'Internal note') }}
							</NcButton>
						</div>

						<ComposerTools ref="tools"
							:ticket="ticket"
							:meta="meta"
							:draft="draft"
							:mode="mode"
							:disabled="sending"
							@insert="onInsert" />

						<div class="ticket__textarea">
							<NcTextArea v-model="draft"
								:label="mode === 'public' ? t('ticketsystem', 'Reply (emailed to the customer)') : t('ticketsystem', 'Internal note (only visible to agents) – type @ to mention a colleague')"
								resize="vertical"
								:disabled="sending"
								@input="onTyping"
								@paste="onPaste"
								@keydown.ctrl.enter="send()"
								@keydown.meta.enter="send()"
								@keydown.esc="$event.target.blur()" />
							<MentionSuggest :textarea="textareaEl"
								:agents="mentionable"
								:enabled="mode === 'internal'"
								@insert="insertMention" />
						</div>

						<div v-if="files.length" class="ticket__files">
							<AttachmentList :attachments="files.filter(f => f.id)" removable @remove="removeFile" />
							<p v-for="file in files.filter(f => !f.id)" :key="file.key" class="ticket__uploading">
								<NcLoadingIcon :size="16" />
								{{ t('ticketsystem', 'Uploading {name} … {percent} %', { name: file.name, percent: Math.round(file.progress * 100) }) }}
							</p>
						</div>

						<p v-if="mode === 'public' && ticket.cc?.length" class="ticket__copies">
							{{ t('ticketsystem', 'A copy goes to {emails}', { emails: ticket.cc.join(', ') }) }}
						</p>

						<p v-if="mode === 'public' && signature" class="ticket__signature" :title="t('ticketsystem', 'Set by an administrator')">
							<span>{{ t('ticketsystem', 'Added below your reply:') }}</span>
							{{ signature }}
						</p>

						<div class="ticket__actions">
							<input ref="fileInput"
								type="file"
								multiple
								class="hidden-visually"
								tabindex="-1"
								aria-hidden="true"
								@change="onFilesChosen">
							<NcButton variant="tertiary"
								:disabled="sending"
								:title="t('ticketsystem', 'You can also drop files here or paste a screenshot')"
								@click="fileInput.click()">
								<template #icon>
									<PaperclipIcon :size="18" />
								</template>
								{{ t('ticketsystem', 'Attach files') }}
							</NcButton>
							<NcButton v-if="draft.trim()"
								variant="tertiary"
								@click="tools.saveAsCanned(draft)">
								{{ t('ticketsystem', 'Save as canned reply') }}
							</NcButton>
							<span v-if="draftRestored && draft.trim()" class="ticket__draft-note">
								{{ t('ticketsystem', 'Unsent draft restored') }}
							</span>
							<span class="ticket__spacer" />
							<template v-if="mode === 'public'">
								<NcButton v-if="hasPendingStatus"
									variant="secondary"
									:disabled="!canSend"
									@click="send('pending')">
									{{ t('ticketsystem', 'Send & wait for customer') }}
								</NcButton>
								<NcButton v-if="resolvedStatus"
									variant="secondary"
									:disabled="!canSend"
									@click="send(resolvedStatus)">
									{{ t('ticketsystem', 'Send & resolve') }}
								</NcButton>
							</template>
							<NcButton v-if="mode === 'public'"
								variant="secondary"
								:disabled="!canSend"
								:aria-label="t('ticketsystem', 'Send later')"
								:title="t('ticketsystem', 'Send later')"
								@click="scheduleDialog = { reply: null }">
								<template #icon>
									<SendClockIcon :size="18" />
								</template>
							</NcButton>
							<NcButton type="submit"
								variant="primary"
								:disabled="!canSend"
								:title="t('ticketsystem', 'Ctrl + Enter')">
								<template #icon>
									<NcLoadingIcon v-if="sending" :size="18" />
								</template>
								{{ mode === 'public' ? t('ticketsystem', 'Send reply') : t('ticketsystem', 'Add note') }}
							</NcButton>
						</div>
					</form>
				</div>
			</div>

			<ScheduleDialog v-if="scheduleDialog"
				:statuses="meta.statuses"
				:pending-status="hasPendingStatus ? 'pending' : null"
				:resolved-status="resolvedStatus"
				:editing="!!scheduleDialog.reply"
				:initial-at="scheduleDialog.reply?.sendAt ?? null"
				:busy="scheduleBusy"
				@close="scheduleDialog = null"
				@confirm="confirmSchedule" />
		</template>
	</div>
</template>

<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { getLanguage, n, t } from '@nextcloud/l10n'
import { showError, showSuccess } from '@nextcloud/dialogs'
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import NcActionCaption from '@nextcloud/vue/components/NcActionCaption'
import NcActionInput from '@nextcloud/vue/components/NcActionInput'
import NcActions from '@nextcloud/vue/components/NcActions'
import NcAvatar from '@nextcloud/vue/components/NcAvatar'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import AlarmSnoozeIcon from 'vue-material-design-icons/AlarmSnooze.vue'
import CalendarIcon from 'vue-material-design-icons/CalendarClock.vue'
import ContentCopyIcon from 'vue-material-design-icons/ContentCopy.vue'
import LockIcon from 'vue-material-design-icons/Lock.vue'
import SendIcon from 'vue-material-design-icons/Send.vue'
import SendClockIcon from 'vue-material-design-icons/SendClockOutline.vue'
import TicketIcon from 'vue-material-design-icons/TicketOutline.vue'
import ComposerTools from './ComposerTools.vue'
import MentionSuggest from './MentionSuggest.vue'
import MessageItem from './MessageItem.vue'
import ScheduleDialog from './ScheduleDialog.vue'
import ScheduledReplies from './ScheduledReplies.vue'
import TicketSidebar from './TicketSidebar.vue'
import { api, errorMessage } from '../api.js'
import { clearDraft, loadDraft, saveDraft } from '../drafts.js'
import { agentName, agentsForTeam, formatBytes, snoozePresets, teamOf, ticketState } from '../format.js'
import PauseIcon from 'vue-material-design-icons/PauseCircleOutline.vue'
import AttachmentList from './AttachmentList.vue'
import PaperclipIcon from 'vue-material-design-icons/Paperclip.vue'

const props = defineProps({
	ticketId: { type: Number, required: true },
	meta: { type: Object, required: true },
})
const emit = defineEmits(['changed', 'tag-created'])

const EMPTY_DETAILS = { tagIds: [], otherTickets: [] }

const ticket = ref(null)
const messages = ref([])
const details = ref({ ...EMPTY_DETAILS })
const version = ref('')
const loadError = ref('')
const portalUrl = ref(null)
const saving = ref(false)
const sending = ref(false)
const draft = ref('')
const mode = ref('public')
const draftRestored = ref(false)
const tools = ref(null)
const composer = ref(null)
const others = ref([])
const customSnooze = ref(null)
const textareaEl = ref(null)
const scheduled = ref([])
const scheduleDialog = ref(null)
const scheduleBusy = ref(false)

const state = computed(() => ticketState(ticket.value, props.meta))
/** Why the ticket is paused. */
const stateDetails = computed(() => {
	const tk = ticket.value
	const parts = []
	if (state.value === 'paused') {
		if (tk.snoozedUntil && tk.snoozedUntil * 1000 > Date.now()) {
			parts.push(t('ticketsystem', 'Snoozed until {date}', { date: openingFormat.format(new Date(tk.snoozedUntil * 1000)) }))
		}
		if (tk.status === 'pending') {
			parts.push(t('ticketsystem', 'Waiting for the customer'))
		}
	}
	return parts.join(' · ')
})
const hasPendingStatus = computed(() => props.meta.statuses.some(s => s.key === 'pending'))
/** The first "done" status, used by "Send & resolve". */
const resolvedStatus = computed(() => props.meta.statuses.find(s => s.closed)?.key ?? null)
const files = ref([])
const fileInput = ref(null)
const dropping = ref(false)
const uploading = computed(() => files.value.some(f => !f.id))
const fileIds = () => files.value.filter(f => f.id).map(f => f.id)
const canSend = computed(() => (draft.value.trim() !== '' || fileIds().length > 0) && !sending.value && !uploading.value
	&& (mode.value === 'internal' || !!ticket.value?.customerEmail))
const openingFormat = new Intl.DateTimeFormat(getLanguage(), { weekday: 'long', hour: '2-digit', minute: '2-digit' })
const signature = computed(() => (teamOf(props.meta, ticket.value?.teamId)?.signature || '').trim())
const mentionable = computed(() => agentsForTeam(props.meta, ticket.value?.teamId)
	.filter(agent => agent.uid !== props.meta.currentUser))

// Other agents on this ticket right now.
const writers = computed(() => others.value.filter(o => o.state === 'typing'))
const names = (list) => list.map(o => agentName(props.meta, o.uid)).join(', ')
const viewersText = computed(() => n('ticketsystem', '{names} is also here', '{names} are also here',
	others.value.length, { names: names(others.value) }))
const writersText = computed(() => n('ticketsystem',
	'{names} is writing on this ticket right now. Check before you send, so the customer doesn\'t get two answers.',
	'{names} are writing on this ticket right now. Check before you send, so the customer doesn\'t get two answers.',
	writers.value.length, { names: names(writers.value) }))

function apply(result) {
	ticket.value = result.ticket
	messages.value = result.messages
	portalUrl.value = result.portalUrl
	version.value = result.version ?? ''
	details.value = {
		tagIds: result.tagIds ?? [],
		otherTickets: result.otherTickets ?? [],
	}
	scheduled.value = result.scheduled ?? []
}

async function load() {
	try {
		const result = await api.getTicket(props.ticketId)
		apply(result)
		const stored = loadDraft(props.ticketId)
		if (stored) {
			draft.value = stored.text
			mode.value = stored.mode
			draftRestored.value = true
		}
		if (!result.ticket.customerEmail) {
			mode.value = 'internal'
		}
	} catch (error) {
		loadError.value = error?.response?.status === 404
			? t('ticketsystem', 'Ticket not found')
			: errorMessage(error, t('ticketsystem', 'Could not load the ticket'))
	}
}

/** Reloads ticket and thread, e.g. to show the history entry a change just added. */
async function reload() {
	try {
		apply(await api.getTicket(props.ticketId))
	} catch {
		// The change itself succeeded; the page catches up on the next heartbeat.
	}
}

async function save(fields) {
	saving.value = true
	try {
		const result = await api.updateTicket(props.ticketId, fields)
		ticket.value = result.ticket
		await reload()
		emit('changed')
	} catch (error) {
		showError(errorMessage(error, t('ticketsystem', 'Could not save the change')))
	} finally {
		saving.value = false
	}
}

function snoozeCustom() {
	const when = customSnooze.value instanceof Date ? customSnooze.value : new Date(customSnooze.value)
	if (Number.isNaN(when.getTime()) || when.getTime() <= Date.now()) {
		showError(t('ticketsystem', 'Pick a time in the future'))
		return
	}
	save({ snoozedUntil: Math.floor(when.getTime() / 1000) })
	customSnooze.value = null
}

function onInsert(text) {
	draft.value = draft.value.trim() ? draft.value.trimEnd() + '\n\n' + text : text
}

async function insertMention(text, caret) {
	draft.value = text
	await nextTick()
	textareaEl.value?.focus()
	textareaEl.value?.setSelectionRange(caret, caret)
}

/** The lasting "email failed" notice, closed once the email went out after all. */
let failureToast = null

function replaceMessage(updated) {
	const index = messages.value.findIndex(m => m.id === updated.id)
	if (index !== -1) {
		messages.value[index] = updated
	}
	if (updated.deliveryStatus === 'sent'
		&& !messages.value.some(m => m.deliveryStatus === 'failed')) {
		failureToast?.hideToast()
		failureToast = null
	}
}

async function copyPortalLink() {
	try {
		await navigator.clipboard.writeText(portalUrl.value)
		showSuccess(t('ticketsystem', 'Customer link copied'))
	} catch {
		showError(t('ticketsystem', 'Could not copy the link'))
	}
}

async function send(thenStatus = null) {
	if (!canSend.value) {
		return
	}
	sending.value = true
	try {
		const result = await api.addMessage(props.ticketId, draft.value, mode.value, fileIds())
		messages.value.push(result.message)
		ticket.value = result.ticket
		draft.value = ''
		files.value = []
		draftRestored.value = false
		clearDraft(props.ticketId)
		const delivery = result.message.deliveryStatus
		if (delivery === 'sent') {
			showSuccess(t('ticketsystem', 'Reply sent to {email}', { email: ticket.value.customerEmail }))
		} else if (delivery === 'failed') {
			failureToast?.hideToast()
			failureToast = showError(t('ticketsystem', 'The reply is saved, but the email to the customer failed. You can send it again from the message.'), { timeout: -1 })
		}
		if (thenStatus && thenStatus !== ticket.value.status) {
			await save({ status: thenStatus })
		} else {
			// The server may have added history lines (e.g. automatic New → Open).
			await reload()
			emit('changed')
		}
		heartbeat()
	} catch (error) {
		showError(errorMessage(error, t('ticketsystem', 'Could not send the message')))
	} finally {
		sending.value = false
	}
}

// --- Files on the message -------------------------------------------------------

// Same limits as the server (AttachmentService), checked here to fail fast.
const MAX_FILE = 20 * 1024 * 1024
const MAX_REPLY = 20 * 1024 * 1024
let fileKey = 0

function addFiles(list) {
	for (const file of Array.from(list ?? [])) {
		const total = files.value.reduce((sum, f) => sum + f.size, 0)
		if (file.size > MAX_FILE) {
			showError(t('ticketsystem', '{name} is too large: files can be at most {size}', { name: file.name, size: formatBytes(MAX_FILE) }))
			continue
		}
		if (total + file.size > MAX_REPLY) {
			showError(t('ticketsystem', 'The files on one reply can be at most {size} together', { size: formatBytes(MAX_REPLY) }))
			break
		}
		const entry = { key: ++fileKey, id: null, name: file.name || t('ticketsystem', 'Screenshot.png'), size: file.size, progress: 0 }
		files.value.push(entry)
		const named = file.name ? file : new File([file], entry.name, { type: file.type })
		api.uploadAttachment(props.ticketId, named, (progress) => {
			const item = files.value.find(f => f.key === entry.key)
			if (item) {
				item.progress = progress
			}
		}).then((result) => {
			const index = files.value.findIndex(f => f.key === entry.key)
			if (index !== -1) {
				files.value[index] = { ...result.attachment, key: entry.key }
			}
		}).catch((error) => {
			files.value = files.value.filter(f => f.key !== entry.key)
			showError(errorMessage(error, t('ticketsystem', 'Could not upload {name}', { name: entry.name })))
		})
	}
}

function removeFile(file) {
	// The server deletes uploads that are never sent.
	files.value = files.value.filter(f => f.key !== file.key)
}

function onFilesChosen(event) {
	addFiles(event.target.files)
	event.target.value = ''
}

function onPaste(event) {
	const pasted = Array.from(event.clipboardData?.files ?? [])
	if (pasted.length) {
		event.preventDefault()
		addFiles(pasted)
	}
}

function onDragOver(event) {
	if (event.dataTransfer?.types?.includes('Files')) {
		dropping.value = true
	}
}

function onDragLeave(event) {
	if (!event.currentTarget.contains(event.relatedTarget)) {
		dropping.value = false
	}
}

function onDrop(event) {
	dropping.value = false
	addFiles(event.dataTransfer?.files)
}

// --- Send later ---------------------------------------------------------------

async function confirmSchedule({ sendAt, thenStatus }) {
	scheduleBusy.value = true
	try {
		const reply = scheduleDialog.value?.reply
		if (reply) {
			scheduled.value = (await api.updateScheduled(props.ticketId, reply.id, { sendAt })).scheduled
			showSuccess(t('ticketsystem', 'The reply will be sent {when}', { when: scheduleFormat.format(new Date(sendAt * 1000)) }))
		} else {
			if (!canSend.value) {
				return
			}
			scheduled.value = (await api.scheduleReply(props.ticketId, draft.value, sendAt, thenStatus, fileIds())).scheduled
			draft.value = ''
			files.value = []
			draftRestored.value = false
			clearDraft(props.ticketId)
			showSuccess(t('ticketsystem', 'Reply scheduled for {when}', { when: scheduleFormat.format(new Date(sendAt * 1000)) }))
			emit('changed')
		}
		scheduleDialog.value = null
	} catch (error) {
		showError(errorMessage(error, t('ticketsystem', 'Could not schedule the reply')))
	} finally {
		scheduleBusy.value = false
	}
}
const scheduleFormat = new Intl.DateTimeFormat(getLanguage(), { weekday: 'short', day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' })

async function sendScheduledNow(reply) {
	scheduleBusy.value = true
	try {
		const result = await api.sendScheduledNow(props.ticketId, reply.id)
		scheduled.value = result.scheduled
		showSuccess(t('ticketsystem', 'Reply sent to {email}', { email: ticket.value.customerEmail }))
		await reload()
		emit('changed')
	} catch (error) {
		showError(errorMessage(error, t('ticketsystem', 'Could not send the reply')))
		await reload()
	} finally {
		scheduleBusy.value = false
	}
}

async function cancelScheduled(reply) {
	scheduleBusy.value = true
	try {
		scheduled.value = (await api.cancelScheduled(props.ticketId, reply.id)).scheduled
		showSuccess(t('ticketsystem', 'Scheduled reply cancelled'))
		emit('changed')
	} catch (error) {
		showError(errorMessage(error, t('ticketsystem', 'Could not cancel the reply')))
	} finally {
		scheduleBusy.value = false
	}
}

/** Moves a scheduled reply back into the composer to change its text. */
async function editScheduled(reply) {
	if (draft.value.trim() && !window.confirm(t('ticketsystem', 'Replace the text you are writing with the scheduled reply?'))) {
		return
	}
	await cancelScheduled(reply)
	draft.value = reply.body
	mode.value = 'public'
	await nextTick()
	textareaEl.value?.focus()
}

// Drafts survive switching tickets and reloads.
let draftTimer
watch([draft, mode], () => {
	clearTimeout(draftTimer)
	draftTimer = setTimeout(() => saveDraft(props.ticketId, draft.value, mode.value), 400)
})

// The text area inside NcTextArea, for @mention suggestions.
watch(composer, async (form) => {
	await nextTick()
	textareaEl.value = form?.querySelector('textarea') ?? null
})

// Presence heartbeat: tells colleagues we're here (or writing), and notices
// changes others made, such as a new customer reply.
const HEARTBEAT_EVERY = 15 * 1000
const TYPING_FOR = 12 * 1000
let heartbeatTimer
let lastTyped = 0
let sentState = ''
const currentState = () => (draft.value.trim() && Date.now() - lastTyped < TYPING_FOR ? 'typing' : 'viewing')

async function heartbeat() {
	if (!ticket.value || document.visibilityState !== 'visible') {
		return
	}
	sentState = currentState()
	try {
		const result = await api.presence(props.ticketId, sentState)
		others.value = result.others
		if (result.version !== version.value && !saving.value && !sending.value) {
			await reload()
			emit('changed')
		}
	} catch {
		// Presence is a nicety; the next heartbeat tries again.
	}
}

function onTyping() {
	lastTyped = Date.now()
	if (sentState !== 'typing' && currentState() === 'typing') {
		heartbeat()
	}
}

function focusComposer(newMode) {
	if ((newMode === 'public' && !ticket.value?.customerEmail)) {
		return
	}
	mode.value = newMode
	textareaEl.value?.focus()
}

function onKeydown(event) {
	const target = event.target
	if (event.ctrlKey || event.metaKey || event.altKey || event.defaultPrevented
		|| (target instanceof HTMLElement && (target.isContentEditable || ['INPUT', 'TEXTAREA', 'SELECT'].includes(target.tagName)))
		|| document.querySelector('.modal-mask, [role="dialog"]')) {
		return
	}
	if (event.key === 'r' || event.key === 'n') {
		event.preventDefault()
		focusComposer(event.key === 'r' ? 'public' : 'internal')
	} else if (event.key === 'a' && ticket.value) {
		event.preventDefault()
		assignToMe()
	}
}

async function assignToMe() {
	if (ticket.value.assigneeUid === props.meta.currentUser) {
		showSuccess(t('ticketsystem', '{ref} is already assigned to you', { ref: ticket.value.reference }))
		return
	}
	await save({ assigneeUid: props.meta.currentUser })
	if (ticket.value.assigneeUid === props.meta.currentUser) {
		showSuccess(t('ticketsystem', '{ref} assigned to {name}', { ref: ticket.value.reference, name: t('ticketsystem', 'you') }))
	}
}

onMounted(async () => {
	document.addEventListener('keydown', onKeydown)
	await load()
	heartbeat()
	heartbeatTimer = setInterval(() => {
		heartbeat()
	}, HEARTBEAT_EVERY)
})

onBeforeUnmount(() => {
	document.removeEventListener('keydown', onKeydown)
	clearInterval(heartbeatTimer)
	clearTimeout(draftTimer)
	failureToast?.hideToast()
	saveDraft(props.ticketId, draft.value, mode.value)
	if (ticket.value) {
		api.presence(props.ticketId, 'left').catch(() => {})
	}
})
</script>

<style scoped lang="scss">
.ticket {
	container-type: inline-size;
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 4);
	max-width: 1240px;
	margin: 0 auto;
	padding: calc(var(--default-grid-baseline) * 4) calc(var(--default-grid-baseline) * 4) calc(var(--default-grid-baseline) * 8);

	&__loading {
		margin-top: 30vh;
	}

	&__header {
		display: flex;
		flex-wrap: wrap;
		align-items: center;
		justify-content: space-between;
		gap: 8px 16px;
	}

	&__header-actions {
		display: flex;
		flex-wrap: wrap;
		align-items: center;
		gap: 8px;
	}

	&__title {
		display: flex;
		align-items: baseline;
		gap: 8px;
		min-width: 0;
		// Leave room for the app content's back/details toggle on mobile.
		padding-inline-start: var(--app-navigation-padding, 0);

		h2 {
			margin: 0;
			font-size: 1.4em;
			overflow-wrap: anywhere;
		}
	}

	&__reference {
		color: var(--color-text-maxcontrast);
		font-weight: bold;
	}

	&__viewers {
		display: flex;
		align-items: center;
		gap: 4px;
		padding: 2px 10px 2px 4px;
		border-radius: var(--border-radius-pill);
		background: var(--color-background-dark);
		font-size: var(--font-size-small, 13px);
	}

	&__state {
		// Same colours as the ticket list's edges (TicketList.vue).
		--hd-paused: #7b8794;
		display: flex;
		flex-wrap: wrap;
		align-items: center;
		gap: 6px 10px;
		padding: 10px 14px;
		border-radius: var(--border-radius-large);
		border-inline-start: 4px solid;

		&--paused {
			border-color: var(--hd-paused);
			background: color-mix(in srgb, var(--hd-paused) 10%, var(--color-main-background));
		}

		a {
			margin-inline-start: auto;
			font-weight: bold;
			text-decoration: underline;
		}
	}

	&__body {
		display: grid;
		grid-template-columns: minmax(0, 1fr) 290px;
		gap: calc(var(--default-grid-baseline) * 5);
		align-items: start;
	}

	&__side {
		grid-column: 2;
		grid-row: 1;
		position: sticky;
		top: 8px;
		max-height: calc(100vh - 80px);
		overflow-y: auto;
	}

	&__main {
		grid-column: 1;
		grid-row: 1;
		display: flex;
		flex-direction: column;
		gap: calc(var(--default-grid-baseline) * 4);
		min-width: 0;
	}

	// Narrow: ticket fields on top, conversation next, customer details below it.
	@container (max-width: 760px) {
		&__body {
			display: flex;
			flex-direction: column;
			align-items: stretch;
		}

		&__side {
			display: contents !important;
		}

		&__side :deep(> .side-card) {
			order: 2;
		}

		&__side :deep(> .side-card--props) {
			order: 0;
			display: grid;
			// NcSelect does not get narrower than about 260px.
			grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
			align-items: start;
		}

		&__side :deep(> .side-card--props > *) {
			min-width: 0;
		}

		&__side :deep(> .side-card--props > .side-card__wide) {
			grid-column: 1 / -1;
		}

		&__main {
			order: 1;
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

	&__collision {
		margin: 0 !important;
	}

	&__spacer {
		flex: 1;
	}

	&__draft-note {
		align-self: center;
		color: var(--color-text-maxcontrast);
		font-size: var(--font-size-small, 13px);
	}

	&__textarea {
		position: relative;
	}

	&__files {
		display: flex;
		flex-direction: column;
		gap: 4px;

		.attachments {
			margin: 0;
		}
	}

	&__uploading {
		display: flex;
		align-items: center;
		gap: 6px;
		margin: 0;
		color: var(--color-text-maxcontrast);
		font-size: var(--font-size-small, 13px);
	}

	&__copies {
		margin: 0;
		color: var(--color-text-maxcontrast);
		font-size: var(--font-size-small, 13px);
		overflow-wrap: anywhere;
	}

	&__signature {
		margin: 0;
		padding: 6px 10px;
		border-inline-start: 3px solid var(--color-border-dark);
		color: var(--color-text-maxcontrast);
		font-size: var(--font-size-small, 13px);
		white-space: pre-wrap;

		span {
			display: block;
			font-weight: 600;
		}
	}

	&__composer {
		display: flex;
		flex-direction: column;
		gap: calc(var(--default-grid-baseline) * 2);
		padding: calc(var(--default-grid-baseline) * 3);
		border: 2px solid var(--color-border);
		border-radius: var(--border-radius-large);

		&--dropping {
			border-style: dashed;
			border-color: var(--color-primary-element);
		}

		&--internal {
			border-color: var(--color-warning);
			background: color-mix(in srgb, var(--color-warning) 8%, transparent);
		}

		:deep(textarea) {
			min-height: 120px;
		}
	}

	&__modes,
	&__actions {
		display: flex;
		flex-wrap: wrap;
		gap: calc(var(--default-grid-baseline) * 2);
	}

	&__actions {
		justify-content: flex-end;
	}
}
</style>
