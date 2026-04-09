<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Add a foreign key on ai_cost_log.user_id that nulls the column when
     * the user is deleted. user_id is intentionally nullable so cost
     * history survives user deletion for accounting purposes — we just
     * stop attributing those rows to a specific user.
     */
    public function up(): void
    {
        Schema::table('ai_cost_log', function (Blueprint $table) {
            $table->foreign('user_id')
                ->references('user_id')
                ->on('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('ai_cost_log', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
        });
    }
};
