<?php

namespace Database\Seeders;

use App\Models\Favorite;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $userId = (int) env('TEST_TELEGRAM_ID', 123456789);

        User::updateOrCreate(
            ['id' => $userId],
            ['username' => 'demo_manager', 'first_name' => 'Менеджер', 'last_seen' => now()]
        );

        Subscription::create([
            'user_id'         => $userId,
            'query'           => ['make' => 'Toyota', 'model' => 'Camry', 'yearFrom' => 2019, 'yearTo' => 2024, 'sources' => ['copart', 'iai', 'manheim']],
            'known_lot_ids'   => ['copart_45892831', 'copart_50234187'],
            'new_lots_count'  => 2,
            'new_lot_previews'=> [
                ['id' => 'copart_50234187', 'make' => 'Toyota', 'model' => 'Camry', 'year' => 2022, 'price' => 9200, 'imageUrl' => 'https://images.unsplash.com/photo-1590362891991-f776e747a588?w=400&h=300&fit=crop', 'sourceName' => 'Copart', 'lotUrl' => 'https://copart.com/lot/50234187', 'damage' => 'SIDE'],
                ['id' => 'copart_45892831', 'make' => 'Toyota', 'model' => 'Camry', 'year' => 2020, 'price' => 11400, 'imageUrl' => 'https://images.unsplash.com/photo-1502877338535-766e1452684a?w=400&h=300&fit=crop', 'sourceName' => 'Copart', 'lotUrl' => 'https://copart.com/lot/45892831', 'damage' => 'FRONT END'],
            ],
            'active'          => true,
            'last_checked_at' => now()->subMinutes(30),
        ]);

        Subscription::create([
            'user_id'         => $userId,
            'query'           => ['make' => 'Honda', 'yearFrom' => 2020, 'priceMax' => 15000, 'sources' => ['copart', 'iai']],
            'known_lot_ids'   => ['copart_45917204'],
            'new_lots_count'  => 0,
            'new_lot_previews'=> [],
            'active'          => true,
            'last_checked_at' => now()->subMinutes(55),
        ]);

        Subscription::create([
            'user_id'         => $userId,
            'query'           => ['make' => 'Tesla', 'sources' => ['copart', 'iai']],
            'known_lot_ids'   => ['copart_50512948'],
            'new_lots_count'  => 1,
            'new_lot_previews'=> [
                ['id' => 'copart_50512948', 'make' => 'Tesla', 'model' => 'Model 3', 'year' => 2022, 'price' => 19600, 'imageUrl' => 'https://images.unsplash.com/photo-1560958089-b8a1929cea89?w=400&h=300&fit=crop', 'sourceName' => 'Copart', 'lotUrl' => 'https://copart.com/lot/50512948', 'damage' => 'REAR END'],
            ],
            'active'          => true,
            'last_checked_at' => now()->subHours(1),
        ]);

        Favorite::create([
            'user_id'  => $userId,
            'lot_id'   => 'copart_45892831',
            'source'   => 'copart',
            'lot_data' => ['id' => 'copart_45892831', 'source' => 'copart', 'sourceName' => 'Copart', 'make' => 'Honda', 'model' => 'Accord', 'year' => 2020, 'price' => 11400, 'mileage' => 85000, 'damage' => 'FRONT END', 'title' => 'Salvage', 'location' => 'Los Angeles, CA', 'lotUrl' => 'https://copart.com/lot/45892831', 'imageUrl' => 'https://images.unsplash.com/photo-1590362891991-f776e747a588?w=400&h=300&fit=crop', 'vin' => '1HGCV1F34LA123456', 'auctionDate' => '2024-03-18'],
        ]);

        Favorite::create([
            'user_id'  => $userId,
            'lot_id'   => 'copart_50512948',
            'source'   => 'copart',
            'lot_data' => ['id' => 'copart_50512948', 'source' => 'copart', 'sourceName' => 'Copart', 'make' => 'Tesla', 'model' => 'Model 3', 'year' => 2022, 'price' => 19600, 'mileage' => 47000, 'damage' => 'REAR END', 'title' => 'Salvage', 'location' => 'San Jose, CA', 'lotUrl' => 'https://copart.com/lot/50512948', 'imageUrl' => 'https://images.unsplash.com/photo-1560958089-b8a1929cea89?w=400&h=300&fit=crop', 'vin' => '5YJ3E1EA5NF512948', 'auctionDate' => '2024-04-10'],
        ]);

        Favorite::create([
            'user_id'  => $userId,
            'lot_id'   => 'copart_50234187',
            'source'   => 'copart',
            'lot_data' => ['id' => 'copart_50234187', 'source' => 'copart', 'sourceName' => 'Copart', 'make' => 'Toyota', 'model' => 'Camry', 'year' => 2022, 'price' => 9200, 'mileage' => 52000, 'damage' => 'SIDE', 'title' => 'Salvage', 'location' => 'Chicago, IL', 'lotUrl' => 'https://copart.com/lot/50234187', 'imageUrl' => 'https://images.unsplash.com/photo-1553440569-bcc63803a83d?w=400&h=300&fit=crop', 'vin' => '4T1B11HK5NU234187', 'auctionDate' => '2024-03-22'],
        ]);
    }
}
