<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Co-Optimize
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\TicketSystem\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** The tags on tickets. */
class RelationMapper {
	public function __construct(
		private IDBConnection $db,
	) {
	}

	// --- Tags ---------------------------------------------------------------

	/**
	 * @param int[] $ticketIds
	 * @return array<int, int[]> tag ids per ticket
	 */
	public function tagIdsFor(array $ticketIds): array {
		$result = [];
		foreach (array_chunk($ticketIds, 500) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->select('ticket_id', 'tag_id')
				->from('ticketsystem_ticket_tags')
				->where($qb->expr()->in('ticket_id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)));
			$rows = $qb->executeQuery();
			while ($row = $rows->fetch()) {
				$result[(int)$row['ticket_id']][] = (int)$row['tag_id'];
			}
			$rows->closeCursor();
		}
		return $result;
	}

	/** @param int[] $tagIds */
	public function setTags(int $ticketId, array $tagIds): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete('ticketsystem_ticket_tags')
			->where($qb->expr()->eq('ticket_id', $qb->createNamedParameter($ticketId, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
		foreach (array_unique($tagIds) as $tagId) {
			$insert = $this->db->getQueryBuilder();
			$insert->insert('ticketsystem_ticket_tags')->values([
				'ticket_id' => $insert->createNamedParameter($ticketId, IQueryBuilder::PARAM_INT),
				'tag_id' => $insert->createNamedParameter($tagId, IQueryBuilder::PARAM_INT),
			]);
			$insert->executeStatement();
		}
	}

	public function deleteTag(int $tagId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete('ticketsystem_ticket_tags')
			->where($qb->expr()->eq('tag_id', $qb->createNamedParameter($tagId, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}

}
