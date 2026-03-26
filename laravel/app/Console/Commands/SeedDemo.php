<?php

namespace App\Console\Commands;

use App\Models\Favorite;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Console\Command;

class SeedDemo extends Command
{
    protected $signature   = 'demo:seed {--telegram-id=123456789 : Telegram user ID}';
    protected $description = 'Seed demo data: test user, subscriptions with new lots, favorites';

    public function handle(): int
    {
        $userId = (int) $this->option('telegram-id');

        $this->info("Seeding demo data for user {$userId}...");

        $user = User::updateOrCreate(
            ['id' => $userId],
            ['username' => 'demo_manager', 'first_name' => 'Менеджер', 'last_seen' => now()]
        );
        $this->info('  User created/updated.');

        Subscription::where('user_id', $userId)->delete();
        Favorite::where('user_id', $userId)->delete();
        $this->info('  Old subscriptions & favorites cleared.');

        $this->seedSubscriptions($userId);
        $this->seedFavorites($userId);

        $this->info('Demo seed complete!');
        return self::SUCCESS;
    }

    private function seedSubscriptions(int $userId): void
    {
        $subs = [
            [
                'query' => ['make' => 'Toyota', 'model' => 'Camry', 'yearFrom' => 2019, 'yearTo' => 2024, 'sources' => ['copart', 'iai', 'manheim']],
                'known_lot_ids' => ['copart_45892831', 'copart_50234187', 'iai_31024781'],
                'new_lots_count' => 3,
                'new_lot_previews' => [
                    ['id' => 'copart_50234187', 'make' => 'Toyota', 'model' => 'Camry', 'year' => 2022, 'price' => 9200, 'imageUrl' => 'https://images.unsplash.com/photo-1590362891991-f776e747a588?w=400&h=300&fit=crop', 'sourceName' => 'Copart', 'lotUrl' => 'https://copart.com/lot/50234187', 'damage' => 'SIDE'],
                    ['id' => 'iai_31024781', 'make' => 'Toyota', 'model' => 'Camry', 'year' => 2021, 'price' => 8700, 'imageUrl' => 'https://images.unsplash.com/photo-1553440569-bcc63803a83d?w=400&h=300&fit=crop', 'sourceName' => 'IAAI', 'lotUrl' => 'https://iaai.com/lot/31024781', 'damage' => 'REAR END'],
                    ['id' => 'copart_45892831', 'make' => 'Toyota', 'model' => 'Camry', 'year' => 2020, 'price' => 11400, 'imageUrl' => 'https://images.unsplash.com/photo-1502877338535-766e1452684a?w=400&h=300&fit=crop', 'sourceName' => 'Copart', 'lotUrl' => 'https://copart.com/lot/45892831', 'damage' => 'FRONT END'],
                ],
                'last_checked_at' => now()->subMinutes(25),
            ],
            [
                'query' => ['make' => 'Honda', 'model' => 'Civic', 'yearFrom' => 2020, 'yearTo' => 2024, 'priceMax' => 15000, 'sources' => ['copart', 'iai']],
                'known_lot_ids' => ['copart_45917204', 'iai_31058293'],
                'new_lots_count' => 1,
                'new_lot_previews' => [
                    ['id' => 'iai_31058293', 'make' => 'Honda', 'model' => 'Civic', 'year' => 2022, 'price' => 9800, 'imageUrl' => 'https://images.unsplash.com/photo-1580273916550-e323be2ae537?w=400&h=300&fit=crop', 'sourceName' => 'IAAI', 'lotUrl' => 'https://iaai.com/lot/31058293', 'damage' => 'FRONT END'],
                ],
                'last_checked_at' => now()->subMinutes(50),
            ],
            [
                'query' => ['make' => 'BMW', 'bodyTypes' => ['Sedan', 'SUV'], 'yearFrom' => 2019, 'sources' => ['copart', 'iai', 'manheim']],
                'known_lot_ids' => ['copart_50341926'],
                'new_lots_count' => 0,
                'new_lot_previews' => [],
                'last_checked_at' => now()->subMinutes(15),
            ],
            [
                'query' => ['make' => 'Tesla', 'sources' => ['copart', 'iai']],
                'known_lot_ids' => ['copart_50512948'],
                'new_lots_count' => 2,
                'new_lot_previews' => [
                    ['id' => 'copart_50512948', 'make' => 'Tesla', 'model' => 'Model 3', 'year' => 2022, 'price' => 19600, 'imageUrl' => 'https://images.unsplash.com/photo-1560958089-b8a1929cea89?w=400&h=300&fit=crop', 'sourceName' => 'Copart', 'lotUrl' => 'https://copart.com/lot/50512948', 'damage' => 'REAR END'],
                    ['id' => 'iai_31134950', 'make' => 'Tesla', 'model' => 'Model Y', 'year' => 2023, 'price' => 24500, 'imageUrl' => 'https://images.unsplash.com/photo-1536700503339-1e4b06520771?w=400&h=300&fit=crop', 'sourceName' => 'IAAI', 'lotUrl' => 'https://iaai.com/lot/31134950', 'damage' => 'SIDE'],
                ],
                'last_checked_at' => now()->subHours(1),
            ],
        ];

        foreach ($subs as $data) {
            Subscription::create([
                'user_id'         => $userId,
                'query'           => $data['query'],
                'known_lot_ids'   => $data['known_lot_ids'],
                'new_lots_count'  => $data['new_lots_count'],
                'new_lot_previews'=> $data['new_lot_previews'],
                'active'          => true,
                'last_checked_at' => $data['last_checked_at'],
            ]);
        }

        $this->info('  4 subscriptions created (2 with new lots badge).');
    }

