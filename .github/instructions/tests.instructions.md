---
applyTo: "tests/**/*.php"
---

# Pest Testing Conventions

## File Structure
- `declare(strict_types=1)` at top of every test file
- `/** @noinspection StaticClosureCanBeUsedInspection */` after opening PHP tag
- No namespace declaration — Pest tests use global namespace
- Import models and classes with root-level `use` statements

## Test Style
- Framework: Pest 3 (not PHPUnit class style)
- Top-level `test()` function with lowercase descriptive strings:
  ```php
  test('user belongs to account', function () { ... });
  ```
- Use `expect()` chains, not `$this->assert*()`
- Chain multiple assertions with `->and()`:
  ```php
  expect($model->name)->toBe('Budget')
      ->and($model->exists)->toBeTrue();
  ```
- Collection assertions use typed callback:
  ```php
  ->each(fn (Pest\Expectation $item) => $item->toBeInstanceOf(Model::class))
  ```
- Exception testing:
  ```php
  expect(fn () => dangerousAction())->toThrow(QueryException::class);
  ```

## Model & Factory Usage
- Always use factories for model creation — never `Model::create()` directly
- Use `->for($parent)` for relationships, not `['parent_id' => $parent->id]`
- Use `->count(N)` for creating multiple records
- Use factory states (e.g., `->withBasiq()`, `->savings()`) over manual attribute overrides
- Check existing factory states before manually setting attributes

## Database
- `RefreshDatabase` is applied globally via `tests/Pest.php` — never add it per file
- Feature tests go in `tests/Feature/`, unit tests in `tests/Unit/`

## Relationship Tests Should Verify
- Relationship exists and returns correct type
- Correct count of related models
- Type assertion on each related model (`->each()`)
- Cascade/nullify behavior where applicable
- Inverse relationships
