<?php

$finder = (new PhpCsFixer\Finder())
    ->in(__DIR__.'/src')
    ->in(__DIR__.'/tests')
    ->in(__DIR__.'/config')
    ->exclude([
        'Unit/Util/fixtures',
        'Unit/Template/fixtures',
        'Unit/Toolkit/fixtures',
        'Unit/Data/fixtures',
        'Unit/Image/fixtures',
        'App/FlatFile/fixtures',
        'fixtures',
    ])
    ->notPath('Console/Resources/skeleton')
    ->append([__FILE__, __DIR__.'/bootstrap.php']);

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(false)
    ->setRules([
        '@PSR12' => true,
        '@Symfony' => true,
        'echo_tag_syntax' => ['format' => 'short'],
    ])
    ->setFinder($finder);
