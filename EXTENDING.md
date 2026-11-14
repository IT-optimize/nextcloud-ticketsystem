# Writing a Ticket system add-in

Add-ins are small, separate Nextcloud apps. They react to Ticket system events and
change tickets through `TicketService`, so the Ticket system core never needs to
change for them. Ticket system's own notifications and customer emails are built
exactly this way (see `lib/Listener/`).

## Events

All in `OCA\TicketSystem\Event`, all extending `AbstractTicketEvent`
(`getTicket()`, `getActorUid()` — null for customer or system actions):

| Event | When | Extra |
|---|---|---|
| `TicketCreatedEvent` | a ticket was stored | |
| `TicketUpdatedEvent` | fields changed (status, priority, assignee, subject, customer …) | `getChanges()` → `['status' => ['old' => …, 'new' => …], …]`, `hasChanged('status')` |
| `TicketAssignedEvent` | assignee changed (also fires `TicketUpdatedEvent`) | `getAssigneeUid()`, `getPreviousAssigneeUid()` |
| `MessageAddedEvent` | a reply or note was added | `getMessage()`; check `->isPublic()` before sharing anything outside the team |

`TicketUpdatedEvent` changes can also include `teamId`, `companyId`, `cc`,
`snoozedUntil` and `tagIds`.

Every ticket belongs to a brand (`getTeamId()`). Ticket system shows one, the
company, but the access rules still apply: use `TicketService::getForAgent()`
and `TeamService::canAccess()` when an add-in shows tickets to users.

Treat the ticket in an event as read-only. To change it, call
`OCA\TicketSystem\Service\TicketService` (`update()`, `addAgentMessage()`, …) so
the change fires its own events.

## Example: post urgent tickets to a Talk room

`appinfo/info.xml` of the add-in declares a dependency on Ticket system being
installed, then in its `Application::register()`:

```php
$context->registerEventListener(TicketCreatedEvent::class, UrgentTicketListener::class);
$context->registerEventListener(TicketUpdatedEvent::class, UrgentTicketListener::class);
```

```php
class UrgentTicketListener implements IEventListener {
	public function handle(Event $event): void {
		if (!$event instanceof AbstractTicketEvent) {
			return;
		}
		$ticket = $event->getTicket();
		$becameUrgent = $event instanceof TicketCreatedEvent
			|| ($event instanceof TicketUpdatedEvent && $event->hasChanged('priority'));
		if ($becameUrgent && $ticket->getPriority() === 'urgent') {
			// post "#42 <subject> is urgent" to the support Talk room
		}
	}
}
```

The same pattern covers Talk or Deck integration, a CRM lookup, webhooks to
other systems, or AI-drafted replies: features Ticket system itself leaves out to
stay simple.

## Outside the Nextcloud process

For tools that run elsewhere (scripts, n8n, another service), use the OCS API
described in README.md with an app password from a dedicated agent account.

All Ticket system events are also webhook-compatible, so Nextcloud's built-in
**webhook_listeners** app can push them as JSON to another system with no
add-in at all. An admin registers one per event:

```sh
curl -u admin:admin -H 'OCS-APIRequest: true' -H 'Content-Type: application/json' \
  -X POST http://localhost:8080/ocs/v2.php/apps/webhook_listeners/api/v1/webhooks \
  -d '{"httpMethod":"POST","uri":"https://example.com/hooks/ticketsystem","event":"OCA\\TicketSystem\\Event\\TicketCreatedEvent"}'
```

The payload contains the ticket, the acting user, and the event's extras
(`changes`, `previousAssigneeUid` or `message`). `MessageAddedEvent` payloads
include internal notes too, so only point webhooks at systems your team trusts.
