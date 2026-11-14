<!--
  - SPDX-FileCopyrightText: 2026 Co-Optimize
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<aside class="sidebar" :aria-label="t('ticketsystem', 'Ticket details')">
		<section class="side-card side-card--props">
			<NcSelect :model-value="statusOption"
				:options="meta.statuses"
				label="label"
				:input-label="t('ticketsystem', 'Status')"
				:clearable="false"
				:disabled="saving"
				@update:model-value="o => o && $emit('save', { status: o.key })" />
			<NcSelect :model-value="priorityOption"
				:options="meta.priorities"
				label="label"
				:input-label="t('ticketsystem', 'Priority')"
				:clearable="false"
				:disabled="saving"
				@update:model-value="o => o && $emit('save', { priority: o.key })" />
			<div>
				<NcSelect :model-value="assigneeOption"
					:options="teamAgents"
					label="displayName"
					:input-label="t('ticketsystem', 'Assignee')"
					:placeholder="t('ticketsystem', 'Unassigned')"
					:disabled="saving"
					@update:model-value="o => $emit('save', { assigneeUid: o ? o.uid : null })" />
				<NcButton v-if="ticket.assigneeUid !== meta.currentUser"
					variant="tertiary"
					size="small"
					:disabled="saving"
					@click="$emit('save', { assigneeUid: meta.currentUser })">
					{{ t('ticketsystem', 'Assign to me') }}
				</NcButton>
			</div>
			<NcSelect v-if="meta.teams.length > 1"
				:model-value="teamOf(meta, ticket.teamId)"
				:options="meta.teams"
				label="name"
				:input-label="t('ticketsystem', 'Brand')"
				:clearable="false"
				:disabled="saving"
				@update:model-value="o => o && o.id !== ticket.teamId && $emit('save', { teamId: o.id })" />
			<TagPicker class="side-card__wide"
				:model-value="details.tagIds"
				:tags="meta.tags"
				:label="t('ticketsystem', 'Tags')"
				:placeholder="t('ticketsystem', 'Add tags')"
				:disabled="saving"
				@created="tag => $emit('tag-created', tag)"
				@update:model-value="ids => $emit('save', { tagIds: ids })" />
			<p v-if="snoozed" class="side-card__snooze side-card__wide">
				<AlarmSnoozeIcon :size="16" />
				<span>{{ t('ticketsystem', 'Snoozed until {date}', { date: formatDate(ticket.snoozedUntil) }) }}</span>
				<NcButton variant="tertiary" size="small" :disabled="saving" @click="$emit('save', { snoozedUntil: null })">
					{{ t('ticketsystem', 'Wake up now') }}
				</NcButton>
			</p>
		</section>

		<TimeCard :ticket="ticket" :meta="meta" />

		<section class="side-card">
			<h3>{{ t('ticketsystem', 'Customer') }}</h3>
			<template v-if="ticket.customerEmail">
				<p v-if="ticket.customerName" class="side-card__strong">
					{{ ticket.customerName }}
				</p>
				<a class="side-card__email" :href="'mailto:' + ticket.customerEmail">{{ ticket.customerEmail }}</a>
				<div class="side-card__cc">
					<span class="side-card__label">{{ t('ticketsystem', 'Copied on replies (CC)') }}</span>
					<ul v-if="cc.length" class="side-card__cc-list">
						<li v-for="address in cc" :key="address">
							<span class="side-card__email">{{ address }}</span>
							<NcButton
								variant="tertiary"
								size="small"
								:disabled="saving"
								:aria-label="t('ticketsystem', 'Stop copying {email}', { email: address })"
								:title="t('ticketsystem', 'Stop copying {email}', { email: address })"
								@click="$emit('save', { cc: cc.filter(a => a !== address) })">
								<template #icon>
									<CloseIcon :size="16" />
								</template>
							</NcButton>
						</li>
					</ul>
					<div v-if="cc.length < maxCc" class="side-card__cc-add">
						<NcTextField v-model="ccDraft"
							type="email"
							:label="t('ticketsystem', 'Add an email address')"
							:disabled="saving"
							@keydown.enter.prevent="addCc" />
						<NcButton variant="tertiary"
							:disabled="saving || ccDraft.trim() === ''"
							:aria-label="t('ticketsystem', 'Add to CC')"
							:title="t('ticketsystem', 'Add to CC')"
							@click="addCc">
							<template #icon>
								<PlusIcon :size="18" />
							</template>
						</NcButton>
					</div>
				</div>
			</template>
			<p v-else class="side-card__muted">
				{{ t('ticketsystem', 'No customer email: only internal notes are possible') }}
			</p>
			<NcSelect :model-value="companyOption"
				:options="meta.companies"
				label="name"
				:input-label="t('ticketsystem', 'Company')"
				:placeholder="t('ticketsystem', 'No company')"
				:disabled="saving"
				@update:model-value="o => $emit('save', { companyId: o ? o.id : null })" />
			<NcButton v-if="portalUrl"
				variant="tertiary"
				size="small"
				:title="t('ticketsystem', 'The private page where the customer follows this ticket')"
				@click="$emit('copy-portal-link')">
				<template #icon>
					<LinkIcon :size="16" />
				</template>
				{{ t('ticketsystem', 'Copy customer link') }}
			</NcButton>
		</section>

		<section v-if="ticket.customerEmail" class="side-card">
			<h3>{{ t('ticketsystem', 'Other requests from this customer') }}</h3>
			<p v-if="details.otherTickets.length === 0" class="side-card__muted">
				{{ t('ticketsystem', 'This is the first request from this customer.') }}
			</p>
			<ul v-else class="side-card__others">
				<li v-for="other in details.otherTickets" :key="other.id">
					<router-link :to="{ name: 'ticket', params: { id: other.id }, query: $route.query }">
						<span class="side-card__other-subject">{{ other.reference }} {{ other.subject }}</span>
						<span class="side-card__other-meta">
							{{ optionLabel(meta.statuses, other.status) }} ·
							<NcDateTime :timestamp="other.createdAt * 1000" :relative-time="'narrow'" />
						</span>
					</router-link>
				</li>
			</ul>
		</section>
	</aside>
