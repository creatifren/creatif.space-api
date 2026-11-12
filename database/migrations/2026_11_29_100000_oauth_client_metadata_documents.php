<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * OAuth Client ID Metadata Documents (MCP auth spec): a client whose
 * client_id is an https URL. Each document URL maps to one Passport client
 * row, refreshed from the document when the cached copy expires. Additive.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('oauth_client_metadata_documents', function (Blueprint $table) {
            $table->id();
            // sha256 of the URL: unique without a long index.
            $table->char('url_hash', 64)->unique();
            $table->text('url');
            $table->uuid('client_id');
            $table->timestamp('fetched_at');
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->foreign('client_id')->references('id')->on('oauth_clients')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('oauth_client_metadata_documents');
    }
};
