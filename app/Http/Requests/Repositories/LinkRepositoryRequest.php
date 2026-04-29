<?php

namespace App\Http\Requests\Repositories;

use App\Models\Project;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates the manual link form. The free-form `repository` field
 * accepts either a full GitHub URL or a bare `owner/name` slug — the
 * action's parser is the source of truth, but we pre-screen so the
 * user gets a friendly error instead of a 500.
 */
class LinkRepositoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        $project = $this->resolvedProject();

        return $project !== null
            && $this->user()?->can('update', $project) === true;
    }

    public function rules(): array
    {
        return [
            'project_id' => ['required', Rule::exists('projects', 'id')],
            'repository' => [
                'required',
                'string',
                'min:3',
                'max:255',
                'regex:#^(?:https?://(?:www\.)?github\.com/[\w.-]+/[\w.-]+(?:\.git)?/?|[\w.-]+/[\w.-]+)$#i',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'repository.regex' => 'Enter a GitHub URL like `https://github.com/owner/name` or a slug like `owner/name`.',
        ];
    }

    /** Resolve the parent project once; cached for `authorize()` + the controller. */
    public function resolvedProject(): ?Project
    {
        $id = $this->input('project_id');

        return $id === null ? null : Project::query()->find($id);
    }
}
