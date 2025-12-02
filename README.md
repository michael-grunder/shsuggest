shsuggest
=========

`shsuggest` is a lightweight replacement for the deprecated `gh copilot suggest`/`explain` commands. It
talks to a local [Ollama](https://ollama.com) instance to generate shell commands or explain existing ones,
and ships as a single PHAR for easy distribution.

## Installation

```
composer install
php -d phar.readonly=0 build-phar.php
mv shsuggest.phar /usr/local/bin/shsuggest
chmod +x /usr/local/bin/shsuggest
```

> Composer must be installed locally and Ollama must be running (`ollama serve`).

## Usage

```
shsuggest [PROMPT]
shsuggest -e|--explain [COMMAND]
```

* If no prompt/command is provided, `shsuggest` reads from STDIN.
* When STDOUT is a TTY, multiple suggestions are displayed and you can pick one.
* When STDOUT is not a TTY, only the first suggestion is printed so that it can be piped to other tools.

Examples:

```
shsuggest "list the 5 largest directories"
echo "remove old node_modules folders" | shsuggest
shsuggest --explain 'find . -name "*.log" -delete'
```

## Configuration

`shsuggest` looks for a simple `key=value` dotfile at `~/.shsuggest`. All settings are optional:

```
model=llama3
ollama_endpoint=http://127.0.0.1:11434
num_suggestions=3
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
