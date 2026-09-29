<?php

declare(strict_types=1);

namespace Eval\Entity;

use Cycle\Annotated\Annotation\Column;
use Cycle\Annotated\Annotation\Entity;

#[Entity(table: 'orders')]
class Order
{
    #[Column(type: 'primary')]
    public int $id;

    #[Column(type: 'integer')]
    public int $user_id;

    #[Column(type: 'string')]
    public string $status;

    public function __construct(int $userId, string $status)
    {
        $this->user_id = $userId;
        $this->status = $status;
    }
}
