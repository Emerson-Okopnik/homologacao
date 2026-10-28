<?php

use App\Domain\Rules\ConditionEvaluator;

beforeEach(function (): void {
    $this->evaluator = new ConditionEvaluator;
});

it('aplica sempre quando a condição é vazia', function (?array $condition): void {
    expect($this->evaluator->evaluate($condition, []))->toBe(['passed' => true, 'failures' => []]);
})->with([[null], [[]]]);

it('compara folhas com os operadores permitidos', function (string $op, mixed $value, bool $expected): void {
    $result = $this->evaluator->evaluate(['fact' => 'considered_power_kw', 'op' => $op, 'value' => $value], ['considered_power_kw' => 75]);

    expect($result['passed'])->toBe($expected);
})->with([
    ['eq', 75, true],
    ['neq', 75, false],
    ['gt', 75, false],
    ['gte', 75, true],
    ['lt', 76, true],
    ['lte', 74, false],
    ['in', [10, 75], true],
    ['not_in', [10, 75], false],
]);

it('reprova fatos ausentes e operadores desconhecidos', function (): void {
    expect($this->evaluator->evaluate(['fact' => 'has_storage', 'op' => 'eq', 'value' => false], [])['passed'])->toBeFalse()
        ->and($this->evaluator->evaluate(['fact' => 'x', 'op' => 'regex', 'value' => '.*'], ['x' => 'a'])['passed'])->toBeFalse();
});

it('acumula todas as falhas de um grupo all com a mensagem da regra', function (): void {
    $result = $this->evaluator->evaluate([
        'all' => [
            ['fact' => 'considered_power_kw', 'op' => 'lte', 'value' => 75, 'message' => 'Acima de 75 kW'],
            ['fact' => 'has_storage', 'op' => 'eq', 'value' => false],
            ['fact' => 'connection_voltage', 'op' => 'eq', 'value' => 'LOW'],
        ],
    ], ['considered_power_kw' => 100, 'has_storage' => true, 'connection_voltage' => 'LOW']);

    expect($result['passed'])->toBeFalse()
        ->and($result['failures'])->toHaveCount(2)
        ->and($result['failures'][0])->toMatchArray(['fact' => 'considered_power_kw', 'actual' => 100, 'message' => 'Acima de 75 kW']);
});

it('passa num grupo any quando qualquer filho passa', function (): void {
    $condition = ['any' => [
        ['fact' => 'classification', 'op' => 'eq', 'value' => 'MICRO'],
        ['fact' => 'has_credit_allocation', 'op' => 'eq', 'value' => true],
    ]];

    expect($this->evaluator->evaluate($condition, ['classification' => 'MINI_NON_DISPATCHABLE', 'has_credit_allocation' => true])['passed'])->toBeTrue()
        ->and($this->evaluator->evaluate($condition, ['classification' => 'MINI_DISPATCHABLE', 'has_credit_allocation' => false])['failures'])->toHaveCount(2);
});
