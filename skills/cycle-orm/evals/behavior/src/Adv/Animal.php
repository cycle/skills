<?php

declare(strict_types=1);

namespace Eval\Adv;

use Cycle\Annotated\Annotation\Column;
use Cycle\Annotated\Annotation\Entity;
use Cycle\Annotated\Annotation\Inheritance\DiscriminatorColumn;

#[Entity(table: 'animals')]
#[DiscriminatorColumn(name: 'type')]
class Animal
{
    #[Column(type: 'primary')]
    public int $id;

    #[Column(type: 'string')]
    public string $name;

    #[Column(type: 'string')]
    public string $type;

    public function __construct(string $name)
    {
        $this->name = $name;
    }
}
