<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Co-Optimize
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\TicketSystem\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * The whole Ticket system 1.0 schema in one step.
 *
 * Ticket system starts here: older versions were the Helpdesk app (another
 * app id), so there is nothing to upgrade from.
 *
 * Every message carries a visibility ("public" or "internal"). Anything that
 * reaches the customer (emails, the portal) must only read public ones.
 */
class Version010000Date20261112200000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		// Brands. Ticket system 1.0 shows one, "the company"; the table keeps room for more.
		if (!$schema->hasTable('ticketsystem_teams')) {
			$table = $schema->createTable('ticketsystem_teams');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('name', Types::STRING, ['notnull' => true, 'length' => 100]);
			// JSON list of Nextcloud group ids whose members work on the tickets
			$table->addColumn('agent_groups', Types::TEXT, ['notnull' => false]);
			$table->addColumn('sender_name', Types::STRING, ['notnull' => false, 'length' => 255]);
			$table->addColumn('reply_to', Types::STRING, ['notnull' => false, 'length' => 255]);
			$table->addColumn('signature', Types::TEXT, ['notnull' => false]);
			$table->addColumn('color', Types::STRING, ['notnull' => false, 'length' => 16]);
			$table->addColumn('language', Types::STRING, ['notnull' => false, 'length' => 16]);
			$table->addColumn('sort_order', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			$table->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			// price per hour in minor units (øre, cents)
			$table->addColumn('hourly_rate', Types::INTEGER, ['notnull' => false, 'unsigned' => true]);
			$table->addColumn('currency', Types::STRING, ['notnull' => false, 'length' => 3]);
			// JSON IMAP settings for the brand's own inbox; the password is encrypted
			$table->addColumn('inbox', Types::TEXT, ['notnull' => false]);
			$table->addColumn('inbox_status', Types::STRING, ['notnull' => false, 'length' => 2000]);
			$table->setPrimaryKey(['id']);
		}

		if (!$schema->hasTable('ticketsystem_companies')) {
			$table = $schema->createTable('ticketsystem_companies');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('name', Types::STRING, ['notnull' => true, 'length' => 255]);
			// JSON list of email domains whose senders belong to the company
			$table->addColumn('domains', Types::TEXT, ['notnull' => false]);
			$table->addColumn('hourly_rate', Types::INTEGER, ['notnull' => false, 'unsigned' => true]);
			$table->addColumn('notes', Types::TEXT, ['notnull' => false]);
			$table->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('updated_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->setPrimaryKey(['id']);
		}

		if (!$schema->hasTable('ticketsystem_tickets')) {
			$table = $schema->createTable('ticketsystem_tickets');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('subject', Types::STRING, ['notnull' => true, 'length' => 255]);
			$table->addColumn('status', Types::STRING, ['notnull' => true, 'length' => 32, 'default' => 'new']);
			$table->addColumn('priority', Types::STRING, ['notnull' => true, 'length' => 32, 'default' => 'normal']);
			$table->addColumn('customer_email', Types::STRING, ['notnull' => false, 'length' => 255]);
			$table->addColumn('customer_name', Types::STRING, ['notnull' => false, 'length' => 255]);
			$table->addColumn('assignee_uid', Types::STRING, ['notnull' => false, 'length' => 64]);
			$table->addColumn('created_by', Types::STRING, ['notnull' => false, 'length' => 64]);
			$table->addColumn('channel', Types::STRING, ['notnull' => true, 'length' => 32, 'default' => 'manual']);
			// Secret for the customer portal link. Never exposed to customers by id alone.
			$table->addColumn('access_token', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('updated_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('first_response_at', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
			$table->addColumn('resolved_at', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
			$table->addColumn('team_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
			$table->addColumn('snoozed_until', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
			// set while the customer's message is the newest one, i.e. we owe a reply
			$table->addColumn('waiting_since', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
			$table->addColumn('company_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
			// comma-separated addresses copied on replies
			$table->addColumn('cc', Types::STRING, ['notnull' => false, 'length' => 2000]);
			$table->addColumn('erased_at', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['access_token'], 'ts_tickets_token');
			$table->addIndex(['status'], 'ts_tickets_status');
			$table->addIndex(['assignee_uid', 'status'], 'ts_tickets_assignee');
			$table->addIndex(['customer_email'], 'ts_tickets_customer');
			$table->addIndex(['updated_at'], 'ts_tickets_updated');
			$table->addIndex(['team_id', 'status'], 'ts_tickets_team');
			$table->addIndex(['company_id'], 'ts_tickets_company');
		}

		if (!$schema->hasTable('ticketsystem_messages')) {
			$table = $schema->createTable('ticketsystem_messages');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('ticket_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			// agent | customer | system
			$table->addColumn('author_type', Types::STRING, ['notnull' => true, 'length' => 16]);
			$table->addColumn('author_uid', Types::STRING, ['notnull' => false, 'length' => 64]);
			$table->addColumn('author_email', Types::STRING, ['notnull' => false, 'length' => 255]);
			// public | internal
			$table->addColumn('visibility', Types::STRING, ['notnull' => true, 'length' => 16]);
			$table->addColumn('body', Types::TEXT, ['notnull' => true]);
			// email Message-ID, for threading replies
			$table->addColumn('mail_message_id', Types::STRING, ['notnull' => false, 'length' => 512]);
			$table->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('delivery_status', Types::STRING, ['notnull' => false, 'length' => 16]);
			$table->addColumn('delivery_error', Types::STRING, ['notnull' => false, 'length' => 1000]);
			// sha1 of mail_message_id, for a short index
			$table->addColumn('mail_id_hash', Types::STRING, ['notnull' => false, 'length' => 40]);
			// JSON: history changes, CC, email headers
			$table->addColumn('meta', Types::TEXT, ['notnull' => false]);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['ticket_id', 'created_at'], 'ts_messages_ticket');
			$table->addIndex(['mail_id_hash'], 'ts_messages_mailid');
			$table->addIndex(['author_email'], 'ts_msg_author_email');
		}

		if (!$schema->hasTable('ticketsystem_attachments')) {
			$table = $schema->createTable('ticketsystem_attachments');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('ticket_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('message_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
			$table->addColumn('scheduled_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
			$table->addColumn('filename', Types::STRING, ['notnull' => true, 'length' => 255]);
			$table->addColumn('mime_type', Types::STRING, ['notnull' => true, 'length' => 255]);
			$table->addColumn('size', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('uploaded_by', Types::STRING, ['notnull' => false, 'length' => 64]);
			$table->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			// sha1 of the content, so the same file stored twice is kept once
			$table->addColumn('content_hash', Types::STRING, ['notnull' => false, 'length' => 40]);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['message_id'], 'ts_att_message');
			$table->addIndex(['ticket_id'], 'ts_att_ticket');
			$table->addIndex(['scheduled_id'], 'ts_att_scheduled');
			$table->addIndex(['content_hash'], 'ts_att_hash');
		}

		if (!$schema->hasTable('ticketsystem_canned')) {
			$table = $schema->createTable('ticketsystem_canned');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('title', Types::STRING, ['notnull' => true, 'length' => 255]);
			$table->addColumn('body', Types::TEXT, ['notnull' => true]);
			// null = shared with every agent
			$table->addColumn('owner_uid', Types::STRING, ['notnull' => false, 'length' => 64]);
			$table->addColumn('created_by', Types::STRING, ['notnull' => false, 'length' => 64]);
			$table->addColumn('usage_count', Types::INTEGER, ['notnull' => true, 'default' => 0, 'unsigned' => true]);
			$table->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('updated_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			// null = available on every brand's tickets
			$table->addColumn('team_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['owner_uid'], 'ts_canned_owner');
		}

		if (!$schema->hasTable('ticketsystem_scheduled')) {
			$table = $schema->createTable('ticketsystem_scheduled');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('ticket_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('uid', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('body', Types::TEXT, ['notnull' => true]);
			$table->addColumn('then_status', Types::STRING, ['notnull' => false, 'length' => 32]);
			$table->addColumn('send_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			// pending | sent | failed
			$table->addColumn('state', Types::STRING, ['notnull' => true, 'length' => 16]);
			$table->addColumn('error', Types::STRING, ['notnull' => false, 'length' => 500]);
			$table->addColumn('message_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
			$table->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('updated_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['state', 'send_at'], 'ts_sched_due');
			$table->addIndex(['ticket_id'], 'ts_sched_ticket');
		}

		if (!$schema->hasTable('ticketsystem_tags')) {
			$table = $schema->createTable('ticketsystem_tags');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('name', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('color', Types::STRING, ['notnull' => false, 'length' => 16]);
			$table->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['name'], 'ts_tags_name');
		}

		if (!$schema->hasTable('ticketsystem_ticket_tags')) {
			$table = $schema->createTable('ticketsystem_ticket_tags');
			$table->addColumn('ticket_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('tag_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->setPrimaryKey(['ticket_id', 'tag_id']);
			$table->addIndex(['tag_id'], 'ts_ttags_tag');
		}

		if (!$schema->hasTable('ticketsystem_time')) {
			$table = $schema->createTable('ticketsystem_time');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('ticket_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('uid', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('minutes', Types::INTEGER, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('billable', Types::BOOLEAN, ['notnull' => false, 'default' => true]);
			$table->addColumn('note', Types::STRING, ['notnull' => false, 'length' => 500]);
			// Y-m-d in the agent's time zone
			$table->addColumn('work_date', Types::STRING, ['notnull' => true, 'length' => 10]);
			// price per hour when the time was logged, minor units
			$table->addColumn('rate', Types::INTEGER, ['notnull' => false, 'unsigned' => true]);
			$table->addColumn('currency', Types::STRING, ['notnull' => false, 'length' => 3]);
			$table->addColumn('invoiced_at', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
			$table->addColumn('invoiced_by', Types::STRING, ['notnull' => false, 'length' => 64]);
			$table->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('updated_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['ticket_id'], 'ts_time_ticket');
			$table->addIndex(['work_date'], 'ts_time_date');
		}

		return $schema;
	}
}
