<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

it('retorna prontidao somente com banco e cache disponiveis sem abrir sessao', function (): void {
    config(['session.driver' => 'redis']);
    DB::shouldReceive('select')->once()->with('SELECT 1')->andReturn([(object) ['value' => 1]]);
    Cache::shouldReceive('get')->once()->with('health:readiness')->andReturnNull();

    $this->getJson('/ready')
        ->assertOk()
        ->assertExactJson(['status' => 'ready'])
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertHeaderMissing('Set-Cookie');
});

it('retorna indisponibilidade generica quando o banco falha', function (): void {
    config(['app.debug' => true]);
    DB::shouldReceive('select')->once()->with('SELECT 1')
        ->andThrow(new RuntimeException('postgres://usuario:segredo@db-interno'));
    Cache::shouldReceive('get')->never();

    $this->getJson('/ready')
        ->assertStatus(503)
        ->assertExactJson(['status' => 'unavailable'])
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertHeaderMissing('Set-Cookie');
});

it('retorna indisponibilidade generica quando o cache falha', function (): void {
    config(['app.debug' => true]);
    DB::shouldReceive('select')->once()->with('SELECT 1')->andReturn([(object) ['value' => 1]]);
    Cache::shouldReceive('get')->once()->with('health:readiness')
        ->andThrow(new RuntimeException('redis://segredo@redis-interno'));

    $this->getJson('/ready')
        ->assertStatus(503)
        ->assertExactJson(['status' => 'unavailable'])
        ->assertHeader('Cache-Control', 'no-store, private');
});

it('mantem liveness independente de banco e cache', function (): void {
    DB::shouldReceive('select')->never();
    Cache::shouldReceive('get')->never();

    $this->get('/up')->assertOk();
});
