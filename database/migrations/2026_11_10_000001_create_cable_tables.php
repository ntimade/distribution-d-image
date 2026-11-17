<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Un tarif partagé par plusieurs clients (bouquet, quartier, offre…).
        Schema::create('tariff_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->decimal('monthly_fee', 15, 3);
            $table->string('description')->nullable();
            $table->timestamps();
            $table->unique(['organisation_id', 'name']);
        });

        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('number'); // numéro client, propre à chaque compte
            $table->string('name');
            $table->string('gender', 1)->nullable();
            $table->string('phone', 50)->nullable();
            $table->string('email')->nullable();
            $table->string('neighbourhood', 100)->nullable();
            $table->string('address')->nullable();
            $table->string('landmark')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->unsignedTinyInteger('tv_count')->default(1);
            // Tarif personnel : prime sur celui du groupe. Null : le tarif du groupe s'applique.
            $table->foreignId('tariff_group_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('monthly_fee', 15, 3)->nullable();
            $table->date('start_date');
            $table->char('first_period', 7); // AAAA-MM : premier mois facturé
            $table->char('billing_from', 7); // AAAA-MM : la facturation reprend ici après un réabonnement
            $table->unsignedTinyInteger('due_day');
            $table->string('status', 20)->default('active'); // active, suspended, terminated
            $table->date('status_since')->nullable();
            $table->string('notes')->nullable();
            $table->timestamps();
            $table->unique(['organisation_id', 'number']);
            $table->index(['organisation_id', 'name']);
            $table->index(['organisation_id', 'status']);
        });

        // L'historique de l'abonnement : souscription, coupures, réabonnements, résiliation, changements de tarif.
        Schema::create('customer_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 30);
            $table->date('happened_on');
            $table->decimal('amount', 15, 3)->nullable();
            $table->string('note')->nullable();
            $table->timestamp('whatsapp_opened_at')->nullable();
            $table->timestamp('sms_opened_at')->nullable();
            $table->timestamps();
            $table->index(['customer_id', 'happened_on']);
        });

        // Une facture par client et par mois. Le montant est figé : changer un tarif ne réécrit pas le passé.
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->char('period', 7); // AAAA-MM
            $table->date('due_date');
            $table->decimal('amount', 15, 3);
            $table->decimal('reconnection_fee', 15, 3)->default(0); // compris dans le montant
            $table->decimal('paid_amount', 15, 3)->default(0);
            $table->string('notes')->nullable();
            $table->timestamp('whatsapp_opened_at')->nullable();
            $table->timestamp('sms_opened_at')->nullable();
            $table->timestamp('reminder_opened_at')->nullable();
            $table->timestamp('late_notice_opened_at')->nullable();
            $table->timestamps();
            $table->unique(['customer_id', 'period']);
            $table->index(['organisation_id', 'period']);
            $table->index(['organisation_id', 'due_date']);
        });

        // Un paiement appartient au client : il peut régler plusieurs mois, y compris à l'avance.
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('amount', 15, 3);
            $table->date('paid_at');
            $table->string('method', 50)->nullable();
            $table->string('reference', 100)->nullable();
            $table->timestamp('whatsapp_opened_at')->nullable();
            $table->timestamp('sms_opened_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['organisation_id', 'paid_at']);
        });

        // La part de chaque paiement sur chaque mois, recalculée à chaque changement.
        Schema::create('payment_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 15, 3);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_allocations');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('customer_events');
        Schema::dropIfExists('customers');
        Schema::dropIfExists('tariff_groups');
    }
};
