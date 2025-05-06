<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('document_line_items', function (Blueprint $table) {
            $table->integer('line_number')->nullable()->after('documentable_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('document_line_items', function (Blueprint $table) {
            $table->dropColumn('line_number');
        });
    }
};
