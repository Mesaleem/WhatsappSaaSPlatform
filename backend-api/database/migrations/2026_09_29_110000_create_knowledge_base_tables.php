<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 8 Task 9 — Knowledge Base / Retrieval foundation. Three new tables;
 * nothing existing is altered (purely additive).
 *
 *   knowledge_bases      one named collection of documents, per account
 *   knowledge_documents  one source item (plain text today) of a knowledge
 *                        base, with its processing lifecycle
 *   knowledge_chunks     the document split into retrievable passages, each
 *                        with its embedding (development vector store)
 *
 * TENANT SAFETY IS A SCHEMA PROPERTY (the CRM pattern, PROJECT_STATE §7):
 * every row carries account_id and every parent link is a COMPOSITE foreign
 * key that includes it —
 *   knowledge_documents (knowledge_base_id, account_id)
 *       → knowledge_bases (id, account_id)
 *   knowledge_chunks (knowledge_document_id, knowledge_base_id, account_id)
 *       → knowledge_documents (id, knowledge_base_id, account_id)
 * so a document cannot sit in another account's knowledge base and a chunk
 * cannot point at another account's (or another knowledge base's) document.
 * CASCADE everywhere (composite FK + CASCADE is the proven MariaDB 10.11
 * combination; `DELETE FROM accounts` keeps working). Declared inside CREATE
 * TABLE, so SQLite enforces them too.
 *
 * VERSIONING / RE-PROCESSING. knowledge_documents.version increments when a
 * document's content changes or it is re-processed; chunks carry the
 * document_version they were built from, and indexed_version names the ONE
 * version retrieval uses. A new version is built beside the live one and
 * switched in a single transaction (older chunks deleted then), so a failed
 * re-index never damages what is already searchable.
 *
 * DETERMINISM. (knowledge_base_id, source_key) identifies a document
 * (caller-supplied or "sha256:<content hash>"); (knowledge_document_id,
 * document_version, chunk_index) identifies a chunk; content_hash on both
 * lets identical input be recognised.
 *
 * No credential is stored. Document text is stored because re-indexing
 * needs it; it is never logged. `embedding` holds a packed float32,
 * unit-length vector (App\Services\Knowledge\DatabaseVectorStore); a real
 * vector backend can replace that column without touching the rest.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('knowledge_bases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained('accounts')->cascadeOnDelete();
            $table->string('name', 120);
            $table->text('description')->nullable();
            // Locked by the first indexed document: every vector of one
            // knowledge base must come from the same embedding space.
            $table->string('embedding_provider', 32)->nullable();
            $table->string('embedding_model', 128)->nullable();
            $table->unsignedInteger('embedding_dimensions')->nullable();
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->timestamps();

            $table->unique(['id', 'account_id'], 'knowledge_bases_id_account_unique');
            $table->unique(['account_id', 'name'], 'knowledge_bases_account_name_unique');
        });

        Schema::create('knowledge_documents', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('account_id');
            $table->unsignedBigInteger('knowledge_base_id');
            $table->string('title', 191);
            $table->string('source_type', 16)->default('text');
            $table->string('source_key', 191);
            $table->longText('content');
            $table->char('content_hash', 64);
            $table->unsignedInteger('content_chars')->default(0);
            $table->unsignedInteger('version')->default(1);
            $table->unsignedInteger('indexed_version')->nullable();
            $table->string('status', 16);
            $table->unsignedInteger('chunk_count')->default(0);
            $table->string('error_code', 64)->nullable();
            $table->string('error_message', 255)->nullable();
            $table->timestamp('processing_started_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->timestamps();

            $table->unique(['id', 'account_id'], 'knowledge_documents_id_account_unique');
            $table->unique(['id', 'knowledge_base_id', 'account_id'], 'knowledge_documents_id_kb_account_unique');
            $table->unique(['knowledge_base_id', 'source_key'], 'knowledge_documents_kb_source_unique');
            $table->index(['knowledge_base_id', 'account_id'], 'knowledge_documents_kb_account_index');
            $table->index(['account_id', 'status'], 'knowledge_documents_account_status_index');
            $table->index(['status', 'processing_started_at'], 'knowledge_documents_status_started_index');

            $table->foreign(['knowledge_base_id', 'account_id'], 'knowledge_documents_kb_account_foreign')
                ->references(['id', 'account_id'])
                ->on('knowledge_bases')
                ->cascadeOnDelete();
        });

        Schema::create('knowledge_chunks', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('account_id');
            $table->unsignedBigInteger('knowledge_base_id');
            $table->unsignedBigInteger('knowledge_document_id');
            $table->unsignedInteger('document_version');
            $table->unsignedInteger('chunk_index');
            $table->text('content');
            $table->char('content_hash', 64);
            $table->unsignedInteger('char_start');
            $table->unsignedInteger('char_end');
            $table->binary('embedding')->nullable();
            $table->string('embedding_provider', 32)->nullable();
            $table->string('embedding_model', 128)->nullable();
            $table->unsignedInteger('embedding_dimensions')->nullable();
            $table->timestamp('embedded_at')->nullable();
            $table->timestamps();

            $table->unique(['knowledge_document_id', 'document_version', 'chunk_index'], 'knowledge_chunks_doc_version_index_unique');
            $table->index(['knowledge_document_id', 'knowledge_base_id', 'account_id'], 'knowledge_chunks_doc_kb_account_index');
            $table->index(['account_id', 'knowledge_base_id', 'document_version'], 'knowledge_chunks_account_kb_version_index');

            $table->foreign(['knowledge_document_id', 'knowledge_base_id', 'account_id'], 'knowledge_chunks_doc_kb_account_foreign')
                ->references(['id', 'knowledge_base_id', 'account_id'])
                ->on('knowledge_documents')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('knowledge_chunks');
        Schema::dropIfExists('knowledge_documents');
        Schema::dropIfExists('knowledge_bases');
    }
};
