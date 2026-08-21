<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * B11 — oauth_states.
 *
 * Short-lived state tokens used during the OAuth 2.0 authorization-code
 * round trip. We persist them (instead of Cache::put) so that we have a
 * durable audit trail of in-flight authorizations and a clean UNIQUE
 * constraint on the state value.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('oauth_states', function (Blueprint $table): void {
            $table->id();
            $table->string('provider', 40);
            $table->string('state', 64)->unique();
            $table->text('redirect_after')->nullable();
            $table->json('payload_json')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['provider', 'expires_at'], 'idx_oauth_states_provider_expires');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('oauth_states');
    }
};