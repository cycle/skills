# Fetching: loading entities together with their relations

A deep dive into relation loading strategies and the surrounding infrastructure. It opens with a short "just fetch an entity" entry point (via the repository and via Select), then gets to the core topic: **how to fetch a set of entities together with their relations** without an N+1. The full Select API (`where`/`limit`/`orderBy`/EntityManager/pagination/`forUpdate`, repository builder methods) lives in `repositories.md`. Writing custom Repository/Scope/Mapper classes lives in `orm-extensions.md`.

See also:
- `repositories.md` — Select WHERE/HAVING/JSON, EntityManager, pagination
- `orm-extensions.md` — implementing custom `ScopeInterface`, `RepositoryInterface`
- `cycle-orm-attributes/resources/relations.md` — declaring relations and the `load:` parameter on the attribute itself

Reference sources:
- https://cycle-orm.dev/docs/basic-select/current/en
- https://cycle-orm.dev/docs/query-builder-relations/current/en
- https://cycle-orm.dev/docs/relation-bulk-loader/current/en
- https://cycle-orm.dev/docs/advanced-scope/current/en
- `vendor/cycle/orm/src/Select/` (Loader, Options, QueryBuilder, Scope)

---

## Just fetch an entity

No relations involved — two standard paths. This is the entry point; the full Select API (every WHERE/HAVING/JSON form, ORDER BY/LIMIT, aggregations, pagination, `forUpdate`, repository builder methods) lives in `repositories.md`.

```php
$repo = $orm->getRepository(User::class);

// 1. Via the repository — simple equality lookups
$user  = $repo->findByPK(42);
$user  = $repo->findOne(['email' => 'alice@example.com']);
$users = $repo->findAll(['active' => true], ['create_time' => 'DESC']);

// 2. Beyond a plain `column = value` — via Select
$users = $repo->select()
    ->where('active', true)
    ->orderBy('create_time', 'DESC')
    ->limit(10)
    ->fetchAll();
```

→ The full set of conditions and custom query/builder methods on the repository — see `repositories.md`. Below: how to fetch entities **together with their relations**.

---

## Minimum working example: with relations

```php
// 1. Loading relations via Select
$users = $repo->select()
    ->load('orders')                     // POSTLOAD: separate SELECT for orders
    ->load('profile')
    ->fetchAll();

// 2. JOIN a relation for filtering, WITHOUT hydration
$paid = $repo->select()
    ->with('orders')->where('orders.status', 'paid')
    ->fetchAll();

// 3. Post-load relations for an already-fetched set (BulkLoader)
$users = $repo->findAll();
(new \Cycle\ORM\Relation\BulkLoader($orm))
    ->collect(...$users)
    ->load('orders')
    ->run();
```

Three tools, three scenarios:
- `load()` — you need the related data in the result.
- `with()` — you need to filter/sort by the related table but don't need the related data.
- `BulkLoader` — the entities are already in hand (after `findAll`, after batch processing, etc.) and you need to add relations without re-querying.

---

## `load()` vs `with()`: choosing the right tool

These are **two different tools**, not interchangeable. Hold the model in mind before reading the table:

- **`with()`** — adds a JOIN **only** for WHERE/ORDER BY against the related table. The related data is **not** placed into the entity (no hydration).
- **`load()`** — places the related data **into** the entity (`$user->orders` returns it with no DB hit). How it loads: a separate `SELECT` (POSTLOAD — the default for HasMany/ManyToMany/BelongsTo) or a JOIN inlined into the main query (INLOAD — the default for HasOne).

| What you need                                                              | What to use                                          |
|----------------------------------------------------------------------------|------------------------------------------------------|
| `$user->orders` must return **loaded** orders (no extra DB hit)            | `->load('orders')`                                   |
| Find Users who **have** an order with `status = paid` (orders data not needed) | `->with('orders')->where('orders.status', 'paid')` |
| Both filter by the relation **and** get its data                          | reuse the JOIN via `using` — see below               |

### Both filter and data: reuse a single JOIN

When you need to both filter by a relation and pull its data, don't make two passes. Build the JOIN with `with()` and reuse it in `load()` via `using`:

```php
use Cycle\ORM\Select\Options\HasManyLoadOptions;

$users = $repo->select()
    ->with('orders', ['as' => 'orders'])                       // with() takes an array only
    ->where('orders.status', 'paid')
    ->load('orders', new HasManyLoadOptions(using: 'orders'))  // same data — no second query
    ->fetchAll();
```

