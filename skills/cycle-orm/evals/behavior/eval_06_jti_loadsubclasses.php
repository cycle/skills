<?php

declare(strict_types=1);

/**
 * Pitfall: assuming loadSubclasses behaves the same for JTI as for STI.
 *
 * Under joined-table inheritance the child columns live in a separate table.
 * loadSubclasses(false) drops the JOIN to that table, so the row hydrates as
 * the PARENT class (instanceof Employee == false, department unset). This is
 * the opposite of STI, where the flag has no effect on the instance type.
 */

use Eval\Jti\Employee;
use Eval\Jti\Person;

return [
    'id'      => 'jti-loadsubclasses',
    'prompt'  => 'In a joined-table (JTI) hierarchy, fetch all persons with loadSubclasses(false). State the concrete class of the employee row and whether its child-table fields are populated.',
    'pitfall' => 'Under JTI, loadSubclasses(false) drops the child-table JOIN: the row hydrates as the parent class, child fields unset.',

    'solution' => static function ($orm): array {
        $rows = $orm->getRepository(Person::class)
            ->select()
            ->loadSubclasses(false)
            ->orderBy('id', 'ASC')
            ->fetchAll();

        $employeeRow = null;
        foreach ($rows as $r) {
            if ($r->name === 'Bob') {
                $employeeRow = $r;
            }
        }

        return [
            'count'         => count($rows),
            'bobFound'      => $employeeRow !== null,
            // The default Mapper returns a proxy subclass, so compare by the
            // declared hierarchy, not by the concrete (proxy) class name.
            'bobIsPerson'   => $employeeRow instanceof Person,
            'bobIsEmployee' => $employeeRow instanceof Employee,
        ];
    },

    'grader' => static function (array $ctx): array {
        if ($ctx['threw'] !== null) {
            return [false, 'solution threw'];
        }
        $r = $ctx['result'];
        if (($r['count'] ?? null) !== 2) {
            return [false, 'expected 2 person rows'];
        }
        if (($r['bobFound'] ?? null) !== true || ($r['bobIsPerson'] ?? null) !== true) {
            return [false, 'employee row not found / not a Person'];
        }
        if (($r['bobIsEmployee'] ?? null) !== false) {
            return [false, 'under JTI + loadSubclasses(false) the employee row must hydrate as the PARENT (Person), not Employee'];
        }
        return [true, 'ok'];
    },
];
