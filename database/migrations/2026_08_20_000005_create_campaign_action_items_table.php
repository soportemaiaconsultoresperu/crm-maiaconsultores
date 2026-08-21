<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaign_action_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('run_id')->constrained('campaign_runs')->cascadeOnDelete();
            $table->foreignId('step_id')->constrained('campaign_steps')->cascadeOnDelete();
            $table->foreignId('participant_id')->constrained('campaign_participants')->cascadeOnDelete();

            // Estado y fechas propios del módulo Campañas (independiente del
            // módulo Activity). La columna status usa un enum local al módulo;
            // la columna scheduled_at registra cuándo debería ejecutarse este
            // touchpoint específico.
            $table->enum('status', [
                'pending', 'in_process', 'completed', 'overdue', 'cancelled', 'not_applicable',
            ])->default('pending');
            $table->dateTime('scheduled_at');
            $table->dateTime('executed_at')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users');

            // Metadata propia de campaña (no existe en Activity).
            $table->text('result')->nullable();
            $table->text('contact_response')->nullable();
            $table->text('observations')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->text('not_applicable_reason')->nullable();
            $table->dateTime('next_action_at')->nullable();
            $table->text('next_action_notes')->nullable();
            $table->unsignedInteger('reschedule_count')->default(0);
            $table->dateTime('last_rescheduled_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['step_id', 'participant_id']);
            $table->index('run_id');
            $table->index(['status', 'scheduled_at']);
        });

        // Cross-driver CHECK: at most one of the two reasons is set. SQLite
        // (used in tests) does not support ALTER TABLE...ADD CONSTRAINT, so
        // the constraint is applied only on drivers that support it.
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement("
                ALTER TABLE campaign_action_items
                ADD CONSTRAINT chk_cancellation_mutually_exclusive CHECK (
                    (cancellation_reason IS NULL AND not_applicable_reason IS NOT NULL) OR
                    (cancellation_reason IS NOT NULL AND not_applicable_reason IS NULL) OR
                    (cancellation_reason IS NULL AND not_applicable_reason IS NULL)
                )
            ");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE campaign_action_items DROP CONSTRAINT IF EXISTS chk_cancellation_mutually_exclusive");
        }
        Schema::dropIfExists('campaign_action_items');
    }
};
