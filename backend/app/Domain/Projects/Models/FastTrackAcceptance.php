<?php

namespace App\Domain\Projects\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Documents\Models\Document;
use App\Domain\Projects\Enums\FastTrackParty;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use App\Domain\Users\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property FastTrackParty $party
 * @property string $signer_name
 * @property string $signer_document
 * @property string $statement_version
 * @property string $statement_text
 * @property int|null $evidence_document_id
 * @property Carbon $accepted_at
 * @property Carbon|null $revoked_at
 */
class FastTrackAcceptance extends Model
{
    use Auditable;
    use BelongsToTenant;

    public const STATEMENT_VERSION = 'FT-2026.1';

    protected $fillable = [
        'solar_project_id', 'party', 'signer_name', 'signer_document', 'statement_version',
        'statement_text', 'evidence_document_id', 'recorded_by', 'accepted_at',
    ];

    protected function casts(): array
    {
        return ['party' => FastTrackParty::class, 'accepted_at' => 'datetime', 'revoked_at' => 'datetime'];
    }

    public static function statementFor(FastTrackParty $party): string
    {
        return match ($party) {
            FastTrackParty::Requester => 'Declaro, como titular da unidade consumidora, que solicito a tramitação simplificada (Fast Track) e que as informações do projeto são verdadeiras, ciente de que a distribuidora poderá exigir adequações na vistoria.',
            FastTrackParty::TechnicalResponsible => 'Declaro, como responsável técnico, que o projeto atende aos requisitos da tramitação simplificada (Fast Track) e às normas técnicas aplicáveis, assumindo a responsabilidade técnica pelas informações prestadas.',
        };
    }

    /** @return BelongsTo<Document, $this> */
    public function evidence(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'evidence_document_id');
    }

    /** @return BelongsTo<User, $this> */
    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
