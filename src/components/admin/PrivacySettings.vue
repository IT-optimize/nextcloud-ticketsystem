<!--
  - SPDX-FileCopyrightText: 2026 Co-Optimize
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="privacy">
		<NcSettingsSection :name="t('ticketsystem', 'A customer\'s data')"
			:description="t('ticketsystem', 'For requests under the GDPR: see what Ticket system holds about an email address, download it, or erase it. Erasing removes the address, name, subjects, message texts and files of their tickets, their messages on other tickets and their place on CC lists. Status, times and logged time stay, so reports and invoices still add up.')">
			<form class="privacy__lookup" @submit.prevent="lookUp">
				<NcTextField v-model="email"
					type="email"
					:label="t('ticketsystem', 'Customer email address')"
					autocomplete="off" />
				<NcButton type="submit" :disabled="busy || !email.trim()">
					<template #icon>
						<NcLoadingIcon v-if="busy && !summary" :size="20" />
						<MagnifyIcon v-else :size="20" />
					</template>
					{{ t('ticketsystem', 'Look up') }}
				</NcButton>
			</form>

			<div v-if="summary" class="privacy__result">
				<p v-if="!found" class="privacy__muted">
					{{ t('ticketsystem', 'Ticket system holds nothing about {email}.', { email: summary.email }) }}
				</p>
				<template v-else>
					<ul v-if="summary.tickets.length" class="privacy__tickets">
						<li v-for="ticket in summary.tickets" :key="ticket.id">
							<a :href="ticketUrl(ticket.id)" target="_blank" rel="noopener noreferrer">{{ ticket.reference }} {{ ticket.subject }}</a>
							<span class="privacy__muted">{{ dateFormat.format(new Date(ticket.createdAt * 1000)) }}</span>
						</li>
					</ul>
					<p v-if="summary.copiedOn || summary.otherMessages" class="privacy__muted">
						{{ t('ticketsystem', 'Also copied on {copied} other tickets, and wrote {messages} messages on other tickets.', { copied: summary.copiedOn, messages: summary.otherMessages }) }}
					</p>
					<div class="privacy__actions">
						<NcButton :disabled="busy" @click="download">
							<template #icon>
								<DownloadIcon :size="20" />
							</template>
							{{ t('ticketsystem', 'Download their data (JSON)') }}
						</NcButton>
						<NcButton v-if="!confirming" variant="error" :disabled="busy" @click="confirming = true">
							<template #icon>
								<DeleteIcon :size="20" />
							</template>
							{{ t('ticketsystem', 'Erase…') }}
						</NcButton>
					</div>
					<div v-if="confirming" class="privacy__confirm">
						<p>{{ t('ticketsystem', 'This can\'t be undone. Type {email} to confirm.', { email: summary.email }) }}</p>
						<NcTextField v-model="confirmation" :label="t('ticketsystem', 'Email address')" autocomplete="off" />
						<div class="privacy__actions">
							<NcButton variant="tertiary" @click="confirming = false; confirmation = ''">
								{{ t('ticketsystem', 'Cancel') }}
							</NcButton>
							<NcButton variant="error"
								:disabled="busy || confirmation.trim().toLowerCase() !== summary.email"
								@click="erase">
								{{ t('ticketsystem', 'Erase customer data') }}
							</NcButton>
						</div>
					</div>
				</template>
			</div>
		</NcSettingsSection>

		<NcSettingsSection :name="t('ticketsystem', 'Erase old customer data automatically')"
			:description="t('ticketsystem', 'Once a day, tickets that have been done for longer than this are erased like above. 0 keeps customer data until you erase it.')">
			<form class="privacy__lookup" @submit.prevent="saveRetention">
				<NcTextField v-model="months"
					type="number"
					min="0"
					max="240"
					:label="t('ticketsystem', 'Months after a ticket is done')" />
				<NcButton type="submit" :disabled="savingRetention">
					{{ t('ticketsystem', 'Save') }}
				</NcButton>
			</form>
		</NcSettingsSection>
	</div>
</template>

