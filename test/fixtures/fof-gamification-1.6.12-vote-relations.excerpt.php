<?php
/*
 * Excerpt from fof/gamification 1.6.12 extend.php
 * QUALIFIED_PROVIDER_SOURCE_SHA=6be68f005b7db3036ca67a7b807bc4531972ed19
 */
return <<<'EOF'
        ->relationship('upvotes', function (Post $post) {
            return $post->votes()->where('value', '>', 0);
        })
        ->relationship('downvotes', function (Post $post) {
            return $post->votes()->where('value', -1);
        })

    (new Extend\ApiController(Controller\ListPostsController::class))
        ->addOptionalInclude(['upvotes', 'downvotes'])

    (new Extend\ApiController(Controller\ShowPostController::class))
        ->addOptionalInclude(['upvotes', 'downvotes'])
EOF;
