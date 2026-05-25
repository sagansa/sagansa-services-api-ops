<?php

namespace Database\Seeders;

use App\Models\Product;
use App\Models\Store;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ProductSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $products = [
            [
                'name' => 'Sumatra Mandheling Coffee Beans',
                'description' => 'Rich, full-bodied coffee beans with low acidity and notes of chocolate and earth. Perfect for espresso and dark roasts. Sourced directly from Aceh.',
                'price' => 250_000,
                'category' => 'Coffee Beans',
                'stock' => 250,
                'color' => '#4B382A',
            ],
            [
                'name' => 'Artisanal Bread Flour (T45)',
                'description' => 'High-protein French-style flour, ideal for creating baguettes, croissants, and other viennoiserie with a light, airy texture.',
                'price' => 85_000,
                'category' => 'Flour & Grains',
                'stock' => 380,
                'color' => '#C8A978',
            ],
            [
                'name' => 'Madagascar Vanilla Beans (Grade A)',
                'description' => 'Plump, oily, and aromatic vanilla beans with a creamy, sweet flavour profile. Essential for high-quality desserts and infusions.',
                'price' => 150_000,
                'category' => 'Spices',
                'stock' => 140,
                'color' => '#6B4B3E',
            ],
            [
                'name' => 'Organic Coconut Sugar',
                'description' => 'An unrefined, natural sweetener with a lower glycaemic index and a subtle caramel flavour. Excellent for baking and beverages.',
                'price' => 60_000,
                'category' => 'Sweeteners',
                'stock' => 420,
                'color' => '#A26B3D',
            ],
            [
                'name' => 'Single-Origin Cacao Nibs',
                'description' => 'Fermented, dried, and roasted cacao beans. Offers intense, unsweetened chocolate flavour and a crunchy texture for culinary applications.',
                'price' => 120_000,
                'category' => 'Chocolate',
                'stock' => 300,
                'color' => '#5B3A2E',
            ],
            [
                'name' => 'Himalayan Pink Salt (Coarse)',
                'description' => 'Unrefined, mineral-rich salt harvested from the Khewra Salt Mine. Ideal for finishing dishes, curing, and brining.',
                'price' => 45_000,
                'category' => 'Spices',
                'stock' => 500,
                'color' => '#D18B94',
            ],
            [
                'name' => 'Italian "00" Flour',
                'description' => 'Finely milled soft wheat flour, the gold standard for authentic Neapolitan pizza and fresh pasta with a silky texture.',
                'price' => 95_000,
                'category' => 'Flour & Grains',
                'stock' => 360,
                'color' => '#E4D4BE',
            ],
            [
                'name' => 'Kampot Black Peppercorns',
                'description' => 'A globally renowned peppercorn from Cambodia with a complex flavour profile that is both spicy and floral. A must-have for professional kitchens.',
                'price' => 180_000,
                'category' => 'Spices',
                'stock' => 210,
                'color' => '#2F2B27',
            ],
        ];

        foreach ($products as $attributes) {
            $slug = Str::slug($attributes['name']);
            $imagePath = $this->ensureImageExists($slug, $attributes['name'], $attributes['category'], $attributes['color']);

            $requestQuantity = random_int(0, 1) === 1;
            $remainingQuantity = random_int(0, 1) === 1;
            $sku = strtoupper(Str::slug($attributes['name'], '-')) . '-' . strtoupper(Str::random(4));
            $barcode = 'BC' . str_pad((string) random_int(1_000_000, 9_999_999), 7, '0', STR_PAD_LEFT);

            $product = Product::updateOrCreate(
                ['slug' => $slug],
                [
                    'name' => $attributes['name'],
                    'description' => $attributes['description'],
                    'price' => $attributes['price'],
                    'stock' => $attributes['stock'],
                    'request' => $requestQuantity,
                    'remaining' => $remainingQuantity,
                    'is_active' => true,
                    'image' => $imagePath,
                    'sku' => $sku,
                    'barcode' => $barcode,
                ]
            );

            $product->variants()->delete();
            $product->modifications()->delete();

            $product->variants()->createMany([
                [
                    'name' => 'Standard Pack',
                    'sku' => $sku . '-STD',
                    'price' => $attributes['price'],
                    'stock' => max(0, (int) floor($attributes['stock'] * 0.6)),
                    'is_active' => true,
                ],
                [
                    'name' => 'Bulk Pack',
                    'sku' => $sku . '-BLK',
                    'price' => (int) round($attributes['price'] * 1.8),
                    'stock' => max(0, (int) floor($attributes['stock'] * 0.4)),
                    'is_active' => true,
                ],
            ]);

            $product->modifications()->createMany([
                [
                    'name' => 'Gift Wrap',
                    'price' => 15000,
                    'is_active' => true,
                ],
                [
                    'name' => 'Express Handling',
                    'price' => 25000,
                    'is_active' => true,
                ],
            ]);

            $storesForProduct = Store::inRandomOrder()->limit(2)->get();
            if ($storesForProduct->isNotEmpty()) {
                $product->stores()->sync(
                    $storesForProduct->mapWithKeys(function (Store $store) use ($attributes) {
                        $ratio = mt_rand(90, 120) / 100; // random 0.9 - 1.2
                        $storePrice = (int) round($attributes['price'] * $ratio);

                        return [$store->id => ['price' => $storePrice]];
                    })->all()
                );
            }
        }
    }

    /**
     * Ensure an illustrative image exists for the product and return its path.
     */
    private function ensureImageExists(string $slug, string $name, string $category, string $colour): string
    {
        $path = "products/{$slug}.svg";

        if (! Storage::disk('public')->exists($path)) {
            Storage::disk('public')->put($path, $this->buildSvg($slug, $name, $category, $colour));
        }

        return $path;
    }

    /**
     * Build a simple SVG preview for the product so we ship self-hosted assets.
     */
    private function buildSvg(string $slug, string $name, string $category, string $colour): string
    {
        $safeName = htmlspecialchars($name, ENT_QUOTES);
        $safeCategory = htmlspecialchars($category, ENT_QUOTES);
        $gradientId = "{$slug}-gradient";

        return <<<SVG
<?xml version="1.0" encoding="UTF-8"?>
<svg xmlns="http://www.w3.org/2000/svg" width="800" height="600" viewBox="0 0 800 600">
  <defs>
    <linearGradient id="{$gradientId}" x1="0%" y1="0%" x2="100%" y2="100%">
      <stop offset="0%" stop-color="{$colour}" stop-opacity="0.95"/>
      <stop offset="100%" stop-color="{$colour}" stop-opacity="0.65"/>
    </linearGradient>
  </defs>
  <rect width="800" height="600" fill="url(#{$gradientId})"/>
  <g fill="#FFFFFF" font-family="Helvetica, Arial, sans-serif">
    <text x="50" y="320" font-size="46" font-weight="700" letter-spacing="1.5">{$safeName}</text>
    <text x="50" y="380" font-size="28" font-weight="400" opacity="0.85">{$safeCategory}</text>
  </g>
  <circle cx="640" cy="160" r="90" fill="#FFFFFF10"/>
  <circle cx="700" cy="460" r="140" fill="#FFFFFF08"/>
  <path d="M520 540C600 470 700 500 760 440" stroke="#FFFFFF20" stroke-width="18" stroke-linecap="round" fill="none"/>
</svg>
SVG;
    }
}
