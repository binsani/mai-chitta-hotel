<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Services\PublicDiscoveryService;
use App\Services\SettingsService;
use Database\Seeders\ConfirmedHotelContactSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConfirmedHotelContactTest extends TestCase
{
    use RefreshDatabase;

    public function test_confirmed_contacts_replace_cached_details_without_changing_other_settings(): void
    {
        Setting::create(['group' => 'hotel', 'key' => 'email', 'value' => 'reception@example.test', 'is_public' => true]);
        app(SettingsService::class)->all();
        app(PublicDiscoveryService::class)->forPath('/');

        $this->seed(ConfirmedHotelContactSeeder::class);
        $this->seed(ConfirmedHotelContactSeeder::class);

        $this->getJson('/api/v1/settings/public')->assertOk()
            ->assertJsonPath('data.hotel.phone', '+2349026703916')
            ->assertJsonPath('data.hotel.address', 'Opposite Rahusa Block, Zango')
            ->assertJsonPath('data.hotel.city', 'Zaria')
            ->assertJsonPath('data.hotel.email', 'reception@example.test');

        $discovery = app(PublicDiscoveryService::class)->forPath('/');
        $this->assertSame('+2349026703916', $discovery['graph'][0]['telephone']);
        $this->assertSame('Opposite Rahusa Block, Zango', $discovery['graph'][0]['address']['streetAddress']);
        $this->assertStringContainsString('Opposite Rahusa Block, Zango', $discovery['html']);
        $this->assertDatabaseCount('settings', 6);
    }
}