`using` points at the alias set in `with(..., ['as' => ...])`. Without a `with()` + `as` pair that alias doesn't exist — you get an error.

### Controlling the JOIN type of `with()`

By default `with()` does an INNER JOIN (drops entities with no related rows). To keep those with no relation as well, switch to a LEFT JOIN via the `method` option:

```php
use Cycle\ORM\Select\Options\JoinMethod;

$repo->select()->with('orders', ['method' => JoinMethod::LeftJoin]);
```

### Pagination + HasMany

You **can't** load a HasMany via a single JOIN query under a parent `limit()`: `load('orders', new HasManyLoadOptions(method: LoadMethod::SingleQuery))` with a limit throws `LoaderException: Unable to load data using join with limit on parent query`. Cycle doesn't mis-paginate — it forbids the combination outright.

- **You need the relation data** → `load('orders')` without a `method:` — POSTLOAD as a separate query. The root runs with `LIMIT N`, the relation is a separate `SELECT ... WHERE parent_id IN (...)`. The limit is exact, the parent set doesn't blow up.
- **You only need to filter by the relation, in one query** → `with('orders')`. This is a raw INNER JOIN (plain SQL semantics): a user with 3 orders becomes 3 rows. `LIMIT N` cuts join-rows, and hydration deduplicates them by PK — so without `distinct()` you get **fewer** than N entities (with 3 orders each, `limit(2)` returns 1 user). Fix it with `with('orders')->distinct()->limit(N)` — `DISTINCT` collapses the duplicate parent rows before the limit applies.
- **Counting** → `count()` with **no argument** → `COUNT(DISTINCT pk)`, correct under a JOIN. **Not** `count('user.id')`: that's `COUNT(user.id)` over join-rows (returns the order count, not the user count), and `distinct()` has no effect on the aggregate.

---

## `LoadOptions` — typed DTOs

In 2.x, fetch customization happens via DTOs in `Cycle\ORM\Select\Options\*`. A subtle point: **`Select::with()` only accepts an array**, while `Select::load()` accepts either a DTO or an array.

### Hierarchy

```
LoadOptions                          ← base (scope/minify/table)
 ├─ JoinableLoadOptions              ← +method/as/using
 │   ├─ HasOneLoadOptions            ← +where/orderBy
 │   ├─ HasManyLoadOptions           ← +where/orderBy
 │   ├─ BelongsToLoadOptions         ← +where
 │   ├─ ManyToManyLoadOptions        ← +where/orderBy/throughWhere/throughOrderBy
 │   ├─ MorphedHasOneLoadOptions     ← +where/orderBy
 │   └─ MorphedHasManyLoadOptions    ← +where/orderBy
 └─ BelongsToMorphedLoadOptions      ← direct child of LoadOptions, no own fields (scope/minify/table only): morphed belongsTo isn't joined
```

### Example: HasMany with all options

```php
use Cycle\ORM\Select\Options\HasManyLoadOptions;
use Cycle\ORM\Select\Options\LoadMethod;
use Cycle\ORM\Select\Options\JoinMethod;

$select->load('comments', new HasManyLoadOptions(
    method:  LoadMethod::SingleQuery,            // INLOAD: JOIN into the main query
    scope:   false,                              // don't apply the entity's default scope (include soft-deleted)
    as:      'public_comment',                   // table alias
    using:   null,                               // whether to reuse an existing JOIN from with()
    where:   ['@.published' => true],            // @ = target-table alias
    orderBy: ['@.create_time' => 'DESC'],
    table:   'comment_archive',                  // read from a different table
    minify:  true,
));
```

### LoadOptions fields — what each does

| Field      | Type                              | Purpose                                                                       |
|------------|-----------------------------------|-------------------------------------------------------------------------------|
| `method`   | `LoadMethod`/`JoinMethod`/`null`  | Strategy: SingleQuery (INLOAD/JOIN), OuterQuery (POSTLOAD/separate SELECT), InnerJoin/LeftJoin (no hydration, filter only) |
| `scope`    | `ScopeInterface`/`bool`           | `true` (default) — source scope; `false` — disable; `ScopeInterface` — replace |
| `as`       | `?string`                         | Table alias in SQL                                                            |
| `using`    | `?string`                         | Reuse an alias set in `with()` instead of generating a new JOIN               |
| `where`    | `?array`                          | Extra WHERE (with `@.` for the target-table alias)                            |
| `orderBy`  | `?array`                          | Extra ORDER BY                                                                |
| `table`    | `?string`                         | Override the table (archives/partitions)                                      |
| `minify`   | `bool`                            | Minify column aliases in SQL (only disable for debugging)                     |

