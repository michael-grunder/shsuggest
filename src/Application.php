<?php

declare(strict_types=1);

namespace Mike\Shsuggest;

final class Application
{
    private PipeRunner $pipeRunner;

    public function __construct(
        private Config $config,
        private OllamaClient $client
    ) {
        $this->pipeRunner = new PipeRunner();
    }

    public function run(array $argv): int
    {
        try {
            return $this->doRun($argv);
        } catch (\Throwable $e) {
            fwrite(STDERR, 'Error: ' . $e->getMessage() . PHP_EOL);

            return 1;
        }
    }

    private function doRun(array $argv): int
    {
        [$mode, $help, $args] = $this->parseArguments($argv);

        if ($help) {
            $this->printHelp();

            return 0;
        }

        if ($mode === 'explain') {
            $command = $this->resolveInput($args, 'Enter the shell command to explain: ');
            if ($command === '') {
                throw new \RuntimeException('No command provided to explain.');
            }
            $explanation = $this->client->explain($command);
            $this->writeLine($explanation);

            return 0;
        }

        $prompt = $this->resolveInput($args, 'Describe what you want to do: ');
        if ($prompt === '') {
            throw new \RuntimeException('No prompt provided for suggestions.');
        }
        $suggestions = $this->client->suggest($prompt, $this->config->getNumSuggestions());

        $pipeProgram = $this->config->getPipeProgram();
        if ($pipeProgram !== null) {
            $this->safePipe($pipeProgram, $suggestions[0]->getCommand());
        }

        if ($this->isInteractive()) {
            $choice = $this->interactiveChoice($suggestions);
            $this->writeLine($choice->getCommand());
            if ($choice->getDescription() !== '') {
                fwrite(STDERR, $choice->getDescription() . PHP_EOL);
            }
        } else {
            $this->writeLine($suggestions[0]->getCommand());
        }

        return 0;
    }

    /**
     * @param Suggestion[] $suggestions
     */
    private function interactiveChoice(array $suggestions): Suggestion
    {
        fwrite(STDERR, PHP_EOL . 'Suggestions:' . PHP_EOL);

        foreach ($suggestions as $index => $suggestion) {
            $num = $index + 1;
            $desc = $suggestion->getDescription();
            fwrite(STDERR, sprintf(' [%d] %s', $num, $suggestion->getCommand()) . PHP_EOL);
            if ($desc !== '') {
                fwrite(STDERR, sprintf('     %s', $desc) . PHP_EOL);
            }
        }

        $choice = null;
        $max = count($suggestions);
        while ($choice === null) {
            fwrite(STDERR, sprintf('Choose a suggestion [1-%d] (default 1): ', $max));
            $line = fgets(STDIN);
            if ($line === false) {
                break;
            }

            $line = trim($line);
            if ($line === '') {
                $choice = 1;
                break;
            }

            if (ctype_digit($line)) {
                $number = (int) $line;
                if ($number >= 1 && $number <= $max) {
                    $choice = $number;
                    break;
                }
            }

            fwrite(STDERR, 'Invalid selection.' . PHP_EOL);
        }

        return $suggestions[($choice ?? 1) - 1];
    }

    private function resolveInput(array $args, string $prompt): string
    {
        if (count($args) > 0) {
            return trim(implode(' ', $args));
        }

        if (!$this->isStdinTty()) {
            $input = stream_get_contents(STDIN) ?: '';

            return trim($input);
        }

        fwrite(STDERR, $prompt);
        $line = fgets(STDIN);

        return trim($line ?: '');
    }

    /**
     * @return array{string, bool, array<int, string>}
     */
    private function parseArguments(array $argv): array
    {
        $args = $argv;
        array_shift($args);

        $mode = 'suggest';
        $help = false;
        $remaining = [];
        $collect = false;

        foreach ($args as $arg) {
            if ($arg === '--') {
                $collect = true;
                continue;
            }

            if (!$collect && ($arg === '-h' || $arg === '--help')) {
                $help = true;
                continue;
            }

            if (!$collect && ($arg === '-e' || $arg === '--explain')) {
                $mode = 'explain';
                continue;
            }

            $collect = true;
            $remaining[] = $arg;
        }

        return [$mode, $help, $remaining];
    }

    private function printHelp(): void
    {
        $help = <<<HELP
shsuggest [OPTIONS] [PROMPT]

Options:
  -e, --explain   Explain the provided shell command instead of generating suggestions.
  -h, --help      Show this help message.

PROMPT or COMMAND values can also be provided via STDIN when omitted. When running in a TTY, multiple
suggestions are shown and you can interactively choose one. In non-interactive mode, only the first
suggestion is printed so it can be piped into other tooling.
HELP;

        $this->writeLine($help);
    }

    private function isInteractive(): bool
    {
        return $this->isTty(STDIN) && $this->isTty(STDOUT);
    }

    private function isStdinTty(): bool
    {
        return $this->isTty(STDIN);
    }

    private function isTty($stream): bool
    {
        if (function_exists('stream_isatty')) {
            return @stream_isatty($stream);
        }

        if (function_exists('posix_isatty')) {
            /** @var resource $stream */
            return @posix_isatty($stream);
        }

        return false;
    }

    private function write(string $message): void
    {
        fwrite(STDOUT, $message);
    }

    private function writeLine(string $message): void
    {
        $this->write($message . PHP_EOL);
    }

    private function safePipe(string $program, string $payload): void
    {
        try {
            $this->pipeRunner->pipe($program, $payload);
            fwrite(STDERR, sprintf('First suggestion piped into "%s".', $program) . PHP_EOL);
        } catch (\Throwable $exception) {
            fwrite(STDERR, 'Warning: ' . $exception->getMessage() . PHP_EOL);
        }
    }
}
