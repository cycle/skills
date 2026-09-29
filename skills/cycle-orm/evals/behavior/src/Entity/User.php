<?php

declare(strict_types=1);

namespace Eval\Entity;

use Cycle\Annotated\Annotation\Column;
use Cycle\Annotated\Annotation\Entity;
use Cycle\Annotated\Annotation\Relation\HasMany;

#[Entity(table: 'users')]
class User
{
    #[Column(type: 'primary')]
    public int $id;

    #[Column(type: 'string')]
    public string $name;

    /** @var Order[] */
    #[HasMany(target: Order::class, innerKey: 'id', outerKey: 'user_id')]
    public array $orders = [];

    public function __construct(string $name)
    {
        $this->name = $name;
    }
}
