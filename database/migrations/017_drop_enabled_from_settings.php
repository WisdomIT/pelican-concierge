<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The on/off toggle duplicated something the panel already does — disabling the plugin
 * itself. Two switches for one thing invites the state where the plugin is enabled but
 * the toggle is off, and someone has to work out why the sidebar is missing (#2).
 *
 * ⚠ Dropping the column rather than abandoning it: a column nothing reads is a trap for
 *   the next person, who will assume it does something.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('concierge_settings', 'enabled')) {
            Schema::table('concierge_settings', function (Blueprint $table) {
                $table->dropColumn('enabled');
            });
        }
    }

    public function down(): void
    {
        if (!Schema::hasColumn('concierge_settings', 'enabled')) {
            Schema::table('concierge_settings', function (Blueprint $table) {
                $table->boolean('enabled')->default(true);
            });
        }
    }
};
