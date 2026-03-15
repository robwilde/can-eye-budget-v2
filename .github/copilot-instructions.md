# Copilot Review Instructions

## Project

Laravel 12 + PHP 8.4 budget app. Livewire 4, Flux UI Free, Pest 3, Tailwind v4, Larastan level 6, Pint with strict rules.

## Review Priorities

### Critical (block merge)
- Security vulnerabilities (SQL injection, XSS, mass assignment)
- `env()` used outside `config/` files
- Raw SQL or `DB::` facade (use `Model::query()`)
- Missing `$fillable` on models accepting user input
- Exposed secrets or credentials
- Money stored as float/decimal (must be `bigInteger` cents)
- Loose comparisons (`==`/`!=`) — must use `===`/`!==`

### Important (request changes)
- New code without tests
- N+1 query risks (missing eager loading)
- Missing `declare(strict_types=1)`
- Non-final classes (all classes must be `final`)
- Missing return type declarations
- Inline validation in controllers (must use Form Request classes)
- `$casts` property instead of `casts()` method
- `protected` visibility where `private` suffices

### Suggestions
- Naming clarity improvements
- PHPDoc type refinements
- Simplification opportunities

## Architecture Rules

- Eloquent relationships over raw joins. Return type hints required on all relationship methods.
- Eager loading via `scopeWithRelations()`, not `$with` property.
- Enums for categorical values: string-backed, TitleCase cases.
- Casts via `casts()` method returning `array<string, string>`.
- Constructor property promotion required. No empty constructors.
- Foreign keys: `foreignId()->constrained()` with explicit `cascadeOnDelete()` or `nullOnDelete()`.
- `self::class` not `static::class` in final classes.

## Pint-Enforced Rules (flag deviations)

- `final_class`: all classes final
- `declare_strict_types`: every PHP file
- `strict_comparison`: `===`/`!==` only
- `protected_to_private`: prefer private
- `ordered_class_elements`: traits > constants > properties > constructor > public > protected > private
- `date_time_immutable`: use CarbonImmutable/DateTimeImmutable
- `no_superfluous_elseif` / `no_useless_else`
- `self_accessor` / `self_static_accessor`

## Larastan

Level 6. Generic PHPDoc required on:
- Relationships: `/** @return BelongsTo<User, $this> */`
- Scopes: `@param Builder<self> $query` and `@return Builder<self>`
- HasFactory: `/** @use HasFactory<ModelFactory> */`
- Fillable: `/** @var list<string> */`

## Testing

Pest 3. Feature tests use `RefreshDatabase` (global in `Pest.php`). Use `expect()` chains, factories with `->for()` for relationships, and factory states over manual attributes.

## Comment Format

Use: `[priority] category: title` — e.g. `[critical] security: unescaped user input`
