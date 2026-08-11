<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 사이드바 커스텀 색 (#10). null = 패널의 primary 를 따른다(기본, 오버라이드 없음).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('concierge_settings', 'sidebar_color')) {
            Schema::table('concierge_settings', function (Blueprint $table) {
                $table->string('sidebar_color')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('concierge_settings', 'sidebar_color')) {
            Schema::table('concierge_settings', function (Blueprint $table) {
                $table->dropColumn('sidebar_color');
            });
        }
    }
};
