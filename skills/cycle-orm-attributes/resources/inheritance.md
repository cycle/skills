# Entity inheritance: STI and JTI

Cycle supports **two classic approaches** to modeling class hierarchies in a single DB:

| Approach                          | Attribute on the child  | Where data lives                  | When to pick                                                                  |
|-----------------------------------|-------------------------|-----------------------------------|------------------------------------------------------------------------------|
| **STI** (Single Table Inheritance) | `#[SingleTable]`        | **one** table for the whole hierarchy, with a discriminator column | few variations between types, common fields dominate, maximum JOIN speed needed |
| **JTI** (Joined Table Inheritance) | `#[JoinedTable]`        | **separate table** for the parent + a separate one for each child, linked via FK | each type has many of its own fields, normalization is needed |

See also:
- the entity itself and its options → `define-entity.md`
- relations inside the hierarchy → `relations.md`
- parent and child columns → `column-types.md`
- indexes / FKs at the table level → `table-constraints.md`

---

## Single Table Inheritance (STI)

All entities of the hierarchy live **in a single parent table**. An extra column — the discriminator — stores the role of the specific child. At load, Cycle reads the discriminator and hydrates into the appropriate child class.

### Minimal example

```php
use Cycle\Annotated\Annotation\Column;
use Cycle\Annotated\Annotation\Entity;
use Cycle\Annotated\Annotation\Inheritance\DiscriminatorColumn;
use Cycle\Annotated\Annotation\Inheritance\SingleTable;

#[Entity]
#[DiscriminatorColumn(name: 'type')]              // ← required on the parent
class Animal
{
    #[Column(type: 'primary')]
    public int $id;
    #[Column(type: 'string')]
    public string $name;
    #[Column(type: 'string')]
    public string $type;    // ← discriminator column (just a string)
}

#[Entity]
#[SingleTable]                                    // ← without value: the child's role is used ('dog')
class Dog extends Animal
{
    #[Column(type: 'string', nullable: true)]
    public ?string $breed = null;
}

#[Entity]
#[SingleTable('feline')]                          // ← custom discriminator value
class Cat extends Animal
{
    #[Column(type: 'int', default: 9)]
    public int $lives = 9;
}
```

### What you get in the DB

**One table** `animals` with all columns: `id`, `name`, `type`, `breed`, `lives`. On `new Cat()` insert, Cycle sets `type = 'feline'` itself.

### Discriminator

`#[DiscriminatorColumn(name: 'type')]` — the only parameter, the column name. The column itself is declared as a regular `#[Column(type: 'string')]` on the parent. Cycle doesn't create it itself — **you** declare it, and it's then used as the discriminator.

### Discriminator value on the child

`#[SingleTable]` — without a parameter → value = the child's role (by default the lowercase class name, see `define-entity.md`).

`#[SingleTable('feline')]` — explicit value. Accepts `string|int|float|\Stringable|\BackedEnum`. From an enum, `value` is taken. Using a backed enum is convenient:

```php
enum AnimalType: string { case Dog = 'dog'; case Cat = 'feline'; }

#[SingleTable(AnimalType::Cat)]
class Cat extends Animal { /* ... */ }
```

### Which columns land in the shared table

**All** columns from all children are merged into the parent table schema. Columns declared in only one child **must be nullable** (or have a default), otherwise an insert of other children fails with a NOT NULL violation.

```php
#[Entity]
class Cat extends Animal
{
    #[Column(type: 'int', default: 9)]            // ✅ has a default — Dog gets 9 too
    public int $lives = 9;

    #[Column(type: 'string')]                      // ❌ NOT NULL — Dog crashes on insert
    public string $color;

    #[Column(type: 'string', nullable: true)]      // ✅ nullable
    public ?string $color2 = null;
}
```

### Where relations live in STI

In STI **relations are inherited** automatically. If `#[BelongsTo(target: Shelter::class)]` is declared on Animal, Cat and Dog get it too. This is natural: one table — one set of columns — one set of relations.

You can add **own** relations on a child. They also land in the shared table.

---

## Joined Table Inheritance (JTI)

Each entity has **its own table**. The parent table contains the common columns, the child tables only their own. Cycle does a JOIN on load.

### Minimal example

```php
#[Entity]
class User
{
    #[Column(type: 'primary')]
    public int $id;
    #[Column(type: 'string')]
    public string $email;
}

#[Entity]
#[JoinedTable]
class Employee extends User
{
    #[Column(type: 'string')]
    public string $department;
    #[Column(type: 'decimal')]
    public string $salary;
}

#[Entity]
#[JoinedTable]
class Manager extends Employee
{
    #[Column(type: 'int')]
    public int $direct_reports;
}
```

**In the DB:** three tables — `users` (id, email), `employees` (id, department, salary), `managers` (id, direct_reports). `employees.id` → FK to `users.id`. `managers.id` → FK to `employees.id`.

