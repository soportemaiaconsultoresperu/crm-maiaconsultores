<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaign_steps', function (Blueprint $table) {
            $table->id();
            $table->boolean('is_template');
            $table->foreignId('template_id')->nullable()->constrained('campaign_templates');
            $table->foreignId('run_id')->nullable()->constrained('campaign_runs');
            $table->foreignId('source_step_id')->nullable()->constrained('campaign_steps');
            $table->unsignedInteger('order')->default(0);
            $table->foreignId('action_type_id')->constrained('activity_types');
            $table->string('title', 200);
            $table->unsignedInteger('day_offset')->default(0);
            $table->time('scheduled_time')->nullable();
            $table->text('instructions')->nullable();
            $table->boolean('is_required')->default(true);
            $table->boolean('is_advertising')->default(false);
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->foreign('created_by')->references('id')->on('users');
            $table->foreign('updated_by')->references('id')->on('users');
            $table->timestamps();

            $table->index(['template_id', 'order']);
            $table->index(['run_id', 'order']);
            $table->index('source_step_id');
        });

        // Cross-driver CHECK constraint: only add it on databases whose ALTER
        // TABLE supports it. SQLite (used in tests) does not — the integrity
        // check is enforced in the model (campaign_steps boot method) instead.
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement("
                ALTER TABLE campaign_steps
                ADD CONSTRAINT chk_campaign_steps_integrity CHECK (
                    (is_template = 1 AND template_id IS NOT NULL AND run_id IS NULL AND source_step_id IS NULL) OR
                    (is_template = 0 AND run_id IS NOT NULL AND template_id IS NULL)
                )
            ");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE campaign_steps DROP CONSTRAINT IF EXISTS chk_campaign_steps_integrity");
        }
        Schema::dropIfExists('campaign_steps');
    }
};
