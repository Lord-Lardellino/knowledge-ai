<?php

use SaaS\Core\Auth\Passkeys\PasskeyDeviceNames;
use Symfony\Component\Uid\Uuid;

it('risolve AAGUID Apple iCloud Keychain', function () {
    expect(PasskeyDeviceNames::resolve('b84e4048-15dc-4dd0-8640-f4f60813c8af'))
        ->toBe('iPhone / iPad (iCloud Keychain)');
});

it('risolve AAGUID Apple iPhone/iPad Face ID', function () {
    expect(PasskeyDeviceNames::resolve('adce0002-35bc-c60a-648b-0b25f1f05503'))
        ->toBe('iPhone / iPad');
});

it('risolve AAGUID Windows Hello', function () {
    expect(PasskeyDeviceNames::resolve('08987058-cadc-4b81-b6e1-30de50dcbe96'))
        ->toBe('Windows Hello');
});

it('risolve AAGUID Google Password Manager', function () {
    expect(PasskeyDeviceNames::resolve('d548826e-79b4-db40-a3d8-11116f7e8349'))
        ->toBe('Android (Google Password Manager)');
});

it('risolve AAGUID case-insensitive', function () {
    expect(PasskeyDeviceNames::resolve('B84E4048-15DC-4DD0-8640-F4F60813C8AF'))
        ->toBe('iPhone / iPad (iCloud Keychain)');
});

it('restituisce null per AAGUID nil (autenticatore non attestato)', function () {
    expect(PasskeyDeviceNames::resolve('00000000-0000-0000-0000-000000000000'))
        ->toBeNull();
});

it('restituisce null per AAGUID sconosciuto', function () {
    expect(PasskeyDeviceNames::resolve('aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee'))
        ->toBeNull();
});

it('restituisce null se aaguid è null', function () {
    expect(PasskeyDeviceNames::resolve(null))->toBeNull();
});

it('accetta un oggetto Symfony Uuid', function () {
    $uuid = Uuid::fromString('b84e4048-15dc-4dd0-8640-f4f60813c8af');
    expect(PasskeyDeviceNames::resolve($uuid))
        ->toBe('iPhone / iPad (iCloud Keychain)');
});

// ---------------------------------------------------------------------------
// detectBrowser
// ---------------------------------------------------------------------------

it('rileva Chrome dal User-Agent', function () {
    $ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/124.0.0.0 Safari/537.36';
    expect(PasskeyDeviceNames::detectBrowser($ua))->toBe('Chrome');
});

it('rileva Edge prima di Chrome (Edge contiene Chrome nel UA)', function () {
    $ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/124.0.0.0 Safari/537.36 Edg/124.0.0.0';
    expect(PasskeyDeviceNames::detectBrowser($ua))->toBe('Edge');
});

it('rileva Firefox dal User-Agent', function () {
    $ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:125.0) Gecko/20100101 Firefox/125.0';
    expect(PasskeyDeviceNames::detectBrowser($ua))->toBe('Firefox');
});

it('rileva Safari dal User-Agent', function () {
    $ua = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Version/17.0 Mobile/15E148 Safari/604.1';
    expect(PasskeyDeviceNames::detectBrowser($ua))->toBe('Safari');
});

it('rileva Samsung Internet prima di Chrome', function () {
    $ua = 'Mozilla/5.0 (Linux; Android 14) AppleWebKit/537.36 SamsungBrowser/24.0 Chrome/117.0.0.0 Safari/537.36';
    expect(PasskeyDeviceNames::detectBrowser($ua))->toBe('Samsung Internet');
});

it('restituisce Browser se UA sconosciuto', function () {
    expect(PasskeyDeviceNames::detectBrowser('curl/7.68.0'))->toBe('Browser');
});

// ---------------------------------------------------------------------------
// buildName
// ---------------------------------------------------------------------------

it('buildName combina device e browser con middot', function () {
    $ua = 'Mozilla/5.0 (Windows NT 10.0) AppleWebKit/537.36 Chrome/124.0 Safari/537.36';
    expect(PasskeyDeviceNames::buildName('b84e4048-15dc-4dd0-8640-f4f60813c8af', $ua))
        ->toBe('iPhone / iPad (iCloud Keychain) · Chrome');
});

it('buildName restituisce solo browser se AAGUID sconosciuto', function () {
    $ua = 'Mozilla/5.0 (Windows NT 10.0) AppleWebKit/537.36 Chrome/124.0 Safari/537.36';
    expect(PasskeyDeviceNames::buildName('aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', $ua))
        ->toBe('Chrome');
});

it('buildName restituisce solo browser se AAGUID nil', function () {
    $ua = 'Mozilla/5.0 AppleWebKit/537.36 Edg/124.0 Chrome/124.0 Safari/537.36';
    expect(PasskeyDeviceNames::buildName('00000000-0000-0000-0000-000000000000', $ua))
        ->toBe('Edge');
});
