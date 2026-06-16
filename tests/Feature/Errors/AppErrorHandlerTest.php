<?php

namespace Tests\Feature\Errors;

use Exception;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class AppErrorHandlerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The custom render hook in bootstrap/app.php no-ops when
        // `APP_DEBUG=true` so Ignition stays. Force debug off here
        // so the hook actually fires.
        config(['app.debug' => false]);

        // Register a throwaway route that crashes hard. Used by every
        // test in this file to exercise the unhandled-exception path
        // without polluting the real routes.
        Route::get('/__test/__crash', function (): void {
            throw new Exception('test crash');
        });
    }

    public function test_unhandled_exception_renders_the_inertia_app_error_page(): void
    {
        // Inertia requests come with the `X-Inertia` header; without
        // it the handler falls through to Laravel's default response.
        // We send it explicitly so the hook resolves to the Inertia
        // render path.
        $response = $this->withHeaders([
            'X-Inertia' => 'true',
            'X-Inertia-Version' => '1',
        ])->get('/__test/__crash');

        $response->assertStatus(500);
        // Inertia responses carry the component name in the
        // `X-Inertia` response header path; we assert via the JSON
        // body which carries `component` + `props.status`.
        $payload = $response->json();
        $this->assertSame('Errors/AppError', $payload['component']);
        $this->assertSame(500, $payload['props']['status']);
    }

    public function test_non_inertia_html_request_also_renders_the_friendly_page(): void
    {
        // A direct browser visit (no X-Inertia header) that hits an
        // uncaught exception should still render the friendly page —
        // Laravel's default error template would otherwise show.
        $response = $this->withHeaders(['Accept' => 'text/html'])
            ->get('/__test/__crash');

        $response->assertStatus(500);
        // Inertia's HTML wrapper serializes the component into the
        // `data-page` attribute on the root <div>. Grepping that
        // proves the page rendered through Inertia, not the default
        // template.
        $this->assertStringContainsString('Errors/AppError', $response->getContent());
    }

    public function test_webhook_path_does_not_render_inertia_app_error_page(): void
    {
        // GitHub webhooks send `Accept: */*` (and similar machine-
        // origin requests omit the Accept header entirely). Both
        // satisfy `acceptsHtml() === true`, which is why the handler
        // also gates on the path prefix. Verify a crash on a
        // webhook-style path falls through to Laravel's default
        // response and never pulls the Inertia render pipeline in.
        Route::post('/webhooks/__test/__crash', function (): void {
            throw new Exception('webhook test crash');
        })->withoutMiddleware([
            ValidateCsrfToken::class,
        ]);

        $response = $this->withHeaders(['Accept' => '*/*'])
            ->post('/webhooks/__test/__crash');

        $response->assertStatus(500);
        $this->assertStringNotContainsString('Errors/AppError', $response->getContent());
    }

    public function test_json_consumer_does_not_render_inertia_app_error_page(): void
    {
        // A JSON consumer (eg. an explicit `Accept: application/json`
        // API client) must continue to receive a JSON-shaped error
        // response, not the Inertia HTML wrapper.
        $response = $this->withHeaders(['Accept' => 'application/json'])
            ->get('/__test/__crash');

        $response->assertStatus(500);
        $this->assertStringNotContainsString('Errors/AppError', $response->getContent());
    }
}
