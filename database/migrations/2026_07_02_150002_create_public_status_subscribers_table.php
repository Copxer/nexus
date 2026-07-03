<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('public_status_subscribers', function (Blueprint $table) {
            $table->id();

            $table->foreignId('project_id')
                ->constrained('projects')
                ->cascadeOnDelete();

            // 190 fits under MySQL's utf8mb4 unique-index byte limit
            // (191 chars * 4 bytes = 764 bytes; index max 767 pre-8.0).
            $table->string('email', 190);

            // 64-char opaque tokens. `unique` on each so the confirm /
            // unsubscribe routes can find their row by token alone
            // (no email guessing).
            $table->string('confirmation_token', 64)->unique();
            $table->string('unsubscribe_token', 64)->unique();

            $table->timestamp('confirmed_at')->nullable();

            $table->timestamps();

            // One subscription per email per project. Repeated subscribe
            // POSTs from the same email regenerate the tokens without
            // creating a second row.
            $table->unique(['project_id', 'email']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('public_status_subscribers');
    }
};
