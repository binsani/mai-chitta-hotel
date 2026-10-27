<?php

namespace Database\Seeders;

use App\Models\Setting;
use App\Services\CmsService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class ConfirmedHotelContactSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {
            foreach ([
                'phone' => '+2349026703916',
                'address' => 'Opposite Rahusa Block, Zango',
                'city' => 'Zaria',
                'state' => 'Kaduna State',
                'country' => 'Nigeria',
            ] as $key => $value) {
                Setting::updateOrCreate(
                    ['group' => 'hotel', 'key' => $key],
                    ['value' => $value, 'is_public' => true],
                );
            }
        });

        Cache::forget('settings.all');
        CmsService::bumpPublicCache();
    }
}
