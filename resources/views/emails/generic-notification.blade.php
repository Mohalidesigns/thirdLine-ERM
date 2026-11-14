<!doctype html>
<html lang="en">
<head><meta charset="utf-8"></head>
<body style="margin:0; padding:24px; background:#f4f5f7; font-family: -apple-system, Segoe UI, Roboto, Helvetica, Arial, sans-serif;">
    <div style="max-width:560px; margin:0 auto; background:#ffffff; border-radius:8px; border:1px solid #e2e8f0; overflow:hidden;">
        <div style="background:#1a365d; color:#ffffff; padding:18px 24px;">
            <p style="margin:0; font-size:16px; font-weight:700;">{{ config('app.name', 'Vantage ERM') }}</p>
            <p style="margin:3px 0 0; font-size:12px; opacity:.85; text-transform:uppercase; letter-spacing:.04em;">
                {{ ucfirst($category) }} Notification
            </p>
        </div>

        <div style="padding:24px;">
            <p style="margin:0 0 12px; font-size:15px; color:#1a202c; font-weight:600;">Hello {{ $recipient->name }},</p>

            <div style="margin:16px 0; padding:16px; background:#f8fafc; border-left:4px solid {{ $priority === 'high' ? '#e53e3e' : ($priority === 'low' ? '#718096' : '#3182ce') }}; border-radius:0 6px 6px 0;">
                <p style="margin:0 0 6px; font-size:15px; font-weight:600; color:#2d3748;">
                    {{ $notificationSubject }}
                </p>
                <p style="margin:0; font-size:14px; line-height:1.5; color:#4a5568;">
                    {!! nl2br(e($notificationBody)) !!}
                </p>
            </div>

            @if($actionUrl)
                <div style="text-align:center; margin:24px 0 16px;">
                    <a href="{{ $actionUrl }}"
                       style="display:inline-block; background:#1a365d; color:#ffffff; font-size:13px; font-weight:600; padding:10px 24px; border-radius:6px; text-decoration:none;">
                        View in {{ config('app.name', 'Vantage ERM') }}
                    </a>
                </div>
            @endif
        </div>

        <div style="padding:14px 24px; background:#f7fafc; border-top:1px solid #edf2f7; font-size:12px; color:#a0aec0; text-align:center;">
            &copy; {{ date('Y') }} {{ config('app.name', 'Vantage ERM') }}. All rights reserved.
        </div>
    </div>
</body>
</html>
