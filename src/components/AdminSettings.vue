<!--
  - SPDX-FileCopyrightText: 2026 Co-Optimize
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="ticketsystem-admin">
		<nav class="ticketsystem-admin__tabs" role="tablist" :aria-label="t('ticketsystem', 'Ticket system settings')">
			<button v-for="tab in tabs"
				:key="tab.id"
				type="button"
				role="tab"
				class="ticketsystem-admin__tab"
				:class="{ 'ticketsystem-admin__tab--active': activeTab === tab.id }"
				:aria-selected="activeTab === tab.id"
				@click="activeTab = tab.id">
				{{ tab.label }}
			</button>
		</nav>

		<NcSettingsSection v-show="activeTab === 'company'" :name="t('ticketsystem', 'Company')">
			<CompanySettings :teams="settings.teams"
				:default-team-id="settings.defaultTeamId"
				:groups="groups"
				@updated="applyServer" />
		</NcSettingsSection>

		<div v-show="activeTab === 'email'">
		<NcSettingsSection :name="t('ticketsystem', 'Email intake')"
			:description="t('ticketsystem', 'Ticket system reads the support inbox over IMAP. New emails become tickets; replies are added to the right ticket. Processed emails are marked as read.')">
			<NcCheckboxRadioSwitch v-model="settings.intakeEnabled" type="switch">
				{{ t('ticketsystem', 'Turn emails into tickets') }}
			</NcCheckboxRadioSwitch>

			<div class="ticketsystem-admin__fields">
				<NcTextField v-model="settings.imapHost" :label="t('ticketsystem', 'IMAP server')" placeholder="imap.example.com" />
				<NcSelect v-model="security"
					:options="securityOptions"
					label="label"
					:clearable="false"
					:input-label="t('ticketsystem', 'Connection security')"
					@update:model-value="onSecurityChange" />
				<NcTextField v-model="port" type="number" :label="t('ticketsystem', 'Port')" />
				<NcTextField v-model="settings.imapUser" :label="t('ticketsystem', 'User name')" autocomplete="off" />
				<NcPasswordField v-model="imapPassword"
					:label="settings.imapPasswordSet ? t('ticketsystem', 'Password (saved)') : t('ticketsystem', 'Password')"
					autocomplete="new-password" />
				<NcTextField v-model="settings.imapFolder" :label="t('ticketsystem', 'Folder to read')" />
				<NcTextField v-model="settings.imapProcessedFolder"
					:label="t('ticketsystem', 'Move processed emails to (optional)')"
					:placeholder="t('ticketsystem', 'Leave empty to only mark as read')" />
			</div>
			<NcCheckboxRadioSwitch v-if="settings.imapSecurity !== 'none'" v-model="settings.imapVerifyTls" type="switch">
				{{ t('ticketsystem', 'Verify the server certificate (turn off only for self-signed test servers)') }}
			</NcCheckboxRadioSwitch>

			<div class="ticketsystem-admin__row">
				<NcButton :disabled="testing" @click="testConnection">
					<template #icon>
						<NcLoadingIcon v-if="testing" :size="20" />
						<LanConnectIcon v-else :size="20" />
					</template>
					{{ t('ticketsystem', 'Test connection') }}
				</NcButton>
				<NcButton :disabled="fetching || !initial.intakeEnabled" @click="fetchNow">
					<template #icon>
						<NcLoadingIcon v-if="fetching" :size="20" />
						<EmailSyncIcon v-else :size="20" />
					</template>
					{{ t('ticketsystem', 'Check inbox now') }}
				</NcButton>
			</div>
			<NcNoteCard v-if="testResult" :type="testResult.ok ? 'success' : 'error'">
				{{ testResult.text }}
			</NcNoteCard>
			<p class="ticketsystem-admin__status">
				{{ intakeStatusText }}
			</p>
			<NcNoteCard v-if="settings.intakeStatus?.lastError" type="warning">
				{{ t('ticketsystem', 'Last check failed: {error}', { error: settings.intakeStatus.lastError }) }}
			</NcNoteCard>
		</NcSettingsSection>

		<NcSettingsSection :name="t('ticketsystem', 'Customer email')"
			:description="t('ticketsystem', 'Replies are sent through the email server configured under Basic settings.')">
			<NcCheckboxRadioSwitch v-model="settings.sendCustomerEmails" type="switch">
				{{ t('ticketsystem', 'Email public replies to the customer') }}
			</NcCheckboxRadioSwitch>
			<NcCheckboxRadioSwitch v-model="settings.sendAcknowledgment" type="switch" :disabled="!settings.sendCustomerEmails">
				{{ t('ticketsystem', 'Confirm new email requests automatically (with the ticket reference)') }}
			</NcCheckboxRadioSwitch>
			<NcCheckboxRadioSwitch v-model="settings.portalEnabled" type="switch">
				{{ t('ticketsystem', 'Customer portal: link each email to a private page where the customer can follow and answer the request') }}
			</NcCheckboxRadioSwitch>
			<div class="ticketsystem-admin__fields">
				<NcSelect :model-value="languages.find(l => l.code === settings.customerLanguage) ?? null"
					:options="languages"
					label="name"
					:clearable="false"
					:input-label="t('ticketsystem', 'Language for customers')"
					@update:model-value="o => settings.customerLanguage = o.code" />
				<NcTextField v-model="settings.senderName"
					:label="t('ticketsystem', 'Sender name')"
					:helper-text="t('ticketsystem', 'Leave empty to use the company name')" />
				<NcTextField v-model="settings.supportAddress"
					type="email"
					:label="t('ticketsystem', 'Reply-to address (your support inbox)')" />
			</div>
		</NcSettingsSection>

		</div>

		<div v-show="activeTab === 'fields'">
		<NcSettingsSection :name="t('ticketsystem', 'Statuses')"
			:description="t('ticketsystem', 'The first status is used for new tickets. Statuses marked as done are hidden from open views and the dashboard.')">
			<OptionRows v-model="settings.statuses" with-closed />
			<p class="ticketsystem-admin__status">
				{{ t('ticketsystem', 'Built-in names of statuses and priorities that you leave unchanged are shown in each person\'s own language. A name you write yourself is shown as written.') }}
			</p>
		</NcSettingsSection>

		<NcSettingsSection :name="t('ticketsystem', 'Priorities')">
			<OptionRows v-model="settings.priorities" />
		</NcSettingsSection>
		</div>

		<NcSettingsSection v-show="activeTab === 'tags'" :name="t('ticketsystem', 'Tags')">
			<TagsSettings :tags="settings.tags" @updated="applyServer" />
		</NcSettingsSection>

		<PrivacySettings v-show="activeTab === 'privacy'" :retention-months="initial.retentionMonths ?? 0" />

		<div v-show="activeTab === 'email' || activeTab === 'fields'" class="ticketsystem-admin__save">
			<NcButton variant="primary" :disabled="saving" @click="save">
				{{ t('ticketsystem', 'Save') }}
			</NcButton>
			<span class="ticketsystem-admin__save-hint">{{ t('ticketsystem', 'Saves the email, portal, status and priority settings. The company and tags save on their own.') }}</span>
		</div>
	</div>
