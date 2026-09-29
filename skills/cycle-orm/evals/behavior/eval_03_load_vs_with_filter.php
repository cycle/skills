<?php

declare(strict_types=1);

/**
 * Pitfall: confusing with() (filter-only JOIN) with load() (hydration).
 *
 * with('orders') filters parents by the relation but does NOT populate
 * $user->orders. load('orders') hydrates but, on its own, does not filter the
 * parent set. To both filter AND hydrate without a second relation join, share
 * the alias: with('orders', ['as' => 'order']) for the WHERE, then
 * load('orders', using: 'order') to hydrate off that same join.
 */

use Cycle\ORM\Select\Options\HasManyLoadOptions;
use Eval\Entity\User;

return [
    'id'      => 'load-vs-with-filter',
    'prompt'  => "Return users that have at least one order with status 'new', with their orders hydrated. Filter by the relation and populate \$user->orders without issuing redundant joins.",
    'pitfall' => 'with() filters but does not hydrate; using two independent joins for filter and load is wasteful — share the alias.',

    'solution' => static function ($orm): array {
        return $orm->getRepository(User::class)
            ->select()
            ->with('orders', ['as' => 'order'])
            ->where('order.status', 'new')
            ->load('orders', new HasManyLoadOptions(using: 'order'))
            ->fetchAll();
    },

    'grader' => static function (array $ctx): array {
        if ($ctx['threw'] !== null) {
            return [false, 'solution threw'];
        }
        $users = $ctx['result'];
        // every seeded user has exactly one 'new' order, so all 3 match
        if (!is_array($users) || count($users) !== 3) {
            return [false, 'expected 3 matching users, got ' . (is_countable($users) ? count($users) : gettype($users))];
        }
        foreach ($users as $u) {
            // anti-pitfall: orders must actually be hydrated (not empty)
            if (count($u->orders) === 0) {
                return [false, "user #{$u->id} has no hydrated orders — with() alone does not load"];
            }
        }
        return [true, 'ok'];
    },
];
