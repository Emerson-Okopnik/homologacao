<?php

namespace App\Domain\Audit\Concerns;

use App\Domain\Audit\AuditLogger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

/**
 * Registra created/updated/deleted automaticamente. Campos sensíveis passam pelo Redactor;
 * campos em $auditExclude nem chegam a ser registrados.
 *
 * @property list<string> $auditExclude
 */
trait Auditable
{
    public static function bootAuditable(): void
    {
        static::created(function (Model $model): void {
            $model->writeAudit('created', null, $model->auditableAttributes($model->getAttributes()));
        });

        static::updated(function (Model $model): void {
            $changes = $model->auditableAttributes($model->getChanges());
            unset($changes['updated_at']);

            if ($changes === []) {
                return;
            }

            $old = Arr::only($model->getOriginal(), array_keys($changes));
            $model->writeAudit('updated', $model->auditableAttributes($old), $changes);
        });

        static::deleted(function (Model $model): void {
            $model->writeAudit('deleted', $model->auditableAttributes($model->getOriginal()), null);
        });
    }

    /**
     * @param  array<string, mixed>|null  $old
     * @param  array<string, mixed>|null  $new
     */
    protected function writeAudit(string $action, ?array $old, ?array $new): void
    {
        $event = str($this->getTable())->singular()->append('.', $action)->toString();

        app(AuditLogger::class)->log($event, $this, $old, $new);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    protected function auditableAttributes(array $attributes): array
    {
        $exclude = property_exists($this, 'auditExclude') ? $this->auditExclude : [];

        return Arr::except($attributes, [...$exclude, ...$this->getHidden()]);
    }
}
