# Cycle ORM: built-in mappers

Cycle ships **4 mappers** with fundamentally different behavior. The default is `Cycle\ORM\Mapper\Mapper` (proxy via `extends`, bound to a PHP entity class). When it does not fit — you need `final` classes, want to avoid proxy magic, or have schemaless data — pick one of the other three. This page is about **choosing** a mapper; writing your own (extends Mapper + overriding methods) lives in `orm-extensions.md`.

The mapper is stored in the compiled schema under the `SchemaInterface::MAPPER` key (per role) — see `schema.md` for the full schema format and its sources. The default mapper factory can be overridden globally at the ORM factory level. The ways to assign a mapper to a specific entity under different schema-declaration styles live in the corresponding skills (for attributes — `[[cycle-orm-attributes]]`).

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
- `hydrate()` writes data into properties through `ProxyEntityFactory::upgrade()` (reflection under the hood).
- Supports STI via `SingleTableTrait` — on `init()` it inspects the discriminator column and picks the correct child class from `SchemaInterface::CHILDREN`.

**Hard requirements on the entity class:**
- `final class` — **forbidden** (the proxy cannot `extends`). → `RuntimeException` on first access.
- `readonly` properties — **forbidden** (the hydrator writes via reflection AFTER instantiate). → `Error: Cannot modify readonly property`.
- The entity constructor is **not invoked** on load from DB — the proxy is created without `new YourEntity()`. No side effects in `__construct`.

**What you observe at runtime:**
- `$user instanceof User` → `true` (the proxy `extends User`).
- `$user::class` → the proxy class name, not `User::class`. Direct `get_class($x) === User::class` comparisons will break — use `instanceof` or compare by role (`$orm->getMapper($user)->getRole()`).
- Access to `$user->orders` (typed `iterable`/`Collection`) is transparent. If the relation has already been loaded (eagerly via `load('orders')` or available in the Heap), the materialized collection is returned; otherwise the proxy issues a `SELECT` on first access.
- **Relation properties are typed with regular types** — `public Collection $orders`, `public ?User $owner`. No union with `ReferenceInterface` needed (unlike `PromiseMapper`): the proxy stores the unresolved reference in a hidden field, and the typed property only ever sees the resolved value.
- Resolution in `__get` is **always eager** (`resolve(..., true)`): the proxy does not check the Heap and never returns a `Promise`. This is what makes lazy-load of the default Mapper genuinely "transparent" — and also why **N+1 on iteration without `load()` is just as transparent**.
- **`new YourEntity()` (without `$orm->make()`) is a separate hydration path.** On a plain object (not a proxy) the mapper cannot use the hidden `__cycle_orm_rel_data` field and inspects the relation property type: if no type in the union accepts `ReferenceInterface` (the typical case — `public Post $post`), the mapper has to **eager-resolve** the relation with a real SELECT during the first hydration. Full case with reproduction and three workarounds (`$orm->make()` / union with `ReferenceInterface` / `PromiseMapper`) — `entity-lifecycle.md`.

---

## 2. `Cycle\ORM\PromiseMapper\PromiseMapper` — no proxy, Promise-based

**Package:** `cycle/orm-promise-mapper` (separate, install with `composer require cycle/orm-promise-mapper`). See `repos.md` for the current version.

**How it works:**
- `init()` instantiates your class through `Doctrine\Instantiator\Instantiator` — **bypasses the constructor**, but produces a real instance of your class (no `extends`-proxy).
- `hydrate()` writes through `Laminas\Hydrator\ReflectionHydrator`. For relations it attempts a non-eager `resolve()`: if the related entity is already in the Heap, assigns the actual value; otherwise wraps it in `Cycle\ORM\Reference\Promise`.
- STI — supported via the same `SingleTableTrait`.

**Differences from the default:**
- `final class` — **allowed** (no `extends`-proxy).
- `$user::class === User::class` — **true**.
- **Relations are NOT transparent.** If not eager-loaded via `load()`, `$user->orders` holds a `Promise` object. `foreach ($user->orders)` will fail — `Promise` does not implement `Traversable`. You need either `$user->orders->fetch()` or eager-load.
- **Lazy relation properties must be typed as a union with `ReferenceInterface`** — otherwise PHP's typed-property check throws `TypeError` during hydration. There is **no** automatic eager-resolve fallback when the property type cannot hold a `Promise` (unlike the ClosureHydrator path in `cycle/orm`, which is used on default-Mapper proxies and not engaged here).
- The constructor is still not invoked (Instantiator bypasses it).
- `readonly` is still not allowed: the hydrator writes via reflection.

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

- **`final class` + default Mapper** → `RuntimeException` on first access. Either drop `final` or switch to `PromiseMapper`.
- **Default Mapper + `new YourEntity()` + typed relation property without `?`/union** → an extra `SELECT` during persist (the mapper is forced to eager-resolve because it cannot place a `Reference` into a typed property). Workarounds — `$orm->make()`, a union with `ReferenceInterface`, or `PromiseMapper`. Full case — `entity-lifecycle.md`.
- **`readonly` properties** → broken with all mappers (the hydrator writes via reflection after instantiate). External immutability — `protected`/`private` + getters.
- **PromiseMapper and `foreach ($entity->relation)`** — without `load('relation')`, the slot holds a `Promise`, not a collection. Fails on `Traversable`. Either `->fetch()` or eager-load.
- **PromiseMapper + typed property without `ReferenceInterface`** — `TypeError` at hydration. Lazy-relation properties must be `ReferenceInterface|TargetType`. There is no automatic eager-query fallback.
- **StdMapper + STI** — does not work in principle. If you need a hierarchy, take `Mapper`.
- **`get_class($entity) === Domain\User::class`** for the default Mapper is always `false` (proxy class). Use `instanceof` or the role via `$orm->getMapper($entity)->getRole()`.
- **The constructor is not invoked by any of the four.** If `__construct` was doing default initialization or validation — move it to a factory (see `entity-lifecycle.md`).
- **Swapping the mapper at runtime** — does not work. The mapper is cached in the ORM on first access to the role; change it only at bootstrap.

## Selection checklist

1. Default — `Mapper`. Do not change until you have a concrete pain.
2. `PromiseMapper` — when `final` is required or proxies get in the way (debug/static analysis). Do not forget `composer require cycle/orm-promise-mapper`.
3. `StdMapper` — when there is no PHP class and you do not want one. STI drops out.
4. `ClasslessMapper` — schemaless + transparent relations required.
5. Assign the mapper to an entity through the schema (the `SchemaInterface::MAPPER` key per role) or, under attribute-driven declaration, via `[[cycle-orm-attributes]]`.
6. Custom mapper (extends existing, overriding `init`/`hydrate`/`queue*`) — `orm-extensions.md`.
