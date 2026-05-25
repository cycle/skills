# Relations between entities

Cycle supports **five** basic relation types, plus **three polymorphic** variants and `Embedded` for inline VOs (`embeddable.md`):

| Relation           | Who holds the FK             | Cardinality              | When to use                                             |
|--------------------|------------------------------|--------------------------|---------------------------------------------------------|
| `BelongsTo`        | **this** entity              | one → one (parent)       | "I have an owner/parent"                                |
| `HasOne`           | **the other** entity         | one → one                | "I have one child, the FK is on it"                     |
| `HasMany`          | **the other** entity         | one → many               | "I have many children, the FK is on them"               |
| `RefersTo`         | **this** entity              | one → one (weak)         | like BelongsTo, but no cascade — soft reference         |
| `ManyToMany`       | **pivot table**              | many → many              | "many-to-many" via `through`                            |
| `BelongsToMorphed` | **this** + morph column      | one → one (polymorphic)  | "one owner from a set of unrelated types"               |
| `MorphedHasOne`    | **the other** + morph column | one → one (polymorphic)  | rare; a regular HasOne with a where is usually simpler  |
| `MorphedHasMany`   | **the other** + morph column | one → many (polymorphic) | rare; a regular HasMany with a where is usually simpler |

See also:
- the entity itself and its properties → `define-entity.md`
- describing the FK column → `column-types.md`
- relations in STI/JTI → `inheritance.md`
- `Embedded` for inline VOs → `embeddable.md`

---

## Choosing a relation

You're describing side **A** (the side carrying the attribute). Side **B** is the target.

### Step 1. Cardinality

- **many-to-many** (article ↔ tag, user ↔ role) → jump to step 4 (ManyToMany).
- **one-to-one / one-to-many** (order → customer, customer → orders) → continue to step 2.
- **target is heterogeneous** (Comment → Article | Photo | Video, picked at runtime) → use a polymorphic variant (`BelongsToMorphed` / `MorphedHasOne` / `MorphedHasMany`; see the "Polymorphic (morphed) relations" section below).
- **inline VO** (Address inside User, no separate table) → `#[Embedded]`, see `embeddable.md`.

### Step 2. Which table holds the FK column

The key question: in **whose table** does the `*_id` FK column physically live?

- **FK lives in table A** (`orders.customer_id` — A=Order, B=Customer) → continue to step 3 (BelongsTo vs RefersTo).
- **FK lives in table B** (`orders.customer_id` — A=Customer, B=Order) → use `HasOne` (one B row per A) or `HasMany` (many).

The paired side gets the mirror relation, but **it's optional**: if the code never navigates from B to A, you can skip the attribute on B.

| A declares               | B declares (if needed)    |
|--------------------------|---------------------------|
| `BelongsTo` / `RefersTo` | `HasOne` or `HasMany`     |
| `HasOne`                 | `BelongsTo` or `RefersTo` |
| `HasMany`                | `BelongsTo` or `RefersTo` |
| `ManyToMany`             | `ManyToMany`              |

### Step 3. BelongsTo vs RefersTo (when the FK is on side A)

Decide by **two questions**:

1. **B's lifecycle**: does A manage saving B, or does B live independently?
2. **Dependency cycle**: is there a schema-level cycle `A → B → A`?

