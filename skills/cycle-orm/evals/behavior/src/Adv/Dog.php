<?php

declare(strict_types=1);

namespace Eval\Adv;

use Cycle\Annotated\Annotation\Column;
use Cycle\Annotated\Annotation\Entity;
use Cycle\Annotated\Annotation\Inheritance\SingleTable;

#[Entity]
#[SingleTable]
class Dog extends Animal
{
    #[Column(type: 'string', nullable: true)]
    public ?string $breed = null;
}
