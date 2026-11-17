<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Append-only ledger. A user's balance is the sum of their rows.
        Schema::create('credit_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('generation_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('type'); // purchase | hold | release | charge
            $table->bigInteger('amount'); // signed nanodollars
            $table->string('description')->nullable();
            $table->timestampsTz();

            $table->index(['user_id', 'created_at']);
            // A generation is held, released and charged at most once each,
            // no matter how many times its webhook is delivered.
            $table->unique(['generation_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_transactions');
    }
};
