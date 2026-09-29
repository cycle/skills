# Cycle ORM: the compiled schema

The compiled **ORM schema** (`Cycle\ORM\SchemaInterface`) is the runtime format that the ORM, mappers, loaders, and factories read for each role: an array `[role => [SchemaInterface::* => value, ...]]`. Every declaration style (PHP attributes — `[[cycle-orm-attributes]]`; schema-builder DSL via `Cycle\Schema\Registry`; raw array; runtime generation) plugs in at the **build** stage and produces this same structure; the runtime reads it via `$orm->getSchema()`. This file covers the schema **format** and how to build and read it independently of the declaration style.

## Minimal example

```php
use Cycle\ORM\SchemaInterface;
use Cycle\ORM\Schema;
use Cycle\ORM\Relation;

$schema = new Schema([
    'user' => [
        SchemaInterface::ENTITY      => \App\Entity\User::class,
        SchemaInterface::MAPPER      => \Cycle\ORM\Mapper\Mapper::class,
        SchemaInterface::DATABASE    => 'default',
        SchemaInterface::TABLE       => 'users',
        SchemaInterface::PRIMARY_KEY => 'id',
        SchemaInterface::COLUMNS     => [
            'id'    => 'id',
            'email' => 'email',
        ],
        SchemaInterface::TYPECAST    => ['id' => 'int'],
        SchemaInterface::RELATIONS   => [
            'posts' => [
                Relation::TYPE   => Relation::HAS_MANY,
                Relation::TARGET => 'post',
                Relation::SCHEMA => [
                    Relation::CASCADE   => true,
                    Relation::INNER_KEY => 'id',
                    Relation::OUTER_KEY => 'user_id',
                ],
                Relation::LOAD   => Relation::LOAD_PROMISE,
            ],
        ],
    ],
    'post' => [ /* ... */ ],
]);
```

This is the "raw" schema. In practice it is produced by the compiler (`Cycle\Schema\Compiler` from `cycle/schema-builder`).

## Schema sources

All of them produce the same final structure:

| Source                                   | Where the declaration lives                          | Skill / package                                |
|------------------------------------------|------------------------------------------------------|------------------------------------------------|
| **PHP 8 attributes** (`#[Entity]` etc.)  | On the entity classes themselves                     | `[[cycle-orm-attributes]]` (`cycle/annotated`) |
| **Schema-builder DSL**                   | Programmatically: `Registry`, `Entity`, `Field`, `Relation\*` | `cycle/schema-builder`                         |
| **Raw array**                            | Literal in code / file                               | `cycle/orm` (this skill)                       |
| **Runtime generation / per-tenant**      | Dynamically, from a DB or config                     | Custom code                                    |

The attribute path is by far the most common. DSL and manual assembly are needed when there are no attributes (`StdMapper`/`ClasslessMapper`, runtime-generated roles) — see `mappers.md`.

## `SchemaInterface` keys

Per-role values (`$schema->define($role, SchemaInterface::*)`):

