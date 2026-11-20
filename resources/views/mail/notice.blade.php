{{--
    Laravel's notification mail in Laravel's mail layout, as the notice fills it, for an application named Laravel.

    Laravel's mail header shows Laravel's logo from laravel.com in place of an application name that reads exactly
    "Laravel", and the notice loads nothing from elsewhere. The empty HTML comment behind the name keeps the header
    from reading it so: a mail program shows nothing for it, and the text layout strips every tag from the header.
    Every component comes from the `mail` namespace, so what the application published of them applies here too.
--}}
<x-mail::layout>
<x-slot:header>
<x-mail::header :url="config('app.url')">
{{ config('app.name') }}<!-- -->
</x-mail::header>
</x-slot:header>

@if (! empty($greeting))
# {{ $greeting }}
@else
# @lang('Hello!')
@endif

@foreach ($introLines as $line)
{{ $line }}

@endforeach

@isset($actionText)
<x-mail::button :url="$actionUrl" color="primary">
{{ $actionText }}
</x-mail::button>
@endisset

@foreach ($outroLines as $line)
{{ $line }}

@endforeach

@if (! empty($salutation))
{{ $salutation }}
@else
@lang('Regards,')<br>
{{ config('app.name') }}
@endif

@isset($actionText)
<x-slot:subcopy>
<x-mail::subcopy>
@lang(
    "If you're having trouble clicking the \":actionText\" button, copy and paste the URL below\n".
    'into your web browser:',
    [
        'actionText' => $actionText,
    ]
) <span class="break-all">[{{ $displayableActionUrl }}]({{ $actionUrl }})</span>
</x-mail::subcopy>
</x-slot:subcopy>
@endisset

<x-slot:footer>
<x-mail::footer>
© {{ date('Y') }} {{ config('app.name') }}. @lang('All rights reserved.')
</x-mail::footer>
</x-slot:footer>
</x-mail::layout>
