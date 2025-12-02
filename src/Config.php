<?php

declare(strict_types=1);

namespace Mike\Shsuggest;

final class Config
{
    private const DEFAULTS = [
        'model' => 'gemma3',
        'ollama_endpoint' => 'http://127.0.0.1:11434',
        'num_suggestions' => 1,
        'temperature' => 0.3,
        'num_thread' => null,
        'pipe_first_into' => null,
        'request_timeout' => 30,
    ];

    /**
     * @param array<string, mixed> $values
     */
    public function __construct(private array $values = [])
    {
        $this->values = array_replace(self::DEFAULTS, $values);
    }

    public function getModel(): string
    {
        return (string) $this->values['model'];
    }

    public function getOllamaEndpoint(): string
    {
        return rtrim((string) $this->values['ollama_endpoint'], '/');
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
        return self::DEFAULTS;
    }
}
