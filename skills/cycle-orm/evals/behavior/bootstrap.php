<?php

declare(strict_types=1);

/**
 * Self-contained bootstrap for cycle-orm behavior evals.
 *
 * Builds a fresh in-memory SQLite database, compiles the schema from the
 * attribute entities in src/ (Entity + Adv + Jti), syncs tables, and seeds
 * deterministic fixtures. Returns [$orm, $logger].
 *
 * Fixtures:
 *   - users  : 3 users, 3 orders each (statuses paid/paid/new)
 *   - blogs  : 1 blog "b1" with 3 posts (2 published, 1 draft; Post has PublishedScope)
 *   - animals: 1 Dog (Rex/husky), 1 Cat (Tom)  -- single-table inheritance
 *   - persons/employees: 1 Person (Alice), 1 Employee (Bob/Sales) -- joined-table inheritance
 *
 * $logger is an EvalLogger recording every SQL statement (read ->queries).
 *
 * Depends on the repo's vendor/ (cycle/* installed via composer). The evals
 * are self-contained otherwise: they do not require the sandbox/ directory.
 */

require dirname(__DIR__, 4) . '/vendor/autoload.php';

// PSR-4 for eval fixtures (Eval\ -> evals/behavior/src/).
spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'Eval\\')) {
        $path = __DIR__ . '/src/' . str_replace('\\', '/', substr($class, strlen('Eval\\'))) . '.php';
        if (is_file($path)) {
            require $path;
        }
    }
});

use Cycle\Annotated;
use Cycle\Annotated\Locator\TokenizerEmbeddingLocator;
use Cycle\Annotated\Locator\TokenizerEntityLocator;
use Cycle\Database\Config;
use Cycle\Database\DatabaseManager;
use Cycle\ORM\EntityManager;
use Cycle\ORM\Factory;
use Cycle\ORM\ORM;
use Cycle\ORM\Schema;
use Cycle\Schema\Compiler;
use Cycle\Schema\Generator;
use Cycle\Schema\Registry;
use Spiral\Tokenizer\ClassLocator;
use Symfony\Component\Finder\Finder;
use Eval\Adv\Blog;
use Eval\Adv\Cat;
use Eval\Adv\Dog;
use Eval\Adv\Post;
use Eval\Entity\Order;
use Eval\Entity\User;
use Eval\Jti\Employee;
use Eval\Jti\Person;

// The harness requires this bootstrap once per case; guard the declaration.
if (!class_exists('EvalLogger')) {
    /** Minimal PSR-3 logger that records SQL statements emitted by the driver. */
    final class EvalLogger extends \Psr\Log\AbstractLogger
    {
        /** @var string[] */
        public array $queries = [];

        public function log($level, $message, array $context = []): void
        {
            $this->queries[] = (string) $message;
        }

        public function reset(): void
        {
            $this->queries = [];
        }

        /** SELECT statements only, whitespace-collapsed (handy for SQL asserts). */
        public function selects(): array
        {
            $out = [];
            foreach ($this->queries as $q) {
                if (stripos(ltrim($q), 'SELECT') === 0) {
                    $out[] = preg_replace('/\s+/', ' ', trim($q));
                }
            }
            return $out;
        }
    }
}

$logger = new EvalLogger();

$dbal = new DatabaseManager(
    new Config\DatabaseConfig([
        'default'     => 'default',
        'databases'   => ['default' => ['connection' => 'sqlite']],
        'connections' => [
            'sqlite' => new Config\SQLiteDriverConfig(
                connection: new Config\SQLite\MemoryConnectionConfig(),
            ),
        ],
    ]),
);
$dbal->database('default')->getDriver()->setLogger($logger);

$classLocator = new ClassLocator(
    (new Finder())->files()->in([__DIR__ . '/src'])->name('*.php'),
);

$registry = new Registry($dbal);
$schemaArray = (new Compiler())->compile($registry, [
    new Annotated\Embeddings(new TokenizerEmbeddingLocator($classLocator)),
    new Annotated\Entities(new TokenizerEntityLocator($classLocator)),
    new Annotated\TableInheritance(),
    new Annotated\MergeColumns(),
    new Generator\GenerateRelations(),
    new Generator\ValidateEntities(),
    new Generator\RenderTables(),
    new Generator\RenderRelations(),
    new Annotated\MergeIndexes(),
    new Generator\SyncTables(),
    new Generator\GenerateTypecast(),
]);

$orm = new ORM(new Factory($dbal), new Schema($schemaArray));

// --- Seed -------------------------------------------------------------------
$em = new EntityManager($orm);

// users + orders
$statuses = ['paid', 'paid', 'new'];
for ($u = 1; $u <= 3; $u++) {
    $user = new User("user{$u}");
    foreach ($statuses as $status) {
        $user->orders[] = new Order(0, $status);
    }
    $em->persist($user);
}

// blog + posts (PublishedScope filters published=true)
$blog = new Blog('b1');
$blog->posts[] = new Post(0, 'published-1', true);
$blog->posts[] = new Post(0, 'published-2', true);
$blog->posts[] = new Post(0, 'draft-1', false);
$em->persist($blog);

// STI animals
$dog = new Dog('Rex');
$dog->breed = 'husky';
$em->persist($dog);
$em->persist(new Cat('Tom'));

// JTI persons/employees
$em->persist(new Person('Alice'));
$em->persist(new Employee('Bob', 'Sales'));

$em->run();

$orm->getHeap()->clean(); // start every eval with a clean identity map
$logger->reset();         // drop seeding SQL

return [$orm, $logger];
