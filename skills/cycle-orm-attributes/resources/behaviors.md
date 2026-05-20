# Entity behaviors: `#[CreatedAt]`, `#[Hook]`, `#[Uuid7]`, etc.

Attributes from the `cycle/entity-behavior` package (timestamps, soft-delete, optimistic lock, hooks, custom event listeners) and `cycle/entity-behavior-uuid` (UUID 1/2/3/4/5/6/7 generators). All are placed **on the entity class** and embedded into the schema as modifiers; at runtime, behaviors hook into Mapper commands via an event dispatcher.

See also:
- the entity itself (where the attribute goes) → `define-entity.md`
- columns created/used by behaviours (datetime, uuid, version) → `column-types.md`
- alternative: soft-delete manually via Mapper + Scope → `cycle-orm/resources/orm-extensions.md`

---

## Installation and bootstrap

```
composer require cycle/entity-behavior
composer require cycle/entity-behavior-uuid   # separately, only if a UUID generator is needed
```

**The attributes don't work on their own** — two integrations are needed:

1. **Schema-builder**: the attribute parser as a modifier. In `cycle/annotated` this is enabled by default — the `Embeddings/Entities` generator constructor picks up subclasses of `\Cycle\ORM\Entity\Behavior\Schema\BaseModifier`. If you have a custom pipeline through `\Cycle\Schema\Compiler::compile()` — make sure the `Generator`s `Entities`/`Embeddings` from `cycle/annotated` are part of the pass.
2. **Runtime**: `\Cycle\ORM\Entity\Behavior\EventDrivenCommandGenerator` must be passed into `\Cycle\ORM\ORM` instead of the standard `CommandGenerator`. Without it Mapper events aren't dispatched, and attributes are silently ignored.

```php
use Cycle\ORM\ORM;
use Cycle\ORM\Entity\Behavior\EventDrivenCommandGenerator;

$orm = new ORM(
    factory: $factory,
    schema: $schema,
    commandGenerator: new EventDrivenCommandGenerator($schema, $container),
);
```

**Pitfall:** if behaviors "don't fire", in 90% of cases you forgot to wire `EventDrivenCommandGenerator`. Attributes in code are only the declaration; without the runner they're dead.

---

## Built-in behaviours: timestamps, soft-delete, optimistic lock

> **About field naming in the examples below.** Package defaults are `field: 'createdAt'` / `'updatedAt'` / `'deletedAt'` (camelCase property, snake_case column via the `_at` suffix). The examples below **override** `field:`/`column:` to AIP-compatible `createTime`/`create_time` etc. (Google AIP: timestamp fields are `*Time`/`*_time`). Without `field:`/`column:` the package defaults kick in — if you want AIP, the override is required.

### `#[CreatedAt]` — creation date

```php
use Cycle\ORM\Entity\Behavior\CreatedAt;

#[Entity]
#[CreatedAt(
    field: 'createTime',         // package default: 'createdAt'
    column: 'create_time',       // package default: derived from field ('created_at')
)]
class User
{
    #[Column(type: 'primary')]
    public int $id;

    public \DateTimeImmutable $createTime;
}
```

- The property `$createTime` does **not** need to be marked `#[Column]` — the behaviour adds the column to the schema itself (datetime, NOT NULL, default `CURRENT_TIMESTAMP`, generated `BEFORE_INSERT`).
- If you want to be explicit — declare `#[Column(type: 'datetime')]` with the same name; the behaviour hooks in and doesn't duplicate.
- Property type — `\DateTimeImmutable`.

### `#[UpdatedAt]` — modification date

```php
use Cycle\ORM\Entity\Behavior\UpdatedAt;

#[Entity]
#[UpdatedAt(field: 'updateTime', column: 'update_time', nullable: false)]   // package defaults: 'updatedAt'/'updated_at'
class User
{
    // ...
    public \DateTimeImmutable $updateTime;
}
```

- The column is generated on `BEFORE_INSERT | BEFORE_UPDATE` — populated both on create and on update.
- `nullable: false` (default) — on insert the initial timestamp is set. `true` — on insert it's `null`, updated only on the first update.

### `#[SoftDelete]` — replace DELETE with UPDATE

```php
use Cycle\ORM\Entity\Behavior\SoftDelete;

#[Entity]
#[SoftDelete(field: 'deleteTime', column: 'delete_time')]   // package defaults: 'deletedAt'/'deleted_at'
class Article
{
    // ...
    public ?\DateTimeImmutable $deleteTime = null;
}
```

