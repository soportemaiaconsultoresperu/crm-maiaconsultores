<?php

namespace Tests\Feature;

use App\Services\CodeGeneratorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CodeGeneratorTest extends TestCase
{
    use RefreshDatabase;

    private CodeGeneratorService $generator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->generator = app(CodeGeneratorService::class);
    }

    public function test_lead_codes_are_sequential_per_year(): void
    {
        $year = now()->format('Y');

        $this->assertSame("LEAD-{$year}-00001", $this->generator->next('lead'));
        $this->assertSame("LEAD-{$year}-00002", $this->generator->next('lead'));
    }

    public function test_entities_keep_independent_sequences(): void
    {
        $year = now()->format('Y');

        $this->assertSame("LEAD-{$year}-00001", $this->generator->next('lead'));
        $this->assertSame("COT-{$year}-00001", $this->generator->next('quotation'));
        $this->assertSame("LEAD-{$year}-00002", $this->generator->next('lead'));
    }

    public function test_unknown_entity_throws_invalid_argument(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->generator->next('invoice');
    }
}
