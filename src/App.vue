<!--
  - SPDX-FileCopyrightText: 2026 Co-Optimize
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<NcContent app-name="ticketsystem">
		<NcAppNavigation v-if="isAgent">
			<template #search>
				<NcAppNavigationSearch v-model="query" :label="t('ticketsystem', 'Search tickets')" />
			</template>
			<template #list>
				<NcAppNavigationNew :text="t('ticketsystem', 'New ticket')" @click="showNewTicket = true">
					<template #icon>
						<PlusIcon :size="20" />
					</template>
				</NcAppNavigationNew>
				<li v-if="setupMissing.length" class="app-setup">
					<NcNoteCard type="info" :heading="t('ticketsystem', 'Finish the setup')">
						<ul class="app-setup__list">
							<li v-for="item in setupMissing" :key="item">
								{{ item }}
							</li>
						</ul>
						<NcButton variant="secondary" size="small" :href="adminUrl">
							{{ t('ticketsystem', 'Open the settings') }}
						</NcButton>
					</NcNoteCard>
				</li>
				<NcAppNavigationItem v-for="item in builtInViews"
					:key="item.id"
					:name="item.name"
					:active="viewId === item.id && selectedId === null && route.name === 'list'"
					:href="viewHref(item.id)"
					@click.prevent="openView(item.id)">
					<template #icon>
						<component :is="viewIcons[item.id] ?? ViewListIcon" :size="20" />
					</template>
					<template v-if="counts[item.id]" #counter>
						<NcCounterBubble :count="counts[item.id]" :type="item.id === 'needs-reply' ? 'highlighted' : ''" />
					</template>
				</NcAppNavigationItem>
			</template>
			<template #footer>
				<ul class="app-footer">
					<NcAppNavigationItem :name="t('ticketsystem', 'Reports')"
						:active="route.name === 'reports'"
						:href="router.resolve({ name: 'reports' }).href"
						@click.prevent="router.push({ name: 'reports' })">
						<template #icon>
							<ChartIcon :size="20" />
						</template>
					</NcAppNavigationItem>
					<NcAppNavigationItem :name="t('ticketsystem', 'Time and invoicing')"
						:active="route.name === 'time'"
						:href="router.resolve({ name: 'time' }).href"
						@click.prevent="router.push({ name: 'time' })">
						<template #icon>
							<ClockIcon :size="20" />
						</template>
					</NcAppNavigationItem>
					<NcAppNavigationItem :name="t('ticketsystem', 'Canned replies')" @click="showCanned = true">
						<template #icon>
							<TextBoxIcon :size="20" />
						</template>
					</NcAppNavigationItem>
					<NcAppNavigationItem :name="t('ticketsystem', 'Keyboard shortcuts')" @click="showShortcuts = true">
						<template #icon>
							<KeyboardIcon :size="20" />
						</template>
					</NcAppNavigationItem>
				</ul>
			</template>
		</NcAppNavigation>

		<NcAppContent v-if="!isAgent">
			<NcEmptyContent :name="t('ticketsystem', 'Ticket system is for agents')"
				:description="t('ticketsystem', 'Ask an administrator to add you to an agent group.')">
				<template #icon>
					<TicketIcon />
				</template>
			</NcEmptyContent>
		</NcAppContent>

		<NcAppContent v-else-if="route.name === 'reports'" :page-heading="t('ticketsystem', 'Reports')">
			<ReportsPage v-if="meta" :meta="meta" />
			<NcLoadingIcon v-else class="app-loading" />
		</NcAppContent>

		<NcAppContent v-else-if="route.name === 'time'" :page-heading="t('ticketsystem', 'Time and invoicing')">
			<TimePage v-if="meta" :meta="meta" @companies-changed="loadMeta(true)" />
			<NcLoadingIcon v-else class="app-loading" />
		</NcAppContent>

		<NcAppContent v-else
			:page-heading="t('ticketsystem', 'Ticket system')"
			:show-details="selectedId !== null"
			:list-size="32"
			:list-min-width="25"
			@update:show-details="closeTicket">
			<template #list>
				<TicketList v-if="meta"
					:tickets="tickets"
					:title="currentViewName"
					:total="total"
					:loading="loadingList"
					:selected-id="selectedId"
					:meta="meta"
					:filter="filter"
					:filter-dirty="filterDirty"
					:view-filter="viewFilter"
					@update:filter="setFilter"
					@reset-filter="setFilter(null)"
					@changed="refresh" />
				<NcLoadingIcon v-else class="app-loading" />
			</template>

			<TicketDetail v-if="selectedId !== null && meta"
				:key="selectedId"
				:ticket-id="selectedId"
				:meta="meta"
				@changed="refresh"
				@tag-created="onTagCreated" />
			<NcEmptyContent v-else
				:name="t('ticketsystem', 'No ticket selected')"
				:description="t('ticketsystem', 'Pick a ticket from the list, or create a new one.')">
				<template #icon>
					<TicketIcon />
				</template>
			</NcEmptyContent>
		</NcAppContent>

		<CannedRepliesModal v-if="showCanned && meta" :meta="meta" @close="showCanned = false" />

		<NcDialog v-if="showShortcuts"
			:name="t('ticketsystem', 'Keyboard shortcuts')"
			size="small"
			@closing="showShortcuts = false">
			<dl class="shortcuts">
				<template v-for="item in shortcuts" :key="item.keys">
					<dt><kbd v-for="key in item.keys.split(' ')" :key="key">{{ key }}</kbd></dt>
					<dd>{{ item.label }}</dd>
				</template>
			</dl>
		</NcDialog>

		<NewTicketModal v-if="showNewTicket && meta"
			:meta="meta"
			@close="showNewTicket = false"
			@created="onCreated"
			@tag-created="onTagCreated" />
	</NcContent>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { loadState } from '@nextcloud/initial-state'
