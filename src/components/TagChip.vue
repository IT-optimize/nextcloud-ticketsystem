<!--
  - SPDX-FileCopyrightText: 2026 Co-Optimize
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<span class="tag-chip" :style="style" :title="tag.name">{{ tag.name }}</span>
</template>

<script setup>
import { computed } from 'vue'
import { textOn } from '../format.js'

const props = defineProps({
	tag: { type: Object, required: true },
	/** Smaller chip for dense lists. */
	small: { type: Boolean, default: false },
})

const style = computed(() => {
	const color = props.tag.color || '#757575'
	return {
		background: color,
		color: textOn(color),
		fontSize: props.small ? '12px' : undefined,
		lineHeight: props.small ? '18px' : undefined,
	}
})
</script>

<style scoped>
.tag-chip {
	display: inline-block;
	max-width: 160px;
	padding: 0 8px;
	border-radius: var(--border-radius-pill);
	font-size: var(--font-size-small, 13px);
	line-height: 20px;
	white-space: nowrap;
	overflow: hidden;
	text-overflow: ellipsis;
	vertical-align: middle;
}
</style>
