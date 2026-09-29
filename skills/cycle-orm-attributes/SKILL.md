---
name: cycle-orm-attributes
description: Map PHP classes to database tables in Cycle ORM 2.x via cycle/annotated PHP 8 attributes — defining entities, columns and typecast configuration, relations (including polymorphic), STI/JTI inheritance, embeddables, table-level constraints, and entity behaviors from cycle/entity-behavior + cycle/entity-behavior-uuid (CreatedAt/UpdatedAt/SoftDelete/OptimisticLock, Hook, EventListener, Uuid1-7). Use when adding/mapping a class to a table, choosing primary-key or identifier strategy, modeling associations, embedding value-objects, configuring indexes or foreign keys, adding auto-timestamps / soft-delete / optimistic-lock / lifecycle hooks declaratively, or hitting class-level restrictions (final/readonly) on entities. For runtime work with already-mapped entities (querying, saving, custom Repository/Scope/Mapper, advanced typecasters, troubleshooting) — see the `cycle-orm` skill.
---

# Attribute-based entity mapping (cycle/annotated)

## Minimum working example

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

Defaults: role = camelCase short class name (`billingInvoice`), table = snake_case plural (`billing_invoices`), column = snake_case property name (`customer_id`), typecast = inferred from the column type (int/bool/float/datetime), database = default from DBAL, mapper/repository/scope — Cycle standards.

## Hard constraints on entity classes
These errors silently pass schema compilation and only blow up at runtime:

- **`final class` with the default mapper is forbidden.** `\Cycle\ORM\Mapper\Mapper` builds a proxy via `extends` → `RuntimeException` on the first load/`make()`. Lifted by switching the mapper to `PromiseMapper` — see `cycle-orm/references/mappers.md`.
- **`readonly` is forbidden.** `readonly class` → fatal on proxy generation; `readonly` properties are not supported by the default mapper. For external immutability use `private`/`protected` properties + getters.
- **The constructor is not invoked on load from DB.** No side effects in it (logs, events).
- **A class with `#[Column]` but without `#[Entity]` is silently skipped by the locator.**
- **At least one primary column is required** (`type: 'primary'`/`'bigPrimary'` or `primary: true`). Without it the entity is silently dropped from the schema.

External immutability — via `protected`/`private` + getters.

## Index — what goes where (references/)

Load files by task trigger. Each is self-contained, with its own minimum, decision tree, pitfalls, and checklist.

- `references/define-entity.md` — creating a new entity, choosing role/repository/mapper/scope, two declaration styles (property-level vs class-level), private constructor + Factory.
- `references/column-types.md` — describing a column: types, defaults, `length`/`precision`/`unsigned`, PG/MSSQL-specific, single and composite PK, identifier strategies (UUID/ULID/snowflake), `GeneratedValue`, **typecast** (int/bool/float/datetime/json + BackedEnum).
- `references/relations.md` — association between entities: HasOne/HasMany/BelongsTo/RefersTo/ManyToMany, `innerKey`/`outerKey`, FK behaviour, cascade, nullable, lazy/eager, `Inverse`, pivot `through`, the `collection:` parameter (alias/FQCN), **polymorphic** relations.
- `references/inheritance.md` — class hierarchy: STI (`#[SingleTable]`/`#[DiscriminatorColumn]`) vs JTI (`#[JoinedTable]`), traits, multi-level, standalone entity extending an STI child.
- `references/embeddable.md` — value-object as parent columns (`#[Embeddable]` + `#[Embedded]`), `columnPrefix`/`prefix:`, comparison with JSON-VO.
- `references/table-constraints.md` — indexes (`#[Index]`, composite, unique), composite PK via `#[PrimaryKey]`, manual FK without a relation (`#[ForeignKey]`).
- `references/behaviors.md` — `cycle/entity-behavior` and `cycle/entity-behavior-uuid`: declarative `#[CreatedAt]`/`#[UpdatedAt]`/`#[SoftDelete]`/`#[OptimisticLock]`, lifecycle hooks via `#[Hook]` (callable) and `#[EventListener]` + `#[Listen]` (class), `OnCreate`/`OnUpdate`/`OnDelete` events, UUID generators `#[Uuid1]`...`#[Uuid7]`. Requires `EventDrivenCommandGenerator` in bootstrap.

## Where to go beyond this skill

- Queries and saving (Select / EntityManager / `persist`/`run` / pagination / `forUpdate`) → skill `cycle-orm`, `references/repositories.md`.
- Choosing a mapper (default `Mapper` vs `PromiseMapper` vs `StdMapper` vs `ClasslessMapper`) → skill `cycle-orm`, `references/mappers.md`.
- Custom Repository / Scope / Mapper (writing your own) → skill `cycle-orm`, `references/orm-extensions.md`.
- Custom typecast handlers (`CastableInterface`/`UncastableInterface`/`CompositeTypecast`, JSON-VO pattern) → skill `cycle-orm`, `references/typecasters-advanced.md`.
- Schema-build and runtime-error diagnostics ("Undefined schema ... not found", MSSQL CASCADE, JTI duplication, `readonly class` proxy fatal, "unknown rule") → skill `cycle-orm`, `references/schema-troubleshooting.md`.
