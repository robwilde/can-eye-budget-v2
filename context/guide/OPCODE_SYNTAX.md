# Opcode Syntax Reference

Opcode is a lightweight Bash command shortcut manager. Define shortcuts in a config file and execute them with `op <code>`.

## Config File Location

Opcode looks for a config file in the **current directory** (not global):
- `opcode` (preferred if present)
- `op.conf` (fallback)

## Basic Syntax

```bash
code: command to run
```

The `code` is what you type after `op`. Valid codes contain letters, numbers, underscores, dots, and hyphens.

### Simple Commands

```bash
build: docker build .
deploy: kubectl apply -f deployment.yaml
clean: rm -rf dist/
```

Usage: `op build`, `op deploy`, `op clean`

## Argument Forwarding

Arguments passed to `op` are available in the command:

### Forward All Arguments (`$@`)

```bash
test: pytest $@
```

Usage: `op test -v --tb=short` → `pytest -v --tb=short`

### Positional Arguments (`$1`, `$2`, etc.)

```bash
commit: git commit -am "$1" && git push
greet: echo "Hello $1, welcome to $2"
```

Usage: `op commit "fix bug"` → `git commit -am "fix bug" && git push`

### Default Values

```bash
env: echo ${1:-development}
```

Usage: `op env` → `echo development`, `op env production` → `echo production`

### Slice Arguments (`${@:n}`)

```bash
log: echo "First: $1, Rest: ${@:2}"
```

Usage: `op log a b c d` → `echo "First: a, Rest: b c d"`

## Multiline Commands

Indent continuation lines with spaces:

```bash
deploy:
  docker build -t myapp .
  docker push myapp
  kubectl rollout restart deployment/myapp
```

Commands execute sequentially (joined with newlines).

## Usage Comments (`#?`)

Add documentation visible via `op ?`:

```bash
deploy: kubectl apply -f k8s/
#? Deploy to Kubernetes cluster
#? Usage: op deploy

test: pytest $@
#? Run test suite
#? Usage: op test [pytest-args]
```

Output of `op ?`:
```
  deploy
    Deploy to Kubernetes cluster
    Usage: op deploy

  test
    Run test suite
    Usage: op test [pytest-args]
```

## Section Headers (`##`)

Organize commands with section comments:

```bash
## Build Commands

build: make build
clean: make clean

## Deployment

deploy: ./deploy.sh
rollback: ./rollback.sh
```

Section headers appear in `op ?` output.

## Private Commands

Hide helper commands from `op ?` and `op --list`:

```bash
deploy: op build && op push && op apply
test: op lint && op unit

private

build: docker build .
push: docker push myapp
apply: kubectl apply -f k8s/
lint: eslint src/
unit: jest
```

Private commands still execute normally—they're just hidden from listings.

## Built-in Color Functions

These functions are available in your commands:

| Function | Color |
|----------|-------|
| `bold` | Bold text |
| `red` | Red text |
| `green` | Green text |
| `yellow` | Yellow text |
| `blue` | Blue text |
| `magenta` | Magenta text |
| `cyan` | Cyan text |

```bash
check:
  eslint src/ && green "PASS: eslint"

status: blue "Checking status..." && git status
```

## Partial Command Matching

Opcode matches partial codes if no exact match exists:

```bash
server: rails server
```

All of these work: `op server`, `op serv`, `op s`

First matching command wins, so be careful with ambiguous prefixes.

## Complete Example

```bash
## Development

dev: npm run dev
#? Start development server

build: npm run build
#? Build for production

test: npm test $@
#? Run tests. Usage: op test [jest-args]

## Git Workflow

commit: git commit -am "$1" && git push
#? Commit and push. Usage: op commit "message"

pr:
  git push -u origin HEAD
  gh pr create --fill
#? Create pull request from current branch

## Docker

up: docker compose up -d
#? Start containers in background

down: docker compose down
#? Stop containers

logs: docker compose logs -f $@
#? Follow container logs. Usage: op logs [service]

private

# Helper commands used by other ops
lint: eslint src/ && green "PASS: lint"
typecheck: tsc --noEmit && green "PASS: types"
```

## CLI Reference

| Command | Description |
|---------|-------------|
| `op CODE [ARGS]` | Execute command |
| `op ?` | Show codes with usage comments |
| `op -l, --list` | List all command codes |
| `op -s, --show` | Display config file contents |
| `op -w, --what CODE` | Show command for a code |
| `op -e, --edit` | Open config in `$EDITOR` |
| `op -a, --add CODE CMD` | Append command to config |
