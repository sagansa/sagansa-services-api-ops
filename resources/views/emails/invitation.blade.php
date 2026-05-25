@component('mail::message')
# Undangan Bergabung

Hai {{ $user->email }},

Anda telah diundang untuk bergabung dengan **{{ $tenant->name }}**.

Klik tombol di bawah ini untuk menyelesaikan pendaftaran dan mengatur password Anda.

@component('mail::button', ['url' => config('app.frontend_url') . '/accept-invite?token=' . $token])
Terima Undangan
@endcomponent

Jika Anda tidak mengharapkan undangan ini, Anda dapat mengabaikan email ini.

Terima kasih,<br>
{{ config('app.name', 'SAGANSA') }}
@endcomponent