</template>

<script setup>
import { computed, reactive, ref } from 'vue'
import { loadState } from '@nextcloud/initial-state'
import { getLanguage, n, t } from '@nextcloud/l10n'
import { showError, showSuccess } from '@nextcloud/dialogs'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcPasswordField from '@nextcloud/vue/components/NcPasswordField'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcSettingsSection from '@nextcloud/vue/components/NcSettingsSection'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import EmailSyncIcon from 'vue-material-design-icons/EmailSyncOutline.vue'
import LanConnectIcon from 'vue-material-design-icons/LanConnect.vue'
import OptionRows from './OptionRows.vue'
import CompanySettings from './admin/CompanySettings.vue'
import PrivacySettings from './admin/PrivacySettings.vue'
import TagsSettings from './admin/TagsSettings.vue'
import { api, errorMessage } from '../api.js'

const initial = reactive(loadState('ticketsystem', 'admin-settings'))
const groups = loadState('ticketsystem', 'groups', [])
const languages = loadState('ticketsystem', 'languages', [])

const settings = reactive(structuredClone(initial))
const tabs = [
	{ id: 'company', label: t('ticketsystem', 'Company') },
	{ id: 'email', label: t('ticketsystem', 'Email') },
	{ id: 'fields', label: t('ticketsystem', 'Statuses') },
	{ id: 'tags', label: t('ticketsystem', 'Tags') },
	{ id: 'privacy', label: t('ticketsystem', 'Customer data') },
]
const activeTab = ref('company')

/** The company and tags save on their own; take over what the server sent back. */
function applyServer(data) {
	settings.teams = data.teams
	settings.defaultTeamId = data.defaultTeamId
	settings.tags = data.tags
}
const imapPassword = ref('')
const port = ref(String(initial.imapPort))
const saving = ref(false)
const testing = ref(false)
const fetching = ref(false)
const testResult = ref(null)

const securityOptions = [
	{ id: 'ssl', label: t('ticketsystem', 'SSL/TLS (port 993)'), port: 993 },
	{ id: 'starttls', label: t('ticketsystem', 'STARTTLS (port 143)'), port: 143 },
	{ id: 'none', label: t('ticketsystem', 'None (testing only)'), port: 143 },
]
const security = ref(securityOptions.find(o => o.id === initial.imapSecurity) ?? securityOptions[0])

function onSecurityChange(option) {
	settings.imapSecurity = option.id
	if (securityOptions.some(o => String(o.port) === port.value)) {
		port.value = String(option.port)
	}
}

