<?php

use App\Http\Controllers\Api\Admin\TenantController;
use App\Http\Controllers\Api\Admin\TenantRoleController;
use App\Http\Controllers\Api\Admin\TenantUserController;
use App\Http\Controllers\Api\Audit\AuditLogController;
use App\Http\Controllers\Api\Auth\AuthController;
use App\Http\Controllers\Api\Auth\PasswordResetController;
use App\Http\Controllers\Api\Homologation\DashboardController;
use App\Http\Controllers\Api\Homologation\DocumentController;
use App\Http\Controllers\Api\Homologation\ExternalTrackingController;
use App\Http\Controllers\Api\Homologation\PendencyController;
use App\Http\Controllers\Api\Homologation\ProcessController;
use App\Http\Controllers\Api\Homologation\ProjectController;
use App\Http\Controllers\Api\Homologation\ProjectTechnicalController;
use App\Http\Controllers\Api\Homologation\WorkflowConfigurationController;
use App\Http\Controllers\Api\Registry\ClientController;
use App\Http\Controllers\Api\Registry\ConsumerUnitController;
use App\Http\Controllers\Api\Registry\DistributorController;
use App\Http\Controllers\Api\Registry\EquipmentController;
use App\Http\Controllers\Api\Registry\TechnicalResponsibleController;
use App\Http\Controllers\Api\Users\RoleController;
use App\Http\Controllers\Api\Users\UserController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function (): void {
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:10,1');
    Route::post('forgot-password', [PasswordResetController::class, 'sendLink'])->middleware('throttle:5,1');
    Route::post('reset-password', [PasswordResetController::class, 'reset'])->middleware('throttle:5,1');

    Route::middleware(['auth:sanctum', 'tenant'])->group(function (): void {
        Route::post('logout', [AuthController::class, 'logout']);
        Route::get('me', [AuthController::class, 'me']);
    });
});

// Super admin: tenants e RBAC de toda a plataforma.
Route::middleware(['auth:sanctum', 'tenant', 'super_admin', 'throttle:120,1'])
    ->prefix('admin')
    ->group(function (): void {
        Route::get('permissions', [TenantRoleController::class, 'permissions']);

        Route::get('tenants', [TenantController::class, 'index']);
        Route::post('tenants', [TenantController::class, 'store']);
        Route::get('tenants/{tenant:uuid}', [TenantController::class, 'show']);
        Route::patch('tenants/{tenant:uuid}', [TenantController::class, 'update']);

        Route::get('tenants/{tenant:uuid}/roles', [TenantRoleController::class, 'index']);
        Route::post('tenants/{tenant:uuid}/roles', [TenantRoleController::class, 'store']);
        Route::patch('tenants/{tenant:uuid}/roles/{role}', [TenantRoleController::class, 'update']);
        Route::delete('tenants/{tenant:uuid}/roles/{role}', [TenantRoleController::class, 'destroy']);

        Route::get('tenants/{tenant:uuid}/users', [TenantUserController::class, 'index']);
        Route::post('tenants/{tenant:uuid}/users', [TenantUserController::class, 'store']);
        Route::patch('tenants/{tenant:uuid}/users/{user}', [TenantUserController::class, 'update']);
    });

