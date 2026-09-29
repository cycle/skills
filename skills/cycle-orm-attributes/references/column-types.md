# Column types and typecast

`#[Column]` describes a single table column — its SQL type, name, default, nullable, and **typecast** (the rule that converts the value between the DB and the PHP object).

See also:
- where `#[Column]` lives (on a property vs on the class) → `define-entity.md`
- indexes / composite PK / manual FKs at the table level → `table-constraints.md`
- `#[Column]` for an FK column for a relation → `relations.md`
- Embeddable as an alternative to JSON-VO → `embeddable.md`
- **in-depth typecast work** (custom classes, `CompositeTypecast`, JSON-VO pattern) → `cycle-orm/references/typecasters-advanced.md`

## Minimum

```php
#[Column(type: 'string')]
public string $email;
```

Default parameters:
- `name` = snake_case of the property name (`$customerId` → column `customer_id`)
- `nullable` = `false`
- `default` = unset
- `primary` = `false`
- `typecast` = inferred from the column type by `GenerateTypecast` — see "Automatic rules" below

## Full signature

```php
#[Column(
    type: 'string',                 // required
    name: 'user_email',             // column name in the DB; default = snake_case property name
    property: 'email',              // for class-level declaration — see define-entity.md
    primary: false,                 // include in the PK
    nullable: false,
    default: 'x',                   // DB-level default; null means "no default"
    typecast: 'datetime',           // conversion rule; see below
    castDefault: false,             // true + no `default:` → DDL gets a type-zero default (0, 0.0, false, '')
    readonlySchema: false,          // true → column is not synced by migrations
    // ...db-specific attributes (see below)
)]
public string $email;
```

---

## Column type

`type:` is an ordinary string, not an enum. Cycle passes the value to `cycle/database` (DBAL), which maps it to the SQL type of the specific driver. What's available depends on the driver and its extensions (for example, `vector` appears if pgvector is installed). The `#[ExpectedValues]` list on `type:` is an IDE/psalm hint, not validation.

### Types with special ORM semantics

Cycle handles these not as "just an SQL-type mapping" — they have their own behavior in the schema or in hydration:

- **`primary` / `bigPrimary`** — automatically PK + auto-increment. INT/BIGINT respectively.
- **`smallPrimary`** — same, but SMALLINT *(PostgreSQL only)*.
- **`uuid` / `ulid` / `snowflake`** — identifiers; see "Identifiers".
- **`enum`** — requires `values:` (array of strings or backed-enum class); see "Enum columns".
- **`json` / `jsonb`** — no inferred typecast; see "Automatic rules". `jsonb` is PG only.
- **`decimal`** — inferred as `float` (precision loss); a `string $amount` property then fails hydration with `MapperException`. Use a `float` property or a callable typecast that returns a string.

Everything else is driver-specific names and passthrough to DBAL. If your driver supports them — write them as is:

```php
#[Column(type: 'datetime2')]
public \DateTimeImmutable $ts;    // SQL Server
#[Column(type: 'timestamptz')]
public \DateTimeImmutable $ts;    // PostgreSQL
#[Column(type: 'inet')]
public string $ip;                // PostgreSQL
#[Column(type: 'vector', dim: 1536)]
public array $embedding;          // pgvector (if installed)
```

> The full list of "canonical" types and their per-driver mappings — `Cycle\Database\Schema\AbstractColumn::$mapping` + `Cycle\Database\Driver\{Postgres,SQLServer,MySQL,SQLite}\Schema\*Column::$mapping`.

---

## Primary column

### Single PK

**`type: 'primary'` / `type: 'bigPrimary'`** — auto-increment PK (see the type list above). The most common case.

```php
#[Column(type: 'primary')]
public int $id;
```

**`primary: true` on a regular column** — PK **without** auto-increment. Used for UUID/ULID/business-key.

```php
#[Column(type: 'uuid', primary: true, typecast: Uid::class)]
public string $id;
```

Adding `primary: true` to a `type: 'primary'` column changes nothing — the type already makes it the PK.

### Composite PK — two equivalent ways

PK columns are ordinary `int`/`string`/..., never `primary`/`bigPrimary`: those mean auto-increment, which most DBs allow on a single column only.

**A. Multiple `primary: true`** — flag each PK column:

```php
#[Entity]
class Product
{
    #[Column(type: 'int', primary: true)]
    public int $tenant_id;

    #[Column(type: 'string', primary: true)]
    public string $sku;
}
```

**B. `Table(primary: PrimaryKey)`** — list the columns in one block:

```php
#[Entity]
#[Table(primary: new PrimaryKey(columns: ['tenant_id', 'sku']))]
class Product
{
    #[Column(type: 'int')]
    public int $tenant_id;

    #[Column(type: 'string')]
    public string $sku;
}
```

Both ways work and are equivalent at the schema level. Internally Cycle holds two parallel structures:
- a per-field `primary` flag (filled by way A),
- an `EntitySchema::$primaryFields` map (filled by way B via `setPrimaryColumns`).

`getPrimaryFields()` checks both:
- if **only one** is filled — takes it;
- if **both** are filled and match — ok, no error;
- if **both** are filled and diverge — `EntityException("Ambiguous primary key definition")`.

**Choice of style** is a project convention: A is more explicit on the field, B keeps the PK declaration in one place. Use one style per entity.

## Identifiers

UUID/ULID/Snowflake columns hydrate as strings. A `string` property needs nothing more; for a value-object property add a callable or custom typecast (`cycle-orm/references/typecasters-advanced.md`).

**Alternative for UUID generation:** the `#[Uuid7]` attribute (or `Uuid1`..`Uuid6`) from `cycle/entity-behavior-uuid` creates the column itself, registers the typecast and generates a UUID of the required version on `OnCreate` — see `behaviors.md`.

---

## Default values

```php
#[Column(type: 'string', default: 'draft')]
public string $status;

#[Column(type: 'integer', default: 0)]
public int $views;

#[Column(type: 'boolean', default: false)]
public bool $is_active;

#[Column(type: 'datetime', default: 'CURRENT_TIMESTAMP')]
public \DateTimeImmutable $create_time;
```

**Important details:**
- `default: null` is the same as no default. For `DEFAULT NULL` set `nullable: true`.
- A BackedEnum default is stored as its `->value`.
- `default` is stored in the **schema** (a migration will create the column with `DEFAULT 'draft'`). When creating an entity in PHP this value is **not** applied automatically — it's a DB-level default. If you want a new object `new Status()` to immediately have the value — set the default **also** in PHP (`public string $status = 'draft';`).
- `castDefault: true` without `default:` — the DDL gets a zero default for the type: `0` for int/datetime, `0.0`, `false`, `''`, or the first enum value. Schema-only; it doesn't affect hydration.

---

## Enum columns

Cycle supports SQL ENUM via `type: 'enum'` + the `values:` parameter:

```php
#[Column(type: 'enum', values: ['draft', 'sent', 'paid'])]
public string $status;
```

With a **PHP backed enum** — pass the class name, Cycle assembles the value list from `cases()` itself:

```php
enum InvoiceStatus: string
{
    case Draft = 'draft';
    case Sent  = 'sent';
    case Paid  = 'paid';
}

#[Column(type: 'enum', values: InvoiceStatus::class, typecast: InvoiceStatus::class)]
public InvoiceStatus $status;
```

`typecast: InvoiceStatus::class` hydrates the value into the enum — see "BackedEnum" below.

You can also pass an array of enum cases:
```php
#[Column(type: 'enum', values: [InvoiceStatus::Draft, InvoiceStatus::Sent])]
```

---

## `name` vs `property`

- **`name:`** — column name in the DB (if it differs from the property name).
- **`property:`** — PHP property name of the class the column belongs to. Needed for **class-level style** (`define-entity.md`).

With **property-level style** `property` isn't needed — it's inferred from the attribute's position. `name` is enough.

```php
// Property-level: column name = 'usr_email', property = $email (inferred automatically)
#[Column(type: 'string', name: 'usr_email')]
public string $email;

// Class-level: same, but property is specified explicitly
#[Cycle\Table(columns: [
    new Cycle\Column(type: 'string', name: 'usr_email', property: 'email'),
])]
class User
{
    public string $email;
}
```

---

## GeneratedValue

`#[GeneratedValue]` is **metadata only**: it tells the ORM who fills the value, so an INSERT may go out without it. Cycle itself generates nothing — the value comes from the DB, from your code, or from a behavior listener (`#[CreatedAt]`, `#[UpdatedAt]`, `#[Uuid7]` — see `behaviors.md`), which sets these flags itself.

```php
use Cycle\Annotated\Annotation\GeneratedValue;

#[Column(type: 'bigInteger', primary: true)]
#[GeneratedValue(onInsert: true)]              // DB fills it (sequence/trigger); ORM reads it back
public int $id;

#[Column(type: 'uuid', primary: true)]
#[GeneratedValue(beforeInsert: true)]          // a PHP listener fills it before INSERT
public string $uuid;
```

