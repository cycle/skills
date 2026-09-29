# Collection factories for `*Many` relations

`HasMany`, `ManyToMany`, and `MorphedHasMany` (plus the M2M `Pivoted` layer) hydrate via a **collection factory**. The default factory is `ArrayCollectionFactory`, which produces a plain PHP `array`. Any other collection (Doctrine, Laravel, loophp, a custom class) is enabled by swapping the factory at the ORM config level and optionally setting `collection:` on the relation attribute.

See also:
- the `#[HasMany]`/`#[ManyToMany]` attributes and the `collection:` parameter → `cycle-orm-attributes/references/relations.md`

Reference docs: https://cycle-orm.dev/docs/relation-collections.

---

## Minimum working example

```php
use Cycle\ORM;

$factory = (new ORM\Factory(
    dbal: $dbal,
    defaultCollectionFactory: new ORM\Collection\ArrayCollectionFactory(),
))
    ->withCollectionFactory(
        'doctrine',
        new ORM\Collection\DoctrineCollectionFactory(),
        \Doctrine\Common\Collections\Collection::class,
    );

$orm = new ORM\ORM(factory: $factory, schema: $schema);
```

```php
#[Entity]
class Article
{
    #[Column(type: 'primary')]
    public int $id;

    /** @var Collection<int, Tag> */
    #[ManyToMany(target: Tag::class, through: ArticleTag::class, collection: 'doctrine')]
    public Collection $tags;

    public function __construct()
    {
        $this->tags = new PivotedCollection();   // ← required for new entities; accepts setPivot()
    }
}
```

The default factory hydrates every `*Many` relation unless `collection:` is set explicitly. Alternative factories are registered via `withCollectionFactory()` and selected by alias or by collection class FQCN.

---

## `CollectionFactoryInterface`

```php
interface CollectionFactoryInterface
{
    public function getInterface(): ?string;
    public function withCollectionClass(string $class): static;
    public function collect(iterable $data): iterable;
}
```

- **`getInterface()`** — the interface this factory "represents" (used to match `collection:` by FQCN). For `ArrayCollectionFactory` it is `null`.
- **`withCollectionClass($class)`** — immutable clone whose `collect()` returns an instance of a specific class (Doctrine `ArrayCollection`, Laravel `Collection`, custom).
- **`collect($data)`** — converts iterable into the final collection. For M2M `$data` may be a `PivotedStorage`; the factory must handle that branch if it wants to preserve pivot context.

---

## Built-in factories

| Class                            | Returns                                                             | Preserves pivot for M2M  | Peer dependency            |
|----------------------------------|---------------------------------------------------------------------|--------------------------|----------------------------|
| `ArrayCollectionFactory`         | PHP `array`                                                         | **no** (pivot dropped)   | —                          |
| `DoctrineCollectionFactory`      | `Doctrine\Common\Collections\Collection` (default `ArrayCollection`) | **yes** (via `PivotedCollection`) | `doctrine/collections`     |
| `IlluminateCollectionFactory`    | `Illuminate\Support\Collection`                                     | **no** (pivot dropped)   | `illuminate/collections`   |
| `LoophpCollectionFactory`        | `loophp\collection\Contract\Collection` (default `Collection`)      | **yes** (via `LoophpPivotedCollection`) | `loophp/collection`        |

`IlluminateCollectionFactory::collect()` does not branch on `PivotedStorage`, so pivot context is lost during hydration. If your M2M relation needs pivot fields, **use Doctrine or loophp**, or write a custom factory that returns a `PivotedCollectionInterface` implementation.

---

## Registering factories

```php
$factory = (new ORM\Factory(
    dbal: $dbal,
    defaultCollectionFactory: new ORM\Collection\ArrayCollectionFactory(),
))
    ->withCollectionFactory(
        'doctrine',
        new ORM\Collection\DoctrineCollectionFactory(),
        \Doctrine\Common\Collections\Collection::class,
    )
    ->withCollectionFactory(
        'illuminate',
        new ORM\Collection\IlluminateCollectionFactory(),
        \Illuminate\Support\Collection::class,
    );
```

**`withCollectionFactory($alias, $factory, $interface = null)` returns a new Factory** (immutable). If you register factories after `new ORM($factory, ...)`, you must rebuild the ORM with the new `$factory` — otherwise the changes are invisible.

The `$interface` argument is the interface this factory "covers" (used when matching `collection: SomeClass::class`). If `null`, `$factory->getInterface()` is used.