On `$em->delete($article)->run()`, instead of SQL `DELETE`, an `UPDATE ... SET delete_time = NOW()` runs.

**Important:** `SoftDelete` **does not fire Update events** for this "deletion" (by package design — see PHPdoc of `SoftDelete.php`). If you have `#[Hook(events: OnUpdate::class)]` attached — soft-delete doesn't trigger them. This is not a bug, but an intentional separation of "logical deletion ≠ regular update".

**Pitfall:** the behaviour **doesn't add an automatic WHERE filter** on reads. To hide soft-deleted records you need a separate Scope (`cycle-orm/resources/orm-extensions.md`):
```php
class NotDeletedScope implements ScopeInterface
{
    public function apply(QueryBuilder $query): void {
        $query->where('delete_time', null);
    }
}

#[Entity(scope: NotDeletedScope::class)]
#[SoftDelete(field: 'deleteTime', column: 'delete_time')]
class Article { /* ... */ }
```

### `#[OptimisticLock]` — versioning for concurrent edits

```php
use Cycle\ORM\Entity\Behavior\OptimisticLock;

#[Entity]
#[OptimisticLock(
    field: 'version',                       // default 'version'
    column: 'version',                       // default = field
    rule: OptimisticLock::RULE_INCREMENT,   // see below
)]
class Document
{
    #[Column(type: 'primary')]
    public int $id;

    public int $version;                     // type depends on rule
}
```

On UPDATE the behaviour adds `version = $oldVersion` to the WHERE. If a concurrent process has already incremented the version — the UPDATE touches 0 rows and Cycle throws `ChangedVersionException`.

Available `rule:` values:

| Constant                     | Column type  | Behavior                                              |
|------------------------------|--------------|-------------------------------------------------------|
| `RULE_INCREMENT`             | INTEGER      | `version + 1` on each update; default int = 1         |
| `RULE_MICROTIME`             | VARCHAR(32)  | `microtime(true)` as a string                         |
| `RULE_RAND_STR`              | VARCHAR(32)  | random string                                         |
| `RULE_DATETIME`              | DATETIME     | `now()`                                               |
| `RULE_MANUAL`                | —            | you set `$version` by hand, the behaviour only checks |

If `rule:` is not set, it's inferred from the type of the existing property: `int` → INCREMENT, `string` → MICROTIME, `DateTime*` → DATETIME. If there's no field — `BehaviorCompilationException` ("Wrong rule ...").

**Pitfall:** `OptimisticLock` wraps the command in a `WrappedCommand` — sometimes conflicts with a custom Mapper that overrides `queueUpdate`. If your Mapper doesn't call `parent::queueUpdate`, the version won't update.

---

## Mapper events

All hooks and event listeners work via **three events** emitted by `EventDrivenCommandGenerator`:

| Event                                                     | When dispatched                             |
|-----------------------------------------------------------|---------------------------------------------|
| `Cycle\ORM\Entity\Behavior\Event\Mapper\Command\OnCreate` | before the SQL INSERT of a new entity       |
| `Cycle\ORM\Entity\Behavior\Event\Mapper\Command\OnUpdate` | before the SQL UPDATE of an existing entity |
| `Cycle\ORM\Entity\Behavior\Event\Mapper\Command\OnDelete` | before SQL DELETE                           |

Each event extends `MapperEvent` and contains:
```php
public string $role;
public MapperInterface $mapper;
public object $entity;                  // the entity itself
public Node $node;                       // heap node (current state)
public State $state;                     // state with pending data to be written
public SourceInterface $source;
public \DateTimeImmutable $timestamp;
public ?CommandInterface $command;       // command to be executed; the listener can modify it
```

A listener can:
- Read/modify `$event->entity`.
- Append data to `$event->state->register('column', $value)` — lands in the final INSERT/UPDATE.
- Replace `$event->command` with a different command (this is what `SoftDelete` does).
- Returns nothing — modifications go through changes to the event state.

---

## `#[Hook]` — callable-based listener

The fastest way to attach an action to an event — without a separate class:

```php
use Cycle\ORM\Entity\Behavior\Hook;
use Cycle\ORM\Entity\Behavior\Event\Mapper\Command\OnCreate;
use Cycle\ORM\Entity\Behavior\Event\Mapper\Command\OnUpdate;

#[Entity]
#[Hook(
    callable: [User::class, 'onCreate'],
    events: OnCreate::class,
)]
#[Hook(
    callable: [User::class, 'touch'],
    events: [OnCreate::class, OnUpdate::class],
)]
class User
{
    // ...

    public static function onCreate(OnCreate $event): void
    {
        // $event->entity — the entity itself, not yet written
        // $event->state->register('audit_user', auth()->id());
    }

    public static function touch(OnCreate|OnUpdate $event): void
    {
        // shared method for create+update
    }
}
```

