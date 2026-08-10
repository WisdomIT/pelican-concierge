<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Second rename: `wisdom_ai_assistant_*` → `concierge_*`.
 *
 * ⚠ Two renames in the history is not a mistake to tidy up later. Migrations 001–015 are
 *   left exactly as they are — rewriting them would break every panel that has already
 *   run them. A fresh install walks the whole chain (old name → 015 → this) and lands in
 *   the same place; nobody sees the intermediate names.
 *
 * ⚠ Guarded on both sides so it is safe to re-run and safe on a fresh database where 015
 *   already produced the source tables.
 */
return new class extends Migration
{
    /** @var array<int, string> */
    private array $tables = [
        'settings',
        'usages',
        'tool_calls',
        'conversations',
        'install_checks',
        'idle_watches',
        'backup_watches',
    ];

    public function up(): void
    {
        foreach ($this->tables as $table) {
            $from = 'wisdom_ai_assistant_' . $table;
            $to = 'concierge_' . $table;

            if (Schema::hasTable($from) && !Schema::hasTable($to)) {
                Schema::rename($from, $to);
            }
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $table) {
            $from = 'concierge_' . $table;
            $to = 'wisdom_ai_assistant_' . $table;

            if (Schema::hasTable($from) && !Schema::hasTable($to)) {
                Schema::rename($from, $to);
            }
        }
    }
};
