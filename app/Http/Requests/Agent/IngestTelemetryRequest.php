<?php

namespace App\Http\Requests\Agent;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the agent telemetry payload (spec 027).
 *
 * Authorisation is delegated entirely to the `agent.auth` middleware
 * — a request that reaches `authorize()` already carries a valid host
 * on the request attributes.
 *
 * Skew window: `recorded_at` must be within [now - 1h, now + 5min].
 * Older than 1h defends against replay of stale captures; further than
 * 5min in the future catches a clock drift severe enough that we
 * can't trust the host's other timestamps either.
 */
class IngestTelemetryRequest extends FormRequest
{
    /** @var int Past skew tolerance, in seconds. */
    public const PAST_SKEW_SECONDS = 3600;

    /** @var int Future skew tolerance, in seconds. */
    public const FUTURE_SKEW_SECONDS = 300;

    public function authorize(): bool
    {
        return $this->attributes->get('agent_host') !== null;
    }

    public function rules(): array
    {
        return [
            'recorded_at' => ['required', 'date'],

            'host' => ['required', 'array'],
            'host.metrics' => ['required', 'array'],
            'host.metrics.cpu_percent' => ['nullable', 'numeric', 'between:0,100'],
            'host.metrics.memory_used_mb' => ['nullable', 'integer', 'min:0'],
            'host.metrics.memory_total_mb' => ['nullable', 'integer', 'min:0'],
            'host.metrics.disk_used_gb' => ['nullable', 'integer', 'min:0'],
            'host.metrics.disk_total_gb' => ['nullable', 'integer', 'min:0'],
            'host.metrics.load_average' => ['nullable', 'numeric', 'min:0'],
            'host.metrics.network_rx_bytes' => ['nullable', 'integer', 'min:0'],
            'host.metrics.network_tx_bytes' => ['nullable', 'integer', 'min:0'],

            'host.facts' => ['sometimes', 'array'],
            'host.facts.cpu_count' => ['nullable', 'integer', 'min:1', 'max:1024'],
            'host.facts.memory_total_mb' => ['nullable', 'integer', 'min:0'],
            'host.facts.disk_total_gb' => ['nullable', 'integer', 'min:0'],
            'host.facts.os' => ['nullable', 'string', 'max:80'],
            'host.facts.docker_version' => ['nullable', 'string', 'max:32'],

            'containers' => ['sometimes', 'array', 'max:500'],
            'containers.*.container_id' => ['required', 'string', 'max:80'],
            'containers.*.name' => ['required', 'string', 'max:255'],
            'containers.*.image' => ['required', 'string', 'max:255'],
            'containers.*.image_tag' => ['nullable', 'string', 'max:128'],
            'containers.*.status' => ['nullable', 'string', 'max:32'],
            'containers.*.state' => ['nullable', 'string', 'max:32'],
            'containers.*.health_status' => ['nullable', 'string', 'max:16'],
            'containers.*.ports' => ['sometimes', 'array'],
            'containers.*.labels' => ['sometimes', 'array'],
            'containers.*.metrics' => ['sometimes', 'array'],
            'containers.*.metrics.cpu_percent' => ['nullable', 'numeric'],
            'containers.*.metrics.memory_usage_mb' => ['nullable', 'integer', 'min:0'],
            'containers.*.metrics.memory_limit_mb' => ['nullable', 'integer', 'min:0'],
            'containers.*.metrics.network_rx_bytes' => ['nullable', 'integer', 'min:0'],
            'containers.*.metrics.network_tx_bytes' => ['nullable', 'integer', 'min:0'],
            'containers.*.metrics.block_read_bytes' => ['nullable', 'integer', 'min:0'],
            'containers.*.metrics.block_write_bytes' => ['nullable', 'integer', 'min:0'],
            'containers.*.started_at' => ['nullable', 'date'],
            'containers.*.finished_at' => ['nullable', 'date'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $value = $this->input('recorded_at');
            if (! is_string($value) || $value === '') {
                return; // Required-rule violation surfaces this case.
            }

            try {
                $recordedAt = CarbonImmutable::parse($value);
            } catch (\Throwable) {
                return; // `date` rule already added an error.
            }

            $now = CarbonImmutable::now();
            $earliest = $now->subSeconds(self::PAST_SKEW_SECONDS);
            $latest = $now->addSeconds(self::FUTURE_SKEW_SECONDS);

            if ($recordedAt->lessThan($earliest) || $recordedAt->greaterThan($latest)) {
                $validator->errors()->add(
                    'recorded_at',
                    'Telemetry timestamp is outside the accepted skew window (±'.
                    (self::PAST_SKEW_SECONDS / 60).' min past / '.
                    (self::FUTURE_SKEW_SECONDS / 60).' min future).',
                );
            }
        });
    }
}
