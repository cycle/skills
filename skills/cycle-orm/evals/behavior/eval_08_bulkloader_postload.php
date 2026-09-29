<?php

declare(strict_types=1);

/**
 * Pitfall: post-loading a relation onto an already-fetched set with BulkLoader.
 *
 * BulkLoader::collect() is immutable — it returns a NEW instance; discarding
 * the return value (calling load() on the original) loads nothing. It also
 * rejects non-persisted entities and mixed roles. Correct usage chains
 * collect()->load()->run() on the returned instance, with persisted, same-role
 * entities.
 */

use Cycle\ORM\Relation\BulkLoader;
use Eval\Entity\User;

return [
    'id'      => 'bulkloader-postload',
    'prompt'  => 'You already fetched 3 users WITHOUT their orders. Post-load the orders onto that existing set in a single batch using BulkLoader, without re-fetching the users.',
    'pitfall' => 'collect() is immutable (keep the returned instance); BulkLoader rejects new/non-persisted entities and mixed roles.',

    'solution' => static function ($orm): array {
        $users = $orm->getRepository(User::class)->select()->orderBy('id')->fetchAll();

        // collect() returns a NEW loader; chain off the return value.
        (new BulkLoader($orm))
            ->collect(...$users)
            ->load('orders')
            ->run();

        return $users;
    },

    'grader' => static function (array $ctx): array {
        if ($ctx['threw'] !== null) {
            return [false, 'solution threw'];
        }
        $users = $ctx['result'];
        if (!is_array($users) || count($users) !== 3) {
            return [false, 'expected 3 users'];
        }
        foreach ($users as $u) {
            // anti-pitfall: orders must be populated by the bulk load
            if (count($u->orders) !== 3) {
                return [false, "user #{$u->id} orders not post-loaded (has " . count($u->orders) . ') — collect() return likely discarded'];
            }
        }
        return [true, 'ok'];
    },
];
