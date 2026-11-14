# Ticket system for Nextcloud

Customer support tickets inside your own Nextcloud, for a small business that
has outgrown a shared Outlook inbox. Customers keep writing email; your team
answers from Nextcloud, logs the time and invoices it.

Its companion app is [Helpdesk](https://github.com/IT-optimize/nextcloud-helpdesk):
a small app where everyone in a Nextcloud asks their IT provider for help. Its
requests become tickets in Ticket system for the person who wrote them.

- **Email in and out**: the support inbox becomes tickets, and customer replies
  land on the right ticket. Replies thread properly in Gmail, Outlook and Apple
  Mail, with attachments, CC and Danish letters intact. Bounces are noticed.
- **Replies and notes**: public replies are emailed to the customer; internal
  notes never leave the team. `@name` in a note notifies a colleague. Files can
  be attached by button, drag and drop or by pasting a screenshot (up to 20 MB
  per reply).
- **Automatic confirmation**: a new email request gets an answer with its ticket
  reference and a link to the customer's portal page.
- **Canned replies**, shared or personal, with placeholders such as
  `{customer_first_name}` and `{ticket_ref}`.
- **Customers and companies**: companies are recognised by email domain, with
  their own hourly rate and notes.
- **Time and invoicing**: log time on a ticket (or run a timer), billable or not.
  The Time page totals a period per company, agent or ticket, downloads a CSV for
  the invoice and marks the time as invoiced.
- **Keeping order**: status, priority, tags and assignee; the views Assigned to
  me, Needs reply, Unassigned, Waiting for customer, All open, Snoozed, Resolved &
  closed and All tickets; search and filters. Snooze a ticket until a date.
- **Send later**: write a reply now and schedule it.
- **Customer portal**: every email links to a private page where the customer
  follows the ticket, answers (with files) or marks it solved.
- **One simple report**: new, open and resolved tickets, reply and resolution
  times, tickets per day and per agent.
- **Customer data (GDPR)**: look up, download or erase what Ticket system holds about
  an email address, and erase old tickets automatically.
- **Working as a team**: "Carl is writing on this ticket" warnings, unsent drafts
  kept per ticket, Send & resolve, keyboard shortcuts (`?` lists them).
- **Fits into Nextcloud**: notifications, the Activity stream, a Dashboard
  widget, unified search, and a ticket card when a ticket link is pasted in Talk
  or Text.
- **Danish and English**: agents get their Nextcloud language; customers get
  the "Language for customers" setting (Danish by default).

Targets **Nextcloud 34–35** (developed and tested on 35.0.2).

## Installing

Download the latest `ticketsystem-x.y.z.tar.gz` from the
[releases](https://github.com/IT-optimize/nextcloud-ticketsystem/releases),
unpack it into your Nextcloud's `custom_apps` (or `apps`) folder so that
`custom_apps/ticketsystem/appinfo/info.xml` exists, and enable it:

```sh
occ app:enable ticketsystem
```

After updating to a new version, run `occ upgrade`.

## Setting it up

Everything is under Administration settings → Ticket system:

- **Company**: your company's name as customers see it, the Nextcloud groups
  whose members work on tickets (admins always can), the hourly rate and
  currency for invoicing, the colour in customer emails and the signature under
  every reply.
- **Email**: the support inbox (IMAP, with **Test connection**), whether replies
  and confirmations are emailed, the customer portal, the language for
  customers, the sender name and the reply-to address.
- **Statuses**: rename the statuses and priorities, or add your own.
- **Tags**: names and colours.
- **Customer data**: see below.

## Canned replies

Pick one from the *Canned reply* box above the reply field (it searches titles
and text), manage them under **Canned replies** at the bottom of the navigation,
or use **Save as canned reply** under the reply box. A reply is shared or
personal. Placeholders are filled in on insert: `{customer_name}`,
`{customer_first_name}`, `{customer_email}`, `{ticket_ref}`, `{ticket_subject}`,
`{agent_name}`, `{agent_first_name}`, `{company}`. The most used replies come first.

## Time, companies and invoicing

- **Log time** in the Time card on a ticket: type `30`, `1:15` or `1h 15m`, or
  start the timer and stop it when you're done. Each entry is billable or not and
  can have a note and a date. You can change or delete your own time until it is
  invoiced; admins can change anyone's.
- **Prices**: an entry is priced at the ticket company's hourly rate, otherwise
  the rate on the Company tab. The rate is stored with the entry, so changing a
  rate later doesn't change old work.
- **Companies** (Time and invoicing → Companies): name, email domains and an
  optional hourly rate. New tickets from those domains are linked automatically,
  and adding a domain links the existing tickets too. Agents can pick another
  company on a ticket. Only admins change rates and delete companies.
- **Time and invoicing** totals a period by company, agent or ticket.
  **Download (CSV)** gives one row per entry, in your number format (Danish Excel
  opens it directly). **Mark as invoiced** (admins) locks the billable entries
  shown, so the next invoice only has new work.

## Notifications

- A customer's reply notifies the assignee, or every agent when nobody is
  assigned. The same goes for a snoozed ticket that wakes up.
- A colleague's reply or note on your ticket notifies you.
- `@alice` in a reply or note notifies Alice, if she works on tickets.
- Opening a ticket clears its notifications.

## Customer data (GDPR)

Under Administration settings → Ticket system → **Customer data** (admins only):

- **Look up** an email address: the customer's tickets, and how many other
  tickets copy them or carry their messages.
- **Download their data (JSON)**: their tickets with the public conversation
  and file names, and what they wrote on other tickets (for access requests).
  Internal notes are the team's working notes and stay out.
- **Erase**: their tickets are anonymised, not deleted. Address, name,
  subjects, every message text and the files go, the portal link stops working
  and an unfinished ticket is closed. Status, assignee, times and logged time
  stay, so reports and invoices still add up; time entry notes are kept for
  invoicing. Their messages on other tickets are blanked and they leave every
  CC list. The admin types the address again to confirm.
- **Erase old customer data automatically**: tickets done for longer than N
  months are erased the same way, once a day.
- Data is found by email address; an address mentioned only inside some text
  is not found. Mail already sent (and the support mailbox itself) is outside
  Ticket system.

## How email intake decides

- Every email Ticket system sends carries its own Message-ID and points to the
  customer's previous email, so the customer's mail program shows the ticket as
  one conversation.
- An email joins an existing ticket when it **references a message already in
  that ticket or any email Ticket system sent about it** (normal email threading; a
  reply works even if the customer deleted `[#42]` from the subject). Ticket system's
  Message-IDs are signed with the server's secret, so they can't be made up for
  someone else's ticket. Otherwise it joins when its subject has the ticket tag
  `[#42]` **and** the sender is that ticket's customer or from the same company
  domain. Everything else becomes a new ticket, so a guessed number can't be used
  to write into someone else's ticket. Free-mail domains (gmail.com, hotmail.com, …)
  never count as "same company".
- **CC**: people on To or Cc of a new email (except Ticket system's own addresses)
  are copied on the ticket: they get the replies, not the automatic
  confirmation. When the customer or a copied person answers and copies someone
  new, that person is added; anyone else answering can't widen the list. Agents
  see and change it in the Customer card (at most 10).
- Quoted history and signatures are cut off: signatures after "-- " or from a
  sign-off line ("Venlig hilsen", "Mvh", "Best regards" …) near the end, and a
  picture in the signature is saved only the first time a sender sends it.
  Ticket system's own emails start with a "reply above this line" marker, and the
  usual Gmail/Outlook/Apple Mail quote styles are recognised, including Danish
  ("Den … skrev:", "Fra:/Sendt:").
- **Bounces** of Ticket system's own emails are matched to the email that bounced: a
  reply the customer did not get shows "Email not delivered" with the reason, and
  its author and the assignee are notified. A bounced CC copy or confirmation is
  noted in the ticket history.
- **Ignored, not imported**: auto-replies (out of office), bounces, mailing lists
  and newsletters, emails from the support address itself, and duplicates.
- Ticket system remembers how far it has read the inbox, so mail someone already
  opened in a mail program is imported too. A newly connected inbox starts with
  its unread mail; older mail is not imported.
- Processed emails are marked as read and, if configured, moved to a folder.
  An email that fails to import stays in the inbox, read and **flagged**.
  Emails over 25 MB are not imported.
- **Attachments** of imported emails are saved on the message (pictures show a
  preview). Files are kept in Nextcloud's app data, not in anyone's Files;
  agents can **Save in my Files**. Only agents, and the customer through their
  portal link (files on public messages only), can download them, and files are
  always downloaded, never opened as web pages.
- **No mail loops**: the automatic confirmation carries an `Auto-Submitted`
  header, so well-behaved auto-responders ignore it. And a sender who opens more
  than 3 tickets by email within an hour gets no further confirmations; this is
  logged as a possible mail loop.

## Running it for real

- **Cron**: email intake runs as a Nextcloud background job, so set Background
  jobs to *Cron* (system cron every 5 minutes), not AJAX.
- **`overwrite.cli.url`** must be your public Nextcloud URL, or portal links in
  emails sent by cron point to the wrong place.
- **IMAP login**: user name + password over SSL/TLS (993) or STARTTLS (143). The
  password is stored encrypted and never sent back to the browser. Providers that
  only allow OAuth (Microsoft 365 with basic auth off, Google Workspace without
  app passwords) are **not supported yet**; use an app password or a mailbox at
  a provider that allows IMAP logins.
- **Outgoing mail** uses Nextcloud's own email server settings (Administration
  settings → Basic settings). Set the reply-to address on the Email tab to the
  support inbox, so customer answers come back into Ticket system.
- The portal link is a secret: anyone with it can read the public part of that
  ticket and answer. Wrong links are rate limited by Nextcloud's brute-force
  protection. The portal can be turned off on the Email tab.

## Working on the code

- PHP is in `lib/`, the interface (Vue 3 + @nextcloud/vue) in `src/`. `js/` holds
  the built interface and is committed, so the app works without a build.
- Build the interface: `npm ci && npm run build` (`npm run watch` while developing).
- A schema change is a new migration in `lib/Migration/`; bump `<version>` in
  `appinfo/info.xml` so Nextcloud runs it.
- **Tests**: PHPUnit tests (email parsing, quote stripping, notifications, time,
  privacy and more) are in `tests/Unit`, with sample emails in `tests/fixtures/`.
  JavaScript tests are in `tests/js`. GitHub runs both on every push.
  ```sh
  composer install && composer test    # PHP
  npm ci && npm test                   # JavaScript
  ```
- `scripts/release.sh` builds the release archive (production build, tests, only
  the files Nextcloud needs).
- **Add-ins** can react to every ticket change: see [EXTENDING.md](EXTENDING.md).
- Security problems: see [SECURITY.md](SECURITY.md).

## What's in the app

```
.
  appinfo/info.xml            app metadata, version range, background jobs, occ command
  lib/
    Db/                       entities and mappers
    Migration/                database schema
    Service/TicketService     the one place tickets change; dispatches all events
    Service/MailIntakeService reads the support inbox, threads replies, makes tickets
    Service/CustomerMailer    all mail to customers, records delivery status
    Service/BounceService     matches bounces to the email that bounced
    Service/SettingsService   statuses, priorities, agents, mail and inbox settings
    Service/TeamService       the company (stored as a brand) and who works on it
    Service/AgentService      who counts as an agent
    Service/CannedReplyService canned replies (shared and personal)
    Service/TimeService, CompanyService     time registration, pricing, companies
    Service/ScheduleService   replies sent later (SendScheduledJob sends them)
    Service/ReportService     the report
    Service/PrivacyService    customer data lookup, export and erasure
    Service/AttachmentService files on messages (app data), downloads, cleanup
    Mail/                     IMAP client, MIME parser, quote/signature stripping
    Event/                    public events for add-ins (see EXTENDING.md)
    Listener/                 notifications, customer emails and ticket history, built on those events
    Controller/               agent pages + OCS API, customer portal, admin settings API
    BackgroundJob/, Command/  inbox check, scheduled replies, snooze, retention; `occ ticketsystem:fetch-mail`
    Activity/, Dashboard/, Search/, Reference/   Activity stream, Dashboard widget, unified search, ticket cards
    Notification/Notifier     renders notifications
  src/                        Vue 3 + @nextcloud/vue: agent UI, admin settings, customer portal
  js/                         built frontend (committed so the app works without a build)
  tests/                      PHPUnit and JavaScript tests, sample emails
```

### Agent API

All under `/ocs/v2.php/apps/ticketsystem/api/v1`, for agents only (send the
`OCS-APIRequest: true` header; app passwords work for scripts):

| Method | Path | |
|---|---|---|
| GET | `/meta` | statuses, priorities, the company, companies, agents, tags, views and their counts |
| GET | `/tickets?view=…&q=&filter=` | list and search; `view` is `mine`, `needs-reply`, `unassigned`, `waiting`, `open`, `snoozed`, `closed` or `all`; `filter` is a JSON filter (see `ViewService`) |
| POST | `/tickets` | create (`subject`, `body`, `customerEmail`, `customerName`, `cc`, `priority`, `assigneeUid`, `tagIds`) |
| GET | `/tickets/{id}` | ticket with its messages, tags, the customer's other tickets, pending scheduled replies and the customer's `portalUrl` |
| PUT | `/tickets/{id}` | change `status`, `priority`, `assigneeUid`, `subject`, customer fields, `companyId`, `cc` (the full list), `snoozedUntil`, `tagIds` |
| POST | `/tickets/{id}/messages` | `body` + `visibility` = `public` (emailed) or `internal`, optional `attachmentIds` |
| POST | `/tickets/{id}/messages/{messageId}/resend` | email a public reply again |
| POST | `/tickets/{id}/attachments` | upload one file (form field `file`, at most 20 MB) for a message being written; unsent uploads are deleted after a day |
| POST | `/attachments/{id}/save` | copy a ticket's file into your own Files (folder "Ticket system"); files download at `/apps/ticketsystem/attachments/{id}` |
| POST/PUT/DELETE | `/tickets/{id}/scheduled[/{replyId}]` | schedule (`body`, `sendAt` unix time, `thenStatus`, `attachmentIds`), move or cancel a reply; `POST …/{replyId}/send` sends it now |
| POST | `/tickets/{id}/presence` | "I'm viewing / writing on this ticket", for the warnings colleagues see |
| GET/POST | `/canned` | list / create canned replies (`title`, `body`, `shared`) |
| PUT/DELETE | `/canned/{id}` | edit / delete one; `POST /canned/{id}/used` counts a use |
| POST | `/tags` | create a tag (agents); `PUT/DELETE /tags/{id}` for admins |
| GET | `/tickets/{id}/time` | time on a ticket, with the current rate |
| POST/PUT/DELETE | `/tickets/{id}/time[/{entryId}]` | log / change / delete time (`minutes`, `billable`, `note`, `workDate`) |
| GET | `/time?from=&to=&companyId=&uid=&billable=&invoiced=` | time in a period with ticket and company; `companyId=none` for tickets without a company |
| POST | `/time/invoiced` | admin only: mark billable entries (`ids`) as invoiced |
| GET/POST/PUT/DELETE | `/companies[/{id}]` | companies (`name`, `domains`, `hourlyRate` in øre/cents, `notes`); rates and deleting for admins |
| GET | `/reports?from=&to=&tz=` | the report's numbers |
| GET/PUT | `/settings` | admin only |
| PUT | `/teams/{id}` | admin only: the company (`name`, `agentGroups`, `hourlyRate`, `currency`, `color`, `signature`) |
| POST | `/settings/test-imap`, `/settings/fetch-mail` | admin only: test the support inbox, import from it now |
| GET | `/privacy?email=`, `/privacy/export?email=` | admin only: what Ticket system holds about an address, and its export |
| POST | `/privacy/erase` | admin only: erase an address's data (`email`) |

## Not built yet

- OAuth login for Microsoft 365 / Google inboxes

## Licence

AGPL-3.0-or-later, © Co-Optimize. See [LICENSE](LICENSE).
