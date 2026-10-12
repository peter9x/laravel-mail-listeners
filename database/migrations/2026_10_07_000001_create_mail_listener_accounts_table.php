<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Mupy\MailListeners\Tenancy\TenantColumn;

return new class extends Migration
{
    /**
     * The `mail-listeners.database.connection` connection (default: the app default connection).
     */
    public function getConnection(): ?string
    {
        return config('mail-listeners.database.connection');
    }

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('mail_listener_accounts', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 150);
            $table->string('email', 255);
            $table->string('connector', 50);
            // Encrypted (`encrypted:array` cast): connector settings may hold secrets, e.g. the IMAP password.
            $table->text('connector_settings')->nullable();
            $table->json('events');
            // Cron expression the account is polled on, e.g. every 5 minutes or "0 6 * * *" (every day at 06:00).
            $table->string('poll_cron', 100)->default('*/5 * * * *');
            $table->timestampTz('read_from')->nullable();
            $table->boolean('active')->default(true)->index();
            // Defined in code (MailListeners::mailbox()) and kept in sync by the package.
            $table->boolean('managed')->default(false)->index();
            $table->timestampTz('last_polled_at')->nullable();
            $table->timestampTz('last_received_at')->nullable();
            // Where the next poll resumes, for connectors reading from their own cursor (e.g. the IMAP UID).
            $table->text('poll_cursor')->nullable();
            $table->text('last_error')->nullable();
            $table->timestampTz('last_error_at')->nullable();
            $table->timestamps();

            // Only when `mail-listeners.tenancy.enabled` (null for central accounts).
            TenantColumn::add($table, ['email']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('mail_listener_accounts');
    }
};
