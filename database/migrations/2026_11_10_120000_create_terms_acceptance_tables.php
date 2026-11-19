<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-user acceptance of the Terms of Use and Privacy Policy.
 *
 * legal_document_versions keeps the exact rendered text of every version that
 * was accepted. terms_acceptances keeps who accepted which version, when and
 * from where; the email is copied so the record survives the user's deletion.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legal_document_versions', function (Blueprint $table) {
            $table->id();
            $table->string('version', 64);
            $table->char('terms_hash', 64);
            $table->char('privacy_hash', 64);
            $table->longText('terms_html');
            $table->longText('privacy_html');
            $table->timestamp('created_at')->nullable();

            $table->unique(['version', 'terms_hash', 'privacy_hash']);
        });

        Schema::create('terms_acceptances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('email', 255);
            $table->foreignId('legal_document_version_id')->constrained('legal_document_versions');
            $table->string('version', 64);
            // signup | prompt
            $table->string('method', 16);
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('accepted_at');

            $table->index(['user_id', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('terms_acceptances');
        Schema::dropIfExists('legal_document_versions');
    }
};
