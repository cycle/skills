# Column types and typecast

`#[Column]` describes a single table column — its SQL type, name, default, nullable, and **typecast** (the rule that converts the value between the DB and the PHP object).

See also:
- where `#[Column]` lives (on a property vs on the class) → `define-entity.md`
- indexes / composite PK / manual FKs at the table level → `table-constraints.md`
- `#[Column]` for an FK column for a relation → `relations.md`
- Embeddable as an alternative to JSON-VO → `embeddable.md`
- **in-depth typecast work** (custom classes, `CompositeTypecast`, JSON-VO pattern) → `cycle-orm/resources/typecasters-advanced.md`

## Minimum

```php
#[Column(type: 'string')]
public string $email;
```

Default parameters:
- `name` = property name (`$email` → column `email`)
- `nullable` = `false`
- `default` = unset
- `primary` = `false`
- `typecast` = unset (the value arrives "as is" from the DB)

## Full signature

```php
#[Column(
    type: 'string',                 // required
    name: 'user_email',             // column name in the DB; default = property name
    property: 'email',              // for class-level declaration — see define-entity.md
    primary: false,                 // include in the PK
    nullable: false,
    default: null,                  // default value (null != "unset")
    typecast: 'datetime',           // conversion rule; see below
    castDefault: false,             // apply typecast to the default value at load time
    readonlySchema: false,          // true → column is not synced by migrations
    // ...db-specific attributes (see below)
)]
public string $email;
```

---

## Column type

`type:` is an ordinary string, not an enum. Cycle passes the value to `cycle/database` (DBAL), which maps it to the SQL type of the specific driver. What's available depends on the driver and its extensions (for example, `vector` appears if pgvector is installed). `ExpectedValues` in `Column.php` is just an IDE/psalm hint, not validation.

Standard types are the usual ones for any DBAL: `integer`, `bigInteger`, `smallInteger`, `tinyInteger`, `float`, `double`, `decimal`, `boolean`, `string`, `text`, `tinyText`, `longText`, `datetime`, `date`, `time`, `timestamp`, `binary`, `tinyBinary`, `longBinary`, `json`. Behavior is the usual one; PHP+SQL knowledge covers it.

### Types with special ORM semantics

Cycle handles these not as "just an SQL-type mapping" — they have their own behavior in the schema or in hydration:

- **`primary` / `bigPrimary`** — automatically PK + auto-increment. INT/BIGINT respectively.
- **`smallPrimary`** — same, but SMALLINT *(PostgreSQL only)*.
- **`uuid` / `ulid` / `snowflake`** — identifiers; **must** be paired with `typecast:` (otherwise you get a raw string from the DB).
- **`enum`** — requires `values:` (array of strings or backed-enum class); see the enum section.
- **`json` / `jsonb`** — work with the built-in `typecast: 'json'`; `jsonb` is PG only.

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

**Cross-driver project pitfall:** if a migration runs across several DBs (`SQLite` locally, `PostgreSQL` in prod), a driver-specific type will crash on a driver that doesn't know it. Use only common types, or split configs.

> The full list of "canonical" types and their per-driver mappings — `Cycle\Database\Schema\AbstractColumn::$mapping` + `Cycle\Database\Driver\{Postgres,SQLServer,MySQL,SQLite}\Schema\*Column::$mapping`.

---

## Primary column

### Single PK

**`type: 'primary'` / `type: 'bigPrimary'`** — auto-increment INT/BIGINT, PK is set automatically. The most common case.

```php
#[Column(type: 'primary')]
public int $id;
```

**`primary: true` on a regular column** — PK **without** auto-increment. Used for UUID/ULID/business-key.

```php
#[Column(type: 'uuid', primary: true, typecast: Uid::class)]
public string $id;
```

### Composite PK — two equivalent ways

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

**Choice of style** is a project convention. A is more explicit on the field, B keeps the PK declaration in one place. Don't combine them — it's redundant, and on a mismatch it'll crash.

### Pitfalls

