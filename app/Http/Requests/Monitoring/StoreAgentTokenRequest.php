<?php

namespace App\Http\Requests\Monitoring;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the create-token form. The DB column caps `name` at 80
 * chars; this request surfaces that as a validation error rather than
 * silently truncating in the controller.
 *
 * Authorisation is delegated to the host's `manageTokens` gate.
 */
class StoreAgentTokenRequest extends FormRequest
{
    public function authorize(): bool
    {
        $host = $this->route('host');

        return $host !== null
            && $this->user()?->can('manageTokens', $host) === true;
    }

    public function rules(): array
    {
        return [
            'name' => ['nullable', 'string', 'max:80'],
        ];
    }
}
