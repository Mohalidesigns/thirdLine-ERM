<x-mail::message>
# Your sign-in code

Hello {{ $name }},

Use this code to finish signing in to the {{ $clientName }} vendor portal.

<x-mail::panel>
# {{ $code }}
</x-mail::panel>

It expires in {{ $expiresInMinutes }} minutes and can only be used once.

**If you did not try to sign in, do not use this code.** Someone may have your
password. Tell your contact at {{ $clientName }}, and change the password you
use for this portal anywhere else you have used it.

We will never ask you for this code by phone, chat or email.

Thanks,<br>
{{ $clientName }}
</x-mail::message>
