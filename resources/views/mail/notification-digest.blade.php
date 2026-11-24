<x-mail::message>
{{ __('notifications.digest_intro') }}

@foreach ($groups as $group)
## {{ $group['label'] }}

@foreach ($group['items'] as $item)
- [{{ $item['message'] }}]({{ $item['url'] }})
@foreach ($item['lines'] as $line)
    - {{ $line }}
@endforeach
@endforeach

<small>[{{ __('notifications.unsubscribe') }}]({{ $group['unsubscribeUrl'] }})</small>

@endforeach
@if ($more > 0)
… +{{ $more }}
@endif

<x-mail::button :url="$inboxUrl">
{{ __('notifications.open_inbox') }}
</x-mail::button>

<x-mail::subcopy>
{{ __('notifications.footer') }}
[{{ __('notifications.manage') }}]({{ $settingsUrl }})
</x-mail::subcopy>
</x-mail::message>
