<?php

namespace App\Http\Requests\Admin;

use App\Models\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        $role = $this->route('role');

        return $role instanceof Role
            ? $this->user()->can('update', $role)
            : $this->user()->can('create', Role::class);
    }

    public function rules(): array
    {
        $role = $this->route('role');
        $creating = ! $role instanceof Role;

        return [
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:120'],
            'slug' => [$creating ? 'required' : 'prohibited', 'string', 'max:64', 'regex:/^[a-z0-9-]+$/', Rule::unique('roles', 'slug')],
            'description' => ['nullable', 'string', 'max:500'],
            'permissions' => ['sometimes', 'array'],
            'permissions.*' => ['string', 'exists:permissions,slug'],
        ];
    }
}
