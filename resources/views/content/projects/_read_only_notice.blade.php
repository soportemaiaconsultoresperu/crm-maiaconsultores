{{-- Archived project = read-only (S2). Include on every project page. --}}
@if ($project->isArchived())
    <div class="alert alert-warning d-flex align-items-center gap-2" role="status" data-testid="content-project-read-only-notice">
        <i class="bi bi-lock" aria-hidden="true"></i>
        <span><strong>Archivado — solo lectura.</strong> Puede consultar y exportar la información, pero no se aceptan cambios hasta que el proyecto se reactive.</span>
    </div>
@endif
