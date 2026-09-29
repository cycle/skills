<?php

declare(strict_types=1);

namespace Eval\Adv;

use Cycle\Annotated\Annotation\Column;
use Cycle\Annotated\Annotation\Entity;

#[Entity(table: 'posts', scope: PublishedScope::class)]
class Post
{
    #[Column(type: 'primary')]
    public int $id;

    #[Column(type: 'integer')]
    public int $blog_id;

    #[Column(type: 'string')]
    public string $title;

    #[Column(type: 'boolean')]
    public bool $published;

    public function __construct(int $blogId, string $title, bool $published)
    {
        $this->blog_id = $blogId;
        $this->title = $title;
        $this->published = $published;
    }
}
