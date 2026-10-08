<?php

namespace App\Domain\Rules;

/**
 * Avalia condições declarativas contra um conjunto de fatos. Sem eval, sem código dinâmico.
 */
final class ConditionEvaluator
{
    /**
     * @param  array<string, mixed>|null  $condition
     * @param  array<string, mixed>  $facts
     * @return array{passed: bool, failures: list<array{fact: string, op: string, expected: mixed, actual: mixed, message: string|null}>}
     */
    public function evaluate(?array $condition, array $facts): array
    {
        if ($condition === null || $condition === []) {
            return ['passed' => true, 'failures' => []];
        }

        if (isset($condition['all'])) {
            $failures = [];
            foreach ($condition['all'] as $child) {
                $result = $this->evaluate($child, $facts);
                if (! $result['passed']) {
                    array_push($failures, ...$result['failures']);
                }
            }

            return ['passed' => $failures === [], 'failures' => $failures];
        }

        if (isset($condition['any'])) {
            $failures = [];
            foreach ($condition['any'] as $child) {
                $result = $this->evaluate($child, $facts);
                if ($result['passed']) {
                    return ['passed' => true, 'failures' => []];
                }
                array_push($failures, ...$result['failures']);
            }

            return ['passed' => false, 'failures' => $failures];
        }

        $fact = (string) $condition['fact'];
        $op = (string) $condition['op'];
        $expected = $condition['value'];
        $actual = $facts[$fact] ?? null;

        $passed = $actual !== null && match ($op) {
            'eq' => $actual == $expected,
            'neq' => $actual != $expected,
            'gt' => $actual > $expected,
            'gte' => $actual >= $expected,
            'lt' => $actual < $expected,
            'lte' => $actual <= $expected,
            'in' => in_array($actual, (array) $expected, false),
            'not_in' => ! in_array($actual, (array) $expected, false),
            default => false,
        };

        return [
            'passed' => $passed,
            'failures' => $passed ? [] : [[
                'fact' => $fact,
                'op' => $op,
                'expected' => $expected,
                'actual' => $actual,
                'message' => $condition['message'] ?? null,
            ]],
        ];
    }
}
