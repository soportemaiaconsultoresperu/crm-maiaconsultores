<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * B14 — whatsapp_templates.
 *
 * Mirror of the Meta Cloud API template catalogue for a given WhatsApp
 * account. Status values (draft|pending|approved|rejected|disabled) are
 * constrained at the application layer by
 * {@see App\Models\WhatsApp\WhatsAppTemplate::STATUS_*} constants — no
 * new MySQL ENUMs per C-03. `variables_json` carries the parsed variable
 * list so the renderer (B14 Pasada B) can interpolate without re-parsing
 * the body on every send.
 *
 * Per docs/v2/01-roadmap.md §2.4. Per decision 15c only `approved`
 * templates are eligible for sending — enforced at the application layer.
 * The `(account_id, name, language)` triplet is the natural identity
 * exposed by Meta and is the UNIQUE key here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_templates', function (Blueprint $table): void {
            $table->id();

            $table->unsignedBigInteger('account_id');
            $table->string('name', 80);
            $table->string('language', 16);
            $table->string('status', 16)->default('draft');
            $table->string('category', 40)->nullable();

            $table->text('body')->nullable();
            $table->string('header_kind', 20)->nullable();
            $table->text('header_text')->nullable();
            $table->text('footer_text')->nullable();

            $table->json('variables_json')->nullable();

            $table->timestamp('approved_at')->nullable();
            $table->text('rejected_reason')->nullable();
            $table->timestamp('synced_at')->nullable();

            $table->timestamps();

            $table->unique(
                ['account_id', 'name', 'language'],
                'uq_whatsapp_templates_account_name_lang',
            );
            $table->index('status', 'idx_whatsapp_templates_status');
            $table->index('category', 'idx_whatsapp_templates_category');
        });

        if (Schema::hasTable('whatsapp_accounts')) {
            Schema::table('whatsapp_templates', function (Blueprint $table): void {
                $table->foreign('account_id', 'fk_whatsapp_templates_account')
                    ->references('id')->on('whatsapp_accounts')
                    ->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('whatsapp_templates')) {
            Schema::table('whatsapp_templates', function (Blueprint $table): void {
                $table->dropForeign('fk_whatsapp_templates_account');
            });
        }

        Schema::dropIfExists('whatsapp_templates');
    }
};
