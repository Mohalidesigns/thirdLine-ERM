<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Email one-time codes as the portal's second factor — TPRM Phase 8.
 *
 * Phase 0 gave `tp_portal_users` a TOTP secret, on the assumption that a
 * vendor would install an authenticator. For this client's vendor population
 * that assumption does not hold: the tail of the register is small firms,
 * printers, couriers and agents, and requiring an app would have meant either
 * excluding them or quietly turning MFA off for them. SMTP is configured, so
 * the code goes to their inbox.
 *
 * EMAIL OTP IS THE WEAKER FACTOR AND THE SCHEMA SHOULD SAY SO. The code and
 * any future password-reset link arrive in the same mailbox, so a compromised
 * inbox is both factors at once — where a TOTP secret lives on a separate
 * device. That is why `mfa_method` exists rather than the email path simply
 * replacing the TOTP columns: a vendor who can hold a secret should be able
 * to, and the column is what lets a client see how many of their vendors do.
 *
 * `mfa_code_hash` IS A HASH. A one-time code sitting in plaintext in a table
 * is a credential readable by a reporting replica or a support export, and its
 * whole value is that only one mailbox has it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tp_portal_users', function (Blueprint $table): void {
            // `email` or `totp`. Email is the default because it needs no
            // enrolment step, and an onboarding flow with fewer steps is one
            // more vendor who finishes it.
            $table->string('mfa_method', 10)->default('email')->after('mfa_enabled');

            $table->string('mfa_code_hash', 255)->nullable()->after('mfa_method');
            $table->timestamp('mfa_code_expires_at')->nullable()->after('mfa_code_hash');

            // Throttles resends, and gives support an answer to "did it send?"
            // that is not a guess.
            $table->timestamp('mfa_code_sent_at')->nullable()->after('mfa_code_expires_at');
            $table->unsignedSmallInteger('mfa_code_attempts')->default(0)->after('mfa_code_sent_at');
        });
    }

    public function down(): void
    {
        Schema::table('tp_portal_users', function (Blueprint $table): void {
            $table->dropColumn([
                'mfa_method', 'mfa_code_hash', 'mfa_code_expires_at', 'mfa_code_sent_at', 'mfa_code_attempts',
            ]);
        });
    }
};
