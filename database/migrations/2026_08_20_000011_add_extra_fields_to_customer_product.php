<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds per-association metadata to the customer_product pivot so the
 * card can carry more than just "is attached": how many, at what price
 * (override of the catalog price), since when, until when, and notes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_product', function (Blueprint $table): void {
            $table->unsignedInteger('quantity')->default(1)->after('notes');
            $table->decimal('price_override', 14, 2)->nullable()->after('quantity');
            $table->date('purchased_at')->nullable()->after('price_override');
            $table->date('expires_at')->nullable()->after('purchased_at');
        });
    }

    public function down(): void
    {
        Schema::table('customer_product', function (Blueprint $table): void {
            $table->dropColumn(['quantity', 'price_override', 'purchased_at', 'expires_at']);
        });
    }
};