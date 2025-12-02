<?php

declare(strict_types=1);

namespace Mike\Shsuggest;

use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Formatter\OutputFormatterStyle;
use function Laravel\Prompts\select;

final class Application
{
    /**
     * Map logical style names to Symfony Console style definitions.
     *
     * @var array<string, array{0:string|null,1:string|null,2:array<int,string>}>
     */
    private const STYLE_DEFINITIONS = [
        'title' => ['cyan', null, ['bold']],
        'command' => ['green', null, ['bold']],
        'selected_command' => ['green', null, ['underscore']],
        'muted' => ['white', null, []],
        'accent' => ['magenta', null, ['bold']],
        'number' => ['blue', null, ['bold']],
        'error' => ['red', null, ['bold']],
    ];

    private PipeRunner $pipeRunner;
    private OutputFormatter $stdoutFormatter;
    private OutputFormatter $stderrFormatter;

    public function __construct(
        private Config $config,
        private OllamaClient $client
    ) {
        $this->pipeRunner = new PipeRunner();
        $this->stdoutFormatter = $this->createFormatter($this->isTty(STDOUT));
        $this->stderrFormatter = $this->createFormatter($this->isTty(STDERR));
    }

    public function run(array $argv): int
    {
        try {
            return $this->doRun($argv);
        } catch (\Throwable $e) {
            $label = $this->style('✖ Error', 'error', STDERR);
            fwrite(STDERR, $label . ': ' . $e->getMessage() . PHP_EOL);

            return 1;
        }
    }

    private function doRun(array $argv): int
    {
        $options = $this->parseArguments($argv);
        $mode = $options['mode'];
        $help = $options['help'];
        $args = $options['args'];
        $asJson = $options['json'];

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
            if ($asJson) {
                $this->writeJson([
                    'mode' => 'explain',
                    'command' => $command,
                    'explanation' => $explanation,
                ]);
            } else {
                $this->renderExplanation($command, $explanation);
            }

            return 0;
        }

        $prompt = $this->resolveInput($args, 'Describe what you want to do: ');
        if ($prompt === '') {
            throw new \RuntimeException('No prompt provided for suggestions.');
        }

        $requested = max(1, $options['num'] ?? $this->config->getNumSuggestions());
        $suggestions = $this->client->suggest($prompt, $requested);

        $pipeProgram = $this->config->getPipeProgram();
        if ($pipeProgram !== null && $this->isTty(STDOUT)) {
            $this->safePipe($pipeProgram, $suggestions[0]->getCommand());
        }

        if ($asJson) {
            $this->writeJson([
                'mode' => 'suggest',
                'prompt' => $prompt,
                'suggestions' => $this->suggestionsToArray($suggestions),
            ]);

            return 0;
        }

        $interactive = $this->isInteractive();
        $shouldPrompt = $interactive && $requested > 1;
        if ($requested > 1 && !$interactive) {
            $message = sprintf(
                '%s Multiple suggestions requested but no TTY detected; returning the first suggestion.',
                $this->style('ℹ', 'accent', STDERR)
            );
            fwrite(STDERR, $message . PHP_EOL);
        }

        $choice = $shouldPrompt ? $this->interactiveChoice($suggestions) : $suggestions[0];
        $deferredDescription = $this->renderSelectedSuggestion($choice, $shouldPrompt);

        if (!$this->isTty(STDOUT)) {
            $this->echoCommandToStderr($choice->getCommand());
            if ($deferredDescription !== null) {
                $this->writeDescription($deferredDescription);
            }
        }

