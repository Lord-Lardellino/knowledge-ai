<?php

namespace SaaS\Core\Auth\Recovery;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * RecoveryLinkNotification
 *
 * Notifica email con il magic link di recupero accesso.
 *
 * Usa il sistema di Notification di Laravel invece di un Mailable diretto:
 *   - Si invia con $user->notify(new RecoveryLinkNotification($link))
 *   - Funziona con qualsiasi driver (mail, slack, ecc.)
 *   - Nessuna necessità di registrare un Mailable nel ServiceProvider
 *
 * Il template usa MailMessage di Laravel (Markdown email built-in).
 * L'app può sovrascrivere il template pubblicando le views:
 *   php artisan vendor:publish --tag=laravel-notifications
 */
class RecoveryLinkNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly string $link
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $appName = config('app.name', 'SaaS');

        return (new MailMessage)
            ->subject("Recupero accesso — {$appName}")
            ->greeting("Ciao {$notifiable->name},")
            ->line('Hai richiesto un link per accedere al tuo account senza passkey.')
            ->line('Clicca il pulsante qui sotto per accedere. Il link è valido per **15 minuti**.')
            ->action('Accedi ora', $this->link)
            ->line('Dopo aver effettuato l\'accesso, ti consigliamo di registrare subito un nuovo dispositivo.')
            ->line('Se non hai fatto questa richiesta, ignora questa email. Il tuo account è al sicuro.')
            ->salutation("Il team di {$appName}");
    }
}
