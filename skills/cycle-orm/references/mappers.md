# Cycle ORM: built-in mappers

Cycle ships **4 mappers** with fundamentally different behavior. The default is `Cycle\ORM\Mapper\Mapper` (proxy via `extends`, bound to a PHP entity class). When it does not fit — you need `final` classes, want to avoid proxy magic, or have schemaless data — pick one of the other three. This page is about **choosing** a mapper; writing your own (extends Mapper + overriding methods) lives in `orm-extensions.md`.

The mapper is stored in the compiled schema under the `SchemaInterface::MAPPER` key (per role) — see `schema.md` for the full schema format and its sources. To change the default for every entity, pass it to the schema compiler: `(new Compiler())->compile($registry, $generators, defaults: [SchemaInterface::MAPPER => PromiseMapper::class])`. `Factory::withDefaultSchemaClasses([SchemaInterface::MAPPER => ...])` covers only roles whose schema has no `MAPPER` key (hand-written array schemas) — a schema compiled by `cycle/schema-builder` always fills that key. The ways to assign a mapper to a specific entity under different schema-declaration styles live in the corresponding skills (for attributes — `[[cycle-orm-attributes]]`).

## Quick summary

| Mapper             | Package                    | What it instantiates            | Lazy relations                     | STI | `final` classes | Use case                                   |
|--------------------|----------------------------|---------------------------------|------------------------------------|-----|-----------------|--------------------------------------------|
| `Mapper` (default) | `cycle/orm`                | your class via proxy-extends    | transparent through proxy          | yes | no              | 90% of typical domain entities             |
| `PromiseMapper`    | `cycle/orm-promise-mapper` | your class without proxy        | `Promise` wrapper (explicit fetch) | yes | yes             | `final` classes, no proxy magic            |
| `StdMapper`        | `cycle/orm`                | `stdClass`                      | `Promise` wrapper                  | no  | n/a             | schemaless data, ETL, migrations           |
| `ClasslessMapper`  | `cycle/orm`                | anonymous proxy (no user class) | transparent through proxy          | no  | n/a             | schema-driven entities without a PHP class |

All four extend the abstract `Cycle\ORM\Mapper\DatabaseMapper`, which implements low-level `cast`/`uncast`/`queueCreate`/`queueUpdate`/`queueDelete` and integrates with the typecaster. The divergence points are `init`/`hydrate`/`extract`.

---

## 1. `Cycle\ORM\Mapper\Mapper` — the default

**How it works:**
- `init()` creates a proxy class via `ProxyEntityFactory`. The proxy extends your entity class (`class YourEntity Cycle ORM Proxy extends YourEntity`) and adds lazy-load logic for relation properties.
- `hydrate()` writes data into properties through `ProxyEntityFactory::upgrade()` → `ClosureHydrator`: closures bound to the scope of each declaring class, so private and protected properties are written without Reflection.
- Supports STI via `SingleTableTrait` — on `init()` it inspects the discriminator column and picks the correct child class from `SchemaInterface::CHILDREN`.

**Hard requirements on the entity class:**
- `final class` — **forbidden** (the proxy cannot `extends`). → ``RuntimeException("The entity `App\User` class is final and can't be extended.")`` on the first load or `make()`.
- `readonly` — **forbidden**. `readonly` properties are not supported by this mapper; a `readonly class` is a fatal on proxy generation (the proxy cannot extend it). For immutability from the outside, use `private` properties with getters.
- The entity constructor is **not invoked** on load from DB — the proxy is created without `new YourEntity()`. No side effects in `__construct`.

