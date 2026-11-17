<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('generations', function (Blueprint $table) {
            // The failed generation this one retries. Unique: a failed generation
            // can be retried once; if the retry also fails, that one can be retried.
            $table->foreignId('retry_of_id')->nullable()->unique()->after('user_id')->constrained('generations')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('generations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('retry_of_id');
        });
    }
};