The old array syntax also works (`load('comments', ['method' => ..., 'where' => [...]])`) — the DTO is a typed wrapper over the same `toArray()`.

### The `@.` placeholder

Inside `LoadOptions::where` and `orderBy`, the `@.` prefix is replaced with the target-table alias of the loaded relation. It's needed because the alias may be auto-generated or set via `as:`.

```php
$select->load('orders', new HasManyLoadOptions(
    where: ['@.status' => 'paid', '@.amount' => ['>' => 0]],
));
// SQL: ... JOIN/SELECT orders AS <alias> WHERE <alias>.status = 'paid' AND <alias>.amount > 0
```

Unlike Select-level `where()`, where dot-notation `relation.column` works by relation name, here the alias is already pinned to this specific loader.

### ManyToMany — pivot filters

`ManyToManyLoadOptions` additionally supports `throughWhere`/`throughOrderBy` — conditions on the pivot table. `@.@.` (double prefix) inside them is the pivot-table alias.

### `loadSubclasses()` — STI/JTI

For STI/JTI entities (`cycle-orm-attributes/resources/inheritance.md`) — control whether to load child-class fields:

```php
$repo->select()->loadSubclasses(false);   // parent fields only
```

Default is `true` — Cycle will pull the discriminator and all child-class fields.

---

## ScopeInterface — how scope influences fetching

`ScopeInterface` (`Cycle\ORM\Select\ScopeInterface`) is a global filter bound to an entity via `#[Entity(scope: MyScope::class)]`. Writing scope classes and DI for them lives in `orm-extensions.md`. Here — how scope is applied at read time and how to bypass it.

### Where scope applies

Scope is applied **automatically**:
- On the entity's root query: `$repo->findByPK()`, `$repo->findOne()`, `$repo->findAll()`, `$repo->select()->...`.
- On every relation load whose target entity has a scope: `$user->orders` (via lazy proxy), `->load('orders')`, `->with('orders')` — everywhere Cycle applies `OrderScope` to the orders query.

That's the whole point of scope: declare once that "`Article` without `delete_time` doesn't exist" and forget about it.

### Bypassing at the root

```php
// Disable scope entirely for this query
$all = $repo->select()->scope(null)->fetchAll();

// Replace with another (e.g. "admin-panel" semantics)
$admin = $repo->select()->scope(new AdminScope())->fetchAll();
```

This is **only** for the root query. Relations still pull with their own scopes.

### Bypassing during relation load (LoadOptions.scope)

`LoadOptions::scope` accepts `ScopeInterface|bool`:
- `true` (default) — apply the target's source scope.
- `false` — disable scope for this load (e.g. to see soft-deleted records in the relation).
- `ScopeInterface` — replace scope for this load.

```php
$select->load('orders', new HasManyLoadOptions(scope: false));  // return soft-deleted orders too

use Cycle\ORM\Select\QueryScope;
$select->load('orders', new HasManyLoadOptions(
    scope: new QueryScope(['@.status' => 'paid']),   // ad-hoc inline scope
));
```

### `QueryScope` — ad-hoc inline scope

`Cycle\ORM\Select\QueryScope` is a ready-made `ScopeInterface` implementation that takes `where`/`orderBy` arrays. Handy for one-off scopes you don't want to factor into a dedicated class. Constructor:

```php
new QueryScope(array $where, array $orderBy = []);
```

Internally `apply()` does `$query->where($this->where)->orderBy($this->orderBy)`. Useful as a fallback when you need a `ScopeInterface` object rather than a domain class.

### Scope vs `where:` in LoadOptions

Both add a condition to the relation query. The differences:
- `where:` — **additive**, the entity's source scope **still** applies (if any).
- `scope:` — **replacement**, the source scope is completely ignored (if `false` or another object is passed).

If you want "default + extra condition" — use `where:`. If you want "return everything, ignore the default" — use `scope: false`.