<script setup>
import { computed, ref } from 'vue'
import { getLanguage, t } from '@nextcloud/l10n'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { generateUrl } from '@nextcloud/router'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcSettingsSection from '@nextcloud/vue/components/NcSettingsSection'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import DeleteIcon from 'vue-material-design-icons/Delete.vue'
import DownloadIcon from 'vue-material-design-icons/Download.vue'
import MagnifyIcon from 'vue-material-design-icons/Magnify.vue'
import { api, errorMessage } from '../../api.js'

const props = defineProps({
	retentionMonths: { type: Number, default: 0 },
})

const email = ref('')
const summary = ref(null)
const busy = ref(false)
const confirming = ref(false)
const confirmation = ref('')
const found = computed(() => summary.value && (summary.value.tickets.length || summary.value.copiedOn || summary.value.otherMessages))
const dateFormat = new Intl.DateTimeFormat(getLanguage(), { dateStyle: 'medium' })
const ticketUrl = (id) => generateUrl('/apps/ticketsystem/tickets/{id}', { id })

async function lookUp() {
	busy.value = true
	confirming.value = false
	confirmation.value = ''
	try {
		summary.value = await api.privacySummary(email.value.trim())
	} catch (error) {
		summary.value = null
		showError(errorMessage(error, t('ticketsystem', 'Could not look up the address')))
	} finally {
		busy.value = false
	}
}

async function download() {
	busy.value = true
	try {
		const data = await api.privacyExport(summary.value.email)
		const link = document.createElement('a')
		link.href = URL.createObjectURL(new Blob([JSON.stringify(data, null, 2)], { type: 'application/json' }))
		link.download = 'ticketsystem-' + summary.value.email.replace(/[^a-z0-9@._-]/gi, '_') + '.json'
		link.click()
		URL.revokeObjectURL(link.href)
	} catch (error) {
		showError(errorMessage(error, t('ticketsystem', 'Could not download the data')))
	} finally {
		busy.value = false
	}
}

async function erase() {
	busy.value = true
	try {
		const result = await api.privacyErase(summary.value.email)
		showSuccess(t('ticketsystem', 'Erased: {tickets} tickets, {messages} messages on other tickets, {copied} CC entries', {
			tickets: result.tickets, messages: result.messages, copied: result.copiedOn,
		}))
		confirming.value = false
		confirmation.value = ''
		summary.value = await api.privacySummary(summary.value.email)
	} catch (error) {
		showError(errorMessage(error, t('ticketsystem', 'Could not erase the data')))
	} finally {
		busy.value = false
	}
}

const months = ref(String(props.retentionMonths))
const savingRetention = ref(false)
async function saveRetention() {
	savingRetention.value = true
	try {
		await api.saveSettings({ retentionMonths: Number(months.value) || 0 })
		showSuccess(Number(months.value) > 0
			? t('ticketsystem', 'Customer data is now erased {months} months after a ticket is done', { months: Number(months.value) })
			: t('ticketsystem', 'Customer data is kept until you erase it'))
	} catch (error) {
		showError(errorMessage(error, t('ticketsystem', 'Could not save the settings')))
	} finally {
		savingRetention.value = false
	}
}
</script>

<style scoped lang="scss">
.privacy {
	&__lookup {
		display: flex;
		flex-wrap: wrap;
		align-items: flex-end;
		gap: 8px;
		max-width: 600px;

		> :first-child {
			flex: 1;
			min-width: 220px;
		}
	}

	&__result {
		display: flex;
		flex-direction: column;
		gap: 8px;
		max-width: 600px;
		margin-top: 12px;
	}

	&__tickets {
		display: flex;
		flex-direction: column;
		gap: 4px;

		li {
			display: flex;
			justify-content: space-between;
			gap: 12px;
		}

		a:hover {
			text-decoration: underline;
		}
	}

	&__muted {
		margin: 0;
		color: var(--color-text-maxcontrast);
	}

	&__actions {
		display: flex;
		flex-wrap: wrap;
		gap: 8px;
	}

	&__confirm {
		display: flex;
		flex-direction: column;
		gap: 8px;
		padding: 12px;
		border-radius: var(--border-radius-large);
		background: color-mix(in srgb, var(--color-error) 10%, transparent);

		p {
			margin: 0;
		}
	}
}
</style>
