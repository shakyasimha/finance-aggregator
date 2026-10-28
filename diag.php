<?php

require __DIR__ . '/vendor/autoload.php';

use Illuminate\Support\Facades\Http;
use Symfony\Component\DomCrawler\Crawler;

$app = require __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

// taking in input
$input = $argv[1] ?? null;  // Checks if argument was passed
if (empty($input)) {
    echo "Enter Url: ";
    $input = trim(fgets(STDIN));
}

if (empty($input)) {
    echo "No URL provided.\n";
    exit(1);
}

$url = parse_url($input, PHP_URL_SCHEME) ? $input : "https://{$input}";
echo "Testing connection to: {$url}....";

try {
    $r = Http::withHeaders([
        'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
    ])->timeout(60)->get($url);

    echo 'Status: ' . $r->status() . PHP_EOL;
    echo 'Length: ' . strlen($r->body()) . PHP_EOL;

    // Parse extracted anchor links
    $crawler = new Crawler($r->body());
    $links = [];

    $crawler->filter('a')->each(function (Crawler $node) use (&$links) {
        $href = trim($node->attr('href') ?? '');
        if (!empty($href) && $href !== '#' && !str_starts_with($href, 'javascript:')) {
            $links[] = $href;
        }
    });

    $uniqueLinks = array_unique($links);
    echo 'Total Unique Links Found: ' . count($uniqueLinks) . PHP_EOL . PHP_EOL;

    echo "--- First 10 Extracted Links Sample ---\n";
    foreach (array_slice($uniqueLinks, 0, 10) as $index => $link) {
        echo sprintf("[%d] %s\n", $index + 1, $link);
    }
} catch (Throwable $e) {
    echo get_class($e) . ': ' . $e->getMessage() . PHP_EOL;
}
