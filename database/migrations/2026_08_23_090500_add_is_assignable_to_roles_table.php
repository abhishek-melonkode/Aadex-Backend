<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Whether a role may be handed to a user account.
 *
 * Kept as data rather than an exclusion list in code, so a Super Admin can
 * flip it through `PUT /roles/{role}` without a deploy. `guest` is the one
 * seeded role turned off: guests are not `users` at all — they live in their
 * own table behind their own guard — so the role exists to keep the taxonomy
 * complete, not to be assigned.
 *
 * Rank is unaffected: a role that cannot be assigned can still be edited by
 * whoever outranks it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table(config('permission.table_names.roles'), function (Blueprint $table) {
            $table->boolean('is_assignable')->default(true)->after('description');
        });

        DB::table(config('permission.table_names.roles'))
            ->where('name', 'guest')
            ->update(['is_assignable' => false]);
    }

    public function down(): void
    {
        Schema::table(config('permission.table_names.roles'), function (Blueprint $table) {
            $table->dropColumn('is_assignable');
        });
    }
};
