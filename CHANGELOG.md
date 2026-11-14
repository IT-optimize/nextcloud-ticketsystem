# Changelog

All notable changes to Ticket system (app id `ticketsystem`). The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and versions follow
[Semantic Versioning](https://semver.org/).

## 1.0.0 – Unreleased

First public release.

### Added
- Email in and out: the support inbox (IMAP) becomes tickets, customer replies join the right ticket, and replies thread properly in Gmail, Outlook and Apple Mail, with attachments and CC. Bounces are matched to the email that bounced; auto-replies and newsletters are ignored.
- Public replies emailed to the customer and internal notes that never leave the team, with @mentions and files (button, drag and drop, pasted screenshots).
- An automatic confirmation for new email requests, with the ticket reference and a link to the customer's portal page.
- Customer portal: a private page per ticket where the customer follows it, answers (with files) or marks it solved.
- Canned replies, shared or personal, with placeholders.
- Customers and companies: companies are recognised by email domain and can have their own hourly rate.
- Time and invoicing: log time on tickets (or run a timer), billable or not; totals per company, agent or ticket, CSV download and "mark as invoiced".
- Status, priority, tags and assignee; the views Assigned to me, Needs reply, Unassigned, Waiting for customer, All open, Snoozed, Resolved & closed and All tickets; search and filters; snooze.
- Replies scheduled for later.
- One report: new, open and resolved tickets, reply and resolution times, tickets per day and per agent.
- Customer data (GDPR): look up, download or erase what is held about an email address, and optional automatic erasure of old tickets.
- Notifications, Activity stream, a Dashboard widget, unified search and ticket cards where a ticket link is pasted.
- A new install names the company after the Nextcloud (its Theming name, else the mail domain), and admins see "Finish the setup" until the company is saved and the support inbox is connected.
- Requests from the [Helpdesk](https://github.com/IT-optimize/nextcloud-helpdesk) app become tickets for the person who wrote them, and messages written there afterwards join the same ticket. Reply-To never decides which ticket an email joins: that takes the real sender or the thread it answers.
- Events for every ticket change, so add-in apps can extend Ticket system (see EXTENDING.md).
- Danish and English.
