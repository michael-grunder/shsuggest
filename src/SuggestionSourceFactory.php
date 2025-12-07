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
}
