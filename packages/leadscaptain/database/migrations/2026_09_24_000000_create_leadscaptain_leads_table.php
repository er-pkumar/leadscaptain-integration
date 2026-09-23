<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leadscaptain_leads', static function (Blueprint $table): void {
            $table->id();
            // Leadscaptain lead id; upsert key (see ProfileKey::MAX_LENGTH)
            $table->string('profile_key', 191)->unique();
            $table->string('full_name')->nullable();
            $table->string('email')->nullable()->index();
            $table->string('position_title')->nullable();
            $table->string('company_name')->nullable();
            $table->string('industry')->nullable();
            $table->string('location')->nullable();
            $table->char('country_code', 2)->nullable()->index();
            // Full source record, including fields without a column (email_status, ...)
            $table->json('raw_attributes')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leadscaptain_leads');
    }
};
