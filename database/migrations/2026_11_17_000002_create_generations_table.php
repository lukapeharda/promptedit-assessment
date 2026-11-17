<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('generations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // Sent on every submit attempt, so retries can never create (or bill) a second generation.
            $table->uuid('idempotency_key')->unique();
            // Null until Pixelforge accepts the request.
            $table->string('pixelforge_id')->nullable()->unique();

            $table->text('prompt');
            $table->string('size');
            $table->string('quality');
            $table->jsonb('reference_images');
            $table->string('status')->index();
            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();
            $table->unsignedSmallInteger('submit_attempts')->default(0);

            // Money: integer nanodollars. Estimates and the hold include the 25% markup.
            $table->unsignedBigInteger('estimated_min_charge');
            $table->unsignedBigInteger('estimated_charge');
            $table->unsignedBigInteger('estimated_max_charge');
            $table->unsignedBigInteger('hold_amount');
            $table->unsignedBigInteger('provider_cost')->nullable();
            $table->unsignedBigInteger('user_charge')->nullable();

            // Rates are fixed by Pixelforge at creation; usage is known on success.
            $table->jsonb('rates')->nullable();
            $table->unsignedInteger('input_text_tokens')->nullable();
            $table->unsignedInteger('input_image_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->unsignedInteger('reasoning_tokens')->nullable();

            $table->text('image_url')->nullable();
            $table->timestampTz('image_url_expires_at')->nullable();
            $table->string('image_path')->nullable();

            $table->timestampTz('submitted_at')->nullable();
            $table->timestampTz('started_at')->nullable();
            // Pixelforge bills a generation on the UTC day it completed.
            $table->timestampTz('completed_at')->nullable()->index();
            $table->timestampTz('settled_at')->nullable();
            $table->timestampsTz();

            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('generations');
    }
};
