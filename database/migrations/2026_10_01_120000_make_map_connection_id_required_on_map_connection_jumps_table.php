<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Jumps are only logged once their connection exists, so the pending rows
     * (never claimed by a connection) are dropped before the column is required.
     */
    public function up(): void
    {
        DB::table('map_connection_jumps')->whereNull('map_connection_id')->delete();

        Schema::table('map_connection_jumps', function (Blueprint $table): void {
            $table->foreignId('map_connection_id')->nullable(false)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('map_connection_jumps', function (Blueprint $table): void {
            $table->foreignId('map_connection_id')->nullable()->change();
        });
    }
};
