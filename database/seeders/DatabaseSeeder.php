<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    // En local : la société de démonstration. Le poste de support a sa propre commande (support:admin).
    public function run(): void
    {
        $this->call(DemoSeeder::class);
    }
}
