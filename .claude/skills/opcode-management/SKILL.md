---
name: opcode-management
description: Creates and manages OpCode op.conf files and aliases. Activate when the user wants to create an op.conf file, add command shortcuts/aliases, manage OpCode configs, or mentions OpCode, op.conf, or "op" command shortcuts.
metadata:
  author: MrWilde
  tags:
    - opcode
    - shortcuts
    - aliases
    - cli
    - bash
---

# OpCode Management Skill

## Overview

OpCode is a lightweight Bash command shortcut manager. Shortcuts are defined in a per-directory config file and executed with `op <code>`.

## Config File

OpCode looks for a config file in the **current working directory** (not global):
- `opcode` (preferred if present)
- `op.conf` (fallback)

Always use `op.conf` as the filename when creating a new config unless an `opcode` file already exists in the directory.

Before creating or editing, check if an `op.conf` or `opcode` file already exists in the target directory.

## Syntax Rules

### Basic Command

```
code: command to run
```

- Codes may contain letters, numbers, underscores (`.`), dots, and hyphens (`-`).
- A single space after the colon is conventional.

### Argument Forwarding

- `$@` — forward all arguments: `test: pytest $@`
- `$1`, `$2`, etc. — positional arguments: `commit: git commit -am "$1" && git push`
- `${1:-default}` — default values: `env: echo ${1:-development}`
- `${@:n}` — slice arguments from position n: `log: echo "First: $1, Rest: ${@:2}"`

### Multiline Commands

Indent continuation lines with two spaces:

```
deploy:
  docker build -t myapp .
  docker push myapp
  kubectl rollout restart deployment/myapp
```

Commands execute sequentially (joined with newlines).

### Usage Comments (`#?`)

Place `#?` lines immediately after a command to document it. These are shown by `op ?`:

```
deploy: kubectl apply -f k8s/
#? Deploy to Kubernetes cluster
#? Usage: op deploy
```

Always add `#?` comments for non-obvious commands.

### Section Headers (`##`)

Use `##` comments to organize commands into groups:

```
## Development

dev: npm run dev
#? Start development server

## Deployment

deploy: ./deploy.sh
#? Deploy to production
```

### Private Commands

Place helper commands below the `private` keyword to hide them from `op ?` and `op --list`:

```
deploy: op build && op push

private

build: docker build .
push: docker push myapp
```

Private commands still execute normally.

### Built-in Color Functions

Available in commands: `bold`, `red`, `green`, `yellow`, `blue`, `magenta`, `cyan`.

```
check: eslint src/ && green "PASS: eslint"
status: blue "Checking status..." && git status
```

### Partial Matching

OpCode matches partial codes if unambiguous. `op s` matches `server` if no other code starts with `s`. First match wins — avoid ambiguous prefixes.

## Adding Aliases via CLI

Use the built-in add command when appending a single alias:

```bash
op -a CODE "COMMAND"
```

Example: `op -a lint "npm run lint"`

Prefer this for quick additions. For bulk additions or when the file does not yet exist, create or edit the file directly.

## CLI Reference

- `op CODE [ARGS]` — Execute command
- `op ?` — Show codes with usage comments
- `op -l, --list` — List all command codes
- `op -s, --show` — Display config file contents
- `op -w, --what CODE` — Show command for a code
- `op -e, --edit` — Open config in `$EDITOR`
- `op -a, --add CODE CMD` — Append command to config

## Workflow When Creating an `op.conf`

1. Check if `op.conf` or `opcode` already exists in the target directory.
2. Inspect the project (README, package.json, Makefile, Dockerfile, composer.json, etc.) to understand common tasks.
3. Create the file with sensible section headers and usage comments.
4. Group related commands under `##` section headers.
5. Add `#?` usage comments for every non-trivial command.
6. Put internal helper commands below `private`.

## Workflow When Adding Aliases

1. Read the existing `op.conf` or `opcode` file.
2. Determine the appropriate section for the new alias.
3. Add the alias with a `#?` usage comment.
4. If no suitable section exists, create one with a `##` header.
5. Maintain consistent formatting with existing entries.
