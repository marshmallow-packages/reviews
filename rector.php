<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;

/**
 * Rector is a deliberate tool here, not a gate. It is deliberately absent from
 * the `test` script and from CI: run `composer refactor:check` to see what it
 * would change, `composer refactor` to apply it, and review the diff yourself.
 */
return RectorConfig::configure()
    ->withPaths([
        __DIR__.'/config',
        __DIR__.'/src',
        __DIR__.'/tests',
    ])
    ->withPhpSets(php83: true)
    ->withPreparedSets(
        deadCode: true,
        codeQuality: true,
        typeDeclarations: true,
        earlyReturn: true,
    )
    ->withImportNames(importShortClasses: false);
