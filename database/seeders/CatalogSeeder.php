<?php

namespace Database\Seeders;

use App\Models\ActivityType;
use App\Models\Currency;
use App\Models\LeadSource;
use App\Models\LeadStatus;
use App\Models\LossReason;
use App\Models\PipelineStage;
use App\Models\ProductCategory;
use App\Models\Tax;
use Illuminate\Database\Seeder;

/**
 * Seed the system catalogs (Spanish display data). Catalogs are never
 * deleted; rows are activated/deactivated. Idempotent via
 * updateOrCreate keyed on slug/code.
 */
class CatalogSeeder extends Seeder
{
    public function run(): void
    {
        foreach ([
            ['code' => 'PEN', 'name' => 'Sol Peruano', 'symbol' => 'S/', 'decimals' => 2],
            ['code' => 'USD', 'name' => 'Dólar Americano', 'symbol' => '$', 'decimals' => 2],
            ['code' => 'EUR', 'name' => 'Euro', 'symbol' => '€', 'decimals' => 2],
        ] as $currency) {
            Currency::query()->updateOrCreate(
                ['code' => $currency['code']],
                [
                    'name' => $currency['name'],
                    'symbol' => $currency['symbol'],
                    'decimals' => $currency['decimals'],
                    'is_active' => true,
                ]
            );
        }

        foreach ([
            ['name' => 'Gravado IGV', 'slug' => 'gravado-igv', 'rate' => 18.00, 'sort' => 1],
            ['name' => 'Exonerado', 'slug' => 'exonerado', 'rate' => 0, 'sort' => 2],
            ['name' => 'Inafecto', 'slug' => 'inafecto', 'rate' => 0, 'sort' => 3],
            ['name' => 'Gratuito', 'slug' => 'gratuito', 'rate' => 0, 'sort' => 4],
        ] as $tax) {
            Tax::query()->updateOrCreate(
                ['slug' => $tax['slug']],
                ['name' => $tax['name'], 'rate' => $tax['rate'], 'sort' => $tax['sort'], 'is_active' => true]
            );
        }

        foreach ([
            ['name' => 'Nuevo', 'slug' => 'nuevo', 'sort' => 1, 'is_final' => false],
            ['name' => 'Contactado', 'slug' => 'contactado', 'sort' => 2, 'is_final' => false],
            ['name' => 'Calificado', 'slug' => 'calificado', 'sort' => 3, 'is_final' => false],
            ['name' => 'No calificado', 'slug' => 'no-calificado', 'sort' => 4, 'is_final' => false],
            ['name' => 'Convertido', 'slug' => 'convertido', 'sort' => 5, 'is_final' => true],
            ['name' => 'Perdido', 'slug' => 'perdido', 'sort' => 6, 'is_final' => true],
        ] as $status) {
            LeadStatus::query()->updateOrCreate(
                ['slug' => $status['slug']],
                [
                    'name' => $status['name'],
                    'sort' => $status['sort'],
                    'is_final' => $status['is_final'],
                    'is_active' => true,
                ]
            );
        }

        foreach ([
            ['name' => 'Web', 'slug' => 'web', 'sort' => 1],
            ['name' => 'Referido', 'slug' => 'referido', 'sort' => 2],
            ['name' => 'Campaña', 'slug' => 'campana', 'sort' => 3],
            ['name' => 'Llamada', 'slug' => 'llamada', 'sort' => 4],
            ['name' => 'Feria', 'slug' => 'feria', 'sort' => 5],
            ['name' => 'Redes sociales', 'slug' => 'redes-sociales', 'sort' => 6],
            ['name' => 'Otro', 'slug' => 'otro', 'sort' => 7],
        ] as $source) {
            LeadSource::query()->updateOrCreate(
                ['slug' => $source['slug']],
                ['name' => $source['name'], 'sort' => $source['sort'], 'is_active' => true]
            );
        }

        foreach ([
            ['name' => 'Llamada', 'slug' => 'llamada', 'sort' => 1],
            ['name' => 'WhatsApp', 'slug' => 'whatsapp', 'sort' => 2],
            ['name' => 'Correo', 'slug' => 'correo', 'sort' => 3],
            ['name' => 'Reunión', 'slug' => 'reunion', 'sort' => 4],
            ['name' => 'Visita', 'slug' => 'visita', 'sort' => 5],
            ['name' => 'Tarea', 'slug' => 'tarea', 'sort' => 6],
            ['name' => 'Nota', 'slug' => 'nota', 'sort' => 7],
        ] as $type) {
            ActivityType::query()->updateOrCreate(
                ['slug' => $type['slug']],
                ['name' => $type['name'], 'sort' => $type['sort'], 'is_active' => true]
            );
        }

        foreach ([
            ['name' => 'Nueva oportunidad', 'slug' => 'nueva-oportunidad', 'stage_type' => 'open', 'default_probability' => 10, 'sort' => 1],
            ['name' => 'Contacto realizado', 'slug' => 'contacto-realizado', 'stage_type' => 'open', 'default_probability' => 25, 'sort' => 2],
            ['name' => 'Reunión programada', 'slug' => 'reunion-programada', 'stage_type' => 'open', 'default_probability' => 40, 'sort' => 3],
            ['name' => 'Propuesta enviada', 'slug' => 'propuesta-enviada', 'stage_type' => 'open', 'default_probability' => 60, 'sort' => 4],
            ['name' => 'Negociación', 'slug' => 'negociacion', 'stage_type' => 'open', 'default_probability' => 75, 'sort' => 5],
            ['name' => 'Ganada', 'slug' => 'ganada', 'stage_type' => 'won', 'default_probability' => 100, 'sort' => 6],
            ['name' => 'Perdida', 'slug' => 'perdida', 'stage_type' => 'lost', 'default_probability' => 0, 'sort' => 7],
        ] as $stage) {
            PipelineStage::query()->updateOrCreate(
                ['slug' => $stage['slug']],
                [
                    'name' => $stage['name'],
                    'stage_type' => $stage['stage_type'],
                    'default_probability' => $stage['default_probability'],
                    'sort' => $stage['sort'],
                    'is_active' => true,
                ]
            );
        }

        foreach ([
            ['name' => 'Precio', 'slug' => 'precio', 'sort' => 1],
            ['name' => 'Competencia', 'slug' => 'competencia', 'sort' => 2],
            ['name' => 'Sin respuesta', 'slug' => 'sin-respuesta', 'sort' => 3],
            ['name' => 'Sin presupuesto', 'slug' => 'sin-presupuesto', 'sort' => 4],
            ['name' => 'No interesado', 'slug' => 'no-interesado', 'sort' => 5],
            ['name' => 'Otro', 'slug' => 'otro', 'sort' => 6],
        ] as $reason) {
            LossReason::query()->updateOrCreate(
                ['slug' => $reason['slug']],
                ['name' => $reason['name'], 'sort' => $reason['sort'], 'is_active' => true]
            );
        }

        foreach ([
            ['name' => 'Consultoría', 'slug' => 'consultoria', 'sort' => 1],
            ['name' => 'Software', 'slug' => 'software', 'sort' => 2],
            ['name' => 'Soporte', 'slug' => 'soporte', 'sort' => 3],
            ['name' => 'Capacitación', 'slug' => 'capacitacion', 'sort' => 4],
        ] as $category) {
            ProductCategory::query()->updateOrCreate(
                ['slug' => $category['slug']],
                ['name' => $category['name'], 'sort' => $category['sort'], 'is_active' => true]
            );
        }
    }
}
