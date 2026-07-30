<?php

declare(strict_types=1);

use Rector\CodingStyle\Rector\ArrowFunction\ArrowFunctionDelegatingCallToFirstClassCallableRector;
use Rector\Config\RectorConfig;
use Rector\Set\ValueObject\SetList;

return RectorConfig::configure()
    // __DIR__.'/database' joins this list in Phase 2, with the migrations.
    ->withPaths([
        __DIR__.'/config',
        __DIR__.'/src',
        __DIR__.'/tests',
    ])
    // CLAUDE.md: PHP 8.2 floor. Never let Rector introduce 8.3+ only syntax —
    // no typed class constants, no json_validate(), no #[\Override].
    ->withPhpSets(php82: true)
    ->withSets([
        SetList::CODE_QUALITY,
        SetList::DEAD_CODE,
        SetList::TYPE_DECLARATION,
    ])
    ->withImportNames(importShortClasses: false, removeUnusedImports: true)
    ->withSkip([
        __DIR__.'/vendor',

        // Pest binds dataset closures to the test instance, so an arrow
        // function wrapping a static call is NOT interchangeable with a
        // first-class callable there: binding one raises "Cannot bind an
        // instance to a static closure" at runtime. The rewrite is not
        // behaviour-preserving in this context.
        ArrowFunctionDelegatingCallToFirstClassCallableRector::class => [
            __DIR__.'/tests',
        ],
    ]);
