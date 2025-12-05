<?php

declare(strict_types=1);

namespace Mike\Shsuggest;

use RuntimeException;

final class OllamaClient
{
    /**
     * @var array{eval_count:?int,eval_duration:?float,total_duration:?float}|null
     */
    private ?array $lastMetrics = null;

    public function __construct(
        private string $endpoint,
        private string $model,
        private float $temperature = 0.3,
        private int $timeout = 30,
        private ?int $numThreads = null
    ) {
        $this->endpoint = rtrim($this->endpoint, '/');
    }

    /**
     * @return Suggestion[]
     */
    public function suggest(string $prompt, int $count): array
    {
        $prompt = trim($prompt);
        if ($prompt === '') {
            throw new RuntimeException('Cannot request suggestions for an empty prompt.');
        }

        $instruction = $this->buildSuggestionPrompt($prompt, $count);
        $response = $this->generate($instruction);
        $decoded = $this->decodeJson($response, 'suggestions');

        if (!isset($decoded['suggestions']) || !is_array($decoded['suggestions'])) {
            throw new OllamaClientException('LLM response missing "suggestions" array.');
        }

        $suggestions = [];
        foreach ($decoded['suggestions'] as $item) {
            if (!is_array($item) || !isset($item['command'])) {
                continue;
            }

            $suggestions[] = new Suggestion(
                trim((string) $item['command']),
                isset($item['description']) ? trim((string) $item['description']) : ''
            );
        }

        if ($suggestions === []) {
            throw new OllamaClientException('No usable suggestions were returned by the LLM.');
        }

        return $suggestions;
    }

    public function explain(string $command): string
    {
        $command = trim($command);
        if ($command === '') {
            throw new RuntimeException('Cannot explain an empty command.');
        }

        $instruction = $this->buildExplainPrompt($command);
        $response = $this->generate($instruction);
        $decoded = $this->decodeJson($response, 'explanation');

        if (!isset($decoded['explanation'])) {
            throw new OllamaClientException('LLM response missing "explanation" field.');
        }

        return trim((string) $decoded['explanation']);
    }

    public function withTimeout(int $timeout): self
    {
        $timeout = max(1, $timeout);

        return new self(
            $this->endpoint,
            $this->model,
            $this->temperature,
            $timeout,
            $this->numThreads
        );
    }

    private function buildSuggestionPrompt(string $prompt, int $count): string
    {
        $count = max(1, $count);

        return <<<PROMPT
You generate shell commands for experienced terminal users.
Respond ONLY with valid JSON that matches this schema:
{
  "suggestions": [
    {
      "command": "one line shell command",
      "description": "short explanation"
    }
  ]
}
Create {$count} suggestions that satisfy the schema.
Keep commands concise, safe, and deterministic when possible.
Human prompt:
"""{$prompt}"""
PROMPT;
    }

    private function buildExplainPrompt(string $command): string
    {
        return <<<PROMPT
You explain shell commands clearly and safely.
Respond ONLY with valid JSON that matches this schema:
{
  "explanation": "plain language explanation"
}
Explain the following command and mention potential hazards:
"""{$command}"""
PROMPT;
    }

