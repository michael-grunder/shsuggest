<?php

declare(strict_types=1);

namespace Mike\Shsuggest;

final class Suggestion
{
    public function __construct(
        private string $command,
        private string $description = ''
    ) {
    }

    public function getCommand(): string
    {
        return $this->command;
    }

    public function getDescription(): string
    {
        return $this->description;
    }
}
