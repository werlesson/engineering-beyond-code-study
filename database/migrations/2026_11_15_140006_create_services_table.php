<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('services', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies');
            $table->string('name');
            $table->integer('duration_minutes');
            $table->timestamps();

            $table->unique(['company_id', 'id'], 'services_company_id_id_unique');
        });

        DB::statement('ALTER TABLE services ADD CONSTRAINT services_duration_positive CHECK (duration_minutes > 0)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE services DROP CONSTRAINT IF EXISTS services_duration_positive');

        Schema::dropIfExists('services');
    }
};
