<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cria bookings. professional_id e service_id não têm FK simples: a FK composta para
     * professional_service garante existência, mesma empresa e vínculo profissional/serviço.
     */
    public function up(): void
    {
        Schema::create('bookings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies');
            $table->unsignedBigInteger('professional_id');
            $table->unsignedBigInteger('customer_id');
            $table->unsignedBigInteger('service_id');
            $table->foreignId('status_id')->default(1)->constrained('booking_statuses');
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at');
            $table->timestamps();

            $table->index(['company_id', 'customer_id']);
            $table->index(['company_id', 'professional_id', 'service_id']);
            $table->index(['professional_id', 'starts_at']);

            $table->foreign(['company_id', 'customer_id'])
                ->references(['company_id', 'id'])
                ->on('customers')
                ->restrictOnDelete();

            $table->foreign(['company_id', 'professional_id', 'service_id'])
                ->references(['company_id', 'professional_id', 'service_id'])
                ->on('professional_service')
                ->restrictOnDelete();
        });

        DB::statement('ALTER TABLE bookings ADD CONSTRAINT bookings_ends_after_starts CHECK (ends_at > starts_at)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE bookings DROP CONSTRAINT IF EXISTS bookings_ends_after_starts');

        Schema::dropIfExists('bookings');
    }
};
