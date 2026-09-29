---
name: cycle-orm
description: Cycle ORM 2.x runtime — bootstrapping, querying, persisting, and extending the ORM. Use when installing or bootstrapping Cycle (spiral/cycle-bridge, standalone Compiler+Factory+ORM, Laravel/Symfony/Yii3 bridges, schema cache, `Cycle\ORM\Options`); querying via Repository/Select (`findByPK`, `where`, `load`/`with`, pagination, aggregations, `forUpdate`, `cursor`); creating and saving entities (`$orm->make()` vs `new`, EntityManager `persist`/`delete`/`run`, cascade, transactions); configuring `*Many` collections; writing a custom Repository, Scope, or Mapper; implementing typecast handlers; reading or hand-building the compiled schema (`SchemaInterface`, `Relation::*`); debugging runtime or schema-build errors like "Undefined schema ... not found". For entity attributes (`#[Entity]`, `#[Column]`, relations), use cycle-orm-attributes; for DBAL connections, use cycle-database.
---

# Cycle ORM: runtime layer

## Minimum working example

```php
// Reading
$repo  = $orm->getRepository(User::class);
$user  = $repo->findByPK(42);
$users = $repo->findAll(['active' => true], ['create_time' => 'DESC']);

// Writing
$user->email = 'new@example.com';
$em->persist($user)->run();

// More complex — via Select
$active = $repo->select()
    ->where('active', true)
    ->load('orders')
    ->orderBy('create_time', 'DESC')
    ->limit(10)
    ->fetchAll();
```

## Hard constraints at runtime

- **`$repo->select()` returns a fresh clone every time** — conditions added to one clone never reach the next `select()` call; keep the Select variable you are building.
- **SQL is deferred until `run()` / a fetch method.** `$em->persist($user)` on its own writes nothing; a Select without `fetchAll`/`fetchOne`/`run` is just a builder.
- **`$em->run()` resets the UnitOfWork.** After every run, successful or failed, the EntityManager is empty; calling `run()` again will not replay the previous `persist`/`delete` — re-issue the operations.
- **Create new entities via `$orm->make()`, not `new`.** A bare `new Entity()` can, in some cases, cause extra DB queries. Details and workarounds — in `entity-lifecycle.md`.
- **Identity map in the Heap is keyed by `role + PK`.** Two `findByPK(1)` calls return the same instance — changes through one variable are visible through the other. A "fresh" object means `$orm->getHeap()->clean()` or a new ORM instance.
- **Cache the schema in production.** Building the schema from attributes on every cold start means class scanning + pluralization + reflection. Dev — rebuild (attributes change); prod — cache. Details in `installation.md`.

## Index — what lives where (references/)

- `references/installation.md` — installation and bootstrap: which packages to install, how to configure for Spiral (`spiral/cycle-bridge` + bootloaders), standalone (your own `Compiler::compile()` + `ORM::__construct`), Laravel/Symfony/Yii3 (community / official bridges + caveats), schema-cache dev vs prod, console commands, **runtime options `Cycle\ORM\Options`** (`ignoreUninitializedRelations`, `groupByToDeduplicate` — feature flags introduced in 2.11+, defaults flip in the next major).
- `references/schema.md` — **the compiled ORM schema**: the array format, `SchemaInterface` keys (`MAPPER`/`COLUMNS`/`PRIMARY_KEY`/`RELATIONS`/`CHILDREN`/`DISCRIMINATOR`/`PARENT`/`SCOPE`/`TYPECAST`/...), `Relation::*` sub-keys, sources (attributes / schema-builder DSL / raw array / runtime generation), who reads what at runtime, runtime access via `$orm->getSchema()`, programmatic assembly without attributes. Production schema caching lives in `references/installation.md`.
- `references/repositories.md` — **reading**: `findByPK`/`findOne`/`findAll`/`select()` + where/orderBy/limit/JSON/aggregations/pagination/forUpdate, streaming large result sets via `cursor()`, custom query methods and builder style (`whereOwner`/`active`/...), `find*` vs `get*` naming.
- `references/entity-lifecycle.md` — **creation and writing**: `$orm->make()` vs bare `new` (extra SELECT with a typed relation property — three ways out), where to place the make call (service/factory, not the repository), `$em->persist`/`persistState`/`delete`/`run`, cascade, batch, transactions, soft-delete.
- `references/fetching.md` — deep dive on loading entities together with relations: `Select::load()` vs `with()`, typed `LoadOptions` (HasMany/BelongsTo/ManyToMany), the `@.` placeholder, `loadSubclasses()`, scope at fetch time (root + per-relation override via `LoadOptions::scope`, `QueryScope`), `BulkLoader` for post-loading an already-fetched set.
- `references/collections.md` — collection factories for `*Many` relations: `CollectionFactoryInterface`, built-in `ArrayCollectionFactory`/`DoctrineCollectionFactory`/`IlluminateCollectionFactory`/`LoophpCollectionFactory`, registration via `Factory::withCollectionFactory()`, constructor initialization, M2M pivot access (`PivotedCollectionInterface`, `getPivot`/`setPivot`), `array` limitations under the proxy mapper, custom classes and factories.
- `references/mappers.md` — catalog of the built-in mappers and how to **pick** among them: the default `Mapper` (proxy via `extends`, incompatible with `final`/`readonly`), `PromiseMapper` from `cycle/orm-promise-mapper` (no proxy, relations as `Promise` wrappers, allows `final`), `StdMapper` (for `stdClass`, no STI), `ClasslessMapper` (anonymous proxy without a user class). Decision tree and edge-case constraints for each.
- `references/orm-extensions.md` — ORM-level customization: custom Repository (named query methods), Scope (soft-delete/multi-tenancy), Mapper (custom hydration/lifecycle).
- `references/typecasters-advanced.md` — custom typecast handlers: callable forms, `CastableInterface`/`UncastableInterface`, `CompositeTypecast`, JSON value-object pattern.
- `references/schema-troubleshooting.md` — schema build / migrations / hydration failures: "Undefined schema ... not found" (missing `#[Entity]` or primary key), MSSQL "multiple cascade paths", the proxy fatal on a `readonly class`, typecast rule ignored (column comes back raw), cross-driver issues.

## Where to go beyond this skill

- Entity definitions and attribute markup (`#[Entity]`, `#[Column]`, column types, basic typecast, relations, STI/JTI, embeddable, `#[Index]`/`#[ForeignKey]`) → [[cycle-orm-attributes]].
- Entity class constraints (`final`/`readonly`/constructor) — same place, in `cycle-orm-attributes/SKILL.md`, under "Hard constraints".
- DBAL connections, drivers, `DatabaseConfig` → [[cycle-database]].
