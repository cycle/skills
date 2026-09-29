# Evals for the `cycle-orm` skill

Two independent checks of skill quality:

- **`behavior/`** — executable. Does an agent armed with the skill produce code
  that solves the task *without* hitting the runtime pitfalls? Each case ships a
  reference solution and a grader; the grader runs the result against a fresh
  in-memory database and asserts both the result and the absence of the pitfall.
- **`activation/`** — routing. Given a user phrase, does the right skill (and the
  right `references/<file>.md`) activate? A judge-facing manifest plus a
  mechanical linter for the invariants a judge can't eyeball.

These evals are built around the pitfalls validated for this skill, where a
naive solution fails and a skill-guided one does not.

## Requirements

- PHP 8.1+ (validated on 8.4).
- The repo's `vendor/` (cycle/* installed via `composer install`). Behavior
  evals load `../../../../vendor/autoload.php`; they do not depend on `sandbox/`.

## Run

```bash
# all behavior cases
php skills/cycle-orm/evals/behavior/harness.php

# a subset (id substring)
php skills/cycle-orm/evals/behavior/harness.php pagination

# activation invariants (references exist, no dangling [[links]], triggers present)
php skills/cycle-orm/evals/activation/lint.php
```

`harness.php` exits non-zero if any case FAILs; `lint.php` exits non-zero on any
violation. Both are CI-friendly.

## Layout

```
evals/
  behavior/
    bootstrap.php        # fresh in-memory SQLite + seeded fixtures, returns [$orm, $logger]
    harness.php          # runs each eval_*.php against a fresh bootstrap, prints PASS/FAIL
    cases.md             # catalog: task, pitfall guarded, mapped skill reference
    eval_NN_<id>.php      # one case = ['id','prompt','pitfall','solution','grader']
    src/{Entity,Adv,Jti}  # attribute entities (User/Order, STI Animal/Dog/Cat + Blog/Post, JTI Person/Employee)
  activation/
    manifest.md          # phrase -> expected skill + reference (+ anti) for a judge
    lint.php             # mechanical invariants behind the manifest
```

## How a behavior case works

```php
return [
    'id'       => 'pagination-hasmany',
    'prompt'   => 'task in natural language',
    'pitfall'  => 'the trap a naive answer falls into',
    'solution' => fn($orm, $logger) => /* reference code */,
    'grader'   => fn(array $ctx) => [bool $pass, string $reason],
    //            $ctx = ['result'=>, 'threw'=>?Throwable, 'orm'=>, 'logger'=>]
];
```

The reference `solution` must make its own `grader` PASS — that doubles as a
sanity check on the grader. Graders assert **result + anti-pitfall** (no
exception, correct count/class, expected SQL shape), so an accidentally-correct
answer reached the wrong way still fails where detectable.

## How activation cases work

Real routing is Claude reading the `description`s + `SKILL.md` indexes. To grade
`manifest.md`, give a judge only those (no expected columns) and ask, per phrase,
which skill + reference activates; compare with the table. `lint.php` enforces the
parts that need no judgment: referenced reference files exist, no dangling
`[[skill-name]]`, the SKILL.md index and `references/` agree, and the manifest's
trigger tokens are present in the skill text.

## Adding cases

1. Behavior: pick a validated pitfall, add `eval_NN_<id>.php`, confirm the naive
   approach fails and the reference passes, add a row to `cases.md`.
2. Activation: add a phrase row to `manifest.md`; if it leans on a new trigger
   token, add it to the `lint.php` token list.
