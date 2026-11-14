/**
 * SPDX-FileCopyrightText: 2026 Co-Optimize
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import axios from '@nextcloud/axios'
import { generateOcsUrl } from '@nextcloud/router'

const url = (path) => generateOcsUrl('/apps/ticketsystem/api/v1' + path)
const data = (response) => response.data.ocs.data

/** Turns an OCS error into a readable message for toasts. */
export function errorMessage(error, fallback) {
	return error?.response?.data?.ocs?.data?.message || fallback
}

export const api = {
	meta: () => axios.get(url('/meta')).then(data),
	listTickets: (params) => axios.get(url('/tickets'), { params }).then(data),
	getTicket: (id) => axios.get(url(`/tickets/${id}`)).then(data),
	createTicket: (ticket) => axios.post(url('/tickets'), ticket).then(data),
	updateTicket: (id, fields) => axios.put(url(`/tickets/${id}`), fields).then(data),
	addMessage: (id, body, visibility, attachmentIds = []) => axios.post(url(`/tickets/${id}/messages`), { body, visibility, attachmentIds }).then(data),
	uploadAttachment: (id, file, onProgress) => {
		const form = new FormData()
		form.append('file', file)
		return axios.post(url(`/tickets/${id}/attachments`), form, {
			onUploadProgress: (e) => onProgress?.(e.total ? e.loaded / e.total : 0),
		}).then(data)
	},
	saveAttachment: (attachmentId) => axios.post(url(`/attachments/${attachmentId}/save`)).then(data),
	presence: (id, state) => axios.post(url(`/tickets/${id}/presence`), { state }).then(data),
	resendMessage: (id, messageId) => axios.post(url(`/tickets/${id}/messages/${messageId}/resend`)).then(data),
	createTag: (name) => axios.post(url('/tags'), { name }).then(data),
	report: (params) => axios.get(url('/reports'), { params }).then(data),
	listCanned: () => axios.get(url('/canned')).then(data),
	createCanned: (reply) => axios.post(url('/canned'), reply).then(data),
	updateCanned: (id, reply) => axios.put(url(`/canned/${id}`), reply).then(data),
	deleteCanned: (id) => axios.delete(url(`/canned/${id}`)).then(data),
	cannedUsed: (id) => axios.post(url(`/canned/${id}/used`)).then(data),
	getSettings: () => axios.get(url('/settings')).then(data),
	privacySummary: (email) => axios.get(url('/privacy'), { params: { email } }).then(data),
	privacyExport: (email) => axios.get(url('/privacy/export'), { params: { email } }).then(data),
	privacyErase: (email) => axios.post(url('/privacy/erase'), { email }).then(data),
	saveSettings: (settings) => axios.put(url('/settings'), settings).then(data),
	testImap: (settings) => axios.post(url('/settings/test-imap'), settings).then(data),
	fetchMail: () => axios.post(url('/settings/fetch-mail')).then(data),
	updateTeam: (id, team) => axios.put(url(`/teams/${id}`), team).then(data),
	updateTag: (id, tag) => axios.put(url(`/tags/${id}`), tag).then(data),
	deleteTag: (id) => axios.delete(url(`/tags/${id}`)).then(data),

	ticketTime: (id) => axios.get(url(`/tickets/${id}/time`)).then(data),
	addTime: (id, entry) => axios.post(url(`/tickets/${id}/time`), entry).then(data),
	updateTime: (id, entryId, entry) => axios.put(url(`/tickets/${id}/time/${entryId}`), entry).then(data),
	deleteTime: (id, entryId) => axios.delete(url(`/tickets/${id}/time/${entryId}`)).then(data),
	timeOverview: (params) => axios.get(url('/time'), { params }).then(data),
	markInvoiced: (ids) => axios.post(url('/time/invoiced'), { ids }).then(data),
	listCompanies: () => axios.get(url('/companies')).then(data),
	createCompany: (company) => axios.post(url('/companies'), company).then(data),
	updateCompany: (id, company) => axios.put(url(`/companies/${id}`), company).then(data),
	deleteCompany: (id) => axios.delete(url(`/companies/${id}`)).then(data),
	scheduleReply: (id, body, sendAt, thenStatus, attachmentIds = []) => axios.post(url(`/tickets/${id}/scheduled`), { body, sendAt, thenStatus, attachmentIds }).then(data),
	updateScheduled: (id, replyId, changes) => axios.put(url(`/tickets/${id}/scheduled/${replyId}`), changes).then(data),
	cancelScheduled: (id, replyId) => axios.delete(url(`/tickets/${id}/scheduled/${replyId}`)).then(data),
	sendScheduledNow: (id, replyId) => axios.post(url(`/tickets/${id}/scheduled/${replyId}/send`)).then(data),
}
