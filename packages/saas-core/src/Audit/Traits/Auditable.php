<?php

namespace SaaS\Core\Audit\Traits;

use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;

/**
 * Auditable Trait
 *
 * Aggiunge logging automatico di ogni modifica a un Eloquent Model.
 * Basta aggiungere `use Auditable` al Model per attivarlo.
 *
 * COSA VIENE LOGGATO:
 *   - created  → nuovo record creato
 *   - updated  → record modificato (salva i valori prima e dopo)
 *   - deleted  → record cancellato (soft o hard delete)
 *
 * DOVE VANNO I LOG:
 *   Nella tabella `activity_log` gestita da spatie/laravel-activitylog.
 *   Il driver può essere cambiato in saas-core.php ('database' o 'elasticsearch').
 *
 * COME SI USA:
 *   class Invoice extends Model {
 *       use Auditable;   ← questo è tutto
 *   }
 *
 * GDPR + RETENTION:
 *   I Model in 'audit.financial_models' (config) non vengono mai
 *   anonimizzati dal GdprEraser, anche se l'utente chiede cancellazione.
 *   La retention minima è 7 anni per obbligo di legge (dati fiscali).
 *
 * DIPENDE DA: spatie/laravel-activitylog
 */
trait Auditable
{
    use LogsActivity;

    /**
     * Configura le opzioni di logging per questo Model.
     * Spatie chiama questo metodo automaticamente quando registra un evento.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            // Logga tutti gli attributi del Model, non solo alcuni
            ->logAll()

            // Salva solo i campi che sono effettivamente cambiati in un update
            // Evita log inutili con tutti gli attributi anche se ne è cambiato uno solo
            ->logOnlyDirty()

            // Salva sia il valore PRIMA che DOPO la modifica
            // Esempio: ['email' => ['old' => 'a@b.com', 'new' => 'c@d.com']]
            ->dontSubmitEmptyLogs()

            // Il nome del log identifica il tipo di Model nei report di audit
            // Usa il nome della classe senza namespace: "Invoice", "User", ecc.
            ->useLogName($this->getAuditLogName());
    }

    /**
     * Nome del log usato per identificare questo Model nell'activity_log.
     * Può essere sovrascritto nel Model per un nome custom.
     */
    protected function getAuditLogName(): string
    {
        // Prende solo il nome della classe senza il namespace completo
        // App\Models\Invoice → "Invoice"
        return class_basename(static::class);
    }

    /**
     * Verifica se questo Model è soggetto a retention finanziaria.
     * I Model nella lista 'financial_models' della config non vengono anonimizzati.
     */
    public function isFinancialModel(): bool
    {
        $financialModels = config('saas-core.audit.financial_models', []);

        return in_array(static::class, $financialModels, strict: true);
    }
}
