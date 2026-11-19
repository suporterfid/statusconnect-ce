<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * NFS-e (#216). Asaas issues the nota fiscal of each confirmed payment from the
 * subscription's invoice settings; invoice_settings_at records when those
 * settings were applied (null = still to apply, retried by the minute cron).
 * billing_invoices mirrors what the INVOICE_* webhooks report.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_subscriptions', function (Blueprint $table) {
            $table->timestamp('invoice_settings_at')->nullable();
        });

        Schema::create('billing_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('billing_subscription_id')->nullable()->constrained()->nullOnDelete();
            $table->string('asaas_invoice_id', 64)->unique();
            $table->string('asaas_payment_id', 64)->nullable();
            // SCHEDULED | SYNCHRONIZED | AUTHORIZED | PROCESSING_CANCELLATION | CANCELED | CANCELLATION_DENIED | ERROR
            $table->string('status', 32);
            $table->text('status_description')->nullable();
            $table->string('number', 64)->nullable();
            $table->string('validation_code', 128)->nullable();
            $table->string('pdf_url', 1024)->nullable();
            $table->string('xml_url', 1024)->nullable();
            $table->unsignedInteger('value_cents')->default(0);
            $table->date('effective_date')->nullable();
            $table->timestamp('error_notified_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'effective_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_invoices');
        Schema::table('billing_subscriptions', function (Blueprint $table) {
            $table->dropColumn('invoice_settings_at');
        });
    }
};
