<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-organization single sign-on configuration.
 *
 * This is a product each client configures for themselves after purchase, so
 * the settings belong in the database behind the admin UI rather than in
 * .env — one deployment serves many organizations, each federating with its
 * own identity provider.
 *
 * `slug` is what makes that work: the sign-in flow has to know which
 * organization it is acting for before anyone is authenticated, so each client
 * gets its own entry URL (/auth/sso/firstbank). SAML needs it regardless,
 * because the Assertion Consumer Service URL is per service-provider-instance
 * and must be registered with the IdP.
 *
 * Secrets (OIDC client secret, SAML SP private key) are encrypted at rest by
 * the model's casts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_sso_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->unique()->constrained()->cascadeOnDelete();

            $table->string('slug', 64)->unique();
            $table->boolean('enabled')->default(false);
            $table->string('driver', 16)->default('oidc');
            $table->string('label', 120)->default('Single sign-on');

            // OIDC
            $table->string('oidc_client_id', 255)->nullable();
            $table->text('oidc_client_secret')->nullable();
            $table->string('oidc_auth_url', 500)->nullable();
            $table->string('oidc_token_url', 500)->nullable();
            $table->string('oidc_userinfo_url', 500)->nullable();
            $table->json('oidc_scopes')->nullable();

            // SAML 2.0
            $table->string('saml_idp_entity_id', 500)->nullable();
            $table->string('saml_idp_sso_url', 500)->nullable();
            $table->string('saml_idp_slo_url', 500)->nullable();
            $table->text('saml_idp_x509_cert')->nullable();
            $table->text('saml_sp_x509_cert')->nullable();
            $table->text('saml_sp_private_key')->nullable();
            $table->string('saml_email_attribute', 190)->nullable();
            $table->string('saml_name_attribute', 190)->nullable();

            // Common
            $table->string('groups_claim', 190)->default('groups');
            $table->json('allowed_domains')->nullable();
            $table->json('role_map')->nullable();
            $table->json('default_roles')->nullable();
            $table->boolean('auto_provision')->default(false);
            $table->boolean('sync_roles_on_login')->default(true);

            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['enabled', 'driver']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_sso_settings');
    }
};
