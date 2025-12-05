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
        return new Config($this->loadValues());
    }

    /**
     * @return array<string, string|float|int|null>
     */
    public function loadValues(): array
    {
        if (!is_readable($this->path)) {
            return [];
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

        $values = $this->validateOptions($values);

        return $values;
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

    /**
     * @param array<string, string|float|int|null> $values
     * @return array<string, string|float|int|null>
     */
    private function validateOptions(array $values): array
    {
        if (array_key_exists('num_suggestions', $values)) {
            $parsed = $this->validateNumSuggestions($values['num_suggestions']);
            if ($parsed === null) {
                unset($values['num_suggestions']);
            } else {
                $values['num_suggestions'] = $parsed;
            }
        }

        return $values;
    }

    private function validateNumSuggestions(string|float|int|null $value): ?int
    {
        if (is_int($value)) {
            $num = $value;
        } elseif (is_float($value)) {
            $num = (int) $value;
            if ((float) $num !== $value) {
                $num = null;
            }
        } elseif (is_string($value) && ctype_digit($value)) {
            $num = (int) $value;
        } else {
            $num = null;
        }

        if ($num === null || $num < 1) {
            $this->warnInvalidOption($value, 'num_suggestions');

            return null;
        }

        return $num;
    }

    private function warnInvalidOption(string|float|int|null $value, string $option): void
    {
        $formattedValue = match (true) {
            is_int($value), is_float($value) => (string) $value,
            $value === null => 'null',
            default => (string) $value,
        };

        $message = sprintf('⚠ Warning: %s is not valid for %s.', $formattedValue, $option);
        fwrite(STDERR, $message . PHP_EOL);
    }
}