</template>

<script setup>
import { computed, ref } from 'vue'
import { getLanguage, t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDateTime from '@nextcloud/vue/components/NcDateTime'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import AlarmSnoozeIcon from 'vue-material-design-icons/AlarmSnooze.vue'
import CloseIcon from 'vue-material-design-icons/Close.vue'
import LinkIcon from 'vue-material-design-icons/LinkVariant.vue'
import PlusIcon from 'vue-material-design-icons/Plus.vue'
import TagPicker from './TagPicker.vue'
import TimeCard from './TimeCard.vue'
import { agentsForTeam, optionLabel, teamOf } from '../format.js'

const props = defineProps({
	ticket: { type: Object, required: true },
	details: { type: Object, required: true },
	meta: { type: Object, required: true },
	portalUrl: { type: String, default: null },
	saving: { type: Boolean, default: false },
})
// save: (fields) — a partial update for the ticket
const emit = defineEmits(['save', 'copy-portal-link', 'tag-created'])

const statusOption = computed(() => props.meta.statuses.find(s => s.key === props.ticket.status) ?? null)
const priorityOption = computed(() => props.meta.priorities.find(p => p.key === props.ticket.priority) ?? null)
const teamAgents = computed(() => agentsForTeam(props.meta, props.ticket.teamId))
const assigneeOption = computed(() => {
	const uid = props.ticket.assigneeUid
	if (!uid) {
		return null
	}
	return props.meta.agents.find(a => a.uid === uid) ?? { uid, displayName: uid }
})
const companyOption = computed(() => props.meta.companies?.find(c => c.id === props.ticket.companyId) ?? null)
const snoozed = computed(() => props.ticket.snoozedUntil && props.ticket.snoozedUntil * 1000 > Date.now())

// The server checks the addresses and keeps at most this many.
const maxCc = 10
const cc = computed(() => props.ticket.cc ?? [])
const ccDraft = ref('')
function addCc() {
	const address = ccDraft.value.trim().toLowerCase()
	if (address === '') {
		return
	}
	ccDraft.value = ''
	if (!cc.value.includes(address)) {
		emit('save', { cc: [...cc.value, address] })
	}
}

const dateFormat = new Intl.DateTimeFormat(getLanguage(), { dateStyle: 'medium', timeStyle: 'short' })
const formatDate = (ts) => dateFormat.format(new Date(ts * 1000))
</script>

<style scoped lang="scss">
.sidebar {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 3);
}

.side-card {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 2);
	padding: calc(var(--default-grid-baseline) * 3);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);

	h3 {
		margin: 0;
		font-size: 1em;
		font-weight: bold;
	}

	p {
		margin: 0;
	}

	:deep(.select) {
		width: 100%;
		min-width: 0;
	}

	&__head {
		display: flex;
		align-items: center;
		justify-content: space-between;
		min-height: 34px;
	}

	&__strong {
		font-weight: bold;
	}

	&__email {
		overflow-wrap: anywhere;
		color: var(--color-text-maxcontrast);

		&:hover {
			text-decoration: underline;
		}
	}

	&__muted {
		color: var(--color-text-maxcontrast);
		font-style: italic;
	}

	&__cc {
		display: flex;
		flex-direction: column;
		gap: 4px;
	}

	&__label {
		font-size: 0.9em;
		color: var(--color-text-maxcontrast);
	}

	&__cc-list {
		display: flex;
		flex-direction: column;

		li {
			display: flex;
			align-items: center;
			justify-content: space-between;
			gap: 4px;
			min-height: 34px;
		}
	}

	&__cc-add {
		display: flex;
		align-items: flex-end;
		gap: 4px;
	}

	&__snooze {
		display: flex;
		flex-wrap: wrap;
		align-items: center;
		gap: 6px;
		padding: 4px 8px;
		border-radius: var(--border-radius-large);
		background: var(--color-background-dark);
	}

	&__others {
		display: flex;
		flex-direction: column;
		gap: 2px;
		margin: 0 calc(var(--default-grid-baseline) * -2);

		a {
			display: flex;
			flex-direction: column;
			flex: 1;
			min-width: 0;
			padding: 4px 8px;
			border-radius: var(--border-radius);

			&:hover,
			&:focus-visible {
				background: var(--color-background-hover);
			}
		}
	}

	&__other-subject {
		overflow: hidden;
		text-overflow: ellipsis;
		white-space: nowrap;
	}

	&__other-meta {
		color: var(--color-text-maxcontrast);
		font-size: var(--font-size-small, 13px);
	}
}
</style>
