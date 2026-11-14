<!--
  - SPDX-FileCopyrightText: 2026 Co-Optimize
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<section class="side-card time" :aria-label="t('ticketsystem', 'Time spent')">
		<div class="side-card__head">
			<h3>{{ t('ticketsystem', 'Time') }}</h3>
			<span v-if="entries.length" class="time__total" :title="totalTitle">
				{{ minutesText(totalMinutes) }}<template v-if="totalAmount !== null"> · {{ money(totalAmount) }}</template>
			</span>
		</div>

		<div class="time__timer">
			<NcButton :variant="timerStartedAt ? 'primary' : 'secondary'"
				size="small"
				:aria-label="timerStartedAt ? t('ticketsystem', 'Stop the timer and fill in the time') : t('ticketsystem', 'Start a timer for this ticket')"
				@click="toggleTimer">
				<template #icon>
					<StopIcon v-if="timerStartedAt" :size="18" />
					<PlayIcon v-else :size="18" />
				</template>
				{{ timerStartedAt ? t('ticketsystem', 'Stop timer') : t('ticketsystem', 'Start timer') }}
			</NcButton>
			<span v-if="timerStartedAt" class="time__elapsed" aria-live="off">{{ elapsedText }}</span>
		</div>

		<form class="time__form" @submit.prevent="submit">
			<div class="time__row">
				<NcTextField ref="minutesField"
					v-model="durationText"
					:label="editing ? t('ticketsystem', 'Time spent') : t('ticketsystem', 'Log time')"
					:placeholder="t('ticketsystem', 'e.g. 30 or 1:15')"
					:error="durationText !== '' && parsedMinutes === null"
					:helper-text="durationText !== '' && parsedMinutes === null ? t('ticketsystem', 'Write minutes (45), hours and minutes (1:30) or 1h 30m') : ''"
					:disabled="busy" />
				<NcButton type="submit" variant="primary" :disabled="busy || parsedMinutes === null">
					{{ editing ? t('ticketsystem', 'Save') : t('ticketsystem', 'Log') }}
				</NcButton>
			</div>
			<NcTextField v-model="note"
				:label="t('ticketsystem', 'What did you do? (optional)')"
				:disabled="busy" />
			<div class="time__options">
				<NcCheckboxRadioSwitch v-model="billable" :disabled="busy">
					{{ t('ticketsystem', 'Billable') }}
				</NcCheckboxRadioSwitch>
				<NcDateTimePickerNative :id="'time-date-' + ticket.id"
					v-model="workDate"
					type="date"
					:max="new Date()"
					:label="t('ticketsystem', 'Date')"
					class="time__date" />
			</div>
			<NcButton v-if="editing" variant="tertiary" size="small" @click="resetForm">
				{{ t('ticketsystem', 'Cancel editing') }}
			</NcButton>
		</form>
		<p class="time__rate">
			<template v-if="rate !== null">{{ t('ticketsystem', '{rate} per hour', { rate: money(rate) }) }}</template>
			<template v-else>{{ t('ticketsystem', 'No hourly rate yet, so time is recorded without a price.') }}</template>
		</p>

		<NcLoadingIcon v-if="loading && entries.length === 0" />
		<ul v-else-if="entries.length" class="time__list">
			<li v-for="entry in entries" :key="entry.id" class="time__entry" :class="{ 'time__entry--editing': editing === entry.id }">
				<NcAvatar :user="entry.uid"
					:display-name="agentName(meta, entry.uid)"
					:size="24"
					hide-status
					disable-menu
					class="time__avatar" />
				<div class="time__text">
					<span class="time__line">
						<strong>{{ minutesText(entry.minutes) }}</strong>
						<span class="time__date-text">{{ dateText(entry.workDate) }}</span>
						<span v-if="!entry.billable" class="time__chip">{{ t('ticketsystem', 'Not billable') }}</span>
						<span v-if="entry.invoicedAt" class="time__chip time__chip--done">
							<LockIcon :size="12" /> {{ t('ticketsystem', 'Invoiced') }}
						</span>
					</span>
					<span v-if="entry.note" class="time__note">{{ entry.note }}</span>
				</div>
				<NcActions v-if="canEdit(entry)" :aria-label="t('ticketsystem', 'Change this time')">
					<NcActionButton close-after-click @click="startEdit(entry)">
						<template #icon>
							<PencilIcon :size="20" />
						</template>
						{{ t('ticketsystem', 'Edit') }}
					</NcActionButton>
					<NcActionButton close-after-click @click="remove(entry)">
						<template #icon>
							<DeleteIcon :size="20" />
						</template>
						{{ t('ticketsystem', 'Delete') }}
					</NcActionButton>
				</NcActions>
			</li>
		</ul>
	</section>