**Three flags:**
- `onInsert: true` — the DB generates the value on INSERT. Set **automatically** for every primary field (`type: 'primary'` and `primary: true` alike) and for `serial` types.
- `beforeInsert: true` — PHP code fills it before INSERT.
- `beforeUpdate: true` — PHP code refreshes it before every UPDATE.

For a PK generated in PHP (UUID set by a listener or your code), `beforeInsert: true` replaces the automatic `onInsert`.

---

## Database-specific attributes

`#[Column]` accepts additional named parameters (via `mixed ...$attributes`) that proxy to DBAL:

```php
#[Column(type: 'string', length: 320)]                    // VARCHAR(320)
public string $email;

#[Column(type: 'decimal', precision: 10, scale: 2)]       // DECIMAL(10,2)
public float $amount;

#[Column(type: 'integer', unsigned: true)]                // UNSIGNED INT (MySQL)
public int $views;

#[Column(type: 'smallInteger', unsigned: true, zerofill: true)]  // MySQL specifics
public int $code;
```

The behavior depends on the driver. `unsigned` works on MySQL, ignored on PG/SQLite. If a migration runs across multiple drivers — verify the DDL on each.

---

## `readonlySchema`

```php
#[Column(type: 'string', readonlySchema: true)]
public string $external_id;
```

