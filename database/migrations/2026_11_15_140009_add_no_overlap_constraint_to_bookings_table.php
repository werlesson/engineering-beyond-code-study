<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Impede, no banco, dois bookings confirmados sobrepostos para o mesmo profissional.
     * Intervalos semiabertos [starts_at, ends_at) permitem horários adjacentes.
     */
    public function up(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE bookings ADD CONSTRAINT bookings_no_overlap
            EXCLUDE USING gist (
                professional_id WITH =,
                tstzrange(starts_at, ends_at, '[)') WITH &&
            ) WHERE (status_id = 1)
            SQL);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE bookings DROP CONSTRAINT IF EXISTS bookings_no_overlap');
    }
};
