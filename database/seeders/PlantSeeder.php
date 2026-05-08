<?php

namespace Database\Seeders;

use App\Models\Plant;
use Illuminate\Database\Seeder;

class PlantSeeder extends Seeder
{
    public function run(): void
    {
        $plants = [
            ['common_name' => 'Arjun Leaf',            'scientific_name' => 'Terminalia arjuna'],
            ['common_name' => 'Curry Leaf',            'scientific_name' => 'Murraya koenigii'],
            ['common_name' => 'Marsh Pennywort Leaf',  'scientific_name' => 'Hydrocotyle sibthorpioides'],
            ['common_name' => 'Mint Leaf',             'scientific_name' => 'Mentha'],
            ['common_name' => 'Neem Leaf',             'scientific_name' => 'Azadirachta indica'],
            ['common_name' => 'Rubble Leaf',           'scientific_name' => null],
        ];

        foreach ($plants as $plant) {
            Plant::updateOrCreate(
                ['common_name' => $plant['common_name']],
                ['scientific_name' => $plant['scientific_name']]
            );
        }
    }
}
