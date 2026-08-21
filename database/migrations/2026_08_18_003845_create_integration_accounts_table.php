<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * B11 — integration_accounts.
 *
 * Stores one row per external integration account (SMTP, Gmail, Outlook,
 * WhatsApp, Google Calendar, Outlook Calendar). Credentials live in
 * `credentials_encrypted` (TEXT, encrypted at the model layer via the
 * `encrypted` cast — Laravel uses APP_KEY to encrypt). Non-secret
 * configuration lives in `config_json`.
 *
 * Per docs/v2/01-roadmap.md §2.2.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_accounts', function (Blueprint $table): void {
            $table->id();

            // Slug: gmail|outlook|smtp|whatsapp|google_calendar|outlook_calendar
            $table->string('provider', 40);
            $table->string('label', 191);

            // Owner / team columns always present so the FK can be added
            // unconditionally when users/teams exist (B11).
            $table->unsignedBigInteger('owner_id')->nullable();
            $table->unsignedBigInteger('team_id')->nullable();

            $table->boolean('is_shared')->default(false);
            $table->boolean('is_active')->default(true);
            $table->boolean('test_mode')->default(true);

            // TEXT encrypted via App\Integrations\Services\CredentialCipher
            // (or the model's `encrypted` cast). Stored as base64/JSON; never plaintext.
            $table->text('config_json')->nullable();
            $table->text('credentials_encrypted')->nullable();

            $table->json('scopes')->nullable();

            $table->timestamp('last_synced_at')->nullable();
            $table->timestamp('last_refresh_at')->nullable();
            $table->timestamp('expires_at')->nullable();

            $table->string('error_class', 191)->nullable();
            $table->text('error_message')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['provider', 'is_active'], 'idx_integration_accounts_provider_active');
            $table->index('owner_id', 'idx_integration_accounts_owner');
            $table->index('team_id', 'idx_integration_accounts_team');
        });

        // FK added conditionally so the migration works whether users/teams
        // tables exist or not (defensive: B11 may land before some upstream).
        if (Schema::hasTable('users')) {
            Schema::table('integration_accounts', function (Blueprint $table): void {
                $table->foreign('owner_id', 'fk_integration_accounts_owner')
                    ->references('id')->on('users')
                    ->nullOnDelete();
            });
        }

        if (Schema::hasTable('teams')) {
            Schema::table('integration_accounts', function (Blueprint $table): void {
                $table->foreign('team_id', 'fk_integration_accounts_team')
                    ->references('id')->on('teams')
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('integration_accounts')) {
            Schema::table('integration_accounts', function (Blueprint $table): void {
                $table->dropForeign('fk_integration_accounts_owner');
                $table->dropForeign('fk_integration_accounts_team');
            });
        }

        Schema::dropIfExists('integration_accounts');
    }
};