<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Category;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Category>
 */
final class CategoryFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->randomElement(['Groceries', 'Transport', 'Utilities', 'Entertainment', 'Dining', 'Healthcare', 'Shopping', 'Education']),
            'is_hidden' => false,
        ];
    }

    public function withParent(Category $parent): self
    {
        return $this->state(fn (array $attributes) => [
            'parent_id' => $parent->id,
        ]);
    }

    public function division(): self
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'Retail Trade',
            'anzsic_division' => 'G',
        ]);
    }

    public function subdivision(): self
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'Food Retailing',
            'anzsic_division' => 'G',
            'anzsic_subdivision' => '41',
        ]);
    }

    public function group(): self
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'Supermarket and Grocery Stores',
            'anzsic_division' => 'G',
            'anzsic_subdivision' => '41',
            'anzsic_group' => '411',
        ]);
    }

    public function anzsicClass(): self
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'Supermarkets',
            'anzsic_division' => 'G',
            'anzsic_subdivision' => '41',
            'anzsic_group' => '411',
            'anzsic_class' => '4110',
        ]);
    }

    public function withIcon(string $icon = 'shopping-cart'): self
    {
        return $this->state(fn (array $attributes) => [
            'icon' => $icon,
        ]);
    }

    public function withColor(string $color = '#4F46E5'): self
    {
        return $this->state(fn (array $attributes) => [
            'color' => $color,
        ]);
    }

    public function hidden(): self
    {
        return $this->state(fn (array $attributes) => [
            'is_hidden' => true,
        ]);
    }
}
