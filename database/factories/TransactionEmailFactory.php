<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Transaction;
use App\Models\TransactionEmail;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TransactionEmail>
 */
final class TransactionEmailFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $user = User::factory();
        $messageId = fake()->uuid().'@mail.gmail.com';

        return [
            'user_id' => $user,
            'transaction_id' => Transaction::factory()->for($user),
            'gmail_message_id' => $messageId,
            'subject' => fake()->sentence(4),
            'from_name' => fake()->company(),
            'from_address' => fake()->companyEmail(),
            'email_date' => fake()->dateTimeBetween('-3 months', 'now'),
            'snippet' => fake()->text(180),
            'gmail_url' => 'https://mail.google.com/mail/u/0/#search/rfc822msgid:'.rawurlencode($messageId),
        ];
    }
}