const dateFormat = new Intl.DateTimeFormat(getLanguage(), { dateStyle: 'medium', timeStyle: 'short' })
const intakeStatusText = computed(() => {
	const status = settings.intakeStatus ?? {}
	if (!status.lastRun) {
		return t('ticketsystem', 'The inbox has not been checked yet. Checks run with Nextcloud\'s background jobs (cron).')
	}
	return t('ticketsystem', 'Last checked {date}.', { date: dateFormat.format(new Date(status.lastRun * 1000)) })
		+ ' ' + n('ticketsystem', '%n email imported in total.', '%n emails imported in total.', status.importedTotal ?? 0)
})

function imapPayload() {
	const payload = {
		imapHost: settings.imapHost,
		imapPort: Number(port.value),
		imapSecurity: settings.imapSecurity,
		imapVerifyTls: settings.imapVerifyTls,
		imapUser: settings.imapUser,
		imapFolder: settings.imapFolder,
		imapProcessedFolder: settings.imapProcessedFolder,
	}
	if (imapPassword.value !== '') {
		payload.imapPassword = imapPassword.value
	}
	return payload
}

async function testConnection() {
	testing.value = true
	testResult.value = null
	try {
		const result = await api.testImap(imapPayload())
		testResult.value = {
			ok: true,
			text: t('ticketsystem', 'Connected. {folder} has {total} emails, {unread} unread. Folders: {folders}', {
				folder: settings.imapFolder || 'INBOX',
				total: result.messages,
				unread: result.unread,
				folders: result.folders.join(', '),
			}),
		}
	} catch (error) {
		testResult.value = { ok: false, text: errorMessage(error, t('ticketsystem', 'Could not connect')) }
	} finally {
		testing.value = false
	}
}

async function fetchNow() {
	fetching.value = true
	try {
		const result = await api.fetchMail()
		Object.assign(settings, { intakeStatus: result.settings.intakeStatus })
		const r = result.result
		showSuccess(t('ticketsystem', 'New tickets: {created}, replies: {replies}, ignored: {ignored}, failed: {failed}', r))
	} catch (error) {
		const status = error?.response?.data?.ocs?.data?.settings?.intakeStatus
		if (status) {
			settings.intakeStatus = status
		}
		showError(errorMessage(error, t('ticketsystem', 'Could not check the inbox')))
	} finally {
		fetching.value = false
	}
}

async function save() {
	saving.value = true
	try {
		// retentionMonths saves on the Customer data tab; this copy may be outdated.
		const { intakeStatus, imapPasswordSet, imapPort, teams, defaultTeamId, tags, agentGroups, retentionMonths, ...rest } = settings
		const saved = await api.saveSettings({
			...rest,
			...imapPayload(),
		})
		Object.assign(settings, saved)
		Object.assign(initial, saved)
		imapPassword.value = ''
		showSuccess(t('ticketsystem', 'Ticket system settings saved'))
	} catch (error) {
		showError(errorMessage(error, t('ticketsystem', 'Could not save the settings')))
	} finally {
		saving.value = false
	}
}
</script>

<style scoped lang="scss">
.ticketsystem-admin {
	&__wide {
		max-width: 500px;
	}

	&__fields {
		display: grid;
		grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
		gap: 12px;
		max-width: 760px;
		margin-top: 12px;
		// Top-aligned, so a field with a help text below it stays in line with its neighbours.
		align-items: start;

		> * {
			min-width: 0;
		}

		:deep(.select) {
			min-width: 0;
			width: 100%;
			// A text field leaves this much room above itself for its label; the select's label sits outside its box.
			margin-top: 4px;
		}
	}

	&__row {
		display: flex;
		flex-wrap: wrap;
		gap: 8px;
		margin: 16px 0 8px;
	}

	&__status {
		color: var(--color-text-maxcontrast);
		margin: 8px 0;
	}

	&__tabs {
		display: flex;
		flex-wrap: wrap;
		gap: 4px;
		margin: 16px 30px 0;
		border-bottom: 1px solid var(--color-border);
	}

	&__tab {
		margin: 0 0 -1px;
		padding: 8px 16px;
		border: none;
		border-bottom: 3px solid transparent;
		border-radius: var(--border-radius) var(--border-radius) 0 0;
		background: transparent;
		color: var(--color-text-maxcontrast);
		font-weight: 600;
		cursor: pointer;

		&:hover {
			background: var(--color-background-hover);
			color: var(--color-main-text);
		}

		&--active {
			border-bottom-color: var(--color-primary-element);
			color: var(--color-main-text);
		}
	}

	&__save {
		position: sticky;
		bottom: 0;
		display: flex;
		flex-wrap: wrap;
		align-items: center;
		gap: 12px;
		margin: 0 30px 24px;
		padding: 12px 0;
		background: var(--color-main-background);
	}

	&__save-hint {
		color: var(--color-text-maxcontrast);
		font-size: var(--font-size-small, 13px);
	}
}
</style>
