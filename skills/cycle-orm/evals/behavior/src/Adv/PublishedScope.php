<?php

declare(strict_types=1);

namespace Eval\Adv;

use Cycle\ORM\Select\QueryBuilder;
use Cycle\ORM\Select\ScopeInterface;

final class PublishedScope implements ScopeInterface
{
    public function apply(QueryBuilder $query): void
    {
        $query->where('published', true);
    }
}
