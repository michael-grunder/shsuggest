<?php

declare(strict_types=1);

namespace Mike\Shsuggest;

final class Explanation
{
    /**
     * @param array<int, array{component: string, detail: string}> $breakdown
     * @param string[] $hazards
     */
    public function __construct(
        private string $summary,
        private array $breakdown = [],
        private array $hazards = [],
        private ?string $notes = null
    ) {
        $summary = trim($summary);
        $this->summary = $summary === '' ? 'No explanation was provided.' : $summary;
        $this->breakdown = $this->sanitizeBreakdown($breakdown);
        $this->hazards = $this->sanitizeHazards($hazards);
        $this->notes = $this->normalizeOptionalString($notes);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function fromArray(array $payload): self
    {
        if (isset($payload['explanation']) && is_string($payload['explanation'])) {
            return self::fromLegacyText($payload['explanation']);
        }

        $summary = isset($payload['summary']) && is_string($payload['summary'])
            ? trim($payload['summary'])
            : '';
        $breakdown = isset($payload['breakdown']) && is_array($payload['breakdown'])
            ? array_values($payload['breakdown'])
            : [];
        $hazards = isset($payload['hazards']) && is_array($payload['hazards'])
            ? array_values($payload['hazards'])
            : [];
        $notes = isset($payload['notes']) && is_string($payload['notes'])
            ? trim($payload['notes'])
            : null;

        if ($summary === '' && $notes !== null) {
            $summary = $notes;
            $notes = null;
        }

        return new self($summary, $breakdown, $hazards, $notes);
    }

    public static function fromLegacyText(string $explanation): self
    {
        return new self($explanation);
    }

    public function getSummary(): string
    {
        return $this->summary;
    }

    /**
     * @return array<int, array{component: string, detail: string}>
     */
    public function getBreakdown(): array
    {
        return $this->breakdown;
    }

    /**
     * @return string[]
     */
    public function getHazards(): array
    {
        return $this->hazards;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    private function normalizeOptionalString(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * @param array<int, mixed> $items
     *
     * @return array<int, array{component: string, detail: string}>
     */
    private function sanitizeBreakdown(array $items): array
    {
        $clean = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $component = '';
            if (isset($item['component']) && is_string($item['component'])) {
                $component = trim($item['component']);
            } elseif (isset($item['part']) && is_string($item['part'])) {
                $component = trim($item['part']);
            }

            $detail = '';
            if (isset($item['detail']) && is_string($item['detail'])) {
                $detail = trim($item['detail']);
            } elseif (isset($item['purpose']) && is_string($item['purpose'])) {
                $detail = trim($item['purpose']);
            }

            if ($component === '' && $detail === '') {
                continue;
            }

            $component = $component === '' ? 'Command' : $component;
            $detail = $detail === '' ? $component : $detail;

            $clean[] = [
                'component' => $component,
                'detail' => $detail,
            ];
        }

        return $clean;
    }

    /**
     * @param array<int, mixed> $entries
     *
     * @return string[]
     */
    private function sanitizeHazards(array $entries): array
    {
        $clean = [];
        foreach ($entries as $entry) {
            if (!is_string($entry)) {
                continue;
            }

            $entry = trim($entry);
            if ($entry !== '') {
                $clean[] = $entry;
            }
        }

        return $clean;
    }
}