</template>

<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { getLanguage, t } from '@nextcloud/l10n'
import { showError, showSuccess } from '@nextcloud/dialogs'
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import NcActions from '@nextcloud/vue/components/NcActions'
import NcAvatar from '@nextcloud/vue/components/NcAvatar'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcDateTimePickerNative from '@nextcloud/vue/components/NcDateTimePickerNative'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import DeleteIcon from 'vue-material-design-icons/DeleteOutline.vue'
import LockIcon from 'vue-material-design-icons/LockOutline.vue'
import PencilIcon from 'vue-material-design-icons/PencilOutline.vue'
import PlayIcon from 'vue-material-design-icons/Play.vue'
import StopIcon from 'vue-material-design-icons/Stop.vue'
import { api, errorMessage } from '../api.js'
import { agentName } from '../format.js'
import { formatMoney, parseDuration, splitMinutes, timerMinutes } from '../time.js'

const props = defineProps({
	ticket: { type: Object, required: true },
	meta: { type: Object, required: true },
})

const entries = ref([])
const rate = ref(null)
const currency = ref('DKK')
const loading = ref(false)
const busy = ref(false)
const durationText = ref('')
const note = ref('')
const billable = ref(true)
const workDate = ref(new Date())
const editing = ref(null)
const minutesField = ref(null)

const parsedMinutes = computed(() => parseDuration(durationText.value))
const totalMinutes = computed(() => entries.value.reduce((sum, e) => sum + e.minutes, 0))
const billableMinutes = computed(() => entries.value.filter(e => e.billable).reduce((sum, e) => sum + e.minutes, 0))
const totalAmount = computed(() => {
	const priced = entries.value.filter(e => e.amount !== null)
	return priced.length ? priced.reduce((sum, e) => sum + e.amount, 0) : null
})
const totalTitle = computed(() => t('ticketsystem', '{billable} billable of {total}', { billable: minutesText(billableMinutes.value), total: minutesText(totalMinutes.value) }))

const money = (minor) => formatMoney(minor, currency.value, getLanguage())
function minutesText(minutes) {
	const { hours, minutes: rest } = splitMinutes(minutes)
	if (hours === 0) {
		return t('ticketsystem', '{m} min', { m: rest })
	}
	return rest === 0 ? t('ticketsystem', '{h} h', { h: hours }) : t('ticketsystem', '{h} h {m} min', { h: hours, m: rest })
}
const dateFormat = new Intl.DateTimeFormat(getLanguage(), { day: 'numeric', month: 'short' })
const dateText = (iso) => dateFormat.format(new Date(iso + 'T12:00:00'))
const isoDate = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
const canEdit = (entry) => !entry.invoicedAt && (entry.uid === props.meta.currentUser || props.meta.isAdmin)

function apply(result) {
	entries.value = result.entries
	rate.value = result.rate
	currency.value = result.currency
}

async function load() {
	loading.value = true
	try {
		apply(await api.ticketTime(props.ticket.id))
	} catch (error) {
		showError(errorMessage(error, t('ticketsystem', 'Could not load the time on this ticket')))
	} finally {
		loading.value = false
	}
}

function resetForm() {
	editing.value = null
	durationText.value = ''
	note.value = ''
	billable.value = true
	workDate.value = new Date()
}

function startEdit(entry) {
	editing.value = entry.id
	durationText.value = String(entry.minutes)
	note.value = entry.note ?? ''
	billable.value = entry.billable
	workDate.value = new Date(entry.workDate + 'T12:00:00')
	nextTick(() => minutesField.value?.focus?.())
}

async function submit() {
	if (parsedMinutes.value === null) {
		return
	}
	busy.value = true
	const entry = {
		minutes: parsedMinutes.value,
		note: note.value,
		billable: billable.value,
		workDate: workDate.value instanceof Date && !Number.isNaN(workDate.value.getTime()) ? isoDate(workDate.value) : null,
	}
	try {
		if (editing.value) {
			apply(await api.updateTime(props.ticket.id, editing.value, entry))
		} else {
			apply(await api.addTime(props.ticket.id, entry))
			showSuccess(t('ticketsystem', '{time} logged on {ref}', { time: minutesText(entry.minutes), ref: props.ticket.reference }))
		}
		resetForm()
	} catch (error) {
		showError(errorMessage(error, t('ticketsystem', 'Could not save the time')))
	} finally {
		busy.value = false
	}
}

