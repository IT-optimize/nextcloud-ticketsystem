/**
 * SPDX-FileCopyrightText: 2026 Co-Optimize
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import '@nextcloud/dialogs/style.css'
import { createApp } from 'vue'
import AdminSettings from './components/AdminSettings.vue'

createApp(AdminSettings).mount('#ticketsystem-admin')
