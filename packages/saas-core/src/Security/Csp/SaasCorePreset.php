<?php

namespace SaaS\Core\Security\Csp;

use Spatie\Csp\Directive;
use Spatie\Csp\Keyword;
use Spatie\Csp\Policy;
use Spatie\Csp\Preset;

/**
 * SaasCorePreset — Content Security Policy di default
 *
 * COSA È IL CSP?
 *   Content Security Policy è un header HTTP che dice al browser
 *   da dove può caricare risorse (script, stili, immagini, font, ecc.).
 *   È la difesa principale contro XSS: anche se un attaccante inietta
 *   del codice JavaScript nella pagina, il browser lo blocca
 *   perché non proviene da una fonte autorizzata.
 *
 * COME SI USA IN SPATIE CSP v3:
 *   Implementa l'interfaccia Preset — non estende Policy.
 *   configure(Policy $policy) riceve la policy e vi aggiunge le direttive.
 *
 *   Per usarlo nell'app, pubblica config/csp.php e imposta:
 *     'policy' => \SaaS\Core\Security\Csp\SaasCorePreset::class,
 *
 *   Oppure composilo con altri preset:
 *     Policy::create(presets: [SaasCorePreset::class, Stripe::class])
 *
 * COME PERSONALIZZARE PER UN SINGOLO SAAS:
 *   class MyCspPreset implements Preset {
 *       public function configure(Policy $policy): void {
 *           (new SaasCorePreset)->configure($policy);
 *           $policy->add(Directive::SCRIPT, 'https://cdn.stripe.com');
 *       }
 *   }
 *
 * DIRETTIVE CONFIGURATE:
 *   default-src 'self'          → tutto dal proprio dominio, nulla da fuori
 *   script-src  'self' + nonce  → script solo dal proprio server o con nonce
 *   style-src   'self' + nonce  → stili solo dal proprio server o con nonce
 *   img-src     'self' data:    → immagini locali + data URI (avatar base64)
 *   font-src    'self'          → font locali
 *   connect-src 'self'          → fetch/XHR solo verso il proprio server
 *   frame-ancestors 'none'      → nessun iframe (rinforza X-Frame-Options)
 *   object-src  'none'          → nessun plugin (Flash ecc.)
 *   base-uri    'self'          → protegge da <base href="..."> injection
 *   form-action 'self'          → i form possono postare solo al proprio server
 */
class SaasCorePreset implements Preset
{
    public function configure(Policy $policy): void
    {
        $policy
            ->add(Directive::DEFAULT, Keyword::SELF)
            ->add(Directive::OBJECT, Keyword::NONE)
            ->add(Directive::BASE, Keyword::SELF)
            ->add(Directive::FORM_ACTION, Keyword::SELF)
            ->add(Directive::FRAME_ANCESTORS, Keyword::NONE)
            ->add(Directive::IMG, [Keyword::SELF, 'data:'])
            ->add(Directive::FONT, Keyword::SELF)
            ->add(Directive::CONNECT, Keyword::SELF);

        if (config('saas-core.security.csp_nonce', true)) {
            // Con nonce: script e stili inline sono permessi solo se hanno il nonce.
            // Il nonce è generato da Spatie e disponibile in Blade via @cspNonce.
            $policy
                ->addNonce(Directive::SCRIPT)
                ->addNonce(Directive::STYLE);
        } else {
            // Senza nonce: solo file esterni (adatto per backend API-only)
            $policy
                ->add(Directive::SCRIPT, Keyword::SELF)
                ->add(Directive::STYLE, Keyword::SELF);
        }
    }
}
