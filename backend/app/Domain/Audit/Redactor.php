<?php

namespace App\Domain\Audit;

final class Redactor
{
    public const MASK = '[REDACTED]';

    /** @var list<string> */
    private array $keys;

    /**
     * @param  list<string>|null  $keys
     */
    public function __construct(?array $keys = null)
    {
        $this->keys = array_map('strtolower', $keys ?? config('homologa.audit.redacted_keys', []));
    }

    /**
     * @param  array<array-key, mixed>|null  $data
     * @return array<array-key, mixed>|null
     */
    public function redact(?array $data): ?array
    {
        if ($data === null) {
            return null;
        }

        foreach ($data as $key => $value) {
            if (is_string($key) && $this->isSensitive($key)) {
                $data[$key] = self::MASK;
            } elseif (is_array($value)) {
                $data[$key] = $this->redact($value);
            }
        }

        return $data;
    }

    private function isSensitive(string $key): bool
    {
        $normalized = strtolower($key);

        foreach ($this->keys as $sensitive) {
            if ($normalized === $sensitive || str_contains($normalized, $sensitive)) {
                return true;
            }
        }

        return false;
    }
}
