<?php

declare(strict_types=1);

namespace Mike\Shsuggest;

use RuntimeException;

final class HistoryStore
{
    private const DEFAULT_FILENAME = '.shsuggest_history';

    private ?string $path;

    public function __construct(?string $path = null)
    {
        if ($path === null) {
            $home = getenv('HOME') ?: getenv('USERPROFILE') ?: null;
            if ($home !== null && $home !== '') {
                $path = rtrim($home, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . self::DEFAULT_FILENAME;
            }
        }

        $this->path = $path;
    }

    public function isAvailable(): bool
    {
        return $this->path !== null;
    }

    public function getPath(): string
    {
        if ($this->path === null) {
            throw new RuntimeException('Unable to determine home directory for the history file.');
        }

        return $this->path;
    }

    /**
     * @param array<string, mixed> $entry
     */
    public function append(array $entry): void
    {
        if ($this->path === null) {
            return;
        }

        $directory = dirname($this->path);
        if (!is_dir($directory)) {
            if (!@mkdir($directory, 0777, true) && !is_dir($directory)) {
                throw new RuntimeException(sprintf('Failed to create history directory: %s', $directory));
            }
        }

        $payload = json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($payload === false) {
            throw new RuntimeException('Failed to encode history entry.');
        }

        if (@file_put_contents($this->path, $payload . PHP_EOL, FILE_APPEND | LOCK_EX) === false) {
            throw new RuntimeException(sprintf('Failed to write history file at %s.', $this->path));
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function loadEntries(): array
    {
        $path = $this->getPath();

        if (!is_file($path)) {
            return [];
        }

        if (!is_readable($path)) {
            throw new RuntimeException(sprintf('Failed to read history file at %s.', $path));
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            throw new RuntimeException(sprintf('Failed to read history file at %s.', $path));
        }

        $entries = [];
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '') {
                continue;
            }

            $decoded = json_decode($trimmed, true);
            if (!is_array($decoded)) {
                continue;
            }

            if (!isset($decoded['command']) || !is_string($decoded['command'])) {
                continue;
            }

            $entries[] = $decoded;
        }

        return $entries;
    }
}
