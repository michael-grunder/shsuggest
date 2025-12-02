<?php

declare(strict_types=1);

namespace Mike\Shsuggest;

use Symfony\Component\Console\Exception\InvalidArgumentException as ConsoleInvalidArgumentException;
use Symfony\Component\Console\Exception\RuntimeException as ConsoleRuntimeException;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Formatter\OutputFormatterStyle;
use Symfony\Component\Console\Helper\DescriptorHelper;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\StreamOutput;
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

    private const DEFAULT_WIDGET_BINDING = '\C-g';

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
        $version = $options['version'];
        $args = $options['args'];
        $asJson = $options['json'];
        $shellIntegration = $options['shell'];

        if ($version) {
            $this->printVersion();

            return 0;
        }

        if ($help) {
            $this->printHelp();

            return 0;
        }

        if ($mode === 'widget') {
            $binding = $options['widget_binding'] ?? self::DEFAULT_WIDGET_BINDING;
            $shell = $options['widget_shell'];
            if ($shell === null || $shell === '') {
                throw new \RuntimeException('Please specify the target shell (bash or zsh).');
            }

            $this->emitShellWidget($binding, $shell, $argv);

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

        if ($shellIntegration && $mode !== 'suggest') {
            throw new \RuntimeException('--shell can only be used when requesting suggestions.');
        }

        if ($shellIntegration && $this->isShellWidgetInvocation()) {
            $this->announceShellWidgetInvocation();
        }

        $requested = $shellIntegration
            ? 1
            : max(1, $options['num'] ?? $this->config->getNumSuggestions());
        $suggestions = $this->client->suggest($prompt, $requested);
        $pipeProgram = $shellIntegration ? null : $this->config->getPipeProgram();

        if ($asJson) {
            if ($pipeProgram !== null && $this->isTty(STDOUT)) {
                $this->safePipe($pipeProgram, $suggestions[0]->getCommand());
            }

            $this->writeJson([
                'mode' => 'suggest',
                'prompt' => $prompt,
                'suggestions' => $this->suggestionsToArray($suggestions),
            ]);

            return 0;
        }

        $interactive = $shellIntegration ? false : $this->isInteractive();
        $shouldPrompt = $interactive && $requested > 1;
        if ($requested > 1 && !$interactive) {
            $message = sprintf(
                '%s Multiple suggestions requested but no TTY detected; returning the first suggestion.',
                $this->style('ℹ', 'accent', STDERR)
            );
            fwrite(STDERR, $message . PHP_EOL);
        }

        $choice = $shouldPrompt ? $this->interactiveChoice($suggestions) : $suggestions[0];

        if ($shellIntegration) {
            $this->writeLine($choice->getCommand());

            return 0;
        }

        if ($pipeProgram !== null && $this->isTty(STDOUT)) {
            $this->safePipe($pipeProgram, $choice->getCommand());
        }

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
                $options[] = $this->formatInteractiveOption($index, $suggestion);
            }

            $selection = select(
                label: '✨ Suggestions',
                options: $options,
                default: $options[0] ?? null,
                scroll: min(10, max(5, count($options))),
                hint: 'Use arrows or type a number, Enter to choose.',
            );

            if ($selection === null) {
                return $suggestions[0];
            }

            $choiceIndex = array_search($selection, $options, true);
            if ($choiceIndex === false) {
                if (is_int($selection)) {
                    $choiceIndex = $selection;
                } elseif (is_string($selection)) {
                    $trimmedSelection = trim($selection);
                    if ($trimmedSelection !== '' && ctype_digit($trimmedSelection)) {
                        $choiceIndex = (int) $trimmedSelection - 1;
                    }
                }
            }

            if (!is_int($choiceIndex) || !isset($suggestions[$choiceIndex])) {
                $choiceIndex = 0;
            }

            return $suggestions[$choiceIndex];
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
     *     shell: bool,
     *     widget_binding: ?string,
     *     widget_shell: ?string,
     *     args: array<int, string>
     * }
     */
    private function parseArguments(array $argv): array
    {
        $definition = $this->createInputDefinition();
        $mode = 'suggest';
        $help = false;
        $json = false;
        $version = false;
        $num = null;
        $shellIntegration = false;
        $widgetBinding = null;
        $widgetShell = null;

        try {
            $input = new ArgvInput($argv, $definition);
        } catch (ConsoleRuntimeException | ConsoleInvalidArgumentException $exception) {
            throw new \RuntimeException($exception->getMessage(), 0, $exception);
        }

        /** @var list<string> $remaining */
        $remaining = $input->getArgument('args');

        $help = (bool) $input->getOption('help');
        $json = (bool) $input->getOption('json');
        $shellIntegration = (bool) ($input->getOption('shell') || $input->getOption('shell-integration'));
        $version = (bool) $input->getOption('version');

        $hasWidgetOption = $input->hasParameterOption('--widget');
        $isExplain = (bool) $input->getOption('explain');
        if ($hasWidgetOption && $isExplain) {
            throw new \RuntimeException('The --widget option cannot be combined with --explain.');
        }

        $widgetOptionValue = $input->getOption('widget');
        if ($hasWidgetOption) {
            $mode = 'widget';
            $bindingProvidedInline = $this->widgetBindingProvidedInline($input);
            if ($bindingProvidedInline) {
                $widgetBinding = $widgetOptionValue === null ? null : (string) $widgetOptionValue;
            } elseif ($widgetOptionValue !== null) {
                array_unshift($remaining, (string) $widgetOptionValue);
            }
        } elseif ($isExplain) {
            $mode = 'explain';
        }

        $numOption = $input->getOption('num');
        if ($numOption !== null) {
            $num = $this->parsePositiveIntOption((string) $numOption, $this->detectNumOptionName($input));
        }

        if ($mode === 'widget') {
            $widgetShell = $remaining[0] ?? null;
            $remaining = array_slice($remaining, 1);
        }

        return [
            'mode' => $mode,
            'help' => $help,
            'version' => $version,
            'json' => $json,
            'num' => $num,
            'shell' => $shellIntegration,
            'widget_binding' => $widgetBinding,
            'widget_shell' => $widgetShell,
            'args' => array_values($remaining),
        ];
    }

    private function printHelp(): void
    {
        $output = new StreamOutput(
            STDOUT,
            StreamOutput::VERBOSITY_NORMAL,
            $this->isTty(STDOUT),
            $this->stdoutFormatter
        );

        $output->writeln('<info>Usage:</info>');
        $output->writeln('  shsuggest [options] [--] [PROMPT]');
        $output->writeln('');
        $output->writeln('Generate shell suggestions from a prompt or explain an existing command.');
        $output->writeln('');

        $descriptor = new DescriptorHelper();
        $descriptor->describe($output, $this->createInputDefinition());

        $output->writeln('');
        $output->writeln('PROMPT or COMMAND values can also be provided via STDIN when omitted.');
        $output->writeln('Pass -n greater than 1 from an interactive terminal to browse suggestions interactively.');
    }

    private function printVersion(): void
    {
        $this->writeLine(sprintf('shsuggest %s', Version::CURRENT));
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
                '%s Selected suggestion piped into "%s".',
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

    private function createInputDefinition(): InputDefinition
    {
        return new InputDefinition([
            new InputArgument(
                'args',
                InputArgument::IS_ARRAY,
                'Prompt or command tokens. Use -- to treat subsequent values literally when they start with "-".'
            ),
            new InputOption('help', 'h', InputOption::VALUE_NONE, 'Show this help message.'),
            new InputOption('version', 'V', InputOption::VALUE_NONE, 'Show application version.'),
            new InputOption('explain', 'e', InputOption::VALUE_NONE, 'Explain the provided shell command instead of generating suggestions.'),
            new InputOption('json', 'j', InputOption::VALUE_NONE, 'Emit machine-readable JSON.'),
            new InputOption('num', 'n', InputOption::VALUE_REQUIRED, 'Request N suggestions (default comes from the config file).'),
            new InputOption('shell', null, InputOption::VALUE_NONE, 'Emit only the selected suggestion for shell integration widgets.'),
            new InputOption('shell-integration', null, InputOption::VALUE_NONE, 'Alias for --shell (deprecated).'),
            new InputOption(
                'widget',
                null,
                InputOption::VALUE_OPTIONAL,
                sprintf(
                    'Print a ready-to-use shell widget for bash or zsh. Provide the shell name as the final argument and optionally override the key binding (default %s).',
                    self::DEFAULT_WIDGET_BINDING
                )
            ),
        ]);
    }

    private function detectNumOptionName(ArgvInput $input): string
    {
        $option = '--num';
        foreach ($input->getRawTokens() as $token) {
            if (str_starts_with($token, '--num')) {
                $option = '--num';
                continue;
            }

            if (str_starts_with($token, '-n')) {
                $option = '-n';
            }
        }

        return $option;
    }

    private function widgetBindingProvidedInline(ArgvInput $input): bool
    {
        foreach ($input->getRawTokens() as $token) {
            if (str_starts_with($token, '--widget=')) {
                return true;
            }
        }

        return false;
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

    private function isShellWidgetInvocation(): bool
    {
        return getenv('SHSUGGEST_WIDGET') !== false;
    }

    private function announceShellWidgetInvocation(): void
    {
        $message = $this->style("\n[shsuggest] Thinking...", 'muted', STDERR);
        fwrite(STDERR, $message . PHP_EOL);
    }

    private function emitShellWidget(string $binding, string $shell, array $argv): void
    {
        $binding = $binding !== '' ? $binding : self::DEFAULT_WIDGET_BINDING;
        $binary = $this->resolveBinaryPath($argv[0] ?? null);
        $shell = strtolower($shell);

        if ($shell === 'zsh') {
            $widget = $this->renderZshWidget($binary, $binding);
        } elseif ($shell === 'bash') {
            $widget = $this->renderBashWidget($binary, $binding);
        } else {
            throw new \RuntimeException(sprintf('Unsupported shell "%s". Expected "bash" or "zsh".', $shell));
        }

        $this->writeLine($widget);
    }

    private function renderBashWidget(string $binary, string $binding): string
    {
        $escapedBinding = $this->escapeForDoubleQuotes($binding);

        $widget = <<<'BASH'
# shsuggest readline widget
_shsuggest_widget() {
    local current cmd
    current=$READLINE_LINE
    cmd="$(SHSUGGEST_WIDGET=1 __BINARY__ --shell -- "$current")" || return
    READLINE_LINE=$cmd
    READLINE_POINT=${#READLINE_LINE}
}

bind -x '"__BINDING__":"_shsuggest_widget"'
BASH;

        return str_replace(['__BINARY__', '__BINDING__'], [$binary, $escapedBinding], $widget);
    }

    private function renderZshWidget(string $binary, string $binding): string
    {
        $binding = $this->normalizeZshBinding($binding);
        $escapedBinding = $this->escapeForDoubleQuotes($binding);

        $widget = <<<'ZSH'
# shsuggest zle widget
_shsuggest_widget() {
    local buffer cmd
    buffer=$BUFFER
    cmd="$(SHSUGGEST_WIDGET=1 __BINARY__ --shell -- "$buffer")" || return
    BUFFER=$cmd
    CURSOR=${#BUFFER}
    zle reset-prompt
}

zle -N shsuggest-widget _shsuggest_widget
bindkey "__BINDING__" shsuggest-widget
ZSH;

        return str_replace(['__BINARY__', '__BINDING__'], [$binary, $escapedBinding], $widget);
    }

    private function resolveBinaryPath(?string $binary): string
    {
        $binary = $binary ?? 'shsuggest';
        if ($binary === '') {
            $binary = 'shsuggest';
        }

        $resolved = @realpath($binary);
        if ($resolved !== false) {
            $binary = $resolved;
        }

        return escapeshellarg($binary);
    }

    private function escapeForDoubleQuotes(string $value): string
    {
        return str_replace('"', '\"', $value);
    }

    private function normalizeZshBinding(string $binding): string
    {
        if ($binding === '') {
            return '';
        }

        if (strlen($binding) === 4 && strncasecmp($binding, '\C-', 3) === 0) {
            $char = $binding[3];
            if (ctype_alpha($char)) {
                return '^' . strtoupper($char);
            }
        }

        return $binding;
    }
}