import { t } from '@nextcloud/l10n'
import { showError } from '@nextcloud/dialogs'
import NcAppContent from '@nextcloud/vue/components/NcAppContent'
import NcAppNavigation from '@nextcloud/vue/components/NcAppNavigation'
import NcAppNavigationItem from '@nextcloud/vue/components/NcAppNavigationItem'
import NcAppNavigationNew from '@nextcloud/vue/components/NcAppNavigationNew'
import NcAppNavigationSearch from '@nextcloud/vue/components/NcAppNavigationSearch'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcContent from '@nextcloud/vue/components/NcContent'
import NcCounterBubble from '@nextcloud/vue/components/NcCounterBubble'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import { generateUrl } from '@nextcloud/router'
import AccountIcon from 'vue-material-design-icons/Account.vue'
import AccountQuestionIcon from 'vue-material-design-icons/AccountQuestion.vue'
import AlarmSnoozeIcon from 'vue-material-design-icons/AlarmSnooze.vue'
import ArchiveIcon from 'vue-material-design-icons/ArchiveOutline.vue'
import WaitingIcon from 'vue-material-design-icons/AccountClockOutline.vue'
import ChartIcon from 'vue-material-design-icons/ChartBar.vue'
import ClockIcon from 'vue-material-design-icons/ClockOutline.vue'
import InboxIcon from 'vue-material-design-icons/InboxMultiple.vue'
import KeyboardIcon from 'vue-material-design-icons/KeyboardOutline.vue'
import MessageAlertIcon from 'vue-material-design-icons/MessageAlertOutline.vue'
import PlusIcon from 'vue-material-design-icons/Plus.vue'
import TextBoxIcon from 'vue-material-design-icons/TextBoxOutline.vue'
import TicketIcon from 'vue-material-design-icons/TicketOutline.vue'
import ViewListIcon from 'vue-material-design-icons/ViewList.vue'
import CannedRepliesModal from './components/CannedRepliesModal.vue'
import NewTicketModal from './components/NewTicketModal.vue'
import ReportsPage from './components/ReportsPage.vue'
import TicketDetail from './components/TicketDetail.vue'
import TimePage from './components/TimePage.vue'
import TicketList from './components/TicketList.vue'
import { api, errorMessage } from './api.js'
import { normalizeFilter, sameFilter } from './format.js'

const isAgent = loadState('ticketsystem', 'is-agent', false)
const route = useRoute()
const router = useRouter()

