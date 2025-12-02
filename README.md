shsuggest
=========

`shsuggest` is a lightweight replacement for the deprecated `gh copilot suggest`/`explain` commands. It
talks to a local [Ollama](https://ollama.com) instance to generate shell commands or explain existing ones,
and ships as a single PHAR for easy distribution.

## Installation

```bash
composer install
php -d phar.readonly=0 build-phar.php
mv shsuggest.phar /usr/local/bin/shsuggest
chmod +x /usr/local/bin/shsuggest
```

> Composer must be installed locally and Ollama must be running (`ollama serve`).

## Usage

```bash
shsuggest [OPTIONS] [PROMPT]
shsuggest -e|--explain [COMMAND]
```

* If no prompt/command is provided, `shsuggest` reads from STDIN.
* A single command is printed by default so it can be piped into other tooling. Pass `-n 3` (or any value > 1) from a TTY to browse suggestions interactively.
* Use `--json` (or `-j`) to emit machine-readable output; interactive prompts are skipped automatically in this mode.
* Use `--shell` when invoking from shell widgets/integration so only the selected suggestion is written to STDOUT.
* When STDOUT is not a TTY, the selected command is also echoed to STDERR so you can still see/copy it while piping.

Examples:

```bash
shsuggest "list the 5 largest directories"
echo "remove old node_modules folders" | shsuggest
shsuggest -n 3 "prepare a git release"
shsuggest --explain 'find . -name "*.log" -delete'
shsuggest --json 'list running docker containers with ids'
```

### Shell widgets

Generate a ready-to-use Bash/Zsh widget that binds <kbd>Ctrl</kbd>+<kbd>G</kbd> (or your preferred key sequence) to `shsuggest`:

```bash
eval "$(shsuggest --widget)"
```

The widget calls `shsuggest --shell -- "$BUFFER"` (or `"$READLINE_LINE"` in Bash) so only the final command is printed, which makes it safe to capture inside the keybinding. To choose a different binding, pass the key sequence directly:

```bash
eval "$(shsuggest --widget='\C-r')"
```

Re-run the command whenever you update the binary so the hook stays in sync.

## Configuration

`shsuggest` looks for a simple `key=value` dotfile at `~/.shsuggest`. All settings are optional:

```ini
model=llama3
ollama_endpoint=http://127.0.0.1:11434
num_suggestions=1
temperature=0.35
num_thread=32
request_timeout=30
pipe_first_into=pbcopy
```

The `pipe_first_into` entry lets you feed the first suggestion into another program (for example, `pbcopy` on
macOS to copy the command to the clipboard). Even when multiple suggestions are shown interactively, only the
first suggestion is piped.

`num_thread` is forwarded to Ollama's `options.num_thread` field, which can be used when targeting models that
benefit from a specific thread count.
