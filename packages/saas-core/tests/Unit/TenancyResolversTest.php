<?php

use SaaS\Core\Tenancy\Resolvers\SubdomainResolver;
use SaaS\Core\Tenancy\Resolvers\HeaderResolver;
use Illuminate\Http\Request;

/**
 * Test per i Resolver della tenancy.
 *
 * SubdomainResolver: legge il tenant dal sottodominio (web/Vue)
 * HeaderResolver: legge il tenant dall'header X-Tenant-ID (React Native)
 *
 * Questi test non toccano il DB — testano solo la logica di parsing
 * della request HTTP. Veloci e isolati.
 */

// ---------------------------------------------------------------------------
// SubdomainResolver
// ---------------------------------------------------------------------------

it('subdomain resolver estrae il tenant dal sottodominio', function () {
    $resolver = new SubdomainResolver();
    $request  = Request::create('https://acme.tuosaas.com/dashboard');

    expect($resolver->resolve($request))->toBe('acme');
});

it('subdomain resolver normalizza il tenant in lowercase', function () {
    $resolver = new SubdomainResolver();
    $request  = Request::create('https://ACME.tuosaas.com/dashboard');

    expect($resolver->resolve($request))->toBe('acme');
});

it('subdomain resolver restituisce null sul dominio root', function () {
    $resolver = new SubdomainResolver();
    $request  = Request::create('https://tuosaas.com/dashboard');

    expect($resolver->resolve($request))->toBeNull();
});

it('subdomain resolver restituisce null su localhost', function () {
    $resolver = new SubdomainResolver();
    $request  = Request::create('http://localhost/dashboard');

    expect($resolver->resolve($request))->toBeNull();
});

it('subdomain resolver gestisce sottodomini con trattino', function () {
    $resolver = new SubdomainResolver();
    $request  = Request::create('https://my-company.tuosaas.com/dashboard');

    expect($resolver->resolve($request))->toBe('my-company');
});

// ---------------------------------------------------------------------------
// HeaderResolver
// ---------------------------------------------------------------------------

it('header resolver estrae il tenant dall header X-Tenant-ID', function () {
    $resolver = new HeaderResolver();
    $request  = Request::create('https://api.tuosaas.com/users');
    $request->headers->set('X-Tenant-ID', 'acme');

    expect($resolver->resolve($request))->toBe('acme');
});

it('header resolver normalizza il tenant in lowercase', function () {
    $resolver = new HeaderResolver();
    $request  = Request::create('https://api.tuosaas.com/users');
    $request->headers->set('X-Tenant-ID', 'ACME');

    expect($resolver->resolve($request))->toBe('acme');
});

it('header resolver restituisce null se l header è assente', function () {
    $resolver = new HeaderResolver();
    $request  = Request::create('https://api.tuosaas.com/users');
    // Nessun header X-Tenant-ID

    expect($resolver->resolve($request))->toBeNull();
});

it('header resolver restituisce null se l header è vuoto', function () {
    $resolver = new HeaderResolver();
    $request  = Request::create('https://api.tuosaas.com/users');
    $request->headers->set('X-Tenant-ID', '');

    expect($resolver->resolve($request))->toBeNull();
});

it('la costante HEADER_NAME è X-Tenant-ID', function () {
    expect(HeaderResolver::HEADER_NAME)->toBe('X-Tenant-ID');
});

// ---------------------------------------------------------------------------
// CompositeResolver — header prima, poi sottodominio
// ---------------------------------------------------------------------------

it('composite resolver usa l header quando presente', function () {
    $resolver = new \SaaS\Core\Tenancy\Resolvers\CompositeResolver();
    $request  = Request::create('https://acme.tuosaas.com/api/users');
    $request->headers->set('X-Tenant-ID', 'globex');

    // L'header vince anche se il sottodominio dice altro (request mobile)
    expect($resolver->resolve($request))->toBe('globex');
});

it('composite resolver ripiega sul sottodominio senza header', function () {
    $resolver = new \SaaS\Core\Tenancy\Resolvers\CompositeResolver();
    $request  = Request::create('https://acme.tuosaas.com/dashboard');

    expect($resolver->resolve($request))->toBe('acme');
});

it('composite resolver restituisce null se nessuna strategia trova il tenant', function () {
    $resolver = new \SaaS\Core\Tenancy\Resolvers\CompositeResolver();
    $request  = Request::create('https://tuosaas.com/');

    expect($resolver->resolve($request))->toBeNull();
});

it('composite resolver accetta resolver custom in ordine', function () {
    $resolver = new \SaaS\Core\Tenancy\Resolvers\CompositeResolver(
        new \SaaS\Core\Tenancy\Resolvers\SubdomainResolver(),
    );
    $request  = Request::create('https://acme.tuosaas.com/api');
    $request->headers->set('X-Tenant-ID', 'globex');

    // Solo SubdomainResolver passato: l'header viene ignorato
    expect($resolver->resolve($request))->toBe('acme');
});
