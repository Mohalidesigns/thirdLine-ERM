@php
    $overdue = $days_overdue !== null && $days_overdue > 0;
    $voice = match ($contact_role) {
        'team_lead' => "The department's risk champions have been asked to complete it. There's nothing for you to enter. If it is stuck, you may want to follow up with them or check who should be covering.",
        'unit_head' => "This return has now been reminded {$sequence} ".($sequence === 1 ? 'time' : 'times')." and has reached unit-head level. The risk champions and team leads have been asked to complete it. There's nothing for you to enter.",
        'group_head' => "This return has been reminded {$sequence} ".($sequence === 1 ? 'time' : 'times').", the highest escalation level. Risk champions, team leads and unit heads have been asked to complete it. There's nothing for you to enter.",
        default => "Please complete the return and press Submit return. You can save a draft and carry on later; nothing counts until you submit. If a value is in the red band you'll be asked for the root cause, an action point and a date. If you can't complete it, tell your team lead.",
    };
@endphp
Hello {{ $recipient_name }},

The KRI return for {{ $department }} for {{ $period }} has not been submitted. @if($overdue)It was due on {{ $due_long }} and is now {{ $days_overdue }} {{ $days_overdue === 1 ? 'day' : 'days' }} overdue.@else It is due on {{ $due_long }}.@endif

This is reminder {{ $sequence }}.@if($earlier) Earlier reminders went to {{ $earlier }}.@endif


{{ $voice }}

{{ $is_champion ? 'Complete my KRIs' : 'View the return' }}:
{{ $url }}

KRIs in the return ({{ count($kris) }}):
@forelse($kris_shown as $kri)
- {{ $kri['code'] }}  {{ $kri['name'] }}
@empty
No KRIs are currently due.
@endforelse
@if($kris_more > 0)
...and {{ $kris_more }} more in the return.
@endif

You're receiving this because you are the {{ $role_label }} for {{ $department }}. A reminder has been sent by the Risk Office.
This reminder went to: {{ $reached }}.

You'll be asked to sign in. {{ $app_name }}
Replies to this email are not read.
