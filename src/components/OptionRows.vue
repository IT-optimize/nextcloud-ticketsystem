<!--
  - SPDX-FileCopyrightText: 2026 Co-Optimize
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="option-rows">
		<div v-for="(row, index) in modelValue" :key="index" class="option-rows__row">
			<NcTextField :model-value="row.key"
				:label="t('ticketsystem', 'Key')"
				@update:model-value="v => change(index, { key: v })" />
			<NcTextField :model-value="row.label"
				:label="t('ticketsystem', 'Label')"
				@update:model-value="v => change(index, { label: v })" />
			<NcCheckboxRadioSwitch v-if="withClosed"
				:model-value="row.closed"
				@update:model-value="v => change(index, { closed: v })">
				{{ t('ticketsystem', 'Done') }}
			</NcCheckboxRadioSwitch>
			<NcButton variant="tertiary"
				:aria-label="t('ticketsystem', 'Remove')"
				:disabled="!allowEmpty && modelValue.length <= 1"
				@click="remove(index)">
				<template #icon>
					<DeleteIcon :size="20" />
				</template>
			</NcButton>
		</div>
		<NcButton variant="secondary" @click="add">
			<template #icon>
				<PlusIcon :size="20" />
			</template>
			{{ t('ticketsystem', 'Add') }}
		</NcButton>
	</div>
</template>

<script setup>
import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import DeleteIcon from 'vue-material-design-icons/Delete.vue'
import PlusIcon from 'vue-material-design-icons/Plus.vue'

const props = defineProps({
	modelValue: { type: Array, required: true },
	withClosed: { type: Boolean, default: false },
	/** Whether the last row may be removed. */
	allowEmpty: { type: Boolean, default: false },
})
const emit = defineEmits(['update:modelValue'])

function change(index, patch) {
	emit('update:modelValue', props.modelValue.map((row, i) => i === index ? { ...row, ...patch } : row))
}

function remove(index) {
	emit('update:modelValue', props.modelValue.filter((_, i) => i !== index))
}

function add() {
	const row = props.withClosed ? { key: '', label: '', closed: false } : { key: '', label: '' }
	emit('update:modelValue', [...props.modelValue, row])
}
</script>

<style scoped lang="scss">
.option-rows {
	display: flex;
	flex-direction: column;
	align-items: flex-start;
	gap: 8px;
	max-width: 700px;

	&__row {
		display: grid;
		grid-template-columns: 1fr 2fr auto auto;
		gap: 12px;
		align-items: end;
		width: 100%;
	}
}
</style>
