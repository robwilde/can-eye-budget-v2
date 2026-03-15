---
applyTo: "**/*.php"
---

# PHP & Laravel Conventions

## Classes
- All classes must be `final`
- `declare(strict_types=1)` required at top of every file
- Constructor property promotion required — no empty constructors
- Use `self::class` not `static::class` in final classes
- Prefer `private` over `protected` visibility

## Class Element Order (enforced by Pint)
1. Traits (`use` statements)
2. Constants (public > protected > private)
3. Properties (public > protected > private)
4. Constructor / Destructor
5. Magic methods
6. Public static methods
7. Public methods
8. Protected methods
9. Private methods

## PHPDoc & Type Hints
- Explicit return type declarations on all methods
- PHPDoc `@property` block on models listing every column with precise types
- Relationship methods: `/** @return BelongsTo<RelatedModel, $this> */`
- Scopes: `@param Builder<self> $query` and `@return Builder<self>`
- HasFactory trait: `/** @use HasFactory<ModelFactory> */`
- Fillable array: `/** @var list<string> */`
- Cast method: `@return array<string, string>`
- No inline comments in method bodies unless logic is exceptionally complex
- Prefer PHPDoc blocks over inline comments

## Eloquent Models
- Casts via `casts()` method, not `$casts` property
- Eager loading via `scopeWithRelations()` pattern, not `$with` property
- Use Eloquent relationships, never raw joins or `DB::` facade
- Use `Model::query()` not `DB::table()`
- Money stored as `bigInteger` (cents), never float/decimal
- Enums: string-backed, TitleCase cases, used as cast values directly

## Foreign Keys
- Use `foreignId('x_id')->constrained()` not manual `unsignedBigInteger`
- Always specify cascade: `->cascadeOnDelete()` or `->nullOnDelete()`
- Nullable FKs: `->nullable()->constrained()->nullOnDelete()`

## Controllers & Validation
- Form Request classes for all validation — no inline validation
- Use named routes with `route()` helper for URL generation

## Configuration
- Never use `env()` outside `config/` files
- Always use `config('key')` instead

## PHP 8.4 Features
- Constructor property promotion
- Enums (string-backed)
- Named arguments where they improve readability
- `readonly` properties where applicable
