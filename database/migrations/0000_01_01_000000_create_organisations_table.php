<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organisations', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code', 20)->unique();
            $table->string('phone', 50)->nullable();
            $table->string('phone_country', 2)->nullable();
            $table->string('address')->nullable();
            $table->string('logo_path')->nullable();
            $table->char('currency', 3);
            // Nombre de jours avant l'échéance où le client apparaît dans la liste des rappels.
            $table->unsignedTinyInteger('reminder_days')->default(3);
            // Jour d'échéance proposé à chaque nouveau client (modifiable client par client).
            $table->unsignedTinyInteger('default_due_day')->default(5);
            // Nombre de mois échus impayés à partir duquel un client est proposé à la coupure.
            $table->unsignedTinyInteger('cutoff_after_months')->default(2);
            // Frais de réabonnement proposés après une coupure (0 : aucun).
            $table->decimal('reconnection_fee', 15, 3)->default(0);
            // Dernier jour où les factures mensuelles ont été générées pour ce compte.
            $table->date('invoices_synced_on')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organisations');
    }
};
