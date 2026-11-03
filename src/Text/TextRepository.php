<?php

declare(strict_types=1);

namespace App\Text;

final class TextRepository
{
    /** @param array<string, mixed> $data */
    private function __construct(private readonly array $data)
    {
    }

    public static function fromFile(string $path): self
    {
        if (!is_file($path)) {
            throw new \RuntimeException('Texts file not found: ' . $path);
        }

        $json = file_get_contents($path);
        $data = json_decode($json ?: '', true, flags: JSON_THROW_ON_ERROR);

        if (!is_array($data)) {
            throw new \RuntimeException('Invalid texts JSON');
        }

        return new self($data);
    }

    public function get(string $key, array $replace = []): string
    {
        $value = $this->resolve($key);
        if (!is_string($value)) {
            throw new \InvalidArgumentException('Text key not found or not a string: ' . $key);
        }

        foreach ($replace as $placeholder => $replacement) {
            $value = str_replace('{' . $placeholder . '}', (string) $replacement, $value);
        }

        return $value;
    }

    /**
     * @return mixed
     */
    private function resolve(string $key): mixed
    {
        $parts = explode('.', $key);
        $current = $this->data;

        foreach ($parts as $part) {
            if (!is_array($current) || !array_key_exists($part, $current)) {
                throw new \InvalidArgumentException('Text key not found: ' . $key);
            }
            $current = $current[$part];
        }

        return $current;
    }
}
