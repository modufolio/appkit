<?php

namespace Modufolio\Appkit\Tests\Response;

use PHPUnit\Framework\Assert;
use Psr\Http\Message\ResponseInterface;

class TestResponse
{
    private ?array $jsonData = null;
    private bool $jsonParsed = false;

    public function __construct(protected ResponseInterface $response)
    {
    }

    public function getResponse(): ResponseInterface
    {
        return $this->response;
    }

    // ----------------------------
    // Status Assertions
    // ----------------------------

    public function assertStatus(int $expected): self
    {
        Assert::assertSame(
            $expected,
            $this->response->getStatusCode(),
            "Expected status {$expected}, got {$this->response->getStatusCode()}"
        );
        return $this;
    }

    public function assertRedirect(?string $uri = null): self
    {
        $status = $this->response->getStatusCode();
        Assert::assertTrue(
            in_array($status, [301, 302, 303, 307, 308]),
            "Expected redirect status code, got {$status}"
        );

        if ($uri !== null) {
            $this->assertHeader('Location', $uri);
        }

        return $this;
    }

    // ----------------------------
    // Header Assertions
    // ----------------------------

    public function assertHeader(string $name, string $expected): self
    {
        $actual = $this->response->getHeaderLine($name);
        Assert::assertSame(
            $expected,
            $actual,
            "Expected header '{$name}' to be '{$expected}', got '{$actual}'"
        );
        return $this;
    }

    // ----------------------------
    // Body Content Assertions
    // ----------------------------

    public function getContent(): string
    {
        $body = $this->response->getBody();
        $body->rewind(); // Ensure we're at the beginning of the stream
        return $body->getContents();
    }


    // ----------------------------
    // JSON Response Handling
    // ----------------------------

    public function jsonData(): array
    {
        if (!$this->jsonParsed) {
            $this->parseJsonData();
        }

        return $this->jsonData ?? [];
    }

    private function parseJsonData(): void
    {
        $this->jsonParsed = true;
        $body = $this->getContent();

        if (empty($body)) {
            $this->jsonData = [];
            return;
        }

        try {
            $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
            $this->jsonData = is_array($data) ? $data : [];
        } catch (\JsonException $e) {
            Assert::fail('Invalid JSON response: ' . $e->getMessage() . "\nBody: " . $body);
        }
    }



    // ----------------------------
    // Inertia.js Specific Assertions
    // ----------------------------

    public function assertInertia(): self
    {
        $data = $this->jsonData();

        Assert::assertArrayHasKey('component', $data,
            'Response is not an Inertia response. Expected "component" key in JSON. ' .
            'Response body: ' . $this->getContent()
        );

        Assert::assertArrayHasKey('props', $data,
            'Response is not an Inertia response. Expected "props" key in JSON. ' .
            'Response body: ' . $this->getContent()
        );

        return $this;
    }

    public function component(string $expected): self
    {
        $data = $this->jsonData();
        $actual = $data['component'] ?? null;
        Assert::assertSame($expected, $actual, "Expected Inertia component '{$expected}', got '{$actual}'");
        return $this;
    }

    public function hasProp(string $key): self
    {
        $data = $this->jsonData();
        $props = $data['props'] ?? [];
        Assert::assertArrayHasKey($key, $props, "Inertia prop '{$key}' is missing");
        return $this;
    }

    public function whereProp(string $key, $expected): self
    {
        $data = $this->jsonData();
        $props = $data['props'] ?? [];
        $actual = $this->arrayGet($props, $key);
        Assert::assertEquals($expected, $actual, "Inertia prop '{$key}' value mismatch");
        return $this;
    }

    public function propEquals(string $key, $expected): self
    {
        return $this->whereProp($key, $expected);
    }


    // ----------------------------
    // Utility Methods
    // ----------------------------

    protected function arrayGet(array $array, string $key, $default = null)
    {
        if (empty($key)) {
            return $array;
        }

        foreach (explode('.', $key) as $segment) {
            if (!is_array($array) || !array_key_exists($segment, $array)) {
                return $default;
            }
            $array = $array[$segment];
        }

        return $array;
    }

    public function dump(): self
    {
        echo 'Status: ' . $this->response->getStatusCode() . "\n";
        echo 'Headers: ' . json_encode($this->response->getHeaders()) . "\n";
        echo 'Body: ' . $this->getContent() . "\n";
        return $this;
    }

    public function dd(): void
    {
        $this->dump();
        exit(1);
    }
}
