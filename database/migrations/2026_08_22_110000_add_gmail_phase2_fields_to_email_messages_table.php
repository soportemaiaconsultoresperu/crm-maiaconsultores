<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_messages', function (Blueprint $table): void {
            $table->string('status', 32)->default('queued')->change();
            $table->char('idempotency_key', 64)->nullable()->after('provider_message_id');
            $table->unique('idempotency_key', 'uq_email_messages_idempotency_key');
        });
    }

    public function down(): void
    {
        Schema::table('email_messages', function (Blueprint $table): void {
            $table->dropUnique('uq_email_messages_idempotency_key');
            $table->dropColumn('idempotency_key');
            $table->string('status', 16)->default('queued')->change();
        });
    }
};
