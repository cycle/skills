# Installation and bootstrap

Which packages to install, which bootloaders/service providers to register, and how to assemble `ORM` for different frameworks. Covered in detail: Spiral (`spiral/cycle-bridge`, first-class by Cycle's authors), Yii3 (`yiisoft/yii-cycle`, official from the Yii team), and a standalone bootstrap. For Laravel / Symfony / everything else — a brief "Other frameworks" section with Packagist links.

See also:
- `EventDrivenCommandGenerator` (required for behaviors) — `cycle-orm-attributes/resources/behaviors.md`
- get a repository / EntityManager after bootstrap → `repositories.md`
- customizing mapper/scope/repository → `orm-extensions.md`
- schema build fails / can't find entities → `schema-troubleshooting.md`

---

## Packages: what actually exists in the ecosystem

| Package                             | Purpose                                                                                                                   |
|-------------------------------------|---------------------------------------------------------------------------------------------------------------------------|
| `cycle/orm`                         | ORM core — Mapper, Heap, Transaction, Select, Repository, Scope.                                                          |
| `cycle/database`                    | DBAL (Database Abstraction Layer) — connections, query builder, schema.                                                   |
| `cycle/annotated`                   | PHP 8 attributes on classes → parser into the schema builder.                                                             |
| `cycle/schema-builder`              | Assembles `SchemaInterface` from generators (Annotated, Render, etc).                                                     |
| `cycle/schema-provider`             | A pipeline that fetches the compiled schema from several sources in order.                                                |
| `cycle/migrations`                  | DSL for migrations; apply/rollback/status.                                                                                |
| `cycle/schema-migrations-generator` | Diff between current schema and DB → generates migration classes.                                                         |
| `cycle/schema-renderer`             | Renders the schema as text / dot / ascii (for the `cycle:render` command).                                                |
| `cycle/entity-behavior`             | Behavior attributes: `#[CreatedAt]`, `#[UpdatedAt]`, `#[SoftDelete]`, `#[OptimisticLock]`, `#[Hook]`, `#[EventListener]`. |
| `cycle/entity-behavior-uuid`        | UUID generators: `#[Uuid1]`..`#[Uuid7]`. Requires `ramsey/uuid`.                                                          |
| `cycle/entity-behavior-identifier`  | Integration with `ramsey/identifier` (successor to `ramsey/uuid`: typed UUIDv7/ULID).                                     |
| `cycle/orm-promise-mapper`          | Alternative mapper: promise wrappers (laminas-hydrator) instead of proxy-via-extends.                                     |
| `cycle/active-record`               | Active Record on top of Cycle: `save()`/`delete()`/`findByPK()` on the entity itself.                                     |
| `spiral/cycle-bridge`               | Full integration into Spiral Framework — bootloaders, console commands. Evolves in lockstep with Cycle.                   |

**Minimum kit** for standalone use: `cycle/orm` + `cycle/database`. Everything else is optional.

---

## Spiral Framework — first-class integration

The most complete integration: a single package that pulls in all the rest as dependencies. The Spiral team are the authors of Cycle, so the integration is native.

### Installation

```
composer require spiral/cycle-bridge
```

This package already depends on `cycle/annotated`, `cycle/migrations`, `cycle/orm`, `cycle/schema-builder`, `cycle/schema-migrations-generator`, `cycle/schema-renderer` — no need to require them separately.

### Bootloaders

In `app/src/Application/Kernel.php` (or equivalent) add `BridgeBootloader` — it wires up the entire kit:

```php
use Spiral\Cycle\Bootloader as CycleBridge;

protected const LOAD = [
    // ...
    CycleBridge\BridgeBootloader::class,
];
```

`BridgeBootloader::DEPENDENCIES` wires in:

- `DatabaseBootloader` — DBAL and connections.
- `MigrationsBootloader` — migrations.
- `SchemaBootloader` — schema build/cache.
- `CycleOrmBootloader` — the `ORM` instance itself (binds `ORMInterface`, `EntityManagerInterface`, `FactoryInterface`, `TransactionInterface`).
- `AnnotatedBootloader` — the attribute-based generator (via `cycle/annotated`).
- `CommandBootloader` — `cycle:*` console commands.
- `ValidationBootloader` — integration with `spiral/validator`.
- `DataGridBootloader` — integration with `spiral/data-grid`.
- `AuthTokensBootloader` — Cycle-backed storage for `spiral/auth`.
- `ScaffolderBootloader` — `cycle:entity` command.
- `PrototypeBootloader` — Cycle repositories in Prototype.

All of these are wired in automatically. The package also contains two bootloaders that are **NOT in `BridgeBootloader::DEPENDENCIES`** — wire them in manually if needed:
- `DisconnectsBootloader` — closes connections after a request (relevant for long-running runtimes: RoadRunner, FrankenPHP).
- `EntityBehaviorBootloader` — binds `CommandGeneratorInterface` to `EventDrivenCommandGenerator` (see "Entity behaviors" below).

If you don't need some of what gets wired in (e.g. no DataGrid) — skip `BridgeBootloader` and wire in only the bootloaders you need manually:

```php
protected const LOAD = [
    \Spiral\Cycle\Bootloader\DatabaseBootloader::class,
    \Spiral\Cycle\Bootloader\MigrationsBootloader::class,
    \Spiral\Cycle\Bootloader\SchemaBootloader::class,
    \Spiral\Cycle\Bootloader\CycleOrmBootloader::class,
    \Spiral\Cycle\Bootloader\AnnotatedBootloader::class,
    \Spiral\Cycle\Bootloader\CommandBootloader::class,
];
```

### Entity behaviors — **a ready bootloader exists, but is not wired in by default**

`spiral/cycle-bridge` **contains** `Spiral\Cycle\Bootloader\EntityBehaviorBootloader`, but it is **not in `BridgeBootloader::DEPENDENCIES`** — wire it in separately. The bootloader itself does exactly one thing: it binds `Cycle\ORM\Transaction\CommandGeneratorInterface` to `Cycle\ORM\Entity\Behavior\EventDrivenCommandGenerator` (see `src/Bootloader/EntityBehaviorBootloader.php`).

1. Install the package:
   ```
   composer require cycle/entity-behavior
   ```
   For UUID attributes also add:
   ```
   composer require cycle/entity-behavior-uuid ramsey/uuid
   ```
   (`ramsey/uuid` is not declared as a hard peer dependency, install it separately.)

2. Register the bootloader in `Kernel::LOAD`:
   ```php
   use Spiral\Cycle\Bootloader as CycleBridge;

   protected const LOAD = [
       CycleBridge\BridgeBootloader::class,
       CycleBridge\EntityBehaviorBootloader::class,   // ← manually
   ];
   ```

Order relative to `BridgeBootloader` doesn't matter — the binding must exist by the time `ORMInterface` is first resolved. Without this bootloader, behavior attributes are **silently ignored**: the schema is built, listeners are registered in the schema, but the default command generator doesn't invoke them.

### Config

You need two files: `app/config/database.php` (DBMS connections — shape, drivers, and connection variants live in `[[cycle-database]]/resources/installation.md`) and `app/config/cycle.php` (ORM specifics).

```php
// app/config/database.php — see [[cycle-database]]/resources/installation.md
return [
    'default'   => 'default',
    'databases' => ['default' => ['connection' => 'postgres']],
    'connections' => [
        'postgres' => new \Cycle\Database\Config\PostgresDriverConfig(
            connection: new \Cycle\Database\Config\Postgres\DsnConnectionConfig(
                dsn: 'pgsql:host=localhost;port=5432;dbname=app',
                user: env('DB_USER'),
                password: env('DB_PASSWORD'),
            ),
        ),
    ],
];

// app/config/cycle.php
use Cycle\ORM\Options;

return [
    'schema' => [
        'cache' => true,                          // persist the compiled schema in `MemoryInterface` (runtime/cache) so worker boot doesn't recompile it
        'collections' => [
            'default' => 'array',                  // or 'doctrine'/'illuminate' — see collections.md
        ],
    ],
    'warmup'  => true,                            // warm up the ORM on worker boot (for RR/FrankenPHP/Swoole; FPM — `false`). Details below.
    'options' => (new Options())                  // ORM runtime flags (see the Options section below)
        ->withIgnoreUninitializedRelations(true)
        ->withGroupByToDeduplicate(true),
];
```

`spiral/tokenizer` already knows where to look for entity classes — set the directories in `app/config/tokenizer.php`:
```php
return [
    'directories' => [
        directory('app') . 'src/Domain/',
        directory('app') . 'src/Module/',
    ],
];
```

### Two independent boot-time caches

Spiral spends real time on two boot steps — both are cacheable:

1. **`schema.cache` in `app/config/cycle.php`** — caches the **compiled ORM schema**. Stored in `MemoryInterface` (by default files under `runtime/cache/`). `SchemaBootloader::schema()` reads from memory when the flag is `true` and memory isn't empty; otherwise it recompiles via `Compiler::compile()` and writes back (see `SchemaBootloader::schema()` in `spiral/cycle-bridge`).
2. **`TOKENIZER_CACHE_TARGETS=true` in the environment** — caches the **directory-scan result**. Without it, `spiral/tokenizer` walks `tokenizer.directories` on every worker boot looking for `#[Entity]` classes; with it, the discovered class list is reused from cache.

Turn both on in prod. On deploy — invalidate both (usually clearing `runtime/cache/` is enough).

### ORM warmup (`warmup`)

`'warmup' => true` in `app/config/cycle.php` tells `CycleOrmBootloader` to call `ORM::prepareServices()` after `kernel->booted()`. That walks every role from the schema and **pre-builds** Mapper / Repository / RelationMap for each; the first access from user code no longer pays the initialization cost.

What happens under the hood (`Cycle\ORM\ORM::prepareServices()` + `MapperProvider/RepositoryProvider/RelationProvider::prepare*()`):
- For every role in `$schema->getRoles()`, `Factory::mapper()` / `Factory::repository()` / `RelationMap::build()` are invoked and the results are cached inside the providers.
- After warmup the providers null out the back-reference to `ORMInterface` (`$this->orm = null`) and drop `$factory`; subsequent `getMapper()` / `getRepository()` calls only hit the cached array. The consequence: **after warmup you cannot add new roles to the schema at runtime** (an attempt → `ORMException('Mapper is not prepared.')`).

When to turn on:
- **Long-running runtime** (RoadRunner, FrankenPHP, Swoole, Octane) — the worker lives long and serves many requests. The one-off warmup cost on worker boot turns into a zero cold-start for the first request → better p95/p99 latency.
- **PHP-FPM / classic CGI** — every request is a new process; warmup means preparing **all** roles for the sake of one request → wasted work, no payoff. Leave `false`.
- **CLI scripts / consumers** — typically narrow, one-shot. Warmup is overkill.

Default — `false` (overridable via the `CYCLE_SCHEMA_WARMUP` env variable).

### Console commands (after bootstrap)

```
# Schema / ORM
php app.php cycle               # rebuild the schema
php app.php cycle:sync          # sync schema with DB (no migrations — dev only)
php app.php cycle:migrate       # generate a migration from the diff
php app.php cycle:render        # print the current schema as text
php app.php cycle:entity ...    # scaffolder for a new Entity (from ScaffolderBootloader)

# Migrations
php app.php migrate             # apply migrations
php app.php migrate:rollback    # rollback
php app.php migrate:replay      # rollback + migrate
php app.php migrate:init        # initialize the migration history table
php app.php migrate:status      # status

# Database
php app.php db:list             # list databases and tables
php app.php db:table <name>     # table structure
```

### Getting ORM / EntityManager

In a controller / service — standard DI:
```php
public function __construct(
    private \Cycle\ORM\ORMInterface $orm,
    private \Cycle\ORM\EntityManagerInterface $em,
) {}
```

### Repository via DI

`CycleOrmBootloader::init()` binds an injector on `Cycle\ORM\RepositoryInterface`: requesting any **custom** repository class (the one set in `#[Entity(repository: ...)]` or stored in the schema under `SchemaInterface::REPOSITORY`) from the container is resolved through `$orm->getRepository($role)`:

```php
final class UserController
{
    public function __construct(
        private App\Repository\UserRepository $users,   // ← resolved by DI automatically
    ) {}
}
```

What does **not** work out of the box and needs your own binding:
- **The default `Cycle\ORM\Select\Repository`** (when an entity has no custom repository) — the injector skips it intentionally. Use `$orm->getRepository(User::class)`.
- **An interface over a custom repository** (`UserRepositoryInterface`, so you can swap the implementation in tests) — register the binding `UserRepositoryInterface => UserRepository` in your own bootloader:
  ```php
  protected const SINGLETONS = [
      App\Repository\UserRepositoryInterface::class => App\Repository\UserRepository::class,
  ];
  ```
  `UserRepository` itself is then resolved by `RepositoryInjector`.

---

## Yii3

Official (from the Yii team) packages, actively maintained. Configuration, DI bindings, and console commands are documented in each package's README — here only the list:

- **`yiisoft/yii-cycle`** — https://packagist.org/packages/yiisoft/yii-cycle. The main Cycle ORM integration for Yii3: DBAL, schema-providers pipeline, migrations, entity-paths via aliases.
- **`yiisoft/data-cycle`** — https://packagist.org/packages/yiisoft/data-cycle. A `yiisoft/data` adapter for Cycle: reader/paginator/sorter over `Select`.
- **`yiisoft/rbac-cycle-db`** — https://packagist.org/packages/yiisoft/rbac-cycle-db. Storage of roles/permissions for `yiisoft/rbac` over `cycle/database` (DBAL directly, not the ORM).

---

## Standalone (no framework)

Minimum bootstrap. Suitable for CLI scripts, tests, your own stack.

### Installation

```
composer require cycle/orm cycle/annotated cycle/schema-builder cycle/database
composer require --dev cycle/migrations cycle/schema-migrations-generator   # if you need migrations
```

### Bootstrap (full example)

```php
use Cycle\Database\Config;
use Cycle\Database\DatabaseManager;
use Cycle\ORM\Factory;
use Cycle\ORM\ORM;
use Cycle\ORM\Schema;
use Cycle\Schema\Compiler;
use Cycle\Schema\Generator;
use Cycle\Schema\Registry;
use Cycle\Annotated;
use Cycle\Annotated\Locator\TokenizerEmbeddingLocator;
use Cycle\Annotated\Locator\TokenizerEntityLocator;
use Spiral\Tokenizer\ClassLocator;
use Symfony\Component\Finder\Finder;

// 1. DBAL — drivers / connection variants / read replicas: see [[cycle-database]]/resources/installation.md
$dbal = new DatabaseManager(
    new Config\DatabaseConfig([
        'default'     => 'default',
        'databases'   => ['default' => ['connection' => 'sqlite']],
        'connections' => [
            'sqlite' => new Config\SQLiteDriverConfig(
                connection: new Config\SQLite\FileConnectionConfig(database: __DIR__ . '/db.sqlite'),
            ),
        ],
    ]),
);

// 2. Locator for entity classes.
// ClassLocator implements Spiral's ClassesInterface; Embeddings/Entities expect Cycle-specific
// locators — wrap ClassLocator with Tokenizer*Locator.
$classLocator = new ClassLocator(
    (new Finder())->files()->in([__DIR__ . '/src/Entity'])->name('*.php')
);

// 3. Schema compilation
$registry = new Registry($dbal);
$schemaArray = (new Compiler())->compile($registry, [
    new Annotated\Embeddings(new TokenizerEmbeddingLocator($classLocator)),
    new Annotated\Entities(new TokenizerEntityLocator($classLocator)),
    new Annotated\TableInheritance(),
    new Annotated\MergeColumns(),
    new Generator\GenerateRelations(),
    new Generator\GenerateModifiers(),               // ← for behavior attributes
    new Generator\ValidateEntities(),
    new Generator\RenderTables(),
    new Generator\RenderRelations(),
    new Generator\RenderModifiers(),                 // ← render behavior columns
    new Annotated\MergeIndexes(),
    // new Generator\SyncTables(),                   // ← uncomment in dev to materialize tables to DB
    new Generator\GenerateTypecast(),
]);

$schemaObj = new Schema($schemaArray);

// 4. ORM
$orm = new ORM(
    factory: new Factory($dbal),
    schema: $schemaObj,
    // commandGenerator: new \Cycle\ORM\Entity\Behavior\EventDrivenCommandGenerator($schemaObj, $container),  // if behaviors are used
);

$em = new \Cycle\ORM\EntityManager($orm);
```

### Schema cache

`Compiler::compile()` is expensive (reflection + DBAL introspection). In production, cache the result:

```php
$cacheFile = __DIR__ . '/var/cycle-schema.php';

if (file_exists($cacheFile)) {
    $schemaArray = require $cacheFile;
} else {
    $schemaArray = (new Compiler())->compile($registry, [/* ... */]);
    file_put_contents($cacheFile, '<?php return ' . var_export($schemaArray, true) . ';');
}

$orm = new ORM(new Factory($dbal), new Schema($schemaArray));
```

In Spiral this is done by `SchemaBootloader` automatically through `MemoryInterface`, toggled by the `schema.cache` flag in `app/config/cycle.php` (see the cache section in the Spiral integration above).

### EventDrivenCommandGenerator (for behaviors)

If you use `cycle/entity-behavior` (official guide — `https://cycle-orm.dev/docs/entity-behaviors-install`):

```php
use Cycle\ORM\Entity\Behavior\EventDrivenCommandGenerator;

$orm = new ORM(
    factory: new Factory($dbal),
    schema: $schemaObj,
    commandGenerator: new EventDrivenCommandGenerator($schemaObj, $psrContainer),
);
```

`$psrContainer` — any PSR-11 container for DI-resolving listener classes (`#[EventListener(listener: ...)]`). If your listeners have no dependencies — a minimal stub will do.

> **Caveat:** behaviors on embedded entities are not supported (`https://cycle-orm.dev/docs/entity-behaviors-install`).

---

## Other frameworks (Laravel, Symfony, etc.)

There is no supported integration package from the Cycle team for these — pick a community bridge from Packagist, or self-roll a thin wrapper around the standalone bootstrap above.

- **Laravel** — community package: https://packagist.org/packages/wayofdev/laravel-cycle-orm-adapter. The service provider auto-registers via package discovery; artisan commands are prefixed `cycle:*`; the package pulls in `cycle/entity-behavior` + `cycle/entity-behavior-uuid` on its own. Details — in the package's README.
- **Symfony** — no maintained bundle; self-rolled: a factory service that assembles `ORM` per the standalone schema above, registered in `services.yaml` against `Cycle\ORM\ORMInterface` / `EntityManagerInterface`, plus your own `bin/console cycle:*` commands on top of `Compiler` + `Migrator`. When migrating from Doctrine — mind the imports: Cycle looks for `Cycle\Annotated\Annotation\Entity`, not `Doctrine\ORM\Mapping\Entity` (see `schema-troubleshooting.md`).
- **Everything else** — `cycle/orm` itself is framework-agnostic. Take the standalone bootstrap, register `ORMInterface` + `EntityManagerInterface` as singletons in your framework's DI.

This skill does not track community bridge versions or APIs — verify against the package's README/Packagist.

---

## Schema-build strategies: dev vs prod

**Dev (schema changes often):**
- Schema cache off — the compiler runs on every PHP-process / worker boot (slow, but never stale when attributes change).
- `warmup` off — providers stay lazy, so editing/adding entities doesn't fight a pre-built cache and the boot stays cheap.
- `cycle:sync` applies changes directly without migrations.
- Locally use SQLite or PostgreSQL in Docker.

**Prod:**
- Schema cache **on**. Invalidated on deploy (via CI: clear `runtime/cache/` or delete the standalone cache file).
- `warmup` — **on for long-running runtime** (RoadRunner/FrankenPHP/Swoole/Octane), **off for PHP-FPM** (every request is a new process — pre-building all roles is wasted work).
- `cycle:migrate` generates diffs → migrations committed to git → `migrate` runs in the pipeline.
- No `cycle:sync` in prod.

In Spiral this is toggled by the `schema.cache` / `warmup` flags in `app/config/cycle.php` (cache backend is `MemoryInterface`, see above). In parallel, turn on `TOKENIZER_CACHE_TARGETS=true` in prod so entity-class discovery isn't redone on every worker boot either. In standalone — you decide whether to read `$cacheFile` or whether to call `$orm->prepareServices()` after bootstrap yourself.

---

## Runtime options (`Cycle\ORM\Options`) — feature flags

`Cycle\ORM\Options` is a final object holding boolean flags that tune runtime behavior. It is passed as the fifth argument to `ORM::__construct(options: ...)` (and accepted by `ORM::with()`). The default is `new Options()` with both flags `false`. From the runtime it is available via `$orm->getService(Options::class)`.

Currently there are two flags. Both carry `@note will be set to TRUE in the next major version` — these are the announced defaults for the next cycle/orm major.

**Recommendation for new projects: turn both flags on (`true`) from day one.** The current `false` defaults are a compatibility shim to avoid breaking projects written before 2.11. In new code the "correct" semantics is the `true` behavior; that is exactly what becomes the default in 3.x.

```php
use Cycle\ORM\{ORM, Factory, Options};

$options = (new Options())
    ->withIgnoreUninitializedRelations(true)
    ->withGroupByToDeduplicate(true);

$orm = new ORM(
    factory: new Factory($dbal),
    schema: $schemaObj,
    options: $options,           // ← fifth argument
);
```

### `ignoreUninitializedRelations` (default `false`, recommended `true`)

What it controls: how the ORM treats an **uninitialized** relation property on an entity.

- `true` (recommended value, future default): an uninitialized property is ignored. `unset($entity->relation)` **does not change** the relation on save; if the query loaded the relation, hydration fills it in. For entities created outside the ORM (through `new`), the ORM **does not try** to populate uninitialized relations — it leaves them alone.
- `false` (current default, BC shim): an uninitialized property is treated as `null` (for `*Many` — an empty collection). On save this **detaches** related entities (or removes pivot rows in M2M). For entities created via `new`, the ORM tries to populate every uninitialized relation.

Where it acts: `Cycle\ORM\Transaction\UnitOfWork` (master and slave relations) when building commands. It does not affect `Select` reads on its own, but it is what lets "untouched" relations survive a save without side effects.

When to enable: **always, in any new code.** The `true` behavior is the semantics the ORM was designed for in the first place; `false` is kept solely for compatibility with projects written before 2.11. The most painful scenario under `false` is partial hydration (a `Select` without `load()` on some relations) followed by a save: every "not loaded and not touched" relation gets nulled — a classic source of silent regressions.

### `groupByToDeduplicate` (default `false`, recommended `true`)

What it controls: whether `Select` injects `GROUP BY <primaryKey>` when `JOIN`s combine with `limit`/`offset`.

- `false` — no `GROUP BY`. JOINs multiply rows, `LIMIT` cuts by rows, so the same root entity can come back several times / the actual entity count is less than `LIMIT`.
- `true` — `Select::addGroupByPK()` adds `GROUP BY` on the root PK whenever there are joined loaders and `limit > 1` or `offset > 0`. The resulting entity count is correct.

Where it acts: only in `Select` and only when joined loaders exist (`load()` / `with()` via `LoadOptions::method = JOIN`).

Edge case: on MSSQL `GROUP BY` requires every selected column to be listed — the corresponding fix landed in `cycle/orm` 2.11 (commit `356874de`). On exotic dialects or custom query modifiers — run the tests.

---

## Common pitfalls

- **Behavior attributes silently don't work** — in Spiral you forgot to add `EntityBehaviorBootloader` (it's in `spiral/cycle-bridge` but not in `BridgeBootloader::DEPENDENCIES` — wire it separately); in standalone / other frameworks — you didn't pass `commandGenerator:` to `ORM::__construct`. The most common problem when migrating from Doctrine or older projects.
- **Schema cache in prod without invalidation on deploy** — you changed an Entity, deployed, but the cache is still stale → code uses new properties, schema is old → fatal/silent breakage. The deploy script must remove `cycle-schema.php` or clear the cache folder.
- **`spiral/tokenizer` doesn't see entity classes** — you forgot to add the directory in `tokenizer.directories`. See `schema-troubleshooting.md` ("class doesn't show up in the schema").
- **Conflict with `Doctrine\ORM\Mapping\Entity` import** — when migrating from Doctrine ORM, the IDE often slips in the old namespace. Cycle looks for `Cycle\Annotated\Annotation\Entity`. Verify imports in new files.
- **`composer require cycle/entity-behavior-uuid` without `ramsey/uuid`** — the peer dependency is not hard-declared, install it separately.
- **Package version compatibility:** the `cycle/*` family evolves in lockstep — after upgrading one package run `composer update "cycle/*"` across the board, otherwise you'll hit interface incompatibilities.
- **Standalone bootstrap without `GenerateModifiers`/`RenderModifiers`** — behavior attributes don't modify the schema. They **must** be included in the `Compiler::compile()` pipeline, even if behaviors aren't used right now (for the future).
- **`Annotated\Embeddings($classLocator)` / `Annotated\Entities($classLocator)` throw `TypeError`** — the constructors expect `Cycle\Annotated\Locator\EmbeddingLocatorInterface` / `EntityLocatorInterface`, not `Spiral\Tokenizer\ClassLocator` directly. Wrap them: `new TokenizerEmbeddingLocator($classLocator)` / `new TokenizerEntityLocator($classLocator)`.
- **Standalone pipeline without `Generator\SyncTables` does not create tables in the DB** — `RenderTables` builds the schema in Registry, but tables only materialize through `SyncTables` (dev-only: immediate `CREATE/ALTER`) or through migrations (`cycle:migrate` + apply). Bootstrap runs cleanly, then you hit "no such table" — that step was missing.
- **Cross-database setup** — Cycle supports multiple `database:` entries in one ORM. But FKs between them are impossible (see `cycle-orm-attributes/resources/relations.md`, `fkCreate: false`). Plan the boundaries.
- **Legacy code relies on "`unset($entity->collection)` = detach"** — this only holds with `ignoreUninitializedRelations = false` (the 2.12 default). Flipping the flag — or upgrading to the next major where it becomes `true` — turns `unset` into a no-op, and you must assign `$entity->collection = new ArrayCollection()` explicitly to clear. Audit partial-update tests before the upgrade.
- **`LIMIT` with `load(..., method: JOIN)` returns a "torn" page** — without `groupByToDeduplicate` the duplicated root rows after a JOIN eat part of the page. Enable the flag when using the joined-load strategy together with pagination (`Select::limit()` / `offset()`).