        return 0;
    }

    /**
     * @param Suggestion[] $suggestions
     */
    private function interactiveChoice(array $suggestions): Suggestion
    {
        $choice = $this->selectInteractiveChoice($suggestions);
        if ($choice !== null) {
            return $choice;
        }

        return $this->legacyInteractiveChoice($suggestions);
    }

    /**
     * @param Suggestion[] $suggestions
     */
    private function selectInteractiveChoice(array $suggestions): ?Suggestion
    {
        try {
            $options = [];
            foreach ($suggestions as $index => $suggestion) {
                $options[(string) $index] = $this->formatInteractiveOption($index, $suggestion);
            }

            $selection = select(
                label: '✨ Suggestions',
                options: $options,
                default: '0',
                scroll: min(10, max(5, count($options))),
                hint: 'Use arrows or type a number, Enter to choose.',
            );

            if ($selection === null) {
                return $suggestions[0];
            }

            $choiceIndex = (int) $selection;

            return $suggestions[$choiceIndex] ?? $suggestions[0];
        } catch (\Throwable $exception) {
            return null;
        }
    }

    private function formatInteractiveOption(int $index, Suggestion $suggestion): string
    {
        $number = str_pad((string) ($index + 1), 2, ' ', STR_PAD_LEFT);
        $label = sprintf('%s ▸ %s', $number, $suggestion->getCommand());
        $description = $suggestion->getDescription();

        return $description !== ''
            ? $label . PHP_EOL . '    ↳ ' . $description
            : $label;
    }

    /**
     * @param Suggestion[] $suggestions
     */
    private function legacyInteractiveChoice(array $suggestions): Suggestion
    {
        fwrite(STDERR, PHP_EOL . $this->style('✨ Suggestions', 'title', STDERR) . PHP_EOL . PHP_EOL);

        foreach ($suggestions as $index => $suggestion) {
            $num = $index + 1;
            $desc = $suggestion->getDescription();
            $label = $this->style(str_pad((string) $num, 2, ' ', STR_PAD_LEFT), 'number', STDERR);
            $command = $this->style($suggestion->getCommand(), 'command', STDERR);
            fwrite(STDERR, sprintf(' %s %s %s', $label, '▸', $command) . PHP_EOL);
            if ($desc !== '') {
                $descText = $this->style($desc, 'muted', STDERR);
                fwrite(STDERR, sprintf('     %s %s', $this->style('↳', 'muted', STDERR), $descText) . PHP_EOL);
            }
        }

        $choice = null;
        $max = count($suggestions);
        while ($choice === null) {
            fwrite(
                STDERR,
                sprintf(
                    '%s Choose a suggestion [1-%d] (default 1): ',
                    $this->style('❯', 'accent', STDERR),
                    $max
                )
            );
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

            fwrite(STDERR, $this->style('Invalid selection.', 'error', STDERR) . PHP_EOL);
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
     * @return array{
     *     mode: string,
     *     help: bool,
     *     json: bool,
     *     num: ?int,
     *     args: array<int, string>
     * }
     */
    private function parseArguments(array $argv): array
    {
        $args = $argv;
        array_shift($args);

        $mode = 'suggest';
        $help = false;
        $json = false;
        $num = null;
        $remaining = [];
        $collect = false;

        while ($args !== []) {
            $arg = array_shift($args);
            if ($arg === null) {
                break;
            }

            if ($arg === '--') {
                $collect = true;
                continue;
            }

            if (!$collect) {
                if ($arg === '-h' || $arg === '--help') {
                    $help = true;
                    continue;
                }

                if ($arg === '-e' || $arg === '--explain') {
                    $mode = 'explain';
                    continue;
                }

                if ($arg === '--json' || $arg === '-j') {
                    $json = true;
                    continue;
                }

                if ($arg === '-n' || $arg === '--num') {
                    $value = array_shift($args);
                    if ($value === null) {
                        throw new \RuntimeException(sprintf('Option "%s" expects a value.', $arg));
                    }

                    $num = $this->parsePositiveIntOption($value, $arg);

                    continue;
                }

                if (preg_match('/^-n([0-9]+)$/', $arg, $matches)) {
                    $num = $this->parsePositiveIntOption($matches[1], '-n');
                    continue;
                }

                if (str_starts_with($arg, '--num=')) {
                    $value = substr($arg, 6);
                    $num = $this->parsePositiveIntOption($value, '--num');
                    continue;
                }
            }

            $collect = true;
            $remaining[] = $arg;
        }

        return [
            'mode' => $mode,
            'help' => $help,
            'json' => $json,
            'num' => $num,
            'args' => array_values($remaining),
        ];
    }

    private function printHelp(): void
    {
        $help = <<<HELP
shsuggest [OPTIONS] [PROMPT]

Options:
  -e, --explain   Explain the provided shell command instead of generating suggestions.
  -n, --num N     Request N suggestions (default 1). When N > 1 and a TTY is available, you'll be prompted to choose.
  -j, --json      Emit machine-readable JSON.
  -h, --help      Show this help message.

PROMPT or COMMAND values can also be provided via STDIN when omitted. By default only a single command is
printed so that it can be piped into other tooling. Pass -n greater than 1 from an interactive terminal to
browse multiple suggestions.
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
            $message = sprintf(
                '%s First suggestion piped into "%s".',
                $this->style('📤', 'accent', STDERR),
                $program
            );
            fwrite(STDERR, $message . PHP_EOL);
        } catch (\Throwable $exception) {
            $warning = sprintf('%s %s', $this->style('⚠ Warning:', 'error', STDERR), $exception->getMessage());
            fwrite(STDERR, $warning . PHP_EOL);
        }
    }

    private function parsePositiveIntOption(string $value, string $option): int
    {
        if (!ctype_digit($value)) {
            throw new \RuntimeException(sprintf('Option "%s" expects a positive integer.', $option));
        }

        $num = (int) $value;
        if ($num < 1) {
            throw new \RuntimeException(sprintf('Option "%s" expects a value greater than zero.', $option));
        }

        return $num;
    }

    private function renderExplanation(string $command, string $explanation): void
    {
        if ($this->isTty(STDERR)) {
            fwrite(STDERR, PHP_EOL . $this->style('🧠 Explanation', 'title', STDERR) . PHP_EOL);
            $line = sprintf(
                ' %s %s',
                $this->style('↳', 'muted', STDERR),
                $this->style($command, 'command', STDERR)
            );
            fwrite(STDERR, $line . PHP_EOL . PHP_EOL);
        }

        $this->writeLine($explanation);
    }

    private function renderSelectedSuggestion(Suggestion $suggestion, bool $fromInteractive): ?string
    {
        if ($fromInteractive && $this->isTty(STDERR)) {
            fwrite(STDERR, PHP_EOL . $this->style('✔ Selected suggestion', 'title', STDERR) . PHP_EOL);
        }

        $this->writeLine($this->style($suggestion->getCommand(), 'command', STDOUT));

        $description = $suggestion->getDescription();
        $shouldDeferDescription = !$this->isTty(STDOUT);
        if ($description !== '' && !$shouldDeferDescription) {
            $this->writeDescription($description);
        }

        return $shouldDeferDescription ? $description : null;
    }

    private function writeDescription(string $description): void
    {
        $line = sprintf(
            ' %s %s',
            $this->style('↳', 'muted', STDERR),
            $this->style($description, 'muted', STDERR)
        );
        fwrite(STDERR, $line . PHP_EOL);
    }

    private function echoCommandToStderr(string $command): void
    {
        $formatted = $this->style($command, 'command', STDERR);
        fwrite(STDERR, $formatted . PHP_EOL);
    }

    /**
     * @param Suggestion[] $suggestions
     *
     * @return array<int, array{command: string, description: string}>
     */
    private function suggestionsToArray(array $suggestions): array
    {
        $payload = [];
        foreach ($suggestions as $suggestion) {
            $payload[] = [
                'command' => $suggestion->getCommand(),
                'description' => $suggestion->getDescription(),
            ];
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function writeJson(array $payload): void
    {
        $json = json_encode(
            $payload,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
        );

        if ($json === false) {
            throw new \RuntimeException('Failed to encode JSON output.');
        }

        $this->write($json . PHP_EOL);
    }

    private function style(string $text, string $style, $stream): string
    {
        $formatter = $this->getFormatterForStream($stream);
        if ($formatter === null || !$formatter->isDecorated() || !$formatter->hasStyle($style)) {
            return $text;
        }

        return $formatter->format(sprintf('<%s>%s</>', $style, $text));
    }

    private function getFormatterForStream($stream): ?OutputFormatter
    {
        if ($stream === STDERR) {
            return $this->stderrFormatter;
        }

        if ($stream === STDOUT) {
            return $this->stdoutFormatter;
        }

        return null;
    }

    private function createFormatter(bool $decorated): OutputFormatter
    {
        $formatter = new OutputFormatter($decorated);

        foreach (self::STYLE_DEFINITIONS as $name => [$foreground, $background, $options]) {
            $formatter->setStyle($name, new OutputFormatterStyle($foreground, $background, $options));
        }

        return $formatter;
    }
}