Lookup in `Factory::collection($name)`:
1. `$name === null` → `defaultCollectionFactory`.
2. `$name` is in the alias + FQCN cache → cached factory.
3. `$name` is an existing class → search for a factory whose `interface` is a superclass/superinterface of `$name` (`is_subclass_of`). Found → `$factory->withCollectionClass($name)`.
4. Otherwise — `factory->make($name)` (attempt to create through the container). In practice this is a fallback and usually throws a TypeError when `$name` doesn't implement `CollectionFactoryInterface`.

### Manually assembled schema

In the compiled schema the collection type lives under `Relation::COLLECTION_TYPE`. When the schema is assembled by hand (without `cycle/annotated` attributes), put there the same set of values the `collection:` attribute accepts (alias / collection class FQCN / `null`):

```php
'tags' => [
    Relation::TYPE => Relation::MANY_TO_MANY,
    Relation::TARGET => Tag::class,
    Relation::COLLECTION_TYPE => 'doctrine',   // or FQCN
    // ...
],
```

---

## Constructor initialization — required for new entities

Cycle initializes a relation property at **hydration time**. For `new Article()` the property stays in its declared state — the first access throws `Error: Typed property Article::$tags must not be accessed before initialization`:

```php
#[Entity]
class Article
{
    #[ManyToMany(target: Tag::class, through: ArticleTag::class, collection: 'doctrine')]
    public Collection $tags;
}

$a = new Article();
$a->tags->add(new Tag('php'));   // ❌ Error: Typed property Article::$tags must not be accessed before initialization
```

Fix — initialize in the constructor. For an M2M whose pivots you write, use `PivotedCollection`: a Doctrine `ArrayCollection` has no `setPivot()`.

```php
public function __construct()
{
    $this->tags = new \Cycle\ORM\Collection\Pivoted\PivotedCollection();
}
```

The same applies to `array`-backed relations — but a property default `array $tags = []` is enough; no constructor needed:

```php
#[HasMany(target: Order::class, outerKey: 'customer_id')]
public array $orders = [];   // default suffices, new Customer() is fine
```

Alternative — create entities via `$orm->make()` (see `entity-lifecycle.md`): it instantiates through the mapper, which handles relation-property init itself.

---

## ManyToMany — pivot

For M2M the factory receives not "an array of target entities" but a `PivotedStorage` — a pair `(elements: TEntity[], context: SplObjectStorage<TEntity, TPivot>)`. If the factory knows how to recognize that branch, it builds a collection implementing `PivotedCollectionInterface`:

```php
interface PivotedCollectionInterface
{
    public function hasPivot(object $element): bool;
    public function getPivot(object $element): mixed;
    public function setPivot(object $element, mixed $pivot): void;
    public function getPivotContext(): \SplObjectStorage;
}
```

Built-in implementations:
- **`PivotedCollection`** (`extends Doctrine ArrayCollection`). Returned by `DoctrineCollectionFactory` when `data instanceof PivotedStorage`.
- **`LoophpPivotedCollection`** (`extends loophp CollectionDecorator`). Returned by `LoophpCollectionFactory`.
- No ready-made pivot variant for `array`/`illuminate`.

### Reading pivot data

```php
/** @var PivotedCollection<int, Tag, ArticleTag> $tags */
$tags = $article->tags;

foreach ($tags as $tag) {
    /** @var ArticleTag $pivot */
    $pivot = $tags->getPivot($tag);
    echo $pivot->linkedAt->format('Y-m-d');
}
```

`getPivot($tag)` returns the pivot entity instance (the class declared in `through:` on `#[ManyToMany]`). Returns `null` if no pivot is bound. `hasPivot($tag)` for a presence check.

### Writing pivot data on a new association

```php
$tag = $tagRepo->findByPK($tagId);
$pivot = new ArticleTag();
$pivot->linkedAt = new \DateTimeImmutable();

$article->tags->add($tag);
$article->tags->setPivot($tag, $pivot);    // attach pivot to the association

$em->persist($article)->run();
```

During M2M persist, Cycle extracts a `PivotedStorage` back from the collection via `extract($data)`:
- `data instanceof PivotedStorage` → returned as-is;
- `data instanceof PivotedCollectionInterface` → `new PivotedStorage($data->toArray(), $data->getPivotContext())`;
- `Doctrine\Collection` (without pivot) → `new PivotedStorage($data->toArray())` (empty pivot context);
- `Traversable` / `array` / `null` → `PivotedStorage` with empty context.

Consequence: with `ArrayCollectionFactory` or `IlluminateCollectionFactory`, the pivot object **does not round-trip** through the collection — you must use Doctrine, loophp, or a custom factory.