const viewIcons = {
	mine: AccountIcon,
	'needs-reply': MessageAlertIcon,
	unassigned: AccountQuestionIcon,
	waiting: WaitingIcon,
	open: InboxIcon,
	snoozed: AlarmSnoozeIcon,
	closed: ArchiveIcon,
	all: ViewListIcon,
}

const meta = ref(null)
const tickets = ref([])
const total = ref(0)
const loadingList = ref(false)
const query = ref('')
const showNewTicket = ref(false)
const showCanned = ref(false)
const showShortcuts = ref(false)

const shortcuts = [
	{ keys: 'j', label: t('ticketsystem', 'Next ticket in the list') },
	{ keys: 'k', label: t('ticketsystem', 'Previous ticket in the list') },
	{ keys: 'r', label: t('ticketsystem', 'Reply to the customer') },
	{ keys: 'n', label: t('ticketsystem', 'Write an internal note') },
	{ keys: 'a', label: t('ticketsystem', 'Assign the open ticket to yourself') },
	{ keys: '@', label: t('ticketsystem', 'Mention a colleague in a note') },
	{ keys: 'Ctrl Enter', label: t('ticketsystem', 'Send the reply or note') },
	{ keys: 'Esc', label: t('ticketsystem', 'Leave the text box') },
	{ keys: '/', label: t('ticketsystem', 'Search tickets') },
	{ keys: '?', label: t('ticketsystem', 'Show these shortcuts') },
]

const views = computed(() => meta.value?.views ?? [])
const builtInViews = computed(() => views.value.filter(v => v.builtIn))
const viewId = computed(() => typeof route.query.view === 'string' && route.query.view ? route.query.view : 'open')
const currentView = computed(() => views.value.find(v => v.id === viewId.value) ?? null)
const currentViewName = computed(() => currentView.value?.name ?? '')
const selectedId = computed(() => route.name === 'ticket' ? Number(route.params.id) : null)
const counts = computed(() => meta.value?.counts ?? {})
// Shown to admins until the company is saved once and the support inbox is on.
const setupMissing = computed(() => {
	const setup = meta.value?.setup
	if (!setup) {
		return []
	}
	return [
		...(setup.company ? [] : [t('ticketsystem', 'Check your company name, agents and signature')]),
		...(setup.inbox ? [] : [t('ticketsystem', 'Connect the support inbox, so emails become tickets')]),
	]
})
const adminUrl = generateUrl('/settings/admin/ticketsystem')
const viewHref = (id) => router.resolve({ name: 'list', query: { view: id } }).href

/** A changed filter lives in the address bar, so reloads and shared links keep it. */
const routeFilter = computed(() => {
	if (typeof route.query.filter !== 'string') {
		return null
	}
	try {
		return normalizeFilter(JSON.parse(route.query.filter))
	} catch {
		return null
	}
})
const viewFilter = computed(() => normalizeFilter(currentView.value?.filter))
const filter = computed(() => routeFilter.value ?? viewFilter.value)
const filterDirty = computed(() => routeFilter.value !== null && !sameFilter(routeFilter.value, viewFilter.value))

function openView(id) {
	router.push({ name: 'list', query: { view: id } })
}

function setFilter(next) {
	const queryParams = { ...route.query }
	if (next === null || sameFilter(next, viewFilter.value)) {
		delete queryParams.filter
	} else {
		queryParams.filter = JSON.stringify(normalizeFilter(next))
	}
	router.push({ name: route.name, params: route.params, query: queryParams })
}

let requestNo = 0
/** @param {boolean} quiet background refresh: no spinner, no error toast */
async function loadList(quiet = false) {
	if (!meta.value) {
		return
	}
	const mine = ++requestNo
	if (!quiet) {
		loadingList.value = true
	}
	try {
		const params = { view: viewId.value, q: query.value || undefined, limit: 100 }
		if (filterDirty.value) {
			params.filter = JSON.stringify(filter.value)
		}
		const result = await api.listTickets(params)
		if (mine === requestNo) {
			tickets.value = result.tickets
			total.value = result.total
		}
	} catch (error) {
		if (!quiet) {
			if (error?.response?.status === 404 && viewId.value !== 'open') {
				// A view that does not exist.
				openView('open')
			} else {
				showError(errorMessage(error, t('ticketsystem', 'Could not load tickets')))
			}
		}
	} finally {
		if (mine === requestNo) {
			loadingList.value = false
		}
	}
}

