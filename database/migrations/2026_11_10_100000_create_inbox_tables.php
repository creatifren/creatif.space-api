<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Inbox: DMs (Instagram only, via Outstand) and comments on the posts
 * of a connected account. Rows belong to the account's user, the same
 * tenancy as social_accounts; provider ids are unique per relay so a
 * webhook and a backfill of the same thing meet on one row.
 *
 * Every index and foreign key is named explicitly. Laravel's generated
 * names (table_columns_type) passed 64 characters on social_messages and
 * MySQL refused the migration (1059); short names keep every identifier
 * well under MySQL's 64. Indexed strings stay within InnoDB's 3072-byte
 * key limit under utf8mb4 (4 bytes a character); the widest key here is
 * 8 + 191×4 = 772 bytes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_conversations', function (Blueprint $table) {
            $table->id();
            $table->ulid()->unique('soc_conv_ulid_uq');
            $table->foreignId('user_id')->constrained(indexName: 'soc_conv_user_fk')->cascadeOnDelete();
            $table->foreignId('social_account_id')->constrained(indexName: 'soc_conv_account_fk')->cascadeOnDelete();
            $table->string('provider', 20);
            $table->string('provider_conversation_id', 128);
            $table->string('network', 20);
            $table->string('contact_id', 128)->nullable();
            $table->string('contact_username')->nullable();
            $table->string('contact_name')->nullable();
            $table->text('contact_avatar_url')->nullable();
            $table->string('last_message_preview', 280)->nullable();
            $table->timestamp('last_message_at')->nullable();
            // The 24h rule: a free-form reply is allowed until this + 24h.
            $table->timestamp('last_inbound_at')->nullable();
            $table->unsignedInteger('unread_count')->default(0);
            $table->string('status', 10)->default('open');
            $table->timestamp('messages_synced_at')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'provider_conversation_id'], 'soc_conv_provider_uq');
            $table->index(['user_id', 'status', 'last_message_at'], 'soc_conv_user_status_idx');
        });

        Schema::create('social_messages', function (Blueprint $table) {
            $table->id();
            $table->ulid()->unique('soc_msg_ulid_uq');
            $table->foreignId('social_conversation_id')->constrained(indexName: 'soc_msg_conv_fk')->cascadeOnDelete();
            $table->string('provider_message_id', 128)->nullable();
            $table->string('platform_message_id', 191)->nullable();
            $table->string('direction', 3);
            $table->text('content')->nullable();
            $table->json('media_urls')->nullable();
            $table->string('status', 10)->default('sent');
            $table->text('error')->nullable();
            // api (sent through us), contact, native_app (sent in Instagram itself).
            $table->string('source', 16)->nullable();
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->unique(['social_conversation_id', 'provider_message_id'], 'soc_msg_conv_provider_uq');
            $table->index(['social_conversation_id', 'sent_at'], 'soc_msg_conv_sent_idx');
        });

        Schema::create('social_comments', function (Blueprint $table) {
            $table->id();
            $table->ulid()->unique('soc_cmt_ulid_uq');
            $table->foreignId('user_id')->constrained(indexName: 'soc_cmt_user_fk')->cascadeOnDelete();
            $table->foreignId('social_account_id')->constrained(indexName: 'soc_cmt_account_fk')->cascadeOnDelete();
            $table->foreignId('social_post_id')->nullable()->constrained(indexName: 'soc_cmt_post_fk')->nullOnDelete();
            $table->string('provider', 20);
            $table->string('provider_post_id', 128)->nullable();
            $table->string('platform_post_id', 191)->nullable();
            $table->string('comment_id', 191);
            $table->string('parent_comment_id', 191)->nullable();
            $table->string('author_id', 191)->nullable();
            $table->string('author_username')->nullable();
            $table->text('text')->nullable();
            $table->string('network', 20);
            $table->timestamp('commented_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('replied_at')->nullable();
            $table->timestamp('private_replied_at')->nullable();
            $table->foreignId('private_reply_conversation_id')->nullable()
                ->constrained('social_conversations', indexName: 'soc_cmt_private_conv_fk')->nullOnDelete();
            $table->timestamps();

            $table->unique(['social_account_id', 'comment_id'], 'soc_cmt_account_comment_uq');
            $table->index(['user_id', 'replied_at'], 'soc_cmt_user_replied_idx');
        });

        Schema::table('social_accounts', function (Blueprint $table) {
            // Set once the account was granted instagram_business_manage_messages.
            // Null on accounts connected before the Inbox: they reconnect.
            $table->timestamp('messages_scope_at')->nullable()->after('connected_at');
            // First Inbox backfill; the hourly sync only covers accounts with one.
            $table->timestamp('inbox_synced_at')->nullable()->after('messages_scope_at');
        });
    }

    public function down(): void
    {
        Schema::table('social_accounts', function (Blueprint $table) {
            $table->dropColumn(['messages_scope_at', 'inbox_synced_at']);
        });
        Schema::dropIfExists('social_comments');
        Schema::dropIfExists('social_messages');
        Schema::dropIfExists('social_conversations');
    }
};
