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

    public function saveValue(string $key, string|float|int|null $value): void
    {
        $lines = $this->readConfigLines();
        $lineIndex = $this->findConfigLineIndex($lines, $key);

        if ($value === null) {
            if ($lineIndex === null) {
                return;
            }

            unset($lines[$lineIndex]);
            $this->writeConfigLines($lines);

            return;
        }

        $newLine = sprintf('%s=%s', $key, $this->stringifyValue($value));
        if ($lineIndex === null) {
            $lines[] = $newLine;
        } else {
            $lines[$lineIndex] = $newLine;
        }

        $this->writeConfigLines($lines);
    }

    /**
     * @return string[]
     */
    private function readConfigLines(): array
    {
        if (!is_file($this->path)) {
            return [];
        }

        if (!is_readable($this->path)) {
            throw new RuntimeException(sprintf('Failed to read configuration file at %s.', $this->path));
        }

        $lines = @file($this->path, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            throw new RuntimeException(sprintf('Failed to read configuration file at %s.', $this->path));
        }

        return $lines;
    }

    /**
     * @param string[] $lines
     */
    private function findConfigLineIndex(array $lines, string $key): ?int
    {
        foreach ($lines as $index => $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || $trimmed[0] === '#' || $trimmed[0] === ';') {
                continue;
            }

            $equalPos = strpos($trimmed, '=');
            if ($equalPos === false) {
                continue;
            }

            $lineKey = trim(substr($trimmed, 0, $equalPos));
            if (strcasecmp($lineKey, $key) === 0) {
                return $index;
            }
        }

        return null;
    }

    /**
     * @param string[] $lines
     */
    private function writeConfigLines(array $lines): void
    {
        $directory = dirname($this->path);
        if (!is_dir($directory)) {
            if (!@mkdir($directory, 0777, true) && !is_dir($directory)) {
                throw new RuntimeException(sprintf('Failed to create configuration directory: %s', $directory));
            }
        }

        $contents = implode(PHP_EOL, $lines);
        if ($contents !== '') {
            $contents .= PHP_EOL;
        }

        if (@file_put_contents($this->path, $contents) === false) {
            throw new RuntimeException(sprintf('Failed to write configuration file at %s.', $this->path));
        }
    }

    private function stringifyValue(string|float|int $value): string
    {
        if (is_float($value)) {
            $formatted = rtrim(rtrim(sprintf('%.10F', $value), '0'), '.');

            return $formatted === '' ? '0' : $formatted;
        }

        return (string) $value;
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
