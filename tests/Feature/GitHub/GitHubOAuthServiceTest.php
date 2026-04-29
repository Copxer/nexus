<?php

namespace Tests\Feature\GitHub;

use App\Domain\GitHub\Services\GitHubOAuthService;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class GitHubOAuthServiceTest extends TestCase
{
    private function service(): GitHubOAuthService
    {
        config([
            'services.github.client_id' => 'client-abc',
            'services.github.client_secret' => 'secret-xyz',
            'services.github.redirect' => 'https://nexus.test/integrations/github/callback',
            'services.github.scopes' => ['read:user', 'repo'],
        ]);

        return new GitHubOAuthService;
    }

    public function test_redirect_url_includes_client_id_and_state(): void
    {
        $url = $this->service()->redirectUrl('state-token-1');

        $this->assertStringStartsWith('https://github.com/login/oauth/authorize?', $url);
        $this->assertStringContainsString('client_id=client-abc', $url);
        $this->assertStringContainsString('state=state-token-1', $url);
        $this->assertStringContainsString('scope=read%3Auser+repo', $url);
        $this->assertStringContainsString(
            'redirect_uri=https%3A%2F%2Fnexus.test%2Fintegrations%2Fgithub%2Fcallback',
            $url,
        );
    }

    public function test_exchange_code_returns_decoded_token_payload(): void
    {
        Http::fake([
            'github.com/login/oauth/access_token' => Http::response([
                'access_token' => 'gho_abc123',
                'token_type' => 'bearer',
                'scope' => 'read:user,repo',
                'refresh_token' => 'ghr_refresh',
                'expires_in' => 28800,
                'refresh_token_expires_in' => 15897600,
            ]),
        ]);

        $payload = $this->service()->exchangeCode('code-from-github');

        $this->assertSame('gho_abc123', $payload['access_token']);
        $this->assertSame('read:user,repo', $payload['scope']);
        $this->assertSame(28800, $payload['expires_in']);
    }

    public function test_exchange_code_throws_on_github_error(): void
    {
        Http::fake([
            'github.com/login/oauth/access_token' => Http::response([
                'error' => 'bad_verification_code',
                'error_description' => 'The code passed is incorrect or expired.',
            ]),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/incorrect or expired/i');

        $this->service()->exchangeCode('bad-code');
    }

    public function test_fetch_user_returns_github_profile(): void
    {
        Http::fake([
            'api.github.com/user' => Http::response([
                'id' => 9001,
                'login' => 'octocat',
                'name' => 'The Octocat',
            ]),
        ]);

        $user = $this->service()->fetchUser('gho_abc123');

        $this->assertSame(9001, $user['id']);
        $this->assertSame('octocat', $user['login']);
    }

    public function test_fetch_user_throws_on_unauthorized(): void
    {
        Http::fake([
            'api.github.com/user' => Http::response(['message' => 'Bad credentials'], 401),
        ]);

        $this->expectException(RuntimeException::class);

        $this->service()->fetchUser('expired-token');
    }
}
