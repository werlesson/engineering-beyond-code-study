<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Professional;
use App\Models\Service;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Professional>
 */
class ProfessionalFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'name' => fake()->name(),
        ];
    }

    /**
     * Vincula os serviços ao profissional após a criação, via offer().
     */
    public function offering(Service ...$services): static
    {
        return $this->afterCreating(function (Professional $professional) use ($services): void {
            foreach ($services as $service) {
                $professional->offer($service);
            }
        });
    }
}
