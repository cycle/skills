# Cycle ORM extensions: Repositories, Scopes, Mappers

This file is about **customizing** the ORM layer (writing your own Repository/Scope/Mapper). Day-to-day usage of the Select API + EntityManager (`findByPK`/`select()->where`/`persist`/`run`/...) lives in `repositories.md`.

Three extension points beyond attribute markup:

- **Repository** — named query methods for an entity (`findActive`, `findByEmail`).
- **Scope** — global conditions applied **to every** query for the entity (soft-delete, multi-tenancy).
- **Mapper** — customization of hydration / extract / lifecycle CRUD commands.

All three are configured via `#[Entity(...)]` (`cycle-orm-attributes/references/define-entity.md`) and implemented by classes from `cycle/orm`.

Related: `cycle-orm-attributes/references/column-types.md` — converting column values; often this is what you need instead of a custom mapper.

---

## Boundary: where to look

| What you want to do                                   | Solution                      |
|-------------------------------------------------------|-------------------------------|
| Turn a DB string into `DateTimeImmutable`/Enum/VO     | **Typecaster** (`cycle-orm-attributes/references/column-types.md`) |
| Add `findActive()` / `findByEmail()` / pagination     | **Repository** ←              |
| Apply a condition to every query                      | **Scope** ←                   |
| Pick the entity class per row during hydration        | **Mapper** ← (`resolveClass()`) |
| Soft-delete at the SQL level (DELETE → UPDATE delete_time) | **Mapper** ← (possible)  |
| Soft-delete filtering (`WHERE delete_time IS NULL`)    | **Scope** ← (for the filter) |
| Virtual/computed fields                                | a getter on the entity (best) or **Mapper** |
| Lifecycle events (audit, notification on save)         | **Mapper** or an external listener |

**Rule of thumb:**
1. First look at a typecaster.
2. Then a repository.
3. Then a scope.
4. Mapper is the last resort, for the most non-standard things.

---

# 1. Repositories

## Custom Repository

```php
use Cycle\ORM\Select\Repository;

#[Entity(repository: UserRepository::class)]
class User { /* ... */ }

/** @extends Repository<User> */
class UserRepository extends Repository
{
    public function findByEmail(string $email): ?User
    {
        return $this->select()->where('email', $email)->fetchOne();
    }

    public function findActive(int $limit = 100): iterable
    {
        return $this->select()
            ->where('active', true)
            ->orderBy('create_time', 'DESC')
            ->limit($limit)
            ->fetchAll();
    }

    public function paginate(int $page, int $perPage): array
    {
        $select = $this->select();
        return [
            'total' => $select->count(),   // before offset()/limit(): count() keeps them
            'items' => $select->offset(($page - 1) * $perPage)->limit($perPage)->fetchAll(),
        ];
    }
}
```

**You must** extend `Cycle\ORM\Select\Repository` — it gives you `$this->select` (the clean Select for the entity role) and the standard `findByPK`/`findOne`/`findAll`.

Querying API — `repositories.md`: `find*` vs `get*` naming, the immutable builder style, `forUpdate()`, `load()` vs `with()`.

A repository holds read queries only: writes go through EntityManager, global filters into a Scope, business logic onto the entity or a domain service, hydration into a Mapper.

---

# 2. Scopes

`Scope` is a **global filter** applied to every query for a specific entity.

## ScopeInterface

```php
namespace Cycle\ORM\Select;

interface ScopeInterface
{
    public function apply(QueryBuilder $query): void;
}
```

A single method that receives a `QueryBuilder` and attaches a condition. Cycle invokes it **after** any user-supplied where-conditions have already been registered on the underlying `SelectQuery`. That timing matters for `wrapWhere()` (below): by the time `apply()` runs, the scope can wrap whatever the user added.

## Recommended pattern: `wrapWhere()` at the top of `apply()`

Start `apply()` with `$query->wrapWhere()`, then add your scope conditions. `wrapWhere()` encloses the already-registered where-tokens in parentheses; conditions added after it sit on top and AND with that group. This protects the scope from being bypassed via user `orWhere` (explained below). When no where-tokens have been registered yet, `wrapWhere()` is a no-op — so it is always safe to call.

