# Schema troubleshooting

A catalog of typical schema-build and migration issues in `cycle/annotated` + `cycle/schema-builder` + `cycle/database`, grouped by symptom.

---

## Symptom: the class doesn't show up in the schema / "Undefined schema ... not found"

Cycle doesn't see the entity at all, and schema compilation raises nothing. `$orm->getRepository(User::class)` → ``SchemaException: Undefined schema `App\User`, not found.``, `findAll()` is empty, a relation with `target: User::class` fails.

**Causes — check in order:**

1. **The `#[Entity]` attribute isn't present at all.** The class may have `#[Column]`, `#[BelongsTo]`, `#[Table]` — without `#[Entity]` the locator **silently skips it**. This is the most common "why nothing works after `composer require`" scenario. No warning or exception — just an empty schema.
   ```php
   // Silently ignored by the locator — no #[Entity]
   class User
   {
       #[Column(type: 'primary')]
       public int $id;
       #[Column(type: 'string')]
       public string $email;
   }

   // Correct
   #[Entity]
   class User { /* ... */ }
   ```
   See `cycle-orm-attributes/references/define-entity.md`.

2. **`#[Entity]` is set, but the import is wrong.** Should be `use Cycle\Annotated\Annotation\Entity;` or the full `\Cycle\Annotated\Annotation\Entity`. If you accidentally have `use Doctrine\ORM\Mapping\Entity;` — Cycle won't recognize it (it's **a different** class with the same name).

3. **The class doesn't make it into the locator.** `TokenizerEntityLocator` / `TokenizerEmbeddingLocator` (`cycle/annotated`) scan only the directories configured for the tokenizer (`Spiral\Tokenizer\ClassesInterface`). Check that the directory containing the class is among them.

4. **`#[Embeddable]` instead of `#[Entity]`** — embeddables are found by `TokenizerEmbeddingLocator` and the `Embeddings` generator, not by `Entities`; they have no repository of their own. See `cycle-orm-attributes/references/embeddable.md`.

5. **No primary key.** The Compiler silently skips an entity without a primary key. Check that:
   - there's `#[Column(type: 'primary')]` or `#[Column(type: 'bigPrimary')]`,
   - **or** there's `#[Column(... primary: true)]` on some column,
   - **or** there's `#[Table(primary: new PrimaryKey(columns: [...]))]` for a composite PK.

   See `cycle-orm-attributes/references/define-entity.md`, `cycle-orm-attributes/references/column-types.md`.

6. **A JTI child inherits the PK from a parent that has none.** Give the parent a PK.

---

## Symptom: "may cause cycles or multiple cascade paths" (MSSQL)

Schema sync fails with `Cycle\Database\Exception\HandlerException`; the message carries SQL Server error 1785:

```
Introducing FOREIGN KEY constraint '...' on table '...' may cause cycles or
multiple cascade paths. Specify ON DELETE NO ACTION or ON UPDATE NO ACTION,
or modify other FOREIGN KEY constraints.
```

**Cause:** every relation attribute (`BelongsTo`, `RefersTo`, `HasOne`, `HasMany`, `ManyToMany`) and `#[JoinedTable]` defaults to `fkAction: 'CASCADE'`, applied to both ON DELETE and ON UPDATE. SQL Server rejects a cascading FK when a table is reachable through two cascade paths (two relations to the same parent, a diamond) or the path loops back (a self-referencing relation).

**Fixes:**

1. **`fkAction: 'NO ACTION'`** on one relation of each conflicting path (`fkOnDelete:` sets ON DELETE separately):
   ```php
   #[BelongsTo(target: User::class, fkAction: 'NO ACTION')]
   public User $reviewer;
   ```

2. **`fkCreate: false`** — disable the FK at the schema level entirely. Cycle still knows about the relationship, just without a DB constraint:
   ```php
   #[HasOne(target: Tool::class, fkCreate: false)]
   public Tool $tool;
   ```

---

## Symptom: "There are no primary or candidate keys in the referenced table" (MSSQL)

SQL Server requires an FK to reference a PK or UNIQUE column. Which column Cycle's FK references depends on the relation:
- `BelongsTo` / `RefersTo`: the FK sits on the source's `innerKey` and references the target's `outerKey`.
- `HasOne` / `HasMany`: the FK sits on the target's `outerKey` and references the **source's `innerKey`**.

**When it occurs:** the referenced key is a non-PK field without a UNIQUE constraint:
```php
#[HasOne(target: Tool::class, innerKey: 'code')]
// FK tools.user_code → users.code; if users.code is neither PK nor UNIQUE → MSSQL refuses to create the FK
```

**Fixes:**

