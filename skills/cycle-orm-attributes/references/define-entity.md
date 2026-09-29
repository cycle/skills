# Defining a Cycle entity

A Cycle entity is an ordinary PHP class marked with `#[Entity]` from the `cycle/annotated` package. All options are attributes on the class and its properties; there are no separate XML/YAML mappings.

See also:
- column types, defaults, GeneratedValue, typecast → `column-types.md`
- relations (`HasOne`/`HasMany`/`BelongsTo`/`ManyToMany`/morphed/...) → `relations.md`
- STI/JTI inheritance → `inheritance.md`
- indexes, composite PK, table-level FKs → `table-constraints.md`
- value-objects without their own table → `embeddable.md` (an alternative to `#[Entity]`, not an addition)
- choosing a mapper for the entity (default / PromiseMapper / StdMapper / ClasslessMapper) → `cycle-orm/references/mappers.md`
- custom mapper / repository / scope (writing your own) → `cycle-orm/references/orm-extensions.md`
- schema problem diagnostics → `cycle-orm/references/schema-troubleshooting.md`

## Minimum entity

```php
use Cycle\Annotated\Annotation\Column;
use Cycle\Annotated\Annotation\Entity;

#[Entity]
class User
{
    #[Column(type: 'primary')]
    public int $id;

    #[Column(type: 'string')]
    public string $email;

    #[Column(type: 'string', nullable: true)]
    public ?string $name = null;
}
```

What happens by default:
- **role** = camelCase short class name: `User` → `user`, `BillingInvoice` → `billingInvoice` (`Configurator.php:50`).
- **table** = snake_case plural of the role: `users`, `billing_invoices`. If the pluralizer gets a word wrong, set `table:` explicitly.
- **column** = snake_case of the property name: `$customerId` → `customer_id` (`Configurator.php:257`). Set `name:` only when the column name differs from that.
- **database** = default DB from the DBAL config.
- **mapper / repository / source / scope** — Cycle standard classes.

An entity needs **at least one** primary column — `#[Column(type: 'primary')]` / `#[Column(type: 'bigPrimary')]`, a regular column with `primary: true`, or a composite PK (see below). An entity without one is **silently dropped** from the compiled schema (`schema-builder/src/Compiler.php:48`); the symptom is ``SchemaException: Undefined schema `App\User`, not found.`` at runtime.

## Hard constraints on entity classes

The schema compiles fine with these mistakes; they surface at runtime, on the first load or `$orm->make()`.

The constraints below describe the **default mapper** (`Cycle\ORM\Mapper\Mapper`), used whenever `#[Entity]` doesn't set `mapper:`. It **subclasses the entity with a proxy class** (for lazy-loaded relations) and hydrates properties through closures bound to the declaring class, **bypassing the constructor** (`orm/src/Mapper/Proxy/Hydrator/ClosureHydrator.php`). Any write error other than `TypeError` is **swallowed silently** (`ClosureHydrator.php:41-47,74-81`).

**Forbidden:** `final` class. Throws ``RuntimeException("The entity `App\User` class is final and can't be extended.")`` on the first load or `make()` (`orm/src/Mapper/Proxy/ProxyEntityFactory.php:153-154`). Lifted by switching to `PromiseMapper`, which doesn't subclass the entity — see `cycle-orm/references/mappers.md`.

**Forbidden:** `readonly`. What breaks depends on the form:
- `readonly class` (PHP 8.2+) → uncatchable fatal when the proxy is generated: `Non-readonly class ... Cycle ORM Proxy cannot extend readonly class ...`.
- `public readonly` property → silently left **uninitialized**; the first read fails with `must not be accessed before initialization`.
- `private`/`protected readonly` property → the first hydration works, but every later write to it (refresh, re-hydration) is silently skipped.

For external immutability use `private`/`protected` properties + getters.

```php
// Forbidden:
final class User
{
    public function __construct(
        public readonly int $id,
        public readonly string $email,
    ) {}
}

// OK:
#[Entity]
class User
{
    #[Column(type: 'primary')]
    public int $id;

    #[Column(type: 'string')]
    public string $email;
}
```

`static` properties are never mapped.

## Constructor

**The constructor is not called during hydration from the DB.** The hydrator writes properties directly via reflection, bypassing the constructor. From this fact, three equally valid patterns follow:

**1. No constructor (the simplest).** Property defaults cover the new-object scenario and hydration.

