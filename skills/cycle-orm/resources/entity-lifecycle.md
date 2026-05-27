# Entity lifecycle

The entity lifecycle at runtime: **creation → tracking in the Heap → persist → delete**. This file covers correct creation (`$orm->make()` vs bare `new`), writing (`$em->persist/run`), deletion and transactions. Reading (`Select`, repository methods) lives in [repositories.md](repositories.md).

See also:
- relations and their `cascade` — `cycle-orm-attributes/resources/relations.md`
- custom mapper (your own `init`/`hydrate`) — `orm-extensions.md`
- soft-delete as a scope — `orm-extensions.md`; as a behavior — `cycle-orm-attributes/resources/behaviors.md`

---

## Minimum working example

```php
$user = $orm->make(User::class, ['email' => 'alice@example.com']);
$em->persist($user)->run();

$user->name = 'Alice';
$em->persist($user)->run();   // UPDATE — same object, identity by PK

$em->delete($user)->run();    // DELETE
```

---

## Creating an entity

### `$orm->make()` — the recommended path

```php
public function make(
    string $role,
    array $data = [],
    int $status = Node::NEW,
    bool $typecast = false,
): object;
```

What it does:
1. Resolves the role (from a class-string or a role string).
2. Calls `MapperInterface::init($data)` — the mapper decides which class to instantiate (for STI/JTI it picks the child via the discriminator) and how (the default `Mapper` instantiates a **proxy subclass** with infrastructure for lazy-load relations and dirty-tracking; `PromiseMapper` uses `Instantiator` without inheritance).
3. Calls `MapperInterface::hydrate($entity, $data)` — the hydrator writes fields via reflection (the constructor is not called).
4. Registers the entity in the **Heap** with status `Node::NEW`.

The `$typecast: true` argument runs `$data` through `Mapper::cast()`. Useful when the data comes from an external source in "raw" form (`'2026-01-01'` instead of `DateTimeImmutable`, `'42'` instead of `int`). Default is `false`, since code usually builds already-typed values.

```php
// Already-typed data — no typecast needed
$user = $orm->make(User::class, [
    'email' => 'alice@example.com',
    'create_time' => new \DateTimeImmutable(),
]);

// Raw input (CSV/HTTP/SQS) — typecast required
$user = $orm->make(User::class, $rawRow, typecast: true);
```

Leave `$status` as `Node::NEW` for **new** entities. `Node::MANAGED` is for niche cases (restoring an entity from an external snapshot with an existing PK without hitting the DB) — not a thing in normal code.

### Bare `new` — when it's OK, when it's not

Technically `$em->persist(new User(...))->run()` works. Assigning FK columns directly (`$comment->postId = 1`) is a perfectly normal pattern. **The subtlety is in the combination:** default mapper + a bare-`new` entity + a relation property whose type has no slot a `Reference` object can fit into (bare `Post`, `?Post`, any strict typing without a union containing `ReferenceInterface`) → persist fires an unexpected SELECT. Remove any one link of that chain and the problem goes away.

```php
#[Entity]
class Comment
{
    #[Column(type: 'primary')]
    public int $id;

    #[Column(type: 'integer')]
    public int $postId;       // ← FK column declared explicitly

    #[BelongsTo(target: Post::class, innerKey: 'postId')]
    public Post $post;        // ← strict-typed, no union, no `?`
}

$c = new Comment();
$c->postId = 1;             // only the FK; the typed $post is left untouched

$em->persist($c)->run();
// INSERT INTO comment ...
// SELECT * FROM post WHERE id = ?    ← UNEXPECTED
```

Where the extra SELECT comes from — the actual mechanism:

- **For a proxy entity** (built via `$orm->make()` or loaded through Select), the default mapper stores the unresolved relation reference (`ReferenceInterface`) in a hidden proxy field, and the typed relation property itself stays `unset` until the first `__get`. This is what allows lazy-loading `Post`.
- **For a plain `new Comment()`** there is no proxy — no hidden field, no `__get` magic. After `persist`, Cycle re-hydrates the entity from its new state and has to place a value **directly into the typed `$post` property**. The hydrator inspects the type: if the union contains a class/interface that `Reference` is an instance of, it stores a `Reference` (no SELECT). If no type fits (as with a bare `Post` — it is not an ancestor of `Reference`) — the only way to assign a value of a compatible type is **eager resolution with a real SELECT against the FK**.

That is exactly why a union with `ReferenceInterface` (option 2 below) lifts the problem: the type now accepts a `Reference`, and the mapper defers resolution.

**What to do depends on the mapper:**

**With the default `Mapper` (proxy-based)** — two options:

1. **`$orm->make()`** — the idiomatic path for the default mapper:
   ```php
   $c = $orm->make(Comment::class, ['content' => $content]);
   $c->postId = $command->postId;
   $em->persist($c)->run();   // INSERT only
   ```
   `make()` instantiates the entity through the same proxy that Select uses. Re-hydration after INSERT drops a `Reference` into the hidden proxy slot — no SELECT.

2. **Union type on the relation property** (a real union — `?Post` does not count) — if you want to keep bare `new`:
   ```php
   use Cycle\ORM\Reference\ReferenceInterface;

   #[BelongsTo(target: Post::class, innerKey: 'postId')]
   public Post|ReferenceInterface $post;
   ```
   Now Cycle has a type slot to drop a `Reference` into, even without a proxy. The cost: on read, the property may be a `Reference` and you must resolve it via `$orm->resolve($post)`.

**With `PromiseMapper`** (`cycle/orm-promise-mapper`) — a union with `ReferenceInterface` is mandatory (the mapper stores relations through `Reference` natively, without proxy inheritance). Once you have it, `new Entity()` becomes a first-class pattern, the extra-SELECT trap goes away, and `$orm->make()` is no longer required. Fits projects where explicit relation loading is the norm.

**When bare `new` is safe:** entities without relations; or where the relation property has **no** type declaration, or is typed as `object`/`mixed`, or has a union containing a type `Reference` is an instance of (in practice — `ReferenceInterface`). `?Post` (= `Post|null`) **does not save you** — reflection sees a single `Post` with `allowsNull`, not a union, and the hydrator still eagerly resolves. In all the safe cases `new Entity(); $entity->fkColumn = ...; $em->persist();` is a correct path.

### Where to put the `make()` call

- **A service or domain factory** is the right place. The factory encapsulates creation invariants and depends on `ORMInterface`.
- **Not the Repository.** A repository is a read-side collection; mixing read and creation leads to confused contracts (`UserRepository::createAndSave()` is an anti-pattern). Persist goes through `EntityManagerInterface` separately.

```php
final class UserFactory
{
    public function __construct(
        private readonly ORMInterface $orm,
    ) {}

    public function create(string $email): User
    {
        $user = $this->orm->make(User::class, [
            'email' => $email,
            'create_time' => new \DateTimeImmutable(),
        ]);
        // domain invariants, id generation, events go here
        return $user;
    }
}
```

---

## Persist

`EntityManagerInterface` (`Cycle\ORM\EntityManagerInterface`) is the default entry point for writes. In DI containers it is usually registered as a singleton — fine for the simple "one EM per request / tick / command" path. If you need several isolated write scopes inside one process (nested transactions on different aggregates, parallel pipelines, a side-handler running inside the main flow), the singleton EM is not the right tool — drop down to the `UnitOfWork` class directly. See [[cycle-orm-best-practice]].

```php
interface EntityManagerInterface
{
    public function persist(object $entity, bool $cascade = true): self;
    public function persistState(object $entity, bool $cascade = true): self;
    public function delete(object $entity, bool $cascade = true): self;
    public function run(): StateInterface;
    public function clean(): static;
}
```

**Basic cycle:**

```php
$user = $orm->make(User::class, ['email' => 'alice@example.com']);

$em->persist($user)->run();    // ← INSERT
$user->name = 'Alice';
$em->persist($user)->run();    // ← UPDATE (same object, identity by PK)
```

