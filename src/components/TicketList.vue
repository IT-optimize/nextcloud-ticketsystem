<!--
  - SPDX-FileCopyrightText: 2026 Co-Optimize
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="ticket-list">
		<div class="ticket-list__header">
			<h2 :title="title">
				{{ title }}<span v-if="filterDirty" class="ticket-list__modified">{{ t('ticketsystem', '(changed)') }}</span>
			</h2>
			<span v-if="!loading || tickets.length" class="ticket-list__total">{{ n('ticketsystem', '%n ticket', '%n tickets', total) }}</span>
			<NcPopover :shown="showFilters" popup-role="dialog" @update:shown="v => showFilters = v">
				<template #trigger>
					<NcButton variant="tertiary"
						:aria-label="t('ticketsystem', 'Filter and sort')"
						:title="t('ticketsystem', 'Filter and sort')"
						:pressed="showFilters">
						<template #icon>
							<FilterIcon :size="20" />
						</template>
					</NcButton>
				</template>
				<FilterPanel :model-value="filter" :meta="meta" @update:model-value="f => $emit('update:filter', f)" />
			</NcPopover>
		</div>

		<div v-if="chips.length || filterDirty" class="ticket-list__chips">
			<button v-for="chip in chips"
				:key="chip.id"
				type="button"
				class="chip"
				:title="t('ticketsystem', 'Remove this filter')"
				@click="$emit('update:filter', { ...filter, ...chip.reset })">
				<TagChip v-if="chip.tag" :tag="chip.tag" small />
				<span v-else>{{ chip.label }}</span>
				<CloseIcon :size="14" />
			</button>
			<template v-if="filterDirty">
				<NcButton variant="tertiary" size="small" @click="$emit('reset-filter')">
					{{ t('ticketsystem', 'Reset') }}
				</NcButton>
			</template>
		</div>


		<NcLoadingIcon v-if="loading && tickets.length === 0" class="ticket-list__loading" />
		<NcEmptyContent v-else-if="tickets.length === 0"
			:name="t('ticketsystem', 'No tickets here')"
			:description="filterDirty || chips.length ? t('ticketsystem', 'Nothing matches these filters.') : t('ticketsystem', 'Nothing matches this view right now.')">
			<template #icon>
				<TicketIcon />
			</template>
		</NcEmptyContent>
		<ul v-else :aria-label="t('ticketsystem', 'Tickets')" class="ticket-list__items">
			<li v-for="ticket in tickets" :key="ticket.id" class="item">
				<router-link :to="{ name: 'ticket', params: { id: ticket.id }, query: $route.query }"
					class="row"
					:class="[{ 'row--active': ticket.id === selectedId, 'row--waiting': ticket.needsReply }, state(ticket) ? 'row--' + state(ticket) : '']"
					:aria-current="ticket.id === selectedId ? 'page' : undefined">
					<div class="row__line">
						<span v-if="ticket.needsReply"
							class="row__dot"
							:title="t('ticketsystem', 'The customer is waiting for an answer')" />
						<span class="row__customer">{{ ticket.customerName || ticket.customerEmail || t('ticketsystem', 'No customer') }}</span>
						<NcDateTime class="row__time" :timestamp="ticket.updatedAt * 1000" :relative-time="'narrow'" />
					</div>
					<div class="row__subject">
						<span class="row__ref">{{ ticket.reference }}</span>
						{{ ticket.subject }}
					</div>
					<div v-if="ticket.lastMessage" class="row__preview">
						<LockIcon v-if="ticket.lastMessage.visibility === 'internal'"
							:size="14"
							class="row__note-icon"
							:title="t('ticketsystem', 'Internal note')" />
						<strong>{{ previewAuthor(ticket, meta) }}:</strong>
						{{ ticket.lastMessage.excerpt }}
					</div>
					<div class="row__tags">
						<span v-if="meta.teams.length > 1 && teamOf(meta, ticket.teamId)"
							class="row__brand"
							:title="t('ticketsystem', 'Brand: {name}', { name: teamOf(meta, ticket.teamId).name })">
							<span class="row__brand-dot" :style="{ background: teamOf(meta, ticket.teamId).color || 'var(--color-text-maxcontrast)' }" />
							{{ teamOf(meta, ticket.teamId).name }}
						</span>
						<span v-if="ticket.needsReply" class="row__tag row__tag--waiting">
							{{ t('ticketsystem', 'Needs reply') }}
						</span>
						<span v-if="isSnoozed(ticket)" class="row__tag" :title="t('ticketsystem', 'Snoozed')">
							<AlarmSnoozeIcon :size="14" />
						</span>
						<span v-if="ticket.scheduledAt"
							class="row__tag row__tag--scheduled"
							:title="t('ticketsystem', 'Reply scheduled for {date}', { date: dueDate(ticket.scheduledAt) })">
							<SendClockIcon :size="14" />
							{{ t('ticketsystem', 'Scheduled') }}
						</span>
						<span class="row__tag" :class="{ 'row__tag--done': isClosedStatus(meta, ticket.status) }">
							{{ optionLabel(meta?.statuses, ticket.status) }}
						</span>
						<span v-if="ticket.priority === 'high' || ticket.priority === 'urgent'"
							class="row__tag"
							:class="`row__tag--${ticket.priority}`">
							{{ optionLabel(meta?.priorities, ticket.priority) }}
						</span>
						<TagChip v-for="tagId in ticket.tagIds.slice(0, 3)"
							:key="tagId"
							:tag="tagOf(meta, tagId) ?? { name: '?', color: null }"
							small />
						<span v-if="ticket.tagIds.length > 3" class="row__more">+{{ ticket.tagIds.length - 3 }}</span>
						<span class="row__spacer" />
						<!-- Room for the assign button, which sits outside the link. -->
					</div>
				</router-link>
				<NcActions class="item__assign"
					:class="{ 'item__assign--empty': !ticket.assigneeUid, 'item__assign--mine': ticket.assigneeUid === meta.currentUser }"
					:aria-label="ticket.assigneeUid
						? t('ticketsystem', 'Assigned to {name}. Change assignee', { name: agentName(meta, ticket.assigneeUid) })
						: t('ticketsystem', 'Unassigned. Assign this ticket')"
					:title="ticket.assigneeUid === meta.currentUser
						? t('ticketsystem', 'Assigned to you')
						: ticket.assigneeUid ? t('ticketsystem', 'Assigned to {name}', { name: agentName(meta, ticket.assigneeUid) }) : t('ticketsystem', 'Assign')"
					:disabled="assigning === ticket.id"
					variant="tertiary-no-background"
					:menu-name="''">
					<template #icon>
						<NcLoadingIcon v-if="assigning === ticket.id" :size="20" />
						<NcAvatar v-else-if="ticket.assigneeUid"
							:user="ticket.assigneeUid"
							:display-name="agentName(meta, ticket.assigneeUid)"
							:size="22"
							hide-status
							disable-menu
							disable-tooltip />
						<AccountPlusIcon v-else :size="18" />
					</template>
					<NcActionButton v-if="ticket.assigneeUid !== meta.currentUser" close-after-click @click="assign(ticket, meta.currentUser)">
						<template #icon>
							<AccountIcon :size="20" />
						</template>
						{{ t('ticketsystem', 'Assign to me') }}
					</NcActionButton>
					<NcActionCaption v-if="assignableOthers(ticket).length" :name="t('ticketsystem', 'Agents')" />
					<NcActionButton v-for="agent in assignableOthers(ticket)"
						:key="agent.uid"
						close-after-click
						@click="assign(ticket, agent.uid)">
						<template #icon>
							<NcAvatar :user="agent.uid"
								:display-name="agent.displayName"
								:size="20"
								hide-status
								disable-menu
								disable-tooltip />
						</template>
						{{ agent.displayName }}
					</NcActionButton>
					<NcActionSeparator v-if="ticket.assigneeUid" />
					<NcActionButton v-if="ticket.assigneeUid" close-after-click @click="assign(ticket, null)">
						<template #icon>
							<AccountRemoveIcon :size="20" />
						</template>
						{{ t('ticketsystem', 'Unassign') }}
					</NcActionButton>
				</NcActions>
			</li>
		</ul>
	</div>