```php
#[Entity]
class User
{
    #[Column(type: 'primary')]
    public int $id;
    #[Column(type: 'string')]
    public string $email;
    #[Column(type: 'string', nullable: true)]
    public ?string $name = null;
}
```

**2. Public constructor for new objects.** Convenient for DTO style.

```php
#[Entity]
class User
{
    #[Column(type: 'primary')]
    public int $id;

    public function __construct(
        #[Column(type: 'string')]
        public string $email,
        #[Column(type: 'string', nullable: true)]
        public ?string $name = null,
    ) {}
}
```

`#[Column]` works correctly on promoted properties — the constructor parameter itself carries it.

**Promoted-properties + defaults pitfall.** `public ?string $name = null` in the constructor signature is a **parameter** default, not a property default. When hydrating from the DB the constructor isn't called; if the data has no value for that column (you added it later, the migration hasn't run yet, it's a field from an embeddable that wasn't loaded), the property stays `uninitialized` → `Typed property ... must not be accessed before initialization`. If you need a guaranteed default during hydration too — declare the property separately with a property default, or set it in the mapper's `init`.

```php
// PARAMETER default: when hydrated bypassing the constructor, the property stays uninitialized.
public function __construct(
    #[Column(type: 'string', nullable: true)]
    public ?string $name = null,
) {}

// PROPERTY default: works for both new and hydration.
#[Column(type: 'string', nullable: true)]
public ?string $name = null;
```

**3. Private constructor + Factory (DDD/clean architecture).** Entity creation only goes through a factory, and invariant logic is hidden. Cycle bypasses the constructor when hydrating from the DB anyway, so `private` doesn't get in its way.

```php
#[Entity(
    role: 'billing_invoice',
    repository: InvoiceRepositoryImpl::class,
    table: 'billing_invoices',
    database: 'billing',
)]
class Invoice
{
    #[Column(type: 'uuid', primary: true)]
    public string $id;
    #[Column(type: 'string')]
    public string $status;

    private function __construct() {}
}

final class InvoiceFactory
{
    public function __construct(
        private readonly ORMInterface $orm,
    ) {}

    public function create(string $accountId): Invoice
    {
        return $this->orm->make(Invoice::class, [
            'id' => Uid::generate(),
            'status' => 'draft',
        ]);
    }
}
```

`$orm->make()` bypasses the `private` constructor (instantiation goes through the mapper), registers the entity in the Heap, sets up proxies for relations and picks the right class for STI/JTI. Prefer it over bare `new` for new entities in general — see `cycle-orm/references/entity-lifecycle.md`.

**Universal rules:**
- **No side effects in the constructor** (logs, events, notifications). They will only run for the new-object scenario and not for entities loaded from the DB — the behavior becomes asymmetric.
- Property visibility — any (`public`/`protected`/`private`). Reflection works regardless.
- If you use the factory pattern — `repository:` is usually also custom (see `cycle-orm/references/orm-extensions.md`); persisting via `$em->persist($invoice)` still works.

## Full `#[Entity]` signature

```php
#[Entity(
    role: 'user',                          // explicit role; useful when names collide across namespaces
    mapper: UserMapper::class,             // class-string<Cycle\ORM\MapperInterface>
    repository: UserRepository::class,     // class-string<Cycle\ORM\RepositoryInterface>
    table: 'app_users',                    // table name
    readonlySchema: false,                 // true → schema for this table is not synced
    database: 'default',                   // DB name from the DBAL config
    typecast: [MyCaster::class, Typecast::class], // typecast handlers; replaces the built-in Cycle\ORM\Parser\Typecast — list it explicitly
    scope: SoftDeletedScope::class,        // class-string<Cycle\ORM\Select\ScopeInterface> — applied to every query
)]
class User { ... }
```

There's also `source:` (`class-string<Cycle\ORM\Select\SourceInterface>`) — for cases when a table is read from one source but written to another. Not needed in 99% of projects; set it only if you know why.

### When to set things explicitly