`persist()` is **queueing**, not writing. SQL runs only on `run()`. You can queue many entities and apply them in a single batch:

```php
foreach ($batch as $row) {
    $em->persist($orm->make(User::class, $row));
}
$em->run();   // all INSERTs in a single transaction
```

### Runners and transaction policy

The interface declares just `run(): StateInterface`, but the concrete `Cycle\ORM\EntityManager` class accepts two optional parameters:

```php
public function run(bool $throwException = true, ?RunnerInterface $runner = null): StateInterface
```

- `$throwException` — whether to re-throw on failure; with `false`, the failure surfaces through the returned `StateInterface` (when the caller wants to react to it explicitly).
- `$runner` — transaction-management strategy. Default (`null`) → UoW uses `Runner::innerTransaction()`.

`Cycle\ORM\Transaction\Runner` factories:

- **`Runner::innerTransaction()`** (default). Cycle opens a transaction on each `Driver` involved, commits on success, rolls back on failure. Fits when `run()` is itself the atomic write boundary.
- **`Runner::outerTransaction(strict: true)`**. Cycle opens and closes nothing — it expects you to have already opened a transaction externally (`$db->begin()`). For each driver it checks a transaction is in progress; otherwise it throws `RunnerException`. Use when `run()` is part of a larger transaction (several UoW runs under one frame, manual composition with native SQL).
- **`Runner::outerTransaction(strict: false)`**. Same as above but without the check — Cycle does not touch driver transaction state at all. Use when part of the UoW intentionally runs without transactions (replication, migrations, sidesteps).

In both outer modes Cycle still calls `complete()` / `rollback()` on commands implementing `CompleteMethodInterface` / `RollbackMethodInterface` — that's about domain post-effects, not DB transactions.

```php
$db = $dbal->database('default');
$db->begin();
try {
    $em->run(runner: Runner::outerTransaction());     // does not touch begin/commit
    $em2->run(runner: Runner::outerTransaction());    // a second UoW in the same transaction
    $db->commit();
} catch (\Throwable $e) {
    $db->rollback();
    throw $e;
}
```

### `persist` vs `persistState`

- **`persist`** — deferred snapshot: state is read at `run()` time. Mutations after `persist()` but before `run()` make it into the SQL.
- **`persistState`** — immediate snapshot: state is captured **now**, subsequent mutations are ignored by this `run()`.

In 95% of cases you want plain `persist`. `persistState` covers the niche of "freeze the state before some process mutates it further."

### `cascade`

`persist($entity, cascade: true)` also queues related entities — those relations whose attribute has `cascade: true` set (see `cycle-orm-attributes/resources/relations.md`). With `cascade: false` only `$entity` itself is processed; related ones must be `persist`-ed separately.

`delete($entity, cascade: true)` — same idea for deletes (assuming the relation supports cascade-delete at the FK or model level).

---

## Delete

```php
$em->delete($user)->run();
```

There's no built-in **soft-delete** API — assemble it from parts:

1. **`SoftDelete` behavior** (`cycle/entity-behavior`) — adds a `deleted_at` column and writes a timestamp instead of a real DELETE. See `cycle-orm-attributes/resources/behaviors.md`.
2. **Reads with a `deleted_at` filter** — two options, not mutually exclusive:
   - **Scope on the entity** — a global filter that hides "deleted" rows in every Select automatically (`#[Entity(scope: NotDeletedScope::class)]`). Applies to all queries without anyone remembering to. See `orm-extensions.md`.
   - **Scope methods on a custom repository** — clone the repository, layer a condition onto its inner `Select`, and return the new repository. This is exactly how the built-in `forUpdate(): static` works. Visible in code, chainable, and repository-level methods (`findAll`/`findOne`/`findByPK`) immediately respect the filter.

   ```php
   use Cycle\ORM\Select\Repository;

   /** @extends Repository<User> */
   final class UserRepository extends Repository
   {
       public function active(): static
       {
           $repo = clone $this;           // the base __clone deep-clones the Select
           $repo->select->where('deleted_at', null);
           return $repo;
       }
   }

   $activeUsers = $userRepo->active()->findAll();
   $matching    = $userRepo->active()->findAll(['email' => 'x@example.com']);
   ```

