<?php

declare(strict_types=1);

/**
 * Activation lint for the cycle-orm skill.
 *
 * Mechanical (no-LLM) invariants behind the activation manifest:
 *
 *   1. Every `references/<file>.md` referenced in manifest.md exists on disk.
 *   2. No dangling [[skill-name]] cross-references anywhere under skills/
 *      (every [[name]] resolves to a real frontmatter `name:`).
 *   3. The skill's own index in SKILL.md references every file that actually
 *      lives in references/ (and vice versa) — index drift guard.
 *   4. Key trigger tokens that the manifest relies on are actually present in
 *      the cycle-orm `description` or SKILL.md body (regression guard: if a
 *      trigger is dropped from the description, routing silently degrades).
 *
 * Usage: php lint.php   (exit non-zero on any violation)
 */

$skillsDir = dirname(__DIR__, 3);          // skills/
$cycleDir  = dirname(__DIR__, 2);          // skills/cycle-orm/
$manifest  = __DIR__ . '/manifest.md';

$errors = [];
$checks = 0;

/** Collect all frontmatter names across skills. */
$names = [];
foreach (glob($skillsDir . '/*/SKILL.md') as $skillFile) {
    $head = file_get_contents($skillFile);
    if (preg_match('/^name:\s*(\S+)/m', $head, $m)) {
        $names[$m[1]] = $skillFile;
    }
}

// --- 1. Manifest references exist -------------------------------------------
$manifestText = file_get_contents($manifest);
preg_match_all('#`(references/[A-Za-z0-9_\-]+\.md)`#', $manifestText, $rm);
foreach (array_unique($rm[1]) as $rel) {
    $checks++;
    if (!is_file($cycleDir . '/' . $rel)) {
        $errors[] = "manifest references missing reference: {$rel}";
    }
}

// --- 2. No dangling [[skill-name]] -----------------------------------------
foreach (glob($skillsDir . '/*/SKILL.md') as $skillFile) {
    $text = file_get_contents($skillFile);
    if (preg_match_all('/\[\[([^\]]+)\]\]/', $text, $lm)) {
        foreach ($lm[1] as $ref) {
            // Literal placeholder used in the "conventions" notes, not a link.
            if ($ref === 'skill-name') {
                continue;
            }
            $checks++;
            if (!isset($names[$ref])) {
                $errors[] = "dangling [[{$ref}]] in " . basename(dirname($skillFile));
            }
        }
    }
}

// --- 3. Index <-> references drift ------------------------------------------
$skillMd = file_get_contents($cycleDir . '/SKILL.md');
$onDisk = array_map('basename', glob($cycleDir . '/references/*.md'));
sort($onDisk);
foreach ($onDisk as $file) {
    $checks++;
    if (!str_contains($skillMd, 'references/' . $file)) {
        $errors[] = "references/{$file} exists but is not referenced in SKILL.md index";
    }
}
// Match LOCAL references/<file> only — a cross-skill path is "<skill>/references/..",
// so require that "references/" is not preceded by a path component.
preg_match_all('#(?<![\w/-])references/([A-Za-z0-9_\-]+\.md)#', $skillMd, $im);
foreach (array_unique($im[1]) as $referenced) {
    $checks++;
    if (!in_array($referenced, $onDisk, true)) {
        $errors[] = "SKILL.md index references references/{$referenced} which does not exist";
    }
}

// --- 4. Trigger tokens present in description / body -----------------------
// Tokens the manifest leans on for routing into cycle-orm. If any disappears
// from the skill text, activation for that phrase regresses.
$triggerTokens = [
    'findByPK',
    'withCollectionFactory',
    'loadSubclasses',
    'CastableInterface',
    'make()',
    'Scope',
    'Repository',
    'pagination',
];
$haystack = $skillMd; // description lives in frontmatter at the top of SKILL.md
foreach ($triggerTokens as $token) {
    $checks++;
    if (!str_contains($haystack, $token)) {
        $errors[] = "trigger token \"{$token}\" missing from cycle-orm SKILL.md (description/body)";
    }
}

// --- Report -----------------------------------------------------------------
if ($errors === []) {
    echo "activation lint: OK ({$checks} checks)\n";
    exit(0);
}

echo "activation lint: " . count($errors) . " problem(s) of {$checks} checks\n";
foreach ($errors as $e) {
    echo "  - {$e}\n";
}
exit(1);
