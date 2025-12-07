<?php

declare(strict_types=1);

namespace Mike\Shsuggest;

use RuntimeException;
use Yosymfony\Toml\Exception\ParseException;
use Yosymfony\Toml\Toml;
use Yosymfony\Toml\TomlBuilder;

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
     * @return array<string, mixed>
     */
    public function loadValues(): array
    {
        $values = $this->parseConfigFile(failOnParseError: false);

        return $this->validateOptions($values);
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function saveValue(string $key, string|float|int|null $value): void
    {
        $values = $this->parseConfigFile(failOnParseError: true);

        $values = $this->storeValueByPath($values, $key, $value);

        $this->writeConfigValues($values);
    }

    /**
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    private function validateOptions(array $values): array
    {
        if (array_key_exists('num_suggestions', $values)) {
            $candidate = $values['num_suggestions'];
            if (
                is_int($candidate)
                || is_float($candidate)
                || is_string($candidate)
                || $candidate === null
            ) {
                $parsed = $this->validateNumSuggestions($candidate);
                if ($parsed === null) {
                    unset($values['num_suggestions']);
                } else {
                    $values['num_suggestions'] = $parsed;
                }
            } else {
                $this->warnInvalidOption($candidate, 'num_suggestions');
                unset($values['num_suggestions']);
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

    private function warnInvalidOption(mixed $value, string $option): void
    {
        $formattedValue = match (true) {
            is_int($value), is_float($value) => (string) $value,
            $value === null => 'null',
            is_string($value) => $value,
            is_array($value) => '[array]',
            is_bool($value) => $value ? 'true' : 'false',
            default => (string) $value,
        };

        $message = sprintf('⚠ Warning: %s is not valid for %s.', $formattedValue, $option);
        fwrite(STDERR, $message . PHP_EOL);
    }

    /**
     * @return array<string, mixed>
     */
    private function parseConfigFile(bool $failOnParseError): array
    {
        if (!is_file($this->path)) {
            return [];
        }

        if (!is_readable($this->path)) {
            if ($failOnParseError) {
                throw new RuntimeException(sprintf('Failed to read configuration file at %s.', $this->path));
            }

            return [];
        }

        try {
            $parsed = Toml::parseFile($this->path);

            return is_array($parsed) ? $parsed : [];
        } catch (ParseException $exception) {
            if ($failOnParseError) {
                $message = sprintf(
                    'Failed to parse configuration file at %s: %s',
                    $this->path,
                    $exception->getMessage()
                );

                throw new RuntimeException($message, 0, $exception);
            }

            $this->warnInvalidToml($exception);

            return [];
        }
    }

    /**
     * @param array<string, mixed> $values
     */
    private function writeConfigValues(array $values): void
    {
        $directory = dirname($this->path);
        if (!is_dir($directory)) {
            if (!@mkdir($directory, 0777, true) && !is_dir($directory)) {
                throw new RuntimeException(sprintf('Failed to create configuration directory: %s', $directory));
            }
        }

        $builder = new TomlBuilder(0);
        $this->dumpTomlValues($builder, $values, []);

        $contents = $builder->getTomlString();

        if (@file_put_contents($this->path, $contents) === false) {
            throw new RuntimeException(sprintf('Failed to write configuration file at %s.', $this->path));
        }
    }

    private function warnInvalidToml(ParseException $exception): void
    {
        $message = sprintf(
            '⚠ Warning: Unable to parse %s as TOML (%s). Defaults will be used.',
            $this->path,
            $exception->getMessage()
        );

        fwrite(STDERR, $message . PHP_EOL);
    }

    /**
     * @param array<string, mixed> $values
     * @param string[] $path
     */
    private function dumpTomlValues(TomlBuilder $builder, array $values, array $path): void
    {
        ksort($values);

        $scalars = [];
        $tables = [];

        foreach ($values as $key => $value) {
            if ($value === null) {
                continue;
            }

            if (is_array($value) && $this->isAssociativeArray($value)) {
                $tables[(string) $key] = $value;
            } else {
                $scalars[(string) $key] = $value;
            }
        }

        foreach ($scalars as $key => $value) {
            $builder->addValue($key, $value);
        }

        foreach ($tables as $key => $tableValues) {
            $tablePath = array_merge($path, [$key]);
            $builder->addTable(implode('.', $tablePath));
            $this->dumpTomlValues($builder, $tableValues, $tablePath);
        }
    }

    private function isAssociativeArray(array $values): bool
    {
        return array_keys($values) !== range(0, count($values) - 1);
    }

    /**
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    private function storeValueByPath(array $values, string $key, string|float|int|null $value): array
    {
        $segments = explode('.', $key);
        $segments = array_values(array_filter($segments, static fn ($segment) => $segment !== ''));

        if ($segments === []) {
            return $values;
        }

        $cursor = &$values;
        while (count($segments) > 1) {
            $segment = array_shift($segments);
            if (!isset($cursor[$segment]) || !is_array($cursor[$segment])) {
                $cursor[$segment] = [];
            }

            $cursor = &$cursor[$segment];
        }

        $finalKey = array_shift($segments);
        if ($value === null) {
            if (is_array($cursor) && array_key_exists($finalKey, $cursor)) {
                unset($cursor[$finalKey]);
            }

            $this->pruneEmptyTables($values);
        } else {
            $cursor[$finalKey] = $value;
        }

        return $values;
    }

    /**
     * @param array<string, mixed> $values
     */
    private function pruneEmptyTables(array &$values): void
    {
        foreach ($values as $key => &$candidate) {
            if (!is_array($candidate)) {
                continue;
            }

            $this->pruneEmptyTables($candidate);
            if ($candidate === []) {
                unset($values[$key]);
            }
        }

        unset($candidate);
    }
}