### `#[JoinedTable]` parameters

```php
#[JoinedTable(
    outerKey: 'email',           // name of the parent field the FK references; default = parent primary field
    fkCreate: true,              // whether to create the FK constraint; default true
    fkAction: 'CASCADE',         // ON DELETE / ON UPDATE; default 'CASCADE'
)]
class Employee extends User { /* ... */ }
```

**`outerKey`** — a **field name** on the parent, not a DB column name. Almost never needed; the default (the parent's primary field) is what you want. Useful only when linking by a non-standard unique field.

> **Terminology note.** Thanks to `outerKey`, Cycle covers not just classic JTI (the JPA `JOINED` flavor where `child.id = parent.id`, one shared identity) but also the more general **Class Table Inheritance** (Fowler): the link goes from the child's own PK to **any unique field** on the parent — email, UUID, business key. The child side is always tied to the child's PK, but the parent side is free via `outerKey`. JPA doesn't call this out as a separate strategy.

**`fkCreate: false`** — disables the FK constraint. Cycle still knows about the relationship, the schema just has no FOREIGN KEY. Useful for cross-DB hierarchies or drivers where FK with CASCADE doesn't work (MSSQL identity columns).

### Which columns live in the child table

The child table contains **only** its own columns + the PK (inherited from the parent, acting as an FK). Parent columns live **in the parent table**, are not duplicated.

Under the hood: `TableInheritance::removeJtiExtraFields()` after `merge` removes all inherited fields from the child entity **except the PK**. The PK is renamed from `primary`/`bigPrimary` to `integer`/`bigInteger` + `primary: true` (because in the child it's not auto-increment, but simply an FK to parent.id).

### Relations in JTI — important nuance

**JTI children DO NOT inherit relations from the parent** in the sense of duplicating FK columns in the child table:

- If `User` declares `#[BelongsTo(target: Org::class)]` — the `org_id` column lives in `users` (the parent's table), not in `employees`.
- To reach `$employee->org`, Cycle joins `users` → `orgs` via `users.org_id` on load.
- In the child entity `Employee` the parent relation `$org` is still **accessible** (PHP inheritance works), but its FK isn't duplicated in `employees`.

**Own relations** of the child entity — fine. They live in the child table:
```php
#[Entity]
#[JoinedTable]
class Employee extends User
{
    #[Column(type: 'int')]
    public int $manager_id;

    #[BelongsTo(target: Employee::class, innerKey: 'manager_id', nullable: true)]
    public ?Employee $manager = null;
}
```

— `manager_id` will be in `employees`, not in `users`.

### Trait + JTI

If the parent uses a trait with a relation that's physically declared in the trait:

```php
trait HasShelter
{
    #[Column(type: 'int', nullable: true)]
    public ?int $shelter_id = null;

    #[BelongsTo(target: Shelter::class, innerKey: 'shelter_id', nullable: true)]
    public ?Shelter $shelter = null;
}

#[Entity] class Animal { use HasShelter; /* ... */ }

#[Entity]
#[JoinedTable]
class Dog extends Animal { /* ... */ }
```

Behavior: the `shelter` relation **belongs to the class using the trait** (via `ReflectionProperty::getDeclaringClass()` this will be `Animal`, not the trait itself). Cycle checks `getDeclaringClass()` against the child class; for Dog declaringClass = Animal ≠ Dog → the relation is **not duplicated in Dog**.

---

## Disabling child-class loading at the Select level

For STI/JTI, Select has a **`loadSubclasses(bool)`** method — controls whether child-class fields are read in the main query:

```php
$repo->select()->loadSubclasses(false);   // parent fields only, no JOIN / discriminator branches
```

The default is `true` — Cycle pulls in the discriminator and fields of all children. `false` makes sense when you deliberately work with parent fields only (lists, aggregations) and want to save on the JOIN/SELECT. Hydration then yields an instance of the parent class — `instanceof` of a child returns false.

Details and pitfalls — `cycle-orm/resources/fetching.md` (`loadSubclasses()`).

---

## Multi-level inheritance (grandchildren)

JTI supports multi-level hierarchies — `User → Employee → Manager`. Each level has its own table, each FK references the **direct** parent:

```
managers.id  →  employees.id  →  users.id
```

A relation declared in `Employee` isn't duplicated in `Manager` — only Manager's own relations land in `managers`.

STI multi-level **also works**: you can have `Animal → Mammal → Cat`, all in one table with a discriminator. Under the hood: `removeStiExtraFields` for each children set removes fields of foreign branches.

Levels **can be mixed**: the same class can be an STI child at one level and a JTI parent at the next (e.g. `Animal → #[SingleTable] Mammal → #[JoinedTable] Bat`). Cycle walks the parent chain and applies the matching mechanism at each level. The price is non-linear growth in schema and migration complexity, so stick to a single approach unless you really need otherwise.

---

## Child without `#[SingleTable]`/`#[JoinedTable]` (Concrete Table Inheritance)

Conceptually close to **Concrete Table Inheritance** (Fowler) / **Table Per Class** (JPA, Doctrine `TABLE_PER_CLASS`) / **Table Per Concrete Class** (Hibernate): each concrete class has its own table containing all fields, inherited ones included. In Cycle this isn't a separate strategy — the behavior "falls out" of a class being marked `#[Entity]` but **not** having `#[SingleTable]`/`#[JoinedTable]`, while still extending another entity in PHP.

The scenario:

```php
#[Entity]
#[DiscriminatorColumn(name: 'type')]
class Animal { /* ... */ }

#[Entity]
#[SingleTable]
class Mammal extends Animal { /* ... */ }

// Beaver — a standalone entity, NOT an STI/JTI child (no #[SingleTable]/#[JoinedTable])
#[Entity]
class Beaver extends Mammal
{
    #[Column(type: 'int')]
    public int $teeth_count;
}
```

Beaver is a **standalone** entity with its own table `beavers`. It inherits PHP methods and public properties from Mammal, but in the schema it's not an STI/JTI child.

**What gets inherited:** EVERYTHING from Mammal/Animal, because Beaver is a plain extends. Columns and relations land in `beavers`.

**This is a legitimate pattern.** Relations are skipped only if the child has an explicit `#[SingleTable]`/`#[JoinedTable]` (checked via the presence of an `Inheritance` attribute). Beaver without one is a regular entity.

---

## STI vs JTI: how to choose

**Pick STI if:**
- Children differ by 2-5 optional fields.
- All types are queried often and often together (`SELECT * FROM animals`).
- Minimizing JOINs is important (analytics, realtime).
- Schema evolution is simple — adding a field = adding a nullable column.

**Pick JTI if:**
- Each child has dozens of specific fields.
- Queries often work with a specific type (`SELECT * FROM employees`).
- Normalization is important (no N nullable columns "for other types").
- You want FK constraints on specific fields without NULL issues.

**Mixing STI and JTI across levels** of the same hierarchy is allowed by Cycle (see "Multi-level inheritance"), but isn't worth it without a clear reason — schema and migration complexity grows fast.

**Don't create a hierarchy just "because OOP says so".** If a single class with an `enum` field `type` is enough for animals — skip inheritance entirely. It simplifies migrations.

---

## Common pitfalls

- **STI: a non-nullable column in one of the children** → INSERT of other children crashes. Make it `nullable: true` or set a `default:` for child-specific columns.
- **JTI: a child declares a column with the same name as the parent** → conflict. Don't override, or rename.
- **Discriminator not declared in the parent via `#[Column]`** — forgotten. `#[DiscriminatorColumn(name: 'type')]` specifies the name, but the column with that name has to be declared separately.
- **`#[SingleTable]` on a class whose parent isn't `#[Entity]`** → error. The parent must be a full-fledged entity.
- **Changing STI/JTI after release in prod** — data migration is non-trivial (STI → JTI requires redistributing columns across new tables). Think upfront.
- **Multi-level + cyclic FK** in JTI — Cycle does several SQLs on insert: parent first, then child. If there's a cycle through relations — it may crash. Decompose.
- **STI child has its own `#[DiscriminatorColumn]`** — no, the discriminator is always on the root parent.
- **JTI child with `table:` different from the parent** — fine, this is the **point** of JTI. Don't confuse with STI.
- **`outerKey:` in `#[JoinedTable]` references a non-existent parent column** → `WrongParentKeyColumnException`. Check the name.

## Checklist

1. For each hierarchy the approach is chosen deliberately: STI, JTI, or (rarely) a per-level mix. Without a clear reason, don't combine them.
2. **STI:**
   - The root parent has `#[DiscriminatorColumn(name: '...')]` + a matching `#[Column]`.
   - Each child has `#[SingleTable]` (optionally with `value:`).
   - Child-specific columns are `nullable` or have a `default`.
3. **JTI:**
   - Each child has `#[JoinedTable]`.
   - The FK constraint works on the target driver (for MSSQL see `cycle-orm/resources/schema-troubleshooting.md`).
4. Multi-level (if needed): each level correctly extends the previous one; `markAsChildOfSingleTableInheritance` / FK chains work.
5. Entities that extend an STI/JTI parent without their own `#[SingleTable]`/`#[JoinedTable]` (Concrete Table style) behave as regular entities — they are **not** a child in Cycle's inheritance sense.
6. Trait relations correctly "land" in the declaring class via `getDeclaringClass()` — verified by a test that they aren't duplicated in JTI children.
