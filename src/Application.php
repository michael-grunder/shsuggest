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
    private ConfigLoader $configLoader;

    public function __construct(
        private Config $config,
        private OllamaClient $client,
        ?ConfigLoader $configLoader = null
    ) {
        $this->configLoader = $configLoader ?? new ConfigLoader();
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
        $showConfig = $options['show_config'];
        $shellIntegration = $options['shell'];
        $dryRun = $options['dry-run'];
        $timeoutOverride = $options['timeout'];

        if ($timeoutOverride !== null) {
            $this->client = $this->client->withTimeout($timeoutOverride);
        }

        if ($version) {
            $this->printVersion();

            return 0;
        }

        if ($help) {
            $this->printHelp();

            return 0;
        }

        if ($showConfig) {
            $this->printConfigSettings();

            return 0;
        }

        if ($mode === 'config') {
            return $this->runConfigCommand($args);
        }

        if ($dryRun && $mode !== 'suggest') {
            throw new \RuntimeException('--dry-run can only be used when requesting suggestions.');
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
        $generationStartedAt = microtime(true);
        $suggestions = $dryRun
            ? $this->generateDryRunSuggestions($prompt, $requested)
            : $this->client->suggest($prompt, $requested);
        $generationDuration = microtime(true) - $generationStartedAt;
        $tokensPerSecond = $dryRun
            ? null
            : $this->client->getLastTokensPerSecond($generationDuration);
        $pipeProgram = $shellIntegration ? null : $this->config->getPipeProgram();
        $model = $this->config->getModel();

        if ($asJson) {
            if ($pipeProgram !== null && $this->isTty(STDOUT)) {
                $this->safePipe($pipeProgram, $suggestions[0]->getCommand());
            }

            $this->writeJson([
                'mode' => 'suggest',
                'model' => $model,
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

        $modelDisplayHandled = false;
        if ($shouldPrompt) {
            $label = $this->buildSuggestionsLabel($model, $generationDuration, $tokensPerSecond);
            $choice = $this->interactiveChoice($suggestions, $label);
            if (trim($model) !== '') {
                $modelDisplayHandled = true;
            }
        } else {
            $choice = $suggestions[0];
        }

        if ($shellIntegration) {
            $this->writeLine($choice->getCommand());

            return 0;
        }

        if (!$shouldPrompt && $requested === 1) {
            $this->announceModelUsage($model, $generationDuration, $tokensPerSecond);
            $modelDisplayHandled = true;
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

        if (!$modelDisplayHandled) {
            $this->announceModelUsage($model, $generationDuration, $tokensPerSecond);
        }

        return 0;
    }

    /**
     * @param Suggestion[] $suggestions
     */
    private function interactiveChoice(array $suggestions, string $label): Suggestion
    {
        $choice = $this->selectInteractiveChoice($suggestions, $label);
        if ($choice !== null) {
            return $choice;
        }

        return $this->legacyInteractiveChoice($suggestions, $label);
    }

    private function buildSuggestionsLabel(string $model, ?float $elapsedSeconds, ?float $tokensPerSecond): string
    {
        $trimmedModel = trim($model);
        if ($trimmedModel === '') {
            return '✨ Suggestions';
        }

        $label = sprintf('✨ %s Suggestions', $trimmedModel);
        $formattedTime = $this->formatGenerationStats($elapsedSeconds, $tokensPerSecond);

        return $formattedTime === null
            ? $label
            : sprintf('%s (%s)', $label, $formattedTime);
    }

    /**
     * @param Suggestion[] $suggestions
     */
    private function selectInteractiveChoice(array $suggestions, string $label): ?Suggestion
    {
        try {
            $options = [];
            foreach ($suggestions as $index => $suggestion) {
                $options[] = $this->formatInteractiveOption($index, $suggestion);
            }

            $selection = select(
                label: $label,
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
    private function legacyInteractiveChoice(array $suggestions, string $label): Suggestion
    {
        fwrite(STDERR, PHP_EOL . $this->style($label, 'title', STDERR) . PHP_EOL . PHP_EOL);

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
     * @return Suggestion[]
     */
    private function generateDryRunSuggestions(string $prompt, int $count): array
    {
        $summary = $this->summarizePromptForDryRun($prompt);
        $count = max(1, $count);
        $suggestions = [];

        for ($i = 1; $i <= $count; $i++) {
            $command = sprintf('echo %s', escapeshellarg(sprintf('[dry-run] #%d %s', $i, $summary)));
            $description = sprintf('Dummy suggestion %d for "%s".', $i, $summary);
            $suggestions[] = new Suggestion($command, $description);
        }

        return $suggestions;
    }

    private function summarizePromptForDryRun(string $prompt): string
    {
        $prompt = trim((string) preg_replace('/\s+/', ' ', $prompt));
        if ($prompt === '') {
            return 'shell task';
        }

        if (strlen($prompt) > 60) {
            $prompt = rtrim(substr($prompt, 0, 57)) . '...';
        }

        return $prompt;
    }

    /**
     * @return array{
     *     mode: string,
     *     help: bool,
     *     json: bool,
     *     num: ?int,
     *     shell: bool,
     *     dry-run: bool,
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
        $dryRun = false;
        $timeout = null;
        $showConfig = false;
        $configMode = false;

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
        $dryRun = (bool) $input->getOption('dry-run');
        $version = (bool) $input->getOption('version');
        $showConfig = (bool) $input->getOption('show-config');
        $configMode = (bool) $input->getOption('config');

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
        $numOptionName = null;
        if ($numOption !== null) {
            $numOptionName = $this->detectNumOptionName($input);
            $num = $this->parsePositiveIntOption((string) $numOption, $numOptionName);
        }

        $timeoutOption = $input->getOption('timeout');
        $timeoutOptionName = null;
        if ($timeoutOption !== null) {
            $timeoutOptionName = $this->detectTimeoutOptionName($input);
            $timeout = $this->parsePositiveIntOption((string) $timeoutOption, $timeoutOptionName);
        }

        if ($configMode) {
            $conflicts = $this->detectConfigConflicts(
                json: $json,
                shellIntegration: $shellIntegration,
                dryRun: $dryRun,
                widget: $hasWidgetOption,
                explain: $isExplain,
                showConfig: $showConfig,
                help: $help,
                version: $version,
                numOptionName: $numOptionName,
                timeoutOptionName: $timeoutOptionName
            );
            if ($conflicts !== []) {
                throw new \RuntimeException(sprintf(
                    '--config cannot be combined with %s.',
                    implode(', ', $conflicts)
                ));
            }

            $mode = 'config';
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
            'dry-run' => $dryRun,
            'widget_binding' => $widgetBinding,
            'widget_shell' => $widgetShell,
            'timeout' => $timeout,
            'show_config' => $showConfig,
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
        $output->writeln('Use --config set <key> <value> to edit the ~/.shsuggest file safely.');
    }

    private function printVersion(): void
    {
        $this->writeLine(sprintf('shsuggest %s', Version::CURRENT));
    }

    private function printConfigSettings(): void
    {
        $path = $this->configLoader->getPath();
        $values = $this->configLoader->loadValues();
        $readable = is_readable($path);

        $this->writeLine($this->style('⚙ Configuration', 'title', STDOUT));
        $this->writeLine(
            sprintf(
                ' %s %s',
                $this->style('Path:', 'muted', STDOUT),
                $this->style($path, 'command', STDOUT)
            )
        );
        $this->writeLine('');

        if (!$readable) {
            $message = sprintf(
                '%s No readable configuration file was found; defaults are active.',
                $this->style('ℹ', 'accent', STDOUT)
            );
            $this->writeLine($message);

            return;
        }

        if ($values === []) {
            $message = sprintf(
                '%s No settings were parsed from the configuration file.',
                $this->style('ℹ', 'accent', STDOUT)
            );
            $this->writeLine($message);

            return;
        }

        foreach ($values as $key => $value) {
            $line = sprintf(
                ' %s %s %s',
                $this->style('•', 'muted', STDOUT),
                $this->style((string) $key, 'accent', STDOUT),
                $this->style($this->formatConfigValue($value), 'command', STDOUT)
            );
            $this->writeLine($line);
        }
    }

    private function formatConfigValue(string|float|int|null $value): string
    {
        if ($value === null) {
            return 'null';
        }

        if (is_string($value)) {
            return $value === '' ? '""' : $value;
        }

        return (string) $value;
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

    /**
     * @return string[]
     */
    private function detectConfigConflicts(
        bool $json,
        bool $shellIntegration,
        bool $dryRun,
        bool $widget,
        bool $explain,
        bool $showConfig,
        bool $help,
        bool $version,
        ?string $numOptionName,
        ?string $timeoutOptionName
    ): array {
        $conflicts = [];
        if ($json) {
            $conflicts[] = '--json';
        }

        if ($shellIntegration) {
            $conflicts[] = '--shell';
        }

        if ($dryRun) {
            $conflicts[] = '--dry-run';
        }

        if ($widget) {
            $conflicts[] = '--widget';
        }

        if ($explain) {
            $conflicts[] = '--explain';
        }

        if ($showConfig) {
            $conflicts[] = '--show-config';
        }

        if ($help) {
            $conflicts[] = '--help';
        }

        if ($version) {
            $conflicts[] = '--version';
        }

        if ($numOptionName !== null) {
            $conflicts[] = $numOptionName;
        }

        if ($timeoutOptionName !== null) {
            $conflicts[] = $timeoutOptionName;
        }

        return $conflicts;
    }

    private function runConfigCommand(array $args): int
    {
        if ($args === []) {
            throw new \RuntimeException('Please provide a config subcommand (e.g. "set").');
        }

        $command = strtolower((string) array_shift($args));
        if ($command === 'set') {
            return $this->handleConfigSet($args);
        }

        throw new \RuntimeException(sprintf('Unknown config subcommand "%s".', $command));
    }

    private function handleConfigSet(array $args): int
    {
        if (count($args) < 2) {
            throw new \RuntimeException('Usage: shsuggest --config set <key> <value>');
        }

        $key = (string) array_shift($args);
        $value = trim(implode(' ', $args));

        if ($key === '') {
            throw new \RuntimeException('Please provide the configuration key to set.');
        }

        $normalizedKey = $this->validateConfigKey($key);
        $normalizedValue = $this->normalizeConfigValue($normalizedKey, $value);
        $values = $this->configLoader->loadValues();
        $path = $this->configLoader->getPath();
        $changed = false;

        if ($normalizedValue === null) {
            if (array_key_exists($normalizedKey, $values)) {
                unset($values[$normalizedKey]);
                $changed = true;
            }
        } else {
            $existing = $values[$normalizedKey] ?? null;
            if (!$this->configValuesEqual($existing, $normalizedValue)) {
                $values[$normalizedKey] = $normalizedValue;
                $changed = true;
            }
        }

        if ($changed) {
            $this->configLoader->saveValues($values);
            if ($normalizedValue === null) {
                $message = sprintf(
                    '%s Reset "%s" to the default value in %s.',
                    $this->style('✔', 'accent', STDOUT),
                    $normalizedKey,
                    $path
                );
            } else {
                $message = sprintf(
                    '%s Set "%s" to "%s" in %s.',
                    $this->style('✔', 'accent', STDOUT),
                    $normalizedKey,
                    $this->describeConfigValue($normalizedValue),
                    $path
                );
            }
        } else {
            if ($normalizedValue === null) {
                $message = sprintf('"%s" already uses the default value.', $normalizedKey);
            } else {
                $message = sprintf(
                    '"%s" is already set to "%s".',
                    $normalizedKey,
                    $this->describeConfigValue($normalizedValue)
                );
            }
        }

        $this->writeLine($message);

        return 0;
    }

    private function validateConfigKey(string $key): string
    {
        $key = trim($key);
        if ($key === '') {
            throw new \RuntimeException('Empty configuration keys are not supported.');
        }

        foreach ($this->allowedConfigKeys() as $valid) {
            if (strcasecmp($valid, $key) === 0) {
                return $valid;
            }
        }

        throw new \RuntimeException(sprintf(
            'Unknown configuration key "%s". Valid keys: %s',
            $key,
            implode(', ', $this->allowedConfigKeys())
        ));
    }

    /**
     * @return string[]
     */
    private function allowedConfigKeys(): array
    {
        return array_keys(Config::defaults());
    }

    private function normalizeConfigValue(string $key, string $rawValue): string|int|float|null
    {
        $trimmed = trim($rawValue);
        if ($trimmed === '') {
            throw new \RuntimeException(sprintf('Please provide a value for "%s".', $key));
        }

        $lower = strtolower($trimmed);
        if (in_array($lower, ['default', 'null', 'none'], true)) {
            return null;
        }

        if ($key === 'num_thread' && $lower === 'auto') {
            return null;
        }

        return match ($key) {
            'num_suggestions', 'request_timeout', 'num_thread' => $this->parseConfigInt($trimmed, $key),
            'temperature' => $this->parseTemperature($trimmed),
            'ollama_endpoint' => $this->parseEndpoint($trimmed),
            'pipe_first_into' => $this->parsePipeProgram($trimmed),
            'model' => $this->validateModelChoice($trimmed),
            default => $trimmed,
        };
    }

    private function parseConfigInt(string $value, string $key): int
    {
        if (!ctype_digit($value)) {
            throw new \RuntimeException(sprintf('"%s" expects a positive integer.', $key));
        }

        $int = (int) $value;
        if ($int < 1) {
            throw new \RuntimeException(sprintf('"%s" must be greater than zero.', $key));
        }

        return $int;
    }

    private function parseTemperature(string $value): float
    {
        if (!is_numeric($value)) {
            throw new \RuntimeException('Temperature expects a numeric value.');
        }

        $float = (float) $value;
        if ($float < 0 || $float > 2) {
            throw new \RuntimeException('Temperature must be between 0 and 2.');
        }

        return $float;
    }

    private function parseEndpoint(string $value): string
    {
        $endpoint = filter_var($value, FILTER_VALIDATE_URL);
        if ($endpoint === false) {
            throw new \RuntimeException('Please provide a valid URL for "ollama_endpoint".');
        }

        $scheme = strtolower((string) parse_url($endpoint, PHP_URL_SCHEME));
        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new \RuntimeException('ollama_endpoint must use http or https.');
        }

        return rtrim($endpoint, '/');
    }

    private function parsePipeProgram(string $value): string
    {
        $program = trim($value);
        if ($program === '') {
            throw new \RuntimeException('pipe_first_into cannot be empty.');
        }

        return $program;
    }

    private function validateModelChoice(string $model): string
    {
        try {
            $available = $this->client->listAvailableModels();
        } catch (OllamaClientException $exception) {
            throw new \RuntimeException(
                'Unable to query Ollama for installed models: ' . $exception->getMessage(),
                0,
                $exception
            );
        }

        $needle = strtolower($model);
        foreach ($available as $candidate) {
            if (strcasecmp($candidate, $model) === 0) {
                return $model;
            }
        }

        foreach ($available as $candidate) {
            $base = explode(':', $candidate)[0] ?? $candidate;
            if (strcasecmp($base, $model) === 0) {
                return $model;
            }
        }

        $message = sprintf(
            'Model "%s" is not installed. Available models: %s',
            $model,
            implode(', ', $available)
        );

        throw new \RuntimeException($message);
    }

    private function configValuesEqual(mixed $current, mixed $next): bool
    {
        if ($current === null || $next === null) {
            return $current === $next;
        }

        if (is_float($current) && is_float($next)) {
            return abs($current - $next) < 0.0000001;
        }

        return $current === $next;
    }

    private function describeConfigValue(string|int|float|null $value): string
    {
        if ($value === null) {
            return 'default';
        }

        if (is_float($value)) {
            $formatted = rtrim(rtrim(sprintf('%.10F', $value), '0'), '.');

            return $formatted === '' ? '0' : $formatted;
        }

        return (string) $value;
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
            new InputOption('show-config', null, InputOption::VALUE_NONE, 'Display the parsed configuration file and exit.'),
            new InputOption('config', null, InputOption::VALUE_NONE, 'Manage the configuration file (example: --config set <key> <value>).'),
            new InputOption('explain', 'e', InputOption::VALUE_NONE, 'Explain the provided shell command instead of generating suggestions.'),
            new InputOption('json', 'j', InputOption::VALUE_NONE, 'Emit machine-readable JSON.'),
            new InputOption('num', 'n', InputOption::VALUE_REQUIRED, 'Request N suggestions (default comes from the config file).'),
            new InputOption('timeout', 't', InputOption::VALUE_REQUIRED, 'Override the Ollama request timeout (seconds).'),
            new InputOption('shell', null, InputOption::VALUE_NONE, 'Emit only the selected suggestion for shell integration widgets.'),
            new InputOption('shell-integration', null, InputOption::VALUE_NONE, 'Alias for --shell (deprecated).'),
            new InputOption('dry-run', null, InputOption::VALUE_NONE, 'Return instantly with dummy suggestions (skips Ollama requests).'),
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

    private function detectTimeoutOptionName(ArgvInput $input): string
    {
        $option = '--timeout';
        foreach ($input->getRawTokens() as $token) {
            if (str_starts_with($token, '--timeout')) {
                $option = '--timeout';
                continue;
            }

            if (str_starts_with($token, '-t')) {
                $option = '-t';
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

    private function announceModelUsage(
        string $model,
        ?float $elapsedSeconds = null,
        ?float $tokensPerSecond = null
    ): void
    {
        $parts = [
            $this->style('🤖', 'accent', STDERR),
            $this->style('Model:', 'muted', STDERR),
            $this->style($model, 'command', STDERR),
        ];

        $formatted = $this->formatGenerationStats($elapsedSeconds, $tokensPerSecond);
        if ($formatted !== null) {
            $parts[] = $this->style(sprintf('(%s)', $formatted), 'muted', STDERR);
        }

        $line = implode(' ', $parts);
        fwrite(STDERR, $line . PHP_EOL);
    }

    private function formatElapsedSeconds(?float $elapsedSeconds): ?string
    {
        if ($elapsedSeconds === null) {
            return null;
        }

        return sprintf('%.2fs', max($elapsedSeconds, 0));
    }

    private function formatGenerationStats(?float $elapsedSeconds, ?float $tokensPerSecond): ?string
    {
        $parts = [];

        $formattedTime = $this->formatElapsedSeconds($elapsedSeconds);
        if ($formattedTime !== null) {
            $parts[] = $formattedTime;
        }

        $formattedTokens = $this->formatTokensPerSecond($tokensPerSecond);
        if ($formattedTokens !== null) {
            $parts[] = $formattedTokens;
        }

        if ($parts === []) {
            return null;
        }

        return implode(' · ', $parts);
    }

    private function formatTokensPerSecond(?float $tokensPerSecond): ?string
    {
        if ($tokensPerSecond === null || $tokensPerSecond <= 0) {
            return null;
        }

        return $tokensPerSecond >= 100
            ? sprintf('%.0f tok/s', $tokensPerSecond)
            : sprintf('%.1f tok/s', $tokensPerSecond);
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
