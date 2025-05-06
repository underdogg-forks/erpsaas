<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasColumn('bills', 'discount_method')) {
            Schema::table('bills', function (Blueprint $table) {
                $table->string('discount_method')->default('per_document')->change();
            });
        }

        if (Schema::hasColumn('estimates', 'discount_method')) {
            Schema::table('estimates', function (Blueprint $table) {
                $table->string('discount_method')->default('per_document')->change();
            });
        }

        if (Schema::hasColumn('recurring_invoices', 'discount_method')) {
            Schema::table('recurring_invoices', function (Blueprint $table) {
                $table->string('discount_method')->default('per_document')->change();
            });
        }

        if (Schema::hasColumn('invoices', 'discount_method')) {
            Schema::table('invoices', function (Blueprint $table) {
                $table->string('discount_method')->default('per_document')->change();
            });
        }
    }
};
