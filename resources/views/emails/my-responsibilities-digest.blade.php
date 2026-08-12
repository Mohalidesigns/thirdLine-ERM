<!doctype html>
<html lang="en">
<head><meta charset="utf-8"></head>
<body style="margin:0; padding:24px; background:#f4f5f7; font-family: -apple-system, Segoe UI, Roboto, Helvetica, Arial, sans-serif;">
    <div style="max-width:560px; margin:0 auto; background:#ffffff; border-radius:8px; border:1px solid #e2e8f0; overflow:hidden;">
        <div style="background:#1a365d; color:#ffffff; padding:16px 20px;">
            <p style="margin:0; font-size:15px; font-weight:700;">My Responsibilities</p>
            <p style="margin:2px 0 0; font-size:12px; opacity:.8;">
                {{ $queue['total_items'] }} {{ Str::plural('item', $queue['total_items']) }} ·
                estimated ~{{ $queue['total_minutes'] }} min
            </p>
        </div>

        @foreach(['overdue' => 'Overdue', 'today' => 'Due today', 'this_week' => 'This week'] as $bucket => $label)
            @php $items = $queue['buckets'][$bucket] ?? []; @endphp
            @if($items !== [])
                <div style="padding:14px 20px 4px;">
                    <p style="margin:0 0 6px; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.05em; color:{{ $bucket === 'overdue' ? '#c53030' : '#4a5568' }};">
                        {{ $label }} ({{ count($items) }})
                    </p>
                    @foreach($items as $item)
                        <p style="margin:0 0 8px; font-size:13px; line-height:1.4;">
                            <a href="{{ $item['url'] }}" style="color:#1a365d; font-weight:600; text-decoration:none;">{{ $item['title'] }}</a>
                            @if($item['due_at'])
                                <span style="color:#a0aec0; font-size:11px;"> · due {{ \Carbon\Carbon::parse($item['due_at'])->format('d M') }}</span>
                            @endif
                            <span style="color:#a0aec0; font-size:11px;"> · ~{{ $item['minutes'] }} min</span>
                        </p>
                    @endforeach
                </div>
            @endif
        @endforeach

        <div style="padding:14px 20px; border-top:1px solid #edf2f7;">
            <a href="{{ route('my.index') }}"
               style="display:inline-block; background:#1a365d; color:#ffffff; font-size:12px; font-weight:600; padding:8px 14px; border-radius:6px; text-decoration:none;">
                Open my queue
            </a>
        </div>
    </div>
</body>
</html>
