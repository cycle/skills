# Repositories: querying

See also:
- creating/persist/delete, transactions → [entity-lifecycle.md](entity-lifecycle.md)
- load strategies (`load()`/`with()`, `LoadOptions`) → `fetching.md`
- the entity and its attributes → `cycle-orm-attributes/references/define-entity.md`
- relation declarations → `cycle-orm-attributes/references/relations.md`
- your own Repository class, global filters (Scope) → `orm-extensions.md`

---

## Getting a Repository

```php
$repo = $orm->getRepository(User::class);   // by class-string
$repo = $orm->getRepository('user');         // by role
```

Returns `Cycle\ORM\RepositoryInterface` (by default — `Cycle\ORM\Select\Repository`). The interface declares only `findByPK()`, `findOne()` and `findAll(array $scope = [])`. `Select\Repository` public API:

```php
public function findByPK(mixed $id): ?object;
public function findOne(array $scope = []): ?object;
public function findAll(array $scope = [], array $orderBy = []): iterable;
public function select(): Select;
public function forUpdate(): static;
```

`select()`, `forUpdate()` and the `$orderBy` argument of `findAll()` exist only on `Select\Repository` — type-hint it (or your subclass) where you call them.

```php
$user  = $repo->findByPK(42);
$user  = $repo->findOne(['email' => 'alice@example.com']);
$users = $repo->findAll(['active' => true], ['create_time' => 'DESC']);

// more complex than `column = value` — via select()
$users = $repo->select()
    ->where('active', true)
    ->where('create_time', '>', $threshold)
    ->orderBy('create_time', 'DESC')
    ->limit(10)
    ->fetchAll();
```

**`select()` returns a clone** of the repository's internal Select; the clone itself is mutable — `where()`/`limit()`/`orderBy()` change it and return `$this`. Two `select()` calls don't interfere; keep the variable you are building.

---

## Naming convention: `find*` vs `get*`

A useful convention for custom query methods (`orm-extensions.md`):

| Prefix   | Signature                | Semantics                                                |
|----------|--------------------------|----------------------------------------------------------|
| `find*`  | `): ?T` (or `): iterable`) | Search — absence is fine, the caller checks `null`/empty array |
| `get*`   | `): T` + `@throws`       | Business invariant: the entity **must** exist; absence → exception |

```php
/** @extends Repository<User> */
class UserRepository extends Repository
{
    public function findByEmail(string $email): ?User
    {
        return $this->select()->where('email', $email)->fetchOne();
    }

    /**
     * @throws UserNotFoundException
     */
    public function getByEmail(string $email): User
    {
        return $this->findByEmail($email)
            ?? throw new UserNotFoundException("User with email {$email} not found");
    }
}
```

**Why keep both:**
- `find*` for read flows where "not found is not an error" (search, autocomplete, optional lookup).
- `get*` for command flows where the `?->` cascade clutters the code and is logically impossible ("pay invoice by id" — if the invoice doesn't exist, that's **already** an error, not a branch).

**Also applies to plural variants:**
- `findAllActive(): iterable` — may be empty.
- `getOnlyActive(int $id): User` — the single active one by id, otherwise throws.

**Built-in Cycle methods** follow the `find*` convention: `findByPK`/`findOne`/`findAll` may always return `null` or an empty set. The default Repository has no paired `get*` — add them yourself per the business invariant.

---

## Immutable builder methods (`where*`, `limit`, ...)

In addition to the "terminal" `find*`/`get*` methods (which immediately return entities), it's useful to have **builder methods** — they return a **new Repository** with an appended condition. This gives composable queries instead of a proliferation of `findActiveByXandYandZ`.

### Contract

- The method returns `static`.
- Inside — `clone $this`, mutation **via `$this->select` directly** (not `$this->select()`), `return`.
- The name is domain-level (`whereOwner`, `active`, `inProject`), not field-level (`whereOwnerId`, `whereStatusActive`).

