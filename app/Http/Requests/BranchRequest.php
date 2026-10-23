<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BranchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage-business');
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('code'))) {
            $this->merge(['code' => strtoupper(trim($this->input('code')))]);
        }
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:30', 'regex:/^[A-Z0-9][A-Z0-9_-]*$/', Rule::unique('branches')->ignore($this->route('branch'))],
            'is_active' => ['required', 'boolean'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'],
            'address' => ['nullable', 'string', 'max:1000'],
            'currency' => ['required', Rule::in(array_keys(config('pos.currencies')))],
            'timezone' => ['required', 'timezone:all', 'max:64'],
            'tax_rate' => ['required', 'numeric', 'between:0,100', 'decimal:0,2'],
            'receipt_footer' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
