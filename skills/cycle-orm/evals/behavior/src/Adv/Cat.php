<?php

declare(strict_types=1);

namespace Eval\Adv;

use Cycle\Annotated\Annotation\Column;
use Cycle\Annotated\Annotation\Entity;
use Cycle\Annotated\Annotation\Inheritance\SingleTable;

#[Entity]
#[SingleTable('feline')]
class Cat extends Animal
{
    #[Column(type: 'integer', default: 9)]
    public int $lives = 9;
}
