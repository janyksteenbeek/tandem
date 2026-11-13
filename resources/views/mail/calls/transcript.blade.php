<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="light dark">
<title>{{ $transcript['title'] }}</title>
</head>
<body style="margin:0;padding:0;background:#f4f4f7;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:#1f2330;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f4f7;padding:32px 12px;">
<tr><td align="center">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:640px;background:#ffffff;border-radius:14px;overflow:hidden;border:1px solid #e6e6ee;">
<tr><td style="padding:28px 32px 20px 32px;border-bottom:1px solid #eeeef3;">
    <div style="font-size:12px;letter-spacing:.06em;text-transform:uppercase;color:#7a7f93;font-weight:600;">Meeting chat · {{ $transcript['organization'] }}</div>
    <h1 style="margin:8px 0 0 0;font-size:22px;line-height:1.3;font-weight:650;color:#14161f;">{{ $transcript['title'] }}</h1>
    <table role="presentation" cellpadding="0" cellspacing="0" style="margin-top:14px;font-size:14px;line-height:1.6;color:#4a4f63;">
        @if ($transcript['started_at'])
        <tr><td style="padding-right:14px;color:#7a7f93;vertical-align:top;">Date</td><td>{{ $transcript['started_at']->format('l j F Y, H:i') }} <span style="color:#9a9fb0;">({{ $transcript['timezone'] }})</span></td></tr>
        @endif
        @if ($transcript['duration'])
        <tr><td style="padding-right:14px;color:#7a7f93;vertical-align:top;">Duration</td><td>{{ $transcript['duration'] }}</td></tr>
        @endif
        <tr><td style="padding-right:14px;color:#7a7f93;vertical-align:top;">Participants</td><td>
            @forelse ($transcript['participants'] as $participant)
                {{ $participant['name'] }}{{ $participant['is_guest'] ? ' (guest)' : '' }}{{ $loop->last ? '' : ',' }}
            @empty
                Nobody joined
            @endforelse
        </td></tr>
    </table>
</td></tr>

@if ($transcript['recordings'] !== [])
<tr><td style="padding:18px 32px 0 32px;">
    @foreach ($transcript['recordings'] as $recording)
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f6f4ff;border:1px solid #e3dcff;border-radius:10px;margin-bottom:8px;">
        <tr>
            <td style="padding:12px 16px;font-size:14px;color:#2b2550;"><strong>Recording</strong> · {{ $recording['label'] }} · {{ $recording['duration'] }}</td>
            <td align="right" style="padding:12px 16px;"><a href="{{ $recording['url'] }}" style="font-size:14px;font-weight:600;color:#603df3;text-decoration:none;">Watch →</a></td>
        </tr>
    </table>
    @endforeach
</td></tr>
@endif

<tr><td style="padding:22px 32px 8px 32px;">
    @forelse ($transcript['messages'] as $message)
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:14px;">
        <tr><td style="font-size:13px;color:#7a7f93;padding-bottom:3px;">
            <strong style="color:#14161f;font-weight:600;">{{ $message['author'] }}</strong>
            &nbsp;{{ $message['at']?->format('H:i') }}
            @if ($message['reply_to'])
                &nbsp;· replying to {{ $message['reply_to'] }}
            @endif
            @if ($message['edited'])
                &nbsp;· edited
            @endif
        </td></tr>
        <tr><td style="font-size:15px;line-height:1.55;color:#1f2330;">
            @if ($message['deleted'])
                <em style="color:#9a9fb0;">Message deleted</em>
            @else
                @if ($message['body'] !== null)
                <div style="white-space:pre-wrap;word-break:break-word;">{{ $message['body'] }}</div>
                @endif
                @foreach ($message['files'] as $file)
                <div style="margin-top:6px;padding:8px 12px;border:1px solid #e6e6ee;border-radius:8px;font-size:14px;display:inline-block;">
                    @if ($file['url'])
                    <a href="{{ $file['url'] }}" style="color:#603df3;text-decoration:none;font-weight:600;">{{ $file['filename'] }}</a>
                    @else
                    <span style="font-weight:600;">{{ $file['filename'] }}</span>
                    @endif
                    <span style="color:#9a9fb0;">&nbsp;{{ $file['size'] }}</span>
                </div>
                @endforeach
            @endif
        </td></tr>
    </table>
    @empty
    <p style="font-size:15px;color:#7a7f93;margin:0 0 14px 0;">No messages were sent in this meeting.</p>
    @endforelse
</td></tr>

<tr><td style="padding:8px 32px 28px 32px;">
    <a href="{{ $transcript['url'] }}" style="display:inline-block;background:#603df3;color:#ffffff;text-decoration:none;font-weight:600;font-size:14px;padding:10px 18px;border-radius:9px;">Open meeting in {{ $brand }}</a>
    <p style="font-size:12px;line-height:1.6;color:#9a9fb0;margin:16px 0 0 0;">
        File and recording links in this email are personal to it and stop working on {{ $transcript['links_expire_at']->format('j F Y') }}.
        Opening the meeting in {{ $brand }} requires an account in {{ $transcript['organization'] }}.
    </p>
</td></tr>
</table>
</td></tr>
</table>
</body>
</html>
