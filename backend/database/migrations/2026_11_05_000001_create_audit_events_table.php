<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_events', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('event_uuid', 64)->unique();
            $table->unsignedSmallInteger('schema_version')->default(1);
            $table->dateTime('occurred_at');
            $table->string('actor_type', 16);
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('actor_label', 191)->nullable();
            $table->json('actor_roles')->nullable();
            $table->string('action', 64);
            $table->string('entity_type', 32)->nullable();
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->unsignedBigInteger('banca_id')->nullable();
            $table->unsignedBigInteger('grupo_id')->nullable();
            $table->unsignedBigInteger('agencia_id')->nullable();
            $table->unsignedBigInteger('taquilla_id')->nullable();
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->json('context')->nullable();
            $table->string('reason_code', 64)->nullable();
            $table->string('reason_text', 500)->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->string('request_id', 64)->nullable();
            $table->string('job_id', 191)->nullable();
            $table->char('hash', 64)->nullable();
            $table->char('prev_hash', 64)->nullable();

            $table->index('occurred_at');
            $table->index(['action', 'occurred_at']);
            $table->index(['actor_id', 'occurred_at']);
            $table->index(['entity_type', 'entity_id', 'occurred_at']);
            $table->index(['banca_id', 'occurred_at']);
            $table->index('request_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_events');
    }
};
