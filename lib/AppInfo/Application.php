<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Co-Optimize
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\TicketSystem\AppInfo;

use OCA\TicketSystem\Activity\ActivityListener;
use OCA\TicketSystem\Dashboard\MyTicketsWidget;
use OCA\TicketSystem\Event\MessageAddedEvent;
use OCA\TicketSystem\Event\TicketAssignedEvent;
use OCA\TicketSystem\Event\TicketCreatedEvent;
use OCA\TicketSystem\Event\TicketUpdatedEvent;
use OCA\TicketSystem\Listener\CustomerMailListener;
use OCA\TicketSystem\Listener\HistoryListener;
use OCA\TicketSystem\Listener\NotificationListener;
use OCA\TicketSystem\Mail\ThreadIds;
use OCA\TicketSystem\Notification\Notifier;
use OCA\TicketSystem\Reference\TicketReferenceProvider;
use OCA\TicketSystem\Search\TicketSearchProvider;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\IConfig;
use Psr\Container\ContainerInterface;

class Application extends App implements IBootstrap {
	public const APP_ID = 'ticketsystem';

	public function __construct(array $urlParams = []) {
		parent::__construct(self::APP_ID, $urlParams);
	}

	public function register(IRegistrationContext $context): void {
		// Ticket system uses its own events internally, exactly like an add-in would.
		$context->registerEventListener(TicketAssignedEvent::class, NotificationListener::class);
		$context->registerEventListener(MessageAddedEvent::class, NotificationListener::class);
		$context->registerEventListener(MessageAddedEvent::class, CustomerMailListener::class);
		$context->registerEventListener(TicketCreatedEvent::class, CustomerMailListener::class);
		$context->registerEventListener(TicketUpdatedEvent::class, HistoryListener::class);
		foreach ([TicketCreatedEvent::class, TicketAssignedEvent::class, TicketUpdatedEvent::class, MessageAddedEvent::class] as $event) {
			$context->registerEventListener($event, ActivityListener::class);
		}

		$context->registerService(ThreadIds::class, fn (ContainerInterface $c) => new ThreadIds($c->get(IConfig::class)->getSystemValueString('secret')));

		$context->registerNotifierService(Notifier::class);
		$context->registerDashboardWidget(MyTicketsWidget::class);
		$context->registerSearchProvider(TicketSearchProvider::class);
		$context->registerReferenceProvider(TicketReferenceProvider::class);
	}

	public function boot(IBootContext $context): void {
	}
}
