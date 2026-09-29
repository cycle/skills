<?php

declare(strict_types=1);

/**
 * Pitfall: paginating a parent over a HasMany relation.
 *
 * A naive solution joins the relation and applies LIMIT on the parent query:
 *   - load('orders', SingleQuery) + limit -> throws LoaderException
 *     ("Unable to load data using join with limit on parent query");
 *   - with('orders') + limit -> the JOIN multiplies parent rows, hydration
 *     dedups by PK, so you get FEWER than `limit` distinct users.
 *
 * Correct: keep the relation as POSTLOAD (the default for HasMany) so LIMIT
 * applies to the parent SELECT, then orders load in a separate query.
 */

use Eval\Entity\User;

return [
    'id'      => 'pagination-hasmany',
    'prompt'  => 'Fetch the first 2 users ordered by id, each with all their orders loaded. The result must contain exactly 2 users, each keeping its full set of orders, and must not throw.',
    'pitfall' => 'JOIN+LIMIT on a HasMany throws LoaderException; with()+limit returns fewer entities due to PK dedup.',

    'solution' => static function ($orm): array {
        // Default load() for HasMany is POSTLOAD: LIMIT applies to the parent
        // SELECT, orders are fetched in a second query. No JOIN+LIMIT clash.
        return $orm->getRepository(User::class)
            ->select()
            ->load('orders')
            ->orderBy('id', 'ASC')
            ->limit(2)
            ->fetchAll();
    },

    'grader' => static function (array $ctx): array {
        if ($ctx['threw'] !== null) {
            return [false, 'solution threw instead of paginating cleanly'];
        }
        $users = $ctx['result'];
        if (!is_array($users) || count($users) !== 2) {
            return [false, 'expected exactly 2 users, got ' . (is_countable($users) ? count($users) : gettype($users))];
        }
        foreach ($users as $u) {
            if (count($u->orders) !== 3) {
                return [false, "user #{$u->id} should keep all 3 orders, has " . count($u->orders)];
            }
        }
        // anti-pitfall: ids must be the first two (1,2), not a dedup artifact
        if ($users[0]->id !== 1 || $users[1]->id !== 2) {
            return [false, 'pagination returned wrong page of users'];
        }
        return [true, 'ok'];
    },
];
