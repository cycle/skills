# Behavior eval cases

Each case targets a runtime pitfall the `cycle-orm` skill is meant to prevent.
A naive agent (no skill) typically falls into the trap; an agent with the skill
should produce the reference solution. The grader checks both the **result**
and the **absence of the pitfall** (no exception, correct entity count/class,
correct SQL shape).

Files: `eval_NN_<id>.php`. Run via `php harness.php` (see `../README.md`).

| # | id | Task (prompt) | Pitfall guarded | Skill reference |
|---|----|---------------|-----------------|----------------|
| 1 | `pagination-hasmany` | First 2 users by id with all their orders, no crash | JOIN+LIMIT on HasMany throws `LoaderException`; `with()`+limit dedups to fewer entities. Fix: POSTLOAD `load()` so LIMIT hits the parent | `references/fetching.md` |
| 2 | `count-under-join` | Distinct user total when filtering via `with('orders')` JOIN | `count('user.id')` counts join rows (9); `count()` no-arg = `COUNT(DISTINCT pk)` (3) | `references/repositories.md`, `references/fetching.md` |
| 3 | `load-vs-with-filter` | Users having a `new` order, with orders hydrated, no redundant join | `with()` filters but does not hydrate; share the alias (`with(as)` + `load(using)`) instead of two joins | `references/fetching.md` |
| 4 | `heap-identity` | Two loads of user #1; mutation visibility + how to get a fresh copy | Heap keyed by role+PK: repeated `findByPK` returns the same instance; fresh = `getHeap()->clean()` | `SKILL.md` (Hard constraints) |
| 5 | `sti-loadsubclasses` | All STI animals with `loadSubclasses(false)`; child vs parent class | STI discriminator is in the shared table → still resolves to child; flag has no effect on instance type | `references/fetching.md` |
| 6 | `jti-loadsubclasses` | All JTI persons with `loadSubclasses(false)`; class + child fields | JTI drops the child-table JOIN → row hydrates as parent, child fields unset (opposite of STI) | `references/fetching.md` |
| 7 | `scope-per-relation` | Blog with ALL posts incl. drafts, bypassing `PublishedScope` on the relation | Per-relation `LoadOptions(scope: false)` is silently dropped under `fetchOne()`/`findOne()` (Select clone); use `fetchAll()` | `references/fetching.md`, `references/orm-extensions.md` |
| 8 | `bulkloader-postload` | Post-load orders onto an already-fetched user set in one batch | `BulkLoader::collect()` is immutable (keep the return); rejects new/non-persisted entities and mixed roles | `references/fetching.md` |

## Conventions

- The reference `solution` MUST make its own grader PASS — this is the grader's
  sanity check. If the reference fails, the grader is wrong, not the ORM.
- Graders assert **result + anti-pitfall**, never just the happy-path value, so
  an accidentally-correct answer reached via the wrong path still FAILs where
  detectable (e.g. case 2 requires `COUNT(DISTINCT ...)` in the SQL log).
- Fixtures are seeded fresh per case (clean Heap, fresh in-memory SQLite).

## Adding a case

1. Pick a validated pitfall (one that a naive solution actually hits).
2. Create `eval_NN_<id>.php` returning `['id','prompt','pitfall','solution','grader']`.
3. Confirm the **naive** approach fails and the reference passes (run the naive
   variant by hand once, as done for `scope-per-relation` vs `fetchOne`).
4. Add a row here and, if it exercises a new reference, cross-check the
   activation manifest.
