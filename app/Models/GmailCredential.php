<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\GmailCredentialFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $user_id
 * @property string $username
 * @property string $app_password
 * @property CarbonImmutable|null $last_verified_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class GmailCredential extends Model
{
    /** @use HasFactory<GmailCredentialFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'username',
        'app_password',
        'last_verified_at',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'app_password',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'app_password' => 'encrypted',
            'last_verified_at' => 'immutable_datetime',
        ];
    }
}
