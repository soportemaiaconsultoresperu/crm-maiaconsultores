<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Closes two asymmetries between the `customers` and `leads` modules:
 *
 *   1) customers now carries the HUMAN contact that the customer deals with:
 *      first_name, last_name, position. Until now a juridica customer only
 *      had `legal_name`; we had no place to record who the actual contact
 *      person is. All three are nullable because natural-person customers
 *      will not always have a separate "position" and juridica customers
 *      will not always provide a contact name (the form is generous).
 *
 *   2) customers now has a separate commercial `address` in addition to the
 *      `fiscal_address` it already has. They are distinct concepts: the
 *      fiscal address is what appears on invoices / SUNAT; the commercial
 *      address is where the customer actually operates. Both nullable.
 *
 * Down() drops every column this migration adds.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->string('first_name', 100)->nullable()->after('person_type');
            $table->string('last_name', 100)->nullable()->after('first_name');
            $table->string('position', 100)->nullable()->after('trade_name');
            $table->string('address', 255)->nullable()->after('website');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->dropColumn(['first_name', 'last_name', 'position', 'address']);
        });
    }
};