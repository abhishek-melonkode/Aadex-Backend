<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Moves the role hierarchy out of PHP and into the roles table.
 *
 * Rank used to be a hardcoded map of five names, which meant a role created
 * through the API had no place in it and could not be safely assigned. With
 * `level` on the row, any number of roles can exist and the escalation rules
 * ("only grant a rank below your own") keep working unchanged.
 *
 * Lower number outranks higher. The gaps between the seeded levels are
 * deliberate: they leave room to slot a custom role between two of them
 * without renumbering anything.
 */
return new class extends Migration
{
    private const SEEDED_LEVELS = [
        'super_admin' => 0,
        'hotel_chain_admin' => 10,
        'hotel_admin' => 20,
        'staff' => 30,
        'guest' => 40,
    ];

    public function up(): void
    {
        Schema::table(config('permission.table_names.roles'), function (Blueprint $table) {
            // Defaults to the bottom of the ladder: a role whose rank nobody
            // set outranks nothing, which is the safe direction to fail.
            $table->unsignedSmallInteger('level')->default(100)->after('guard_name');
            $table->string('description')->nullable()->after('level');
        });

        foreach (self::SEEDED_LEVELS as $name => $level) {
            DB::table(config('permission.table_names.roles'))
                ->where('name', $name)
                ->update(['level' => $level]);
        }
    }

    public function down(): void
    {
        Schema::table(config('permission.table_names.roles'), function (Blueprint $table) {
            $table->dropColumn(['level', 'description']);
        });
    }
};
