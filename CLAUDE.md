# DDEV Environment & OpCode Aliases

**This project runs inside DDEV docker containers. NEVER run `php`, `artisan`, `composer`, `npm`, or `vendor/bin/*` commands directly via Bash.**

All shell commands MUST use the `op` (OpCode) aliases defined in `op.conf`. These route through `ddev exec` automatically.

**Key aliases:**

| Task                    | Command                                                   |
|-------------------------|-----------------------------------------------------------|
| Run all tests           | `op test`                                                 |
| Run filtered tests      | `op test.filter <name>`                                   |
| Run unit tests only     | `op test.unit`                                            |
| Run feature tests only  | `op test.feature`                                         |
| Lint dirty files (Pint) | `op lint.dirty`                                           |
| Lint all files          | `op lint`                                                 |
| Run seeders             | `op seed`                                                 |
| Fresh migrate + seed    | `op migrate.fresh`                                        |
| Run migrations          | `op migrate`                                              |
| Create model            | `op make.model <Name> [--migration] [--factory] [--seed]` |
| Create test             | `op make.test <Name>`                                     |
| Full CI check           | `op ci`                                                   |

See `op.conf` for the complete list. When in doubt, read the file.

<laravel-boost-guidelines>
=== foundation rules ===

# Laravel Boost Guidelines

The Laravel Boost guidelines are specifically curated by Laravel maintainers for this application. These guidelines should be followed closely to ensure the best experience when building Laravel applications.

## Foundational Context

This application is a Laravel application and its main Laravel ecosystems package & versions are below. You are an expert with them all. Ensure you abide by these specific packages & versions.

- php - 8.4
- laravel/fortify (FORTIFY) - v1
- laravel/framework (LARAVEL) - v12
- laravel/horizon (HORIZON) - v5
- laravel/prompts (PROMPTS) - v0
- livewire/flux (FLUXUI_FREE) - v2
- livewire/livewire (LIVEWIRE) - v4
- larastan/larastan (LARASTAN) - v3
- laravel/boost (BOOST) - v2
- laravel/mcp (MCP) - v0
- laravel/pail (PAIL) - v1
- laravel/pint (PINT) - v1
- laravel/sail (SAIL) - v1
- pestphp/pest (PEST) - v4
- phpunit/phpunit (PHPUNIT) - v12
- rector/rector (RECTOR) - v2
- tailwindcss (TAILWINDCSS) - v4

## Skills Activation

This project has domain-specific skills available in `**/skills/**`. You MUST activate the relevant skill whenever you work in that domain—don't wait until you're stuck.

## Conventions

- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Verification Scripts

- Do not create verification scripts or tinker when tests cover that functionality and prove they work. Unit and feature tests are more important.

## Application Structure & Architecture

- Stick to existing directory structure; don't create new base folders without approval.
- Do not change the application's dependencies without approval.

## Frontend Bundling

- If the user doesn't see a frontend change reflected in the UI, it could mean they need to run `op build` or `op dev`. Ask them.

## Documentation Files

- You must only create documentation files if explicitly requested by the user.

## Replies

- Be concise in your explanations - focus on what's important rather than explaining obvious details.

=== boost rules ===

# Laravel Boost

## Tools

- Laravel Boost is an MCP server with tools designed specifically for this application. Prefer Boost tools over manual alternatives like shell commands or file reads.
- Use `database-query` to run read-only queries against the database instead of writing raw SQL in tinker.
- Use `database-schema` to inspect table structure before writing migrations or models.
- Use `get-absolute-url` to resolve the correct scheme, domain, and port for project URLs. Always use this before sharing a URL with the user.
- Use `browser-logs` to read browser logs, errors, and exceptions. Only recent logs are useful, ignore old entries.

## Searching Documentation (IMPORTANT)

- Always use `search-docs` before making code changes. Do not skip this step. It returns version-specific docs based on installed packages automatically.
- Pass a `packages` array to scope results when you know which packages are relevant.
- Use multiple broad, topic-based queries: `['rate limiting', 'routing rate limiting', 'routing']`. Expect the most relevant results first.
- Do not add package names to queries because package info is already shared. Use `test resource table`, not `filament 4 test resource table`.

### Search Syntax

1. Use words for auto-stemmed AND logic: `rate limit` matches both "rate" AND "limit".
2. Use `"quoted phrases"` for exact position matching: `"infinite scroll"` requires adjacent words in order.
3. Combine words and phrases for mixed queries: `middleware "rate limit"`.
4. Use multiple queries for OR logic: `queries=["authentication", "middleware"]`.

## Artisan

- Run Artisan commands through project wrappers: use documented `op` aliases from `op.conf` when available (for example `op routes`, `op migrate`, `op test.*`, and `op make.*`); otherwise use `ddev exec php artisan ...`. Never run bare `php artisan` on the host.
- Inspect routes with `op routes`, or use `ddev exec php artisan route:list` when you need flags such as `--method=GET`, `--name=users`, `--path=api`, `--except-vendor`, or `--only-vendor`.
- Read configuration values by reading files in `config/`, or use `ddev exec php artisan config:show app.name` / `ddev exec php artisan config:show database.default` when command output is useful.

## Tinker

