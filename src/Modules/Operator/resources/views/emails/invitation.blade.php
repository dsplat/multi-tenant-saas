<x-mail::message>
# {{ trans('operator.invite_greeting', ['name' => $operatorName]) }}

{{ trans('operator.invite_body', ['tenant' => $tenantName, 'role' => $role]) }}

<x-mail::button :url="$inviteUrl" color="primary">
{{ trans('operator.invite_button') }}
</x-mail::button>

{{ trans('operator.invite_expiry') }}

{{ trans('operator.invite_footer') }}
</x-mail::message>
