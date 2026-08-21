<?php

namespace App\Exports;

use App\Services\AuditService;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Spatie\Activitylog\Models\Activity;

/**
 * Audit log Excel export (RF-USR-007).
 *
 * Reuses AuditService::builder so the export honours the same filters
 * the viewer shows. Deterministic order (newest first, then id) keeps
 * the export stable across pagination. The viewer-facing Spanish
 * headings match the audit view in lang/es/admin.php (Tanda B will wire
 * the actual strings; this exporter ships them inline to keep the
 * service self-contained for tests).
 */
class AuditLogExport implements FromQuery, WithHeadings, WithMapping
{
    /**
     * @param  array<string, mixed>  $filters
     */
    public function __construct(
        private readonly array $filters = [],
        private readonly AuditService $service = new AuditService(),
    ) {}

    /**
     * @return EloquentBuilder<Activity>
     */
    public function query(): EloquentBuilder
    {
        /** @var EloquentBuilder<Activity> $builder */
        $builder = $this->service->builder($this->filters);

        return $builder;
    }

    /**
     * @return list<string>
     */
    public function headings(): array
    {
        return [
            'Fecha',
            'Evento',
            'Sujeto',
            'ID del sujeto',
            'Usuario',
            'Descripción',
            'Propiedades',
        ];
    }

    /**
     * @param  Activity  $row
     * @return list<mixed>
     */
    public function map($row): array
    {
        $causer = $row->causer;

        return [
            $row->created_at?->format('d/m/Y H:i:s'),
            $row->event,
            $row->subject_type,
            $row->subject_id,
            $causer?->name ?? ($row->causer_type ? "{$row->causer_type} #{$row->causer_id}" : null),
            $row->description,
            $this->stringifyProperties($row),
        ];
    }

    private function stringifyProperties(Activity $row): string
    {
        if ($row->properties === null) {
            return '';
        }

        $properties = $row->properties->toArray();

        return (string) json_encode(
            $properties,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );
    }
}