1. **Make the referenced column UNIQUE:** in User add `#[Index(columns: ['code'], unique: true)]`.
2. **Point the key at the PK** (drop `innerKey:` here — it defaults to the source's PK).
3. **`fkCreate: false`** if the FK isn't critical.

---

## Symptom: "WrongParentKeyColumnException"

Under JTI the parent key isn't found.

**Cause:** `#[JoinedTable(outerKey: 'some_field')]` where `some_field` isn't among the parent entity's fields.

**Fix:** typo in `outerKey:` (remember — this is a property/field name, not a DB column name), or you set `outerKey:` unnecessarily — the default (parent's primary field) is usually correct.

See `cycle-orm-attributes/references/inheritance.md`.

---

## Symptom: "Tests pass on SQLite, fail on MySQL/PostgreSQL/MSSQL"

A CI classic. SQLite is very lenient, the rest are stricter. If the project is cross-DB, plan for the constraints of the **strictest** driver — usually MSSQL.

**Checklist:**

1. **Multiple cascade paths** → MSSQL fail. See above.
2. **PG-only types (`jsonb`, `point`, etc) on other drivers** → fail. See `cycle-orm-attributes/references/column-types.md`.
3. **MSSQL-only types (`datetime2`) on other drivers** → fail.
4. **`unsigned` columns on PG** → silently ignored, but application logic may rely on unsigned semantics.
5. **Case sensitivity:** MySQL column names are case-insensitive everywhere; table names are case-sensitive on Linux and case-insensitive on Windows/macOS (`lower_case_table_names`). Use `snake_case` everywhere.
6. **NULL in UNIQUE** — on MSSQL only **one** NULL is allowed in a unique column (on the rest — many). If business logic relies on "many NULLs allowed" — there will be a conflict on MSSQL.
7. **ENUM** — MSSQL has no ENUM type; it's emulated via CHECK. Changing the list of values is heavier.

---

## Symptom: relation isn't loaded / `null` instead of an object

`$user->tenant` returns `null`, even though the FK reference is present in the DB.

**Causes:**

1. **`innerKey`/`outerKey` defaults don't match the real field names of the entity** (`cycle-orm-attributes/references/relations.md`). These parameters take property/field names, not DB column names — set them explicitly.
2. **Target entity isn't in the schema** (not found by the locator). All relations require the target to also be in the registry.
3. **A scope on the target entity filters the record out** — for example, Tenant has `NotDeletedScope` and the wanted tenant is deleted (`orm-extensions.md`). Bypass the scope for admin cases.

---

## Symptom: `Non-readonly class ... Cycle ORM Proxy cannot extend readonly class ...`

A fatal error when the default mapper generates the proxy for an entity declared as `readonly class` (first load or `make()`).

**Cause:** the default mapper subclasses the entity with a proxy, and PHP does not let a non-readonly class extend a `readonly class`. `readonly` properties are not supported by the default mapper either.

**Fix:** remove `readonly`. External immutability — via `protected` + getters. See `cycle-orm-attributes/references/define-entity.md`, `cycle-orm-attributes/references/embeddable.md`.

---

## Symptom: a migration "wipes" columns

A migration drops columns that aren't described in the entity.

**Cause:** Cycle syncs the DB schema with the PHP description. If a column isn't in the code, the migration removes it.

**Fixes:**

1. **Declare the column with `#[Column(..., readonlySchema: true)]`**, or mark the whole entity `readonlySchema: true` — Cycle won't touch it (`cycle-orm-attributes/references/column-types.md`, `cycle-orm-attributes/references/define-entity.md`).
2. **Describe the column in `#[Column]`**, so Cycle knows about it.
3. **Run migrations through CLI with a review step** (diff before apply), not auto-applied to production.

---

## Symptom: typecast rule ignored, column comes back raw

A column has `typecast: '...'`, but the property receives the raw DB value — no error. An unrecognised rule is silently skipped.

**Causes:**

1. **The entity lists its own handlers without `Typecast::class`** — `#[Entity(typecast: [MyHandler::class])]` replaces the built-in handler, so built-in rules stop applying.
2. **No handler on the entity understands the rule** — a custom rule name without the handler that implements it.

**Fix:** see "Handler list replaces the built-in one" in `cycle-orm-attributes/references/column-types.md`.

---

## Symptom: cyclic dependency on insert

On `persist($a)` where `$a->b = $b`, `$b->a = $a` — Cycle can't determine the order: `Transaction can't be finished. Some relations can't be resolved:`.

**Fix:**
1. Break the cycle: on one side use `#[RefersTo(target: ..., nullable: true)]` instead of `#[BelongsTo]` — it stores the key in a follow-up UPDATE. A non-nullable `RefersTo` is compiled as `BelongsTo` and keeps the cycle.
2. Or persist in two passes: first `persist($a)` without `$a->b`, then `$a->b = $b; persist($b); persist($a)`.

See `cycle-orm-attributes/references/relations.md`.

---

## Symptom: "Can't hydrate an entity because property and value types are incompatible."

`MapperException` on load: the hydrator wrote `null` into a non-nullable typed property. The previous exception is the `TypeError`, e.g. `Cannot assign null to property App\Pet::$owner of type App\Person`.

**Causes:**

1. **`#[Column(type: 'string', nullable: true)] public string $name;`** — the DB returns NULL.
2. **`#[BelongsTo(target: Person::class, nullable: true)] public Person $owner;`** with a NULL FK. An eager relation fails on load with the `MapperException`; a lazy one throws the bare `TypeError` on first access of `$pet->owner`.

**Fixes:** make properties nullable (`?string` + `nullable: true` + default `= null`), or ensure the DB is never-NULL. For `must not be accessed before initialization` on promoted properties see `cycle-orm-attributes/references/define-entity.md`.

---

## General diagnostic checklist

1. In Spiral (`spiral/cycle-bridge`), run `cycle:migrate` — it prints the detected schema changes and writes a migration without applying it (`migrate` applies; `cycle:migrate -r` does both). `cycle:sync` applies changes directly, without a migration.
2. Verify that all entity classes reach the schema: `cycle:render` prints the compiled schema (pass roles, e.g. `cycle:render user,post`, to narrow it).
3. Verify that the problematic driver (MSSQL/PG) constraints are accounted for: cascade paths, identifier length, unique NULL behavior.
4. If the error is via a relation — temporarily set `fkCreate: false` for diagnostics; if the symptom goes away — the issue is in the FK constraint, not in Cycle.
