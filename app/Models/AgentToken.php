<?php

namespace App\Models;

use Database\Factories\AgentTokenFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Bearer credential a Nexus agent presents on `/agent/telemetry`
 * (spec 027). The plaintext is shown to the user once at issuance or
 * rotation; only the sha256 hash is persisted.
 */
class AgentToken extends Model
{
    /** @use HasFactory<AgentTokenFactory> */
    use HasFactory;

    protected $fillable = [
        'host_id',
        'name',
        'hashed_token',
        'last_used_at',
        'revoked_at',
        'created_by_user_id',
    ];

    /**
     * The hash is the secret — keep it out of every default
     * serialisation (Inertia props, JSON, logs).
     */
    protected $hidden = [
        'hashed_token',
    ];

    protected function casts(): array
    {
        return [
            'last_used_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function host(): BelongsTo
    {
        return $this->belongsTo(Host::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null;
    }

    /**
     * Hash a plaintext token the same way `IssueAgentTokenAction` does
     * so `/agent/telemetry` middleware can do `where('hashed_token',
     * static::hash($plaintext))` without leaking the plaintext into
     * other layers.
     */
    public static function hash(string $plaintext): string
    {
        return hash('sha256', $plaintext);
    }
}
