<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Billing through Asaas (#121). No card data and no CPF/CNPJ are stored here:
 * the customer document lives in Asaas, referenced by customer_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('provider', 32);
            $table->string('customer_id', 64)->nullable();
            $table->string('subscription_id', 64)->nullable()->index();
            $table->string('plan', 32);
            // pending (awaiting the first payment) | active | overdue | canceled | ended
            $table->string('status', 16);
            $table->timestamp('paid_until')->nullable();
            $table->timestamp('overdue_since')->nullable();
            $table->string('invoice_url', 512)->nullable();
            $table->timestamps();
        });

        Schema::create('billing_events', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 32);
            $table->string('event_id', 128);
            $table->string('event', 64);
            $table->longText('payload');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('processed_at')->nullable();
            $table->string('outcome', 64)->nullable();
            $table->text('error')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'event_id']);
            $table->index('processed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_events');
        Schema::dropIfExists('billing_subscriptions');
    }
};
