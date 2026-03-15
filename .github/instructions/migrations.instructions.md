---
applyTo: "database/migrations/**/*.php"
---

# Migration Conventions

## File Structure
- `declare(strict_types=1)` at top
- Anonymous class: `return new class extends Migration`
- No namespace declaration
- Explicit `: void` return type on `up()` and `down()`

## Schema Rules
- Always include `down()` method with `Schema::dropIfExists()`
- Foreign keys: `$table->foreignId('x_id')->constrained()` — never manual `unsignedBigInteger`
- Specify cascade behavior explicitly: `->cascadeOnDelete()` or `->nullOnDelete()`
- Nullable FK pattern: `->nullable()->constrained()->nullOnDelete()`
- Money columns: `$table->bigInteger()` — never `decimal` or `float`
- Enum-backed columns: store as `$table->string()`, not native DB enum
- Composite and single-column indexes declared after column definitions, inside same closure

## Column Modifications (Laravel 12)
- When modifying a column, redefine ALL existing attributes (nullable, default, etc.)
- Omitted attributes will be dropped — this is a Laravel 12 behavior change

## Naming
- Table names: plural snake_case (`transactions`, `budget_categories`)
- Foreign key columns: `singular_id` (`user_id`, `account_id`)
- Pivot tables: alphabetical singular (`account_user`, `budget_category`)
