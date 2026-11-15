<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cria a pivô profissional/serviço. As FKs compostas com company_id garantem que
     * profissional e serviço pertençam à mesma empresa.
     */
    public function up(): void
    {
        Schema::create('professional_service', function (Blueprint $table) {
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('professional_id');
            $table->unsignedBigInteger('service_id');
            $table->timestamps();

            $table->primary(['professional_id', 'service_id']);
            $table->unique(
                ['company_id', 'professional_id', 'service_id'],
                'professional_service_company_professional_service_unique',
            );
            $table->index('service_id');

            $table->foreign(['company_id', 'professional_id'])
                ->references(['company_id', 'id'])
                ->on('professionals')
                ->cascadeOnDelete();

            $table->foreign(['company_id', 'service_id'])
                ->references(['company_id', 'id'])
                ->on('services')
                ->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('professional_service');
    }
};
