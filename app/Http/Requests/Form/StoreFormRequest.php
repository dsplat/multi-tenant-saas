<?php

namespace App\Http\Requests\Form;

use Illuminate\Foundation\Http\FormRequest;

class StoreFormRequest extends FormRequest
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
            'status' => ['sometimes', 'string', 'in:draft,published,closed'],
            'submit_limit' => ['nullable', 'integer', 'min:0'],
            'start_at' => ['nullable', 'date'],
            'end_at' => ['nullable', 'date', 'after_or_equal:start_at'],
            'submit_text' => ['nullable', 'string', 'max:32'],
            'success_message' => ['nullable', 'string', 'max:255'],
            'is_public' => ['nullable', 'boolean'],
            'require_login' => ['nullable', 'boolean'],
            'fields' => ['required', 'array', 'min:1'],
            'fields.*.field_key' => ['required', 'string', 'max:64', 'regex:/^[a-zA-Z_][a-zA-Z0-9_]*$/'],
            'fields.*.field_type' => ['required', 'string', 'in:text,textarea,number,email,phone,date,time,datetime,select,multi_select,radio,checkbox,file,image,rich_text,rating,signature,location,cascader'],
            'fields.*.label' => ['required', 'string', 'max:128'],
            'fields.*.placeholder' => ['nullable', 'string', 'max:255'],
            'fields.*.default_value' => ['nullable'],
            'fields.*.options' => ['nullable', 'array'],
            'fields.*.is_required' => ['nullable', 'boolean'],
            'fields.*.sort_order' => ['nullable', 'integer'],
            'fields.*.validation_rules' => ['nullable', 'array'],
        ];
    }

    public function messages(): array
    {
        return [
            'title.required' => '表单标题不能为空',
            'fields.required' => '表单字段不能为空',
            'fields.min' => '表单至少需要1个字段',
            'fields.*.field_key.required' => '字段标识不能为空',
            'fields.*.field_key.regex' => '字段标识只能包含字母、数字和下划线，且以字母或下划线开头',
            'fields.*.field_type.required' => '字段类型不能为空',
            'fields.*.label.required' => '字段标签不能为空',
        ];
    }
}
