<!--
  - SPDX-FileCopyrightText: 2026 Co-Optimize
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="tools">
		<NcSelect v-model="picked"
			class="tools__canned"
			:options="brandReplies"
			label="title"
			:filter-by="matchReply"
			:input-label="t('ticketsystem', 'Canned reply')"
			:placeholder="brandReplies.length ? t('ticketsystem', 'Search saved replies') : t('ticketsystem', 'No saved replies yet')"
			:disabled="disabled || brandReplies.length === 0"
			@update:model-value="insertCanned" />
		<NcButton variant="tertiary"
			:aria-label="t('ticketsystem', 'Manage canned replies')"
			:title="t('ticketsystem', 'Manage canned replies')"
			@click="showManager = true">
			<template #icon>
				<CogIcon :size="20" />
			</template>
		</NcButton>

		<span class="tools__spacer" />


		<CannedRepliesModal v-if="showManager"
			:initial-body="managerBody"
			:meta="meta"
			:team-id="ticket.teamId"
			@changed="loadReplies"
			@close="showManager = false; managerBody = ''" />
	</div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import CogIcon from 'vue-material-design-icons/Cog.vue'
import CannedRepliesModal from './CannedRepliesModal.vue'
import { api } from '../api.js'
import { fillPlaceholders } from '../format.js'

const props = defineProps({
	ticket: { type: Object, required: true },
	meta: { type: Object, required: true },
	draft: { type: String, required: true },
	mode: { type: String, required: true },
	disabled: { type: Boolean, default: false },
})
// insert: (text, { replace, source })
const emit = defineEmits(['insert'])

const replies = ref([])
/** Replies for every brand, plus those for this ticket's brand. */
const brandReplies = computed(() => replies.value.filter(r => r.teamId === null || r.teamId === props.ticket.teamId))
const picked = ref(null)
const showManager = ref(false)
const managerBody = ref('')

async function loadReplies() {
	try {
		replies.value = (await api.listCanned()).replies
	} catch {
		// The picker just stays empty; the manager shows the error when opened.
	}
}

function matchReply(option, label, search) {
	const needle = search.toLowerCase()
	return option.title.toLowerCase().includes(needle) || option.body.toLowerCase().includes(needle)
}

function insertCanned(reply) {
	if (!reply) {
		return
	}
	emit('insert', fillPlaceholders(reply.body, props.ticket, props.meta), { replace: false, source: 'canned' })
	api.cannedUsed(reply.id).catch(() => {})
	// Reset so the same reply can be picked again.
	setTimeout(() => { picked.value = null }, 0)
}

/** Opens the manager with a new reply prefilled, used by "Save as canned reply". */
function saveAsCanned(body) {
	managerBody.value = body
	showManager.value = true
}

onMounted(loadReplies)

defineExpose({ saveAsCanned })
</script>

<style scoped lang="scss">
.tools {
	display: flex;
	flex-wrap: wrap;
	align-items: end;
	gap: calc(var(--default-grid-baseline) * 2);

	&__canned {
		min-width: 0;
		flex: 1 1 200px;
		max-width: 320px;
	}

	&__spacer {
		flex: 1;
	}
}
</style>