**Parameters:**
- **`callable`** — a PHP callable, typically `[ClassName::class, 'staticMethodName']`. The method **must be `static`** (Cycle invokes it as a class-method, no instance).
- **`events`** — `class-string<MapperEvent>` or an array of class-strings.

`#[Hook]` is **not repeatable**, but you can attach several different `#[Hook]`s on a class — each with its own callable. If you want a single callable for multiple events — pass an array to `events:`.

**Pitfall:** closures don't work in `callable` (they don't serialize to the schema). Only a static class-method or a global function name.

---

## `#[EventListener]` + `#[Listen]` — listener class

When there's a lot of logic and a Hook starts turning into a "god method" — make it a listener:

```php
use Cycle\ORM\Entity\Behavior\EventListener;
use Cycle\ORM\Entity\Behavior\Attribute\Listen;
use Cycle\ORM\Entity\Behavior\Event\Mapper\Command\OnCreate;
use Cycle\ORM\Entity\Behavior\Event\Mapper\Command\OnUpdate;
use Cycle\ORM\Entity\Behavior\Event\Mapper\Command\OnDelete;

#[Entity]
#[EventListener(listener: UserAuditor::class, args: ['source' => 'web'])]
class User { /* ... */ }

final class UserAuditor
{
    public function __construct(
        private AuditLog $log,
        private string $source,           // comes from args
    ) {}

    #[Listen(OnCreate::class)]
    public function logCreate(OnCreate $event): void
    {
        $this->log->record('user.created', $event->entity, $this->source);
    }

    #[Listen(OnUpdate::class)]
    #[Listen(OnDelete::class)]
    public function logModify(OnUpdate|OnDelete $event): void
    {
        $this->log->record('user.modified', $event->entity, $this->source);
    }
}
```

- **`#[Listen]` is repeatable** on a single method — multiple events via multiple attributes.
- **DI works**: the listener's constructor is resolved through the container you passed to `EventDrivenCommandGenerator(..., $container)`. Parameters from `args` are mixed in as kwargs.
- **`#[EventListener]` is repeatable** — you can attach several different listener classes to one entity.

**Pitfall:** `EventListener` does NOT create columns in the schema (unlike CreatedAt/UpdatedAt/etc.). If the listener writes to `$event->state->register('audit_column', ...)` and the column isn't in the schema — the SQL query crashes. Declare the column separately via `#[Column]`.

---

## `cycle/entity-behavior-uuid` — UUID generators

Separate package: automatic UUID generation on `OnCreate` + automatic typecast.

```php
use Cycle\ORM\Entity\Behavior\Uuid\Uuid7;

#[Entity]
#[Uuid7(field: 'uuid', column: 'uuid', nullable: false)]
class Order
{
    public UuidInterface $uuid;          // \Ramsey\Uuid\UuidInterface

    #[Column(type: 'string')]
    public string $name;
}
```

What it does:
1. Adds to the schema a `uuid` column of type UUID (via `addUuidColumn`), `BEFORE_INSERT`-generated.
2. Registers the typecast `[\Ramsey\Uuid\Uuid::class, 'fromString']` for the field — the hydrator immediately gives back a `UuidInterface` object, not a string.
3. On `OnCreate`, generates a UUID of the required version via `\Ramsey\Uuid\Uuid::uuid7()` (or the corresponding method).

Available variants — by UUID version:

| Attribute | Ramsey method     | When                                                 |
|-----------|-------------------|------------------------------------------------------|
| `Uuid1`   | `uuid1()`         | MAC + timestamp; guarantees host-level uniqueness    |
| `Uuid2`   | `uuid2()`         | DCE security; used rarely                            |
| `Uuid3`   | `uuid3($ns, $name)` | name-based MD5; you must set namespace/name        |
| `Uuid4`   | `uuid4()`         | random (the most common "just a UUID")              |
| `Uuid5`   | `uuid5($ns, $name)` | name-based SHA-1                                   |
| `Uuid6`   | `uuid6()`         | reordered timestamp v1; sortable                     |
| `Uuid7`   | `uuid7()`         | Unix epoch timestamp; **recommended by default** for new projects — sortable and compatible with k-sortable DB indexes |

