<?php

namespace MultiTenantSaas\Modules\Voting\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreVoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'vote_type' => ['sometimes', 'string', 'in:single,multiple'],
            'status' => ['sometimes', 'string', 'in:draft,active,ended'],
            'start_at' => ['nullable', 'date'],
            'end_at' => ['nullable', 'date', 'after_or_equal:start_at'],
            'daily_limit' => ['nullable', 'integer', 'min:0'],
            'total_limit' => ['nullable', 'integer', 'min:0'],
            'daily_limit_per_user' => ['nullable', 'integer', 'min:0'],
            'total_limit_per_user' => ['nullable', 'integer', 'min:0'],
            'anti_cheat_ip' => ['nullable', 'boolean'],
            'show_result' => ['nullable', 'boolean'],
            'show_rank' => ['nullable', 'boolean'],
            'options' => ['required', 'array', 'min:2'],
            'options.*.title' => ['required', 'string', 'max:255'],
            'options.*.image' => ['nullable', 'string', 'max:512'],
            'options.*.description' => ['nullable', 'string', 'max:1000'],
            'options.*.sort_order' => ['nullable', 'integer'],
        ];
    }

    public function messages(): array
    {
        return [
            'title.required' => '投票标题不能为空',
            'options.required' => '投票选项不能为空',
            'options.min' => '投票选项至少需要2个',
            'options.*.title.required' => '选项标题不能为空',
        ];
    }
}
