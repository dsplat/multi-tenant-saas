<?php

namespace App\Http\Requests\Lottery;

use Illuminate\Foundation\Http\FormRequest;

class StoreLotteryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:128', 'regex:/^[a-z0-9\-]+$/'],
            'description' => ['nullable', 'string', 'max:2000'],
            'status' => ['sometimes', 'string', 'in:draft,active,paused,ended'],
            'rules' => ['nullable', 'array'],
            'rules.max_per_user' => ['nullable', 'integer', 'min:0'],
            'rules.require_login' => ['nullable', 'boolean'],
            'rules.anti_bot' => ['nullable', 'boolean'],
            'rules.animation_type' => ['nullable', 'string', 'in:wheel,scratch,egg,blindbox'],
            'rules.animation_duration' => ['nullable', 'integer', 'min:1000', 'max:10000'],
            'rules.animation_sound' => ['nullable', 'boolean'],
            'rules.animation_confetti' => ['nullable', 'boolean'],
            'rules.wheel_segments' => ['nullable', 'integer', 'min:4', 'max:24'],
            'rules.scratch_threshold' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'start_at' => ['nullable', 'date'],
            'end_at' => ['nullable', 'date', 'after_or_equal:start_at'],
            'prizes' => ['nullable', 'array', 'min:1'],
            'prizes.*.name' => ['required_with:prizes', 'string', 'max:128'],
            'prizes.*.image_url' => ['nullable', 'string', 'max:512'],
            'prizes.*.type' => ['sometimes', 'string', 'in:physical,virtual,credit,coupon'],
            'prizes.*.value' => ['nullable', 'numeric', 'min:0'],
            'prizes.*.total_count' => ['required_with:prizes', 'integer', 'min:0'],
            'prizes.*.weight' => ['required_with:prizes', 'integer', 'min:0'],
            'prizes.*.sort_order' => ['nullable', 'integer'],
        ];
    }

    public function messages(): array
    {
        return [
            'title.required' => '活动标题不能为空',
            'title.max' => '活动标题不能超过255个字符',
            'slug.required' => 'URL标识不能为空',
            'slug.regex' => 'URL标识只能包含小写字母、数字和连字符',
            'prizes.*.name.required_with' => '奖品名称不能为空',
            'prizes.*.total_count.required_with' => '奖品数量不能为空',
            'prizes.*.weight.required_with' => '奖品权重不能为空',
        ];
    }
}
