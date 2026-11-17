<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Dernière ouverture du préavis de coupure envoyé à un client qui a atteint le seuil de retard.
        Schema::table('customers', function (Blueprint $table) {
            $table->timestamp('cutoff_warning_opened_at')->nullable()->after('status_since');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn('cutoff_warning_opened_at');
        });
    }
};
