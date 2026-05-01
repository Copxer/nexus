<?php

namespace App\Http\Requests\Monitoring;

use App\Enums\HostConnectionType;
use App\Models\Host;
use App\Models\Project;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates the create-host form. Project ownership is gated via the
 * `HostPolicy::create` policy.
 */
class StoreHostRequest extends FormRequest
{
    public function authorize(): bool
    {
        $project = $this->resolvedProject();

        return $this->user()?->can('create', [Host::class, $project]) === true;
    }

    public function rules(): array
    {
        return [
            'project_id' => ['required', Rule::exists('projects', 'id')],
            'name' => ['required', 'string', 'max:120'],
            'provider' => ['nullable', 'string', 'max:32'],
            'endpoint_url' => ['nullable', 'url', 'max:2048'],
            'connection_type' => ['required', Rule::enum(HostConnectionType::class)],
        ];
    }

    public function resolvedProject(): ?Project
    {
        $id = $this->input('project_id');

        return $id !== null ? Project::query()->find($id) : null;
    }
}
