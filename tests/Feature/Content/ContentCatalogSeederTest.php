<?php

namespace Tests\Feature\Content;

use App\Models\Content\ContentChannel;
use App\Models\Content\ContentFormat;
use App\Models\Content\ContentObjective;
use App\Models\Content\ContentPillar;
use App\Models\Content\ContentProject;
use App\Models\Content\ContentTopic;
use App\Models\User;
use Database\Seeders\ContentCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ContentCatalogSeederTest extends TestCase
{
    use RefreshDatabase;

    private const PILLARS = [
        'maia-consultores' => [
            'Somos Maia' => ['Quiénes somos', 'Nuestro equipo', 'Cultura Maia', 'Novedades Maia'],
            'Maia Business' => ['Conoce Maia Business', 'Funciones y módulos', 'Personalización, desarrollo e integraciones', 'Beneficios y soluciones', 'Novedades y actualizaciones'],
            'Consultoría' => ['Saneamiento ambiental', 'Calidad e inocuidad alimentaria', 'Proyectos ProInnóvate'],
            'Capacitación' => ['Curso en Saneamiento Ambiental'],
            'Educación' => ['Conocimiento técnico en saneamiento', 'Cultura de calidad e inocuidad', 'Transformación digital', 'Normativa y actualidad'],
            'Experiencia y confianza' => ['Casos y proyectos', 'Reconocimientos y logros', 'Eventos y participación empresarial', 'Historia y evolución de Maia'],
        ],
        'maia-plagas' => [
            'Maia Plagas SaaS' => ['Conoce Maia Plagas', 'Funciones y módulos', 'Beneficios y soluciones', 'Novedades y actualizaciones'],
            'Maia Plagas Licencia' => ['Conoce Maia Plagas Licencia', 'Funciones y módulos', 'Personalización y desarrollo', 'Beneficios y soluciones', 'Novedades y actualizaciones'],
            'Educación' => ['Gestión de empresas de saneamiento', 'Normativa y cumplimiento', 'Buenas prácticas de gestión', 'Transformación digital', 'Tendencias y actualidad del sector'],
            'Experiencia y confianza' => ['Casos y experiencias de clientes', 'Maia Plagas en acción', 'Reconocimientos y logros', 'Eventos y participación empresarial', 'Comunidad y fechas especiales'],
        ],
        'maia-residuos' => [
            'Maia Residuos' => ['Conoce Maia Residuos', 'Funciones y módulos', 'Personalización y desarrollo', 'Beneficios y soluciones', 'Novedades y actualizaciones'],
            'Educación' => ['Gestión de residuos sólidos', 'Normativa y cumplimiento', 'Buenas prácticas de gestión', 'Transformación digital', 'Tendencias y actualidad del sector'],
            'Experiencia y confianza' => ['Casos y experiencias de clientes', 'Maia Residuos en acción', 'Reconocimientos y logros', 'Eventos y participación empresarial', 'Comunidad y fechas especiales'],
        ],
    ];

    private const OBJECTIVES = [
        'Aumentar seguidores', 'Aumentar interacciones', 'Aumentar alcance',
        'Aumentar reproducciones', 'Aumentar guardados', 'Aumentar compartidos',
        'Aumentar visitas al perfil o web', 'Aumentar testimonios', 'Aumentar recomendaciones',
        'Aumentar leads', 'Aumentar conversaciones por WhatsApp', 'Aumentar solicitudes de demo',
        'Aumentar cotizaciones', 'Aumentar ventas o conversión', 'Reducir costo por lead',
    ];

    public function test_own_seeder_twice_keeps_exact_specification_counts_order_and_ownership(): void
    {
        // This exercises ContentCatalogSeeder only, not whole-DatabaseSeeder idempotency.
        $permissionsBefore = DB::table('permissions')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        $grantsBefore = DB::table('role_has_permissions')->orderBy('role_id')->orderBy('permission_id')->get()->map(fn ($row) => (array) $row)->all();
        $this->seed(ContentCatalogSeeder::class);
        $first = $this->snapshot();
        $this->assertSpecification();
        $this->seed(ContentCatalogSeeder::class);
        $this->assertSame($first, $this->snapshot());
        $this->assertSpecification();
        $this->assertSame(0, User::count());
        $this->assertSame(0, DB::table('content_project_user')->count());
        $this->assertSame($permissionsBefore, DB::table('permissions')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all());
        $this->assertSame($grantsBefore, DB::table('role_has_permissions')->orderBy('role_id')->orderBy('permission_id')->get()->map(fn ($row) => (array) $row)->all());
    }

    public function test_accented_names_keep_accents_but_slugs_are_transliterated(): void
    {
        $this->seed(ContentCatalogSeeder::class);
        foreach ([
            'Quiénes somos' => 'quienes-somos',
            'Proyectos ProInnóvate' => 'proyectos-proinnovate',
            'Personalización, desarrollo e integraciones' => 'personalizacion-desarrollo-e-integraciones',
            'Gestión de residuos sólidos' => 'gestion-de-residuos-solidos',
            'Transformación digital' => 'transformacion-digital',
        ] as $name => $slug) {
            $this->assertDatabaseHas('content_topics', ['name' => $name, 'slug' => $slug]);
        }
        $this->assertDatabaseHas('content_pillars', ['name' => 'Educación', 'slug' => 'educacion']);
        $this->assertDatabaseHas('content_objectives', ['name' => 'Aumentar ventas o conversión', 'slug' => 'aumentar-ventas-o-conversion']);
    }

    public function test_reseed_preserves_custom_data_memberships_grants_and_lifecycle(): void
    {
        $this->seed(ContentCatalogSeeder::class);
        $project = ContentProject::where('slug', 'maia-consultores')->firstOrFail();
        $actor = User::factory()->create();
        $project->members()->attach($actor);
        $project->update(['color' => '#123456']);
        $project->archive();
        $project->delete();
        $pillar = ContentPillar::where('content_project_id', $project->id)->firstOrFail();
        $pillar->deactivate();
        $topic = $pillar->topics()->firstOrFail();
        $topic->deactivate();
        foreach ([ContentObjective::class, ContentFormat::class, ContentChannel::class] as $model) {
            $model::where('content_project_id', $project->id)->firstOrFail()->deactivate();
        }
        $custom = ContentObjective::factory()->for($project, 'project')->create();
        $foreign = ContentProject::factory()->create();
        $permissionCount = DB::table('permissions')->count();
        $permission = \Spatie\Permission\Models\Permission::create(['name' => 'sentinel.view', 'guard_name' => 'web']);
        $actor->givePermissionTo($permission);
        $grants = DB::table('model_has_permissions')->get()->map(fn ($row) => (array) $row)->all();

        $this->seed(ContentCatalogSeeder::class);

        $this->assertSame(4, ContentProject::withTrashed()->count());
        $this->assertSame('#123456', $project->fresh()->color);
        $this->assertTrue($project->fresh()->trashed());
        $this->assertTrue($project->fresh()->isArchived());
        $this->assertFalse($project->fresh()->is_active);
        $this->assertFalse($pillar->fresh()->is_active);
        $this->assertFalse($topic->fresh()->is_active);
        foreach ([ContentObjective::class, ContentFormat::class, ContentChannel::class] as $model) {
            $this->assertSame(1, $model::where('content_project_id', $project->id)->where('is_active', false)->count());
        }
        $this->assertNotNull($custom->fresh());
        $this->assertNotNull($foreign->fresh());
        $this->assertSame([$actor->id], $project->members()->pluck('users.id')->all());
        $this->assertSame($grants, DB::table('model_has_permissions')->get()->map(fn ($row) => (array) $row)->all());
        $this->assertSame(1, User::count());
        $this->assertSame($permissionCount + 1, DB::table('permissions')->count());
    }

    private function assertSpecification(): void
    {
        foreach ([ContentProject::class => 3, ContentPillar::class => 13, ContentTopic::class => 55, ContentObjective::class => 46, ContentFormat::class => 15, ContentChannel::class => 18] as $model => $count) {
            $this->assertSame($count, $model::count(), $model);
        }
        $this->assertSame([
            'maia-consultores' => 'Maia Consultores',
            'maia-plagas' => 'Maia Plagas',
            'maia-residuos' => 'Maia Residuos',
        ], ContentProject::orderBy('id')->pluck('name', 'slug')->all());
        $this->assertSame(['#2563EB', '#EA580C', '#16A34A'], ContentProject::orderBy('id')->pluck('color')->all());

        foreach (self::PILLARS as $slug => $expectedPillars) {
            $project = ContentProject::where('slug', $slug)->firstOrFail();
            $this->assertTrue($project->isActive());
            $pillars = ContentPillar::where('content_project_id', $project->id)->orderBy('sort')->get();
            $this->assertOrderedOptions(array_keys($expectedPillars), $pillars);
            foreach ($pillars as $pillar) {
                $this->assertTrue($pillar->project->is($project));
                $topics = $pillar->topics()->orderBy('sort')->get();
                $this->assertOrderedOptions($expectedPillars[$pillar->name], $topics);
                foreach ($topics as $topic) {
                    $this->assertTrue($topic->pillar->is($pillar));
                    $this->assertTrue($topic->pillar->project->is($project));
                }
            }
            $objectives = self::OBJECTIVES;
            if ($slug === 'maia-consultores') {
                $objectives[] = 'Generar inscripciones a cursos';
            } else {
                $this->assertDatabaseMissing('content_objectives', ['content_project_id' => $project->id, 'slug' => 'generar-inscripciones-a-cursos']);
            }
            foreach ([ContentObjective::class => $objectives, ContentFormat::class => ['Imagen', 'Carrusel', 'Reel', 'Video', 'Historia'], ContentChannel::class => ['Facebook', 'Instagram', 'TikTok', 'YouTube', 'LinkedIn', 'WhatsApp']] as $model => $names) {
                $this->assertOrderedOptions($names, $model::where('content_project_id', $project->id)->orderBy('sort')->get());
            }
        }
    }

    private function assertOrderedOptions(array $names, $rows): void
    {
        $this->assertSame($names, $rows->pluck('name')->all());
        $this->assertSame(range(1, count($names)), $rows->pluck('sort')->all());
        $this->assertSame(array_map(fn (string $name) => Str::slug($name), $names), $rows->pluck('slug')->all());
        $this->assertTrue($rows->every(fn ($row) => $row->is_active));
    }

    private function snapshot(): array
    {
        $snapshot = [];
        foreach ([ContentProject::class, ContentPillar::class, ContentTopic::class, ContentObjective::class, ContentFormat::class, ContentChannel::class] as $model) {
            $snapshot[$model] = $model::orderBy('id')->get()->map(fn ($row) => collect($row->getAttributes())->except(['created_at', 'updated_at'])->all())->all();
        }

        return $snapshot;
    }
}
