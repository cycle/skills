<?php

declare(strict_types=1);

/**
 * Pitfall: expecting loadSubclasses(false) to downgrade STI rows to the parent.
 *
 * Under single-table inheritance the discriminator lives in the shared table
 * and is always read, so rows still resolve to the child class (Dog/Cat) even
 * with loadSubclasses(false). The flag does NOT affect the instance type for
 * STI — it only matters for joined-table inheritance.
 */

use Eval\Adv\Animal;
use Eval\Adv\Cat;
use Eval\Adv\Dog;

return [
    'id'      => 'sti-loadsubclasses',
    'prompt'  => 'Fetch all animals from a single-table (STI) hierarchy with loadSubclasses(false). State whether the rows come back as the concrete child classes or as the parent.',
    'pitfall' => 'Under STI the discriminator is in the shared table, so loadSubclasses(false) STILL returns child instances.',

    'solution' => static function ($orm): array {
        return $orm->getRepository(Animal::class)
            ->select()
            ->loadSubclasses(false)
            ->orderBy('id', 'ASC')
            ->fetchAll();
    },

    'grader' => static function (array $ctx): array {
        if ($ctx['threw'] !== null) {
            return [false, 'solution threw'];
        }
        $animals = $ctx['result'];
        if (!is_array($animals) || count($animals) !== 2) {
            return [false, 'expected 2 animals (Dog, Cat)'];
        }
        $hasDog = false;
        $hasCat = false;
        foreach ($animals as $a) {
            $hasDog = $hasDog || $a instanceof Dog;
            $hasCat = $hasCat || $a instanceof Cat;
        }
        if (!$hasDog || !$hasCat) {
            return [false, 'STI rows did not resolve to child classes (Dog/Cat) under loadSubclasses(false)'];
        }
        return [true, 'ok'];
    },
];
