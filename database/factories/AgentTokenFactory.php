<?php

namespace Database\Factories;

use App\Models\AgentToken;
use App\Models\Host;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<AgentToken> */
class AgentTokenFactory extends Factory
{
    protected $model = AgentToken::class;

    public function definition(): array
    {
        // Tests almost never need the plaintext — they assert against
        // the hash directly. When they do need the plaintext, they go
        // through `IssueAgentTokenAction` which is the production path.
        $plaintext = Str::random(40);

        return [
            'host_id' => Host::factory(),
            'name' => 'agent token',
            'hashed_token' => AgentToken::hash($plaintext),
            'last_used_at' => null,
            'revoked_at' => null,
            'created_by_user_id' => null,
        ];
    }

    public function revoked(): static
    {
        return $this->state(fn () => [
            'revoked_at' => now(),
        ]);
    }
}
