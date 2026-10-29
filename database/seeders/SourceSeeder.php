<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\Source;

class SourceSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        //
        $sources = [
            [
                'name' => 'ShareSansar',
                'url'  => 'https://www.sharesansar.com/news-page',
                'type' => 'html'
            ],
            [
                'name' => 'Bizmandu',
                'url'  => 'https://bizmandu.com/content/category/news.html',
                'type' => 'html'
            ],
            [
                'name' => 'Setopati',
                'url'  => 'https://www.setopati.com/kinmel',
                'type' => 'html'
            ],
            [
                'name' => 'Ratopati',
                'url'  => 'https://www.ratopati.com/economy',
                'type' => 'html'
            ],
        ];

        foreach ($sources as $source) {
            Source::firstOrCreate(
                ['url' => $source['url']],
                [
                    'name'      => $source['name'],
                    'type'      => $source['type'],
                    'is_active' => true,
                ]
            );
        }
    }
}
