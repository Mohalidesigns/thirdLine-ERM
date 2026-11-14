<!doctype html>
<html lang="en">
<head><meta charset="utf-8"></head>
<body style="margin:0; padding:24px; background:#f4f5f7; font-family: -apple-system, Segoe UI, Roboto, Helvetica, Arial, sans-serif;">
    <div style="max-width:560px; margin:0 auto; background:#ffffff; border-radius:8px; border:1px solid #e2e8f0; overflow:hidden;">
        <div style="background:#1a365d; color:#ffffff; padding:20px 24px;">
            <p style="margin:0; font-size:18px; font-weight:700;">{{ config('app.name', 'Vantage ERM') }}</p>
            <p style="margin:4px 0 0; font-size:13px; opacity:.85;">Account Verification &amp; Activation</p>
        </div>

        <div style="padding:24px;">
            <p style="margin:0 0 16px; font-size:15px; color:#1a202c; font-weight:600;">Hello {{ $user->name }},</p>
            <p style="margin:0 0 16px; font-size:14px; line-height:1.6; color:#4a5568;">
                An account has been created for you on <strong>{{ config('app.name', 'Vantage ERM') }}</strong>.
                To verify your email and activate your account, please click the button below to set your permanent password:
            </p>

            <div style="text-align:center; margin:28px 0;">
                <a href="{{ $verificationUrl }}"
                   style="display:inline-block; background:#1a365d; color:#ffffff; font-size:14px; font-weight:600; padding:12px 28px; border-radius:6px; text-decoration:none;">
                    Verify Email &amp; Set Password
                </a>
            </div>

            <div style="background:#f7fafc; border:1px solid #edf2f7; border-radius:6px; padding:16px; margin:20px 0;">
                <p style="margin:0 0 8px; font-size:12px; font-weight:700; text-transform:uppercase; letter-spacing:.05em; color:#718096;">
                    Account Information
                </p>
                <p style="margin:0 0 6px; font-size:13px; color:#2d3748;">
                    <strong>Email:</strong> {{ $user->email }}
                </p>
                <p style="margin:0; font-size:13px; color:#2d3748;">
                    <strong>Temporary Password:</strong> <code style="background:#edf2f7; padding:2px 6px; border-radius:4px; font-size:13px;">{{ $tempPassword }}</code>
                </p>
            </div>

            <p style="margin:16px 0 0; font-size:12px; line-height:1.5; color:#718096;">
                This link is valid for 48 hours. If you did not expect an account or need assistance, please contact your system administrator.
            </p>
        </div>

        <div style="padding:16px 24px; background:#f7fafc; border-top:1px solid #edf2f7; font-size:12px; color:#a0aec0; text-align:center;">
            &copy; {{ date('Y') }} {{ config('app.name', 'Vantage ERM') }}. All rights reserved.
        </div>
    </div>
</body>
</html>