---

## BulkLoader — post-load for an already-fetched set

`Cycle\ORM\Relation\BulkLoader` (`BulkLoaderInterface`) lets you load relations for a **set of already-fetched** entities in a single batch query. An alternative to re-querying with `Select::load()` or hitting lazy proxies in a loop (N+1).

### When to use

| Scenario                                                                   | Solution                                |
|----------------------------------------------------------------------------|-----------------------------------------|
| Fetching entities and **immediately** knowing relations are needed         | `$repo->select()->load(...)->fetchAll()` |
| Entities already in hand (from another layer, after `findAll`/list of `findByPK`), need relations | **`BulkLoader`** ←                     |
| In a loop, check a condition and decide whether relations are needed      | Collect the needed entities into an array, then `BulkLoader` |
| Relations sometimes needed — don't want `load: 'eager'` in the schema      | `BulkLoader` in those scenarios         |

### Getting an instance

```php
// Via DI (recommended)
final class OrderService
{
    public function __construct(
        private \Cycle\ORM\Relation\BulkLoaderInterface $bulkLoader,
    ) {}
}

// Container binding
$container->bindSingleton(
    \Cycle\ORM\Relation\BulkLoaderInterface::class,
    \Cycle\ORM\Relation\BulkLoader::class,
);
```

Or directly — `new \Cycle\ORM\Relation\BulkLoader($orm)`. The constructor only takes `ORMInterface`.

### Basic workflow

```php
$users = $repo->findAll();   // assume they came from somewhere else

$loader = $bulkLoader
    ->collect(...$users)     // immutable: returns a clone with the entities collected
    ->load('profile')        // named relation
    ->load('orders')
    ->load('posts.comments') // nested via dot-notation
;
$loader->run();              // execute queries and hydrate relations into the entities

// Now each $user has ->profile, ->orders, etc. populated
```

`collect()` is immutable and returns a new object. `load()` mutates the current object and returns `static` for chaining. `run()` executes the load and fills relations via `Mapper::hydrate()`.

### Load options

`load($relation, array|LoadOptions $options = [])` accepts the same options as `Select::load()`:

```php
use Cycle\ORM\Select\Options\HasManyLoadOptions;

$bulkLoader
    ->collect(...$users)
    ->load('comments', new HasManyLoadOptions(
        where:   ['@.approved' => true],
        orderBy: ['@.created_at' => 'DESC'],
    ))
    ->run();
```

For ManyToMany sorting by a pivot column, use `throughOrderBy` with the pivot alias `@.@.`:

```php
use Cycle\ORM\Select\Options\ManyToManyLoadOptions;

$bulkLoader
    ->collect(...$users)
    ->load('tags', new ManyToManyLoadOptions(throughOrderBy: ['@.@.created_at' => 'DESC']))
    ->run();
```

### Limitations

- **Role homogeneity** — all entities must share the same role; `BulkLoader::collect()` validates this and throws `InvalidArgumentException('All entities must belong to the same role.')`. For a heterogeneous set — group by role and use one `BulkLoader` per group.
- **STI/JTI not supported** — polymorphic hierarchies currently behave as a single role and child classes look incompatible.
- **Entities must be in the Heap** — `BulkLoader::run()` reads the entity snapshot from the Heap, and if the node is missing it throws `LogicException("Entity node not found in the heap.")`. Freshly created `new Entity()` (not yet persisted) doesn't qualify.
- **Won't overwrite already-loaded relations** — `run()` only hydrates relations whose current state is a `ReferenceInterface` (still-unresolved proxy/promise). Already-loaded relations aren't clobbered.

### Comparison with `Select::load()`

| Parameter                            | `Select::load()`                                       | `BulkLoader`                                    |
|--------------------------------------|--------------------------------------------------------|-------------------------------------------------|
| When you decide                      | Before executing the entity query                      | Entities already fetched                        |
| Source of entities                   | Current Select                                         | Any set (must all be in Heap and share a role)  |
| Role homogeneity                     | From the schema, automatic                             | Required, validated                             |
| STI/JTI support                      | Yes                                                    | No (todo)                                       |
| Pagination handling                  | Sees the whole query — can pick INLOAD/POSTLOAD        | Always POSTLOAD-style                           |
| Idiomatic for                        | Planned reads                                          | Late binding: "turns out we need relations now" |

