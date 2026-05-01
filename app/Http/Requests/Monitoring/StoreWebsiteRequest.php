<?php

namespace App\Http\Requests\Monitoring;

use App\Models\Project;
use App\Models\Website;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates the create-website form. Project ownership is
 * authorised via the `WebsitePolicy::create` gate; the request also
 * pins the field shapes to keep the controller branch-free.
 */
class StoreWebsiteRequest extends FormRequest
{
    public function authorize(): bool
    {
        $project = $this->resolvedProject();

        return $this->user()?->can('create', [Website::class, $project]) === true;
    }

    public function rules(): array
    {
        return [
            'project_id' => ['required', Rule::exists('projects', 'id')],
            'name' => ['required', 'string', 'max:120'],
            'url' => ['required', 'url', 'max:2048'],
            'method' => ['required', Rule::in(['GET', 'HEAD', 'POST'])],
            'expected_status_code' => ['required', 'integer', 'between:100,599'],
            'timeout_ms' => ['required', 'integer', 'min:1000', 'max:60000'],
            'check_interval_seconds' => ['required', 'integer', 'min:60', 'max:86400'],
        ];
    }

    public function resolvedProject(): ?Project
    {
        $id = $this->input('project_id');

        return $id !== null ? Project::query()->find($id) : null;
    }
}