**All `Uuid*` attributes are repeatable** (`IS_REPEATABLE`) — theoretically you can attach several with different `field:`, but typically one per entity.

**`nullable: true`** — skips auto-generation if the value is already set (for data imports where the UUID comes from outside).

**Pitfall:** the typecast is registered by the behaviour automatically. **Don't duplicate** `typecast: [Uuid::class, 'fromString']` in `#[Column]` by hand — it'll be a double cast and/or an error ("unknown rule" if out of sync).

**Pitfall:** requires `composer require ramsey/uuid` (the uuid package is a peer dependency). Without it — `Class 'Ramsey\Uuid\Uuid' not found`.

---

## Custom behaviour — your own `BaseModifier`

If the built-ins aren't enough (for instance, you need auto-slug, auto-tenant-id, encrypted column), extend `\Cycle\ORM\Entity\Behavior\Schema\BaseModifier` and place a listener class next to it with `#[Listen]` methods. A full example is in the package's source (`src/CreatedAt.php` + `src/Listener/CreatedAt.php`). In brief:

1. The attribute extends `BaseModifier`, implements `compute()`/`render()` (schema modification — add a column), `getListenerClass()`, `getListenerArgs()`.
2. The listener — a regular class with a constructor (DI works) and one or more `#[Listen(EventClass::class)]` methods.

This is a rare need — usually `Hook` or `EventListener` covers everything.

---

## Common pitfalls

- **Attributes silently ignored** — forgot to wire `EventDrivenCommandGenerator` into `ORM::__construct(commandGenerator: ...)`. The most common stumble.
- **`#[Hook]` callable is not `static`** — crashes with "Cannot call non-static method statically". Make the methods `static`.
- **`#[Hook]` callable is a closure** — not allowed, the schema is compiled and references are cached. Only `[Class, 'method']` / `'global_function'`.
- **`SoftDelete` doesn't filter SELECTs** — add a `Scope` separately. The behaviour only handles "DELETE → UPDATE the delete_time column".
- **`SoftDelete` doesn't fire `OnUpdate`** — listeners on update don't see the soft-delete. If you need an audit of soft-delete — listen to `OnDelete` (it's dispatched on logical delete too).
- **`OptimisticLock` + custom Mapper without `parent::queueUpdate()`** — the version won't update, the lock breaks.
- **`Uuid*` + manual `typecast`** — double cast / unknown rule. The behaviour sets the typecast itself.
- **`Uuid*` without `ramsey/uuid`** — the package isn't formally peer-required, you must install it manually.
- **Behaviour columns + a manual `#[Column]` with a different type** — `BehaviorCompilationException` ("field is not of the correct type"). Either trust the behaviour to create the column, or describe it with an exactly compatible type.
- **`#[EventListener]` writes to a state column that's not in the schema** — runtime SQL error. The listener doesn't create columns; use Hook + declare the column, or write your own `BaseModifier` attribute.
- **Hook on the parent entity of STI/JTI** (`inheritance.md`) — inherited by children (a class attribute is visible via reflection). Sometimes that's what you want, sometimes not — keep this in mind.

## Checklist

1. `composer require cycle/entity-behavior` (+ `cycle/entity-behavior-uuid`, `ramsey/uuid` if you need UUID).
2. `EventDrivenCommandGenerator` is passed in the bootstrap to `ORM::__construct` — without it the attributes are dead.
3. The correct attribute is chosen for timestamps: `#[CreatedAt]` (create only), `#[UpdatedAt]` (create+update), `#[SoftDelete]` (DELETE → UPDATE).
4. For soft-delete, a `Scope` is registered next to `#[SoftDelete]`, hiding deleted records.
5. `#[OptimisticLock]` — `rule:` is chosen to fit the business (incrementing int for counters, microtime/random for strings, datetime if you want both timestamp and lock).
6. `#[Hook]` callable — a `static` method; a closure won't work.
7. The `#[EventListener]` listener class is resolved through the container (DI works); `args:` are mixed into the constructor.
8. For UUID-PK `#[Uuid7]` is chosen (unless there's a specific reason for another). The field type is `\Ramsey\Uuid\UuidInterface`. The typecast is **not** registered manually.
9. If the behaviour conflicts with a custom `Mapper` — the Mapper calls `parent::queueCreate/Update/Delete`.
10. Not mixed up: "attribute on the class" (CreatedAt/Hook/Uuid7) vs "attribute on a method" (`Listen` — only inside a listener class declared via `EventListener`).
