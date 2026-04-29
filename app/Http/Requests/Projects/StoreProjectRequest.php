<?php

namespace App\Http\Requests\Projects;

use App\Enums\ProjectPriority;
use App\Enums\ProjectStatus;
use App\Models\Project;
use App\Support\ProjectPalette;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class StoreProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Project::class) ?? false;
    }

    /**
     * Field rules. Color and icon are nullable but constrained to the
     * curated lists from the spec — keeps the dashboard visually
     * coherent and prevents one-off palette drift.
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
            'status' => ['required', new Enum(ProjectStatus::class)],
            'priority' => ['required', new Enum(ProjectPriority::class)],
            'environment' => ['nullable', 'string', 'max:64'],
            'color' => ['nullable', Rule::in(ProjectPalette::COLORS)],
            'icon' => ['nullable', Rule::in(ProjectPalette::ICONS)],
        ];
    }
}
