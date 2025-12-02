<?php

declare(strict_types=1);

namespace Mike\Shsuggest;

use RuntimeException;

final class ConfigLoader
{
    private const DOTFILE = '.shsuggest';

    public function __construct(private ?string $path = null)
    {
        if ($this->path === null) {
            $home = getenv('HOME') ?: getenv('USERPROFILE') ?: null;
            if ($home === null) {
                throw new RuntimeException('Unable to determine home directory for configuration file.');
            }

            $this->path = rtrim($home, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . self::DOTFILE;
        }
    }

    public function load(): Config
    {
        if (!is_readable($this->path)) {
            return new Config(Config::defaults());
        }

        $lines = file($this->path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        $values = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || str_starts_with($line, ';')) {
                continue;
            }

            if (!str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = array_map('trim', explode('=', $line, 2));
            if ($key === '') {
                continue;
            }

            $values[$key] = $this->normalizeValue($value);
        }

        return new Config($values);
    }

    private function normalizeValue(string $value): string|float|int|null
    {
        if (is_numeric($value)) {
            return str_contains($value, '.') ? (float) $value : (int) $value;
        }

        $lower = strtolower($value);
        if (in_array($lower, ['null', 'none'], true)) {
            return null;
        }

        return trim($value, "\"'");
    }

    public function getPath(): string
    {
        return $this->path;
    }
}
