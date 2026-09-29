<?php

declare(strict_types=1);

/**
 * Pitfall: expecting two fetches of the same PK to return independent objects.
 *
 * The identity map (Heap) is keyed by role + PK. Two findByPK(1) calls return
 * the SAME instance — a mutation through one variable is visible through the
 * other. A genuinely fresh object requires getHeap()->clean() (or a new ORM).
 */

use Eval\Entity\User;

return [
    'id'      => 'heap-identity',
    'prompt'  => 'Load user #1 twice into two variables. Explain whether mutating one is visible through the other, and how to obtain a truly independent copy.',
    'pitfall' => 'Heap is keyed by role+PK: repeated findByPK returns the same instance; only getHeap()->clean() yields a fresh object.',

    'solution' => static function ($orm): array {
        $repo = $orm->getRepository(User::class);

        $a = $repo->findByPK(1);
        $b = $repo->findByPK(1);
        $sameInstance = ($a === $b);

        // A fresh, independent instance requires clearing the identity map.
        $orm->getHeap()->clean();
        $c = $repo->findByPK(1);
        $freshIsDifferent = ($c !== $a);

        return [
            'sameInstance'     => $sameInstance,
            'freshIsDifferent' => $freshIsDifferent,
        ];
    },

    'grader' => static function (array $ctx): array {
        if ($ctx['threw'] !== null) {
            return [false, 'solution threw'];
        }
        $r = $ctx['result'];
        if (($r['sameInstance'] ?? null) !== true) {
            return [false, 'two findByPK(1) should return the SAME instance'];
        }
        if (($r['freshIsDifferent'] ?? null) !== true) {
            return [false, 'after getHeap()->clean() the re-fetched object should be a NEW instance'];
        }
        return [true, 'ok'];
    },
];
