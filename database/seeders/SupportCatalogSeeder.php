<?php

namespace Database\Seeders;

use App\Models\SupportCategory;
use App\Models\SupportChannel;
use App\Models\SupportPriority;
use App\Models\SupportStatus;
use App\Models\SupportTicketType;
use Illuminate\Database\Seeder;

class SupportCatalogSeeder extends Seeder
{
    public function run(): void
    {
        foreach ([
            ['name' => 'Reunión', 'slug' => 'reunion', 'sort' => 1],
            ['name' => 'Capacitación', 'slug' => 'capacitacion', 'sort' => 2],
            ['name' => 'Ayuda funcional', 'slug' => 'ayuda-funcional', 'sort' => 3],
            ['name' => 'Asesoría', 'slug' => 'asesoria', 'sort' => 4],
            ['name' => 'Configuración', 'slug' => 'configuracion', 'sort' => 5],
            ['name' => 'Incidente o error', 'slug' => 'incidente-o-error', 'sort' => 6],
            ['name' => 'Queja', 'slug' => 'queja', 'sort' => 7],
            ['name' => 'Levantamiento de observaciones', 'slug' => 'levantamiento-observaciones', 'sort' => 8],
            ['name' => 'Otro', 'slug' => 'otro', 'sort' => 9],
        ] as $row) {
            SupportTicketType::query()->updateOrCreate(['slug' => $row['slug']], $row + ['is_active' => true]);
        }

        foreach ([
            ['name' => 'General', 'slug' => 'general', 'sort' => 1],
            ['name' => 'Funcional', 'slug' => 'funcional', 'sort' => 2],
            ['name' => 'Técnico', 'slug' => 'tecnico', 'sort' => 3],
            ['name' => 'Capacitación', 'slug' => 'capacitacion', 'sort' => 4],
            ['name' => 'Configuración', 'slug' => 'configuracion', 'sort' => 5],
        ] as $row) {
            SupportCategory::query()->updateOrCreate(['slug' => $row['slug']], $row + ['is_active' => true]);
        }

        foreach ([
            ['name' => 'Registro interno', 'slug' => 'registro-interno', 'sort' => 1],
            ['name' => 'Llamada', 'slug' => 'llamada', 'sort' => 2],
            ['name' => 'WhatsApp', 'slug' => 'whatsapp', 'sort' => 3],
            ['name' => 'Correo', 'slug' => 'correo', 'sort' => 4],
            ['name' => 'Reunión', 'slug' => 'reunion', 'sort' => 5],
            ['name' => 'Presencial', 'slug' => 'presencial', 'sort' => 6],
            ['name' => 'Otro', 'slug' => 'otro', 'sort' => 7],
        ] as $row) {
            SupportChannel::query()->updateOrCreate(['slug' => $row['slug']], $row + ['is_active' => true]);
        }

        foreach ([
            ['name' => 'Baja', 'slug' => 'baja', 'color' => 'secondary', 'sort' => 1],
            ['name' => 'Media', 'slug' => 'media', 'color' => 'info', 'sort' => 2],
            ['name' => 'Alta', 'slug' => 'alta', 'color' => 'warning', 'sort' => 3],
            ['name' => 'Crítica', 'slug' => 'critica', 'color' => 'danger', 'sort' => 4],
        ] as $row) {
            SupportPriority::query()->updateOrCreate(['slug' => $row['slug']], $row + ['is_active' => true]);
        }

        foreach ([
            ['name' => 'Nuevo', 'slug' => SupportStatus::SLUG_NEW, 'sort' => 1, 'is_terminal' => false],
            ['name' => 'Asignado', 'slug' => SupportStatus::SLUG_ASSIGNED, 'sort' => 2, 'is_terminal' => false],
            ['name' => 'Programado', 'slug' => SupportStatus::SLUG_SCHEDULED, 'sort' => 3, 'is_terminal' => false],
            ['name' => 'En atención', 'slug' => SupportStatus::SLUG_IN_PROGRESS, 'sort' => 4, 'is_terminal' => false],
            ['name' => 'En espera del cliente', 'slug' => SupportStatus::SLUG_WAITING_CUSTOMER, 'sort' => 5, 'is_terminal' => false],
            ['name' => 'En espera interna', 'slug' => SupportStatus::SLUG_WAITING_INTERNAL, 'sort' => 6, 'is_terminal' => false],
            ['name' => 'Resuelto', 'slug' => SupportStatus::SLUG_RESOLVED, 'sort' => 7, 'is_terminal' => false],
            ['name' => 'Cerrado', 'slug' => SupportStatus::SLUG_CLOSED, 'sort' => 8, 'is_terminal' => true],
            ['name' => 'Cancelado', 'slug' => SupportStatus::SLUG_CANCELLED, 'sort' => 9, 'is_terminal' => true],
            ['name' => 'Reabierto', 'slug' => SupportStatus::SLUG_REOPENED, 'sort' => 10, 'is_terminal' => false],
        ] as $row) {
            SupportStatus::query()->updateOrCreate(['slug' => $row['slug']], $row + ['is_active' => true]);
        }
    }
}
