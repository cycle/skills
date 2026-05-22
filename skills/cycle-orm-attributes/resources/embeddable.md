# Embeddable: VO as parent columns

`#[Embeddable]` is **not** a separate entity with a table, but a **set of columns** embedded into the owning entity's table. Ideal for reusable value-objects: `Address`, `Money`, `Coordinates`, `Period`.

```php
use Cycle\Annotated\Annotation\Column;
use Cycle\Annotated\Annotation\Embeddable;
use Cycle\Annotated\Annotation\Entity;
use Cycle\Annotated\Annotation\Relation\Embedded;

#[Embeddable(columnPrefix: 'address_')]
class Address
{
    #[Column(type: 'string')]
    public string $street;
    #[Column(type: 'string')]
    public string $city;
    #[Column(type: 'string', nullable: true)]
    public ?string $zip = null;
}

#[Entity]
class Customer
{
    #[Column(type: 'primary')]
    public int $id;
    #[Column(type: 'string')]
    public string $name;

    #[Embedded(target: Address::class)]
    public Address $address;
}
```

**In the DB:** one table `customers` with all columns — `id`, `name`, `address_street`, `address_city`, `address_zip`. No separate table for Address.

See also:
- the owning entity itself → `define-entity.md`
- columns that will be embedded → `column-types.md`
- `#[Embedded]` as a relation → `relations.md`
- alternative via JSON-VO → `cycle-orm/resources/typecasters-advanced.md`

---

## `#[Embeddable]` — the VO description itself

```php
#[Embeddable(
    role: 'address',                 // as with Entity; default = lowercase class name
    mapper: AddressMapper::class,    // usually not needed
    columnPrefix: 'addr_',           // prefix for columns when embedded; default ''
    columns: [/* class-level style */],
    typecast: [...],                 // typecast handlers
)]
class Address { /* ... */ }
```

**`columnPrefix`** — the key parameter. If a single entity has two different Addresses (`billing_address`, `shipping_address`) — without prefixes the columns collide. With prefixes all is clean:

```php
#[Embeddable(columnPrefix: 'billing_')]
class BillingAddress { #[Column(type: 'string')] public string $city; }

#[Embeddable(columnPrefix: 'shipping_')]
class ShippingAddress { #[Column(type: 'string')] public string $city; }

#[Entity]
class Order
{
    #[Embedded(target: BillingAddress::class)]
    public BillingAddress $billing;
    #[Embedded(target: ShippingAddress::class)]
    public ShippingAddress $shipping;
}
```

— you get `billing_city`, `shipping_city` in `orders`. Without prefixes there would be two `city`s → schema error.

**Alternative:** the prefix can be set **on the `#[Embedded]` side**:

```php
#[Embeddable]                            // no columnPrefix
class Address { /* ... */ }

#[Entity]
class Order
{
    #[Embedded(target: Address::class, prefix: 'billing_')]
    public Address $billing;

    #[Embedded(target: Address::class, prefix: 'shipping_')]
    public Address $shipping;
}
```

— the same `Address` twice, with different prefixes. This is more convenient when a VO is single and used in several entities with different names.

**Don't use both places at once.** Behavior is undefined.

---

## `#[Embedded]` — owner's relation

```php
#[Embedded(
    target: Address::class,
    load: 'eager',                  // ← note: default is 'eager', not 'lazy'!
    prefix: 'billing_',             // overrides columnPrefix from Embeddable
)]
public Address $address;
```

**`load: 'eager'` by default** — the only relation with this default. Eager here means "embedded columns are added to the parent `SELECT`" — the data arrives in the same row.

### `load: 'lazy'` — opt-in loading

`load: 'lazy'` on `#[Embedded]` **does not auto-load the data on property access**:

1. The parent `SELECT` doesn't include embedded columns:
   ```sql
   SELECT customer.id, customer.name FROM customers AS customer WHERE id = ?
   -- addr_city / addr_street absent
   ```
2. During entity hydration Cycle **assigns `null` to the embedded property** — there's no separate fetch mechanism for embedded data from the parent table, and without an explicit load there's nothing to assign. For a typed non-nullable property (`public Address $address`), assigning `null` immediately throws `TypeError: Cannot assign null to property ... of type Address`.

3. Load embedded explicitly — one of two ways:
   ```php
   // (a) on Select: ->load('embeddedName')
   $repo->select()->load('address')->wherePK($id)->fetchOne();

   // (b) on an already-fetched set: BulkLoader
   //     (details — cycle-orm/resources/fetching.md)
   $customers = $repo->findAll();
   (new \Cycle\ORM\Relation\BulkLoader($orm))
       ->collect(...$customers)
       ->load('address')
       ->run();
   ```
   Either path mounts the embedded columns into the query. A plain `$repo->findByPK($id)` without `->load(...)` will throw `TypeError` on first property access.

