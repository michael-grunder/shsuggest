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
     * @return array<string, string|float|int|null>
     */
    public function loadValues(): array
    {
        $values = $this->parseConfigFile(failOnParseError: false);

        $scalarsOnly = [];
        foreach ($values as $key => $value) {
            if (is_int($value) || is_float($value) || is_string($value) || $value === null) {
                $scalarsOnly[$key] = $value;
            }
        }

        $scalarsOnly = $this->validateOptions($scalarsOnly);

        return $scalarsOnly;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function saveValue(string $key, string|float|int|null $value): void
    {
        $values = $this->parseConfigFile(failOnParseError: true);

        if ($value === null) {
            unset($values[$key]);
        } else {
            $values[$key] = $value;
        }

        $this->writeConfigValues($values);
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

        ksort($values);

        $builder = new TomlBuilder(0);

        foreach ($values as $key => $value) {
            if ($value === null) {
                continue;
            }

            if (is_int($value) || is_float($value) || is_string($value) || is_bool($value)) {
                $builder->addValue($key, $value);
            }
        }

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

}
