<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\Unit\Data;

use Modufolio\Appkit\Data\Yaml;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Yaml::class)]
class YamlTest extends TestCase
{
    public function testEncodeDecode(): void
    {
        $array = [
            'name' => 'Homer',
            'children' => ['Lisa', 'Bart', 'Maggie'],
        ];

        $data = Yaml::encode($array);
        $this->assertSame(
            "name: Homer\nchildren:\n  - Lisa\n  - Bart\n  - Maggie\n",
            $data
        );

        $this->assertSame($array, Yaml::decode($data));
    }

    public function testEncodeEmptyArrayAsSequence(): void
    {
        $this->assertSame("items: []\n", Yaml::encode(['items' => []]));
    }

    public function testDecodeNull(): void
    {
        $this->assertSame([], Yaml::decode(null));
    }

    public function testDecodeEmptyString(): void
    {
        $this->assertSame([], Yaml::decode(''));
    }

    public function testDecodeArrayPassthrough(): void
    {
        $this->assertSame(['this is' => 'an array'], Yaml::decode(['this is' => 'an array']));
    }

    public function testDecodeScalarResultIsWrapped(): void
    {
        $this->assertSame(['scalar'], Yaml::decode('scalar'));
    }

    public function testDecodeInvalid(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid YAML data; please pass a string');

        Yaml::decode(new \stdClass());
    }
}
