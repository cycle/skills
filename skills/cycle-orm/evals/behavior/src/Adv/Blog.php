<?php

declare(strict_types=1);

namespace Eval\Adv;

use Cycle\Annotated\Annotation\Column;
use Cycle\Annotated\Annotation\Entity;
use Cycle\Annotated\Annotation\Relation\HasMany;

#[Entity(table: 'blogs')]
class Blog
{
    #[Column(type: 'primary')]
    public int $id;

    #[Column(type: 'string')]
    public string $title;

    /** @var Post[] */
    #[HasMany(target: Post::class, innerKey: 'id', outerKey: 'blog_id')]
    public array $posts = [];

    public function __construct(string $title)
    {
        $this->title = $title;
    }
}
