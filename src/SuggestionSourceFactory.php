<?php

declare(strict_types=1);

namespace Mike\Shsuggest;

final class SuggestionSourceFactory
{
    public function __construct(private Config $config)
    {
    }

    public function create(?string $source = null): SuggestionSource
    {
        $selected = $source ?? $this->config->getSource();

        return match ($selected) {
            'ollama' => $this->createOllamaSource(),
            'copilot' => $this->createCopilotSource(),
            'openai' => $this->createOpenAiSource(),
            'claude' => $this->createClaudeSource(),
            default => throw new \RuntimeException(sprintf('Unknown suggestion source "%s".', $selected)),
        };
    }

    private function createOllamaSource(): SuggestionSource
    {
        $settings = $this->config->getSourceSettings('ollama');
        $endpoint = $this->resolveOllamaEndpoint($settings);

        return new OllamaClient(
            $endpoint,
            $this->config->getModel('ollama'),
            $this->config->getTemperature(),
            $this->config->getRequestTimeout(),
            $this->config->getNumThread()
        );
    }

    private function createCopilotSource(): SuggestionSource
    {
        $settings = $this->config->getSourceSettings('copilot');
        $binary = $this->normalizeStringSetting($settings['binary'] ?? null, 'copilot');

        return new CopilotCliClient(
            $binary,
            $this->config->getModel('copilot'),
            $this->config->getRequestTimeout()
        );
    }

    private function createOpenAiSource(): SuggestionSource
    {
        $settings = $this->config->getSourceSettings('openai');
        $endpoint = $this->normalizeStringSetting($settings['endpoint'] ?? null, 'https://api.openai.com/v1');
        $apiKey = $this->normalizeOptionalString($settings['api_key'] ?? null);

        return new OpenAiClient(
            $endpoint,
            $apiKey,
            $this->config->getModel('openai'),
            $this->config->getTemperature(),
            $this->config->getRequestTimeout()
        );
    }

    private function createClaudeSource(): SuggestionSource
    {
        $settings = $this->config->getSourceSettings('claude');
        $endpoint = $this->normalizeStringSetting($settings['endpoint'] ?? null, 'https://api.anthropic.com');
        $apiKey = $this->normalizeOptionalString($settings['api_key'] ?? null);
        $version = $this->normalizeStringSetting($settings['anthropic_version'] ?? null, '2023-06-01');

        return new ClaudeClient(
            $endpoint,
            $apiKey,
            $this->config->getModel('claude'),
            $this->config->getTemperature(),
            $this->config->getRequestTimeout(),
            $version
        );
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function resolveOllamaEndpoint(array $settings): string
    {
        $endpoint = $settings['endpoint'] ?? null;
        if (is_string($endpoint) && trim($endpoint) !== '') {
            return rtrim($endpoint, '/');
        }

        $scheme = $this->normalizeScheme($settings['scheme'] ?? null);
        $host = $this->normalizeStringSetting($settings['host'] ?? null, '127.0.0.1');
        $port = $this->normalizePort($settings['port'] ?? null);

        return sprintf('%s://%s:%d', $scheme, $host, $port);
    }

    private function normalizeScheme(mixed $value): string
    {
        $candidate = strtolower($this->normalizeStringSetting($value, 'http'));

        return in_array($candidate, ['http', 'https'], true) ? $candidate : 'http';
    }

    private function normalizeStringSetting(mixed $value, string $default): string
    {
        if (is_string($value)) {
            $trimmed = trim($value);

            if ($trimmed !== '') {
                return $trimmed;
            }
        }

        return $default;
    }

    private function normalizePort(mixed $value): int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }

        if (is_string($value) && ctype_digit($value)) {
            $int = (int) $value;

            if ($int > 0) {
                return $int;
            }
        }

        return 11434;
    }

    private function normalizeOptionalString(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