```php
use Cycle\ORM\Select\ScopeInterface;
use Cycle\ORM\Select\QueryBuilder;

class NotDeletedScope implements ScopeInterface
{
    public function apply(QueryBuilder $query): void
    {
        $query->wrapWhere();                 // enclose already-registered wheres
        $query->where('delete_time', null);  // scope condition stays outside the parens
    }
}

#[Entity(scope: NotDeletedScope::class)]
class Article
{
    #[Column(type: 'primary')]
    public int $id;
    #[Column(type: 'string')]
    public string $title;
    #[Column(type: 'datetime', nullable: true)]
    public ?\DateTimeImmutable $delete_time = null;
}
```

- `$repo->findAll()` returns only `delete_time IS NULL` records.
- `$repo->findByPK(42)` also applies the scope.

### Why `wrapWhere()`: the `orWhere` trap

SQL precedence: `AND` binds tighter than `OR`. Without `wrapWhere()`, a scope that just does `$query->where('delete_time', null)` gets defeated by a user `orWhere`:

```php
$select->where('id', 1)->orWhere('id', 2);
// scope appends: AND delete_time IS NULL
//
// result: WHERE {id} = 1 OR {id} = 2 AND {delete_time} IS NULL
//       ≡ WHERE {id} = 1 OR ({id} = 2 AND {delete_time} IS NULL)
```

The row with `id = 1` passes regardless of `delete_time` — the scope is silently bypassed on the first OR arm. With `wrapWhere()` the same query compiles to

```sql
WHERE ({id} = 1 OR {id} = 2) AND {delete_time} IS NULL
```

— and the scope holds no matter how user code mixes AND/OR. For loader-attached scopes (on joins), `QueryBuilder` forwards `where*` to `onWhere`; `wrapWhere()` works the same way.

## Multi-tenancy

```php
class TenantScope implements ScopeInterface
{
    public function __construct(
        private TenantContext $context,
    ) {}

    public function apply(QueryBuilder $query): void
    {
        $query->wrapWhere();
        $query->where('tenant_id', $this->context->currentTenantId());
    }
}

#[Entity(scope: TenantScope::class)]
class Order { /* ... */ }
```

For multi-tenancy `wrapWhere()` is mandatory: a single forgotten parenthesis around an OR in user code is enough for a query to start returning rows from another tenant.

**DI:** Cycle creates the scope via the container. The constructor may accept any services — the container will inject them.

## QueryBuilder in a scope

Inside `apply()` the full Select API is available (via the QueryBuilder wrapper):

```php
public function apply(QueryBuilder $query): void
{
    $query->wrapWhere();
    $query->where('active', true);
    $query->where('expires_at', '>', new \DateTimeImmutable());
    $query->orderBy('priority', 'DESC');
    $query->with('owner')->where('owner.verified', true);  // even JOINs are possible
}
```

## Bypassing the scope for a specific query

```php
$repo->select()
    ->scope(null)                  // disable scope entirely
    ->fetchAll();

$repo->select()
    ->scope(new AdminScope())      // swap in a different scope
    ->fetchAll();
```

From the repository it's convenient to add a separate method so the scope-bypass is **explicit** in the API:

```php
public function findAllIncludingDeleted(): iterable
{
    return $this->select()->scope(null)->fetchAll();
}
```

## One scope per entity

`#[Entity(scope: ...)]` takes one class-string. Compose several conditions in one scope class, and start `apply()` with `wrapWhere()`:

```php
class CompoundScope implements ScopeInterface
{
    public function __construct(private TenantContext $tenant) {}

    public function apply(QueryBuilder $query): void
    {
        $query->wrapWhere();
        $query->where('delete_time', null);
        $query->where('tenant_id', $this->tenant->id);
    }
}
```

## Scope and relations

A scope **is applied when loading relations too**. If `Comment` has `NotDeletedScope`, then `$article->comments` will load only non-deleted ones. This is usually what you want.