    private function generate(string $prompt): string
    {
        $payload = [
            'model' => $this->model,
            'prompt' => $prompt,
            'stream' => false,
            'options' => [
                'temperature' => $this->temperature,
            ],
        ];

        if ($this->numThreads !== null) {
            $payload['options']['num_thread'] = $this->numThreads;
        }

        $response = $this->post('/api/generate', $payload);
        $this->recordMetrics($response);

        if (!isset($response['response'])) {
            if (isset($response['error'])) {
                throw new OllamaClientException('Ollama error: ' . (string) $response['error']);
            }

            throw new OllamaClientException('Unexpected Ollama response payload.');
        }

        return (string) $response['response'];
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function post(string $path, array $payload): array
    {
        $url = $this->endpoint . $path;
        $body = json_encode($payload, JSON_THROW_ON_ERROR);

        if (!function_exists('curl_init')) {
            throw new OllamaClientException(
                'The cURL extension is required to contact Ollama. Please enable the "curl" PHP extension.'
            );
        }

        $result = $this->postWithCurl($url, $body);

        $decoded = json_decode($result, true);
        if (!is_array($decoded)) {
            throw new OllamaClientException('Failed to decode Ollama response: ' . $result);
        }

        return $decoded;
    }

    private function postWithCurl(string $url, string $body): string
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new OllamaClientException('Unable to initialize cURL.');
        }

        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json',
                'Content-Length: ' . strlen($body),
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
        ]);

        $result = curl_exec($ch);
        if ($result === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new OllamaClientException('cURL error while talking to Ollama: ' . $error);
        }

        $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($status >= 400) {
            throw new OllamaClientException(sprintf('Ollama returned HTTP %d: %s', $status, $result));
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJson(string $raw, string $expectedKey): array
    {
        foreach ($this->candidateJsonStrings($raw) as $candidate) {
            $decoded = $this->attemptJsonDecode($candidate);
            if ($decoded !== null) {
                return $decoded;
            }
        }

        throw new OllamaClientException(sprintf(
            'Failed to decode JSON with expected "%s" key. Raw response: %s',
            $expectedKey,
            $raw
        ));
    }

    public function getLastTokensPerSecond(?float $fallbackDuration = null): ?float
    {
        if ($this->lastMetrics === null) {
            return null;
        }

        $tokens = $this->lastMetrics['eval_count'] ?? null;
        if (!is_int($tokens) || $tokens <= 0) {
            return null;
        }

        $duration = $this->lastMetrics['eval_duration'] ?? null;
        if (!is_float($duration) || $duration <= 0) {
            $duration = $this->lastMetrics['total_duration'] ?? null;
        }

        if ((!is_float($duration) || $duration <= 0) && $fallbackDuration !== null && $fallbackDuration > 0) {
            $duration = $fallbackDuration;
        }

        if (!is_float($duration) || $duration <= 0) {
            return null;
        }

        return $tokens / $duration;
    }

    /**
     * @return string[]
     */
    public function listAvailableModels(): array
    {
        $response = $this->get('/api/tags');
        if (!isset($response['models']) || !is_array($response['models'])) {
            throw new OllamaClientException('Unexpected payload while listing Ollama models.');
        }

        $models = [];
        foreach ($response['models'] as $model) {
            if (is_array($model) && isset($model['name'])) {
                $models[] = (string) $model['name'];
            }
        }

        if ($models === []) {
            throw new OllamaClientException('Ollama did not report any installed models.');
        }

        sort($models, SORT_NATURAL | SORT_FLAG_CASE);

        return array_values(array_unique($models));
    }

    /**
     * @return string[]
     */
    private function candidateJsonStrings(string $raw): array
    {
        $raw = trim($raw);
        $candidates = [$raw];

        if (preg_match('/```[a-z0-9]*\s*(.*?)```/is', $raw, $match)) {
            $candidates[] = trim($match[1]);
        }

        foreach (['response', 'json'] as $tag) {
            $pattern = sprintf('/<%1$s>(.*?)<\/%1$s>/is', preg_quote($tag, '/'));
            if (preg_match($pattern, $raw, $match)) {
                $candidates[] = trim($match[1]);
            }
        }

        if (preg_match('/\{(?:[^{}]|(?R))*\}/s', $raw, $match)) {
            $candidates[] = trim($match[0]);
        }

        $candidates = array_values(array_unique(array_filter($candidates, static function (string $value): bool {
            return $value !== '';
        })));

        return $candidates === [] ? [$raw] : $candidates;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function attemptJsonDecode(string $candidate): ?array
    {
        $decoded = json_decode($candidate, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        $repaired = $this->repairJson($candidate);
        if ($repaired !== $candidate) {
            $decoded = json_decode($repaired, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return null;
    }

    private function repairJson(string $json): string
    {
        $pattern = '/("(?:\\\\.|[^"\\\\])*")(\s*)(?="(?:\\\\.|[^"\\\\])*"\s*:)/';
        $repaired = preg_replace($pattern, '$1,$2', $json);

        return $repaired ?? $json;
    }

    /**
     * @param array<string, mixed> $response
     */
    private function recordMetrics(array $response): void
    {
        if ($response === []) {
            $this->lastMetrics = null;

            return;
        }

        $this->lastMetrics = [
            'eval_count' => isset($response['eval_count']) ? (int) $response['eval_count'] : null,
            'eval_duration' => $this->normalizeDuration($response['eval_duration'] ?? null),
            'total_duration' => $this->normalizeDuration($response['total_duration'] ?? null),
        ];
    }

    private function normalizeDuration(mixed $value): ?float
    {
        if (!is_numeric($value)) {
            return null;
        }

        $seconds = (float) $value;
        if ($seconds <= 0) {
            return null;
        }

        // Ollama reports durations in nanoseconds, so divide to convert seconds when the value looks large.
        if ($seconds > 1000000) {
            $seconds /= 1000000000;
        }

        return $seconds;
    }

    /**
     * @return array<string, mixed>
     */
    private function get(string $path): array
    {
        $url = $this->endpoint . $path;

        if (!function_exists('curl_init')) {
            throw new OllamaClientException(
                'The cURL extension is required to contact Ollama. Please enable the "curl" PHP extension.'
            );
        }

        $result = $this->getWithCurl($url);
        $decoded = json_decode($result, true);
        if (!is_array($decoded)) {
            throw new OllamaClientException('Failed to decode Ollama response: ' . $result);
        }

        return $decoded;
    }

    private function getWithCurl(string $url): string
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new OllamaClientException('Unable to initialize cURL.');
        }

        curl_setopt_array($ch, [
            CURLOPT_HTTPGET => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
            ],
        ]);

        $result = curl_exec($ch);
        if ($result === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new OllamaClientException('cURL error while talking to Ollama: ' . $error);
        }

        $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($status >= 400) {
            throw new OllamaClientException(sprintf('Ollama returned HTTP %d: %s', $status, $result));
        }

        return $result;
    }
}
