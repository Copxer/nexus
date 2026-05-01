<?php

namespace App\Http\Requests\Monitoring;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates the edit-website form. The `project_id` is intentionally
 * NOT editable post-create — moving a website between projects would
 * orphan its check history's project context. Re-create if needed.
 */
class UpdateWebsiteRequest extends FormRequest
{
    public function authorize(): bool
    {
        $website = $this->route('website');

        return $website !== null
            && $this->user()?->can('update', $website) === true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'url' => ['required', 'url', 'max:2048'],
            'method' => ['required', Rule::in(['GET', 'HEAD', 'POST'])],
            'expected_status_code' => ['required', 'integer', 'between:100,599'],
            'timeout_ms' => ['required', 'integer', 'min:1000', 'max:60000'],
            'check_interval_seconds' => ['required', 'integer', 'min:60', 'max:86400'],
        ];
    }
}
