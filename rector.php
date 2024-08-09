<?php
use Rector\Config\RectorConfig;
use Rector\Set\ValueObject\LevelSetList;
use Rector\ValueObject\PhpVersion;

return RectorConfig::configure()
    ->withPaths([
//        __DIR__ . '/extension',
//        __DIR__ . '/kernel',
//        __DIR__ . '/lib',
//        __DIR__ . '/bin',
//        __DIR__ . '/cronjobs',
        __DIR__ . '/benchmarks',
//        __DIR__ . '/vendor/opencontent',
    ])->withSkip([
        __DIR__ . '/extension/*/settings/*',
        __DIR__ . '/extension/*/design/*',
        __DIR__ . '/extension/*/doc/*',
        __DIR__ . '/extension/*/translations/*',
        __DIR__ . '/extension/*/schema/*',
        __DIR__ . '/extension/*/update/*',
        __DIR__ . '/extension/*/packages/*',
        \Rector\Php54\Rector\Array_\LongArrayToShortArrayRector::class,
//        \Rector\CodeQuality\Rector\ClassMethod\OptionalParametersAfterRequiredRector::class
    ])
    ->withSets([LevelSetList::UP_TO_PHP_81])
    ;