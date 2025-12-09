<?php

declare(strict_types=1);

namespace Mike\Shsuggest;

final class SuggestionResponse
{
    /**
     * @param Suggestion[] $suggestions
     */
    public function __construct(
        private array $suggestions,
        private ?string $normalizedPrompt = null
    ) {
    }

    /**
     * @return Suggestion[]
     */
    public function getSuggestions(): array
    {
        return $this->suggestions;
    }

    public function getNormalizedPrompt(): ?string
    {
        return $this->normalizedPrompt;
    }
}

