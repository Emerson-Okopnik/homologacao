<?php

use App\Domain\Rules\RuleValidator;

beforeEach(function (): void {
    $this->validator = new RuleValidator;
});

it('aceita condições bem formadas', function (mixed $condition): void {
    expect($this->validator->errors($condition))->toBe([]);
})->with([
    'vazia' => [[]],
    'nula' => [null],
    'folha numérica' => [['fact' => 'considered_power_kw', 'op' => 'lte', 'value' => 75, 'message' => 'Até 75 kW']],
    'enum em lista' => [['fact' => 'classification', 'op' => 'in', 'value' => ['MICRO', 'MINI_NON_DISPATCHABLE']]],
    'grupos aninhados' => [['all' => [
        ['fact' => 'has_storage', 'op' => 'eq', 'value' => false],
        ['any' => [['fact' => 'connection_voltage', 'op' => 'eq', 'value' => 'LOW']]],
    ]]],
]);

it('rejeita condições inválidas com mensagem específica', function (mixed $condition, string $fragment): void {
    $errors = $this->validator->errors($condition);

    expect($errors)->not->toBeEmpty()
        ->and(implode(' ', $errors))->toContain($fragment);
})->with([
    'não é objeto' => ['texto', 'deve ser um objeto'],
    'fato fora da whitelist' => [['fact' => 'php_code', 'op' => 'eq', 'value' => 1], 'fato desconhecido'],
    'operador inválido' => [['fact' => 'has_storage', 'op' => 'matches', 'value' => true], 'operador inválido'],
    'chave extra na folha' => [['fact' => 'has_storage', 'op' => 'eq', 'value' => true, 'eval' => 'x'], 'apenas fact, op, value'],
    'sem value' => [['fact' => 'has_storage', 'op' => 'eq'], 'apenas fact, op, value'],
    'in sem lista' => [['fact' => 'classification', 'op' => 'in', 'value' => 'MICRO'], 'exige lista'],
    'comparação em bool' => [['fact' => 'has_storage', 'op' => 'gt', 'value' => true], 'só vale para fatos numéricos'],
    'tipo errado' => [['fact' => 'considered_power_kw', 'op' => 'eq', 'value' => '75'], 'valor inválido'],
    'enum fora dos valores' => [['fact' => 'connection_voltage', 'op' => 'eq', 'value' => 'HIGH'], 'valor inválido'],
    'grupo vazio' => [['all' => []], 'lista não vazia'],
    'grupo com chave extra' => [['all' => [['fact' => 'has_storage', 'op' => 'eq', 'value' => true]], 'any' => []], 'única chave'],
]);

it('limita a profundidade de aninhamento', function (): void {
    $condition = ['fact' => 'has_storage', 'op' => 'eq', 'value' => true];
    for ($i = 0; $i < 6; $i++) {
        $condition = ['all' => [$condition]];
    }

    expect(implode(' ', $this->validator->errors($condition)))->toContain('aninhamento acima');
});