</template>

<script setup>
import { computed, ref } from 'vue'
import { n, t } from '@nextcloud/l10n'
import { showError, showSuccess } from '@nextcloud/dialogs'
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import NcActionCaption from '@nextcloud/vue/components/NcActionCaption'
import NcActions from '@nextcloud/vue/components/NcActions'
import NcActionSeparator from '@nextcloud/vue/components/NcActionSeparator'
import NcAvatar from '@nextcloud/vue/components/NcAvatar'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDateTime from '@nextcloud/vue/components/NcDateTime'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcPopover from '@nextcloud/vue/components/NcPopover'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import AccountIcon from 'vue-material-design-icons/Account.vue'
import SendClockIcon from 'vue-material-design-icons/SendClockOutline.vue'
import AccountPlusIcon from 'vue-material-design-icons/AccountPlusOutline.vue'
import AccountRemoveIcon from 'vue-material-design-icons/AccountRemoveOutline.vue'
import AlarmSnoozeIcon from 'vue-material-design-icons/AlarmSnooze.vue'
import CloseIcon from 'vue-material-design-icons/Close.vue'
import FilterIcon from 'vue-material-design-icons/FilterVariant.vue'
import LockIcon from 'vue-material-design-icons/Lock.vue'
import TicketIcon from 'vue-material-design-icons/TicketOutline.vue'
import FilterPanel from './FilterPanel.vue'
import TagChip from './TagChip.vue'
import { api, errorMessage } from '../api.js'
import { getLanguage } from '@nextcloud/l10n'
import { agentName, agentsForTeam, filterChips, isClosedStatus, optionLabel, previewAuthor, tagOf, teamOf, ticketState } from '../format.js'

