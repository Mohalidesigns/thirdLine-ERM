<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="light dark">
<title>{{ $department }}: You have been designated as Risk Champion</title>
</head>
<body bgcolor="#F4F5F7" style="margin:0; padding:0; background:#F4F5F7; font-family:Arial, Helvetica, sans-serif; color:#2D3748;">
<div style="display:none; max-height:0; overflow:hidden; opacity:0;">You have been designated as Risk Champion for {{ $department }}. Your monthly responsibility is to complete departmental KRIs.</div>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" bgcolor="#F4F5F7"><tr><td align="center" style="padding:16px;">
<table role="presentation" width="600" cellpadding="0" cellspacing="0" bgcolor="#FFFFFF" style="max-width:600px; width:100%; background:#FFFFFF;">
    <tr><td bgcolor="#1A365D" style="background:#1A365D; color:#FFFFFF; padding:16px 20px; font-size:16px; font-weight:bold; border-bottom:3px solid #D4AF37;">{{ $app_name }}</td></tr>
    <tr><td style="padding:20px 20px 0; font-size:16px; line-height:24px;">Hello {{ $recipient_name }},</td></tr>
    <tr><td style="padding:12px 20px 0;">
        <h1 style="margin:0; font-size:18px; line-height:26px; font-weight:normal;">You have been designated as a <strong>Risk Champion</strong> for <strong>{{ $department }}</strong>.</h1>
        <p style="margin:8px 0 0; font-size:15px; line-height:22px; color:#4B5563;">Assigned by {{ $assigned_by }}.</p>
    </td></tr>
    <tr><td style="padding:20px;">
        <table role="presentation" cellpadding="0" cellspacing="0"><tr><td bgcolor="#1A365D" style="background:#1A365D; border-radius:6px;">
            <a href="{{ $url }}" style="display:inline-block; padding:14px 24px; color:#FFFFFF; font-size:16px; font-weight:bold; text-decoration:none;">Complete my KRIs</a>
        </td></tr></table>
        <p style="margin:8px 0 0; font-size:12px; color:#4B5563;">Or copy this link: {{ $url }}</p>
    </td></tr>
    <tr><td style="padding:0 20px;">
        <h2 style="margin:0 0 8px; font-size:16px;">Your Monthly Responsibilities</h2>
        <ul style="margin:0; padding-left:18px; font-size:14px; line-height:22px;">
            <li><strong>Monthly KRI Readings:</strong> At the beginning of each working day of the month, you will receive an email notification when the departmental KRI return cycle opens.</li>
            <li><strong>Fill and Submit:</strong> Log in via the link to enter the readings for your department's Key Risk Indicators for the reporting month.</li>
            <li><strong>Red-Band Explanations:</strong> If any indicator breaches into the red threshold band, you will provide the root cause, an action point, and a target remediation timeline.</li>
            <li><strong>Save Drafts:</strong> You can save a draft at any time and resume later. Readings and issues are only formally recorded once you press <strong>Submit return</strong>.</li>
        </ul>
    </td></tr>
    <tr><td style="padding:16px 20px 0; font-size:14px; line-height:21px; color:#4B5563;">If you believe this assignment was made in error or have any questions, please contact your Risk Management Office.</td></tr>
    <tr><td style="padding:16px 20px 20px; font-size:12px; color:#4B5563;">You'll be asked to sign in. &middot; {{ $app_name }}<br>Replies to this email are not read.</td></tr>
</table>
</td></tr></table>
</body>
</html>