```php
public function whereOwner(Uid $ownerId): static
{
    $repository = clone $this;
    $repository->select->where('owner_id', $ownerId);  // ← direct, not select()
    return $repository;
}
```

**Why `$this->select` directly, not `$this->select()`:**
- `$this->select()` in the parent `Cycle\ORM\Select\Repository` returns a **clone**.
- In the builder method you've ALREADY done `clone $this`, which via the parent's `__clone()` also clones the internal `$select`.
- If instead of `$repository->select->where(...)` you wrote `$repository->select()->where(...)` — `where(...)` is applied to a **double clone** that's immediately discarded. The condition is not recorded.

```php
// Doesn't work — where goes into a discarded clone
public function whereOwner(Uid $ownerId): static
{
    $repository = clone $this;
    $repository->select()->where('owner_id', $ownerId);   // ← BUG
    return $repository;
}
```

### Example: composable Repository

Entity following Google AIP — `_time`-suffixed timestamps, snake_case, FK columns `<resource>_id`:

```php
#[Entity]
class Order
{
    #[Column(type: 'uuid', primary: true, typecast: Uid::class)]
    public Uid $id;
    #[Column(type: 'uuid', typecast: Uid::class)]
    public Uid $account_id;
    #[Column(type: 'string')]
    public string $status;                       // enum: 'draft'|'pending'|'paid'|'cancelled'
    #[Column(type: 'datetime')]
    public \DateTimeImmutable $create_time;
    #[Column(type: 'datetime', nullable: true)]
    public ?\DateTimeImmutable $delete_time = null;
}

/** @extends Repository<Order> */
class OrderRepository extends Repository
{
    // ---- builder methods ----

    public function whereAccount(Uid $accountId): static
    {
        $repository = clone $this;
        $repository->select->where('account_id', $accountId);
        return $repository;
    }

    public function whereStatus(OrderStatus $status): static
    {
        $repository = clone $this;
        $repository->select->where('status', $status->value);
        return $repository;
    }

    /** Not soft-deleted */
    public function active(): static
    {
        $repository = clone $this;
        $repository->select->where('delete_time', null);
        return $repository;
    }

    public function createdAfter(\DateTimeInterface $threshold): static
    {
        $repository = clone $this;
        $repository->select->where('create_time', '>', $threshold);
        return $repository;
    }

    public function orderByRecent(): static
    {
        $repository = clone $this;
        $repository->select->orderBy('create_time', 'DESC');
        return $repository;
    }

    public function limit(int $limit): static
    {
        $repository = clone $this;
        $repository->select->limit($limit);
        return $repository;
    }

    // ---- terminal (use parent findOne/findAll) ----

    public function findOneByAccount(Uid $accountId): ?Order
    {
        return $this->whereAccount($accountId)->findOne();
    }

    /** @return list<Order> */
    public function listPaidForAccount(Uid $accountId): array
    {
        return $this->whereAccount($accountId)
            ->whereStatus(OrderStatus::Paid)
            ->active()
            ->orderByRecent()
            ->findAll();
    }
}
```

Usage:

```php
// Composition on the application-service side — no proliferation of find methods
$orders = $repo
    ->whereAccount($accountId)
    ->whereStatus(OrderStatus::Pending)
    ->active()
    ->createdAfter($lastWeek)
    ->orderByRecent()
    ->limit(50)
    ->findAll();              // ← parent Repository::findAll(array $scope = [], array $orderBy = [])
```

**Why `->findAll()` with no arguments works after the chain:** the parent `Repository::findAll()` internally calls `$this->select()->where($scope)->orderBy($orderBy)->fetchAll()`. `$this->select()` returns a **clone** of `$this->select` — and the clone **preserves state** (where/orderBy/limit added by the builders). Empty `$scope`/`$orderBy` add nothing. You **don't need** to define your own `findAll()`.

