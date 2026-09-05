<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Users, sessions and password resets.
 *
 * A user is a PLATFORM-level record, not an organisation-level one: the same
 * person may be an accountant in one organisation and read-only in another,
 * so users are deliberately NOT organisation-scoped. Membership and role live
 * in `organization_user`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            // UUID v7 throughout: time-ordered, so index locality stays good
            // as the table grows, and non-enumerable, so an id in a URL
            // leaks no information about how many users exist.
            $table->uuid('id')->primary();

            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->rememberToken();

            // -- Multi-factor ------------------------------------------
            // Mandatory for any role that can post to the ledger, approve a
            // payment, or manage users. SECURITY.md §2.
            $table->text('two_factor_secret')->nullable();
            $table->text('two_factor_recovery_codes')->nullable();
            $table->timestamp('two_factor_confirmed_at')->nullable();

            // -- Preferences -------------------------------------------
            // Display only. An organisation's own locale and timezone govern
            // its documents; these govern this person's interface.
            $table->string('locale', 10)->default('en');
            $table->string('timezone', 64)->default('UTC');
            $table->string('theme', 10)->default('system');
            $table->string('density', 16)->default('compact');

            // The organisation this user last worked in, so returning lands
            // them where they left off rather than on a chooser.
            $table->uuid('last_organization_id')->nullable();

            // -- Account state -----------------------------------------
            $table->timestamp('last_login_at')->nullable();
            $table->string('last_login_ip', 45)->nullable();

            // Set when an administrator suspends the account. Distinct from
            // deletion: a suspended user's audit trail must remain intact
            // and attributable.
            $table->timestamp('suspended_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index('suspended_at');
        });

        Schema::create('password_reset_tokens', function (Blueprint $table): void {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->uuid('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
    }
};
