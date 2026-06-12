<?php

namespace SaaS\Core\Tenancy;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use SaaS\Core\Tenancy\Models\Tenant;

/**
 * TenantInviteNotification
 *
 * Email di invito a unirsi a un tenant. Il link porta alla pagina di
 * registrazione con il token in query string: il frontend lo legge e
 * lo passa a /auth/passkey/register/options come invite_token.
 *
 * Il token è in chiaro SOLO qui (e nell'email): nel DB c'è l'hash.
 */
class TenantInviteNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly Tenant $tenant,
        private readonly string $token,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = rtrim(config('app.url'), '/') . '/register?invite=' . $this->token;

        return (new MailMessage)
            ->subject('Sei stato invitato in ' . $this->tenant->name)
            ->greeting('Ciao!')
            ->line("Sei stato invitato a unirti a **{$this->tenant->name}**.")
            ->line('Per accettare, registrati con la tua passkey: nessuna password da ricordare.')
            ->action('Accetta l\'invito', $url)
            ->line('Il link scade tra ' . config('saas-core.tenancy.invite_ttl_days', 7) . ' giorni.')
            ->line('Se non ti aspettavi questo invito puoi ignorare questa email.');
    }
}