| Constant          | Type                       | What it stores                                                                |
|-------------------|----------------------------|-------------------------------------------------------------------------------|
| `ROLE`            | int = 0                    | Role override for a class-keyed entry (`[User::class => [ROLE => 'user', ...]]`). `Schema` reads it on construction and removes it. |
| `ENTITY`          | class-string \| null       | The PHP entity class. Unused by `StdMapper`/`ClasslessMapper`.                |
| `MAPPER`          | class-string               | A `MapperInterface` implementation. Default — `Cycle\ORM\Mapper\Mapper`.      |
| `SOURCE`          | class-string \| null       | `SourceInterface` (database+table for reads/writes). Default — `Source`.      |
| `REPOSITORY`      | class-string               | `RepositoryInterface`. Default — `Cycle\ORM\Select\Repository`.               |
| `DATABASE`        | string                     | Database alias from the DBAL config.                                          |
| `TABLE`           | string                     | Table name.                                                                   |
| `PRIMARY_KEY`     | string \| string[]         | PK column(s). Array — for a composite PK.                                     |
| `FIND_BY_KEYS`    | string[]                   | Heap lookup keys: PK fields plus the fields that relations use as keys.       |
| `COLUMNS`         | array<string,string>       | Map `property => column`. If the key is an int, `property == column`.         |
| `RELATIONS`       | array<string,array>        | Map `relationName => [Relation::* => ...]` — see below.                       |
| `CHILDREN`        | array<string,string>       | **STI parent**: map `discriminator_value => child_role`.                      |
| `SCOPE`           | class-string \| null       | `ScopeInterface` — a global filter for every query against the role.          |
| `TYPECAST`        | array<string,string>       | Typecast rules keyed by field (property) name: `field => 'int'/'bool'/'datetime'/handler-key`. |
| `SCHEMA`          | array                      | Custom per-role data (schema-builder `Entity::setSchema()`); passed to the mapper constructor as the `schema` argument. |
| `PARENT`          | string \| null             | **JTI child**: the parent role in the hierarchy.                              |
| `PARENT_KEY`      | string \| null             | **JTI child**: the parent-table key the child PK joins on. Default — the parent PK. |
| `DISCRIMINATOR`   | string \| null             | **STI parent**: name of the discriminator column.                             |
| `LISTENERS`       | array                      | Listener classes (`cycle/entity-behavior`).                                   |
| `TYPECAST_HANDLER`| class-string[]             | `TypecastInterface` handlers.                                                 |
| `GENERATED_FIELDS`| array<string,int>          | Map `field => generating-type-flag` (value generated on the DB side).         |

## `RELATIONS` sub-keys

Each relation is an array keyed by `Cycle\ORM\Relation` constants:

| Key                       | What it stores                                                                                |
|---------------------------|-----------------------------------------------------------------------------------------------|
| `Relation::TYPE`          | Int type constant: `HAS_ONE` (10) / `HAS_MANY` (11) / `BELONGS_TO` (12) / `REFERS_TO` (13) / `MANY_TO_MANY` (14) / `EMBEDDED` (1) / `BELONGS_TO_MORPHED` (20) / `MORPHED_HAS_ONE` (21) / `REFERS_TO_MORPHED` (22) / `MORPHED_HAS_MANY` (23). |
| `Relation::TARGET`        | Target role name (for morphed — an interface name).                                           |
| `Relation::LOAD`          | `LOAD_PROMISE` (10) / `LOAD_EAGER` (11) — default load strategy.                              |
| `Relation::SCHEMA`        | Sub-array with concrete parameters: `INNER_KEY`/`OUTER_KEY`/`CASCADE`/`NULLABLE`/`WHERE`/`ORDER_BY`/`THROUGH_*`/`MORPH_KEY`/`INVERSION`. |
| `Relation::COLLECTION_TYPE` | Alias or FQCN of the collection (for `HAS_MANY`/`MANY_TO_MANY`/`MORPHED_HAS_MANY`). See `collections.md`. |

## Who reads what at runtime

- **`Factory::mapper()`** — `MAPPER`; passes `SCHEMA` to the mapper constructor.
- **`Factory::repository()`** — `REPOSITORY`.
- **`Factory::source()`** — `SOURCE`, `TABLE`, `DATABASE`, `SCOPE`.
- **`Factory::typecast()`** — `TYPECAST`, `TYPECAST_HANDLER`, and the parent role's handlers via `PARENT`.
- **`DatabaseMapper`** (the base of `Mapper`, `StdMapper`, `ClasslessMapper`) — `COLUMNS` (plus parent columns along `PARENT`), `GENERATED_FIELDS`, `PRIMARY_KEY`.
- **`Cycle\ORM\Mapper\Mapper`** — additionally `ENTITY`, `CHILDREN`, `DISCRIMINATOR` for STI dispatch.
- **`Factory::relation()`/`Factory::loader()`** — `RELATIONS`; `ParentLoader`/`SubclassLoader` — `PARENT`, `PARENT_KEY`.
- **Heap index** (`IndexProvider`) — `FIND_BY_KEYS`.

## Runtime access

