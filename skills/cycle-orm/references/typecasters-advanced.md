# Typecasters: deep API

For cases where **built-in rules** (`int`/`bool`/`float`/`datetime`/`json` + BackedEnum) aren't enough. Contains:

- callable typecast (3 call forms),
- custom typecast classes via `CastableInterface`/`UncastableInterface`,
- `CompositeTypecast` (chain of handlers),
- the "JSON value-objects in a jsonb column" pattern.

The basic part of typecast lives in `cycle-orm-attributes/references/column-types.md`.

---

## Callable typecast (3 forms)

When a stateless function/method is enough — pass a callable as the rule.

Callable rules are handled by the built-in `Typecast`: if the entity declares its own handler list, include `Typecast::class` in it.

Callable rules convert only on read. On write the object reaches DBAL as is: a `Stringable` object is written as its string, any other object fails with `Object of class ... could not be converted to string`. For a two-way conversion use a class implementing `UncastableInterface` (below).

### 1. Single-argument function

```php
#[Column(type: 'uuid', typecast: [Uid::class, 'fromString'])]
public Uid $id;
```

`Uid::fromString($value)` will be called on cast. The method takes one parameter — the value from the DB.

### 2. Two-argument function (with `DatabaseInterface`)

```php
class GeoPointCaster
{
    public static function fromDb(mixed $value, DatabaseInterface $db): GeoPoint
    {
        // $db is available for driver-specific parsing
        return GeoPoint::parse($value);
    }
}

#[Column(type: 'point', typecast: [GeoPointCaster::class, 'fromDb'])]
public GeoPoint $location;
```

A two-element callable with more than one parameter always receives the `DatabaseInterface` as its second argument; the parameter type is not checked. Useful when you need the timezone, DB version, etc.

### 3. Callable with extra arguments

```php
#[Column(type: 'string', typecast: [DecimalCaster::class, 'cast', [2]])]  // 2 decimal places
public Decimal $amount;
```

The third array element is the list of extra arguments. The DB is injected only when the second parameter is typed `DatabaseInterface`; otherwise the extra arguments start at position 2. Always declare a type on the second parameter. Method signature:
```php
public static function cast(mixed $value, int $scale): Decimal { ... }
// or with DatabaseInterface:
public static function cast(mixed $value, DatabaseInterface $db, int $scale): Decimal { ... }
```

### When to use callable vs a class

- **Callable** — simple transformation, no shared logic between columns, no DI dependencies needed.
- **Class** (see below) — you need state, DI, shared logic for multiple columns of the same type (e.g., your `JsonValueObjectTypecast` services 5 different VOs).

---

## Custom typecast class

Implements `CastableInterface` (for cast) and/or `UncastableInterface` (for uncast). Both extend `TypecastInterface`.

### Minimum: `setRules + cast + uncast`

```php
use Cycle\ORM\Parser\CastableInterface;
use Cycle\ORM\Parser\UncastableInterface;
use Cycle\ORM\Exception\TypecastException;

final class JsonValueObjectTypecast implements CastableInterface, UncastableInterface
{
    /** @var array<string, \Closure(array): JsonValueObject> */
    private array $factory = [];

    public function setRules(array $rules): array
    {
        foreach ($rules as $key => $rule) {
            // Is this rule a class-string of a class implementing JsonValueObject?
            if (\is_string($rule) && \is_subclass_of($rule, JsonValueObject::class, true)) {
                $this->factory[$key] = $rule::fromJson(...);
                unset($rules[$key]);   // ← remove the rule from the shared list
            }
        }
        return $rules;   // return the remaining rules to the next handler
    }

    public function cast(array $data): array
    {
        foreach ($this->factory as $key => $factory) {
            if (!isset($data[$key])) continue;
            $json = \json_decode($data[$key], true, 512, \JSON_THROW_ON_ERROR);
            $data[$key] = $factory($json);
        }
        return $data;
    }

    public function uncast(array $data): array
    {
        foreach ($this->factory as $key => $_) {
            if (!isset($data[$key])) continue;
            \assert($data[$key] instanceof JsonValueObject);
            $data[$key] = \json_encode($data[$key]->jsonSerialize(), \JSON_UNESCAPED_UNICODE);
        }
        return $data;
    }
}
```