### Domain terms hide the schema

A builder method **is named by business meaning**, not by column name. This gives two benefits:

1. **Caller code doesn't know about columns.** `$repo->active()` reads as a domain condition, not SQL.
2. **The meaning of one word may differ for different entities** — and that's fine:
   ```php
   // For Order: active = "not deleted" (soft-delete)
   class OrderRepository {
       public function active(): static {
           $r = clone $this;
           $r->select->where('delete_time', null);
           return $r;
       }
   }

   // For Subscription: active = "status Active"
   class SubscriptionRepository {
       public function active(): static {
           $r = clone $this;
           $r->select->where('status', SubscriptionStatus::Active->value);
           return $r;
       }
   }

   // For Article: active = "published and publish time has arrived"
   class ArticleRepository {
       public function active(): static {
           $r = clone $this;
           $r->select
               ->where('published', true)
               ->where('publish_time', '<=', new \DateTimeImmutable());
           return $r;
       }
   }
   ```
   The caller at the domain level writes `$repo->active()->findAll()` — the filter implementation inside the Repository can change without touching callers (e.g., a second column `archive_time` is added — the filter expands in one place).

### When to use a builder vs a find method

| Scenario                                                  | What to write                          |
|-----------------------------------------------------------|----------------------------------------|
| One-to-one lookup by a specific key, reused as-is         | `findByEmail($email): ?User`           |
| Often composed with other conditions                      | `whereEmail($email): static`           |
| Business filter, single semantic                          | `active(): static`, `expired(): static`|
| "Non-existent entity is an error"                         | `getByEmail($email): User` (on top of `findByEmail`) |

Builder methods and `find*` **coexist** in one Repository: builders for composition, `find*` for frequent one-off queries or where composition isn't needed.

### Builder-style pitfalls

- **`$this->select()->where(...)` in a builder method** — loses the condition (see above). Use only `$this->select->where(...)` after `clone $this`.
- **Mutating `$this->select` without `clone $this`** — permanently changes the repository instance. The next `findAll()` will carry the condition. `clone $this` is mandatory in every builder.
- **A builder method returns `self` instead of `static`** — when inheriting Repository, child-class chain methods return the parent type, IDE navigation and static typing for child methods are lost. Use `static`.
- **A builder method mixes "add a condition" and "return data"** — `whereActiveAndFetch()` is bad. Builder returns `static` (no I/O), terminal (`find*`, parent `findAll`) executes SQL. Don't mix.
- **A domain method (`active()`) filters by a non-existent field** — passes the schema build silently, fails with an SQL error on first invocation. Tests for every builder are mandatory.

---

## Select: WHERE — all forms

`where()` accepts six different syntaxes. They all assemble into a single WHERE clause:

```php
// 1. column = value
$select->where('id', 2);
$select->where('status', 'active');

// 2. column operator value
$select->where('level', '>=', 10);
$select->where('name', 'LIKE', '%john%');
$select->where('delete_time', '=', null);

// 3. BETWEEN
$select->where('id', 'between', 1, 100);

// 4. Array (AND by default)
$select->where(['key1' => 1, 'key2' => 2]);
$select->where(['id' => new Parameter([1, 2])]);            // IN
$select->where(['id' => ['>' => 0, '<' => 100]]);            // operators
$select->where(['id' => ['between' => [1, 4]]]);

// 5. Logical grouping
$select->where([
    '@or' => [
        ['status' => 'paid'],
        ['status' => 'shipped'],
    ],
]);

// 6. Closure (nested group)
$select->where(function (QueryBuilder $q) {
    $q->where('a', 1)->orWhere(function (QueryBuilder $q) {
        $q->where('b', 2)->where('c', 3);
    });
});

// 7. Raw fragments
use Cycle\Database\Injection\Fragment;
use Cycle\Database\Injection\Expression;

$select->where(new Fragment('LENGTH(name) > ?', 5));
$select->where('a.id', new Expression('b.parent_id'));        // column = column
```

