<!--
  - SPDX-FileCopyrightText: 2026 Co-Optimize
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<NcDialog :name="editing ? t('ticketsystem', 'Change when the reply is sent') : t('ticketsystem', 'Send the reply later')"
		size="normal"
		@closing="$emit('close')">
		<div class="schedule">
			<p class="schedule__hint">
				{{ t('ticketsystem', 'Nothing reaches the customer before then. You can change the time, send it right away or cancel it until it goes out.') }}
			</p>
			<div class="schedule__presets" role="radiogroup" :aria-label="t('ticketsystem', 'When')">
				<button v-for="preset in presets"
					:key="preset.id"
					type="button"
					role="radio"
					class="schedule__preset"
					:class="{ 'schedule__preset--active': choice === preset.id }"
					:aria-checked="choice === preset.id"
					@click="choose(preset)">
					<span class="schedule__preset-label">{{ preset.label }}</span>
					<span class="schedule__preset-time">{{ format(preset.at) }}</span>
				</button>
				<button type="button"
					role="radio"
					class="schedule__preset"
					:class="{ 'schedule__preset--active': choice === 'custom' }"
					:aria-checked="choice === 'custom'"
					@click="choice = 'custom'">
					<span class="schedule__preset-label">{{ t('ticketsystem', 'Pick a date and time') }}</span>
					<span class="schedule__preset-time">{{ t('ticketsystem', 'Up to a year ahead') }}</span>
				</button>
			</div>
			<NcDateTimePickerNative v-if="choice === 'custom'"
				id="schedule-custom"
				v-model="custom"
				type="datetime-local"
				:min="minDate"
				:max="maxDate"
				:label="t('ticketsystem', 'Send at')" />
			<NcSelect v-if="!editing && statusOptions.length > 1"
				v-model="after"
				:options="statusOptions"
				label="label"
				:clearable="false"
				:input-label="t('ticketsystem', 'After sending')" />
			<p v-if="error" class="schedule__error">{{ error }}</p>
		</div>
		<template #actions>
			<NcButton @click="$emit('close')">{{ t('ticketsystem', 'Cancel') }}</NcButton>
			<NcButton variant="primary" :disabled="busy || !sendAt" @click="confirm">
				<template #icon>
					<SendClockIcon :size="20" />
				</template>
				{{ sendAt ? t('ticketsystem', 'Send {when}', { when: format(sendAt) }) : t('ticketsystem', 'Schedule') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script setup>
import { computed, ref } from 'vue'
import { getLanguage, t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDateTimePickerNative from '@nextcloud/vue/components/NcDateTimePickerNative'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import SendClockIcon from 'vue-material-design-icons/SendClockOutline.vue'
import { schedulePresets } from '../format.js'

const props = defineProps({
	statuses: { type: Array, default: () => [] },
	pendingStatus: { type: String, default: null },
	resolvedStatus: { type: String, default: null },
	/** A unix time when changing an existing scheduled reply */
	initialAt: { type: Number, default: null },
	editing: { type: Boolean, default: false },
	busy: { type: Boolean, default: false },
	error: { type: String, default: '' },
})
// confirm: ({ sendAt: unix seconds, thenStatus: ?string })
const emit = defineEmits(['close', 'confirm'])

const presets = computed(() => schedulePresets(new Date()).map(p => ({
	...p,
	label: {
		'tomorrow-morning': t('ticketsystem', 'Tomorrow morning'),
		'tomorrow-afternoon': t('ticketsystem', 'Tomorrow afternoon'),
		monday: t('ticketsystem', 'Monday morning'),
		week: t('ticketsystem', 'In a week'),
	}[p.id],
})))

const choice = ref(props.initialAt ? 'custom' : presets.value[0]?.id ?? 'custom')
const custom = ref(props.initialAt ? new Date(props.initialAt * 1000) : null)
const minDate = new Date(Date.now() + 2 * 60 * 1000)
const maxDate = new Date(Date.now() + 365 * 86400 * 1000)

const statusOptions = computed(() => {
	const label = (key) => props.statuses.find(s => s.key === key)?.label ?? key
	const list = [{ key: null, label: t('ticketsystem', 'Keep the status') }]
	if (props.pendingStatus) {
		list.push({ key: props.pendingStatus, label: t('ticketsystem', 'Wait for the customer ({status})', { status: label(props.pendingStatus) }) })
	}
	if (props.resolvedStatus) {
		list.push({ key: props.resolvedStatus, label: t('ticketsystem', 'Resolve ({status})', { status: label(props.resolvedStatus) }) })
	}
	return list
})
const after = ref(statusOptions.value[0])

const sendAt = computed(() => {
	if (choice.value === 'custom') {
		const d = custom.value instanceof Date ? custom.value : (custom.value ? new Date(custom.value) : null)
		return d && !Number.isNaN(d.getTime()) && d.getTime() > Date.now() + 60000 ? d : null
	}
	return presets.value.find(p => p.id === choice.value)?.at ?? null
})

const formatter = new Intl.DateTimeFormat(getLanguage(), { weekday: 'short', day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' })
const format = (date) => formatter.format(date)

function choose(preset) {
	choice.value = preset.id
}

function confirm() {
	if (!sendAt.value) {
		return
	}
	emit('confirm', { sendAt: Math.floor(sendAt.value.getTime() / 1000), thenStatus: after.value?.key ?? null })
}
</script>

<style scoped lang="scss">
.schedule {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding-bottom: 8px;

	&__hint {
		margin: 0;
		color: var(--color-text-maxcontrast);
	}

	&__presets {
		display: grid;
		grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
		gap: 8px;
	}

	&__preset {
		display: flex;
		flex-direction: column;
		align-items: flex-start;
		gap: 2px;
		margin: 0;
		padding: 10px 12px;
		border: 2px solid var(--color-border);
		border-radius: var(--border-radius-large);
		background: var(--color-main-background);
		color: var(--color-main-text);
		text-align: start;
		cursor: pointer;

		&:hover {
			border-color: var(--color-primary-element-light);
		}

		&--active {
			border-color: var(--color-primary-element);
			background: var(--color-primary-element-light);
		}
	}

	&__preset-label {
		font-weight: 600;
	}

	&__preset-time {
		color: var(--color-text-maxcontrast);
		font-size: var(--font-size-small, 13px);
	}

	&__error {
		margin: 0;
		color: var(--color-error-text, var(--color-error));
	}
}
</style>