**Key points:**
1. **`setRules($rules)`** is called once at initialization. It receives all the entity's rules, **picks out its own**, and returns the rest. The return value flows to the next typecast (see `CompositeTypecast` below).
2. **`cast(array $data)`** — for each row from the DB. Transforms `data[key]` for the columns this typecast claimed in `setRules`.
3. **`uncast(array $data)`** — the inverse transformation before writing to the DB.
4. **`unset($rules[$key])`** — marks the rule as claimed; the next handler receives only the rest. A rule that no handler claims is silently ignored: the column keeps the raw DB value, with no error.

### Registration

```php
#[Entity(
    role: 'billing_invoice',
    typecast: [JsonValueObjectTypecast::class, Typecast::class],
)]
class Invoice
{
    #[Column(type: 'datetime', typecast: 'datetime')]              // ← handled by Typecast
    public \DateTimeImmutable $create_time;

    #[Column(type: 'jsonb', typecast: BillingInterval::class)]     // ← handled by JsonValueObjectTypecast
    public BillingInterval $interval;

    #[Column(type: 'string', typecast: InvoiceStatus::class)]      // ← BackedEnum — handled by Typecast
    public InvoiceStatus $status;
}
```

Order matters only when two handlers accept the same rule — the first one claims it. Here the rules don't overlap: `JsonValueObjectTypecast` claims the class-strings of its VOs, `Typecast` takes `'datetime'` and the BackedEnum.

---

## `CompositeTypecast`: handler chain

When `#[Entity(typecast: [...])]` lists two or more handlers, Cycle wraps them into `Cycle\ORM\Parser\CompositeTypecast` (a single handler is used as is):

1. Calls `A->setRules($all)` → `A` keeps what it needs, returns the rest.
2. Calls `B->setRules($rest)` → `B` claims its own.
3. And so on.
4. On `cast()` each handler processes its columns sequentially.

A child role in an inheritance hierarchy gets the parent's handler chained in front of its own, through the same `CompositeTypecast`.

`uncast()` goes in **reverse** order, so transformations are correctly "unwound".

---

## Pattern: JSON value-objects in a jsonb column

Scenario: you have a Value Object (`BillingInterval`, `Money`, `Address`), and you store it as a jsonb column.

### Step 1. Make the VO a `final readonly class` implementing `JsonValueObject`

```php
final readonly class BillingInterval implements JsonValueObject
{
    public function __construct(
        public string $unit,
        public int $count,
    ) {}

    public static function fromJson(array $json): self
    {
        return new self($json['unit'], $json['count']);
    }

    public function jsonSerialize(): array
    {
        return ['unit' => $this->unit, 'count' => $this->count];
    }
}
```

**Important:** the VO **can** be `final readonly` — it's not an Entity, isn't extended via a proxy; hydration goes through your typecast (not via ORM-level reflection).

### Step 2. Define a marker interface

```php
interface JsonValueObject extends \JsonSerializable
{
    public static function fromJson(array $json): self;
}
```

### Step 3. Write the typecast (see `JsonValueObjectTypecast` above)

### Step 4. On the column, specify the VO class

```php
#[Column(type: 'jsonb', nullable: true, typecast: BillingInterval::class)]
public ?BillingInterval $interval = null;
```

### Step 5. On the entity, register the handler

```php
#[Entity(typecast: [JsonValueObjectTypecast::class, Typecast::class])]
class Plan { /* ... */ }
```

### When this pattern is appropriate (vs Embeddable)

- **VO + JSON typecast** — when the VO is small, immutable, not queried separately in WHERE, not indexed. PG `jsonb` supports indexes on fields, but that's rarely needed.
- **Embeddable** (see `cycle-orm-attributes/references/embeddable.md`) — when the VO's fields must be separate columns in the DB (for indexing, WHERE filters, migrations).

In DDD projects VO + JSON is widespread; Embeddable is rarer.

---

## Common pitfalls (advanced)

- **Forgot `unset($rules[$key])`** in your own `setRules` → the next handler still sees the rule; if it accepts it too, the column is converted twice. Remove every rule you claim.
- **Custom typecast class without `UncastableInterface`** → on write the object reaches DBAL as is, the same as with callable rules (see "Callable typecast"). Implement `UncastableInterface` if you cast objects.
- **Built-in `json` rule vs custom JSON-VO typecast** — `json` yields decoded arrays or scalars (`'42'` → `42`). If you need a typed VO — write your own.
- **Callable method isn't `static`** → `[$class, 'method']` is not callable, so the rule is silently ignored and the value stays raw. Use static methods.
- **`TypecastException: Unable to typecast the ... field: ... Argument #2 ... must be of type int, Cycle\Database\Database given`** → a two-element callable with a second parameter always receives the DB there. Pass extra arguments through the three-element form.
