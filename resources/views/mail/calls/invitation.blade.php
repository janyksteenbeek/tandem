<x-mail::message>
# {{ $inviterName }} invited you to a meeting

**{{ $title }}** · {{ $organization }}

@if ($note)
> {{ $note }}

@endif
Join from your browser. There is nothing to install and you do not need an account.

<x-mail::button :url="$joinUrl">
Join meeting
</x-mail::button>

@if ($hasPassword)
This meeting has a password. Ask {{ $inviterName }} for it if you are prompted.

@endif
Or copy this link: {{ $joinUrl }}

Thanks,<br>
{{ $brand }}
</x-mail::message>
