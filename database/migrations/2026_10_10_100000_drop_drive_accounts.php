<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Google Drive import is gone — storage is R2 only. The connected
     * accounts (and the OAuth tokens they held) go with it.
     *
     * Imported files stay: their bytes were copied into R2. They keep
     * `source = drive_import`, and `source_meta->drive_email` still says
     * which account brought them, so dropping the key loses no provenance.
     */
    public function up(): void
    {
        Schema::table('files', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'source_account_id']);
            $table->dropConstrainedForeignId('source_account_id');
        });

        Schema::dropIfExists('drive_accounts');
    }

    /**
     * Structure only. The tokens are not coming back, and neither is the
     * link from a file to its account.
     */
    public function down(): void
    {
        Schema::create('drive_accounts', function (Blueprint $table) {
            $table->id();
            $table->ulid()->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 20)->default('google');
            $table->string('provider_account_id', 64);
            $table->string('email');
            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();
            $table->timestamp('token_expires_at')->nullable();
            $table->string('status', 20)->default('connected');
            $table->timestamps();

            $table->unique(['user_id', 'provider', 'provider_account_id']);
        });

        Schema::table('files', function (Blueprint $table) {
            $table->foreignId('source_account_id')
                ->nullable()
                ->after('source')
                ->constrained('drive_accounts')
                ->nullOnDelete();
            $table->index(['user_id', 'source_account_id']);
        });
    }
};
