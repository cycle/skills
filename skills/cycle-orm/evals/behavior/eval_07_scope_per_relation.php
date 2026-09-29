<?php

declare(strict_types=1);

/**
 * Pitfall: a relation's default Scope hides rows, and the per-relation scope
 * override interacts badly with fetchOne()/findOne().
 *
 * Post has PublishedScope (published=true), so load('posts') normally returns
 * only published posts. To include drafts you override the relation scope with
 * LoadOptions(scope: false). That works under fetchAll(). It does NOT survive
 * fetchOne()/findOne(): those clone the Select internally (limit(1)) and the
 * per-relation scope override is dropped — so drafts get filtered out again.
 *
 * Correct: use fetchAll() (+ pick the row) when overriding a relation scope.
 */

use Cycle\ORM\Select\Options\HasManyLoadOptions;
use Eval\Adv\Blog;

return [
    'id'      => 'scope-per-relation',
    'prompt'  => 'The Post entity has a global PublishedScope. Load blog "b1" together with ALL of its posts, including unpublished drafts. (Seed: 2 published + 1 draft.)',
    'pitfall' => 'Per-relation scope override (LoadOptions scope:false) is silently dropped under fetchOne()/findOne() because they clone the Select; use fetchAll().',

    'solution' => static function ($orm): array {
        // scope:false disables PublishedScope for THIS relation load.
        // Must be fetchAll() — fetchOne()/findOne() drop the override on clone.
        $blogs = $orm->getRepository(Blog::class)
            ->select()
            ->where('id', 1)
            ->load('posts', new HasManyLoadOptions(scope: false))
            ->fetchAll();

        return $blogs;
    },

    'grader' => static function (array $ctx): array {
        if ($ctx['threw'] !== null) {
            return [false, 'solution threw'];
        }
        $blogs = $ctx['result'];
        if (!is_array($blogs) || count($blogs) !== 1) {
            return [false, 'expected exactly 1 blog'];
        }
        $blog = $blogs[0];
        // anti-pitfall: all 3 posts (incl. draft) must be present, not just the 2 published
        if (count($blog->posts) !== 3) {
            return [false, 'expected 3 posts (incl. draft); got ' . count($blog->posts) . ' — scope override did not apply (likely fetchOne) or was omitted'];
        }
        $hasDraft = false;
        foreach ($blog->posts as $p) {
            if ($p->published === false) {
                $hasDraft = true;
            }
        }
        if (!$hasDraft) {
            return [false, 'draft post is missing — PublishedScope was not bypassed'];
        }
        return [true, 'ok'];
    },
];