- Execute PHP in app context only through DDEV. Prefer existing tests and Artisan commands; if tinker is necessary, use `op tinker` interactively or `ddev exec php artisan tinker --execute 'Your::code();'` for one-off reads.
- Keep one-off `--execute` snippets single-quoted to avoid shell expansion.
  - Example with double quotes inside PHP: `ddev exec php artisan tinker --execute 'User::where("active", true)->count();'`

=== php rules ===

# PHP

- Always use curly braces for control structures, even for single-line bodies.
- Use PHP 8 constructor property promotion: `public function __construct(public GitHub $github) { }`. Do not leave empty zero-parameter `__construct()` methods unless the constructor is private.
- Use explicit return type declarations and type hints for all method parameters: `function isAccessible(User $user, ?string $path = null): bool`
- Use TitleCase for Enum keys: `FavoritePerson`, `BestLake`, `Monthly`.
- Prefer PHPDoc blocks over inline comments. Only add inline comments for exceptionally complex logic.
- Use array shape type definitions in PHPDoc blocks.

=== deployments rules ===

# Deployment

- Laravel can be deployed using [Laravel Cloud](https://cloud.laravel.com/), which is the fastest way to deploy and scale production Laravel applications.

=== tests rules ===

# Test Enforcement

- Every change must be programmatically tested. Write a new test or update an existing test, then run the affected tests to make sure they pass.
- Run the minimum number of tests needed to ensure code quality and speed. Use `op test.filter <name>`, or `ddev exec php artisan test --compact --filter=<name>` when an alias does not cover the case.

=== laravel/core rules ===

# Do Things the Laravel Way

- Use `op make.*` aliases or `ddev exec php artisan make:* --no-interaction` commands to create new files (i.e. migrations, controllers, models, etc.). You can list available Artisan commands with `ddev exec php artisan list` and inspect parameters with `ddev exec php artisan [command] --help`.
- If you're creating a generic PHP class, use `ddev exec php artisan make:class`.
- Pass `--no-interaction` to all Artisan commands to ensure they work without user input. You should also pass the correct `--options` to ensure correct behavior.

### Model Creation

- When creating new models, create useful factories and seeders for them too. Ask the user if they need any other things, using `op make.model <Name> --help` or `ddev exec php artisan make:model --help` to check the available options.

## APIs & Eloquent Resources

- For APIs, default to using Eloquent API Resources and API versioning unless existing API routes do not, then you should follow existing application convention.

## URL Generation

- When generating links to other pages, prefer named routes and the `route()` function.

## Testing

- When creating models for tests, use the factories for the models. Check if the factory has custom states that can be used before manually setting up the model.
- Faker: Use methods such as `$this->faker->word()` or `fake()->randomDigit()`. Follow existing conventions whether to use `$this->faker` or `fake()`.
- When creating tests, use `op make.test <Name>` to create a Pest feature test, and pass `--unit` for a unit test. Most tests should be feature tests.

## Vite Error

- If you receive an "Illuminate\Foundation\ViteException: Unable to locate file in Vite manifest" error, run `op build` or ask the user to run `op build` / `op dev`.

=== laravel/v12 rules ===

# Laravel 12

- CRITICAL: ALWAYS use `search-docs` tool for version-specific Laravel documentation and updated code examples.
- Since Laravel 11, Laravel has a new streamlined file structure which this project uses.

## Laravel 12 Structure

- In Laravel 12, middleware are no longer registered in `app/Http/Kernel.php`.
- Middleware are configured declaratively in `bootstrap/app.php` using `Application::configure()->withMiddleware()`.
- `bootstrap/app.php` is the file to register middleware, exceptions, and routing files.
- `bootstrap/providers.php` contains application specific service providers.
- The `app/Console/Kernel.php` file no longer exists; use `bootstrap/app.php` or `routes/console.php` for console configuration.
- Console commands in `app/Console/Commands/` are automatically available and do not require manual registration.

## Database

- When modifying a column, the migration must include all of the attributes that were previously defined on the column. Otherwise, they will be dropped and lost.
- Laravel 12 allows limiting eagerly loaded records natively, without external packages: `$query->latest()->limit(10);`.

### Models

- Casts can and likely should be set in a `casts()` method on a model rather than the `$casts` property. Follow existing conventions from other models.

=== livewire/core rules ===

# Livewire

- Livewire allow to build dynamic, reactive interfaces in PHP without writing JavaScript.
- You can use Alpine.js for client-side interactions instead of JavaScript frameworks.
- Keep state server-side so the UI reflects it. Validate and authorize in actions as you would in HTTP requests.

=== pint/core rules ===

# Laravel Pint Code Formatter

- If you have modified any PHP files, run `op lint.dirty` before finalizing changes to ensure your code matches the project's expected style.
- To fix formatting across the whole project, run `op lint`.

=== pest/core rules ===

## Pest

- This project uses Pest for testing. Create tests with `op make.test {name}` (for example, `op make.test SomeFeatureTest`).
- The `{name}` argument should not include the test suite directory. Use `op make.test SomeFeatureTest` instead of `op make.test Feature/SomeFeatureTest`.
- Run tests with `op test`, or filter with `op test.filter testName`.
- Do NOT delete tests without approval.

=== spatie/boost-spatie-guidelines rules ===

# Project Coding Guidelines

- This codebase follows Spatie's Laravel & PHP guidelines.
- Always activate the `spatie-laravel-php-standards` skill whenever writing, editing, reviewing, or formatting Laravel or PHP code.

</laravel-boost-guidelines>
