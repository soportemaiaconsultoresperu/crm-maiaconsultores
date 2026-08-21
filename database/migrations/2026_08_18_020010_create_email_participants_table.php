<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * B13 — email_participants.
 *
 * One row per (message, kind, email) triplet. A single message can have
 * multiple participants (to/cc/bcc/from) and the same email can appear in
 * different roles across different messages.
 *
 * Per docs/v2/01-roadmap.md §2.3 `kind` is application-layer enforced
 * (to|cc|bcc|from) via the App\Models\Email\EmailParticipant::KIND_* constants.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_participants', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('message_id');
            $table->string('kind', 8); // to|cc|bcc|from
            $table->string('email', 191);
            $table->string('name', 191)->nullable();

            $table->index('message_id', 'idx_email_participants_message');
        });

        if (Schema::hasTable('email_messages')) {
            Schema::table('email_participants', function (Blueprint $table): void {
                $table->foreign('message_id', 'fk_email_participants_message')
                    ->references('id')->on('email_messages')
                    ->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('email_participants')) {
            Schema::table('email_participants', function (Blueprint $table): void {
                $table->dropForeign('fk_email_participants_message');
            });
        }

        Schema::dropIfExists('email_participants');
    }
};
