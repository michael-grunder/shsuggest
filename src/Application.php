<?php

declare(strict_types=1);

namespace Mike\Shsuggest;

final class Application
{
    private const STYLE_CODES = [
        'title' => '1;36',
        'command' => '1;32',
        'selected_command' => '4;32',
        'muted' => '37',
        'accent' => '35',
        'number' => '1;34',
        'error' => '1;31',
    ];

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
        $choice = $this->enhancedInteractiveChoice($suggestions);
        if ($choice !== null) {
            return $choice;
        }

        return $this->legacyInteractiveChoice($suggestions);
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

    /**
     * @param Suggestion[] $suggestions
     */
    private function enhancedInteractiveChoice(array $suggestions): ?Suggestion
    {
        if (!$this->supportsEnhancedSelection()) {
            return null;
        }

        $originalState = $this->enterRawMode();
        if ($originalState === null) {
            return null;
        }

        $this->hideCursor();

        $selected = 0;

        try {
            $this->printInteractiveSuggestionHeading();
            $canRestoreCursor = $this->saveCursorPosition();
            $lineCount = $this->renderInteractiveSuggestionBlock($suggestions, $selected);
            $max = count($suggestions);
            $buffer = '';

            while (true) {
                $key = $this->readKeyPress();
                if ($key === null) {
                    break;
                }

                if ($key === 'ENTER') {
                    break;
                }

                if ($key === 'UP') {
                    $selected = ($selected - 1 + $max) % $max;
                    $buffer = '';
                    $lineCount = $this->rerenderInteractiveSuggestionBlock(
                        $suggestions,
                        $selected,
                        $lineCount,
                        $canRestoreCursor
                    );

                    continue;
                }

                if ($key === 'DOWN') {
                    $selected = ($selected + 1) % $max;
                    $buffer = '';
                    $lineCount = $this->rerenderInteractiveSuggestionBlock(
                        $suggestions,
                        $selected,
                        $lineCount,
                        $canRestoreCursor
                    );

                    continue;
                }

                if ($key === 'ESC') {
                    $selected = 0;
                    break;
                }

                if ($key === 'BACKSPACE') {
                    $buffer = '';
                    continue;
                }

                if (strlen($key) === 1 && ctype_digit($key)) {
                    $buffer .= $key;
                    $number = (int) $buffer;
                    if ($number < 1 || $number > $max) {
                        $buffer = $key;
                        $number = (int) $buffer;
                    }

                    if ($number >= 1 && $number <= $max) {
                        $selected = $number - 1;
                        $lineCount = $this->rerenderInteractiveSuggestionBlock(
                            $suggestions,
                            $selected,
                            $lineCount,
                            $canRestoreCursor
                        );
                    }

                    continue;
                }

                $buffer = '';
            }
        } finally {
            $this->showCursor();
            $this->restoreTerminalState($originalState);
        }

        return $suggestions[$selected];
    }

    /**
     * @param Suggestion[] $suggestions
     */
    private function renderInteractiveSuggestionBlock(array $suggestions, int $selected): int
    {
        $lines = 0;

        foreach ($suggestions as $index => $suggestion) {
            $count = $index + 1;
            $num = $this->style(str_pad((string) $count, 2, ' ', STR_PAD_LEFT), 'number', STDERR);
            $pointer = $index === $selected
                ? $this->style('▸', 'accent', STDERR)
                : $this->style('▹', 'muted', STDERR);
            $commandStyle = $index === $selected ? 'selected_command' : 'command';
            $command = $this->style($suggestion->getCommand(), $commandStyle, STDERR);
            fwrite(STDERR, sprintf(' %s %s %s', $num, $pointer, $command) . PHP_EOL);
            $lines++;

            $desc = $suggestion->getDescription();
            if ($desc !== '') {
                $descColor = $index === $selected ? 'accent' : 'muted';
                $descText = $this->style($desc, $descColor, STDERR);
                $arrow = $this->style('↳', 'muted', STDERR);
                fwrite(STDERR, sprintf('     %s %s', $arrow, $descText) . PHP_EOL);
                $lines++;
            }
        }

        fwrite(STDERR, PHP_EOL);
        $lines++;

        $instruction = sprintf(
            '%s Use ↑/↓ arrows or numbers, Enter to choose (default 1).',
            $this->style('❯', 'accent', STDERR)
        );
        fwrite(STDERR, $instruction . PHP_EOL);
        $lines++;

        return $lines;
    }

