<?php

namespace App\Domain\Shared\Validation;

/**
 * Validação de CPF/CNPJ por dígitos verificadores (RF-03).
 */
final class TaxDocument
{
    public static function digits(?string $value): string
    {
        return preg_replace('/\D/', '', (string) $value) ?? '';
    }

    public static function isValid(string $type, ?string $value): bool
    {
        $digits = self::digits($value);

        return match ($type) {
            'PF' => self::isValidCpf($digits),
            'PJ' => self::isValidCnpj($digits),
            default => false,
        };
    }

    public static function isValidCpf(string $cpf): bool
    {
        if (strlen($cpf) !== 11 || preg_match('/^(\d)\1{10}$/', $cpf)) {
            return false;
        }

        for ($t = 9; $t < 11; $t++) {
            $sum = 0;
            for ($i = 0; $i < $t; $i++) {
                $sum += (int) $cpf[$i] * (($t + 1) - $i);
            }
            $digit = ((10 * $sum) % 11) % 10;
            if ((int) $cpf[$t] !== $digit) {
                return false;
            }
        }

        return true;
    }

    public static function isValidCnpj(string $cnpj): bool
    {
        if (strlen($cnpj) !== 14 || preg_match('/^(\d)\1{13}$/', $cnpj)) {
            return false;
        }

        $weights = [[5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2], [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2]];

        foreach ($weights as $index => $weight) {
            $sum = 0;
            foreach ($weight as $i => $w) {
                $sum += (int) $cnpj[$i] * $w;
            }
            $rest = $sum % 11;
            $digit = $rest < 2 ? 0 : 11 - $rest;
            if ((int) $cnpj[12 + $index] !== $digit) {
                return false;
            }
        }

        return true;
    }
}
