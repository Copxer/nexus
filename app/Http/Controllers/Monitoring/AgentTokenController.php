<?php

namespace App\Http\Controllers\Monitoring;

use App\Domain\Docker\Actions\IssueAgentTokenAction;
use App\Domain\Docker\Actions\RevokeAgentTokenAction;
use App\Domain\Docker\Actions\RotateAgentTokenAction;
use App\Http\Controllers\Controller;
use App\Models\AgentToken;
use App\Models\Host;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Lifecycle endpoints for a host's agent token. Plaintext travels via
 * the session flash so the Vue layer can show it once on redirect and
 * then drop it. We never write the plaintext to logs, JSON, or
 * Inertia props.
 */
class AgentTokenController extends Controller
{
    public function store(Request $request, Host $host, IssueAgentTokenAction $issue): RedirectResponse
    {
        $this->authorize('manageTokens', $host);

        $name = $request->input('name');
        if ($name !== null && ! is_string($name)) {
            $name = null;
        }

        $result = $issue->execute(
            $host,
            $name !== null ? mb_substr($name, 0, 80) : null,
            $request->user(),
        );

        return redirect()
            ->route('monitoring.hosts.show', $host)
            ->with('status', 'Agent token issued. Copy it now — it will not be shown again.')
            ->with('agentTokenPlaintext', $result->plaintext);
    }

    public function rotate(Request $request, Host $host, AgentToken $token, RotateAgentTokenAction $rotate): RedirectResponse
    {
        $this->authorize('manageTokens', $host);
        $this->ensureBelongs($host, $token);

        $name = $request->input('name');
        if ($name !== null && ! is_string($name)) {
            $name = null;
        }

        $result = $rotate->execute(
            $host,
            $name !== null ? mb_substr($name, 0, 80) : null,
            $request->user(),
        );

        return redirect()
            ->route('monitoring.hosts.show', $host)
            ->with('status', 'Agent token rotated. Copy the new token now — it will not be shown again.')
            ->with('agentTokenPlaintext', $result->plaintext);
    }

    public function destroy(Host $host, AgentToken $token, RevokeAgentTokenAction $revoke): RedirectResponse
    {
        $this->authorize('manageTokens', $host);
        $this->ensureBelongs($host, $token);

        $revoke->execute($token);

        return redirect()
            ->route('monitoring.hosts.show', $host)
            ->with('status', 'Agent token revoked.');
    }

    /**
     * Defence in depth — the route already binds {host} and {token}
     * separately, so a mismatched pair would otherwise just 404 on the
     * agent's next request. Surface the mismatch as a 404 here.
     */
    private function ensureBelongs(Host $host, AgentToken $token): void
    {
        abort_if($token->host_id !== $host->id, 404);
    }
}