**What you observe at runtime:**
- `$user instanceof User` → `true` (the proxy `extends User`).
- `$user::class` → the proxy class name, not `User::class`. Direct `get_class($x) === User::class` comparisons will break — use `instanceof` or compare by role (`$orm->getMapper($user)->getRole()`).
- Access to `$user->orders` (typed `iterable`/`Collection`) is transparent: a relation eager-loaded via `load('orders')` is already materialized; otherwise the first access resolves it.
- **Relation properties are typed with regular types** — `public Collection $orders`, `public ?User $owner`. No union with `ReferenceInterface` needed (unlike `PromiseMapper`): the proxy stores the unresolved reference in a hidden field, and the typed property only ever sees the resolved value.
- `__get` resolves the stored reference with `load: true` and never returns a `Promise`. A to-one target already in the Heap is returned without a query; a to-one target outside the Heap and every to-many relation cost a `SELECT` on first access. Lazy-load is transparent — and so is **N+1 when iterating without `load()`**.
- **`new YourEntity()` (instead of `$orm->make()`) takes a separate hydration path** that can fire an extra `SELECT` on persist — see `entity-lifecycle.md`.

---

## 2. `Cycle\ORM\PromiseMapper\PromiseMapper` — no proxy, Promise-based

**Package:** `cycle/orm-promise-mapper` (separate, install with `composer require cycle/orm-promise-mapper`).

**How it works:**
- `init()` instantiates your class through `Doctrine\Instantiator\Instantiator` — **bypasses the constructor**, but produces a real instance of your class (no `extends`-proxy).
- `hydrate()` writes through `Laminas\Hydrator\ReflectionHydrator`. For relations it attempts a non-eager `resolve()`: if the related entity is already in the Heap, assigns the actual value; otherwise wraps it in `Cycle\ORM\Reference\Promise`.
- STI — supported via the same `SingleTableTrait`.

**Differences from the default:**
- `final class` — **allowed** (no `extends`-proxy).
- `$user::class === User::class` — **true**.
- **Relations are NOT transparent.** If not eager-loaded via `load()`, `$user->orders` holds a `Promise` object. `foreach ($user->orders)` will fail — `Promise` does not implement `Traversable`. You need either `$user->orders->fetch()` or eager-load.
- **Lazy relation properties must be typed as a union with `ReferenceInterface`** — otherwise PHP's typed-property check throws `TypeError` during hydration. There is **no** automatic eager-resolve fallback when the property type cannot hold a `Promise` (the default Mapper has one, and only for non-proxy objects — bare `new Entity()`; PromiseMapper hydrates through Laminas instead).
- The constructor is still not invoked (Instantiator bypasses it).
- `readonly` properties are initialized on load and on `make()` — the first write goes through Reflection. Any second write by the mapper to an initialized `readonly` property throws `Error: Cannot modify readonly property App\User::$name` — e.g. when the post-persist sync writes a changed column value back.

**Correct typing of relation properties** (from the official `cycle/orm-promise-mapper` README):

```php
#[HasMany(target: Post::class, load: 'eager')]
public array $posts;                          // eager → plain type

#[HasMany(target: Tag::class, load: 'lazy')]
public ReferenceInterface|array $tags;        // lazy → union with ReferenceInterface

#[BelongsTo(target: User::class, load: 'lazy')]
public ReferenceInterface|User $user;         // lazy → union with the target entity
```

Without `ReferenceInterface` in the union for a lazy relation, the first hydration will fail with `TypeError`.

**When to pick:** DDD aggregates that need to be `final` (forbid inheritance); a codebase where proxy classes confuse the debugger/IDE/static analysis. The price — relation properties no longer work transparently; the team has to embrace the `Promise` pattern.

---

## 3. `Cycle\ORM\Mapper\StdMapper` — for stdClass

**How it works:**
- `init()` returns `new \stdClass()` regardless of role.
- `hydrate()` writes values as dynamic properties: `$entity->{$column} = $value`. Relation references are handled as in PromiseMapper — non-eager resolve, then either the actual value or a `Promise`.

**Limitations:**
- **STI is not supported** — stated explicitly in the docblock (`Does not support single table inheritance.`). No discriminator resolution, no child classes.
- **JTI works at the infrastructure level**: the parser injects `@role`, `EntityFactory` routes to the right StdMapper before `init()`, and `DatabaseMapper` merges parent columns into `$columns + $parentColumns`. But `stdClass` instances of parent and child look identical — you cannot tell them apart with `instanceof`. Useful only if `@role` / the column contents are enough to distinguish them.
- The entity is a `stdClass`, so it has no typed properties, no methods, and no constructor. Hook callables bound to entity methods and any class-level domain logic do not work — there are no methods to call.
- **Command-level behaviors still work as usual** — those that register listener classes in `EventDrivenCommandGenerator` (timestamp columns, soft-delete, optimistic lock, UUID generation, etc.). They invoke a listener, not a method on the entity; the `stdClass` instance accepts dynamic property assignment without issue.

