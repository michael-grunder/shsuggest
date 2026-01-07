<?php

declare(strict_types=1);

namespace Mike\Shsuggest;

use RuntimeException;

final class OpenAiClient implements SuggestionSource
{
    private SystemContextProvider $systemContextProvider;
    private ?int $lastCompletionTokens = null;
    private ?float $lastRequestDuration = null;

    public function __construct(
        private string $endpoint,
        private ?string $apiKey,
        private string $model,
        private float $temperature = 0.3,
        private int $timeout = 30,
        ?SystemContextProvider $systemContextProvider = null
    ) {
        $this->endpoint = rtrim($endpoint, '/');
        $this->apiKey = $this->trimmedOrNull($apiKey);
        $this->systemContextProvider = $systemContextProvider ?? new SystemContextProvider();
    }

    public function suggest(string $prompt, int $count): SuggestionResponse
    {
        $instruction = $this->renderSuggestionPrompt($prompt, $count);
        $content = $this->sendChatCompletion($instruction);
        $decoded = $this->decodeJson($content, 'suggestions');

        if (!isset($decoded['suggestions']) || !is_array($decoded['suggestions'])) {
            throw new OpenAiClientException('LLM response missing "suggestions" array.');
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
            throw new OpenAiClientException('No usable suggestions were returned by the LLM.');
        }

        $normalizedPrompt = $this->resolveNormalizedPrompt($decoded, $prompt);

        return new SuggestionResponse($suggestions, $normalizedPrompt);
    }

    public function explain(string $command): Explanation
    {
        $instruction = $this->renderExplainPrompt($command);
        $content = $this->sendChatCompletion($instruction);
        $decoded = $this->decodeJson($content, 'summary');

        return Explanation::fromArray($decoded);
    }

    public function withTimeout(int $timeout): SuggestionSource
    {
        return new self(
            $this->endpoint,
            $this->apiKey,
            $this->model,
            $this->temperature,
            max(1, $timeout),
            $this->systemContextProvider
        );
    }

    public function renderSuggestionPrompt(string $prompt, int $count): string
    {
        $prompt = trim($prompt);
        if ($prompt === '') {
            throw new RuntimeException('Cannot request suggestions for an empty prompt.');
        }

        return $this->buildSuggestionPrompt($prompt, $count);
    }

    public function renderExplainPrompt(string $command): string
    {
        $command = trim($command);
        if ($command === '') {
            throw new RuntimeException('Cannot explain an empty command.');
        }

        return $this->buildExplainPrompt($command);
    }

    public function listAvailableModels(): array
    {
        $response = $this->get('/models');
        if (!isset($response['data']) || !is_array($response['data'])) {
            throw new OpenAiClientException('Unexpected payload while listing OpenAI models.');
        }

        $models = [];
        foreach ($response['data'] as $entry) {
            if (is_array($entry) && isset($entry['id'])) {
                $models[] = (string) $entry['id'];
            }
        }

        if ($models === []) {
            throw new OpenAiClientException('OpenAI did not report any models.');
        }

        sort($models, SORT_NATURAL | SORT_FLAG_CASE);

        return array_values(array_unique($models));
    }

    public function getLastTokensPerSecond(?float $fallbackDuration = null): ?float
    {
        $tokens = $this->lastCompletionTokens;
        if ($tokens === null || $tokens <= 0) {
            return null;
        }

        $duration = $this->lastRequestDuration;
        if (($duration === null || $duration <= 0) && $fallbackDuration !== null && $fallbackDuration > 0) {
            $duration = $fallbackDuration;
        }

        if ($duration === null || $duration <= 0) {
            return null;
        }

        return $tokens / $duration;
    }

    private function sendChatCompletion(string $prompt): string
    {
        $payload = [
            'model' => $this->model,
            'temperature' => $this->temperature,
            'messages' => [
                ['role' => 'user', 'content' => $prompt],
            ],
        ];

        $startedAt = microtime(true);
        $response = $this->post('/chat/completions', $payload);
        $this->recordUsage($response, microtime(true) - $startedAt);

        if (!isset($response['choices'][0]['message']['content'])) {
            throw new OpenAiClientException('OpenAI response was missing content.');
        }

        return (string) $response['choices'][0]['message']['content'];
    }

