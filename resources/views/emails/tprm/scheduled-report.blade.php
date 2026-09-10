@component('mail::message')
# {{ $reportTitle }}

{{ $organizationName }} — position as at **{{ $asAt }}**.

The report is attached as `{{ $fileName }}` and holds
{{ $rowCount }} {{ Str::plural('row', $rowCount) }}.

{{-- The figures stay in the attachment. A distribution list for a third-party
     report routinely includes a shared mailbox, and counts of overdue
     assessments or undecided sanctions matches in the body of an unencrypted
     email are published to whatever forwards it. --}}

@component('mail::panel')
**Scope of this report**

@foreach ($provenance as $label => $value)
- **{{ $label }}:** {{ $value }}
@endforeach
@endcomponent

This was sent by the standing schedule **{{ $scheduleName }}**, set up by
{{ $ownerName }}. If you should not be receiving it, ask them rather than the
platform — the recipient list is theirs to change.

@endcomponent