**When to pick `'lazy'`:** when the embedded is genuinely not needed in most queries and you want to control its loading explicitly. Otherwise keep the `'eager'` default — the columns are in the same row anyway, no overhead.

---

## When Embeddable vs JSON-typecast VO

This is a common dilemma. Two scenarios:

| Aspect                          | Embeddable                          | JSON-VO + typecast (`cycle-orm/resources/typecasters-advanced.md`) |
|---------------------------------|-------------------------------------|--------------------------------------------|
| Where it's stored               | separate columns of the parent table | a single `jsonb`/`json` column            |
| Indexing by VO field            | standard column index               | jsonb index by path (PG only)              |
| WHERE on VO field               | `WHERE address_city = 'Berlin'`     | `WHERE address->>'city' = 'Berlin'` (PG)   |
| Migration: adding a VO field    | parent table migration              | nothing, the jsonb format is flexible      |
| Immutability (`final readonly`) | Embeddable fields are written by the hydrator — `readonly` is forbidden | the VO is hydrated by typecast — `final readonly` is allowed |
| Complexity                      | simpler for simple cases            | more powerful for deeply nested VOs        |

**Pick Embeddable if:** VO fields are often filtered/indexed as ordinary columns; NOT NULL constraints are needed; maximum SQL query speed matters.

**Pick JSON-VO if:** you work in DDD with `final readonly` VOs; the format may change without migrations; nested structure (VO inside VO inside VO); you use PG `jsonb` for path indexes.

---

## Embeddable limitations

- **Embeddable can't have its own PK.** It's not an entity with independent identity. PK comes from the owner.
- **Embeddable can't have relations.** No `#[BelongsTo]`/`#[HasOne]` inside. If needed — it's already an Entity, not an Embeddable.
- **Embeddable properties are hydrated as usual** — `final readonly` is forbidden for the same reasons as for Entity (`define-entity.md`). Use `protected` + getters if external immutability is needed.
- **A single Embeddable can be used several times in one entity**, but only if prefixes differ — set either on the VO side via `#[Embeddable(columnPrefix: ...)]` or on the owner side via `#[Embedded(prefix: ...)]`.
- **The Embeddable constructor** is not called on load (the same as for Entity).

---

## Class-level style

Like `#[Entity]`, `#[Embeddable]` has a `columns:` parameter — columns can be described at the class level instead of `#[Column]` above properties. See `define-entity.md`.

```php
use Cycle\Annotated\Annotation as Cycle;

#[Cycle\Embeddable(
    columnPrefix: 'address_',
    columns: [
        new Cycle\Column(type: 'string', property: 'street'),
        new Cycle\Column(type: 'string', property: 'city'),
        new Cycle\Column(type: 'string', property: 'zip', nullable: true),
    ],
)]
class Address
{
    public string $street;
    public string $city;
    public ?string $zip = null;
}
```

Useful in DDD style when the VO shouldn't have ORM attributes on its properties.

---

## Common pitfalls

- **Two Embedded of the same type without `prefix:`** — columns collide, the schema crashes. Use `columnPrefix` in Embeddable or `prefix:` in Embedded.
- **`final readonly class` on an Embeddable** — forbidden for the same reasons as for Entity (hydration via reflection). If you want an immutable VO — go with a JSON-typecast VO (`cycle-orm/resources/typecasters-advanced.md`).
- **Embeddable with a PK** — no, it's not an entity, it never has its own PK.
- **`load: 'lazy'` on `#[Embedded]` without an explicit `->load('name')`** — `TypeError: Cannot assign null to property of type X` on first property access. Embedded columns drop out of the root SELECT and there's no auto-load on access. Loading is explicit-only: `->load('name')` on Select, or `BulkLoader` on an already-fetched set.
- **Changing the Embeddable schema** — migrate the tables of **all** entities it's embedded into. Embeddable has no table of its own to migrate.
- **Embeddable doesn't appear in `Registry::getEntities()`** — it's on a separate embeddings list. If you search for it as a regular entity — you won't find it.
- **Hydrating an Embedded when all fields are null** — Cycle hydrates an object with null fields. If all Embeddable fields are nullable and often all null — the embedded object is still created. If you want the embedded to become `null` when all fields are null — that's manual work in the mapper, or a JSON-VO with a fully nullable column.

## Checklist

1. The class is marked `#[Embeddable]` (not `#[Entity]`).
2. The class is **not `final`** and properties are **not `readonly`** — the same constraints as for Entity.
3. If a single entity has several embeds of the same VO — each has its own `prefix:`.
4. The Embeddable has **no** PK, relations, or inheritance from other entities.
5. The `load: 'eager'` default is preserved. If `'lazy'` is chosen — load embedded explicitly (`->load('name')` on Select or `BulkLoader`); otherwise property access throws `TypeError`.
6. The Embeddable vs JSON-VO decision is made deliberately (see the table above).
7. The migration covers all tables the VO is embedded into.