Bypassing and overriding scope at load time (`LoadOptions::scope` — `bool` / `ScopeInterface`, `QueryScope` for ad-hoc inline conditions) — `fetching.md`.

---

# 3. Mappers

`Mapper` is responsible for the **entity lifecycle**: creating the empty object (`init`), writing data into properties (`hydrate`), reading properties (`extract`), generating SQL commands for CRUD (`queueCreate`/`queueUpdate`/`queueDelete`).

**This is a rare need.** In 95% of cases you don't need a custom mapper — typecaster, repository, and scope cover everything.

The catalog of built-in mappers (default `Mapper`, `PromiseMapper`, `StdMapper`, `ClasslessMapper`), how the default `Mapper` creates, hydrates and extracts entities, and the rules for choosing among them live in `mappers.md`. The section below is about writing your **own** mapper by extending one of the built-in ones.

## MapperInterface

```php
interface MapperInterface
{
    public function getRole(): string;
    public function init(array $data, ?string $role = null): object;
    public function cast(array $data): array;
    public function uncast(array $data): array;
    public function hydrate(object $entity, array $data): object;
    public function extract(object $entity): array;
    public function fetchFields(object $entity): array;
    public function fetchRelations(object $entity): array;
    public function mapColumns(array &$values): array;
    public function queueCreate(object $entity, Node $node, State $state): CommandInterface;
    public function queueUpdate(object $entity, Node $node, State $state): CommandInterface;
    public function queueDelete(object $entity, Node $node, State $state): CommandInterface;
}
```

**Never implement this from scratch.** Extend `Cycle\ORM\Mapper\Mapper` and override only the methods you need.

## Registering a custom mapper

```php
#[Entity(mapper: CustomMapper::class)]
class Invoice { /* ... */ }
```

```php
use Cycle\ORM\Mapper\Mapper;
use Cycle\ORM\ORMInterface;
use Cycle\ORM\Mapper\Proxy\ProxyEntityFactory;

class CustomMapper extends Mapper
{
    public function __construct(
        ORMInterface $orm,
        ProxyEntityFactory $entityFactory,
        string $role,
        // your extra dependencies — Cycle will inject via the container
        private MyService $myService,
    ) {
        parent::__construct($orm, $entityFactory, $role);
    }
}
```

## Typical scenarios

### Soft-delete at the SQL level (DELETE → UPDATE)

```php
use Cycle\ORM\Heap\Node;

class SoftDeleteMapper extends Mapper
{
    public function queueDelete(object $entity, Node $node, State $state): CommandInterface
    {
        // instead of physical deletion — update delete_time
        $state->register('delete_time', new \DateTimeImmutable());
        $command = $this->queueUpdate($entity, $node, $state);
        $state->setStatus(Node::SCHEDULED_DELETE);
        return $command;
    }
}
```

Register the new value on `$state`. Assigning `$entity->delete_time` inside `queueDelete()` is not picked up: the UPDATE runs without the column and the row stays undeleted.

**Alternative without your own Mapper:** the `#[SoftDelete]` attribute from `cycle/entity-behavior` does the same thing declaratively — see `cycle-orm-attributes/references/behaviors.md`. A scope for filtering SELECTs is still needed separately.

Pairs with the `NotDeletedScope` (above) — filtering on reads + replacing DELETE with UPDATE on "deletion". Full-fledged soft-delete.

### Virtual/computed fields during hydration

```php
public function hydrate(object $entity, array $data): object
{
    $entity = parent::hydrate($entity, $data);
    $entity->display_name = "{$entity->first_name} {$entity->last_name}";
    return $entity;
}
```

