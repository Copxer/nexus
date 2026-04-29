<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GithubConnection extends Model
{
    protected $fillable = [
        'user_id',
        'github_user_id',
        'github_username',
        'access_token',
        'refresh_token',
        'expires_at',
        'refresh_token_expires_at',
        'scopes',
        'connected_at',
    ];

    protected function casts(): array
    {
        return [
            // Encrypted at rest — Laravel transparently decrypts on read,
            // encrypts on write. Uses APP_KEY; cipher is whatever the
            // app config says.
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'expires_at' => 'datetime',
            'refresh_token_expires_at' => 'datetime',
            'scopes' => 'array',
            'connected_at' => 'datetime',
        ];
    }

    /**
     * Hide the (decrypted) tokens by default so they don't sneak into
     * Inertia props or `toArray()` output. Anything that needs the
     * plaintext access token reads `$connection->access_token`
     * directly.
     */
    protected $hidden = [
        'access_token',
        'refresh_token',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** True when the access token is still within its expiry window. */
    public function isAccessTokenValid(): bool
    {
        if ($this->expires_at === null) {
            return true;
        }

        return $this->expires_at->isFuture();
    }
}