```php
$schema = $orm->getSchema();         // SchemaInterface

$schema->getRoles();                 // ['user', 'post', 'tag', ...]
$schema->defines('user');            // bool

$schema->define('user', SchemaInterface::TABLE);        // 'users'
$schema->define('user', SchemaInterface::PRIMARY_KEY);  // 'id'
$schema->define('user', SchemaInterface::RELATIONS);    // ['posts' => [...]]

$schema->defineRelation('user', 'posts');               // the relations['posts'] sub-array
$schema->resolveAlias(\App\Entity\User::class);         // 'user' — class-string → role
$schema->getInheritedRoles('user');                     // children tree for JTI
```

## Programmatic assembly (without attributes)

`cycle/schema-builder` provides a DSL: build a `Registry`, hand it to `Compiler`, get a schema array:

```php
use Cycle\Schema\Compiler;
use Cycle\Schema\Definition\Entity;
use Cycle\Schema\Definition\Field;
use Cycle\Schema\Registry;
use Cycle\Schema\Generator;
use Cycle\Database\DatabaseProviderInterface;

$registry = new Registry($dbal /* DatabaseProviderInterface */);

$user = (new Entity())
    ->setRole('user')
    ->setClass(\App\Entity\User::class)         // optional for ClasslessMapper
    ->setMapper(\Cycle\ORM\Mapper\Mapper::class);
$user->getFields()->set('id', (new Field())
    ->setType('primary')
    ->setColumn('id')
    ->setPrimary(true));
$user->getFields()->set('email', (new Field())
    ->setType('string')
    ->setColumn('email'));
$registry->register($user);
$registry->linkTable($user, 'default', 'users');

// Relations: $registry->register($post); + $user->getRelations()->set('posts', new RelationDefinition...);

$schemaArray = (new Compiler())->compile($registry, [
    new Generator\ResetTables(),
    new Generator\GenerateRelations(),
    new Generator\ValidateEntities(),
    new Generator\RenderTables(),
    new Generator\RenderRelations(),
    new Generator\GenerateTypecast(),
]);

$schema = new \Cycle\ORM\Schema($schemaArray);
```

The full standalone pipeline, generator order included, is in `installation.md` ("Bootstrap (full example)"). Custom generators implement `Cycle\Schema\GeneratorInterface`.

## Schema caching

The compiled schema is a plain `var_export()`-able array: compile it once and cache it — see `installation.md` ("Schema cache"; `cycle/schema-provider` ships `SimpleCacheSchemaProvider` and `PhpFileSchemaProvider`).

## Pitfalls

- **`define()` throws "Undefined schema `Foo`, not found."** — `resolveAlias()` returns input it doesn't know unchanged, so an unregistered class name reaches `define()` as a role. Pass a role or a class that the schema registers as an entity.
- **Schema modifier not plugged in** — `cycle/entity-behavior` needs `Generator\GenerateModifiers` and `Generator\RenderModifiers` in `Compiler::compile()`. Without them, `#[CreatedAt]`/`#[SoftDelete]` are declared but no listeners are registered.
- **`SCOPE` applies to every query for the role** — including relation loaders. `->scope(null)` on a `Select` affects only the root query; for a relation pass `scope: false` in its load options (`fetching.md`, "Bypassing during relation load").

## Checklist

1. Hand-assembled roles (DSL / raw array) set every key their mapper and factories read (see "Who reads what at runtime").
2. The compiled schema is cached in production and the cache is invalidated on deploy (`installation.md`, "Schema cache").
3. `MAPPER`/`REPOSITORY`/`SOURCE`/`SCOPE` classes whose constructor dependencies the container can't autowire (scalars, unbound interfaces) have those dependencies bound in the container.
4. If there are behaviors (`cycle/entity-behavior`), `Generator\GenerateModifiers` and `Generator\RenderModifiers` are in the `Compiler` pipeline.
5. Custom generators sit at the right place in the pipeline (order as in `installation.md`, "Bootstrap (full example)").
6. `CHILDREN`+`DISCRIMINATOR` on the STI parent; `PARENT` on the JTI child (`PARENT_KEY` only when the join key is not the parent PK).