## Checklist

### Spiral
1. `composer require spiral/cycle-bridge` is installed.
2. `BridgeBootloader` is in `Kernel::LOAD` (or a manual set of bootloaders).
3. If behaviors are used — `Spiral\Cycle\Bootloader\EntityBehaviorBootloader` is added to `Kernel::LOAD` (separately, it's not in `BridgeBootloader`) + `cycle/entity-behavior` is installed (+ `cycle/entity-behavior-uuid` + `ramsey/uuid` for UUIDs).
4. `tokenizer.directories` contains the paths to entity classes.
5. `database.php` / `cycle.php` are configured; in prod `schema.cache => true` + `TOKENIZER_CACHE_TARGETS=true` (both caches are invalidated on deploy), in dev — both `false`.
6. Migrations via `cycle:migrate` + `migrate`, not `cycle:sync` (in prod).

### Yii3
1. `composer require yiisoft/yii-cycle` is installed. Configuration and DI bindings follow the package's README.
2. Optionally: `yiisoft/data-cycle` for Yii Data, `yiisoft/rbac-cycle-db` for RBAC.

### Standalone
1. The minimum is installed: `cycle/orm`, `cycle/database`, `cycle/annotated`, `cycle/schema-builder`.
2. The bootstrap assembles `ORM` via `Factory($dbal)` + `Schema(compiled)`.
3. The Compiler pipeline includes `Annotated\Embeddings`/`Entities`/`TableInheritance`/`MergeColumns`/`MergeIndexes` + `Generator\GenerateRelations`/`GenerateModifiers`/`ValidateEntities`/`RenderTables`/`RenderRelations`/`RenderModifiers`/`GenerateTypecast`.
4. Schema cache is implemented for prod (file cache with invalidation on deploy).
5. For behaviors — `EventDrivenCommandGenerator` is passed to `ORM::__construct(commandGenerator:)`.
6. For partial-save scenarios / JOIN-based pagination — `Cycle\ORM\Options` is set deliberately (`withIgnoreUninitializedRelations` / `withGroupByToDeduplicate`) and passed to `ORM::__construct(options:)`.

### Other frameworks (Laravel / Symfony / etc.)
1. Picked the right package: community bridge from Packagist (e.g. `wayofdev/laravel-cycle-orm-adapter` for Laravel) or a self-rolled wrapper around the standalone bootstrap above.
2. `Cycle\ORM\ORMInterface` and `EntityManagerInterface` are registered as singletons in the framework's DI.
3. For behaviors — the chosen package wires `EventDrivenCommandGenerator` itself, or you do it manually.
4. No stray `use Doctrine\ORM\Mapping\...` in entity classes (relevant when migrating from Doctrine).
