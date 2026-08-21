<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * B14 — whatsapp_accounts.
 *
 * One row per WhatsApp phone number registered in the Meta Cloud API.
 * Linked back to a vendor account row in `integration_accounts` so the
 * secrets (access token, business id) live alongside the rest of the
 * V2 integration accounts (decision 12b — contract swap-ready).
 *
 * Per docs/v2/01-roadmap.md §2.4. Status values are constrained at the
 * application layer by App\Models\WhatsApp\WhatsAppAccount constants (no
 * new MySQL ENUMs per C-03). The FK to `integration_accounts` is nullable
 * so historical message / conversation rows can survive a vendor
 * disconnect with their trace intact (nullOnDelete — same pattern as B13
 * `email_messages.account_id`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_accounts', function (Blueprint $table): void {
            $table->id();

            // Vendor account in the shared B11 integration_accounts table.
            $table->unsignedBigInteger('account_id')->nullable();

            $table->string('phone_number_id', 191)->nullable();
            $table->string('business_id', 191)->nullable();
            $table->string('phone_number', 191);
            $table->string('display_name', 191)->nullable();
            $table->string('status', 32)->nullable();

            $table->timestamp('verified_at')->nullable();
            $table->timestamp('last_event_at')->nullable();

            $table->timestamps();

            $table->index('account_id', 'idx_whatsapp_accounts_account');
            $table->index('phone_number', 'idx_whatsapp_accounts_phone');
        });

        if (Schema::hasTable('integration_accounts')) {
            Schema::table('whatsapp_accounts', function (Blueprint $table): void {
                $table->foreign('account_id', 'fk_whatsapp_accounts_account')
                    ->references('id')->on('integration_accounts')
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('whatsapp_accounts')) {
            Schema::table('whatsapp_accounts', function (Blueprint $table): void {
                $table->dropForeign('fk_whatsapp_accounts_account');
            });
        }

        Schema::dropIfExists('whatsapp_accounts');
    }
};
