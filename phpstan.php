<?php

use Modufolio\Appkit\PHPStan\Doctrine\ObjectMetadataResolver;

return [
    'includes' => [
        __DIR__.'/extension.php',
        __DIR__.'/vendor/phpstan/phpstan-phpunit/extension.neon',
    ],
    'services' => [
        'appkitDoctrine.objectMetadataResolver' => [
            'class' => ObjectMetadataResolver::class,
            'arguments' => [
                'objectManagerLoader' => __DIR__.'/tests/phpstan-object-manager.php',
            ],
        ],
    ],
    'parameters' => [
        'level' => 8,
        'paths' => ['src', 'tests'],
        'excludePaths' => [
            'analyseAndScan' => [
                'vendor/*',
                // Toolkit: utility classes ported from Kirby CMS; excluded from
                // static analysis.
                'src/Toolkit/*',
                // Code-generation templates: variables are populated via extract()
                // at render time, so they're intentionally undefined here.
                'src/Console/Resources/skeleton/*',
                // Fixture files: sample "app" source code used as generator
                // input/output in tests, referencing intentionally fake classes.
                'tests/*/fixtures/*',
                'tests/*/*/fixtures/*',
                // The test app's service definitions are closures rebound to the
                // container at load time, so `$this` is legitimately undefined
                // when the file is read on its own. The rest of the test app's
                // config analyses cleanly and stays in scope.
                'tests/fixtures/config/interfaces.php*',
                // These tests generate `use` statements / attribute nodes from arbitrary
                // FQCN strings purely to exercise string-formatting logic; the FQCNs are
                // deliberately fictitious and never actually loaded or instantiated.
                'tests/Unit/Util/ClassSourceManipulatorTest.php*',
                'tests/Unit/Util/UseStatementGeneratorTest.php*',
                // Collection/Html use PHP magic methods (__get/__set/__call/__callStatic)
                // over genuinely arbitrary, unenumerable keys/tags — not visible to static
                // analysis without a dedicated PHPStan reflection extension.
                'tests/Unit/Toolkit/CollectionGetterTest.php*',
                'tests/Unit/Toolkit/CollectionMutatorTest.php*',
                'tests/Unit/Toolkit/HtmlTest.php*',
                // Fixture controller classes whose constructors are never invoked —
                // only inspected via Reflection to test argument-resolution metadata.
                'tests/Unit/DependencyInjection/ReflectionControllerArgumentResolverTest.php*',
            ],
        ],
        'bootstrapFiles' => [
            'phpstan-bootstrap.php',
        ],
        'ignoreErrors' => [
            // Doctrine ORM writes $id via reflection — not visible to static analysis
            ['message' => '#Property .+::\$id is never written, only read\.#', 'reportUnmatched' => false],
            ['message' => '#Property .+::\$id \(int\|null\) is never assigned int so it can be removed from the property type\.#', 'reportUnmatched' => false],
            // PHPStan extension code necessarily depends on non-BC-covered
            // reflection internals (DummyParameter) — same trade-off phpstan-doctrine
            // itself makes for the equivalent magic-method reflection extension.
            ['identifier' => 'phpstanApi.constructor', 'path' => 'src/PHPStan/*', 'reportUnmatched' => false],
            // phpstan-phpunit flags an assertInstanceOf() whose subject is already
            // of that type as redundant. In the suite these are deliberate: they
            // document the contract a factory or resolver is expected to honour,
            // so a later signature change surfaces as a failing assertion rather
            // than a silently weakened test. The extension's real value here is
            // narrowing after assertions and `method.impossibleType`, both of
            // which stay on.
            ['identifier' => 'method.alreadyNarrowedType', 'path' => 'tests/*', 'reportUnmatched' => false],
        ],
    ],
];
