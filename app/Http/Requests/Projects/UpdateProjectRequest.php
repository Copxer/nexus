<?php

namespace App\Http\Requests\Projects;

use App\Enums\ProjectPriority;
use App\Enums\ProjectStatus;
use App\Support\ProjectPalette;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class UpdateProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        $project = $this->route('project');

        return $project !== null && $this->user()?->can('update', $project);
    }

    /**
     * Spec 047 — every field is `sometimes` so partial forms don't
     * clobber siblings. The main `ProjectForm` still posts every field
     * today; the public-status panel on `Projects/Edit.vue` posts only
     * `public_status_*` fields to prevent stale main-form state from
     * overwriting a fresh save.
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'min:2', 'max:120'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'status' => ['sometimes', 'required', new Enum(ProjectStatus::class)],
            'priority' => ['sometimes', 'required', new Enum(ProjectPriority::class)],
            'environment' => ['sometimes', 'nullable', 'string', 'max:64'],
            'color' => ['sometimes', 'nullable', Rule::in(ProjectPalette::COLORS)],
            'icon' => ['sometimes', 'nullable', Rule::in(ProjectPalette::ICONS)],
            // Spec 047 — per-project opt-in for the public status page.
            'public_status_enabled' => ['sometimes', 'boolean'],
            'public_status_headline' => ['sometimes', 'nullable', 'string', 'max:240'],
        ];
    }
}