The behavior writes the column; either the scope or repository methods filter on it. Scope wins when "hide deleted" is a domain-wide invariant; explicit methods win when the filter is local and you want it visible in code.

---

## Transactions

`run()` is **transactional on its own** — all queued operations run atomically (assuming the driver supports transactions). You only need an explicit wrapper to include **additional non-ORM operations** (writing to another store, logging, issuing credits, etc.) in the same transaction:

```php
$dbal = $orm->getSource(User::class)->getDatabase();
$dbal->transaction(function () use ($em, $user, $auditLog) {
    $em->persist($user)->run();
    $auditLog->log('user.updated', $user->id);
});
```

When several drivers/DBs are involved, `run()` does "saga-style" coordination — it tries to commit all participating transactions and rolls back if one fails. This is not XA-grade; cross-DB operations in production need a different design (outbox, eventual consistency).

### `clean()`

Drops the queue without executing it. Rarely needed — the queue is already empty after `run()`. Useful if you want to discard accumulated `persist()` calls.

---

## Common pitfalls

- **Extra SELECT after INSERT with `new` + typed relation property** — see the Comment/Post case above. Fixes: `$orm->make()`, union with `ReferenceInterface`, or `PromiseMapper`.
- **Forgot `->run()`** — changes never reach the DB. `persist()` queues; `run()` executes.
- **`persist()` without a follow-up `run()` in a script/test** — the classic "everything succeeded but the DB is empty." If a helper wraps the call, check that the helper actually runs `run()`.
- **Mutating an entity between `persist()` and `run()`** — those mutations DO make it to the DB (the snapshot is taken at `run` time). Want a snapshot now — `persistState()`.
- **`persistState()` on an entity that isn't NEW or MANAGED** — behavior is undefined; only use it for entities already registered in the Heap.
- **`make($class, $rawData)` without `typecast: true`** — fields stay in raw form. The hydrator doesn't throw (it writes what you gave it), but a property typed `DateTimeImmutable` will hold a `string` and blow up with `TypeError` on first read.
- **`delete()` without cascade — related rows aren't deleted** automatically. Cascade has to live either in the relation attribute or in the FK schema (`ON DELETE CASCADE`).
- **The Heap cache makes `findByPK(42)` idempotent** within a single `$em`. To force a fresh read from the DB — `$em->clean()` or a new ORM instance. Scope bypass (`->scope(null)`) doesn't help: the Heap caches independently of scope.
- **Using the Repository as a creation site** — anti-pattern. Create through a service/factory, the repository only reads.
- **`new` for an STI/JTI parent** — `new Animal()` instantiates exactly `Animal`, even if the `discriminator` says it should be `Dog`. With `$orm->make(Animal::class, ['type' => 'dog'])` the mapper picks the correct class.

---

## Checklist

1. Create via `$orm->make($class, $data)`, not `new` — especially with typed relations or inheritance (STI/JTI).
2. `$typecast: true` for external data (HTTP/CSV/queue); default `false`.
3. Creation lives in a service/factory, not in the repository.
4. Writing: `$em->persist($entity)->run()`. Batch — many `persist()`, one `run()`.
5. `cascade: true` (default) — related entities are written together; explicit `false` — only the object itself.
6. Soft-delete — via a behavior or a scope, not by hand-zeroing `deleted_at` (you lose transparency).
7. Multi-step business operations including non-ORM writes — wrap in `$dbal->transaction(...)`, don't stack `run()` calls.
8. After a failed `run()` — the Heap may already be corrupted (some entities got new PKs, some didn't); don't reuse the `$em`, bring up a fresh one.