    /**
     * @param array<string, mixed> $response
     */
    private function recordUsage(array $response, float $duration): void
    {
        $usage = $response['usage'] ?? null;
        if (!is_array($usage)) {
            $this->lastCompletionTokens = null;
            $this->lastRequestDuration = null;

            return;
        }

        $tokens = null;
        foreach (['completion_tokens', 'output_tokens', 'total_tokens'] as $key) {
            if (isset($usage[$key]) && is_numeric($usage[$key])) {
                $tokens = (int) $usage[$key];
                break;
            }
        }

        if ($tokens === null || $tokens <= 0 || $duration <= 0) {
            $this->lastCompletionTokens = null;
            $this->lastRequestDuration = null;

            return;
        }

        $this->lastCompletionTokens = $tokens;
        $this->lastRequestDuration = $duration;
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function post(string $path, array $payload): array
    {
        $body = json_encode($payload, JSON_THROW_ON_ERROR);

        return $this->request('POST', $path, $body);
    }

    /**
     * @return array<string, mixed>
     */
    private function get(string $path): array
    {
        return $this->request('GET', $path, null);
    }

    /**
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, ?string $body): array
    {
        if (!function_exists('curl_init')) {
            throw new OpenAiClientException(
                'The cURL extension is required to contact OpenAI. Please enable the "curl" PHP extension.'
            );
        }

        $url = $this->buildUrl($path);
        $ch = curl_init($url);
        if ($ch === false) {
            throw new OpenAiClientException('Unable to initialize cURL.');
        }

        $headers = [
            'Accept: application/json',
            'Authorization: Bearer ' . $this->resolveApiKey(),
        ];

        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
            $headers[] = 'Content-Length: ' . strlen($body);
        }

        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_HTTPHEADER => $headers,
        ];

        if (strtoupper($method) === 'GET') {
            $options[CURLOPT_HTTPGET] = true;
        } else {
            $options[CURLOPT_CUSTOMREQUEST] = strtoupper($method);
            if ($body !== null) {
                $options[CURLOPT_POSTFIELDS] = $body;
            }
        }

        curl_setopt_array($ch, $options);

        $result = curl_exec($ch);
        if ($result === false) {
            $error = curl_error($ch);
            curl_close($ch);

            throw new OpenAiClientException('cURL error while talking to OpenAI: ' . $error);
        }

        $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($status >= 400) {
            throw new OpenAiClientException(sprintf('OpenAI returned HTTP %d: %s', $status, $result));
        }

        $decoded = json_decode($result, true);
        if (!is_array($decoded)) {
            throw new OpenAiClientException('Failed to decode OpenAI response: ' . $result);
        }

        return $decoded;
    }

    private function buildUrl(string $path): string
    {
        $base = rtrim($this->endpoint, '/');

        return $base . '/' . ltrim($path, '/');
    }

    private function resolveApiKey(): string
    {
        $candidates = [
            $this->apiKey,
            getenv('OPENAI_API_KEY') ?: null,
            $_SERVER['OPENAI_API_KEY'] ?? null,
            $_ENV['OPENAI_API_KEY'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (!is_string($candidate)) {
                continue;
            }

            $trimmed = trim($candidate);
            if ($trimmed !== '') {
                return $trimmed;
            }
        }

        throw new OpenAiClientException(
            'Missing OpenAI API key. Set openai.api_key in ~/.shsuggest or the OPENAI_API_KEY environment variable.'
        );
    }

    private function buildSuggestionPrompt(string $prompt, int $count): string
    {
        $count = max(1, $count);
        $contextBlock = '';
        $systemContext = trim($this->systemContextProvider->describe());
        if ($systemContext !== '') {
            $contextBlock = "System context:\n{$systemContext}\n\n";
        }

        return <<<PROMPT
You generate shell commands for experienced terminal users.
Respond ONLY with valid JSON that matches this schema:
{
  "normalized_prompt": "human prompt rewritten clearly",
  "suggestions": [
    {
      "command": "one line shell command",
      "description": "short explanation"
    }
  ]
}
Create {$count} suggestions that satisfy the schema.
Keep commands concise, safe, and deterministic when possible.
Rewrite the human prompt into normalized_prompt, fixing typos and grammar without changing intent.
Respect the system context, especially when commands differ across shells or operating systems.
{$contextBlock}Human prompt:
"""{$prompt}"""
PROMPT;
    }

    private function buildExplainPrompt(string $command): string
    {
        return <<<PROMPT
You explain shell commands clearly and safely.
Respond ONLY with valid JSON that matches this schema:
{
  "summary": "1-2 sentence overview in plain language",
  "breakdown": [
    {
      "component": "piece of the command",
      "detail": "what that piece does"
    }
  ],
  "hazards": [
    "risk, safety concern, or caveat"
  ],
  "notes": "optional extra tips"
}
Explain the following command and mention potential hazards:
"""{$command}"""
PROMPT;
    }

    /**
     * @param array<string, mixed> $decoded
     */
    private function resolveNormalizedPrompt(array $decoded, string $fallback): string
    {
        $candidate = isset($decoded['normalized_prompt'])
            ? trim((string) $decoded['normalized_prompt'])
            : '';
        $fallback = trim($fallback);

        return $candidate !== '' ? $candidate : $fallback;
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

        throw new OpenAiClientException(sprintf(
            'Failed to decode JSON with expected "%s" key. Raw response: %s',
            $expectedKey,
            $raw
        ));
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

    private function trimmedOrNull(?string $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
