<?php

namespace App\Http\Requests\Monitoring;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the edit-host form. `project_id` is intentionally NOT
 * editable post-create — moving a host between projects would orphan
 * its telemetry. Re-create if needed.
 *
 * `connection_type` is also frozen post-create: changing it mid-flight
 * would invalidate the existing agent's contract.
 */
class UpdateHostRequest extends FormRequest
{
    public function authorize(): bool
    {
        $host = $this->route('host');

        return $host !== null
            && $this->user()?->can('update', $host) === true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'provider' => ['nullable', 'string', 'max:32'],
            'endpoint_url' => ['nullable', 'url', 'max:2048'],
        ];
    }
}
