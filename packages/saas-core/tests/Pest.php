<?php

/**
 * tests/Pest.php — Punto di bootstrap di PestPHP
 *
 * Pest cerca questo file in tests/Pest.php (non nella root del progetto).
 * Qui diciamo a Pest quale TestCase base usare per ogni cartella di test.
 *
 * uses(TestCase::class)->in('Unit', 'Feature') significa:
 *   "ogni test in tests/Unit/ e tests/Feature/ estende automaticamente
 *    SaaS\Core\Tests\TestCase, che avvia il mini-framework Laravel"
 *
 * Questo è il motivo per cui nei test possiamo usare $this->postJson(),
 * Cache::, e tutti gli helper Laravel senza configurarli manualmente.
 */

uses(SaaS\Core\Tests\TestCase::class)
    ->in('Unit', 'Feature');