async function loadMeta(quiet = false) {
	try {
		meta.value = await api.meta()
	} catch (error) {
		if (!quiet) {
			showError(errorMessage(error, t('ticketsystem', 'Could not load ticketsystem settings')))
		}
	}
}

async function refresh(quiet = false) {
	await loadMeta(quiet === true)
	await loadList(quiet === true)
}

function onTagCreated(tag) {
	if (meta.value && !meta.value.tags.some(existing => existing.id === tag.id)) {
		meta.value.tags = [...meta.value.tags, tag].sort((a, b) => a.name.localeCompare(b.name))
	}
}

// --- Navigation and shortcuts ------------------------------------------------

function closeTicket(show) {
	if (!show) {
		router.push({ name: 'list', query: route.query })
	}
}

function onCreated(ticket) {
	showNewTicket.value = false
	refresh()
	router.push({ name: 'ticket', params: { id: ticket.id }, query: route.query })
}

// New emails and colleagues' changes show up without reloading the page.
const REFRESH_EVERY = 30 * 1000
let refreshTimer
function onVisibility() {
	if (document.visibilityState === 'visible') {
		refresh(true)
	}
}

/** Opens the ticket before (-1) or after (+1) the selected one in the list. */
function moveSelection(step) {
	if (tickets.value.length === 0) {
		return
	}
	const index = tickets.value.findIndex(ticket => ticket.id === selectedId.value)
	const next = index === -1
		? (step > 0 ? 0 : tickets.value.length - 1)
		: Math.min(Math.max(index + step, 0), tickets.value.length - 1)
	router.push({ name: 'ticket', params: { id: tickets.value[next].id }, query: route.query })
}

function isTyping(target) {
	return target instanceof HTMLElement
		&& (target.isContentEditable || ['INPUT', 'TEXTAREA', 'SELECT'].includes(target.tagName))
}

function onKeydown(event) {
	if (event.ctrlKey || event.metaKey || event.altKey || event.defaultPrevented
		|| isTyping(event.target) || document.querySelector('.modal-mask, [role="dialog"]')) {
		return
	}
	if (event.key === 'j') {
		moveSelection(1)
	} else if (event.key === 'k') {
		moveSelection(-1)
	} else if (event.key === '/') {
		document.querySelector('.app-navigation input[type="search"], .app-navigation input')?.focus()
	} else if (event.key === '?') {
		showShortcuts.value = true
	} else {
		return
	}
	event.preventDefault()
}

let searchTimer
watch(query, () => {
	clearTimeout(searchTimer)
	searchTimer = setTimeout(() => loadList(), 300)
})
watch([viewId, () => route.query.filter], () => loadList())

onMounted(async () => {
	if (isAgent) {
		await refresh()
		refreshTimer = setInterval(() => {
			if (document.visibilityState === 'visible') {
				refresh(true)
			}
		}, REFRESH_EVERY)
		document.addEventListener('visibilitychange', onVisibility)
		document.addEventListener('keydown', onKeydown)
	}
})

onBeforeUnmount(() => {
	clearInterval(refreshTimer)
	document.removeEventListener('visibilitychange', onVisibility)
	document.removeEventListener('keydown', onKeydown)
})
</script>

<style scoped lang="scss">
.app-footer {
	padding: var(--default-grid-baseline);
}

.app-setup {
	list-style: none;
	padding: 0 calc(var(--default-grid-baseline) * 2);

	&__list {
		margin: 4px 0 8px;
		padding-inline-start: 16px;
		list-style: disc;
	}
}

.app-loading {
	margin-top: 30vh;
}

.shortcuts {
	display: grid;
	grid-template-columns: auto 1fr;
	gap: 8px 16px;
	align-items: center;
	padding-bottom: 12px;

	dt {
		white-space: nowrap;
	}

	kbd {
		display: inline-block;
		min-width: 24px;
		margin-inline-end: 4px;
		padding: 1px 6px;
		border: 1px solid var(--color-border-dark);
		border-bottom-width: 2px;
		border-radius: var(--border-radius);
		text-align: center;
		font-family: inherit;
	}
}
</style>
