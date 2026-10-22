<?php

namespace Database\Seeders;

use App\Models\Content\ContentChannel;
use App\Models\Content\ContentFormat;
use App\Models\Content\ContentObjective;
use App\Models\Content\ContentPillar;
use App\Models\Content\ContentProject;
use App\Models\Content\ContentTopic;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Specification catalogs only: no users, memberships or permission grants. */
class ContentCatalogSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            foreach ($this->projects() as $name => $definition) {
                // Fixed blue/orange/green hues distinguish the consulting, pest and
                // waste calendars respectively; green evokes environmental work.
                // These are identification defaults, not official brand colours.
                // Preserve customized colours and archived/trashed project state.
                $project = ContentProject::withTrashed()->firstOrCreate(
                    ['slug' => Str::slug($name)],
                    ['name' => $name, 'color' => $definition['color'], 'is_active' => true],
                );

                foreach ($definition['pillars'] as $pillarName => $topics) {
                    $pillar = ContentPillar::query()->updateOrCreate(
                        ['content_project_id' => $project->id, 'slug' => Str::slug($pillarName)],
                        ['name' => $pillarName, 'sort' => array_search($pillarName, array_keys($definition['pillars']), true) + 1],
                    );
                    $this->seedOptions(ContentTopic::class, 'content_pillar_id', $pillar->id, $topics);
                }

                $objectives = $this->objectives();
                if ($project->slug === 'maia-consultores') {
                    $objectives[] = 'Generar inscripciones a cursos';
                }
                $this->seedOptions(ContentObjective::class, 'content_project_id', $project->id, $objectives);
                $this->seedOptions(ContentFormat::class, 'content_project_id', $project->id, ['Imagen', 'Carrusel', 'Reel', 'Video', 'Historia']);
                $this->seedOptions(ContentChannel::class, 'content_project_id', $project->id, ['Facebook', 'Instagram', 'TikTok', 'YouTube', 'LinkedIn', 'WhatsApp']);
            }
        });
    }

    /** Restore specification labels/order without deleting extras or reactivating retired options. */
    private function seedOptions(string $model, string $parentKey, int $parentId, array $names): void
    {
        foreach ($names as $index => $name) {
            $model::query()->updateOrCreate(
                [$parentKey => $parentId, 'slug' => Str::slug($name)],
                ['name' => $name, 'sort' => $index + 1],
            );
        }
    }

    private function objectives(): array
    {
        return [
            'Aumentar seguidores',
            'Aumentar interacciones',
            'Aumentar alcance',
            'Aumentar reproducciones',
            'Aumentar guardados',
            'Aumentar compartidos',
            'Aumentar visitas al perfil o web',
            'Aumentar testimonios',
            'Aumentar recomendaciones',
            'Aumentar leads',
            'Aumentar conversaciones por WhatsApp',
            'Aumentar solicitudes de demo',
            'Aumentar cotizaciones',
            'Aumentar ventas o conversión',
            'Reducir costo por lead',
        ];
    }

    private function projects(): array
    {
        return [
            'Maia Consultores' => [
                'color' => '#2563EB',
                'pillars' => [
                    'Somos Maia' => ['Quiénes somos', 'Nuestro equipo', 'Cultura Maia', 'Novedades Maia'],
                    'Maia Business' => ['Conoce Maia Business', 'Funciones y módulos', 'Personalización, desarrollo e integraciones', 'Beneficios y soluciones', 'Novedades y actualizaciones'],
                    'Consultoría' => ['Saneamiento ambiental', 'Calidad e inocuidad alimentaria', 'Proyectos ProInnóvate'],
                    'Capacitación' => ['Curso en Saneamiento Ambiental'],
                    'Educación' => ['Conocimiento técnico en saneamiento', 'Cultura de calidad e inocuidad', 'Transformación digital', 'Normativa y actualidad'],
                    'Experiencia y confianza' => ['Casos y proyectos', 'Reconocimientos y logros', 'Eventos y participación empresarial', 'Historia y evolución de Maia'],
                ],
            ],
            'Maia Plagas' => [
                'color' => '#EA580C',
                'pillars' => [
                    'Maia Plagas SaaS' => ['Conoce Maia Plagas', 'Funciones y módulos', 'Beneficios y soluciones', 'Novedades y actualizaciones'],
                    'Maia Plagas Licencia' => ['Conoce Maia Plagas Licencia', 'Funciones y módulos', 'Personalización y desarrollo', 'Beneficios y soluciones', 'Novedades y actualizaciones'],
                    'Educación' => ['Gestión de empresas de saneamiento', 'Normativa y cumplimiento', 'Buenas prácticas de gestión', 'Transformación digital', 'Tendencias y actualidad del sector'],
                    'Experiencia y confianza' => ['Casos y experiencias de clientes', 'Maia Plagas en acción', 'Reconocimientos y logros', 'Eventos y participación empresarial', 'Comunidad y fechas especiales'],
                ],
            ],
            'Maia Residuos' => [
                'color' => '#16A34A',
                'pillars' => [
                    'Maia Residuos' => ['Conoce Maia Residuos', 'Funciones y módulos', 'Personalización y desarrollo', 'Beneficios y soluciones', 'Novedades y actualizaciones'],
                    'Educación' => ['Gestión de residuos sólidos', 'Normativa y cumplimiento', 'Buenas prácticas de gestión', 'Transformación digital', 'Tendencias y actualidad del sector'],
                    'Experiencia y confianza' => ['Casos y experiencias de clientes', 'Maia Residuos en acción', 'Reconocimientos y logros', 'Eventos y participación empresarial', 'Comunidad y fechas especiales'],
                ],
            ],
        ];
    }
}
