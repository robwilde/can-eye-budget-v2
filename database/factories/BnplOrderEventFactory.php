<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\BnplOrderEventType;
use App\Models\BnplOrder;
use App\Models\BnplOrderEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BnplOrderEvent>
 */
final class BnplOrderEventFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'bnpl_order_id' => BnplOrder::factory(),
            'event' => BnplOrderEventType::EmailPulled,
            'payload' => null,
            'actor' => 'system',
        ];
    }
}