---

## PHP `array` limitations under ArrayCollectionFactory

With the proxy mapper (default in Cycle 2.x) a lazy-loaded `array` relation **goes through `__get()`** on the proxy. This causes two classes of problems:

```php
// $user->posts not loaded yet (lazy)

// ❌ Notice: Indirect modification of overloaded property
$user->posts[] = new Post();

// ❌ Notice: Indirect modification of overloaded property; also won't behave as expected
$posts = &$user->posts;
```

PHP does not allow indexed appends or reference grabs from a value returned by a magic method **without** a prior materializing access.

Workaround — read-then-write:

```php
$user->posts;            // triggers lazy load — relation is materialized into the property
$user->posts[] = new Post();   // ✓ works now
```

Or: use a `Collection` factory (Doctrine/Laravel) — `add($post)` works without the workaround because `$user->posts` returns the collection object itself, and `add()` mutates its internal state.

---

## Custom collection class

### Custom class extending a built-in pivot collection

Simplest case — a class that extends the Doctrine/loophp pivot variant:

```php
class TagCollection extends \Cycle\ORM\Collection\Pivoted\PivotedCollection
{
    public function names(): array
    {
        return array_map(fn(Tag $t) => $t->name, $this->toArray());
    }
}

#[ManyToMany(target: Tag::class, through: ArticleTag::class, collection: TagCollection::class)]
public TagCollection $tags;
```

When Cycle matches `collection: TagCollection::class`:
1. it looks up a registered factory whose `interface = Doctrine\Common\Collections\Collection`;
2. calls `withCollectionClass(TagCollection::class)` on it;
3. `DoctrineCollectionFactory::collect()` builds `new TagCollection($data->getElements(), $data->getContext())` via the `is_a($class, PivotedCollection::class)` branch.

`PivotedCollection::__construct()` and `LoophpPivotedCollection::__construct()` are `final`: a subclass adds methods only and inherits the `(array $elements = [], ?SplObjectStorage $pivotData = null)` constructor the factory calls.

### Custom `CollectionFactoryInterface`

When you need a fundamentally different class (immutable, outside the Doctrine/loophp hierarchy), implement `CollectionFactoryInterface` yourself and register it via `withCollectionFactory()`. Honor the contract — `getInterface()` / `withCollectionClass()` / `collect()`. If the collection should support pivot, implement `PivotedCollectionInterface` and handle the `$data instanceof PivotedStorage` branch in `collect()`.

---

## Pitfalls

- **`new Entity()`, then collection access → "Typed property Article::$tags must not be accessed before initialization".** Initialize the property in `__construct` (see "Constructor initialization") or create the entity via `$orm->make()`.
- **M2M pivot is lost with `collection: 'array'` or `'illuminate'`.** Use `'doctrine'`, `'loophp'`, or a custom factory that handles `PivotedStorage`.
- **`$user->posts[] = new Post` → "Indirect modification of overloaded property".** Read `$user->posts;` before the write, or use a `Collection` factory.
- **Factories registered after `new ORM()` have no effect.** Register every factory before `new ORM($factory, $schema)`.
- **`collection: SomeClass::class` fails inside `factory->make()`.** Register a factory whose `interface` is a parent of `SomeClass`, or use the factory alias.
- **`getPivot()` returns `null` on a `map()` result.** `map()` produces new elements without pivots. `filter()`/`partition()` results keep pivots: they share the source collection's pivot storage, so `setPivot()` on a filtered result changes the source too. Read pivots from the loaded collection.

## Checklist

1. `defaultCollectionFactory` is set explicitly when a non-array default is wanted.
2. All needed factories (`doctrine`/`illuminate`/`loophp`/custom) are registered **before** `new ORM($factory, ...)`.
3. If an M2M relation needs pivot fields — `collection:` points to Doctrine, loophp, or a custom `PivotedCollectionInterface` factory. For `array`/Illuminate, pivot is lost.
4. Every entity that owns a `*Many` relation with a typed `Collection` property initializes it in its constructor (`new PivotedCollection()` where M2M pivots are written) — or is created via `$orm->make()`.
5. With an `array` collection, no code path uses `$entity->relation[] = $item` without a prior materializing read (when the proxy mapper is in play).
6. When `collection: SomeClass::class` (not an alias) is used — a registered factory exists whose `interface` is a superclass of `SomeClass`. Otherwise the `factory->make()` fallback will fail.
7. The custom pivot collection subclass declares no constructor.