---

## Alternative tables and `from()` / `table:`

```php
// Root FROM from another table (archive, partition)
$select->from('user_archive')->wherePK(1)->fetchOne();

// Loading a relation from an alternative table
$select->load('orders', new HasManyLoadOptions(table: 'order_archive'));
```

Entity and relation mapping doesn't change — only the data source. Useful for archives, partitioning, snapshot reads.

---

## Pitfalls

- **`load: 'eager'` in the schema + JOINs everywhere** — `relations.md` already warns about this; for one-off loading prefer `BulkLoader` or an explicit `Select::load()` rather than eager in the attribute.
- **`Select::with()` only takes an array, no DTO.** Passing `new HasManyLoadOptions(...)` into `with()` — TypeError. DTOs only work in `Select::load()`.
- **`with('hasMany')` without `distinct()`** — `LIMIT` returns **fewer** than N entities: parent rows multiply via the JOIN and hydration dedups them by PK. Fix: `load()` without `method:` (POSTLOAD) or `with()->distinct()->limit()`. Count via `count()` with no argument (`COUNT(DISTINCT pk)`), **not** `count('user.id')` (that counts join-rows).
- **`load('comments', new HasManyLoadOptions(using: 'comments'))` without a prior `with('comments', ['as' => 'comments'])`** — alias doesn't exist, error. `using:` is only valid when the corresponding JOIN has already been made via `with()`.
- **`@.` in LoadOptions confused with the alias from `with('rel', ['as' => 'r'])`** — these are different scopes: `@.` is local to the LoadOptions of a specific `load()` call, while `as:` from `with()` lives in the Select-level `where()/orderBy`. They don't overlap.
- **`scope: false` in LoadOptions doesn't disable scope on the root** — these are two independent levels. To disable everywhere — `select()->scope(null)` for the root + LoadOptions `scope: false` per load.
- **`scope: new QueryScope(...)` without `@.` in where** — `QueryScope::apply()` does `$query->where($where)`, and the conditions bind to the current table by default. If you want to target a specific alias — add `@.` to the keys.
- **`BulkLoader` on `new` entities** — `LogicException: Entity node not found in the heap`. Only persisted entities (with a node in the Heap) qualify.
- **`BulkLoader` on a mixed set of roles** — `InvalidArgumentException`. Group by role.
- **`BulkLoader::collect()` returns a clone and the result is dropped** — `$bulkLoader->collect(...$users); $bulkLoader->load(...)` won't work because the return value of `collect()` was discarded. It's immutable: `$loader = $bulkLoader->collect(...)->load(...)`.
- **`Select::load()` on an already-loaded relation** — Cycle reloads it, snapshot is overwritten. If you want idempotency — check state, or use `BulkLoader` (which doesn't overwrite already-resolved relations).
- **`loadSubclasses(false)` behaves differently for JTI vs STI.** Under **JTI** (separate child tables) the flag drops the JOINs to child tables: the entity hydrates as the **parent** class, `instanceof Child` is false, child fields are unset — this is the "parent fields only" case. Under **STI** (single table) the discriminator lives in the shared table and is always read, so the class **still** resolves to the child and the flag has no effect on the instance type. Don't rely on `loadSubclasses(false)` to get a parent instance under STI — it only works for JTI.

## Checklist

1. Decided which to use: `load()` (data needed), `with()` (filter needed), `BulkLoader` (entities already in hand) — or a combination with `using:`.
2. For HasMany/ManyToMany pagination — POSTLOAD (`load()` without `method:`) or an explicit `distinct()`/`count(DISTINCT pk)`.
3. LoadOptions is passed to `load()` via a typed DTO (`HasManyLoadOptions`, etc.); to `with()` — array only.
4. In LoadOptions `where:`/`orderBy:`, keys start with `@.` (target-table alias); for ManyToMany pivot — `@.@.`.
5. Entity scope accounted for: applied automatically at the root; to bypass — `scope(null)`; to load-time bypass — `LoadOptions::scope = false / QueryScope`.
6. When using `BulkLoader`:
   - All entities share the same role.
   - All persisted (present in the Heap).
   - STI/JTI heaps — not yet supported.
   - `collect()` is immutable — assign the result or continue chaining.
7. `loadSubclasses(false)` — only when you intentionally want a parent-without-children hydration.
