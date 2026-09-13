<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('outbound_deliveries', function (Blueprint $table): void {
            $table->foreignId('email_message_id')
                ->nullable()
                ->after('account_id')
                ->constrained('email_messages')
                ->nullOnDelete();
            $table->unique('email_message_id', 'outbound_deliveries_email_message_id_unique');
        });
    }

    public function down(): void
    {
        Schema::table('outbound_deliveries', function (Blueprint $table): void {
            $table->dropUnique('outbound_deliveries_email_message_id_unique');
            $table->dropConstrainedForeignId('email_message_id');
        });
    }
};
