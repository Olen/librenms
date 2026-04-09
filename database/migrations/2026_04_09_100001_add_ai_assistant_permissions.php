<?php

use Carbon\Carbon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    /**
     * Register the ai-assistant.chat permission so administrators can grant
     * AI assistant access to specific roles. Without a dedicated permission
     * the chat endpoint could only be gated on generic roles (admin /
     * global-read), which doesn't match the actual use case: not every
     * read-only user should be allowed to burn LLM budget.
     *
     * The permission name follows the same entity.action convention used by
     * the rest of the LibreNMS granular permission system (see
     * database/migrations/2026_02_28_231000_add_all_permissions.php).
     */
    private array $permissions = [
        'ai-assistant.chat',
    ];

    public function up(): void
    {
        $now = Carbon::now();

        $insertData = array_map(fn ($name) => [
            'name' => $name,
            'guard_name' => 'web',
            'created_at' => $now,
            'updated_at' => $now,
        ], $this->permissions);

        DB::table('permissions')->insertOrIgnore($insertData);
    }

    public function down(): void
    {
        DB::table('permissions')
            ->whereIn('name', $this->permissions)
            ->where('guard_name', 'web')
            ->delete();
    }
};
