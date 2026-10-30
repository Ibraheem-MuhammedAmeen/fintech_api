<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds users.phone (e.g. "08031234567").
 *
 * SAFE BY DESIGN:
 * - nullable: existing users (like Ameen) have no phone yet, and that is fine.
 *   Their old wallet numbers keep working.
 * - unique: one phone number = one account. MySQL allows many NULLs in a
 *   unique column, so users without a phone do not clash with each other.
 * - Only adds; never drops anything.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'phone')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('phone', 15)->nullable()->after('email');
            });
        }

        if (! Schema::hasIndex('users', 'users_phone_unique')) {
            Schema::table('users', function (Blueprint $table) {
                $table->unique('phone', 'users_phone_unique');
            });
        }
    }

    public function down(): void
    {
        // Intentionally empty: phone numbers are real user data.
    }
};