| Option           | When to set                                                                                             |
|------------------|---------------------------------------------------------------------------------------------------------|
| `role`           | two entities with the same class name in different namespaces; relations reference entities by role. For modules, the typical convention is to prefix: `billing_invoice`, `mail_template` — namespacing via the role name instead of fighting collisions after the fact |
| `table`          | the table name doesn't match the pluralized role, or the pluralizer gives the wrong result for irregular words (`Series`, `News`, domain terms) |
| `database`       | multi-DB setup                                                                                          |
| `repository`     | custom query methods are needed (`findActive()`, etc.) — see `cycle-orm/references/orm-extensions.md`                          |
| `mapper`         | non-standard hydration/dehydration, custom event model — see `cycle-orm/references/orm-extensions.md`                          |
| `scope`          | global condition on all queries (soft-delete, tenant filter) — see `cycle-orm/references/orm-extensions.md`                    |
| `readonlySchema` | the table is created/managed externally, migrations shouldn't touch it                                  |
| `typecast`       | you use a **custom** typecast handler class. The list replaces the built-in handler, so include `Typecast::class` too (or add it via schema defaults). Built-in rules and callables work without this option. See `column-types.md`. |

## Composite primary key

A composite PK is set **one of two equivalent ways**: mark each column with `primary: true`, or list them in `#[Table(primary: new PrimaryKey(...))]`. Columns are ordinary (`int`/`string`/...), **not** `primary`/`bigPrimary` (otherwise they'd be auto-increment).

```php
// Option A: primary: true on each column
#[Entity]
class Product
{
    #[Column(type: 'int',    primary: true)]
    public int $tenant_id;
    #[Column(type: 'string', primary: true)]
    public string $sku;
    #[Column(type: 'string')]
    public string $name;
}

// Option B: list in Table
#[Entity]
#[Table(primary: new PrimaryKey(columns: ['tenant_id', 'sku']))]
class Product
{
    #[Column(type: 'int')]
    public int $tenant_id;
    #[Column(type: 'string')]
    public string $sku;
    #[Column(type: 'string')]
    public string $name;
}
```

Don't combine them — that's duplication; on disagreement it throws `EntityException("Ambiguous primary key definition")`. More details → `column-types.md` and `table-constraints.md`.

## Two column declaration styles

`cycle/annotated` supports **two styles** of column markup. Within a single codebase you can use either, or mix them.

| Style                          | Where the description lives                       | Where the class properties live    | When to pick                                                                  |
|--------------------------------|--------------------------------------------------|------------------------------------|-------------------------------------------------------------------------------|
| **A. Property-level**          | `#[Column]` directly above the PHP property      | in the class body as usual         | the default style from the cycle-orm docs; quick to write, visible "next to" |
| **B. Class-level**             | `#[Table(columns: […])]` or `#[Entity(columns: […])]` | in the body as "plain" PHP properties | DDD/clean architecture: mapping is separated from domain logic              |

### Style A: property-level attributes

The most widespread — this is the same style used in the official `cycle-orm.dev` documentation.

```php
#[Entity]
class User
{
    #[Column(type: 'primary')]
    public int $id;

    #[Column(type: 'string')]
    public string $email;

    #[BelongsTo(target: Tenant::class, nullable: true)]
    public ?Tenant $tenant = null;
}
```

**Pros:** mapping sits next to the property — easy to read, easy to edit a single field.
**Cons:** the domain class is "polluted" with infrastructure attributes.

### Style B: class-level attributes

Columns are declared **in one place on the class** via `#[Table(columns: [...])]` (or `#[Entity(columns: [...])]`). Properties in the class body remain "clean" PHP properties without attributes. The property↔column link is via the `property:` parameter on `#[Column]`.

```php
use Cycle\Annotated\Annotation as Cycle;

#[Cycle\Entity(
    role: 'billing_invoice',
    repository: InvoiceRepositoryImpl::class,
    table: 'billing_invoices',
    database: 'billing',
    typecast: [JsonValueObjectTypecast::class, Typecast::class],
)]
#[Cycle\Table(
    columns: [
        new Cycle\Column(type: 'uuid', property: 'id', primary: true, typecast: Uid::class),
        new Cycle\Column(type: 'uuid', property: 'project_id', typecast: Uid::class),
        new Cycle\Column(type: 'string', property: 'code'),
        new Cycle\Column(type: 'string', property: 'status', default: 'draft'),
    ],
    indexes: [
        new Cycle\Table\Index(columns: ['project_id', 'code'], unique: true),
    ],
)]
class Invoice
{
    public string $id;
    public string $project_id;
    public string $code;
    public string $status;

    private function __construct() {}
}
```

**Key detail:** `property:` binds the column to a class property. It is required only when the property name differs from `name:`; with neither set, the column cannot be resolved (`Configurator.php:233-240`).

**Pros:**
- The domain class doesn't know about the ORM at the property-attribute level.
- The entire table mapping is visible as one block at the top — easy to review migrations.
- Pairs naturally with DDD: `private __construct` + Factory + JSON-VO via a custom typecast (`column-types.md`).

**Cons:**
- More verbose: each `new Cycle\Column(...)` is written by hand.
- When adding a property, edits in **two** places: the property itself + the column in `#[Table]`.
- IDE navigation "property → column" works worse than with property-level.

**Alternative form — columns inside `#[Entity]`:** the `#[Entity]` constructor also accepts `columns:` and `foreignKeys:`. The semantics are the same; `#[Table]` remains for indexes and table-level PK. A matter of taste.

### Hybrid style (B + relations in A)

In real DDD projects a **hybrid** is common: columns on the class (style B), relations above the property (style A). This is done because relations are typed via the property (`public ?Tenant $tenant`), and keeping them in `#[Table]` as `new Relation\BelongsTo(...)` without a property binding would be less clear.

```php
#[Cycle\Entity(role: 'billing_invoice', table: 'billing_invoices', database: 'billing')]
#[Cycle\Table(columns: [
    new Cycle\Column(type: 'uuid',   property: 'id', primary: true, typecast: Uid::class),
    new Cycle\Column(type: 'uuid',   property: 'account_product_id', typecast: Uid::class),
    new Cycle\Column(type: 'string', property: 'code'),
])]
class Invoice
{
    public string $id;
    public string $account_product_id;
    public string $code;

    #[Cycle\Relation\BelongsTo(
        target: AccountProduct::class,
        innerKey: 'account_product_id',
        fkCreate: true,
        indexCreate: false,
    )]
    public AccountProduct $accountProduct;

    private function __construct() {}
}
```

Pick one convention per project.

## Common pitfalls

- **Forgot `#[Entity]`** on a class with `#[Column]` — the locator skips it silently, the class never appears in the schema.
- **The class is outside the locator's configured directories** (`TokenizerEntityLocator` / `Entity` locator) — it also won't reach the schema. This is an application-level configuration, not an attribute one, but the symptom is the same.
- **No primary column** → no error at compile time: the entity is silently left out of the schema (`schema-builder/src/Compiler.php:48`), and the first `$orm->getRepository(X::class)` throws ``SchemaException: Undefined schema `App\User`, not found.`` Add `#[Column(type: 'primary')]`, `primary: true` on an existing column, or a composite PK.
- **Table name not guessed correctly** for irregular words or domain terms. Set `table:` explicitly instead of fighting the pluralizer.
- **Role conflict across namespaces**: `App\Billing\Invoice` and `App\Mail\Invoice` both get role=`invoice`. Give them distinct explicit `role`s.
- **`#[Entity]` without arguments on an existing class** — works, but renaming or moving the class later changes the role and table. For production, set `role` and `table` explicitly.
- **Forgot `readonlySchema: true`**, and a migration wipes columns. If the table is Cycle-managed — leave the default `false`.
- **Class-level style — typo in `property:`**: the name must **exactly** match the PHP property. The schema compiles, but hydration is empty or crashes.
- **Same column declared in both styles** (`#[Column]` over the property + the same column in `#[Table(columns: [...])]`) — declare each column in exactly one place.
- **Inheritance + class-level**: on inheritance (`inheritance.md`) the child entity inherits columns via the PHP mechanism. But if the child redefines `#[Table]` without parent columns — they **disappear**.

## Checklist when creating an entity

1. The class is marked `#[Entity]` (plus, if needed, explicit `role`/`table`/`database`/`repository`).
2. The class is **not `final`** and **not `readonly`**; properties are **not `readonly`** and **not `static`**.
3. There is a primary column: either one `#[Column(type: 'primary')]` / `primary: true`, or a composite PK via `#[Table(primary: new PrimaryKey(...))]`.
4. The table name is what you expect (eyeball the pluralizer).
5. The column declaration style (A or B) matches the project convention; if class-level, every `Column` has a correct `property:`.
6. If you set `scope`/`repository`/`mapper` — the corresponding class implements the right interface from `cycle/orm` (see `cycle-orm/references/orm-extensions.md`).
7. Columns are described correctly (`column-types.md`), relations — separately (`relations.md`).
8. For STI/JTI — the parent is marked with `#[DiscriminatorColumn]`/`#[SingleTable]`/`#[JoinedTable]` (`inheritance.md`).
