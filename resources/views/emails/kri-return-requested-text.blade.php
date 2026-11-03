Hello {{ $recipient_name }},

Your KRI readings for {{ $period }} are due on {{ $due_long }}.
This is the monthly request for {{ $department }}.

{{ $is_champion ? 'Complete my KRIs' : 'View the return' }}:
{{ $url }}

KRIs to complete ({{ count($kris) }}):
@forelse($kris_shown as $kri)
- {{ $kri['code'] }}  {{ $kri['name'] }}
@empty
No KRIs are currently due. Open your return to check.
@endforelse
@if($kris_more > 0)
...and {{ $kris_more }} more in your return.
@endif

- Enter each KRI's value. You can save a draft and come back; nothing counts until you press Submit return.
- If a value falls in the red band you'll be asked for the root cause, an action point and a date.
- You can reopen and correct a submitted return until the month is closed.

You're receiving this because you are the {{ $role_label }} for {{ $department }}. If that's wrong, tell your Risk Office so the department's contacts can be updated.

You'll be asked to sign in. {{ $app_name }}
Replies to this email are not read.
