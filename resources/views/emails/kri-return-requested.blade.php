<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="light dark">
<title>{{ $department }}: KRI readings for {{ $period }} due {{ $due_short }}</title>
</head>
<body bgcolor="#F4F5F7" style="margin:0; padding:0; background:#F4F5F7; font-family:Arial, Helvetica, sans-serif; color:#2D3748;">
<div style="display:none; max-height:0; overflow:hidden; opacity:0;">{{ count($kris) }} KRIs to complete in Complete my KRIs. Save a draft any time; nothing counts until you submit.</div>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" bgcolor="#F4F5F7"><tr><td align="center" style="padding:16px;">
<table role="presentation" width="600" cellpadding="0" cellspacing="0" bgcolor="#FFFFFF" style="max-width:600px; width:100%; background:#FFFFFF;">
    <tr><td bgcolor="#1A365D" style="background:#1A365D; color:#FFFFFF; padding:16px 20px; font-size:16px; font-weight:bold; border-bottom:3px solid #D4AF37;">{{ $app_name }}</td></tr>
    <tr><td style="padding:20px 20px 0; font-size:16px; line-height:24px;">Hello {{ $recipient_name }},</td></tr>
    <tr><td style="padding:12px 20px 0;">
        <h1 style="margin:0; font-size:18px; line-height:26px; font-weight:normal;">Your KRI readings for <strong>{{ $period }}</strong> are due on <strong>{{ $due_long }}</strong>.</h1>
        <p style="margin:8px 0 0; font-size:16px; line-height:24px;">This is the monthly request for <strong>{{ $department }}</strong>.</p>
    </td></tr>
    <tr><td style="padding:20px;">
        <table role="presentation" cellpadding="0" cellspacing="0"><tr><td bgcolor="#1A365D" style="background:#1A365D; border-radius:6px;">
            <a href="{{ $url }}" style="display:inline-block; padding:14px 24px; color:#FFFFFF; font-size:16px; font-weight:bold; text-decoration:none;">{{ $is_champion ? 'Complete my KRIs' : 'View the return' }}</a>
        </td></tr></table>
        <p style="margin:8px 0 0; font-size:12px; color:#4B5563;">Or copy this link: {{ $url }}</p>
    </td></tr>
    <tr><td style="padding:0 20px;">
        <h2 style="margin:0 0 8px; font-size:16px;">KRIs to complete ({{ count($kris) }})</h2>
        @if($kris_shown === [])
            <p style="margin:0; font-size:14px;">No KRIs are currently due. Open your return to check.</p>
        @else
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="font-size:14px;">
            <tr><th scope="col" align="left" style="padding:4px 8px 4px 0; color:#4B5563; font-size:12px;">Code</th><th scope="col" align="left" style="padding:4px 0; color:#4B5563; font-size:12px;">Indicator</th></tr>
            @foreach($kris_shown as $kri)
                <tr><td style="padding:4px 8px 4px 0; font-family:'Courier New', monospace;">{{ $kri['code'] }}</td><td style="padding:4px 0;">{{ $kri['name'] }}</td></tr>
            @endforeach
        </table>
        @if($kris_more > 0)<p style="margin:6px 0 0; font-size:14px;">&hellip;and {{ $kris_more }} more in your return.</p>@endif
        @endif
    </td></tr>
    <tr><td style="padding:16px 20px 0; font-size:14px; line-height:21px;">
        <ul style="margin:0; padding-left:18px;">
            <li>Enter each KRI's value. You can <strong>save a draft</strong> and come back; nothing counts until you press <strong>Submit return</strong>.</li>
            <li>If a value falls in the red band you'll be asked for the root cause, an action point and a date.</li>
            <li>You can reopen and correct a submitted return until the month is closed.</li>
        </ul>
    </td></tr>
    <tr><td style="padding:16px 20px 0; font-size:14px; line-height:21px;">You're receiving this because you are the <strong>{{ $role_label }}</strong> for {{ $department }}. If that's wrong, tell your Risk Office so the department's contacts can be updated.</td></tr>
    <tr><td style="padding:16px 20px 20px; font-size:12px; color:#4B5563;">You'll be asked to sign in. &middot; {{ $app_name }}<br>Replies to this email are not read.</td></tr>
</table>
</td></tr></table>
</body>
</html>
