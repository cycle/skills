---
name: cycle-orm-attributes
description: Map PHP classes to database tables in Cycle ORM 2.x via cycle/annotated PHP 8 attributes — defining entities, columns and typecast configuration, relations (including polymorphic), STI/JTI inheritance, embeddables, table-level constraints, and entity behaviors from cycle/entity-behavior + cycle/entity-behavior-uuid (CreatedAt/UpdatedAt/SoftDelete/OptimisticLock, Hook, EventListener, Uuid1-7). Use when adding/mapping a class to a table, choosing primary-key or identifier strategy, modeling associations, embedding value-objects, configuring indexes or foreign keys, adding auto-timestamps / soft-delete / optimistic-lock / lifecycle hooks declaratively, or hitting class-level restrictions (final/readonly) on entities. For runtime work with already-mapped entities (querying, saving, custom Repository/Scope/Mapper, advanced typecasters, troubleshooting) — see the `cycle-orm` skill.
---

# Cycle ORM: attribute-based schema (cycle/annotated)

Schema description via **PHP 8 attributes** from `cycle/annotated`: entities, columns, relations, inheritance, value-objects, indexes/FKs. Day-to-day work with already-defined entities (Select, EntityManager, custom Repository/Scope/Mapper, custom typecast handlers, diagnostics) lives in a separate skill, `cycle-orm`.

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

Defaults: role = lowercase class name without namespace (`user`), table = pluralization (`users`), database = default from DBAL, mapper/repository/scope — Cycle standards.

## Hard constraints on entity classes (ALWAYS read)

These errors silently pass schema compilation and only blow up at runtime:

- **`final class` with the default mapper is forbidden.** `\Cycle\ORM\Mapper\Mapper` builds a proxy via `extends`. → `RuntimeException`.
- **`readonly` properties are forbidden.** The hydrator writes via reflection **after** the constructor. → `Error: Cannot modify readonly property`.
- **The constructor is not invoked on load from DB.** No side effects in it (logs, events).
- **A class with `#[Column]` but without `#[Entity]` is silently skipped by the locator.**
- **At least one primary column is required** (`type: 'primary'`/`'bigPrimary'` or `primary: true`).

External immutability — via `protected`/`private` + getters, not via `readonly`.

## Index — what goes where (resources/)

Load files by task trigger. Each is self-contained, with its own minimum, decision tree, pitfalls, and checklist.

- `resources/define-entity.md` — creating a new entity, choosing role/repository/mapper/scope, two declaration styles (property-level vs class-level), private constructor + Factory, composite PK.
- `resources/column-types.md` — describing a column: types, defaults, `length`/`precision`/`unsigned`, PG/MSSQL-specific, identifier strategies (UUID/ULID/snowflake), `GeneratedValue`, **typecast** (int/bool/float/datetime/json + BackedEnum).
- `resources/relations.md` — association between entities: HasOne/HasMany/BelongsTo/RefersTo/ManyToMany, `innerKey`/`outerKey`, FK behaviour, cascade, nullable, lazy/eager, `Inverse`, pivot `through`, the `collection:` parameter (alias/FQCN), **polymorphic** relations.
- `resources/inheritance.md` — class hierarchy: STI (`#[SingleTable]`/`#[DiscriminatorColumn]`) vs JTI (`#[JoinedTable]`), traits, multi-level, standalone entity extending an STI child.
- `resources/embeddable.md` — value-object as parent columns (`#[Embeddable]` + `#[Embedded]`), `columnPrefix`/`prefix:`, comparison with JSON-VO.
- `resources/table-constraints.md` — indexes (`#[Index]`, composite, unique), composite PK via `#[PrimaryKey]`, manual FK without a relation (`#[ForeignKey]`).
- `resources/behaviors.md` — `cycle/entity-behavior` and `cycle/entity-behavior-uuid`: declarative `#[CreatedAt]`/`#[UpdatedAt]`/`#[SoftDelete]`/`#[OptimisticLock]`, lifecycle hooks via `#[Hook]` (callable) and `#[EventListener]` + `#[Listen]` (class), `OnCreate`/`OnUpdate`/`OnDelete` events, UUID generators `#[Uuid1]`...`#[Uuid7]`. Requires `EventDrivenCommandGenerator` in bootstrap.

## Conventions inside resources/

- Cross-references **within** this skill — relative paths (`relations.md`).
- Cross-references **to another skill** — `<skill-name>/resources/<file>.md` (e.g. `cycle-orm/resources/repositories.md`).
- PHP attributes only. Doctrine docblock annotations are not covered.
- Common pitfalls collected in a single section at the end of each file.
- Checklist at the end — actionable, not a rehash of the body.