**When to pick:** tooling that does not need a PHP class — ETL pipelines, data migrations, reporting/admin queries. Dynamic scenarios where the schema is **generated on the fly or stored in the database** (low-code/no-code builders, multi-tenant setups with per-tenant tables, runtime role assembly) — you cannot write PHP classes for these, and `stdClass` accepts any set of columns.

---

## 4. `Cycle\ORM\Mapper\ClasslessMapper` — anonymous proxy

**How it works:**
- For each role it dynamically generates an anonymous class through `ClasslessProxyFactory` (`eval` with a name like `Cycle\ORM\ClasslessProxy\Classless {$role} N Cycle ORM Proxy`).
- The generated class is a full proxy with the proxy trait, supports transparent access to relations (like the default `Mapper`), but **without a backing user class**.

**Difference from StdMapper:** ClasslessMapper gives you real proxy infrastructure (transparent lazy-load for relations) instead of a bare `stdClass`. Columns and relations are known up front — from the schema.

**Limitations:**
- **STI is not supported at the mapper level** — `SingleTableTrait` is not in use, `init()` uses `$this->role` directly and performs no discriminator-based class dispatch. With STI, every row of the parent role would be instantiated as the same anonymous class — child-role selection by `_type` does not happen.
- **JTI works at the infrastructure level.** Each child is its own role with its own ClasslessMapper; the parser injects `@role` into the row, and `EntityFactory` routes to the right mapper before `init()`. `DatabaseMapper` meanwhile walks the `SchemaInterface::PARENT` chain (see `schema.md`) and merges parent columns with child columns — the child's anonymous proxy is generated with all the required properties. JTI is declared via `Cycle\Schema\Definition\Inheritance\JoinedTable` in the schema-builder — a class-agnostic path that's compatible with ClasslessMapper.
- `$entity::class` is a dynamically generated name — attribute/property checks against a specific classname will not work.

**When to pick:** the same dynamic scenarios as for StdMapper — schema **generated on the fly or stored in the database** (low-code/no-code builders, multi-tenant setups with per-tenant tables, runtime role assembly, schema-builder DSLs, generic dashboards) — but you also need **transparent lazy relations** (access without `->fetch()`). Roughly: "StdMapper plus proxy infrastructure".

---

## Decision tree

1. Do you need an entity with behavior (methods, constructor validation, domain logic)?
   - No → `StdMapper` (dynamic) or `ClasslessMapper` (if you need transparent relations).
   - Yes → next step.
2. Must the class be `final`, or do you categorically reject proxy magic (debug/IDE/static analysis)?
   - Yes → `PromiseMapper` (plus install `cycle/orm-promise-mapper`).
   - No → `Mapper` (default).
3. STI / class hierarchy with a discriminator?
   - Yes → `Mapper` or `PromiseMapper` (both supported). `Std`/`Classless` drop out.

## Pitfalls

- **Defaults or validation in `__construct` never run on loaded entities** → `Mapper` and `PromiseMapper` instantiate the class without calling the constructor → move the initialization into a factory around `$orm->make()` (see `entity-lifecycle.md`).
- **A mapper changed after bootstrap has no effect** → the ORM caches the mapper instance on first access to the role → set the mapper in the schema before the ORM serves its first request.

## Selection checklist

1. The mapper is set per entity: `#[Entity(mapper: PromiseMapper::class)]` with attributes (see [[cycle-orm-attributes]]), or the `SchemaInterface::MAPPER` key in a hand-written schema.
2. Custom mapper (extends existing, overriding `init`/`hydrate`/`queue*`) — `orm-extensions.md`.
