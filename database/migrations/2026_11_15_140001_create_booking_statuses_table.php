<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cria a lookup de status com IDs fixos (contrato usado pela exclusion constraint).
     */
    public function up(): void
    {
        Schema::create('booking_statuses', function (Blueprint $table) {
            $table->unsignedBigInteger('id')->primary();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->timestamps();
        });

        $now = now();

        DB::table('booking_statuses')->insert([
            ['id' => 1, 'name' => 'Confirmado', 'slug' => 'confirmed', 'created_at' => $now, 'updated_at' => $now],
            ['id' => 2, 'name' => 'Cancelado', 'slug' => 'cancelled', 'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('booking_statuses');
    }
};
