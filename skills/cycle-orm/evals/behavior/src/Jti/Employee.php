<?php

declare(strict_types=1);

namespace Eval\Jti;

use Cycle\Annotated\Annotation\Column;
use Cycle\Annotated\Annotation\Entity;
use Cycle\Annotated\Annotation\Inheritance\JoinedTable;

#[Entity(table: 'employees')]
#[JoinedTable]
class Employee extends Person
{
    #[Column(type: 'string')]
    public string $department;

    public function __construct(string $name, string $department)
    {
        parent::__construct($name);
        $this->department = $department;
    }
}
