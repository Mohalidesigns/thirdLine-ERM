@php
    $appName = config('app.name');
    $late = $days_overdue !== null && $days_overdue > 0;
    $kriTotal = count($kris);
    $kriShown = array_slice($kris, 0, 25);
    $tierWords = [
        1 => 'risk champions',
        2 => 'risk champions and team leads',
        3 => 'risk champions, team leads and unit heads',
        4 => 'all four levels, including group heads',
    ][max(1, min(4, (int) $tier))];
    $preheader = $is_champion
        ? 'Complete and submit it in Complete my KRIs.'
        : 'The return has not been submitted. You are receiving this as a contact for '.$department.'.';
    $subject = $late
        ? 'Overdue: '.$department.' KRI return for '.$period.' ('.$days_overdue.' '.\Illuminate\Support\Str::plural('day', $days_overdue).')'
        : 'Reminder: '.$department.' KRI return for '.$period.' is due '.$due_on;
@endphp
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="light dark">
<meta name="supported-color-schemes" content="light dark">
<title>{{ $subject }}</title>
</head>
<body bgcolor="#F4F5F7" style="margin:0; padding:0; background-color:#F4F5F7; font-family:Arial, Helvetica, sans-serif; color:#2D3748;">
<div style="display:none; max-height:0; overflow:hidden; opacity:0; font-size:1px; line-height:1px; color:#F4F5F7;">{{ $preheader }}</div>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#F4F5F7" style="background-color:#F4F5F7;">
<tr><td align="center" style="padding:16px 8px;">
<table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" bgcolor="#FFFFFF" style="width:100%; max-width:600px; background-color:#FFFFFF;">
    <tr><td bgcolor="#1A365D" style="background-color:#1A365D; color:#FFFFFF; padding:16px 24px; font-size:16px; line-height:24px; font-weight:bold;">{{ $appName }}</td></tr>
    <tr><td bgcolor="#D4AF37" height="3" style="background-color:#D4AF37; height:3px; line-height:3px; font-size:3px;">&nbsp;</td></tr>

    <tr><td style="padding:24px 24px 0; font-size:16px; line-height:24px; color:#2D3748;">
        <p style="margin:0 0 16px;">Hello {{ $recipient_name }},</p>
        <h1 style="margin:0 0 12px; font-size:18px; line-height:26px; font-weight:normal; color:#2D3748;">
            The KRI return for <strong>{{ $department }}</strong> for <strong>{{ $period }}</strong> has not been submitted.
            @if ($late)
                It was due on <strong>{{ $due_on }}</strong> and is now <strong>{{ $days_overdue }} {{ \Illuminate\Support\Str::plural('day', $days_overdue) }} overdue</strong>.
            @else
                It is due on <strong>{{ $due_on }}</strong>.
            @endif
        </h1>
        <p style="margin:0 0 16px; font-size:14px; line-height:22px;">This reminder was sent by the Risk Office and went to {{ $tierWords }}.</p>
    </td></tr>

    <tr><td style="padding:0 24px 16px; font-size:14px; line-height:22px; color:#2D3748;">
        @if ($is_champion)
            Please complete the return and press <strong>Submit return</strong>. You can save a draft and carry on later; nothing counts until you submit. If a value is in the red band you'll be asked for the root cause, an action point and a date. If you can't complete it, tell your team lead.
        @else
            The department's risk champions have been asked to complete it. There's nothing for you to enter. If it is stuck, you may want to follow up with them or check who should be covering.
        @endif
    </td></tr>

    <tr><td style="padding:0 24px 8px;">
        <table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr>
            <td bgcolor="#1A365D" style="background-color:#1A365D; border-radius:6px;">
                <a href="{{ $url }}" style="display:inline-block; padding:14px 24px; font-family:Arial, Helvetica, sans-serif; font-size:16px; line-height:16px; font-weight:bold; color:#FFFFFF; text-decoration:none;">{{ $is_champion ? 'Complete my KRIs' : 'View the return' }}</a>
            </td>
        </tr></table>
    </td></tr>
    <tr><td style="padding:0 24px 20px; font-size:12px; line-height:18px; color:#4B5563;">Or copy this link: <a href="{{ $url }}" style="color:#1A365D; text-decoration:underline; word-break:break-all;">{{ $url }}</a></td></tr>

    <tr><td style="padding:0 24px 20px;">
        <h2 style="margin:0 0 8px; font-size:16px; line-height:24px; color:#2D3748;">KRIs in the return ({{ $kriTotal }})</h2>
        @if ($kriTotal === 0)
            <p style="margin:0; font-size:14px; line-height:20px;">No KRIs are currently due. Open the return to check.</p>
        @else
            <table width="100%" cellpadding="0" cellspacing="0" border="0" style="font-size:14px; line-height:20px; border-collapse:collapse;">
                <thead>
                    <tr>
                        <th scope="col" align="left" style="padding:6px 8px 6px 0; border-bottom:1px solid #CBD5E0; color:#4B5563; font-size:12px; width:110px;">Code</th>
                        <th scope="col" align="left" style="padding:6px 0; border-bottom:1px solid #CBD5E0; color:#4B5563; font-size:12px;">Indicator</th>
                    </tr>
                </thead>
                <tbody>
                @foreach ($kriShown as $kri)
                    <tr>
                        <td style="padding:6px 8px 6px 0; border-bottom:1px solid #EDF2F7; font-family:'Courier New', monospace; vertical-align:top;">{{ $kri['code'] }}</td>
                        <td style="padding:6px 0; border-bottom:1px solid #EDF2F7; vertical-align:top;">{{ $kri['name'] }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
            @if ($kriTotal > 25)
                <p style="margin:8px 0 0; font-size:14px; line-height:20px;">&hellip;and {{ $kriTotal - 25 }} more in the return.</p>
            @endif
        @endif
    </td></tr>

    <tr><td style="padding:0 24px 24px; font-size:14px; line-height:22px; color:#2D3748;">
        @if ($is_champion)
            You're receiving this because you are the <strong>Risk champion</strong> for {{ $department }}.
        @else
            You're receiving this because you are a KRI contact for {{ $department }}.
        @endif
        A reminder has been sent by the Risk Office.
    </td></tr>

    <tr><td bgcolor="#F7FAFC" style="background-color:#F7FAFC; padding:16px 24px; font-size:12px; line-height:18px; color:#4B5563;">
        You'll be asked to sign in. Replies to this email are not read.<br>{{ $appName }}
    </td></tr>
</table>
</td></tr>
</table>
</body>
</html>
