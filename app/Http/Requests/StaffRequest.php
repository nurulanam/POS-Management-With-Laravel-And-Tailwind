<?php

namespace App\Http\Requests;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class StaffRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage-business');
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => strtolower(trim($this->input('email')))]);
        }
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique(User::class)->ignore($this->route('user'))],
            'role' => ['required', Rule::enum(UserRole::class)],
            'is_active' => ['required', 'boolean'],
            'password' => [$this->isMethod('post') ? 'required' : 'nullable', 'confirmed', Password::min(12)->letters()->numbers(), 'max:72'],
            'branch_ids' => [Rule::requiredIf($this->input('role') !== UserRole::Admin->value && $this->boolean('is_active')), 'array'],
            'branch_ids.*' => ['integer', 'distinct', Rule::exists('branches', 'id')->where('is_active', true)],
        ];
    }
}
