<!doctype html>
<html lang="en">
<head><meta charset="utf-8"></head>
<body style="margin:0; padding:24px; background:#f4f5f7; font-family: -apple-system, Segoe UI, Roboto, Helvetica, Arial, sans-serif;">
    <div style="max-width:560px; margin:0 auto; background:#ffffff; border-radius:8px; border:1px solid #e2e8f0; overflow:hidden;">
        <div style="background:{{ $days_overdue !== null ? '#9b2c2c' : '#1a365d' }}; color:#ffffff; padding:16px 20px;">
            <p style="margin:0; font-size:15px; font-weight:700;">Reminder: monthly KRI return not submitted</p>
            <p style="margin:2px 0 0; font-size:12px; opacity:.8;">{{ $department }} &middot; {{ $period }}</p>
        </div>

        <div style="padding:16px 20px 4px; font-size:13px; line-height:1.5; color:#2d3748;">
            <p style="margin:0 0 10px;">Hello {{ $recipient_name }},</p>
            <p style="margin:0 0 10px;">
                The KRI return for <strong>{{ $department }}</strong> covering <strong>{{ $period }}</strong>
                has not been submitted.
                @if($days_overdue !== null)
                    It was due on <strong>{{ $due_on }}</strong> and is <strong>{{ $days_overdue }} {{ \Illuminate\Support\Str::plural('day', $days_overdue) }} overdue</strong>.
                @else
                    It is due on <strong>{{ $due_on }}</strong>.
                @endif
            </p>
        </div>

        <div style="padding:4px 20px 8px;">
            <p style="margin:0 0 6px; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.05em; color:#4a5568;">
                KRIs due ({{ count($kris) }})
            </p>
            @forelse($kris as $kri)
                <p style="margin:0 0 4px; font-size:13px;">
                    <span style="color:#718096;">{{ $kri['code'] }}</span> &middot; {{ $kri['name'] }}
                </p>
            @empty
                <p style="margin:0; font-size:13px; color:#718096;">No KRIs are currently due.</p>
            @endforelse
        </div>

        <div style="padding:14px 20px; border-top:1px solid #edf2f7;">
            <a href="{{ $url }}"
               style="display:inline-block; background:#1a365d; color:#ffffff; font-size:12px; font-weight:600; padding:8px 14px; border-radius:6px; text-decoration:none;">
                {{ $is_champion ? 'Complete my KRIs' : 'View the return' }}
            </a>
        </div>
        <p style="margin:0; padding:0 20px 14px; font-size:11px; color:#a0aec0;">Replies to this email are not read.</p>
    </div>
</body>
</html>
