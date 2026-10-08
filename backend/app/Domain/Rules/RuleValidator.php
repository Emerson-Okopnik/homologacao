<?php

namespace App\Domain\Rules;

use App\Domain\Rules\Models\RequirementRule;
use App\Domain\Rules\Models\VersionedRule;
use App\Domain\Shared\Exceptions\DomainException;

/**
 * Valida a estrutura de uma condição antes de salvar a regra.
 * Formato: {"all":[...]} | {"any":[...]} | {"fact":"x","op":"gte","value":1,"message":"..."}
 * Condição vazia ({} ou []) significa "sempre aplica".
 */
final class RuleValidator
{
    private const MAX_DEPTH = 4;

    /**
     * @return list<string>
     */
    public function errors(mixed $condition, int $depth = 0, string $path = '$'): array
    {
        if ($condition === null || $condition === []) {
            return [];
        }

        if (! is_array($condition)) {
            return ["{$path}: condição deve ser um objeto."];
        }

        if ($depth > self::MAX_DEPTH) {
            return ["{$path}: aninhamento acima de ".self::MAX_DEPTH.' níveis.'];
        }

        foreach (['all', 'any'] as $group) {
            if (array_key_exists($group, $condition)) {
                if (count($condition) !== 1 || ! is_array($condition[$group]) || ! array_is_list($condition[$group]) || $condition[$group] === []) {
                    return ["{$path}.{$group}: deve ser uma lista não vazia e única chave do nó."];
                }

                $errors = [];
                foreach ($condition[$group] as $i => $child) {
                    array_push($errors, ...$this->errors($child, $depth + 1, "{$path}.{$group}[{$i}]"));
                }

                return $errors;
            }
        }

        $allowedKeys = ['fact', 'op', 'value', 'message'];
        if (array_diff(array_keys($condition), $allowedKeys) !== [] || ! isset($condition['fact'], $condition['op']) || ! array_key_exists('value', $condition)) {
            return ["{$path}: folha deve conter apenas fact, op, value e message (opcional)."];
        }

        $fact = $condition['fact'];
        $op = $condition['op'];
        $value = $condition['value'];

        if (! is_string($fact) || ! FactCatalog::has($fact)) {
            return ["{$path}: fato desconhecido \"".(is_string($fact) ? $fact : '?').'".'];
        }

        if (! in_array($op, FactCatalog::OPERATORS, true)) {
            return ["{$path}: operador inválido \"".(is_string($op) ? $op : '?').'".'];
        }

        $definition = FactCatalog::all()[$fact];
        $values = in_array($op, ['in', 'not_in'], true) ? $value : [$value];

        if (! is_array($values) || ! array_is_list($values) || $values === []) {
            return ["{$path}: operador {$op} exige lista de valores."];
        }

        if (in_array($op, ['gt', 'gte', 'lt', 'lte'], true) && $definition['type'] !== 'number') {
            return ["{$path}: operador {$op} só vale para fatos numéricos."];
        }

        foreach ($values as $v) {
            $valid = match ($definition['type']) {
                'number' => is_int($v) || is_float($v),
                'bool' => is_bool($v),
                'enum' => is_string($v) && in_array($v, $definition['values'], true),
                default => is_string($v),
            };

            if (! $valid) {
                return ["{$path}: valor inválido para o fato {$fact} ({$definition['type']})."];
            }
        }

        if (isset($condition['message']) && ! is_string($condition['message'])) {
            return ["{$path}: message deve ser texto."];
        }

        return [];
    }

    public function assertValidRule(VersionedRule $rule): void
    {
        $errors = $this->errors($rule->conditions);

        if (trim((string) $rule->source_reference) === '') {
            $errors[] = 'source_reference é obrigatório.';
        }

        if ($rule->effective_to !== null && $rule->effective_to->lt($rule->effective_from)) {
            $errors[] = 'effective_to deve ser posterior a effective_from.';
        }

        if ($rule instanceof RequirementRule) {
            if ($rule->kind === 'DOCUMENT' && ! $rule->document_type) {
                $errors[] = 'Requisito DOCUMENT exige document_type.';
            }
            if ($rule->kind === 'FACT') {
                if (empty($rule->satisfied_when)) {
                    $errors[] = 'Requisito FACT exige satisfied_when.';
                }
                array_push($errors, ...$this->errors($rule->satisfied_when, 0, '$satisfied_when'));
            }
        }

        if ($errors === [] && $rule->active) {
            $this->assertNoOverlap($rule, $errors);
        }

        if ($errors !== []) {
            throw new DomainException('Regra inválida: '.implode(' ', $errors), 'invalid_rule');
        }
    }

    /**
     * Duas versões ativas do mesmo rule_code/distribuidora/prioridade não podem ter vigências sobrepostas.
     *
     * @param  list<string>  $errors
     */
    private function assertNoOverlap(VersionedRule $rule, array &$errors): void
    {
        $to = $rule->effective_to?->toDateString() ?? '9999-12-31';

        $overlap = $rule->newQuery()
            ->where('rule_code', $rule->rule_code)
            ->where('active', true)
            ->where('priority', $rule->priority)
            ->when($rule->exists, fn ($q) => $q->whereKeyNot($rule->getKey()))
            ->where(fn ($q) => $rule->distributor_code === null
                ? $q->whereNull('distributor_code')
                : $q->where('distributor_code', $rule->distributor_code))
            ->whereDate('effective_from', '<=', $to)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $rule->effective_from))
            ->exists();

        if ($overlap) {
            $errors[] = "Já existe versão ativa de {$rule->rule_code} com vigência sobreposta. Encerre a versão anterior antes de publicar a nova.";
        }
    }
}
