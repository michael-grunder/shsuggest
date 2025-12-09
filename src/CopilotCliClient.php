<?php

declare(strict_types=1);

namespace Mike\Shsuggest;

use RuntimeException;

final class CopilotCliClient implements SuggestionSource
{
    private SystemContextProvider $systemContextProvider;

    public function __construct(
        private string $binary,
        private string $model,
        private int $timeout = 30,
        ?SystemContextProvider $systemContextProvider = null
    ) {
        $trimmed = trim($binary);
        if ($trimmed === '') {
            throw new RuntimeException('Copilot CLI binary path cannot be empty.');
        }

        $this->binary = $trimmed;
        $this->systemContextProvider = $systemContextProvider ?? new SystemContextProvider();
    }

    /**
     * @return SuggestionResponse
     */
    public function suggest(string $prompt, int $count): SuggestionResponse
    {
        $instruction = $this->renderSuggestionPrompt($prompt, $count);
        $response = $this->runCopilotCommand($instruction);
        $decoded = $this->decodeJson($response, 'suggestions');

        if (!isset($decoded['suggestions']) || !is_array($decoded['suggestions'])) {
            throw new CopilotCliException('LLM response missing "suggestions" array.');
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
            throw new CopilotCliException('No usable suggestions were returned by the LLM.');
        }

        $normalizedPrompt = $this->resolveNormalizedPrompt($decoded, $prompt);

        return new SuggestionResponse($suggestions, $normalizedPrompt);
    }

    public function explain(string $command): string
    {
        $instruction = $this->renderExplainPrompt($command);
        $response = $this->runCopilotCommand($instruction);
        $decoded = $this->decodeJson($response, 'explanation');

        if (!isset($decoded['explanation'])) {
            throw new CopilotCliException('LLM response missing "explanation" field.');
        }

        return trim((string) $decoded['explanation']);
    }

    public function withTimeout(int $timeout): SuggestionSource
    {
        return new self(
            $this->binary,
            $this->model,
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

    /**
     * @return string[]
     */
    public function listAvailableModels(): array
    {
        $model = trim($this->model);

        return $model === '' ? ['copilot-cli'] : [$model];
    }

    public function getLastTokensPerSecond(?float $fallbackDuration = null): ?float
    {
        return null;
    }

    private function runCopilotCommand(string $prompt): string
    {
        $command = sprintf('exec %s -p %s', escapeshellarg($this->binary), escapeshellarg($prompt));
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = @proc_open($command, $descriptorSpec, $pipes);
        if (!is_resource($process)) {
            throw new CopilotCliException(sprintf('Failed to start Copilot CLI using "%s".', $this->binary));
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';
        $deadline = microtime(true) + $this->timeout;
        $openStreams = [1 => $pipes[1], 2 => $pipes[2]];
        $timedOut = false;

        while ($openStreams !== []) {
            $timeLeft = $deadline - microtime(true);
            if ($timeLeft <= 0) {
                $timedOut = true;
                break;
            }

            $read = array_values($openStreams);
            $write = null;
            $except = null;
            $seconds = (int) floor($timeLeft);
            $microseconds = (int) (($timeLeft - $seconds) * 1000000);

            $ready = @stream_select($read, $write, $except, $seconds, $microseconds);
            if ($ready === false) {
                break;
            }

            if ($ready === 0) {
                continue;
            }

            foreach ($read as $stream) {
                $chunk = fread($stream, 8192);
                if ($chunk === false || $chunk === '') {
                    if (feof($stream)) {
                        foreach ($openStreams as $key => $candidate) {
                            if ($candidate === $stream) {
                                fclose($candidate);
                                unset($openStreams[$key]);
                                break;
                            }
                        }
                    }

                    continue;
                }

                if ($stream === $pipes[1]) {
                    $stdout .= $chunk;
                } else {
                    $stderr .= $chunk;
                }
            }
        }

        foreach ($openStreams as $stream) {
            fclose($stream);
        }

        if ($timedOut) {
            proc_terminate($process, 9);
            proc_close($process);

            throw new CopilotCliException(sprintf(
                'Copilot CLI timed out after %d seconds.',
                $this->timeout
            ));
        }

        $exitCode = proc_close($process);
        if ($exitCode !== 0) {
            $message = trim($stderr);
            if ($message === '') {
                $message = 'Unknown Copilot CLI error.';
            }

            throw new CopilotCliException(sprintf(
                'Copilot CLI exited with code %d: %s',
                $exitCode,
                $message
            ));
        }

        return $stdout;
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
  "explanation": "plain language explanation"
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

        throw new CopilotCliException(sprintf(
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
}
