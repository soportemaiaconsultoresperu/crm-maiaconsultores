<?php

namespace App\Services\DemoData;

use App\Models\Activity;
use App\Models\ActivityType;
use App\Models\CampaignActionItem;
use App\Models\CampaignParticipant;
use App\Models\CampaignRun;
use App\Models\CampaignStep;
use App\Models\CampaignTemplate;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\DemoDataBatch;
use App\Models\DemoDataRecord;
use App\Models\Document;
use App\Models\Lead;
use App\Models\LeadSource;
use App\Models\LeadStatus;
use App\Models\Opportunity;
use App\Models\OpportunityStageHistory;
use App\Models\PipelineStage;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Quotation;
use App\Models\QuotationItem;
use App\Models\SupportAssignment;
use App\Models\SupportCategory;
use App\Models\SupportChannel;
use App\Models\SupportIncidentDetail;
use App\Models\SupportObservation;
use App\Models\SupportPriority;
use App\Models\SupportStatus;
use App\Models\SupportTicket;
use App\Models\SupportTicketType;
use App\Models\SupportTicketUpdate;
use App\Models\Tax;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class DemoDataGenerator
{
    public const SCENARIO_NAME = 'Presentación comercial completa';

    public function __construct(
        private readonly DemoDataContext $context,
        private readonly DemoDataDependencyPreview $preview,
    ) {}

    /** @param list<string> $modules */
    public function generate(array $modules, ?User $actor = null): DemoDataBatch
    {
        $existingActive = DemoDataBatch::query()->active()->latest('id')->first();
        if ($existingActive instanceof DemoDataBatch) {
            return $existingActive;
        }

        $expanded = $this->preview->preview($modules)['expanded'];

        /** @var DemoDataBatch $batch */
        $batch = DemoDataBatch::query()->create([
            'uuid' => (string) Str::uuid(),
            'scenario_name' => self::SCENARIO_NAME,
            'status' => DemoDataBatch::STATUS_RUNNING,
            'modules' => $expanded,
            'record_counts' => [],
            'created_by' => $actor?->id,
            'started_at' => now(),
        ]);

        try {
            DB::transaction(function () use ($batch, $expanded, $actor): void {
                $this->context->runForBatch($batch, function () use ($batch, $expanded, $actor): void {
                    $state = [];
                    foreach ($expanded as $module) {
                        match ($module) {
                            'users' => $state['users'] = $state['users'] ?? $this->createUsers($batch),
                            'products' => $state['products'] = $state['products'] ?? $this->createProducts($batch),
                            'leads' => $state['leads'] = $state['leads'] ?? $this->createLeads($batch, $state['users'] ??= $this->createUsers($batch)),
                            'customers' => $state['customers'] = $state['customers'] ?? $this->createCustomers($batch, $state['users'] ??= $this->createUsers($batch)),
                            'contacts' => $state['contacts'] = $state['contacts'] ?? $this->createContacts($batch, $state['customers'] ??= $this->createCustomers($batch, $state['users'] ??= $this->createUsers($batch))),
                            'opportunities' => $state['opportunities'] = $state['opportunities'] ?? $this->createOpportunities($batch, $state),
                            'activities' => $state['activities'] = $state['activities'] ?? $this->createActivities($batch, $state),
                            'quotations' => $state['quotations'] = $state['quotations'] ?? $this->createQuotations($batch, $state),
                            'documents' => $state['documents'] = $state['documents'] ?? $this->createDocuments($batch, $state, $actor),
                            'campaigns' => $state['campaigns'] = $state['campaigns'] ?? $this->createCampaigns($batch, $state),
                            'support' => $state['support'] = $state['support'] ?? $this->createSupport($batch, $state),
                            default => null,
                        };
                    }

                    $batch->forceFill([
                        'status' => DemoDataBatch::STATUS_COMPLETED,
                        'record_counts' => $batch->records()->selectRaw('module, count(*) as total')->groupBy('module')->pluck('total', 'module')->all(),
                        'finished_at' => now(),
                    ])->save();
                });
            });
        } catch (Throwable $exception) {
            $batch->forceFill(['status' => DemoDataBatch::STATUS_FAILED, 'finished_at' => now()])->save();
            throw $exception;
        }

        return $batch->refresh();
    }

    /** @return Collection<int, User> */
    private function createUsers(DemoDataBatch $batch): Collection
    {
        return collect([
            ['Carla Rojas Demo', 'carla.rojas@maia-demo.example'],
            ['Miguel Torres Demo', 'miguel.torres@maia-demo.example'],
            ['Valeria Salazar Demo', 'valeria.salazar@maia-demo.example'],
        ])->map(function (array $row) use ($batch): User {
            $user = User::query()->create([
                'name' => $row[0],
                'email' => $row[1],
                'password' => Hash::make(Str::password(32)),
                'is_active' => false,
            ]);
            if (method_exists($user, 'assignRole') && \Spatie\Permission\Models\Role::query()->where('name', 'vendedor')->exists()) {
                $user->assignRole('vendedor');
            }
            $this->register($batch, 'users', $user);

            return $user;
        });
    }

    /** @return Collection<int, Product> */
    private function createProducts(DemoDataBatch $batch): Collection
    {
        $tax = Tax::query()->where('slug', 'gravado-igv')->firstOrFail();
        $categories = ProductCategory::query()->whereIn('slug', ['consultoria', 'software', 'soporte', 'capacitacion'])->get()->keyBy('slug');

        return collect([
            ['Implementación CRM Comercial', 'software', 18500],
            ['Consultoría de Procesos Comerciales', 'consultoria', 9200],
            ['Capacitación Equipo de Ventas', 'capacitacion', 4800],
            ['Soporte Mensual CRM', 'soporte', 2500],
            ['Automatización de Seguimiento', 'software', 7600],
            ['Diagnóstico Comercial Ejecutivo', 'consultoria', 6200],
            ['Mesa de Ayuda Funcional', 'soporte', 3900],
            ['Tablero de Indicadores Comerciales', 'software', 11800],
            ['Acompañamiento Go Live', 'capacitacion', 5400],
            ['Optimización de Pipeline', 'consultoria', 8300],
        ])->map(function (array $row, int $index) use ($batch, $tax, $categories): Product {
            $product = Product::query()->create([
                'code' => $this->code('DEMO-SRV', $index + 1),
                'product_type' => 'servicio',
                'name' => $row[0],
                'category_id' => $categories[$row[1]]?->id,
                'description' => 'Servicio ficticio para presentaciones comerciales del CRM.',
                'price' => $row[2],
                'currency_code' => 'PEN',
                'tax_id' => $tax->id,
                'is_active' => true,
            ]);
            $this->register($batch, 'products', $product);

            return $product;
        });
    }

    /** @param Collection<int, User> $users @return Collection<int, Lead> */
    private function createLeads(DemoDataBatch $batch, Collection $users): Collection
    {
        $statuses = LeadStatus::query()->get()->keyBy('slug');
        $sources = LeadSource::query()->get()->keyBy('slug');
        $firstNames = ['Ana', 'Luis', 'Rosa', 'Jorge', 'Patricia', 'Diego', 'Elena', 'Marco', 'Lucía', 'Sofía', 'Renato', 'Camila', 'Andrés', 'Valeria', 'Hugo'];
        $lastNames = ['Paredes', 'Huamán', 'Vega', 'Salinas', 'Núñez', 'Mendoza', 'Campos', 'Rivas', 'Gálvez', 'Torres', 'Delgado', 'Mori', 'Castro', 'Reyes', 'Soto'];
        $companies = ['Grupo Andino', 'Textiles Lima Norte', 'Clínica San Felipe', 'Constructora Horizonte', 'Agroexport Sol', 'Logística Pacífico', 'Colegio Prisma', 'Inversiones Sur', 'Retail Plaza', 'Corporación Altamar', 'Servicios Delta', 'Distribuidora Central', 'Laboratorio Nova', 'Hotel Mirador', 'Consultora Prisma'];
        $statusSlugs = ['nuevo', 'contactado', 'calificado', 'no-calificado', 'convertido', 'perdido'];
        $sourceSlugs = ['web', 'referido', 'campana', 'feria', 'redes-sociales', 'llamada'];

        return collect(range(0, 44))->map(function (int $index) use ($batch, $users, $statuses, $sources, $firstNames, $lastNames, $companies, $statusSlugs, $sourceSlugs): Lead {
            $email = 'lead'.($index + 1).'@maia-demo.example';
            $lead = Lead::query()->create([
                'code' => $this->code('DEMO-L', $index + 1),
                'person_type' => 'juridica',
                'first_name' => $firstNames[$index % count($firstNames)],
                'last_name' => $lastNames[$index % count($lastNames)],
                'company_name' => $companies[$index % count($companies)].' Demo '.str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT),
                'position' => ['Gerencia comercial', 'Administración', 'Operaciones', 'Dirección general'][$index % 4],
                'phone' => '999'.str_pad((string) (100000 + $index), 6, '0', STR_PAD_LEFT),
                'whatsapp' => '999'.str_pad((string) (200000 + $index), 6, '0', STR_PAD_LEFT),
                'email' => $email,
                'email_norm' => $email,
                'source_id' => $sources[$sourceSlugs[$index % count($sourceSlugs)]]->id,
                'status_id' => $statuses[$statusSlugs[$index % count($statusSlugs)]]->id,
                'interest_level' => ['bajo', 'medio', 'alto'][$index % 3],
                'owner_id' => $users[$index % $users->count()]->id,
                'entered_at' => now()->subDays(44 - $index),
                'observations' => 'Prospecto ficticio creado para demostración. No contactar.',
            ]);
            $this->register($batch, 'leads', $lead);

            return $lead;
        });
    }

    /** @param Collection<int, User> $users @return Collection<int, Customer> */
    private function createCustomers(DemoDataBatch $batch, Collection $users): Collection
    {
        $names = [
            'Maia Retail Demo SAC', 'Norte Industrial Demo SAC', 'Servicios Médicos Demo SRL', 'Innova Educación Demo SAC', 'Logística Andina Demo SAC',
            'Agroexport Sol Demo SAC', 'Hotel Mirador Demo SAC', 'Corporación Altamar Demo SAC', 'Distribuidora Central Demo SAC', 'Laboratorio Nova Demo SAC',
            'Constructora Horizonte Demo SAC', 'Colegio Prisma Demo SAC', 'Consultora Delta Demo SAC', 'Retail Plaza Demo SAC', 'Clínica San Felipe Demo SAC',
        ];
        $sectors = ['Retail', 'Industrial', 'Salud', 'Educación', 'Logística', 'Agroindustria', 'Hotelería', 'Servicios', 'Distribución', 'Laboratorio'];

        return collect($names)->map(function (string $name, int $index) use ($batch, $users, $sectors): Customer {
            $docNumber = '20'.str_pad((string) (900000000 + $index), 9, '0', STR_PAD_LEFT);
            $customer = Customer::query()->create([
                'code' => $this->code('DEMO-C', $index + 1),
                'person_type' => 'juridica',
                'legal_name' => $name,
                'trade_name' => str_replace(' Demo', '', $name),
                'doc_type' => 'ruc',
                'doc_number' => $docNumber,
                'doc_number_norm' => $docNumber,
                'phone' => '01-555-10'.str_pad((string) $index, 2, '0', STR_PAD_LEFT),
                'email' => 'cliente'.($index + 1).'@maia-demo.example',
                'email_norm' => 'cliente'.($index + 1).'@maia-demo.example',
                'sector' => $sectors[$index % count($sectors)],
                'owner_id' => $users[$index % $users->count()]->id,
                'status' => $index % 7 === 0 ? 'inactivo' : 'activo',
                'observations' => 'Cliente ficticio para presentación. No contactar.',
            ]);
            $this->register($batch, 'customers', $customer);

            return $customer;
        });
    }

    /** @param Collection<int, Customer> $customers @return Collection<int, Contact> */
    private function createContacts(DemoDataBatch $batch, Collection $customers): Collection
    {
        $firstNames = ['María', 'Carlos', 'Fernanda', 'Ricardo', 'Paola', 'Sergio', 'Claudia', 'Héctor'];
        $lastNames = ['Loayza', 'Ponce', 'Arce', 'Mejía', 'Vargas', 'Quispe', 'Rojas', 'Silva'];

        return collect(range(0, 23))->map(function (int $index) use ($batch, $customers, $firstNames, $lastNames): Contact {
            $customer = $customers[$index % $customers->count()];
            $contact = Contact::query()->create([
                'customer_id' => $customer->id,
                'first_name' => $firstNames[$index % count($firstNames)],
                'last_name' => $lastNames[$index % count($lastNames)],
                'position' => ['Jefatura comercial', 'Administración', 'Operaciones', 'Gerencia general'][$index % 4],
                'area' => ['Comercial', 'Finanzas', 'Operaciones', 'Dirección'][$index % 4],
                'phone' => '988000'.str_pad((string) $index, 3, '0', STR_PAD_LEFT),
                'whatsapp' => '988111'.str_pad((string) $index, 3, '0', STR_PAD_LEFT),
                'email' => 'contacto'.($index + 1).'@maia-demo.example',
                'email_norm' => 'contacto'.($index + 1).'@maia-demo.example',
                'is_primary' => $index < $customers->count(),
                'is_active' => true,
                'observations' => 'Contacto ficticio de demostración. No contactar.',
            ]);
            $this->register($batch, 'contacts', $contact);

            return $contact;
        });
    }

    /** @param array<string,mixed> $state @return Collection<int, Opportunity> */
    private function createOpportunities(DemoDataBatch $batch, array &$state): Collection
    {
        $users = $state['users'] ??= $this->createUsers($batch);
        $customers = $state['customers'] ??= $this->createCustomers($batch, $users);
        $contacts = $state['contacts'] ??= $this->createContacts($batch, $customers);
        $leads = $state['leads'] ??= $this->createLeads($batch, $users);
        $stages = PipelineStage::query()->get()->keyBy('slug');
        $source = LeadSource::query()->where('slug', 'campana')->first();
        $stageSlugs = ['nueva-oportunidad', 'contacto-realizado', 'reunion-programada', 'propuesta-enviada', 'negociacion', 'ganada', 'perdida'];

        return collect(range(0, 24))->map(function (int $index) use ($batch, $users, $customers, $contacts, $leads, $stages, $source, $stageSlugs): Opportunity {
            $stage = $stages[$stageSlugs[$index % count($stageSlugs)]];
            $customer = $customers[$index % $customers->count()];
            $opportunity = Opportunity::query()->create([
                'code' => $this->code('DEMO-O', $index + 1),
                'title' => 'Proyecto demo '.($index + 1).' - '.$customer->trade_name,
                'lead_id' => $index < 15 ? $leads[$index]->id : null,
                'customer_id' => $customer->id,
                'contact_id' => $contacts[$index % $contacts->count()]->id,
                'owner_id' => $users[$index % $users->count()]->id,
                'stage_id' => $stage->id,
                'estimated_amount' => 9000 + ($index * 1800),
                'currency_code' => 'PEN',
                'probability' => $stage->default_probability,
                'expected_close_at' => now()->addDays(5 + $index)->toDateString(),
                'source_id' => $source?->id,
                'priority' => ['baja', 'media', 'alta'][$index % 3],
                'description' => 'Oportunidad ficticia para mostrar el embudo comercial.',
                'closed_at' => in_array($stage->slug, ['ganada', 'perdida'], true) ? now()->subDays($index % 10) : null,
                'final_amount' => $stage->slug === 'ganada' ? 12000 + ($index * 1200) : null,
            ]);
            $this->register($batch, 'opportunities', $opportunity);

            $history = OpportunityStageHistory::query()->create([
                'opportunity_id' => $opportunity->id,
                'from_stage_id' => null,
                'to_stage_id' => $stage->id,
                'user_id' => $opportunity->owner_id,
                'changed_at' => now()->subDays(24 - min($index, 24)),
                'note' => 'Historial demo de etapa comercial.',
            ]);
            $this->register($batch, 'opportunities', $history);

            return $opportunity;
        });
    }

    /** @param array<string,mixed> $state @return Collection<int, Activity> */
    private function createActivities(DemoDataBatch $batch, array &$state): Collection
    {
        $users = $state['users'] ??= $this->createUsers($batch);
        $opportunities = $state['opportunities'] ??= $this->createOpportunities($batch, $state);
        $types = ActivityType::query()->get()->keyBy('slug');
        $statuses = ['pending', 'in_process', 'completed', 'overdue', 'cancelled'];

        return collect(range(0, 59))->map(function (int $index) use ($batch, $users, $opportunities, $types, $statuses): Activity {
            $status = $statuses[$index % count($statuses)];
            $activity = Activity::query()->create([
                'type_id' => $types[['llamada', 'reunion', 'tarea', 'correo'][$index % 4]]->id,
                'subject_type' => Opportunity::class,
                'subject_id' => $opportunities[$index % $opportunities->count()]->id,
                'owner_id' => $users[$index % $users->count()]->id,
                'title' => 'Seguimiento demo '.($index + 1),
                'description' => 'Actividad ficticia para calendario y reportes.',
                'scheduled_at' => $status === 'overdue' ? now()->subDays(($index % 8) + 1) : now()->addDays($index % 30),
                'executed_at' => $status === 'completed' ? now()->subDays($index % 7) : null,
                'result' => $status === 'completed' ? 'Cliente interesado en siguiente paso.' : null,
                'status' => $status,
                'priority' => ['baja', 'media', 'alta'][$index % 3],
                'reminder_at' => now()->addDays(max(0, ($index % 30) - 1)),
            ]);
            $this->register($batch, 'activities', $activity);

            return $activity;
        });
    }

    /** @param array<string,mixed> $state @return Collection<int, Quotation> */
    private function createQuotations(DemoDataBatch $batch, array &$state): Collection
    {
        $users = $state['users'] ??= $this->createUsers($batch);
        $products = $state['products'] ??= $this->createProducts($batch);
        $opportunities = $state['opportunities'] ??= $this->createOpportunities($batch, $state);
        $tax = Tax::query()->where('slug', 'gravado-igv')->firstOrFail();
        $statuses = ['draft', 'sent', 'accepted', 'rejected', 'expired', 'voided'];

        return collect(range(0, 15))->map(function (int $index) use ($batch, $users, $products, $opportunities, $tax, $statuses): Quotation {
            $opportunity = $opportunities[$index % $opportunities->count()];
            $product = $products[$index % $products->count()];
            $subtotal = (float) $product->price;
            $taxAmount = round($subtotal * ((float) $tax->rate / 100), 2);
            $status = $statuses[$index % count($statuses)];
            $quotation = Quotation::query()->create([
                'number' => $this->code('DEMO-Q', $index + 1),
                'customer_id' => $opportunity->customer_id,
                'contact_id' => $opportunity->contact_id,
                'opportunity_id' => $opportunity->id,
                'owner_id' => $users[$index % $users->count()]->id,
                'issued_at' => now()->subDays(16 - $index)->toDateString(),
                'expires_at' => now()->addDays(8 + $index)->toDateString(),
                'currency_code' => 'PEN',
                'terms' => 'Condiciones ficticias para demostración.',
                'observations' => 'Cotización demo. No enviar al cliente.',
                'status' => $status,
                'subtotal' => $subtotal,
                'discount_total' => 0,
                'tax_total' => $taxAmount,
                'total' => $subtotal + $taxAmount,
                'accepted_at' => $status === 'accepted' ? now()->subDay() : null,
            ]);
            $this->register($batch, 'quotations', $quotation);

            $item = QuotationItem::query()->create([
                'quotation_id' => $quotation->id,
                'product_id' => $product->id,
                'description' => $product->name,
                'quantity' => 1 + ($index % 2),
                'unit' => 'servicio',
                'unit_price' => $subtotal,
                'discount_amount' => 0,
                'tax_id' => $tax->id,
                'tax_name' => $tax->name,
                'tax_rate' => $tax->rate,
                'line_subtotal' => $subtotal,
                'line_tax' => $taxAmount,
                'line_total' => $subtotal + $taxAmount,
            ]);
            $this->register($batch, 'quotations', $item);

            return $quotation;
        });
    }

    /** @param array<string,mixed> $state @return Collection<int, Document> */
    private function createDocuments(DemoDataBatch $batch, array &$state, ?User $actor): Collection
    {
        $quotations = $state['quotations'] ??= $this->createQuotations($batch, $state);
        $disk = (string) config('filesystems.docs_disk', 'docs');

        return collect(range(0, 19))->map(function (int $index) use ($batch, $disk, $actor, $quotations): Document {
            $quotation = $quotations[$index % $quotations->count()];
            $path = 'demo-data/'.$batch->uuid.'/documento-demo-'.($index + 1).'.txt';
            Storage::disk($disk)->put($path, "Documento de demostración
Lote: {$batch->uuid}
No contiene datos reales.
");
            $document = Document::query()->create([
                'docable_type' => Quotation::class,
                'docable_id' => $quotation->id,
                'name' => 'Documento Demo '.($index + 1).'.txt',
                'disk' => $disk,
                'path' => $path,
                'mime_type' => 'text/plain',
                'extension' => 'txt',
                'size_bytes' => strlen(Storage::disk($disk)->get($path)),
                'uploaded_by' => $actor?->id,
                'uploaded_at' => now(),
            ]);
            $this->register($batch, 'documents', $document);

            return $document;
        });
    }

    /** @param array<string,mixed> $state @return array<string, mixed> */
    private function createCampaigns(DemoDataBatch $batch, array &$state): array
    {
        $users = $state['users'] ??= $this->createUsers($batch);
        $opportunities = $state['opportunities'] ??= $this->createOpportunities($batch, $state);
        $types = ActivityType::query()->whereIn('slug', ['llamada', 'correo', 'reunion', 'tarea'])->get()->keyBy('slug');
        $campaigns = [];

        foreach (range(0, 2) as $campaignIndex) {
            $owner = $users[$campaignIndex % $users->count()];
            $template = CampaignTemplate::query()->create([
                'name' => 'Campaña demo '.($campaignIndex + 1).' '.Str::upper(Str::random(4)),
                'description' => 'Campaña visual/documentaria. No ejecuta integraciones externas.',
                'objective' => ['reactivation', 'nurturing', 'cross_sell'][$campaignIndex],
                'status' => CampaignTemplate::STATUS_INACTIVE,
                'owner_id' => $owner->id,
            ]);
            $this->register($batch, 'campaigns', $template);

            $run = CampaignRun::query()->create([
                'code' => $this->code('DEMO-CAM', $campaignIndex + 1),
                'name' => 'Campaña demo '.($campaignIndex + 1).' - pipeline comercial',
                'template_id' => $template->id,
                'template_hash' => hash('sha256', $template->name),
                'starts_at' => now()->addDays(2 + $campaignIndex),
                'ends_at_estimated' => now()->addDays(16 + $campaignIndex),
                'owner_id' => $owner->id,
                'status' => CampaignRun::STATUS_DRAFT,
                'status_changed_at' => now(),
                'status_changed_by' => $owner->id,
                'status_reason' => 'Demo visual sin ejecución real.',
                'progress_cache' => ['pending' => 4, 'completed' => 3, 'overdue' => 3],
                'observations' => 'No despachar jobs outbound para este lote demo.',
            ]);
            $this->register($batch, 'campaigns', $run);

            $steps = collect(['llamada', 'correo', 'reunion'])->map(function (string $slug, int $index) use ($batch, $run, $types): CampaignStep {
                $step = CampaignStep::query()->create([
                    'is_template' => false,
                    'run_id' => $run->id,
                    'order' => $index + 1,
                    'action_type_id' => $types[$slug]->id,
                    'title' => 'Paso demo '.($index + 1),
                    'day_offset' => $index * 3,
                    'scheduled_time' => '09:00:00',
                    'instructions' => 'Paso visual. No ejecutar integraciones externas.',
                    'is_required' => true,
                    'is_advertising' => false,
                    'status' => CampaignStep::STATUS_INACTIVE,
                ]);
                $this->register($batch, 'campaigns', $step);

                return $step;
            });

            $participants = collect(range(0, 9))->map(function (int $index) use ($batch, $run, $opportunities, $campaignIndex): CampaignParticipant {
                $opportunity = $opportunities[($campaignIndex * 10 + $index) % $opportunities->count()];
                $participant = CampaignParticipant::query()->create([
                    'run_id' => $run->id,
                    'subject_type' => 'opportunity',
                    'subject_id' => $opportunity->id,
                    'assigned_to' => $opportunity->owner_id,
                    'status' => $index % 5 === 4 ? 'excluded' : 'active',
                    'included_at' => now()->subDay(),
                    'excluded_at' => $index % 5 === 4 ? now() : null,
                    'exclusion_reason' => $index % 5 === 4 ? 'Caso demo excluido para mostrar segmentación.' : null,
                    'display_name' => $opportunity->title,
                    'company_name' => $opportunity->customer?->trade_name,
                ]);
                $this->register($batch, 'campaigns', $participant);

                return $participant;
            });

            foreach ($participants as $index => $participant) {
                $item = CampaignActionItem::query()->create([
                    'run_id' => $run->id,
                    'step_id' => $steps[$index % $steps->count()]->id,
                    'participant_id' => $participant->id,
                    'status' => ['pending', 'in_process', 'completed', 'overdue', 'cancelled', 'not_applicable'][$index % 6],
                    'scheduled_at' => now()->addDays($index),
                    'executed_at' => $index % 6 === 2 ? now()->subDay() : null,
                    'completed_by' => $index % 6 === 2 ? $participant->assigned_to : null,
                    'result' => $index % 6 === 2 ? 'Avance ficticio documentado.' : null,
                    'observations' => 'Item visual demo; no hay job outbound asociado.',
                ]);
                $this->register($batch, 'campaigns', $item);
            }

            $campaigns[] = ['template' => $template, 'run' => $run, 'steps' => $steps, 'participants' => $participants];
        }

        return $campaigns;
    }

    /** @param array<string,mixed> $state @return Collection<int, SupportTicket> */
    private function createSupport(DemoDataBatch $batch, array &$state): Collection
    {
        $users = $state['users'] ??= $this->createUsers($batch);
        $customers = $state['customers'] ??= $this->createCustomers($batch, $users);
        $contacts = $state['contacts'] ??= $this->createContacts($batch, $customers);
        $types = SupportTicketType::query()->active()->get()->keyBy('slug');
        $categories = SupportCategory::query()->active()->get()->keyBy('slug');
        $channels = SupportChannel::query()->active()->get()->keyBy('slug');
        $priorities = SupportPriority::query()->active()->get()->keyBy('slug');
        $statuses = SupportStatus::query()->active()->get()->keyBy('slug');
        $typeSlugs = ['ayuda-funcional', 'capacitacion', 'configuracion', 'incidente-o-error', 'asesoria'];
        $categorySlugs = ['funcional', 'capacitacion', 'configuracion', 'tecnico', 'general'];
        $channelSlugs = ['registro-interno', 'llamada', 'whatsapp', 'correo', 'reunion'];
        $prioritySlugs = ['baja', 'media', 'alta', 'critica'];
        $statusSlugs = [SupportStatus::SLUG_NEW, SupportStatus::SLUG_ASSIGNED, SupportStatus::SLUG_SCHEDULED, SupportStatus::SLUG_IN_PROGRESS, SupportStatus::SLUG_WAITING_CUSTOMER, SupportStatus::SLUG_RESOLVED, SupportStatus::SLUG_CLOSED, SupportStatus::SLUG_REOPENED];

        return collect(range(0, 17))->map(function (int $index) use ($batch, $users, $customers, $contacts, $types, $categories, $channels, $priorities, $statuses, $typeSlugs, $categorySlugs, $channelSlugs, $prioritySlugs, $statusSlugs): SupportTicket {
            $status = $statuses[$statusSlugs[$index % count($statusSlugs)]];
            $responsible = $users[$index % $users->count()];
            $ticket = SupportTicket::query()->create([
                'code' => $this->code('DEMO-ST', $index + 1),
                'title' => 'Caso soporte demo '.($index + 1),
                'customer_id' => $customers[$index % $customers->count()]->id,
                'requester_contact_id' => $contacts[$index % $contacts->count()]->id,
                'type_id' => $types[$typeSlugs[$index % count($typeSlugs)]]->id,
                'category_id' => $categories[$categorySlugs[$index % count($categorySlugs)]]->id,
                'channel_id' => $channels[$channelSlugs[$index % count($channelSlugs)]]->id,
                'priority_id' => $priorities[$prioritySlugs[$index % count($prioritySlugs)]]->id,
                'status_id' => $status->id,
                'responsible_id' => $responsible->id,
                'description' => 'Caso ficticio para demostrar seguimiento de soporte. No contactar al cliente.',
                'impact' => ['Operación diaria', 'Consulta funcional', 'Configuración comercial', 'Incidencia controlada'][$index % 4],
                'general_observations' => 'Registro demo sin acciones externas.',
                'assigned_at' => now()->subDays($index % 10),
                'first_responded_at' => $index % 3 === 0 ? now()->subDays($index % 8) : null,
                'work_started_at' => $index % 4 === 0 ? now()->subDays($index % 6) : null,
                'resolved_at' => in_array($status->slug, [SupportStatus::SLUG_RESOLVED, SupportStatus::SLUG_CLOSED], true) ? now()->subDays($index % 4) : null,
                'closed_at' => $status->slug === SupportStatus::SLUG_CLOSED ? now()->subDays($index % 3) : null,
                'solution_summary' => in_array($status->slug, [SupportStatus::SLUG_RESOLVED, SupportStatus::SLUG_CLOSED], true) ? 'Solución ficticia documentada para la demo.' : null,
                'created_by' => $responsible->id,
                'updated_by' => $responsible->id,
            ]);
            $this->register($batch, 'support', $ticket);

            $assignment = SupportAssignment::query()->create([
                'ticket_id' => $ticket->id,
                'new_responsible_id' => $responsible->id,
                'reason' => 'Asignación demo para balancear carga de soporte.',
                'assigned_by' => $responsible->id,
                'assigned_at' => $ticket->assigned_at ?? now(),
            ]);
            $this->register($batch, 'support', $assignment);

            $update = SupportTicketUpdate::query()->create([
                'ticket_id' => $ticket->id,
                'type' => $index % 3 === 0 ? SupportTicketUpdate::TYPE_INTERNAL_NOTE : SupportTicketUpdate::TYPE_CASE_UPDATE,
                'body' => 'Actualización ficticia para evidenciar trazabilidad del caso demo.',
                'is_internal' => $index % 3 === 0,
                'is_customer_response' => false,
                'created_by' => $responsible->id,
            ]);
            $this->register($batch, 'support', $update);

            if ($index < 6) {
                $incident = SupportIncidentDetail::query()->create([
                    'ticket_id' => $ticket->id,
                    'system' => 'CRM Maia Demo',
                    'module' => ['Prospectos', 'Cotizaciones', 'Dashboard'][$index % 3],
                    'environment' => 'Demostración',
                    'severity' => ['baja', 'media', 'alta'][$index % 3],
                    'diagnosis' => 'Diagnóstico ficticio para presentación.',
                    'technical_solution' => 'Solución técnica ficticia sin impacto real.',
                ]);
                $this->register($batch, 'support', $incident);
            }

            if ($index < 8) {
                $observation = SupportObservation::query()->create([
                    'ticket_id' => $ticket->id,
                    'title' => 'Observación demo '.($index + 1),
                    'description' => 'Observación ficticia asociada al caso demo.',
                    'state' => ['pending', 'in_process', 'lifted', 'validated'][$index % 4],
                    'priority' => ['baja', 'media', 'alta'][$index % 3],
                    'responsible_id' => $responsible->id,
                    'raised_at' => now()->subDays($index + 1),
                    'due_at' => now()->addDays($index + 2),
                    'created_by' => $responsible->id,
                ]);
                $this->register($batch, 'support', $observation);
            }

            return $ticket;
        });
    }

    private function register(DemoDataBatch $batch, string $module, Model $model): void
    {
        DemoDataRecord::query()->create([
            'batch_id' => $batch->id,
            'module' => $module,
            'table_name' => $model->getTable(),
            'model_type' => $model::class,
            'record_id' => (int) $model->getKey(),
            'created_by' => $batch->created_by,
        ]);
    }

    private function code(string $prefix, int $index): string
    {
        return $prefix.'-'.now()->format('His').str_pad((string) $index, 2, '0', STR_PAD_LEFT).Str::upper(Str::random(2));
    }
}
