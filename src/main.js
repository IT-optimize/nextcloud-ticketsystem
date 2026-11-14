/**
 * SPDX-FileCopyrightText: 2026 Co-Optimize
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import '@nextcloud/dialogs/style.css'
import { createApp } from 'vue'
import { createRouter, createWebHistory } from 'vue-router'
import { generateUrl } from '@nextcloud/router'
import App from './App.vue'

// URLs: /apps/ticketsystem/?view=mine and /apps/ticketsystem/tickets/42?view=mine
// Both are served by PageController, so deep links and reloads work.
const router = createRouter({
	history: createWebHistory(generateUrl('/apps/ticketsystem')),
	routes: [
		{ path: '/', name: 'list', component: { render: () => null } },
		{ path: '/tickets/:id(\\d+)', name: 'ticket', component: { render: () => null } },
		{ path: '/reports', name: 'reports', component: { render: () => null } },
		{ path: '/time', name: 'time', component: { render: () => null } },
		{ path: '/:pathMatch(.*)*', redirect: '/' },
	],
})

createApp(App).use(router).mount('#ticketsystem-app')
