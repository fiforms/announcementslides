<x-mail::message>
{{ __('notifications.immediate_intro') }}

**{{ $message }}**

@foreach ($lines as $line)
- {{ $line }}
@endforeach

<x-mail::button :url="$url">
{{ __('notifications.view') }}
</x-mail::button>

<x-mail::subcopy>
{{ __('notifications.footer') }}
[{{ __('notifications.manage') }}]({{ $settingsUrl }}) · [{{ __('notifications.unsubscribe') }}]({{ $unsubscribeUrl }})
</x-mail::subcopy>
</x-mail::message>
