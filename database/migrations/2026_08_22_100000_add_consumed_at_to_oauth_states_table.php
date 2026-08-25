<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('oauth_states', function (Blueprint $table): void {
            $table->timestamp('consumed_at')->nullable()->after('expires_at');
            $table->index(['provider', 'consumed_at', 'expires_at'], 'idx_oauth_states_provider_consumed');
        });
    }

    public function down(): void
    {
        Schema::table('oauth_states', function (Blueprint $table): void {
            $table->dropIndex('idx_oauth_states_provider_consumed');
            $table->dropColumn('consumed_at');
        });
    }
};