`andWhere()` / `orWhere()` — same arguments, explicit connective. By default `where()` is already AND.

### WHERE by primary key

```php
$select->wherePK(42)->fetchOne();
$select->wherePK(1, 2, 3)->fetchAll();   // IN

// composite PK — arrays
$select->wherePK(['tenant_id' => 5, 'sku' => 'X'])->fetchOne();
$select->wherePK([5, 'X'], [6, 'Y'])->fetchAll();
```

### HAVING

```php
$select
    ->groupBy('user_id')
    ->having('COUNT(comments.id)', '>', 5);
```

`having` / `andHaving` / `orHaving` — same forms as where.

### JSON queries (PG/MySQL 5.7+/MSSQL 2016+)

```php
$select->whereJson('settings->theme', 'dark');                   // exact equality
$select->whereJsonContains('tags', 'php');                        // array contains
$select->whereJsonDoesntContain('tags', 'deprecated');
$select->whereJsonContainsKey('settings->notifications');         // key exists
$select->whereJsonDoesntContainKey('settings->legacy_flag');
$select->whereJsonLength('tags', 3);
$select->whereJsonLength('tags', 5, '>');
```

`or*` versions — `orWhereJson`, `orWhereJsonContains` etc. Implementation depends on the driver; SQLite — partial.

---

## ORDER BY, LIMIT, OFFSET, DISTINCT

```php
$select->orderBy('create_time', 'DESC');
$select->orderBy(['name' => 'ASC', 'id' => 'DESC']);
$select->orderBy(new Fragment('RAND()'));            // raw expression

$select->limit(10)->offset(20);

$select->distinct();
```

---

## Loading relations: `load()` vs `with()`

```php
// load() — pull the relation into the entity
$users = $repo->select()->load('orders')->fetchAll();

// with() — JOIN the relation for filter/sort, WITHOUT hydration
$users = $repo->select()
    ->with('orders')->where('orders.status', 'paid')
    ->fetchAll();
```

Deeper coverage (typed `LoadOptions`, `method:`/`as:`/`using:`/`scope:`, `loadSubclasses()`, post-loading via `BulkLoader`, scope in the fetch context) lives in `fetching.md`.

---

## Aggregations

```php
$count = $select->count();                 // COUNT(DISTINCT pk) — correct under JOIN
$count = $select->count('user_id');         // COUNT(user_id)

$sum = $select->sum('balance');
$avg = $select->avg('score');
$min = $select->min('price');
$max = $select->max('level');
```

`count()` without an argument counts `DISTINCT(PK)` — this is needed with `with()` on HasMany/ManyToMany so JOIN duplicates don't inflate the count. With a composite PK it emits `COUNT(*)` instead, so JOIN duplicates from `with()` on a `*Many` relation are counted.

`limit()` has no effect on `count()`: `limit(1)->count()` still counts every matching row. Call `count()` on a Select without `offset()`.

### exists / just a presence check

There is no separate `exists()` in the API. Use either:
- `$select->count() > 0` — no hydration, counts every matching row,
- `$select->fetchOne() !== null` — reads one row (`LIMIT 1`) and hydrates one entity.

---

## Pagination

```php
public function paginateActive(int $page, int $perPage): array
{
    $filtered = $this->select()->where('active', true);
    $total = $filtered->count();                    // before offset/limit
    $items = (clone $filtered)
        ->offset(($page - 1) * $perPage)
        ->limit($perPage)
        ->fetchAll();
    return ['items' => $items, 'total' => $total];
}
```

`Select` implements `PaginableInterface` from `spiral/pagination` — Spiral's paginator drives it through the `limit()`/`offset()` contract.

---

## Pessimistic locking — `forUpdate()`