async function remove(entry) {
	busy.value = true
	try {
		apply(await api.deleteTime(props.ticket.id, entry.id))
		if (editing.value === entry.id) {
			resetForm()
		}
	} catch (error) {
		showError(errorMessage(error, t('ticketsystem', 'Could not delete the time')))
	} finally {
		busy.value = false
	}
}

// --- Timer: kept in the browser per ticket, so it survives reloads and switching tickets.
const timerKey = computed(() => `ticketsystem-timer-${props.ticket.id}`)
const timerStartedAt = ref(null)
const now = ref(Date.now())
let tick = null

function readTimer() {
	try {
		const value = Number(localStorage.getItem(timerKey.value))
		timerStartedAt.value = value > 0 ? value : null
	} catch {
		timerStartedAt.value = null
	}
}

function toggleTimer() {
	if (timerStartedAt.value) {
		const minutes = timerMinutes(timerStartedAt.value)
		durationText.value = String((parsedMinutes.value ?? 0) + minutes)
		timerStartedAt.value = null
		try {
			localStorage.removeItem(timerKey.value)
		} catch {
			// The time is in the field; nothing else to keep.
		}
		nextTick(() => minutesField.value?.focus?.())
	} else {
		timerStartedAt.value = Date.now()
		try {
			localStorage.setItem(timerKey.value, String(timerStartedAt.value))
		} catch {
			// Private windows: the timer still runs while the ticket stays open.
		}
	}
}

const elapsedText = computed(() => {
	const seconds = Math.max(0, Math.floor((now.value - (timerStartedAt.value ?? now.value)) / 1000))
	const h = Math.floor(seconds / 3600)
	const m = Math.floor((seconds % 3600) / 60)
	const s = seconds % 60
	return `${h}:${String(m).padStart(2, '0')}:${String(s).padStart(2, '0')}`
})

watch(() => props.ticket.id, () => {
	resetForm()
	readTimer()
	load()
})

onMounted(() => {
	readTimer()
	load()
	tick = setInterval(() => {
		now.value = Date.now()
	}, 1000)
})
onBeforeUnmount(() => clearInterval(tick))
</script>

<style scoped lang="scss">
// The card frame comes from the sidebar; its inner parts are styled here.
.side-card__head {
	display: flex;
	align-items: center;
	justify-content: space-between;
	min-height: 34px;

	h3 {
		margin: 0;
		font-size: 1em;
		font-weight: bold;
	}
}

.time {
	p {
		margin: 0;
	}

	&__total {
		font-weight: 600;
		font-variant-numeric: tabular-nums;
	}

	&__timer {
		display: flex;
		align-items: center;
		gap: 8px;
	}

	&__elapsed {
		font-variant-numeric: tabular-nums;
		font-weight: 600;
	}

	&__form {
		display: flex;
		flex-direction: column;
		gap: 6px;
	}

	&__row {
		display: flex;
		align-items: flex-start;
		gap: 6px;

		> :first-child {
			flex: 1;
			min-width: 0;
		}

		> button {
			margin-top: 6px;
		}
	}

	&__options {
		display: flex;
		flex-wrap: wrap;
		align-items: center;
		justify-content: space-between;
		gap: 6px;
	}

	&__date {
		max-width: 150px;
	}

	&__rate {
		color: var(--color-text-maxcontrast);
		font-size: var(--font-size-small, 13px);
	}

	&__list {
		display: flex;
		flex-direction: column;
		gap: 2px;
		margin: 0 calc(var(--default-grid-baseline) * -2);
	}

	&__entry {
		display: flex;
		align-items: flex-start;
		gap: 8px;
		padding: 4px 8px;
		border-radius: var(--border-radius);

		&--editing {
			background: var(--color-primary-element-light);
		}
	}

	&__avatar {
		margin-top: 2px;
	}

	&__text {
		display: flex;
		flex-direction: column;
		flex: 1;
		min-width: 0;
	}

	&__line {
		display: flex;
		flex-wrap: wrap;
		align-items: center;
		gap: 6px;
	}

	&__date-text,
	&__note {
		color: var(--color-text-maxcontrast);
		font-size: var(--font-size-small, 13px);
	}

	&__note {
		overflow-wrap: anywhere;
	}

	&__chip {
		display: inline-flex;
		align-items: center;
		gap: 2px;
		padding: 0 6px;
		border-radius: var(--border-radius-pill);
		background: var(--color-background-dark);
		font-size: 12px;
		line-height: 18px;

		&--done {
			background: color-mix(in srgb, var(--color-success) 18%, transparent);
		}
	}
}
</style>
