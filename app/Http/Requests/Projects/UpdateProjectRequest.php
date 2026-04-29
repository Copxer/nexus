<?php

namespace App\Http\Requests\Projects;

use App\Enums\ProjectPriority;
use App\Enums\ProjectStatus;
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
     * Same shape as the store request — Update is a full PATCH/PUT replace.
     * If we move to partial updates later we'll switch to `sometimes`
     * everywhere, but the form posts every field today.
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
            'status' => ['required', new Enum(ProjectStatus::class)],
            'priority' => ['required', new Enum(ProjectPriority::class)],
            'environment' => ['nullable', 'string', 'max:64'],
            'color' => ['nullable', Rule::in(['cyan', 'blue', 'purple', 'magenta', 'success', 'warning'])],
            'icon' => ['nullable', Rule::in([
                'FolderKanban', 'Rocket', 'GitBranch', 'Server',
                'Globe', 'BarChart3', 'Bell', 'Activity',
                'HeartPulse', 'Cpu', 'Database', 'Cloud',
            ])],
        ];
    }
}
