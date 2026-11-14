<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Co-Optimize
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\TicketSystem\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<Ticket>
 */
class TicketMapper extends QBMapper {
	/** Filter value for "tickets nobody is assigned to". */
	public const ASSIGNEE_NONE = "\0unassigned";

	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'ticketsystem_tickets', Ticket::class);
	}

	/**
	 * @throws DoesNotExistException
	 */
	public function find(int $id): Ticket {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		return $this->findEntity($qb);
	}

	/**
	 * @throws DoesNotExistException
	 */
	public function findByToken(string $token): Ticket {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('access_token', $qb->createNamedParameter($token)));
		return $this->findEntity($qb);
	}

	/** @return Ticket[] */
	public function search(TicketFilter $filter, int $limit = 50, int $offset = 0): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName());
		$this->applyFilter($qb, $filter);
		if ($filter->sort === TicketFilter::SORT_WAITING) {
			// Longest-waiting customers first; tickets nobody waits on after them.
			$qb->orderBy($qb->createFunction('CASE WHEN waiting_since IS NULL THEN 1 ELSE 0 END'))
				->addOrderBy('waiting_since', 'ASC')
				->addOrderBy('updated_at', 'DESC');
		} elseif ($filter->sort === TicketFilter::SORT_CREATED) {
			$qb->orderBy('created_at', 'DESC');
		} else {
			$qb->orderBy('updated_at', 'DESC');
		}
		$qb->addOrderBy('id', 'DESC')
			->setMaxResults($limit)
			->setFirstResult($offset);
		return $this->findEntities($qb);
	}

	/**
	 * @param int[] $ids
	 * @return Ticket[]
	 */
	public function findMany(array $ids): array {
		if ($ids === []) {
			return [];
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->in('id', $qb->createNamedParameter(array_values($ids), IQueryBuilder::PARAM_INT_ARRAY)))
			->orderBy('id', 'DESC');
		return $this->findEntities($qb);
	}

	/** @return Ticket[] snoozed tickets whose time is up */
	public function findSnoozeEnded(int $now): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->isNotNull('snoozed_until'))
			->andWhere($qb->expr()->lte('snoozed_until', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT)))
			->setMaxResults(200);
		return $this->findEntities($qb);
	}

	/**
	 * Other tickets of the same customer, newest first.
	 * @return Ticket[]
	 */
	public function findByCustomer(string $email, int $excludeId, int $limit): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('customer_email', $qb->createNamedParameter(strtolower($email))))
			->andWhere($qb->expr()->neq('id', $qb->createNamedParameter($excludeId, IQueryBuilder::PARAM_INT)))
			->orderBy('created_at', 'DESC')
			->addOrderBy('id', 'DESC')
			->setMaxResults($limit);
		return $this->findEntities($qb);
	}

	/** @return Ticket[] every ticket of this customer address */
	public function findAllByCustomer(string $email): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('customer_email', $qb->createNamedParameter(strtolower($email))))
			->orderBy('id');
		return $this->findEntities($qb);
	}

	/** @return Ticket[] tickets that copy this address (CC) */
	public function findWithCc(string $email): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->like('cc', $qb->createNamedParameter('%"' . $this->db->escapeLikeParameter(strtolower($email)) . '"%')))
			->orderBy('id');
		return $this->findEntities($qb);
	}

	/**
	 * Done tickets whose customer data is due for erasure: closed before
	 * $before and not erased yet.
	 *
	 * @param string[] $closedStatuses
	 * @return Ticket[]
	 */
	public function findErasable(array $closedStatuses, int $before, int $limit): array {
		if ($closedStatuses === []) {
			return [];
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->in('status', $qb->createNamedParameter($closedStatuses, IQueryBuilder::PARAM_STR_ARRAY)))
			->andWhere($qb->expr()->isNull('erased_at'))
			->andWhere($qb->expr()->orX(
				$qb->expr()->lt('resolved_at', $qb->createNamedParameter($before, IQueryBuilder::PARAM_INT)),
				$qb->expr()->andX(
					$qb->expr()->isNull('resolved_at'),
					$qb->expr()->lt('updated_at', $qb->createNamedParameter($before, IQueryBuilder::PARAM_INT)),
				),
			))
			->orderBy('id')
			->setMaxResults($limit);
		return $this->findEntities($qb);
	}

	/** Tickets this address opened by email or the portal since $since (unix time). */
	public function countEmailTicketsFrom(string $email, int $since): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'n'))
			->from($this->getTableName())
			->where($qb->expr()->eq('customer_email', $qb->createNamedParameter(strtolower($email))))
			->andWhere($qb->expr()->in('channel', $qb->createNamedParameter(['email', 'web'], IQueryBuilder::PARAM_STR_ARRAY)))
			->andWhere($qb->expr()->gte('created_at', $qb->createNamedParameter($since, IQueryBuilder::PARAM_INT)));
		$result = $qb->executeQuery();
		$n = (int)$result->fetchOne();
		$result->closeCursor();
		return $n;
	}

	public function count(TicketFilter $filter): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'n'))->from($this->getTableName());
		$this->applyFilter($qb, $filter);
		$result = $qb->executeQuery();
		$n = (int)$result->fetchOne();
		$result->closeCursor();
		return $n;
	}

	private function applyFilter(IQueryBuilder $qb, TicketFilter $f): void {
		$expr = $qb->expr();
		$none = fn () => $qb->andWhere('1 = 0');
		if ($f->statuses !== null) {
			if ($f->statuses === []) {
				$none();
				return;
			}
			$qb->andWhere($expr->in('status', $qb->createNamedParameter($f->statuses, IQueryBuilder::PARAM_STR_ARRAY)));
		}
		if ($f->teamIds !== null) {
			if ($f->teamIds === []) {
				$none();
				return;
			}
			$qb->andWhere($expr->in('team_id', $qb->createNamedParameter($f->teamIds, IQueryBuilder::PARAM_INT_ARRAY)));
		}
		if ($f->assignee === self::ASSIGNEE_NONE) {
			$qb->andWhere($expr->isNull('assignee_uid'));
		} elseif ($f->assignee !== null) {
			$qb->andWhere($expr->eq('assignee_uid', $qb->createNamedParameter($f->assignee)));
		}
		if ($f->priorities !== []) {
			$qb->andWhere($expr->in('priority', $qb->createNamedParameter($f->priorities, IQueryBuilder::PARAM_STR_ARRAY)));
		}
		if ($f->needsReply) {
			$qb->andWhere($expr->isNotNull('waiting_since'));
		}
		if ($f->snoozed === TicketFilter::SNOOZED_HIDE) {
			$qb->andWhere($expr->orX(
				$expr->isNull('snoozed_until'),
				$expr->lte('snoozed_until', $qb->createNamedParameter($f->now, IQueryBuilder::PARAM_INT)),
			));
		} elseif ($f->snoozed === TicketFilter::SNOOZED_ONLY) {
			$qb->andWhere($expr->gt('snoozed_until', $qb->createNamedParameter($f->now, IQueryBuilder::PARAM_INT)));
		}
		if ($f->tagIds !== []) {
			$sub = $this->db->getQueryBuilder();
			$sub->select('tt.ticket_id')
				->from('ticketsystem_ticket_tags', 'tt')
				->where($sub->expr()->in('tt.tag_id', $qb->createNamedParameter($f->tagIds, IQueryBuilder::PARAM_INT_ARRAY)));
			$qb->andWhere($expr->in('id', $qb->createFunction($sub->getSQL())));
		}

		$query = $f->query !== null ? trim($f->query) : '';
		$numeric = ltrim($query, '#');
		$byNumber = $query !== '' && ctype_digit($numeric);
		if ($query !== '') {
			$or = [];
			$like = '%' . $this->db->escapeLikeParameter($query) . '%';
			$likeParam = $qb->createNamedParameter($like);
			foreach (['subject', 'customer_email', 'customer_name'] as $column) {
				$or[] = $expr->iLike($column, $likeParam);
			}
			// Text of every message, internal notes included (only agents can search);
			// history entries are left out, or "open" would match every ticket that changed status.
			$sub = $this->db->getQueryBuilder();
			$sub->select('m.ticket_id')
				->from('ticketsystem_messages', 'm')
				->where($sub->expr()->iLike('m.body', $likeParam))
				->andWhere($sub->expr()->neq('m.author_type', $qb->createNamedParameter(Message::AUTHOR_SYSTEM)));
			$or[] = $expr->in('id', $qb->createFunction($sub->getSQL()));
			// "#42" or "42" matches the ticket number directly.
			if ($byNumber) {
				$or[] = $expr->eq('id', $qb->createNamedParameter((int)$numeric, IQueryBuilder::PARAM_INT));
			}
			$qb->andWhere($expr->orX(...$or));
		}
	}
}
