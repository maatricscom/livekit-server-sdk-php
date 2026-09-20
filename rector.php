<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Set\ValueObject\LevelSetList;

return RectorConfig::configure()
    ->withPaths([__DIR__ . '/src', __DIR__ . '/tests'])
    ->withSkip([__DIR__ . '/src/Proto'])
    ->withSets([LevelSetList::UP_TO_PHP_83])
    ->withPhpSets(php83: true);