    private function printInteractiveSuggestionHeading(): void
    {
        fwrite(STDERR, PHP_EOL);
        fwrite(STDERR, $this->style('✨ Suggestions', 'title', STDERR) . PHP_EOL);
        fwrite(STDERR, PHP_EOL);
    }

    private function saveCursorPosition(): bool
    {
        if (!$this->supportsAnsi(STDERR)) {
            return false;
        }

        fwrite(STDERR, "\033[s");

        return true;
    }

    private function restoreCursorPosition(): bool
    {
        if (!$this->supportsAnsi(STDERR)) {
            return false;
        }

        fwrite(STDERR, "\033[u");

        return true;
    }

    /**
     * @param Suggestion[] $suggestions
     */
    private function rerenderInteractiveSuggestionBlock(
        array $suggestions,
        int $selected,
        int $linesPrinted,
        bool $canRestoreCursor
    ): int
    {
        if ($canRestoreCursor && $this->restoreCursorPosition()) {
            fwrite(STDERR, "\033[J");
            $this->saveCursorPosition();
        } elseif ($linesPrinted > 0) {
            fwrite(STDERR, sprintf("\033[%dF", $linesPrinted));
            fwrite(STDERR, "\033[J");
        }

        return $this->renderInteractiveSuggestionBlock($suggestions, $selected);
    }

    private function readKeyPress(): ?string
    {
        $char = @fread(STDIN, 1);
        if ($char === false || $char === '') {
            return null;
        }

        if ($char === "\r" || $char === "\n") {
            return 'ENTER';
        }

        if ($char === "\033") {
            $next = @fread(STDIN, 1);
            if ($next === false || $next === '') {
                return 'ESC';
            }

            if ($next === '[') {
                $direction = @fread(STDIN, 1);
                if ($direction === 'A') {
                    return 'UP';
                }

                if ($direction === 'B') {
                    return 'DOWN';
                }
            }

            return null;
        }

        if ($char === "\177") {
            return 'BACKSPACE';
        }

        return $char;
    }

    private function supportsEnhancedSelection(): bool
    {
        if (DIRECTORY_SEPARATOR !== '/' || !function_exists('shell_exec')) {
            return false;
        }

        return $this->isTty(STDIN) && $this->isTty(STDERR);
    }

    private function enterRawMode(): ?string
    {
        if (DIRECTORY_SEPARATOR !== '/' || !function_exists('shell_exec')) {
            return null;
        }

        $current = @shell_exec('stty -g');
        if ($current === null) {
            return null;
        }

        $current = trim($current);
        if ($current === '') {
            return null;
        }

        @shell_exec('stty -icanon -echo min 1 time 0');

        register_shutdown_function(function () use ($current): void {
            $this->restoreTerminalState($current);
        });

        return $current;
    }

    private function restoreTerminalState(?string $state): void
    {
        if ($state === null || DIRECTORY_SEPARATOR !== '/' || !function_exists('shell_exec')) {
            return;
        }

        @shell_exec(sprintf('stty %s', escapeshellarg($state)));
    }

    private function hideCursor(): void
    {
        if ($this->supportsAnsi(STDERR)) {
            fwrite(STDERR, "\033[?25l");
        }
    }

    private function showCursor(): void
    {
        if ($this->supportsAnsi(STDERR)) {
            fwrite(STDERR, "\033[?25h");
        }
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
        if (!isset(self::STYLE_CODES[$style]) || !$this->supportsAnsi($stream)) {
            return $text;
        }

        return sprintf("\033[%sm%s\033[0m", self::STYLE_CODES[$style], $text);
    }

    private function supportsAnsi($stream): bool
    {
        return $this->isTty($stream);
    }
}
