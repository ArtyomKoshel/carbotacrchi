<?php

namespace Database\Factories;

use App\Models\LotChange;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<LotChange> */
class LotChangeFactory extends Factory
{
    protected $model = LotChange::class;

    public function definition(): array
    {
        return [
            'lot_id'      => fn () => LotFactory::new()->create()->id,
            'source'      => 'encar',
            'event'       => 'update',
            'changes'     => ['price' => ['old' => 22000000, 'new' => 21500000]],
            'recorded_at' => now()->subHours(2),
        ];
    }

    public function delisted(): static
    {
        return $this->state(['event' => 'delisted', 'changes' => ['is_active' => ['old' => true, 'new' => false]]]);
    }
}
