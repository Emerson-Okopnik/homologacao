<?php

use Illuminate\Http\UploadedFile;

it('anexa e revisa o relatório na fase de vistoria e atualiza a vistoria agendada para realizada', function (string $type): void {
    extract(diagramProject($this));
    $base = '/api/processes/'.$process;
    $initial = $this->postJson($base.'/submissions', ['kind' => 'initial', 'idempotency_key' => 'report-initial-01', 'change_reason' => 'Dossiê inicial revisado'])->assertCreated()->json('data.id');
    $receipt = diagramDocument($this, $process, 'comprovante_envio');
    $confirmation = ['protocol_number' => 'REPORT-001', 'receipt_document_id' => $receipt, 'external_receipt' => 'RECEIPT-001'];
    $this->postJson($base.'/submissions/'.$initial.'/confirm', $confirmation)->assertOk();
    $this->postJson($base.'/transitions', ['status' => 'em_analise'])->assertOk();
    $this->postJson($base.'/transitions', ['status' => 'aprovado'])->assertOk();
    $submission = $this->postJson($base.'/submissions', ['kind' => 'inspection', 'idempotency_key' => 'report-inspection-01', 'change_reason' => 'Solicitação de vistoria'])->assertCreated()->json('data.id');
    $this->postJson($base.'/submissions/'.$submission.'/confirm', $confirmation)->assertOk();
    $this->getJson($base)->assertOk()->assertJsonPath('data.editable', false)->assertJsonPath('data.status', 'vistoria_solicitada');
    $dates = ['submission_id' => $submission, 'requested_at' => now()->subDay()->toIso8601String(), 'scheduled_at' => now()->subHour()->toIso8601String()];
    $inspection = $this->postJson($base.'/inspections', [...$dates, 'status' => 'agendada'])->assertOk()->json('data.inspections.0.id');
    $report = $this->post($base.'/documents', ['document_type' => $type, 'file' => UploadedFile::fake()->createWithContent('relatorio-vistoria.pdf', "%PDF-1.4\nRelatório da vistoria realizada")], ['Accept' => 'application/json'])
        ->assertCreated()->assertJsonPath('data.document_type', $type)->assertJsonPath('data.review_status', 'pendente')->json('data.id');
    $realized = [...$dates, 'status' => 'realizada', 'performed_at' => now()->subMinute()->toIso8601String(), 'report_document_id' => $report];
    $this->putJson($base.'/inspections/'.$inspection, $realized)->assertUnprocessable()->assertJsonPath('code', 'inspection_report_required');
    $this->postJson('/api/documents/'.$report.'/review', ['review_status' => 'aprovado'])->assertOk();
    $this->getJson('/api/projects/'.$project['id'].'/technical-data')->assertOk()->assertJsonFragment(['id' => $report, 'document_type' => $type, 'review_status' => 'aprovado']);
    $this->putJson($base.'/inspections/'.$inspection, $realized)->assertOk()
        ->assertJsonPath('data.inspections.0.status', 'realizada')->assertJsonPath('data.inspections.0.report_document_id', $report);
    $this->post($base.'/documents', ['document_type' => 'SINGLE_LINE_DIAGRAM', 'file' => UploadedFile::fake()->createWithContent('diagrama.pdf', "%PDF-1.4\nProjeto alterado")], ['Accept' => 'application/json'])
        ->assertUnprocessable()->assertJsonPath('code', 'process_locked');
    $this->postJson($base.'/transitions', ['status' => 'cancelado', 'reason' => 'Encerramento do cenário de teste'])->assertOk();
    $this->post($base.'/documents', ['document_type' => $type, 'file' => UploadedFile::fake()->createWithContent('novo-relatorio.pdf', "%PDF-1.4\nOutro relatório")], ['Accept' => 'application/json'])
        ->assertUnprocessable()->assertJsonPath('code', 'process_closed');
})->with(['INSPECTION_REPORT', 'relatorio_vistoria']);
