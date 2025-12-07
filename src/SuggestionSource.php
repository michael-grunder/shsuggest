<?php

declare(strict_types=1);

namespace Mike\Shsuggest;

interface SuggestionSource
{
    /**
     * @return Suggestion[]
     */
    public function suggest(string $prompt, int $count): array;

    public function explain(string $command): string;

    public function withTimeout(int $timeout): self;

    public function renderSuggestionPrompt(string $prompt, int $count): string;

    public function renderExplainPrompt(string $command): string;

    /**
     * @return string[]
     */
    public function listAvailableModels(): array;

    public function getLastTokensPerSecond(?float $fallbackDuration = null): ?float;
}
