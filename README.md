shsuggest
=========

`shsuggest` is a lightweight replacement for the deprecated `gh copilot suggest`/`explain` commands. It supports
multiple suggestion sources via pluggable adapters—including local [Ollama](https://ollama.com) instances,
the GitHub Copilot CLI, and hosted APIs such as OpenAI and Claude—to generate shell commands or explain
existing ones, and ships as a single PHAR for easy distribution.

## Installation

```bash
composer install
php -d phar.readonly=0 build-phar.php
mv shsuggest.phar /usr/local/bin/shsuggest
chmod +x /usr/local/bin/shsuggest
```

> Composer must be installed locally. You'll also need at least one configured source adapter, such as a running
> Ollama instance (`ollama serve`), the GitHub Copilot CLI available on your PATH (or set `copilot.binary`),
> or API keys for OpenAI / Claude if you're targeting those adapters.

## Usage

```bash
shsuggest [OPTIONS] [PROMPT]
shsuggest -e|--explain [COMMAND]
```

* If no prompt/command is provided, `shsuggest` reads from STDIN.
* A single command is printed by default so it can be piped into other tooling. Pass `-n 3` (or any value > 1) from a TTY to browse suggestions interactively.
* Use `--json` (or `-j`) to emit machine-readable output; interactive prompts are skipped automatically in this mode. JSON responses now include a `normalized_prompt` that rewrites your request with spelling and grammar fixes so it can be logged or reused later.
* Use `--show-config` to print the settings parsed from `~/.shsuggest` and exit.
* Use `--shell` when invoking from shell widgets/integration so only the selected suggestion is written to STDOUT.
* Use `--dry-run` to instantly emit dummy suggestions without contacting the configured source—handy when testing UI flows.
* Use `--dump-prompt` to print the raw model prompt (including system metadata) that would be sent, then exit.
* Use `-t 60` (or `--timeout=60`) to override the model request timeout for a single run.
* When STDOUT is not a TTY, the selected command is also echoed to STDERR so you can still see/copy it while piping.

Examples:

```bash
shsuggest "list the 5 largest directories"
echo "remove old node_modules folders" | shsuggest
shsuggest -n 3 "prepare a git release"
shsuggest --explain 'find . -name "*.log" -delete'
shsuggest --json 'list running docker containers with ids'
```

## Sources

`shsuggest` uses source adapters to connect to different backends. Built-in adapters include:

- `ollama`: a local Ollama server over HTTP.
- `copilot`: the GitHub Copilot CLI.

Pick the active adapter with the `source` setting in `~/.shsuggest` or by passing `--config set source <name>`.

### Shell widgets

Generate a ready-to-use widget that binds <kbd>Ctrl</kbd>+<kbd>G</kbd> (or your preferred key sequence) to `shsuggest`.
Pass the shell you want to configure (`bash` or `zsh`) as the final argument:

```bash
# Bash
eval "$(shsuggest --widget bash)"

# Zsh
eval "$(shsuggest --widget zsh)"
```

The widget calls `shsuggest --shell -- "$BUFFER"` (or `"$READLINE_LINE"` in Bash) so only the final command is printed, which makes it safe to capture inside the keybinding. To choose a different binding, pass the key sequence directly:

```bash
# Bash (Bash uses readline-style bindings like \C-r)
eval "$(shsuggest --widget='\C-r' bash)"

# Zsh (pass the notation accepted by bindkey, for example ^R)
eval "$(shsuggest --widget='^R' zsh)"
```

Re-run the command whenever you update the binary so the hook stays in sync.

## Configuration

`shsuggest` looks for a simple TOML dotfile at `~/.shsuggest` (or `~/.shsuggest.toml`). All settings are optional:

```toml
source = "ollama"
num_suggestions = 1
temperature = 0.35
num_thread = 32
request_timeout = 30
pipe_first_into = "pbcopy"

[ollama]
model = "llama3"
endpoint = "http://127.0.0.1:11434"
# or split the endpoint into parts:
host = "127.0.0.1"
port = 11434
scheme = "http"

[copilot]
model = "copilot-cli"
binary = "/home/you/.local/bin/copilot"

[openai]
model = "gpt-4o-mini"
api_key = "sk-..."
endpoint = "https://api.openai.com/v1"

[claude]
model = "claude-3-5-haiku-latest"
api_key = "anthropic-key"
endpoint = "https://api.anthropic.com"
anthropic_version = "2023-06-01"
```

`source` selects which backend to use. `ollama` contacts a local Ollama server over HTTP, while `copilot`
shells out to the GitHub Copilot CLI (override `copilot.binary` if it lives somewhere else). `openai` and
`claude` talk to the respective hosted APIs using the configured endpoint and API key. Set `openai.api_key`
or `claude.api_key` in the config file or rely on the `OPENAI_API_KEY` / `ANTHROPIC_API_KEY` environment
variables (`CLAUDE_API_KEY` is also accepted). The `[ollama]` table accepts a full `endpoint` or separate
host/port/scheme parts (the endpoint wins when both are provided) as well as the `model` to query. The legacy
top-level `ollama_endpoint` and `model` keys are still honored for backwards compatibility. Additional
adapters can be added over time; the `source` key just picks the one you want to use.

The Copilot adapter ignores `temperature` and thread settings but still respects `num_suggestions`,
`request_timeout`, and the prompt-rendering options. The hosted adapters honor the global `temperature`
value and respect `request_timeout`; customize `copilot.model`, `openai.model`, or `claude.model` if you want
the UI to display a friendlier label than the defaults.

`num_suggestions` controls the default value passed to `-n/--num` when it isn't provided explicitly.
Invalid values are ignored (and reset to 1) with a warning.

The `pipe_first_into` entry lets you feed the first suggestion into another program (for example, `pbcopy` on
macOS to copy the command to the clipboard). Even when multiple suggestions are shown interactively, only the
first suggestion is piped.

`num_thread` is forwarded to Ollama's `options.num_thread` field when that adapter is active, which can be used
when targeting models that benefit from a specific thread count.

`request_timeout` (or the `-t/--timeout` CLI option) controls how long `shsuggest` waits for the backend to
respond before failing the request. Increase it when running slower models or reduce it if you'd like to fail
fast.

Run `shsuggest --show-config` at any time to confirm which settings were parsed from your dotfile.

To edit the file from the CLI, use `shsuggest --config set <key> <value>`. Values are validated before being
written—numeric fields reject invalid numbers and the `ollama.model` entry is checked against the models reported by
the Ollama adapter. Pass `default`, `none`, or `null` as the value to remove an override and fall back to the
built-in defaults. The model key is addressed as `ollama.model`, though the legacy root-level `model` alias is still
accepted. Older `key=value` files are still recognized, and the next call to `shsuggest --config set …` will convert
them to TOML automatically.
