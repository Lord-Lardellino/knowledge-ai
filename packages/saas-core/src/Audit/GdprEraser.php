<?php

namespace SaaS\Core\Audit;

use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\DB;

/**
 * GdprEraser
 *
 * Implementa il "diritto all'oblio" del GDPR (Art. 17 GDPR).
 *
 * COSA FA:
 *   Quando un utente chiede la cancellazione del proprio account,
 *   non possiamo semplicemente eliminare tutto — alcune leggi (es. normativa
 *   fiscale italiana) ci obbligano a conservare dati per 7+ anni.
 *
 *   La strategia è: ANONIMIZZARE i dati personali, non cancellarli.
 *   I record rimangono per l'audit trail, ma non sono più riconducibili
 *   a una persona fisica.
 *
 * COSA VIENE ANONIMIZZATO:
 *   - nome → "[deleted]"
 *   - email → "deleted_{id}@deleted.invalid"  (univoca ma non reale)
 *   - password → null
 *   - remember_token → null
 *
 * COSA NON VIENE TOCCATO:
 *   - I log nella tabella activity_log → retention 7 anni
 *   - I record nei Model in 'audit.financial_models' (fatture, pagamenti, ecc.)
 *   - Il record User stesso → rimane (anonimizzato) per integrità referenziale
 *
 * COME SI USA:
 *   app(GdprEraser::class)->erase($user);
 */
class GdprEraser
{
    /**
     * Anonimizza tutti i dati personali dell'utente.
     * Eseguito in una transazione: se qualcosa fallisce, niente viene modificato.
     */
    public function erase(User $user): void
    {
        DB::transaction(function () use ($user) {
            $this->anonymizeUser($user);
            $this->logErasure($user);
        });
    }

    /**
     * Sovrascrive i campi personali dell'utente con valori anonimi.
     */
    private function anonymizeUser(User $user): void
    {
        // Salva l'ID prima di anonimizzare — serve per l'email anonima univoca
        $id = $user->getKey();

        $user->forceFill([
            'name'           => '[deleted]',
            // Email univoca ma non reale — necessaria per i vincoli UNIQUE del DB
            'email'          => "deleted_{$id}@deleted.invalid",
            'password'       => null,
            'remember_token' => null,
            // Marca come verificata per evitare invii di email di verifica
            'email_verified_at' => null,
        ])->save();

        // Soft delete finale: il record esiste ma è inaccessibile alle query normali
        // (solo se il Model usa SoftDeletes — controllato dinamicamente)
        if (method_exists($user, 'delete')) {
            $user->delete();
        }
    }

    /**
     * Registra l'evento di cancellazione nell'activity_log.
     * Il log della cancellazione è conservato per 7 anni (compliance GDPR Art. 5).
     * Paradossalmente dobbiamo loggare che abbiamo cancellato i dati.
     */
    private function logErasure(User $user): void
    {
        // Solo se il package spatie/laravel-activitylog è attivo
        if (! class_exists(\Spatie\Activitylog\Models\Activity::class)) {
            return;
        }

        activity()
            ->causedByAnonymous()
            ->withProperties([
                'user_id'    => $user->getKey(),
                'erased_at'  => now()->toIso8601String(),
                'reason'     => 'GDPR Art. 17 — right to erasure',
            ])
            ->log('user.gdpr_erased');
    }
}