| Condition                                                                                          | Use                                             |
|----------------------------------------------------------------------------------------------------|-------------------------------------------------|
| A owns/creates B (standard parent–child)                                                           | `BelongsTo` (cascade-save by default)           |
| B is external (auth module, vendor entity, read-only from A's perspective)                         | `RefersTo` (`cascade: false`)                   |
| There's a cycle `A.foo → B` + `B.bar → A` (`BelongsTo` on both sides creates an insert-time cycle) | Make **one** side `RefersTo` to break the cycle |
| You need a soft reference: an FK without cascade management                                        | `RefersTo`                                      |

Canonical cycle example: `Order.lastInvoice` + `Invoice.order`. `BelongsTo` on both sides → Cycle can't determine the insert order. Fix: turn one of the two into `RefersTo`.

### Step 4. ManyToMany — via `through`

`ManyToMany` always requires a pivot entity (`through:`) — a separate class with its own PK and two FKs. Details in the "ManyToMany — `through`" section below.

### The one-line rule

**"Whoever owns the FK column declares `BelongsTo` or `RefersTo`. The opposite side declares `HasOne` / `HasMany`."** Everything else is cascade and cycle nuance.

---

## Relationship structure: the paired pattern

A real relation is a **pair** of attributes from two sides:

```php
// Side A
#[Entity]
class Order
{
    #[Column(type: 'primary')]
    public int $id;

    #[Column(type: 'int', nullable: true)]
    public ?int $customer_id = null;

    #[BelongsTo(target: Customer::class, innerKey: 'customer_id', nullable: true)]
    public ?Customer $customer = null;
}

// Side B
#[Entity]
class Customer
{
    #[Column(type: 'primary')]
    public int $id;

    /**
     * @var list<Order>
     */
    #[HasMany(target: Order::class, outerKey: 'customer_id')]
    public array $orders = [];     // or a typed collection — see below
}
```

`Order::$customer_id` is the field that holds the FK (it maps to a same-named column in the `orders` table unless overridden via `#[Column(name: ...)]`). `Customer::$id` is the primary field that the FK references. The `#[BelongsTo]` attribute is on the Order side; the paired `#[HasMany]` is on the Customer side. The `innerKey`/`outerKey` parameters take **field (property) names**, not column names — see the "Keys: innerKey, outerKey" section.

**The mirror side is not required.** If you don't need to navigate to Orders from Customer — `#[HasMany]` can be omitted. Cycle doesn't break because of it.

---

## Keys: innerKey, outerKey

**`innerKey` and `outerKey` are entity field (property) names, not DB column names.** The column name is stored separately on the field — taken from `#[Column(name: ...)]` or (if not overridden) defaulting to the property name.

- **`innerKey`** — field name in **the same entity** where the attribute is declared (the "near side").
- **`outerKey`** — field name in the **target entity** (the "far side").

The distinction is invisible when the property is snake_case and `#[Column]` doesn't override the column name. But the moment the property is camelCase (`$customerId`) or the column is renamed via `#[Column(name: 'fk_customer')]`, it matters.

```php
// Property and column happen to have the same name — innerKey = 'customer_id' works by coincidence
#[Column(type: 'int', nullable: true)]
public ?int $customer_id = null;

#[BelongsTo(target: Customer::class, innerKey: 'customer_id')]
public ?Customer $customer = null;
```

```php
// Property = camelCase, column = snake_case → innerKey is ALWAYS the property name
#[Column(type: 'int', name: 'customer_id', nullable: true)]
public ?int $customerId = null;

#[BelongsTo(target: Customer::class, innerKey: 'customerId')]   // ← property name
public ?Customer $customer = null;
```

### Key defaults

| Relation     | Default `innerKey`                   | Default `outerKey`                   |
|--------------|--------------------------------------|--------------------------------------|
| `BelongsTo`  | `{relationName}_{outerKey}`          | primary field name of target entity  |
| `RefersTo`   | `{relationName}_{outerKey}`          | primary field name of target entity  |
| `HasOne`     | primary field name of own entity     | `{parentRole}_{innerKey}`            |
| `HasMany`    | primary field name of own entity     | `{parentRole}_{innerKey}`            |
| `ManyToMany` | primary field name of own entity     | primary field name of target entity  |

All values are **field names**. Cycle will fill these in, but **prefer to spell them explicitly**.

---

## Foreign keys: `fkCreate`, `fkAction`, `fkOnDelete`, `indexCreate`

By default Cycle, when building the schema:
- **creates an FK** on innerKey (for BelongsTo/RefersTo) or outerKey (for HasOne/HasMany) — `fkCreate: true`.
- **creates an index** on the FK column — `indexCreate: true`.
- `ON DELETE CASCADE ON UPDATE CASCADE` — `fkAction: 'CASCADE'`.

### Cycle relies on the FK for delete cascading

On `$em->delete($parent)->run()` Cycle **does not delete children itself** — it doesn't walk relations to enqueue child DELETEs. Cascading the delete to children is left to the DB-level FK with `ON DELETE CASCADE`.

Consequences:
- Deleting children in the same transaction works only with `fkCreate: true` + `fkAction: 'CASCADE'` (or `fkOnDelete: 'CASCADE'`).
- With `fkCreate: false`, or `fkOnDelete: 'NO ACTION'`/`'SET NULL'`, children survive after the parent is gone (orphaned, or with `NULL` FK). Clean them up manually — either an explicit `$em->delete($child)` for each, or a `DELETE FROM children WHERE parent_id = ...` via DBAL.
- Dis-association (`$parent->children = []`, an item removed from the collection) is a **different** case: here Cycle does enqueue the child row for DELETE (`HasMany::prepare()` → `deleteChild()`, `vendor/cycle/orm/src/Relation/HasMany.php:80-84`). That's a reaction to a collection change during `persist()`, not a cascade from parent deletion.

### `fkCreate: false`

Don't create an FK at the DB level. The relation still works (Cycle knows about it), but there's no FOREIGN KEY constraint in the schema.

**When useful:**
- **The paired side already creates the same FK.** When a relation is declared from both sides (e.g. `BelongsTo` on Order + `HasMany` on Customer), both renderers target the FK on the same column `orders.customer_id`. DBAL deduplicates FKs by columns (`vendor/cycle/database/src/Schema/AbstractTable.php:391`), but `fkAction`/`fkOnDelete` from the side rendered last overwrite values from the first — the configuration becomes traversal-order-dependent. To avoid surprises, set `fkCreate: false` on **one** side (typically on `HasOne`/`HasMany` — the FK logically belongs to the side holding the FK column, i.e. `BelongsTo`/`RefersTo`).
- Cross-DB relations (an FK between different databases is impossible).
- SQL Server: an FK on an identity column with CASCADE is forbidden; on a non-PK/unique column is forbidden. If you hit a restriction and can't restructure — `fkCreate: false`.
- Performance/operational reasons (bulk loads, migrations under load).
- Tests with fixtures where the FK interferes with insert order.

### `fkAction` vs `fkOnDelete`

`fkAction` — action **for both** `ON DELETE` and `ON UPDATE`. `fkOnDelete` overrides **only** `ON DELETE` and has priority.

```php
#[BelongsTo(target: Customer::class,
    fkAction: 'CASCADE',           // ON UPDATE CASCADE
    fkOnDelete: 'SET NULL',         // ON DELETE SET NULL
    nullable: true,                 // required for SET NULL
)]
public ?Customer $customer = null;
```

Allowed values: `'CASCADE'`, `'NO ACTION'`, `'SET NULL'`.

### `indexCreate: false`

Don't create an index on the FK column. By default Cycle creates one — a reasonable default for JOIN performance. Disabling makes sense when there's a composite index with this column first, and the single index duplicates part of the pyramid.

---

## `cascade`

By default `cascade: true` for all relations (except `RefersTo`, which is also `true`, but usually explicitly set to `false` — see below).

`cascade: true` — on `persist($parent)` Cycle saves related entities too (if they're new or changed).

```php
$order = new Order();
$order->customer = new Customer();   // new, not saved
$em->persist($order)->run();          // saves BOTH order AND customer (BelongsTo cascade)
```

`cascade: false` — related entities need to be saved by hand with a separate `persist()`.

**When `cascade: false`:**
- Round-trip referential cycles (see below).
- The related side's entity is read-only from your point of view (e.g., `User` belongs to the auth module, your `Order` just references it).
- You want explicit control over order and transaction boundary.

---

## `nullable`

`nullable: true` — the relation may be absent (FK column NULL).

**Consistency with the column:** If `nullable: true` on a relation — the FK column must also be `nullable`:

```php
#[Column(type: 'int', nullable: true)]
public ?int $customer_id = null;

#[BelongsTo(target: Customer::class, innerKey: 'customer_id', nullable: true)]
public ?Customer $customer = null;
```

Both the PHP type `?Customer` and `?int $customer_id`. If out of sync — hydration may crash on assigning `null` to a non-nullable property.

---

## `load`: lazy vs eager

`load` sets the **default loading strategy** for the relation (overridable per query via `with()`/`load()` on the Select).

- **`load: 'lazy'`** (default) — the relation isn't loaded together with the main entity. On access to the property Cycle hits the DB with a separate query (via the proxy).
- **`load: 'eager'`** — the relation is **always** loaded with a JOIN together with the main entity.

```php
#[BelongsTo(target: Customer::class, load: 'eager')]
public Customer $customer;
```

**When eager:**
- The relation is **always** used when working with the entity (e.g., `Order.customer` is needed almost everywhere).
- Cardinality is `*-to-one` (for `HasMany`, eager is an N+1 risk going the other way).

**The "lazy" default is almost always right.** Eager is permanently baked into the schema, and it's easy to forget you have JOINs everywhere.

`#[Embedded]` is the only relation whose default is `'eager'`. With `'lazy'` you must explicitly load via `->load('name')` or `BulkLoader` — without that, property access throws `TypeError`; details in `embeddable.md`.

### Post-load for an already-fetched set — `BulkLoader`

`Cycle\ORM\Relation\BulkLoader` ($orm $entities → `->load('relation')` → `->run()`) lets you pull relations for a **set of already-fetched** entities in a single batch query — without re-querying the entities themselves. An alternative to `load: 'eager'` when relations are needed in some scenarios but not others (and you don't want to change the schema), and an alternative to lazy access (which would issue N queries in a loop).

```php
$users = $orm->getRepository(User::class)->findAll();   // separate query

(new \Cycle\ORM\Relation\BulkLoader($orm))
    ->collect(...$users)
    ->load('orders')
    ->load('profile.avatar')   // nested relations via dot-notation
    ->run();                    // one query for orders + one for avatar across the whole set
```

Details (LoadOptions, constraints — all entities must share the same role, full PKs must be in the Heap, STI/JTI not yet supported), real-world patterns, and the comparison with `Select::load()` — in `cycle-orm/resources/fetching.md`.

---

## `Inverse` — declaring the mirror side from the main one

Class: `Cycle\Annotated\Annotation\Relation\Inverse`. The `inverse:` parameter is accepted by `BelongsTo`, `HasOne`, `HasMany`, `RefersTo`, `ManyToMany`, `BelongsToMorphed`, `MorphedHasOne`, and `MorphedHasMany`. Instead of declaring the mirror `#[HasMany]`/`#[HasOne]` (or another reverse) on the target entity, you can describe the reverse side **through the main one**:

```php
#[BelongsTo(
    target: Customer::class,
    innerKey: 'customer_id',
    inverse: new Inverse(as: 'orders', type: 'hasMany', load: 'lazy'),
)]
public Customer $customer;
```

When the schema is built Cycle registers a `hasMany` relation in the Customer entity named `'orders'`. Useful when you want both sides of a bidirectional relation described in one place, or when the target entity is outside your control (a vendor package, generated code).

### Required arguments

- **`as:`** — the relation name on the target side (`string`).
- **`type:`** — the mirror relation type (`string`).
- **`load:`** — optional; `'lazy'`, `'eager'`, or `'promise'` (default `null`, falls back to the type's default).

### Allowed `type:` values per source

The schema builder validates the pair in `<Relation>::inverseRelation()` and throws a `RelationException` on a mismatch:

| `inverse:` source  | Allowed `type:` values                |
|--------------------|---------------------------------------|
| `BelongsTo`        | `'hasOne'`, `'hasMany'`               |
| `HasOne`           | `'belongsTo'`, `'refersTo'`           |
| `HasMany`          | `'belongsTo'`, `'refersTo'`           |
| `ManyToMany`       | `'manyToMany'`                        |
| `BelongsToMorphed` | `'morphedHasOne'`, `'morphedHasMany'` |

### Where `inverse:` is accepted but doesn't work

`RefersTo`, `MorphedHasOne`, and `MorphedHasMany` accept the parameter in the annotation constructor, but the corresponding schema classes **don't implement `InversableInterface`**. The schema build fails with `SchemaException('Unable to inverse relation of type …')`. `Embedded` doesn't accept `inverse:` at all (`getInverse()` always returns `null`).

### `inverse:` pitfalls

- **`ManyToMany` with `where:` or `throughWhere:` can't be inversed** — `RelationException('Unable to inverse ManyToMany relation with where scope.')`.
- **If the target entity already has a relation named `as:`** — it is **silently overwritten** by the generated inverse (`registerRelation` just writes into the map by key, no collision check). Convenient when you want to replace someone else's declaration, easy to shoot yourself in the foot otherwise.

---

## `collection` (HasMany, ManyToMany, MorphedHasMany)

`HasMany`, `ManyToMany`, and `MorphedHasMany` accept `collection: ?string`. The parameter selects which collection class Cycle wraps the elements into at hydration. The default is plain PHP `array` (`ArrayCollectionFactory`).

Accepted forms:

```php
// 1. Alias (registered via Factory::withCollectionFactory)
#[HasMany(target: Post::class, collection: 'doctrine')]
public Collection $posts;

// 2. Interface FQCN — factory matched via getInterface()
#[HasMany(target: Post::class, collection: \Doctrine\Common\Collections\Collection::class)]
public Collection $posts;

// 3. Concrete collection class FQCN — withCollectionClass()
#[HasMany(target: Post::class, collection: MyCustomCollection::class)]
public MyCustomCollection $posts;

// 4. null / omitted — default factory (ArrayCollectionFactory out of the box)
#[HasMany(target: Post::class)]
public array $posts = [];
```

Collection-factory configuration (built-in `ArrayCollectionFactory`/`DoctrineCollectionFactory`/`IlluminateCollectionFactory`/`LoophpCollectionFactory`, registration via `Factory::withCollectionFactory()`, constructor initialization, M2M pivot access through `PivotedCollectionInterface`, `array` limitations under the proxy mapper, and setting `COLLECTION_TYPE` directly in a manually built schema) lives in the [[cycle-orm]] skill, `cycle-orm/resources/collections.md`.

---

## `where` and `orderBy` (HasMany, ManyToMany, MorphedHasMany)

You can constrain the relation with a condition on the target side:

```php
// On the target Order entity:
//   #[Column(type: 'int', name: 'customer_id')] public int $customerId;
//   #[Column(type: 'string')]                   public string $status;
//   #[Column(type: 'datetime', name: 'create_time')] public \DateTimeImmutable $createTime;

#[HasMany(
    target: Order::class,
    outerKey: 'customerId',                 // target-side property name (NOT a column name)
    where: ['status' => 'paid'],            // keys are target-entity property names
    orderBy: ['createTime' => 'DESC'],      // same convention
)]
public array $paidOrders;
```

On load Cycle appends `WHERE orders.status = 'paid' ORDER BY orders.create_time DESC` — `customerId`/`createTime` are translated into the `customer_id`/`create_time` columns through the `#[Column(name: ...)]` map. The condition is **permanent**, always applied to this relation.

Keys are **target-entity property names** (same rule as `innerKey`/`outerKey` — see the "Keys" section above). Dot-notation `relationName.field` is supported for conditions across JOINs — e.g. `where: ['customer.status' => 'active']`.

---

## ManyToMany — `through`

```php
#[Entity] class Tag
{
    #[Column(type: 'primary', name: 'tag_id')]
    public int $tagId;                          // property = tagId, column = tag_id
    #[Column(type: 'string', name: 'tag_name')]
    public string $name;
}

#[Entity] class Article
{
    #[Column(type: 'primary', name: 'article_id')]
    public int $articleId;                      // property = articleId, column = article_id

    #[ManyToMany(
        target: Tag::class,
        through: ArticleTag::class,           // pivot entity
        innerKey: 'articleId',                // property in Article (source)
        outerKey: 'tagId',                    // property in Tag (target)
        throughInnerKey: 'pivotArticleId',    // property in ArticleTag → source
        throughOuterKey: 'pivotTagId',        // property in ArticleTag → target
    )]
    public array $tags = [];
}

#[Entity(table: 'article_tags')]
class ArticleTag                              // pivot — a regular entity with two FKs
{
    #[Column(type: 'primary', name: 'id')]
    public int $id;
    #[Column(type: 'int', name: 'article_id')]
    public int $pivotArticleId;               // property = pivotArticleId, column = article_id
    #[Column(type: 'int', name: 'tag_id')]
    public int $pivotTagId;                   // property = pivotTagId,    column = tag_id

    // additional pivot fields can go here
    #[Column(type: 'datetime', name: 'linked_at', default: 'CURRENT_TIMESTAMP')]
    public \DateTimeImmutable $linkedAt;      // property = linkedAt, column = linked_at
}
```

The attribute carries **property names only** (`articleId`, `tagId`, `pivotArticleId`, `pivotTagId`); the column names (`article_id`, `tag_id`) never appear inside it.

`through:` accepts a class-string of the pivot entity. The pivot must be a **full-fledged** entity with its own PK.

**Additional pivot fields** are accessible if the collection implements `PivotedCollectionInterface` (Doctrine `PivotedCollection` or `LoophpPivotedCollection`):
```php
/** @var \Cycle\ORM\Collection\Pivoted\PivotedCollection $tags */
$tags = $article->tags;
foreach ($tags as $tag) {
    $pivot = $tags->getPivot($tag);   // ArticleTag|null
}
```

With `collection: 'array'` or `'illuminate'`, pivot data is **lost** during hydration. API details and registration — `cycle-orm/resources/collections.md`.

---

## Relation name determines key defaults

Remember the default `innerKey: '{relationName}_{outerKey}'`? The relation name is the **property name**:

```php
#[BelongsTo(target: Customer::class)]   // innerKey default = 'customer_id' (property name + _id)
public Customer $customer;
```

If you rename `$customer` → `$buyer`:
```php
#[BelongsTo(target: Customer::class)]   // innerKey default = 'buyer_id'
public Customer $buyer;
```

— then the FK column is expected to be `buyer_id`. So when renaming a relation property, always **check the default names**.

---

## Polymorphic (morphed) relations

A polymorphic relation is one whose **target is not a fixed entity, but one of a set**. The specific target is determined by the value of the **morph column** (which stores the role of the related entity).

Classic example: `Comment` can belong to `Article`, `Photo`, `Video`. Instead of separate `article_id`/`photo_id`/`video_id` columns we store a pair `(commentable_id, commentable_role)`.

```php
#[Entity]
class Comment
{
    #[Column(type: 'primary')]
    public int $id;
    #[Column(type: 'text')]
    public string $body;

    #[Column(type: 'int', nullable: true)]
    public ?int $commentable_id = null;

    #[Column(type: 'string', length: 32, nullable: true)]
    public ?string $commentable_role = null;

    #[BelongsToMorphed(
        target: Commentable::class,
        innerKey: 'commentable_id',
        morphKey: 'commentable_role',
    )]
    public ?Commentable $commentable = null;
}

interface Commentable { /* marker */ }

#[Entity] class Article implements Commentable { /* ... */ }
#[Entity] class Photo   implements Commentable { /* ... */ }
#[Entity] class Video   implements Commentable { /* ... */ }
```

### Morph fields: innerKey + morphKey

A polymorphic relation has **two** key fields on the "near" side (as everywhere, the attribute parameters take entity field names, not DB column names):
- **`innerKey`** (default `{relationName}_{outerKey}`) — field that stores the id of the related entity.
- **`morphKey`** (default `{relationName}_role`) — field that stores the role of the related entity (string `'article'`, `'photo'`, etc.).

`morphKeyLength: 32` — the length of the VARCHAR column for the morph key, when Cycle creates the field itself. 32 bytes is usually enough for role names (`article`, `billing_invoice`). Bump to 64-128 if you have long roles.

**Cycle creates both fields itself** if they aren't declared explicitly — but it's almost always better to declare them by hand to control `length`, `nullable`, indexes.

### `target` — is not a specific entity

`target:` for a morphed relation is a **common type/marker**:
- an interface (`Commentable::class`),
- an abstract class,
- even just `target: 'mixed'` if there's no common type.

Cycle doesn't itself check that target is an interface with implementations. The relation works at runtime: on load Cycle reads morphKey, looks up the entity by role, hydrates. On write — takes the role from the object, puts it into morphKey.

**A strong convention** is to have a common interface/abstract class so PHPStan/IDE can check that the assigned entity fits.

### `BelongsToMorphed`

"I have one owner, and it can be of any type from a set."

```php
public function __construct(
    string $target,
    bool $cascade = true,
    bool $nullable = true,                  // ← note: default true (unlike BelongsTo)
    array|string|null $innerKey = null,
    array|string|null $outerKey = null,
    ?string $morphKey = null,
    int $morphKeyLength = 32,
    bool $indexCreate = true,
    string $load = 'lazy',
    ?Inverse $inverse = null,
)
```

**`nullable: true` by default** — a polymorphic FK is often optional. If the relation is required — set `nullable: false` explicitly.

**FK constraint is absent.** A polymorphic FK cannot be created at the DB level (an FK references one table, but here there are several). Cycle **does not create an FK** for a morphed relation — there's only the `innerKey` column + an index. These attributes don't have an `fkCreate` parameter.

`indexCreate: true` creates a composite index on `[morphKey, innerKey]` — critically important for performance when looking up "all Comments of this Article".

### `MorphedHasOne` / `MorphedHasMany`

The reverse sides: "I (Article) have many Comments, and they store me via a morph reference".

```php
#[Entity] class Article implements Commentable
{
    #[Column(type: 'primary')]
    public int $id;

    #[MorphedHasMany(
        target: Comment::class,
        outerKey: 'commentable_id',
        morphKey: 'commentable_role',
    )]
    public array $comments = [];
}
```

On load Cycle takes Article (`role = 'article'`, `id = 42`) and runs:
```sql
SELECT * FROM comments WHERE commentable_id = 42 AND commentable_role = 'article'
```

**In practice** `MorphedHasOne`/`MorphedHasMany` are rare. Far more often the only morph side is `BelongsToMorphed`, and the reverse sides are declared as regular `HasMany` per target with a `where` filter:

```php
// Alternative: a regular HasMany with a where filter on the morph role
#[Entity] class Article
{
    #[HasMany(
        target: Comment::class,
        outerKey: 'commentable_id',
        where: ['commentable_role' => 'article'],   // pin the role
        fkCreate: false,                             // FK is impossible
    )]
    public array $comments = [];
}
```

This variant **reads more clearly** and doesn't require learning `MorphedHasMany`.

### Morph vs inheritance vs JSON

"I have entities of types X, Y, Z, and one entity references any of them." Options:

| Option                | When to pick                                                                  |
|-----------------------|------------------------------------------------------------------------------|
| **Morphed relation**  | X/Y/Z have different tables and share the meaning "commentable" only in the relation context. They have no common columns. |
| **STI** (`inheritance.md`) | X/Y/Z are variants of one entity with common fields. All in one table with a discriminator. |
| **JTI** (`inheritance.md`) | X/Y/Z share part of the fields + have their own. Common part — in the parent table. |
| **JSON column**       | The relation isn't the primary concern. Store `{"type": "article", "id": 42}` in jsonb. Without relation attributes. |

A morphed relation **does not make X/Y/Z kin** — they are separate entities. STI/JTI does make them kin. Pick morphed when X/Y/Z are **different domain concepts** that happen to share an "attachable" function.

---

## Self-reference (self-referencing)

```php
#[Entity]
class Category
{
    #[Column(type: 'primary')]
    public int $id;
    #[Column(type: 'int', nullable: true)]
    public ?int $parent_id = null;

    #[BelongsTo(target: self::class, innerKey: 'parent_id', nullable: true)]
    public ?Category $parent = null;

    #[HasMany(target: self::class, outerKey: 'parent_id')]
    public array $children = [];
}
```

`target: self::class` or `target: 'category'` (by role) — both work.

---

## Common pitfalls

- **"Relation not found"** — usually an incorrect `target:` (no such role/class in the schema). Verify that the target entity is under `#[Entity]` and reaches the locator.
- **"Field `Entity`.`xxx` does not exists, referenced by …"** during schema build → `innerKey`/`outerKey`/`morphKey` references a name that isn't among the entity's fields. Most common cause: you wrote a **DB column name** instead of a **property name**. If property `$customerId` maps to column `customer_id`, `innerKey` must be `'customerId'`.
- **FK column is duplicated in STI/JTI**: BelongsTo on the child + the column inherited from the parent → conflict. See `inheritance.md`.
- **Cycle on insert (cyclic dependency)**: A → BelongsTo B, B → BelongsTo A. Cycle can't figure out the order. Break the cycle: on one of the sides use `RefersTo` (cascade: false), save B after A.
- **`nullable: true` on a relation, but the column is not nullable** → hydration may crash on assigning `null`. Keep them in sync.
- **CASCADE FK on an MSSQL identity column** → the schema won't compile. Solutions: `fkAction: 'NO ACTION'` or `fkCreate: false`. See `cycle-orm/resources/schema-troubleshooting.md`.
- **Paired `BelongsTo` + `HasMany` both with default `fkCreate: true`** → both try to create an FK on the same column. DBAL deduplicates, but `fkAction`/`fkOnDelete` from the side rendered last wins — behavior becomes traversal-order-dependent. Fix: `fkCreate: false` on one side (typically on `HasOne`/`HasMany`).
- **`$em->delete($parent)` didn't delete children — they're stuck in the DB (or the transaction fails with an FK violation)** → Cycle doesn't cascade DELETE itself; it relies on the FK's `ON DELETE CASCADE`. Check: `fkCreate: true` + `fkAction: 'CASCADE'` (or `fkOnDelete: 'CASCADE'`). With `fkCreate: false`, either delete children manually or use `fkOnDelete: 'SET NULL'` (+ `nullable: true` on the FK column).
- **`through` entity has no PK** → Cycle can't identify a pivot row. The pivot must be a full-fledged entity.
- **Eager load on HasMany with large cardinality** → every `select Customer` pulls all its `Order`s via JOIN, hits performance. Use `load: 'lazy'` (default) and pull in the relation in specific queries via `->load('orders')`.
- **Composite outerKey but scalar innerKey** (or vice versa) → the schema crashes. They must be symmetric.
- **`Inverse` without `type:`** → schema-build error. The type is always required.
- **Morph: the `morphKey` column doesn't fit in `morphKeyLength`** (long roles like `billing_invoice_line_item`). Bump `morphKeyLength` to 64-128, don't squeeze.
- **Morph: composite index on `[morphKey, innerKey]`** is critically important — `indexCreate: true` (default), don't disable without reason.
- **Morph + FK constraint** — impossible. Cycle doesn't create one; if you try by hand — you'll crash on the first insert.
- **WHERE on morph columns without both columns** — a query `WHERE commentable_id = 42` without `commentable_role` finds foreign comments (of other entities with the same id). Always query by both columns.

## Checklist

1. The correct relation type is chosen (BelongsTo/RefersTo for "I hold the FK", HasOne/HasMany for "the FK is on the other", ManyToMany for many-many, Morphed for "attachable to any").
2. `innerKey`/`outerKey` are either explicitly set, or the defaults match the actual column names.
3. The FK column exists as `#[Column]` in the corresponding entity with the right type and `nullable`.
4. `nullable:` on the relation is in sync with `nullable:` on the column and `?T` in the property type.
5. `cascade:` is decided deliberately: `true` for owned relations, `false` for weak references and cycles.
6. `fkCreate`/`fkAction`/`fkOnDelete` suit the driver (especially for MSSQL — see `cycle-orm/resources/schema-troubleshooting.md`).
7. `load:` — `'lazy'` by default; `'eager'` only when the relation is truly always needed.
8. For ManyToMany — `through:` points to a full-fledged entity with a PK.
9. If you don't control the mirror side — use `inverse:` to generate the reverse side.
10. For morphed relations: `morphKeyLength:` is enough for the longest role names; both columns (innerKey + morphKey) are declared; `target:` is a common interface; all "ends" implement it.
