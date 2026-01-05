<?php

namespace Database\Seeders;

use App\Models\PermissionSet;
use Illuminate\Database\Seeder;

class PermissionSetSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $templates = PermissionSet::getDefaultTemplates();

        foreach ($templates as $index => $template) {
            PermissionSet::updateOrCreate(
                ['slug' => $template['slug']],
                array_merge($template, [
                    'is_template' => true,
                    'is_active' => true,
                    'position' => $index,
                ])
            );
        }
    }
}
