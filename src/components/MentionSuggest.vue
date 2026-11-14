<!--
  - SPDX-FileCopyrightText: 2026 Co-Optimize
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<ul v-if="open && matches.length"
		class="mentions"
		role="listbox"
		:aria-label="t('ticketsystem', 'Mention a colleague')">
		<li v-for="(agent, index) in matches"
			:key="agent.uid"
			role="option"
			:aria-selected="index === active"
			class="mentions__item"
			:class="{ 'mentions__item--active': index === active }"
			@mousedown.prevent="choose(agent)">
			<NcAvatar :user="agent.uid"
				:display-name="agent.displayName"
				:size="24"
				hide-status
				disable-menu
				disable-tooltip />
			<span class="mentions__name">{{ agent.displayName }}</span>
			<span class="mentions__uid">@{{ agent.uid }}</span>
		</li>
	</ul>
</template>

<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { t } from '@nextcloud/l10n'
import NcAvatar from '@nextcloud/vue/components/NcAvatar'

const props = defineProps({
	/** The text area to watch. */
	textarea: { type: Object, default: null },
	/** Agents who can be mentioned ({uid, displayName}). */
	agents: { type: Array, required: true },
	enabled: { type: Boolean, default: true },
})
// insert: (newText, caret) — the text with the mention filled in
const emit = defineEmits(['insert'])

const open = ref(false)
const term = ref('')
const start = ref(0)
const active = ref(0)

const matches = computed(() => {
	const needle = term.value.toLowerCase()
	return props.agents
		.filter(agent => agent.uid.toLowerCase().includes(needle) || agent.displayName.toLowerCase().includes(needle))
		.slice(0, 6)
})

/** Looks for "@something" right before the caret. */
function update() {
	const el = props.textarea
	if (!props.enabled || !el) {
		open.value = false
		return
	}
	const before = el.value.slice(0, el.selectionStart)
	const match = /(^|[\s(])@([\w.@-]*)$/.exec(before)
	if (!match) {
		open.value = false
		return
	}
	term.value = match[2]
	start.value = el.selectionStart - match[2].length - 1
	if (!open.value) {
		active.value = 0
	}
	open.value = true
	active.value = Math.min(active.value, Math.max(matches.value.length - 1, 0))
}

function choose(agent) {
	const el = props.textarea
	const end = el.selectionStart
	const text = el.value.slice(0, start.value) + '@' + agent.uid + ' ' + el.value.slice(end)
	const caret = start.value + agent.uid.length + 2
	open.value = false
	emit('insert', text, caret)
}

function onKeydown(event) {
	if (!open.value || matches.value.length === 0) {
		return
	}
	if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
		event.preventDefault()
		const step = event.key === 'ArrowDown' ? 1 : -1
		active.value = (active.value + step + matches.value.length) % matches.value.length
	} else if ((event.key === 'Enter' && !event.ctrlKey && !event.metaKey) || event.key === 'Tab') {
		event.preventDefault()
		event.stopImmediatePropagation()
		choose(matches.value[active.value])
	} else if (event.key === 'Escape') {
		event.preventDefault()
		event.stopImmediatePropagation()
		open.value = false
	}
}

function onBlur() {
	open.value = false
}

function attach(el, old) {
	if (old) {
		old.removeEventListener('input', update)
		old.removeEventListener('click', update)
		old.removeEventListener('keydown', onKeydown, true)
		old.removeEventListener('blur', onBlur)
	}
	if (el) {
		el.addEventListener('input', update)
		el.addEventListener('click', update)
		el.addEventListener('keydown', onKeydown, true)
		el.addEventListener('blur', onBlur)
	}
}

watch(() => props.textarea, attach, { immediate: true })
watch(() => props.enabled, update)
onBeforeUnmount(() => attach(null, props.textarea))
</script>

<style scoped lang="scss">
.mentions {
	position: absolute;
	z-index: 10;
	inset-inline-start: 12px;
	top: 100%;
	min-width: 240px;
	max-width: calc(100% - 24px);
	margin-top: -8px;
	padding: 4px;
	border-radius: var(--border-radius-large);
	background: var(--color-main-background);
	box-shadow: 0 2px 12px var(--color-box-shadow);

	&__item {
		display: flex;
		align-items: center;
		gap: 8px;
		padding: 4px 8px;
		border-radius: var(--border-radius);
		cursor: pointer;

		&--active,
		&:hover {
			background: var(--color-background-hover);
		}
	}

	&__name {
		font-weight: 600;
	}

	&__uid {
		color: var(--color-text-maxcontrast);
		font-size: var(--font-size-small, 13px);
	}
}
</style>
