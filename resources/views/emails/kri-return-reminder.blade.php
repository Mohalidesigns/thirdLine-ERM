@php
    $overdue = $days_overdue !== null && $days_overdue > 0;
    $voice = match ($contact_role) {
        'team_lead' => "The department's risk champions have been asked to complete it. There's nothing for you to enter. If it is stuck, you may want to follow up with them or check who should be covering.",
        'unit_head' => "This return has now been reminded {$sequence} ".($sequence === 1 ? 'time' : 'times')." and has reached unit-head level. The risk champions and team leads have been asked to complete it. There's nothing for you to enter.",
        'group_head' => "This return has been reminded {$sequence} ".($sequence === 1 ? 'time' : 'times').", the highest escalation level. Risk champions, team leads and unit heads have been asked to complete it. There's nothing for you to enter.",
        default => "Please complete the return and press Submit return. You can save a draft and carry on later; nothing counts until you submit. If a value is in the red band you'll be asked for the root cause, an action point and a date. If you can't complete it, tell your team lead.",
    };
    $preheader = $is_champion
        ? "Complete and submit it in Complete my KRIs. Reminder {$sequence}."
        : "Reminder {$sequence}: the return has not been submitted. You are receiving this as {$role_label}.";
@endphp
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="light dark">
<title>{{ $overdue ? "Overdue: {$department} KRI return for {$period}" : "Reminder: {$department} KRI return for {$period}" }}</title>
</head>
<body bgcolor="#F4F5F7" style="margin:0; padding:0; background:#F4F5F7; font-family:Arial, Helvetica, sans-serif; color:#2D3748;">
<div style="display:none; max-height:0; overflow:hidden; opacity:0;">{{ $preheader }}</div>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" bgcolor="#F4F5F7"><tr><td align="center" style="padding:16px;">
<table role="presentation" width="600" cellpadding="0" cellspacing="0" bgcolor="#FFFFFF" style="max-width:600px; width:100%; background:#FFFFFF;">
    <tr><td bgcolor="#1A365D" style="background:#1A365D; color:#FFFFFF; padding:16px 20px; font-size:16px; font-weight:bold; border-bottom:3px solid #D4AF37;">{{ $app_name }}</td></tr>
    <tr><td style="padding:20px 20px 0; font-size:16px; line-height:24px;">Hello {{ $recipient_name }},</td></tr>
    <tr><td style="padding:12px 20px 0;">
        <h1 style="margin:0; font-size:18px; line-height:26px; font-weight:normal;">
            The KRI return for <strong>{{ $department }}</strong> for <strong>{{ $period }}</strong> has not been submitted.
            @if($overdue)
                It was due on <strong>{{ $due_long }}</strong> and is now <strong>{{ $days_overdue }} {{ $days_overdue === 1 ? 'day' : 'days' }} overdue</strong>.
            @else
                It is due on <strong>{{ $due_long }}</strong>.
            @endif
        </h1>
        <p style="margin:8px 0 0; font-size:14px; line-height:21px;">
            This is reminder <strong>{{ $sequence }}</strong>.
            @if($earlier) Earlier reminders went to {{ $earlier }}.@endif
        </p>
    </td></tr>
    <tr><td style="padding:12px 20px 0; font-size:16px; line-height:24px;">{{ $voice }}</td></tr>
    <tr><td style="padding:20px;">
        <table role="presentation" cellpadding="0" cellspacing="0"><tr><td bgcolor="#1A365D" style="background:#1A365D; border-radius:6px;">
            <a href="{{ $url }}" style="display:inline-block; padding:14px 24px; color:#FFFFFF; font-size:16px; font-weight:bold; text-decoration:none;">{{ $is_champion ? 'Complete my KRIs' : 'View the return' }}</a>
        </td></tr></table>
        <p style="margin:8px 0 0; font-size:12px; color:#4B5563;">Or copy this link: {{ $url }}</p>
    </td></tr>
    <tr><td style="padding:0 20px;">
        <h2 style="margin:0 0 8px; font-size:16px;">KRIs in the return ({{ count($kris) }})</h2>
        @if($kris_shown === [])
            <p style="margin:0; font-size:14px;">No KRIs are currently due.</p>
        @else
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="font-size:14px;">
            <tr><th scope="col" align="left" style="padding:4px 8px 4px 0; color:#4B5563; font-size:12px;">Code</th><th scope="col" align="left" style="padding:4px 0; color:#4B5563; font-size:12px;">Indicator</th></tr>
            @foreach($kris_shown as $kri)
                <tr><td style="padding:4px 8px 4px 0; font-family:'Courier New', monospace;">{{ $kri['code'] }}</td><td style="padding:4px 0;">{{ $kri['name'] }}</td></tr>
            @endforeach
        </table>
        @if($kris_more > 0)<p style="margin:6px 0 0; font-size:14px;">&hellip;and {{ $kris_more }} more in the return.</p>@endif
        @endif
    </td></tr>
    <tr><td style="padding:16px 20px 0; font-size:14px; line-height:21px;">You're receiving this because you are the <strong>{{ $role_label }}</strong> for {{ $department }}. A reminder has been sent by the Risk Office.</td></tr>
    <tr><td style="padding:8px 20px 0; font-size:14px; line-height:21px;">This reminder went to: {{ $reached }}.</td></tr>
    <tr><td style="padding:16px 20px 20px; font-size:12px; color:#4B5563;">You'll be asked to sign in. &middot; {{ $app_name }}<br>Replies to this email are not read.</td></tr>
</table>
</td></tr></table>
</body>
</html>