```php
use Cycle\ORM\Transaction\Runner;

$db = $dbal->database();
$db->transaction(static function () use ($repo, $em, $id): void {
    $user = $repo->forUpdate()->findByPK($id);   // SELECT ... FOR UPDATE
    $user->balance -= 100;
    $em->persist($user)->run(runner: Runner::outerTransaction());
});
```

`$db->begin()`/`$db->commit()` work the same way; runner choice — `entity-lifecycle.md`, "Runners and transaction policy".

`forUpdate()` on a repository **clones** the repository and marks Select as `FOR UPDATE`. Without a transaction no LOCK is acquired — that's an SQL contract, not Cycle.

You can also do it pointwise:
```php
$user = $repo->select()->wherePK($id)->forUpdate()->fetchOne();
```

---

## Fetching raw data

```php
$rows = $select->fetchData();         // array, through the typecaster (DateTimeImmutable/enum/VO)
$rows = $select->fetchData(false);    // raw, as in the DB (strings/numbers)
```

Useful for analytics queries where an Entity isn't needed:
```php
$stats = $orm->getRepository(Order::class)->select()
    ->where('create_time', '>=', $monthStart)
    ->groupBy('status')
    ->columns(['status', 'COUNT(*) AS cnt'])      // __call → Database\Query\SelectQuery
    ->fetchData(false);
```

Note: methods that aren't on `Select` directly (`columns`, `groupBy`, `join`, ...) go through `__call` into the underlying `Cycle\Database\Query\SelectQuery`. The return is still `Select` (for chaining), results come back through the standard fetch methods.

---

## Iteration: `getIterator()` and large result sets

```php
foreach ($repo->select()->where('active', true) as $user) {
    // $select is an IteratorAggregate. fetchAll() = iterator_to_array(getIterator())
}
```

`getIterator()` and `fetchAll()` read the whole result set into memory before the first entity is hydrated. For **large datasets** stream with `Select::cursor()` — a server-side cursor that yields entities in chunks of at most `$chunkSize` root entities:
```php
$db = $dbal->database();
$db->transaction(static function () use ($repo, $orm): void {
    $n = 0;
    foreach ($repo->select()->where('active', true)->cursor(chunkSize: 500) as $user) {
        // ...
        if (++$n % 500 === 0) {
            $orm->getHeap()->clean();            // otherwise the Heap keeps every streamed entity
        }
    }
});
```

- **Drivers:** Postgres, SQLite, SQL Server. Other drivers (MySQL) throw `DriverException`: "Server-side cursors are not supported for driver ...".
- **Transaction required:** iterating outside a transaction throws `DriverException`, e.g. "SQLite cursor requires an active transaction to guarantee snapshot consistency. ...".
- **Ordering:** the root PK is appended to `ORDER BY`, so rows of one parent stay together; order by root columns, not by a joined child column.
- Driver tuning (Postgres `FETCH FORWARD` size, `WITH HOLD`, SQL Server cursor type) — the second argument, `PostgresCursorOptions` / `SQLServerCursorOptions`.

Fallback for drivers without cursors — an offset loop ordered by PK:
```php
$page = 0;
do {
    $rows = $repo->select()
        ->orderBy('id')
        ->limit(1000)
        ->offset($page * 1000)
        ->fetchAll();
    foreach ($rows as $row) { /* ... */ }
    $orm->getHeap()->clean();
    $page++;
} while (count($rows) === 1000);
```

---

## `from()` — override the table

```php
$select->from('user_archive')->wherePK(1)->fetchOne();
```

The entity mapping is the same (same columns/relations), but FROM is set manually. Applicable for archives, partitions, reading snapshots.

---

## `scope(null)` — bypass the global filter

If the entity has `#[Entity(scope: ...)]` (`orm-extensions.md`):

```php
$repo->select()->scope(null)->fetchAll();          // including soft-deleted/etc.
$repo->select()->scope(new AdminScope())->fetchAll();   // swap in a scope
```