const props = defineProps({
	tickets: { type: Array, required: true },
	loading: { type: Boolean, default: false },
	selectedId: { type: Number, default: null },
	title: { type: String, default: '' },
	total: { type: Number, default: 0 },
	meta: { type: Object, required: true },
	/** The filter the list shows now. */
	filter: { type: Object, required: true },
	/** Whether the filter differs from the view's own. */
	filterDirty: { type: Boolean, default: false },
	/** The view's own filter: chips show only what differs from it. */
	viewFilter: { type: Object, default: null },
})
const emit = defineEmits(['update:filter', 'reset-filter', 'changed'])

const showFilters = ref(false)
const chips = computed(() => filterChips(props.filter, props.meta, props.viewFilter ?? undefined))

const dueFormat = new Intl.DateTimeFormat(getLanguage(), { weekday: 'short', day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' })
const dueDate = (ts) => dueFormat.format(new Date(ts * 1000))
const state = (ticket) => ticketState(ticket, props.meta)
const isSnoozed = (ticket) => ticket.snoozedUntil && ticket.snoozedUntil * 1000 > Date.now()

const assigning = ref(null)

/** Agents of the ticket's brand, other than me and the current assignee. */
const assignableOthers = (ticket) => agentsForTeam(props.meta, ticket.teamId)
	.filter(agent => agent.uid !== props.meta.currentUser && agent.uid !== ticket.assigneeUid)

/** Assigns one ticket straight from the list. */
async function assign(ticket, uid) {
	assigning.value = ticket.id
	const previous = ticket.assigneeUid
	ticket.assigneeUid = uid
	try {
		await api.updateTicket(ticket.id, { assigneeUid: uid })
		showSuccess(uid
			? t('ticketsystem', '{ref} assigned to {name}', { ref: ticket.reference, name: uid === props.meta.currentUser ? t('ticketsystem', 'you') : agentName(props.meta, uid) })
			: t('ticketsystem', '{ref} is unassigned', { ref: ticket.reference }))
		emit('changed')
	} catch (error) {
		ticket.assigneeUid = previous
		showError(errorMessage(error, t('ticketsystem', 'Could not assign the ticket')))
	} finally {
		assigning.value = null
	}
}

</script>

<style scoped lang="scss">
.ticket-list {
	padding: calc(var(--default-grid-baseline) * 2);

	&__loading {
		margin-top: 30vh;
	}

	&__header {
		display: flex;
		align-items: center;
		gap: 8px;
		min-height: var(--default-clickable-area);
		// Room for the navigation toggle that sits in the top corner.
		padding-inline: calc(var(--default-clickable-area) + 4px) 0;
		margin-bottom: 4px;

		h2 {
			flex: 1;
			min-width: 0;
			margin: 0;
			font-size: 1.15em;
			line-height: var(--default-clickable-area);
			overflow: hidden;
			text-overflow: ellipsis;
			white-space: nowrap;
		}
	}

	&__modified {
		margin-inline-start: 6px;
		color: var(--color-text-maxcontrast);
		font-size: var(--font-size-small, 13px);
		font-weight: normal;
	}

	&__total {
		color: var(--color-text-maxcontrast);
		white-space: nowrap;
	}

	&__chips {
		display: flex;
		flex-wrap: wrap;
		align-items: center;
		gap: 6px;
		padding: 0 6px 8px;
	}

	&__items {
		display: flex;
		flex-direction: column;
		gap: 2px;
	}
}

.chip {
	display: inline-flex;
	align-items: center;
	gap: 4px;
	min-height: 28px;
	margin: 0;
	padding: 0 6px 0 10px;
	border: 1px solid var(--color-border-dark);
	border-radius: var(--border-radius-pill);
	background: var(--color-main-background);
	color: var(--color-main-text);
	font-size: var(--font-size-small, 13px);
	cursor: pointer;

	&:hover,
	&:focus-visible {
		background: var(--color-background-hover);
	}
}

.item {
	position: relative;

	// Doubled up on purpose: in a production build other apps' copies of
	// @nextcloud/vue share the button's scoped id, and their later stylesheet
	// would otherwise put the button back to position: relative.
	& &__assign {
		position: absolute;
		z-index: 1;
		inset-inline-end: 6px;
		bottom: 4px;

		&--empty :deep(button) {
			color: var(--color-text-maxcontrast);
			font-size: var(--font-size-small, 13px);
			font-weight: normal;
		}

		// Your own tickets get a ring in the primary color, so they stand out at a glance.
		&--mine :deep(.avatardiv) {
			box-shadow: 0 0 0 2px var(--color-main-background), 0 0 0 4px var(--color-primary-element);
		}
	}
}

.row {
	// Paused tickets get a coloured edge (see ticketState in format.js).
	--hd-paused: #7b8794;
	display: flex;
	flex-direction: column;
	gap: 2px;
	padding: 8px 10px 8px 28px;
	border-radius: var(--border-radius-large);
	color: var(--color-main-text);
	position: relative;

	&--paused {
		box-shadow: inset 4px 0 0 var(--hd-paused);
	}

	&:hover {
		background: var(--color-background-hover);
	}

	&:focus-visible {
		outline: 2px solid var(--color-main-text);
		outline-offset: -2px;
	}

	&--active,
	&--active:hover {
		background: var(--color-primary-element-light);
		color: var(--color-primary-element-light-text);
	}

	&__line,
	&__tags {
		display: flex;
		align-items: center;
		gap: 6px;
		min-width: 0;
	}

	&__tags {
		flex-wrap: wrap;
		margin-top: 4px;
		// Room for the assign button, which sits over the row's bottom-right corner.
		min-height: 24px;
		padding-inline-end: 34px;
	}

	&__dot {
		position: absolute;
		inset-inline-start: 10px;
		top: 15px;
		width: 8px;
		height: 8px;
		border-radius: 50%;
		background: var(--color-primary-element);
	}

	&__customer {
		flex: 1;
		min-width: 0;
		overflow: hidden;
		text-overflow: ellipsis;
		white-space: nowrap;
		font-weight: bold;
	}

	&__time {
		flex-shrink: 0;
		color: var(--color-text-maxcontrast);
		font-size: var(--font-size-small, 13px);
	}

	&__subject,
	&__preview {
		overflow: hidden;
		text-overflow: ellipsis;
		white-space: nowrap;
	}

	&--waiting &__subject {
		font-weight: bold;
	}

	&__ref {
		color: var(--color-text-maxcontrast);
	}

	&__preview {
		color: var(--color-text-maxcontrast);
		font-size: var(--font-size-small, 13px);

		strong {
			font-weight: 600;
		}
	}

	&__note-icon {
		display: inline-flex;
		vertical-align: text-bottom;
		color: var(--color-warning-text, var(--color-warning));
	}

	&__brand {
		display: inline-flex;
		align-items: center;
		gap: 4px;
		max-width: 120px;
		overflow: hidden;
		white-space: nowrap;
		text-overflow: ellipsis;
		color: var(--color-text-maxcontrast);
		font-size: var(--font-size-small, 13px);
	}

	&__brand-dot {
		flex-shrink: 0;
		width: 8px;
		height: 8px;
		border-radius: 2px;
	}

	&__tag {
		display: inline-flex;
		align-items: center;
		font-size: var(--font-size-small, 13px);
		line-height: 20px;
		padding: 0 7px;
		border-radius: var(--border-radius-pill);
		background: var(--color-background-dark);
		color: var(--color-main-text);
		white-space: nowrap;

		&--waiting {
			background: var(--color-primary-element);
			color: var(--color-primary-element-text);
		}

		&--done {
			color: var(--color-text-maxcontrast);
		}

		&--scheduled {
			gap: 3px;
			background: var(--color-primary-element-light);
			color: var(--color-primary-element-light-text, var(--color-main-text));
		}

		&--high {
			background: var(--color-warning);
			color: var(--color-warning-text, #000);
		}

		&--urgent {
			background: var(--color-error);
			color: var(--color-error-text, #fff);
		}
	}

	&__more {
		color: var(--color-text-maxcontrast);
		font-size: var(--font-size-small, 13px);
	}

	&__spacer {
		flex: 1;
	}


}
</style>