(But usually it's simpler — a PHP getter `getDisplayName()` without any mapper.)

### Picking the entity class per row

```php
class AccountMapper extends Mapper
{
    protected function resolveClass(array $data, ?string $role = null): string
    {
        return !empty($data['is_admin']) ? AdminAccount::class : parent::resolveClass($data, $role);
    }
}
```

`init()` asks `resolveClass()` which class to instantiate; `$data` is the row before typecast. Return a subclass of the entity class, and call `parent::resolveClass()` for the rest to keep STI discriminator handling (`cycle-orm-attributes/references/inheritance.md`).

## What to extend

- `Cycle\ORM\Mapper\Mapper` — the default, with proxy and STI. The most common base class for a custom mapper.
- `Cycle\ORM\Mapper\DatabaseMapper` — abstract base, without `init`/`hydrate`/`extract`. Only pick this if you are writing a mapper with a fundamentally different hydration model.
- Full catalog of built-in mappers and a comparison table — `mappers.md`.

---

## Common pitfalls (across all three extensions)

### Repository

- **A condition that should stick across calls** → a builder method that returns a modified clone (builder style in `repositories.md`); a condition for every query is a **Scope**.
- **N+1 on iteration** — `findAll()` without `load()` will trigger lazy loading of the relation for each entity.

### Scope

- **Scope without `wrapWhere()` breaks under `orWhere`** — a user `where(...)->orWhere(...)` leaves the first OR arm unconstrained by the scope (`AND` binds tighter than `OR`). Always start `apply()` with `$query->wrapWhere()`, then add your conditions. Especially critical for multi-tenancy and any security-bearing scope.
- **No bypass method** → admin/export/migration code silently works on filtered data. Add explicit `find*IncludingDeleted()`-style methods.
- **An expensive scope** (e.g., JOIN + complex WHERE on every findByPK) will hit the latency of every query.
- **A scope that reads from request/session** — won't work when an entity is hydrated in a background job (a worker with no session). Pass context explicitly.
- **Multi-tenancy without a scope** — works (filter in every repository method), but **every** forgotten condition can leak a record from another tenant — a data leak.
- **Side effects in `apply()`** — don't. `apply` is called often.

### Mapper

- **"Mapper doesn't invoke my `__construct`"** — by design: the default mapper instantiates entities without calling the constructor (`mappers.md`).
- **Custom mapper, but you forgot `parent::__construct(...)`** — `$role` and `$schema` aren't initialized, the mapper fails on the first access to the schema.
- **DI in the mapper constructor doesn't work** — Cycle builds mappers through the container passed as the third argument of `new Factory($dbal, $config, $container)` (a `Spiral\Core\FactoryInterface`), supplying `orm`, `role` and `schema` by name. Check that this container resolves your extra dependencies.
- **A mapper per entity is architectural garbage.** If you have 30 entities and a mapper for each — something went wrong. A mapper is the exception, not the rule.
- **Business logic in a mapper** — `Invoice::send()` doesn't belong in InvoiceMapper. A mapper is the ORM layer.

---

## Checklist

### When adding a query method
1. This is a **named query** for an entity → a method on its Repository.
2. Repository extends `Cycle\ORM\Select\Repository`, not implementing `RepositoryInterface` from scratch.
3. Every `find*` method starts from `$this->select()`; builder methods follow the builder style in `repositories.md`.
4. Registered via `#[Entity(repository: ...)]`.
5. PHPDoc `@extends Repository<EntityClass>` for typing.

### When adding a global condition
1. The condition applies to **all** queries (every findByPK, findAll, relation load) → Scope.
2. Scope implements `Cycle\ORM\Select\ScopeInterface`.
3. `apply()` starts with `$query->wrapWhere()` (protection from `orWhere` bypass).
4. Registered via `#[Entity(scope: ...)]`.
5. The bypass (`select()->scope(null)`) is documented.
6. Every scope constructor dependency is resolvable by the container passed to `Factory`.

### When customizing a mapper
1. Confirmed: the task isn't solvable by a typecaster / repository / scope.
2. Your mapper extends `Cycle\ORM\Mapper\Mapper` (not implementing MapperInterface from scratch).
3. The constructor calls `parent::__construct($orm, $entityFactory, $role)`.
4. Registered via `#[Entity(mapper: ...)]`.
5. No business logic in the mapper; only the ORM layer.
