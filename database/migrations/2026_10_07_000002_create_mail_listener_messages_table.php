<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('mail_listener_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('mail_account_id')->constrained('mail_listener_accounts')->cascadeOnDelete();
            $table->char('dedup_key', 40);
            $table->string('provider_message_id', 512);
            $table->string('internet_message_id', 512)->nullable();
            $table->string('subject', 1000)->nullable();
            $table->string('from_email', 255)->nullable();
            $table->timestampTz('received_at');
            $table->string('status', 20)->index();
            $table->text('error')->nullable();
            $table->timestamps();

            $table->unique(['mail_account_id', 'dedup_key']);
            $table->index(['mail_account_id', 'received_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('mail_listener_messages');
    }
};
