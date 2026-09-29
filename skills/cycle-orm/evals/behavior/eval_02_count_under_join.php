<?php

declare(strict_types=1);

/**
 * Pitfall: counting the total for pagination when a relation is joined.
 *
 * with('orders') produces a JOIN that multiplies rows (3 users x 3 orders = 9).
 *   - count('user.id') -> COUNT(user.id) over join rows = 9 (WRONG total);
 *   - count() no-arg    -> COUNT(DISTINCT pk) = 3 (correct).
 *
 * The correct page total under a join is the distinct parent count.
 */

use Eval\Entity\User;

return [
    'id'      => 'count-under-join',
    'prompt'  => 'You filter users by a condition on their orders via a JOIN (with(\'orders\')). Return the total number of distinct users for a pagination footer.',
    'pitfall' => "count('user.id') counts join rows (9); the correct total is the distinct parent count (3).",

    'solution' => static function ($orm): int {
        // count() with no argument compiles to COUNT(DISTINCT primary key),
        // which is correct even when a JOIN multiplies rows.
        return $orm->getRepository(User::class)
            ->select()
            ->with('orders')
            ->count();
    },

    'grader' => static function (array $ctx): array {
        if ($ctx['threw'] !== null) {
            return [false, 'solution threw'];
        }
        if ($ctx['result'] !== 3) {
            return [false, 'expected distinct user count 3, got ' . var_export($ctx['result'], true) . ' (likely counted join rows)'];
        }
        // anti-pitfall: the emitted COUNT must be DISTINCT, not a raw COUNT over join rows
        $sawDistinctCount = false;
        foreach ($ctx['logger']->selects() as $sql) {
            if (stripos($sql, 'COUNT(DISTINCT') !== false) {
                $sawDistinctCount = true;
            }
        }
        if (!$sawDistinctCount) {
            return [false, 'count did not compile to COUNT(DISTINCT ...)'];
        }
        return [true, 'ok'];
    },
];