- **Composite PK with `type: 'primary'`** — not allowed. `'primary'` means auto-increment, and in most DBs auto-increment can only be on a single column. For a composite PK, use regular `int`/`string`/... + `primary: true` (way A) or `Table(primary: PrimaryKey)` (way B).
- **`type: 'primary'` + `primary: true` on the same column** — both are true for the schema-builder, the effect is identical (PK + auto-increment), it's just a duplicate declaration. One is enough.

## Identifiers

For UUID/ULID/Snowflake you **must** pair the type with a typecast (see below), otherwise you'll get a raw string from the DB instead of an object.

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
- `default: null` **!= unset**. `null` is explicitly written as DEFAULT NULL. To have no default at all — simply don't pass the parameter.
- `default` is stored in the **schema** (a migration will create the column with `DEFAULT 'draft'`). When creating an entity in PHP this value is **not** applied automatically — it's a DB-level default. If you want a new object `new Status()` to immediately have the value — set the default **also** in PHP (`public string $status = 'draft';`).
- `castDefault: true` — apply the typecast to the default value at load (useful for datetime defaults via a string).

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

`typecast: InvoiceStatus::class` — at load time Cycle automatically calls `InvoiceStatus::tryFrom($value)` (see the BackedEnum section below).

You can also pass an array of enum cases:
```php
#[Column(type: 'enum', values: [InvoiceStatus::Draft, InvoiceStatus::Sent])]
```

**Pitfall:** SQL ENUM lives poorly with migrations (ADD VALUE requires care, ORDER is strict in PG). On PG/MSSQL it's often more practical to keep the column as a `string` + validate at the PHP level.

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

`#[GeneratedValue]` marks that the value is **generated automatically** — by the DB on insert (autoincrement), or by PHP before insert/update.

```php
use Cycle\Annotated\Annotation\GeneratedValue;

#[Column(type: 'primary')]
#[GeneratedValue(onInsert: true)]              // DB auto-increment on insert
public int $id;

#[Column(type: 'uuid', primary: true)]
#[GeneratedValue(beforeInsert: true)]          // PHP side generates UUID before insert
public string $uuid;

#[Column(type: 'datetime', default: 'CURRENT_TIMESTAMP')]
#[GeneratedValue(beforeInsert: true)]
public \DateTimeImmutable $create_time;

#[Column(type: 'datetime', nullable: true)]
#[GeneratedValue(beforeUpdate: true)]          // refreshed on every save()
public ?\DateTimeImmutable $update_time = null;
```

**Three flags:**
- `beforeInsert: true` — generated by PHP before INSERT (UUID/ULID/timestamp).
- `onInsert: true` — generated by the DB on INSERT (autoincrement — Cycle sets it for `primary`/`bigPrimary` itself).
- `beforeUpdate: true` — refreshed on every UPDATE (`update_time`).

Without `GeneratedValue` the field value comes from the entity itself (whatever you put in it manually).

---

## Database-specific attributes

`#[Column]` accepts additional named parameters (via `mixed ...$attributes`) that proxy to DBAL:

```php
#[Column(type: 'string', length: 320)]                    // VARCHAR(320)
public string $email;

#[Column(type: 'decimal', precision: 10, scale: 2)]       // DECIMAL(10,2)
public string $amount;

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

## Two registration levels

**1. On the column** — `#[Column(typecast: ...)]`. Specifies which rule to apply to this column.

```php
#[Column(type: 'datetime')]
public \DateTimeImmutable $create_time;        // crashes: the DB returns a string

#[Column(type: 'datetime', typecast: 'datetime')]
public \DateTimeImmutable $create_time;        // the built-in rule applies
```

**2. On the entity** — `#[Entity(typecast: ...)]`. Registers **handler classes** that know how to process rules:

```php
#[Entity(typecast: [Typecast::class, JsonValueObjectTypecast::class])]
class Invoice { /* ... */ }
```

**The link:** on a column you write the **rule name**, on an entity you register the **handler classes** that apply that rule. For built-in rules (`int`/`bool`/`float`/`datetime`/`json`) and BackedEnum the default handler (`Cycle\ORM\Parser\Typecast`) is wired in **automatically** — no explicit registration needed.

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