Route::middleware(['auth:sanctum', 'tenant'])->group(function (): void {
    Route::apiResource('users', UserController::class)->except(['destroy']);
    Route::get('roles', [RoleController::class, 'index']);
    Route::get('audit-logs', [AuditLogController::class, 'index']);

    Route::get('dashboard', DashboardController::class);

    // Cadastros
    Route::apiResource('clients', ClientController::class)->except(['destroy']);
    Route::post('clients/{client}/contacts', [ClientController::class, 'storeContact']);
    Route::put('contacts/{contact}', [ClientController::class, 'updateContact']);
    Route::delete('contacts/{contact}', [ClientController::class, 'destroyContact']);

    Route::get('consumer-units', [ConsumerUnitController::class, 'index']);
    Route::post('consumer-units', [ConsumerUnitController::class, 'store']);
    Route::put('consumer-units/{consumerUnit}', [ConsumerUnitController::class, 'update']);

    Route::get('distributors', [DistributorController::class, 'index']);
    Route::post('distributors', [DistributorController::class, 'store']);
    Route::put('distributors/{distributor}', [DistributorController::class, 'update']);

    Route::get('technical-responsibles', [TechnicalResponsibleController::class, 'index']);
    Route::post('technical-responsibles', [TechnicalResponsibleController::class, 'store']);
    Route::put('technical-responsibles/{technicalResponsible}', [TechnicalResponsibleController::class, 'update']);

    Route::get('equipment', [EquipmentController::class, 'index']);
    Route::post('equipment', [EquipmentController::class, 'store']);
    Route::put('equipment/{equipment}', [EquipmentController::class, 'update']);

    // Projetos e homologação
    Route::get('projects', [ProjectController::class, 'index']);
    Route::post('projects', [ProjectController::class, 'store'])->middleware('throttle:30,1');
    Route::get('projects/{project}', [ProjectController::class, 'show']);
    Route::put('projects/{project}', [ProjectController::class, 'update']);
    Route::get('projects/{project}/technical-data', [ProjectTechnicalController::class, 'show']);
    Route::put('projects/{project}/technical-data', [ProjectTechnicalController::class, 'update']);
    Route::post('projects/{project}/responsibility-terms', [ProjectTechnicalController::class, 'term']);
    Route::post('projects/{project}/versions', [ProjectTechnicalController::class, 'freeze']);
    Route::get('projects/{project}/versions/{version}', [ProjectTechnicalController::class, 'version']);
    Route::post('projects/{project}/processes', [ProjectTechnicalController::class, 'open']);
    Route::post('projects/{project}/documents', [DocumentController::class, 'storeProject']);

    Route::get('workflow-configuration', [WorkflowConfigurationController::class, 'index']);
    Route::post('workflow-stages', [WorkflowConfigurationController::class, 'saveStage']);
    Route::put('workflow-stages/{stage}', [WorkflowConfigurationController::class, 'saveStage']);
    Route::post('requirements', [WorkflowConfigurationController::class, 'saveRequirement']);
    Route::put('requirements/{requirement}', [WorkflowConfigurationController::class, 'saveRequirement']);
    Route::post('external-credentials', [WorkflowConfigurationController::class, 'credential']);
    Route::post('checklist-items/{item}/review', [WorkflowConfigurationController::class, 'reviewChecklist']);

    Route::get('process-statuses', [ProcessController::class, 'statuses']);
    Route::get('assignment-users', [ProcessController::class, 'assignmentUsers']);
    Route::get('processes', [ProcessController::class, 'index']);
    Route::get('processes/{process}', [ProcessController::class, 'show']);
    Route::patch('processes/{process}', [ProcessController::class, 'update']);
    Route::post('processes/{process}/transitions', [ProcessController::class, 'transition'])->middleware('throttle:60,1');
    Route::post('processes/{process}/interactions', [ProcessController::class, 'storeInteraction']);
    Route::get('processes/{process}/tracking', [ExternalTrackingController::class, 'show']);
    Route::post('processes/{process}/submissions', [ExternalTrackingController::class, 'prepare']);
    Route::post('processes/{process}/submissions/{submission}/confirm', [ExternalTrackingController::class, 'confirm']);
    Route::post('processes/{process}/submissions/{submission}/fail', [ExternalTrackingController::class, 'fail']);
    Route::get('processes/{process}/submissions/{submission}/manifest', [ExternalTrackingController::class, 'manifest']);
    Route::get('processes/{process}/submissions/{submission}/dossier', [ExternalTrackingController::class, 'dossier']);
    Route::post('processes/{process}/external-status', [ExternalTrackingController::class, 'status']);
    Route::post('processes/{process}/external-pendencies', [ExternalTrackingController::class, 'pending']);
    Route::put('processes/{process}/external-pendencies/{pending}/response', [ExternalTrackingController::class, 'response']);
    Route::post('processes/{process}/connection-budgets', [ExternalTrackingController::class, 'budget']);
    Route::post('processes/{process}/inspections', [ExternalTrackingController::class, 'inspection']);
    Route::put('processes/{process}/inspections/{inspection}', [ExternalTrackingController::class, 'inspection']);
    Route::post('processes/{process}/pendencies', [PendencyController::class, 'store']);
    Route::post('pendencies/{pendency}/resolve', [PendencyController::class, 'resolve']);

    Route::get('document-types', [DocumentController::class, 'types']);
    Route::get('documents', [DocumentController::class, 'index']);
    Route::post('processes/{process}/documents', [DocumentController::class, 'store'])->middleware('throttle:60,1');
    Route::get('processes/{process}/documents/{type}/versions', [DocumentController::class, 'versions']);
    Route::post('documents/{document}/review', [DocumentController::class, 'review']);
    Route::get('documents/{document}/download', [DocumentController::class, 'download']);
});
