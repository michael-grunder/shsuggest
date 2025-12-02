<?php

declare(strict_types=1);

namespace Mike\Shsuggest;

use RuntimeException;

final class OllamaClient
{
    public function __construct(
        private string $endpoint,
        private string $model,
        private float $temperature = 0.3,
        private int $timeout = 30
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

        $response = $this->post('/api/generate', $payload);

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

        if (function_exists('curl_init')) {
            $result = $this->postWithCurl($url, $body);
        } else {
            $result = $this->postWithStream($url, $body);
        }

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
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
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

    private function postWithStream(string $url, string $body): string
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\n",
                'content' => $body,
                'timeout' => $this->timeout,
            ],
        ]);

        $result = @file_get_contents($url, false, $context);
        if ($result === false) {
            $error = error_get_last();
            throw new OllamaClientException(
                'Network error while contacting Ollama' . ($error ? ': ' . $error['message'] : '.')
            );
        }

        $statusLine = $http_response_header[0] ?? '';
        if (!str_contains($statusLine, '200')) {
            throw new OllamaClientException('Ollama returned unexpected status: ' . $statusLine);
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJson(string $raw, string $expectedKey): array
    {
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new OllamaClientException(sprintf(
                'Failed to decode JSON with expected "%s" key. Raw response: %s',
                $expectedKey,
                $raw
            ));
        }

        return $decoded;
    }
}
