<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StaffPortalDomainTest extends TestCase
{
    use RefreshDatabase;

    public function test_www_staff_links_use_the_primary_domain_and_preserve_reset_parameters(): void
    {
        config(['app.url' => 'https://maichittahotel.com']);

        $this->get('https://www.maichittahotel.com/admin/login')
            ->assertRedirect('https://maichittahotel.com/admin/login');
        $this->get('https://www.maichittahotel.com/admin/reset-password?token=sample&email=staff%40example.test')
            ->assertRedirect('https://maichittahotel.com/admin/reset-password?token=sample&email=staff%40example.test')
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->get('https://maichittahotel.com/admin/login')->assertOk();
        $this->get('https://www.maichittahotel.com/')->assertOk();
    }

    public function test_local_staff_portal_does_not_redirect_to_the_live_domain(): void
    {
        config(['app.url' => 'http://localhost']);

        $this->get('/admin/login')->assertOk();
    }
}
