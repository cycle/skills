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
- alternative via JSON-VO → `cycle-orm/references/typecasters-advanced.md`

---

## `#[Embeddable]` — the VO description itself

```php
#[Embeddable(
    role: 'address',                 // as with Entity; default = camelCase short class name (`BillingAddress` → `billingAddress`)
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

— you get `billing_city`, `shipping_city` in `orders`. Without prefixes both embeds map to one `city` column. Cycle neither rejects nor warns about this (the same happens with one embeddable used twice — open question upstream, cycle/schema-builder#54): the INSERT writes a single value — the one from the embed declared last — and both properties read it back. Sharing a column is safe only when both embeds always hold the same value; for independent values give each `#[Embedded]` its own `prefix:`.

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

**Precedence:** `#[Embedded(prefix: ...)]` overrides `#[Embeddable(columnPrefix: ...)]`; `prefix: ''` turns the prefix off for that embed.

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

### `load: 'lazy'` — load on first access

`load: 'lazy'` on `#[Embedded]` leaves the embedded columns out of the parent query and fetches them **on first property access**:

1. The parent `SELECT` doesn't include embedded columns:
   ```sql
   SELECT customer.id, customer.name FROM customers AS customer WHERE id = ?
   -- addr_city / addr_street absent
   ```
2. With the default proxy `Mapper` the embedded property holds a reference scoped by the owner's PK. The first read of `$customer->address` runs a separate `SELECT` of the embedded columns by that PK and hydrates the object — a typed non-nullable `public Address $address` works too.

3. To skip the per-entity query, load embedded up front — one of two ways:
   ```php
   // (a) on Select: ->load('embeddedName')
   $repo->select()->load('address')->wherePK($id)->fetchOne();

   // (b) on an already-fetched set: BulkLoader
   //     (details — cycle-orm/references/fetching.md)
   $customers = $repo->findAll();
   (new \Cycle\ORM\Relation\BulkLoader($orm))
       ->collect(...$customers)
       ->load('address')
       ->run();
   ```
   Either path mounts the embedded columns into the query.

**When to pick `'lazy'`:** when most queries don't need the embedded and an extra query on first access is acceptable. Otherwise keep the `'eager'` default — the columns are in the same row anyway, no overhead.

---

## When Embeddable vs JSON-typecast VO

| Aspect                          | Embeddable                          | JSON-VO + typecast (`cycle-orm/references/typecasters-advanced.md`) |
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

- **Embeddable can't have its own PK.** It's not an entity with independent identity. PK comes from the owner: Cycle copies the owner's PK fields into the embeddable.
- **Embeddable can't have relations.** A relation attribute inside fails with `AnnotationException`: "Relations are not allowed within embeddable entities in `App\Address`". If needed — it's already an Entity, not an Embeddable.
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

- **Embeddable field named like the owner's PK** (e.g. `id`) — compilation fails with `EmbeddedPrimaryKeyException`: "Entity `customer:address:address` has conflicted field `id`." Rename the field.
- **`load: 'lazy'` on `#[Embedded]` over a list** — every entity runs its own `SELECT` on first access (N+1). Load up front: `->load('name')` on Select, or `BulkLoader` on an already-fetched set.
- **Changing the Embeddable schema** — migrate the tables of **all** entities it's embedded into. Embeddable has no table of its own to migrate.
- **Hydrating an Embedded when all fields are null** — Cycle hydrates an object with null fields. If all Embeddable fields are nullable and often all null — the embedded object is still created. If you want the embedded to become `null` when all fields are null — that's manual work in the mapper, or a JSON-VO with a fully nullable column.

## Checklist

1. The class is marked `#[Embeddable]` (not `#[Entity]`).
2. If a single entity has several embeds of the same VO — each has its own `prefix:`.
3. The Embeddable has **no** PK, relations, or inheritance from other entities.
4. The `load: 'eager'` default is preserved. With `'lazy'`, lists load the embedded up front (`->load('name')` on Select or `BulkLoader`) to avoid a query per entity.
5. The Embeddable vs JSON-VO decision is made deliberately (see the table above).
6. The migration covers all tables the VO is embedded into.