Cycle **will not** touch this column during migrations (won't create, drop, or modify). Used when the column is managed by an external process / DB trigger / a different migration tool.

---

# Typecast

Cycle stores data in the DB as strings/numbers. In a PHP entity they must be turned into objects (`DateTimeImmutable`, BackedEnum, VO). This is done by the **typecaster** — a set of rules applied at load (cast) and at save (uncast).

## Automatic rules

`Cycle\Schema\Generator\GenerateTypecast` — part of the standard Compiler pipeline (`cycle-orm/references/installation.md`) — assigns a rule to every column **without** `typecast:`, based on the column type in the table schema:

| Column type                                  | Inferred rule |
|----------------------------------------------|---------------|
| boolean                                      | `bool`        |
| integer types, `primary`/`bigPrimary`        | `int`         |
| `float`/`double`/`decimal`                   | `float`       |
| `datetime`/`date`/`time`/`timestamp`         | `datetime`    |
| everything else (`string`, `json`, `uuid`, `enum`, ...) | none — stays a string |

So `#[Column(type: 'datetime')] public \DateTimeImmutable $at;` works without `typecast:`. Write `typecast:` explicitly for `json`, BackedEnum, value-objects, or to override the inferred rule.

## Two registration levels

**1. On the column** — `#[Column(typecast: ...)]`: the **rule** for this column (a built-in rule name, a BackedEnum class, a callable, or a rule name your handler understands).

```php
#[Column(type: 'json', typecast: 'json')]
public array $metadata = [];
```

**2. On the entity** — `#[Entity(typecast: ...)]`: the **handler classes** that interpret rules.

```php
#[Entity(typecast: [JsonValueObjectTypecast::class, Typecast::class])]
class Invoice { /* ... */ }
```

**Handler list replaces the built-in one.** `Cycle\ORM\Parser\Typecast` is used only when the entity declares no handler. With `typecast: MyHandler::class` alone, the built-in rules — including the inferred `datetime`/`int`/`bool` — stop applying to that entity. List `Typecast::class` explicitly, or add it once via schema defaults:

```php
// Compiler defaults are merged into every entity's handler list: entity handlers first, then defaults.
// spiral/cycle-bridge passes its `schema.defaults` config here.
(new Compiler())->compile($registry, $generators, [
    SchemaInterface::TYPECAST_HANDLER => [Typecast::class],
]);
```

A default list without `Typecast::class` removes the built-in handler from every entity that doesn't declare its own.

## Built-in rules

The default handler `Cycle\ORM\Parser\Typecast` supports **five** rules:

| Rule         | What it does (cast at load)                                       |
|--------------|-------------------------------------------------------------------|
| `'int'`      | `(int)$value`                                                     |
| `'float'`    | `(float)$value`                                                   |
| `'bool'`     | `(bool)$value`                                                    |
| `'datetime'` | `new \DateTimeImmutable($value, $driverTimezone)`                 |
| `'json'`     | `json_decode($value, true, ...)` on cast, `json_encode` on uncast |

```php
#[Column(type: 'integer', typecast: 'int')]
public int $views;

#[Column(type: 'json', typecast: 'json')]
public array $metadata = [];

#[Column(type: 'datetime', typecast: 'datetime')]
public \DateTimeImmutable $create_time;
```

**Note:** the `json` rule is two-way (cast + uncast). `datetime` — cast only; on save DBAL converts `DateTimeImmutable` to a string itself.

## BackedEnum

The default `Typecast` recognizes a `BackedEnum` class as a rule and calls `tryFrom($value)` at load (`InvoiceStatus` as in "Enum columns"):

```php
#[Column(type: 'string', typecast: InvoiceStatus::class)]
public InvoiceStatus $status;
```

Works for both `string`-backed and `int`-backed enums (with automatic normalization). The built-in `Typecast` handles it — no registration needed unless the entity declares its own handler list (then include `Typecast::class`).

For **uncast** nothing needs to be configured — DBAL converts a `BackedEnum` to its `value` via `->value` itself.

## What the built-in rules don't cover

- **Value objects** (UUID/ULID objects, `Money`, `Address`, `BillingInterval`) — a callable or a custom typecast class.
- **Complex logic** (timezone-conversion, decimal precision, multi-arg formats) — callable or class.

For all that — **read `cycle-orm/references/typecasters-advanced.md`**. It covers:
- callable typecast (3 forms: 1-arg, with DatabaseInterface, with extra arguments),
- custom typecast classes via `CastableInterface`/`UncastableInterface`,
- `CompositeTypecast` (a chain of handlers),
- the "JSON value-objects in a jsonb column" pattern (steps 1-5, full example).

---

## Common pitfalls

- **Custom handler on the entity, and `datetime`/`int`/BackedEnum columns come back raw** → `#[Entity(typecast: X::class)]` replaced the built-in handler. Use `typecast: [X::class, Typecast::class]` or add `Typecast::class` to schema defaults.
- **Default string length** — typically 255 (driver-dependent). For email/URL — set `length:` explicitly.
- **Migration fails on one driver only** (SQLite locally, PostgreSQL in prod) → a driver-specific type (`jsonb`, `inet`, `datetime2`, ...) the other driver doesn't know. Stick to common types (`json`, not `jsonb`) or split configs per driver.
- **SQL ENUM value list needs to change** → migrating ENUM is painful (ADD VALUE needs care, ordering is strict on PG). On PG/MSSQL a `string` column + PHP-level validation is often more practical.
- **`datetime` column hydrates as a string** → `GenerateTypecast` is missing from the Compiler pipeline. Add it (or set `typecast: 'datetime'` per column).
- **`type: 'primary'` on a UUID column** — `primary` is autoincrement INT. For a UUID-PK you need `type: 'uuid'` + `primary: true`.
- **GeneratedValue without flags** → has no effect (`getFlags()` returns `null`). Set at least one of `beforeInsert`/`onInsert`/`beforeUpdate`.
- **Custom rule on a column, but no handler for it on the entity** → the column isn't converted; the raw DB value lands in the property, silently.
- **DateTime timezone** — the built-in `datetime` rule takes TZ from the DB driver. If the service has a different TZ — set it on the driver, or use your own datetime typecast.
- **`\DateTime` (mutable) property** → the `datetime` rule produces `DateTimeImmutable`, and hydration fails with `MapperException("Can't hydrate an entity because property and value types are incompatible.")`. Type the property as `\DateTimeImmutable` (or `\DateTimeInterface`).
- **Enum value in the DB != cases()** — `tryFrom()` returns `null`, into a not-nullable property it crashes. Keep values in sync with the enum.

## Checklist

1. The column type matches the project's driver (no PG-only type on MySQL, etc.).
2. For the PK the correct option is chosen: `primary`/`bigPrimary` (autoincrement) **or** `primary: true` (for UUID/ULID/business-key).
3. If the field is nullable — `nullable: true` is set AND the PHP type is marked `?T`.
4. For `string` columns with a known length — `length:` is set.
5. `json`, BackedEnum, and value-object columns have an explicit `typecast:`; the Compiler pipeline includes `GenerateTypecast` for the rest.
6. A custom typecast class is registered in `#[Entity(typecast: [...])]` **together with** `Typecast::class` (or `Typecast::class` is in schema defaults).
7. `GeneratedValue` marks only fields filled by the DB or by a listener, never ones your code sets before `persist()`.
8. `default` is duplicated in PHP if you want to see it in new objects too.
9. For class-level declaration, every `Column` has a correct `property:` (`define-entity.md`).
