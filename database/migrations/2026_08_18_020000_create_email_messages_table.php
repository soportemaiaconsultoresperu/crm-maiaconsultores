<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * B13 — email_messages.
 *
 * One row per email message (outbound or inbound). Identity is the
 * pair (account_id, provider_message_id) — when the same provider reports
 * the same external id twice we surface the unique constraint explicitly.
 *
 * Per docs/v2/01-roadmap.md §2.3. Status values are constrained at the
 * application layer by the App\Models\Email\EmailMessage::STATUS_* constants
 * (no new MySQL ENUMs per C-03).
 *
 * Related foreign keys (lead, customer, opportunity, quotation, contact) are
 * nullable and added conditionally so the migration is safe whether the
 * upstream tables exist or not.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_messages', function (Blueprint $table): void {
            $table->id();

            // Vendor account that originated or received the message.
            $table->unsignedBigInteger('account_id')->nullable();

            $table->string('direction', 8); // outbound|inbound
            $table->string('provider_message_id', 191);
            $table->string('thread_id', 191)->nullable();
            $table->string('in_reply_to', 191)->nullable();

            $table->string('from_email', 191);
            $table->string('from_name', 191)->nullable();

            $table->string('subject', 191)->nullable();
            $table->longText('body_html')->nullable();
            $table->longText('body_text')->nullable();

            $table->string('status', 16)->default('queued');
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('received_at')->nullable();

            $table->string('error_class', 191)->nullable();
            $table->text('error_message')->nullable();

            // Optional relations to the rest of the CRM.
            $table->unsignedBigInteger('related_lead_id')->nullable();
            $table->unsignedBigInteger('related_customer_id')->nullable();
            $table->unsignedBigInteger('related_opportunity_id')->nullable();
            $table->unsignedBigInteger('related_quotation_id')->nullable();
            $table->unsignedBigInteger('related_contact_id')->nullable();

            $table->unsignedBigInteger('created_by')->nullable();

            $table->timestamps();

            $table->unique(['account_id', 'provider_message_id'], 'uq_email_messages_account_provider');
            $table->index('direction', 'idx_email_messages_direction');
            $table->index('status', 'idx_email_messages_status');
            $table->index('thread_id', 'idx_email_messages_thread');
            $table->index('from_email', 'idx_email_messages_from_email');
            $table->index('related_lead_id', 'idx_email_messages_lead');
            $table->index('related_customer_id', 'idx_email_messages_customer');
            $table->index('related_opportunity_id', 'idx_email_messages_opportunity');
            $table->index('related_quotation_id', 'idx_email_messages_quotation');
            $table->index('related_contact_id', 'idx_email_messages_contact');
        });

        // FKs are added conditionally to stay safe across migration orderings.
        if (Schema::hasTable('integration_accounts')) {
            Schema::table('email_messages', function (Blueprint $table): void {
                $table->foreign('account_id', 'fk_email_messages_account')
                    ->references('id')->on('integration_accounts')
                    ->nullOnDelete();
            });
        }

        if (Schema::hasTable('leads')) {
            Schema::table('email_messages', function (Blueprint $table): void {
                $table->foreign('related_lead_id', 'fk_email_messages_lead')
                    ->references('id')->on('leads')
                    ->nullOnDelete();
            });
        }

        if (Schema::hasTable('customers')) {
            Schema::table('email_messages', function (Blueprint $table): void {
                $table->foreign('related_customer_id', 'fk_email_messages_customer')
                    ->references('id')->on('customers')
                    ->nullOnDelete();
            });
        }

        if (Schema::hasTable('opportunities')) {
            Schema::table('email_messages', function (Blueprint $table): void {
                $table->foreign('related_opportunity_id', 'fk_email_messages_opportunity')
                    ->references('id')->on('opportunities')
                    ->nullOnDelete();
            });
        }

        if (Schema::hasTable('quotations')) {
            Schema::table('email_messages', function (Blueprint $table): void {
                $table->foreign('related_quotation_id', 'fk_email_messages_quotation')
                    ->references('id')->on('quotations')
                    ->nullOnDelete();
            });
        }

        if (Schema::hasTable('contacts')) {
            Schema::table('email_messages', function (Blueprint $table): void {
                $table->foreign('related_contact_id', 'fk_email_messages_contact')
                    ->references('id')->on('contacts')
                    ->nullOnDelete();
            });
        }

        if (Schema::hasTable('users')) {
            Schema::table('email_messages', function (Blueprint $table): void {
                $table->foreign('created_by', 'fk_email_messages_created_by')
                    ->references('id')->on('users')
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('email_messages')) {
            Schema::table('email_messages', function (Blueprint $table): void {
                $table->dropForeign('fk_email_messages_account');
                $table->dropForeign('fk_email_messages_lead');
                $table->dropForeign('fk_email_messages_customer');
                $table->dropForeign('fk_email_messages_opportunity');
                $table->dropForeign('fk_email_messages_quotation');
                $table->dropForeign('fk_email_messages_contact');
                $table->dropForeign('fk_email_messages_created_by');
            });
        }

        Schema::dropIfExists('email_messages');
    }
};
