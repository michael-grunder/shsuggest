<?php

declare(strict_types=1);

namespace Mike\Shsuggest;

final class Config
{
    private const DEFAULT_SOURCE = 'ollama';
    private const OLLAMA_DEFAULT_ENDPOINT = 'http://127.0.0.1:11434';

    private const TOP_LEVEL_DEFAULTS = [
        'num_suggestions' => 1,
        'temperature' => 0.3,
        'num_thread' => null,
        'pipe_first_into' => null,
        'request_timeout' => 30,
        'source' => self::DEFAULT_SOURCE,
    ];

    private const SOURCE_DEFAULTS = [
        'ollama' => [
            'model' => 'gemma3',
            'endpoint' => self::OLLAMA_DEFAULT_ENDPOINT,
            'scheme' => 'http',
            'host' => '127.0.0.1',
            'port' => 11434,
        ],
        'copilot' => [
            'model' => 'copilot-cli',
            'binary' => 'copilot',
        ],
    ];

    /**
     * @var array<string, mixed>
     */
    private array $values = [];

    /**
     * @var array<string, array<string, mixed>>
     */
    private array $sourceSettings = [];

    /**
     * @param array<string, mixed> $values
     */
    public function __construct(array $values = [])
    {
        $this->initializeValues($values);
    }

    public function getModel(?string $source = null): string
    {
        $sourceName = $source ?? $this->getSource();
        $settings = $this->getSourceSettings($sourceName);
        $model = $settings['model'] ?? null;

        if (is_string($model) && trim($model) !== '') {
            return $model;
        }

        $sourceDefaults = self::SOURCE_DEFAULTS[$sourceName] ?? null;
        if (is_array($sourceDefaults)) {
            $default = $sourceDefaults['model'] ?? null;
            if (is_string($default) && $default !== '') {
                return $default;
            }
        }

        return (string) (self::SOURCE_DEFAULTS[self::DEFAULT_SOURCE]['model'] ?? '');
    }

    public function getSource(): string
    {
        $source = strtolower(trim((string) ($this->values['source'] ?? self::DEFAULT_SOURCE)));

        return $source === '' ? self::DEFAULT_SOURCE : $source;
    }

    /**
     * @return array<string, mixed>
     */
    public function getSourceSettings(string $source): array
    {
        $key = strtolower($source);

        if (isset($this->sourceSettings[$key])) {
            return $this->sourceSettings[$key];
        }

        return $this->sourceSettings[self::DEFAULT_SOURCE] ?? [];
    }

    /**
     * Legacy accessor preserved for compatibility.
     */
    public function getOllamaEndpoint(): string
    {
        $settings = $this->getSourceSettings('ollama');
        $endpoint = (string) ($settings['endpoint'] ?? self::OLLAMA_DEFAULT_ENDPOINT);

        return rtrim($endpoint, '/');
    }

    public function getNumSuggestions(): int
    {
        return max(1, (int) $this->values['num_suggestions']);
    }

    public function getTemperature(): float
    {
        return (float) $this->values['temperature'];
    }

    public function getNumThread(): ?int
    {
        $value = $this->values['num_thread'] ?? null;
        if ($value === null || $value === '') {
            return null;
        }

        $int = (int) $value;

        return $int > 0 ? $int : null;
    }

    public function getPipeProgram(): ?string
    {
        $value = $this->values['pipe_first_into'] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function getRequestTimeout(): int
    {
        return max(1, (int) $this->values['request_timeout']);
    }

    /**
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        return array_replace(
            self::TOP_LEVEL_DEFAULTS,
            [
                'ollama_endpoint' => self::OLLAMA_DEFAULT_ENDPOINT,
                'ollama.model' => self::SOURCE_DEFAULTS['ollama']['model'],
                'copilot.model' => self::SOURCE_DEFAULTS['copilot']['model'],
                'copilot.binary' => self::SOURCE_DEFAULTS['copilot']['binary'],
            ]
        );
    }

    /**
     * @param array<string, mixed> $values
     */
    private function initializeValues(array $values): void
    {
        $topLevel = [];
        $sources = [];
        $legacyModel = null;

        foreach ($values as $key => $value) {
            if (!is_string($key)) {
                continue;
            }

            if ($key === 'model') {
                if (is_string($value) && $value !== '') {
                    $legacyModel = $value;
                }

                continue;
            }

            if (array_key_exists($key, self::TOP_LEVEL_DEFAULTS) || $key === 'ollama_endpoint') {
                $topLevel[$key] = $value;
                continue;
            }

            if (is_array($value)) {
                $sources[strtolower($key)] = $this->filterSourceSettings($value);
            }
        }

        $this->values = array_replace(self::TOP_LEVEL_DEFAULTS, $topLevel);
        $this->sourceSettings = $this->mergeSourceDefaults($sources);

        $legacyEndpoint = $this->values['ollama_endpoint'] ?? null;
        if (is_string($legacyEndpoint) && $legacyEndpoint !== '') {
            $this->sourceSettings['ollama']['endpoint'] = rtrim($legacyEndpoint, '/');
        }

        if (is_string($legacyModel) && $legacyModel !== '') {
            $source = strtolower((string) ($this->values['source'] ?? self::DEFAULT_SOURCE));
            if ($source === '') {
                $source = self::DEFAULT_SOURCE;
            }

            if (!isset($this->sourceSettings[$source])) {
                $source = self::DEFAULT_SOURCE;
            }

            $this->sourceSettings[$source]['model'] = $legacyModel;
        }
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function mergeSourceDefaults(array $sources): array
    {
        $merged = [];

        foreach (self::SOURCE_DEFAULTS as $name => $defaults) {
            $merged[$name] = $defaults;
        }

        foreach ($sources as $name => $settings) {
            $lower = strtolower($name);
            $existing = $merged[$lower] ?? [];
            $merged[$lower] = array_replace($existing, $settings);
        }

        return $merged;
    }

    /**
     * @param array<string, mixed> $settings
     *
     * @return array<string, mixed>
     */
    private function filterSourceSettings(array $settings): array
    {
        $filtered = [];

        foreach ($settings as $key => $value) {
            if (is_string($key)) {
                $filtered[$key] = $value;
            }
        }

        return $filtered;
    }
}
