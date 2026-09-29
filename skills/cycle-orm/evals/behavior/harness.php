<?php

declare(strict_types=1);

/**
 * Behavior eval harness for the cycle-orm skill.
 *
 * Each eval case lives in eval_*.php and returns an array:
 *
 *   return [
 *       'id'       => 'pagination-hasmany',
 *       'prompt'   => 'natural-language task given to the agent',
 *       'pitfall'  => 'the trap a naive solution falls into',
 *       'solution' => function (ORMInterface $orm, EvalLogger $logger) { ... return $result; },
 *       'grader'   => function (array $ctx): array {
 *           // $ctx = ['result'=>mixed, 'threw'=>?Throwable, 'orm'=>, 'logger'=>]
 *           // return [bool $pass, string $reason]
 *       },
 *   ];
 *
 * The harness runs each case against a FRESH bootstrap (clean Heap + fresh DB),
 * executes the reference solution, captures any Throwable, and feeds the result
 * to the grader. The reference solution is expected to make its own grader PASS
 * — that doubles as a sanity check on the grader itself.
 *
 * Usage:
 *   php harness.php            # run all cases
 *   php harness.php pagination # run cases whose id contains "pagination"
 *
 * Exit code is non-zero if any case FAILs.
 */

use Cycle\ORM\ORMInterface;

$filter = $argv[1] ?? null;

$caseFiles = glob(__DIR__ . '/eval_*.php');
sort($caseFiles);

$pass = 0;
$fail = 0;
$failed = [];

foreach ($caseFiles as $file) {
    // Each case gets its own fresh ORM + logger.
    /** @var ORMInterface $orm */
    [$orm, $logger] = require __DIR__ . '/bootstrap.php';

    $case = require $file;

    $id = $case['id'] ?? basename($file);
    if ($filter !== null && !str_contains($id, $filter)) {
        continue;
    }

    $result = null;
    $threw = null;
    try {
        $result = ($case['solution'])($orm, $logger);
    } catch (\Throwable $e) {
        $threw = $e;
    }

    [$ok, $reason] = ($case['grader'])([
        'result' => $result,
        'threw'  => $threw,
        'orm'    => $orm,
        'logger' => $logger,
    ]);

    if ($ok) {
        $pass++;
        echo "PASS  {$id}\n";
    } else {
        $fail++;
        $failed[] = $id;
        echo "FAIL  {$id}\n";
        echo "        prompt:  " . ($case['prompt'] ?? '') . "\n";
        echo "        pitfall: " . ($case['pitfall'] ?? '') . "\n";
        echo "        reason:  {$reason}\n";
        if ($threw !== null) {
            echo "        threw:   " . get_class($threw) . ': ' . $threw->getMessage() . "\n";
        }
    }
}

echo "\n";
echo "----------------------------------------\n";
echo "Total: " . ($pass + $fail) . "  PASS: {$pass}  FAIL: {$fail}\n";
if ($failed !== []) {
    echo "Failed: " . implode(', ', $failed) . "\n";
}

exit($fail === 0 ? 0 : 1);
