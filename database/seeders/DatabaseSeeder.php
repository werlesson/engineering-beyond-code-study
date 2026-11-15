<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     *
     * Uso: php artisan migrate:fresh --seed
     */
    public function run(): void
    {
        $this->call(DemoSeeder::class);
    }
}
