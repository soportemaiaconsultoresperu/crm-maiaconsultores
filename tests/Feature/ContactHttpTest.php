<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\Customer;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Facades\Excel;
use Tests\TestCase;

/**
 * Standalone contacts list (RF-CON follow-up) + Excel import/template:
 * contacts.index is scoped like the customers module (only contacts whose
 * customer's owner is inside the actor's data scope); the import mirrors the
 * leads import pattern (template download, valid rows created via
 * ContactService, duplicates skipped, unknown customers reported invalid).
 */
class ContactHttpTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $salespersonOne;

    private User $salespersonTwo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(SettingsSeeder::class);

        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole('admin');

        $this->salespersonOne = User::factory()->create(['is_active' => true]);
        $this->salespersonOne->assignRole('vendedor');

        $this->salespersonTwo = User::factory()->create(['is_active' => true]);
        $this->salespersonTwo->assignRole('vendedor');

        Storage::fake('local');
    }

    public function test_contacts_index_lists_only_contacts_of_visible_customers(): void
    {
        $visible = Customer::factory()->forOwner($this->salespersonOne)->create([
            'legal_name' => 'Corporación Andina S.A.C.',
        ]);
        Contact::factory()->forCustomer($visible)->create([
            'first_name' => 'Rosa',
            'last_name' => 'Quispe',
            'is_primary' => true,
        ]);

        $hidden = Customer::factory()->forOwner($this->salespersonTwo)->create([
            'legal_name' => 'Competencia Oculta S.A.C.',
        ]);
        Contact::factory()->forCustomer($hidden)->create([
            'first_name' => 'Secreto',
            'last_name' => 'Invisible',
        ]);

        $response = $this->actingAs($this->salespersonOne)
            ->get('/contacts');

        $response->assertOk();
        $response->assertSee('Rosa Quispe');
        $response->assertSee('Principal');
        $response->assertSee('Corporación Andina S.A.C.');
        $response->assertDontSee('Secreto Invisible');
        $response->assertDontSee('Competencia Oculta S.A.C.');
    }

    public function test_contacts_index_requires_a_view_permission(): void
    {
        $noRole = User::factory()->create(['is_active' => true]);

        $this->actingAs($noRole)
            ->get('/contacts')
            ->assertForbidden();
    }

        public function test_contacts_create_form_lists_only_visible_customers(): void
        {
            $visible = Customer::factory()->forOwner($this->salespersonOne)->create([
                'legal_name' => 'Corporación Andina S.A.C.',
            ]);
            Customer::factory()->forOwner($this->salespersonTwo)->create([
                'legal_name' => 'Competencia Oculta S.A.C.',
            ]);

            $response = $this->actingAs($this->salespersonOne)
                ->get(route('contacts.create'));

            $response->assertOk();
            $response->assertSee('Corporación Andina S.A.C.');
            $response->assertDontSee('Competencia Oculta S.A.C.');
        }

        public function test_standalone_store_creates_a_contact_and_redirects_to_index(): void
        {
            $customer = Customer::factory()->forOwner($this->salespersonOne)->create();

            $response = $this->actingAs($this->salespersonOne)
                ->post(route('contacts.store'), [
                    'customer_id' => $customer->id,
                    'first_name' => 'Rosa',
                    'last_name' => 'Quispe',
                    'position' => 'Gerente de Compras',
                    'area' => 'Compras',
                    'phone' => '+51 987 654 321',
                    'whatsapp' => '987654321',
                    'email' => 'rosa.quispe@andina.example.com',
                    'is_primary' => '1',
                    'observations' => 'Contacto clave de compras',
                ]);

            $response->assertRedirect(route('contacts.index'));
            $response->assertSessionHas('status');

            $contact = Contact::query()->where('email_norm', 'rosa.quispe@andina.example.com')->firstOrFail();
            $this->assertSame($customer->id, $contact->customer_id);
            $this->assertSame('Rosa', $contact->first_name);
            $this->assertTrue((bool) $contact->is_primary);
            $this->assertSame($this->salespersonOne->id, $contact->created_by);
        }

        public function test_standalone_store_rejects_a_non_visible_customer(): void
        {
            $hidden = Customer::factory()->forOwner($this->salespersonTwo)->create();

            $response = $this->actingAs($this->salespersonOne)
                ->post(route('contacts.store'), [
                    'customer_id' => $hidden->id,
                    'first_name' => 'Secreto',
                    'last_name' => 'Invisible',
                ]);

            $response->assertForbidden();
            $this->assertSame(0, Contact::query()->where('customer_id', $hidden->id)->count());
        }

        public function test_import_template_downloads_an_xlsx_file(): void
    {
        $response = $this->actingAs($this->admin)
            ->get(route('contacts.import.template'));

        $response->assertOk();
        $response->assertHeader(
            'content-type',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
        );
        $this->assertStringContainsString(
            'contactos-plantilla.xlsx',
            (string) $response->headers->get('content-disposition')
        );
    }

    public function test_import_creates_valid_rows_and_skips_duplicates(): void
    {
        $customer = Customer::factory()->forOwner($this->salespersonOne)->create([
            'doc_number' => '20512345678',
            'doc_number_norm' => '20512345678',
        ]);

        // Existing contact of that customer: same email as row 3 below.
        Contact::factory()->forCustomer($customer)->create([
            'first_name' => 'Juan',
            'last_name' => 'Pérez',
            'email' => 'juan.perez@andina.example.com',
            'email_norm' => 'juan.perez@andina.example.com',
        ]);

        $file = $this->storeImportFile([
            ['20512345678', 'María', 'Torres', 'Gerente de Compras', 'Compras', '+51 987 654 321', '987654321', 'maria.torres@andina.example.com', 'si', 'Contacto clave'],
            ['20512345678', 'Juan', 'Pérez', 'Compras', '', '', '', 'juan.perez@andina.example.com', 'no', ''],
            ['99999999999', 'Fantasma', 'SinCliente', '', '', '', '', 'fantasma@example.com', 'no', ''],
            ['20512345678', 'SinApellido', '', '', '', '', '', '', 'no', ''],
        ]);

        $response = $this->actingAs($this->admin)
            ->post(route('contacts.import.process'), ['file' => $file]);

        $response->assertOk();

        // Row 1 created via ContactService: audited + primary + email_norm.
        $created = Contact::query()
            ->where('email_norm', 'maria.torres@andina.example.com')
            ->firstOrFail();
        $this->assertSame('María', $created->first_name);
        $this->assertSame($customer->id, $created->customer_id);
        $this->assertTrue((bool) $created->is_primary);
        $this->assertSame($this->admin->id, $created->created_by);

        // Row 2 (duplicate email) was skipped, not duplicated.
        $this->assertSame(
            1,
            Contact::query()->where('email_norm', 'juan.perez@andina.example.com')->count()
        );

        // Rows 3 (unknown customer) and 4 (missing last_name) are reported.
        $response->assertSee('no encontrado');
        $response->assertSee('Omitido');
        $response->assertSee('Inválido');
    }

    public function test_import_form_and_process_require_contacts_create(): void
    {
        $noRole = User::factory()->create(['is_active' => true]);

        $this->actingAs($noRole)
            ->get(route('contacts.import'))
            ->assertForbidden();

        $this->actingAs($noRole)
            ->post(route('contacts.import.process'))
            ->assertForbidden();
    }

    /**
     * Store the given rows as a real .xlsx on the faked local disk and wrap
     * the resulting file as an uploadable UploadedFile.
     *
     * @param  list<list<string>>  $rows
     */
    private function storeImportFile(array $rows): UploadedFile
    {
        $path = 'imports/contacts-test.xlsx';

        Excel::store(
            new class($rows) implements FromArray, WithHeadings, ShouldAutoSize
            {
                public function __construct(private array $rows) {}

                public function array(): array
                {
                    return $this->rows;
                }

                public function headings(): array
                {
                    return [
                        'customer_doc_number', 'first_name', 'last_name', 'position',
                        'area', 'phone', 'whatsapp', 'email', 'is_primary', 'observations',
                    ];
                }
            },
            $path,
            'local',
        );

        return new UploadedFile(
            Storage::disk('local')->path($path),
            'contacts-test.xlsx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            null,
            true,
        );
    }
}
