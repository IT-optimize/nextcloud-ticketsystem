/**
 * SPDX-FileCopyrightText: 2026 Co-Optimize
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import '@nextcloud/dialogs/style.css'
import './public.css'
import { createApp } from 'vue'
import CustomerPortal from './components/CustomerPortal.vue'

createApp(CustomerPortal).mount('#ticketsystem-portal')
