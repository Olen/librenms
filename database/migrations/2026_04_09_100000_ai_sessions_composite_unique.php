<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Replace the global unique index on ai_sessions.session_id with a
     * composite unique on (user_id, session_id). A global unique let one
     * user squat on a session_id and block other users from ever creating
     * that same id string, which combined with client-supplied session_ids
     * would be a DoS vector. Scoping uniqueness to the owning user
     * eliminates the cross-user collision surface without giving up
     * uniqueness guarantees within a single user's sessions.
     */
    public function up(): void
    {
        Schema::table('ai_sessions', function (Blueprint $table) {
            $table->dropUnique(['session_id']);
            $table->unique(['user_id', 'session_id']);
        });
    }

    public function down(): void
    {
        Schema::table('ai_sessions', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'session_id']);
            $table->unique(['session_id']);
        });
    }
};
