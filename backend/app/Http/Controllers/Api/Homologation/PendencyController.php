<?php

namespace App\Http\Controllers\Api\Homologation;

use App\Domain\Homologations\Models\HomologationProcess;
use App\Domain\Homologations\Models\ProcessPendency;
use App\Domain\Homologations\ProcessWorkflow;
use App\Domain\Homologations\TimelineRecorder;
use App\Domain\Shared\Exceptions\DomainException;
use App\Http\Controllers\Controller;
use App\Http\Resources\Homologation\PendencyResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class PendencyController extends Controller
{
    public function __construct(
        private readonly ProcessWorkflow $workflow,
        private readonly TimelineRecorder $timeline,
    ) {}

    public function store(Request $request, HomologationProcess $process): JsonResponse
    {
        $this->authorize('homologations.manage');

        if (! $process->isActive()) {
            throw new DomainException('Processo encerrado não aceita novas pendências.', 'process_closed');
        }

        $data = $request->validate([
            'origin' => ['required', Rule::in(['interna', 'distribuidora'])],
            'title' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:5000'],
            'due_date' => ['nullable', 'date', 'after_or_equal:today'],
        ]);

        $pendency = ProcessPendency::create([
            ...$data,
            'homologation_process_id' => $process->id,
            'status' => 'aberta',
            'created_by' => $request->user()->id,
        ]);

        $this->timeline->record($process, 'PENDENCY_CREATED', "Pendência aberta: {$pendency->title}", $pendency->description);

        return PendencyResource::make($pendency->load('author'))->response()->setStatusCode(201);
    }

    public function resolve(Request $request, ProcessPendency $pendency): PendencyResource
    {
        $this->authorize('homologations.manage');

        $data = $request->validate(['resolution' => ['required', 'string', 'max:5000']]);
        $this->workflow->resolvePendency($pendency, $request->user(), $data['resolution']);

        return PendencyResource::make($pendency->refresh()->load(['author', 'resolver']));
    }
}