The default `Typecast` itself recognizes a `BackedEnum` class and calls `tryFrom()`:

```php
enum InvoiceStatus: string
{
    case Draft = 'draft';
    case Sent  = 'sent';
    case Paid  = 'paid';
}

#[Column(type: 'string', typecast: InvoiceStatus::class)]
public InvoiceStatus $status;
```

At load Cycle calls `InvoiceStatus::tryFrom($value)`. Works for both `string`-backed and `int`-backed (with automatic normalization). **Handler registration is not needed** — the built-in `Typecast` handles it.

For **uncast** nothing needs to be configured — DBAL converts a `BackedEnum` to its `value` via `->value` itself.

## What the built-in rules don't cover

- **UUID/ULID/Snowflake** → need a callable or a custom typecast class.
- **Value Objects** (`Money`, `Address`, `BillingInterval`) — need a custom typecast class.
- **Complex logic** (timezone-conversion, decimal precision, multi-arg formats) — callable or class.

For all that — **read `cycle-orm/resources/typecasters-advanced.md`**. It covers:
- callable typecast (3 forms: 1-arg, with DatabaseInterface, with extra arguments),
- custom typecast classes via `CastableInterface`/`UncastableInterface`,
- `CompositeTypecast` (a chain of handlers),
- the "JSON value-objects in a jsonb column" pattern (steps 1-5, full example).

---

## Common pitfalls

- **`default: null` for a non-nullable column** → the schema crashes. If you want NULL — add `nullable: true`.
- **Default string length** — typically 255 (driver-dependent). For email/URL — set `length:` explicitly.
- **PG-only/MSSQL-only types in a cross-driver project** — crashes on the others. Use `jsonb` only if you're sure of PG; otherwise `json`.
- **ENUM with migrations** — changing the value list is operationally painful (especially on PG). Often `string` is simpler.
- **`type: 'datetime'` + PHP `\DateTimeImmutable` without a typecast** → you get a string from the DB, not an object. Add `typecast: 'datetime'`.
- **`type: 'primary'` on a UUID column** — `primary` is autoincrement INT. For a UUID-PK you need `type: 'uuid'` + `primary: true`.
- **GeneratedValue without flags** → has no effect (`getFlags()` returns `null`). Set at least one of `beforeInsert`/`onInsert`/`beforeUpdate`.
- **`property:` typo** in class-level style → the column won't bind to the property, hydration sails right past.
- **`typecast` on a column, but the handler isn't registered on the entity** → the column isn't converted, the raw DB value lands in the property. This is especially silent for custom typecast classes.
- **DateTime timezone** — the built-in `datetime` rule takes TZ from the DB driver. If the service has a different TZ — set it on the driver, or use your own datetime typecast.
- **`datetime` is stored as `DateTimeImmutable`, not `DateTime`** — if the property is `\DateTime` (mutable), an immutable comes back from the DB. Use `\DateTimeImmutable`.
- **Enum value in the DB != cases()** — `tryFrom()` returns `null`, into a not-nullable property it crashes. Keep values in sync with the enum.

## Checklist

1. The column type matches the project's driver (no PG-only type on MySQL, etc.).
2. For the PK the correct option is chosen: `primary`/`bigPrimary` (autoincrement) **or** `primary: true` (for UUID/ULID/business-key).
3. If the field is nullable — `nullable: true` is set AND the PHP type is marked `?T`.
4. For `string` columns with a known length — `length:` is set.
5. For `datetime`/`enum`/`uuid`/`ulid`/`json`/VO — `typecast:` is set (or you're deliberately working with a raw string).
6. If you use a custom typecast class — it's registered in `#[Entity(typecast: [...])]`.
7. `GeneratedValue` is placed exactly where the value isn't generated from PHP code by hand.
8. `default` is duplicated in PHP if you want to see it in new objects too.
9. For class-level declaration, every `Column` has a correct `property:` (`define-entity.md`).