    private function seedFavorites(int $userId): void
    {
        $favorites = [
            [
                'lot_id' => 'copart_45892831',
                'source' => 'copart',
                'lot_data' => [
                    'id' => 'copart_45892831', 'source' => 'copart', 'sourceName' => 'Copart',
                    'make' => 'Honda', 'model' => 'Accord', 'year' => 2020, 'price' => 11400,
                    'mileage' => 85000, 'damage' => 'FRONT END', 'title' => 'Salvage',
                    'location' => 'Los Angeles, CA',
                    'lotUrl' => 'https://copart.com/lot/45892831',
                    'imageUrl' => 'https://images.unsplash.com/photo-1590362891991-f776e747a588?w=400&h=300&fit=crop',
                    'vin' => '1HGCV1F34LA123456', 'auctionDate' => '2024-03-18',
                ],
            ],
            [
                'lot_id' => 'copart_50234187',
                'source' => 'copart',
                'lot_data' => [
                    'id' => 'copart_50234187', 'source' => 'copart', 'sourceName' => 'Copart',
                    'make' => 'Toyota', 'model' => 'Camry', 'year' => 2022, 'price' => 9200,
                    'mileage' => 52000, 'damage' => 'SIDE', 'title' => 'Salvage',
                    'location' => 'Chicago, IL',
                    'lotUrl' => 'https://copart.com/lot/50234187',
                    'imageUrl' => 'https://images.unsplash.com/photo-1553440569-bcc63803a83d?w=400&h=300&fit=crop',
                    'vin' => '4T1B11HK5NU234187', 'auctionDate' => '2024-03-22',
                ],
            ],
            [
                'lot_id' => 'iai_31073416',
                'source' => 'iai',
                'lot_data' => [
                    'id' => 'iai_31073416', 'source' => 'iai', 'sourceName' => 'IAAI',
                    'make' => 'Toyota', 'model' => 'RAV4', 'year' => 2023, 'price' => 18500,
                    'mileage' => 15000, 'damage' => 'HAIL', 'title' => 'Salvage',
                    'location' => 'Dallas, TX',
                    'lotUrl' => 'https://iaai.com/lot/31073416',
                    'imageUrl' => 'https://images.unsplash.com/photo-1519641471654-76ce0107ad1b?w=400&h=300&fit=crop',
                    'vin' => 'JTMRFREV4NJ073416', 'auctionDate' => '2024-04-05',
                ],
            ],
            [
                'lot_id' => 'copart_50512948',
                'source' => 'copart',
                'lot_data' => [
                    'id' => 'copart_50512948', 'source' => 'copart', 'sourceName' => 'Copart',
                    'make' => 'Tesla', 'model' => 'Model 3', 'year' => 2022, 'price' => 19600,
                    'mileage' => 47000, 'damage' => 'REAR END', 'title' => 'Salvage',
                    'location' => 'San Jose, CA',
                    'lotUrl' => 'https://copart.com/lot/50512948',
                    'imageUrl' => 'https://images.unsplash.com/photo-1560958089-b8a1929cea89?w=400&h=300&fit=crop',
                    'vin' => '5YJ3E1EA5NF512948', 'auctionDate' => '2024-04-10',
                ],
            ],
            [
                'lot_id' => 'manheim_MH100103',
                'source' => 'manheim',
                'lot_data' => [
                    'id' => 'manheim_MH100103', 'source' => 'manheim', 'sourceName' => 'Manheim',
                    'make' => 'Ford', 'model' => 'F-150', 'year' => 2022, 'price' => 28500,
                    'mileage' => 34000, 'damage' => null, 'title' => '',
                    'location' => 'Dallas, TX',
                    'lotUrl' => 'https://manheim.com/lot/MH100103',
                    'imageUrl' => 'https://images.unsplash.com/photo-1559416523-140ddc3d238c?w=400&h=300&fit=crop',
                    'vin' => '1FTEW1EP2NFA00303', 'auctionDate' => '2024-04-03',
                ],
            ],
            [
                'lot_id' => 'encar_EN-20230005',
                'source' => 'encar',
                'lot_data' => [
                    'id' => 'encar_EN-20230005', 'source' => 'encar', 'sourceName' => 'Encar',
                    'make' => 'Genesis', 'model' => 'G80', 'year' => 2023, 'price' => 52000,
                    'mileage' => 8000, 'damage' => null, 'title' => '',
                    'location' => 'Gangnam, Seoul, Korea',
                    'lotUrl' => 'https://encar.com/car/EN-20230005',
                    'imageUrl' => 'https://images.unsplash.com/photo-1555215695-3004980ad54e?w=400&h=300&fit=crop',
                    'vin' => null, 'auctionDate' => '2024-03-15',
                ],
            ],
        ];

        foreach ($favorites as $fav) {
            Favorite::create([
                'user_id'  => $userId,
                'lot_id'   => $fav['lot_id'],
                'source'   => $fav['source'],
                'lot_data' => $fav['lot_data'],
            ]);
        }

        $this->info('  6 favorites created.');
    }
}
