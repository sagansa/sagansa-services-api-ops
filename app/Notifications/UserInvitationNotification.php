<?php

namespace App\Notifications;

use App\Models\Tenant;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class UserInvitationNotification extends Notification
{
    public function __construct(
        private readonly Tenant $tenant,
        private readonly string $token,
        private readonly ?string $inviterName = null,
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $baseUrl = rtrim(
            config('app.frontend_url')
                ?? env('FRONTEND_URL')
                ?? env('FRONTEND_URLS')
                ?? config('app.url'),
            '/'
        );

        $invitationUrl = $baseUrl . '/auth/invite?token=' . $this->token;

        $mail = (new MailMessage())
            ->subject('Undangan Bergabung ke ' . $this->tenant->name)
            ->greeting('Halo!')
            ->line(sprintf(
                'Anda diundang untuk bergabung ke tenant %s.',
                $this->tenant->name,
            ));

        if ($this->inviterName) {
            $mail->line(sprintf('Undangan dikirim oleh %s.', $this->inviterName));
        }

        return $mail
            ->line('Silakan selesaikan proses pendaftaran dan verifikasi email dengan menekan tombol di bawah ini, kemudian atur kata sandi yang akan digunakan untuk login.')
            ->action('Lengkapi Pendaftaran', $invitationUrl)
            ->line('Jika Anda tidak merasa menerima undangan ini, abaikan email ini.');
    }
}