---

## sqlStatement() — debug the SQL

```php
echo $select->sqlStatement();
// SELECT user.id AS c0, user.email AS c1 FROM users AS user WHERE user.active = ? ...
```

Parameters via `?` placeholders; to see their values, check driver logs or wire a Profiler through `Cycle\Database\Logger\LoggerInterface`.

---

## Common pitfalls

- **`fetchOne()` after `where()` — returns the first match, not "the only one".** To guarantee uniqueness — `->limit(2)->fetchAll()` and throw if two rows come back.
- **`count()` without an argument + `with('many-relation')`** — Cycle correctly counts `DISTINCT(PK)`, duplicates don't inflate. But if you pass your own column (`count('id')`) — think about DISTINCT yourself.
- **Reused `$select`** — a Select keeps every `where`/`limit`/`offset` applied to it earlier (only `fetchOne()` works on an internal clone). Start each query from `$repo->select()`.
- **Pagination total taken from the paged Select** — call `count()` on the filtered Select before `offset()`/`limit()` and page a `clone` of it (see "Pagination").
- **`with('hasMany')` without `distinct()`** — pagination over a JOINed HasMany breaks: a single `User` row turns into N (by the number of orders), `limit` cuts join-rows and hydration dedups by PK → fewer than N entities. For fetching use `distinct()` or enable `Options::withGroupByToDeduplicate(true)` (the ORM then adds `GROUP BY pk` when `with()` joins meet `limit()`/`offset()`; wiring — `installation.md`); for counting use `count()` with no argument (`COUNT(DISTINCT pk)`), **not** `count('user.id')`.
- **JSON methods on a driver without JSON support** — will fail. Old SQLite versions don't do JSON path; MySQL <5.7 either.
- **`forUpdate()` outside a transaction** — no lock is acquired; the SELECT runs but with no locking effect.
- **`from('archive')` + `load('relation')`** — relations still load from their own tables per the schema; only the root FROM changes. If you want relations to also come from the archive — `load(..., new ...LoadOptions(table: 'archive_relation'))`.
- **`load()` expects the relation name, not a property name** — they usually coincide, but if in the class-level declaration (`cycle-orm-attributes/references/define-entity.md`) the relation name differs — take the name from the attribute.
- **`@.` in LoadOptions where/orderBy** — this is the target-table alias inside the loader. Don't confuse it with the `with()` alias (where you set `as:` yourself).
- **A large result set via `fetchAll()` or `foreach`** — `getIterator()`/`fetchAll()` load all rows into memory → OOM. Stream with `cursor()` inside a transaction (see "Iteration").
- **Stale entity from the Heap** — `findByPK(42)` twice returns the **same** object. For fresh data from the DB — `$orm->getHeap()->clean()`, `$em->clean(cleanHeap: true)` or a new ORM instance; plain `$em->clean()` keeps the Heap, and `scope(null)` only bypasses the scope.

## Checklist

1. Simple filter `column = value` → `findOne(['col' => $v])` / `findAll([...])`.
2. More complex — `$repo->select()->where(...)->...->fetchAll()`. In a custom repo, mutate `$this->select` only after `clone $this`.
3. Relation in the filter → `with('relation')->where('relation.col', $v)`.
4. Relation in the result → `load('relation')`.
5. Both — both, optionally with `using:` to reuse the JOIN.
6. Pagination — `count()` on the filtered Select before `offset()`/`limit()`; items from its `clone`.
7. Lock — `forUpdate()` INSIDE a transaction.
8. Bypassing the global scope — `->scope(null)`, document in admin cases.
9. `fetchOne()` ≠ assert "exactly one" — it's `LIMIT 1`. For uniqueness — `limit(2)->fetchAll()`, throw on two rows.
10. `with()` + HasMany without `distinct()` breaks pagination.
11. Large result sets — `cursor()` inside a transaction, Heap cleaned between batches.
