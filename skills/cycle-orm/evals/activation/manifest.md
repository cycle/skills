# Activation eval manifest — `cycle-orm`

Checks **routing**: given a user phrase, does the right skill activate, and does
it point at the right `references/<file>.md`? This is separate from behavior
evals (which assume the skill is already loaded and test the generated code).

Granularity: **skill + reference**. The `anti` column lists skills that must NOT
win the phrase (boundary / anti-trigger cases).

## How to run

Real routing is performed by Claude reading the frontmatter `description`s plus
each `SKILL.md` index. To evaluate:

1. Give a judge ONLY the frontmatter of all skills + the "Index" section of
   each `SKILL.md` (no answers).
2. For each phrase below, ask: "Which skill activates, and which `references/`
   file does its index route to? Or none?"
3. Compare with the expected columns. A case PASSes if the skill matches and the
   reference is the expected one (or a documented alternative).

The objective invariants behind this table (reference files exist, no dangling
`[[links]]`, trigger tokens are actually present in the `description`/index) are
enforced mechanically by `lint.php`.

## Cases

| # | Phrase | Expected skill | Expected reference | Anti (must NOT win) |
|---|--------|----------------|-------------------|---------------------|
| 1 | "findByPK keeps returning the same stale object after I change the DB" | `cycle-orm` | `SKILL.md` Hard constraints (Heap identity) → `references/repositories.md` | — |
| 2 | "pagination breaks when I load('orders') with a limit" | `cycle-orm` | `references/fetching.md` | — |
| 3 | "how do I count distinct parents when I join a hasMany for filtering" | `cycle-orm` | `references/fetching.md` / `references/repositories.md` | — |
| 4 | "loadSubclasses(false) still returns Dog instead of the parent" | `cycle-orm` | `references/fetching.md` | `cycle-orm-attributes` |
| 5 | "how do I register a Doctrine collection via withCollectionFactory" | `cycle-orm` | `references/collections.md` | — |
| 6 | "writing a custom repository with named query methods like findActive" | `cycle-orm` | `references/repositories.md` / `references/orm-extensions.md` | — |
| 7 | "add a global soft-delete filter to every query (Scope)" | `cycle-orm` | `references/orm-extensions.md` | `cycle-orm-attributes` |
| 8 | "JSON value-object via a custom typecast handler (CastableInterface)" | `cycle-orm` | `references/typecasters-advanced.md` | — |
| 9 | "$orm->make() vs new Entity — why the extra SELECT" | `cycle-orm` | `references/entity-lifecycle.md` | — |
| 10 | "how do I bootstrap Cycle standalone, which packages do I install" | `cycle-orm` | `references/installation.md` | — |
| 11 | "Cannot modify readonly property when hydrating / role not found at build" | `cycle-orm` | `references/schema-troubleshooting.md` | — |
| 12 | "what does the compiled schema array look like, the SchemaInterface keys" | `cycle-orm` | `references/schema.md` | — |
| **Boundary / anti-trigger** |
| 13 | "final readonly Entity does not work with the default mapper" | `cycle-orm-attributes` | (attributes: class constraints) | `cycle-orm` |
| 14 | "how do I declare a #[HasMany] / map a column type with #[Column]" | `cycle-orm-attributes` | (attributes: relations/columns) | `cycle-orm` |
| 15 | "configure the SQLite/Postgres driver, DatabaseConfig, read/write split" | `cycle-database` | (dbal) | `cycle-orm` |
| 16 | "write a raw SQL SELECT with a JOIN in plain PHP, no ORM" | (none) | — | `cycle-orm`, `cycle-orm-attributes`, `cycle-database` |
| 17 | "how do I add an index to a Laravel Eloquent model" | (none) | — | all cycle skills |

## Notes on boundaries

- Cases 4 & 7 brush against `cycle-orm-attributes` (inheritance / soft-delete are
  *declared* with attributes) but the phrasing is about **runtime fetch/query
  behavior** → `cycle-orm`. If the phrase were "how do I *declare* STI / add
  `#[SoftDelete]`", the winner flips to `cycle-orm-attributes`.
- Cases 16–17 are negative controls: no cycle skill should fire on raw-SQL or
  non-Cycle-ORM (Eloquent) questions.
