<?php

namespace App\Console\Commands;

use App\Models\RatePlan;
use App\Models\Room;
use App\Models\User;
use App\Services\SettingsService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

#[Signature('hotel:check-readiness')]
#[Description('Check production configuration, database, assets, and booking setup without changing data')]
class CheckReadinessCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $checks = [];
        $check = function (string $label, bool $passes, string $fix) use (&$checks): void {
            $checks[] = [$passes ? 'PASS' : 'FAIL', $label, $passes ? '' : $fix];
        };
        $check('Production environment', app()->isProduction(), 'Set APP_ENV=production.');
        $check('Debug disabled', ! config('app.debug'), 'Set APP_DEBUG=false.');
        $check('Encryption key', filled(config('app.key')), 'Preserve the existing production APP_KEY; never regenerate it on an existing installation.');
        $check('Domain URL', rtrim(config('app.url'), '/') === 'https://maichittahotel.com', 'Set APP_URL=https://maichittahotel.com.');
        $check('Secure cookies', (bool) config('session.secure'), 'Set SESSION_SECURE_COOKIE=true.');
        $check('Stateful domain', in_array('maichittahotel.com', config('sanctum.stateful'), true), 'Include maichittahotel.com in SANCTUM_STATEFUL_DOMAINS.');
        $check('Mail transport', ! in_array(config('mail.default'), ['log', 'array'], true), 'Configure a real mail transport and test delivery.');
        $check('Writable storage', is_writable(storage_path()) && is_writable(base_path('bootstrap/cache')), 'Make storage and bootstrap/cache writable by PHP.');
        $check('Public storage link', is_dir(public_path('storage')), 'Run php artisan storage:link.');
        $shell = public_path('spa.html');
        $assetsExist = is_file($shell);
        if ($assetsExist) {
            preg_match_all('~(?:src|href)="(/assets/[^"?]+)~', file_get_contents($shell), $matches);
            $assetsExist = count($matches[1]) > 0;
            foreach ($matches[1] as $asset) {
                $assetsExist = $assetsExist && is_file(public_path(ltrim($asset, '/')));
            }
        }
        $check('Frontend build and assets', $assetsExist, 'Deploy backend/public/spa.html and all referenced assets from the same build.');
        try {
            DB::connection()->getPdo();
            $check('Database connection', true, '');
            $pending = array_diff(array_map(fn ($path) => pathinfo($path, PATHINFO_FILENAME), glob(database_path('migrations/*.php'))), DB::table('migrations')->pluck('migration')->all());
            $check('Database migrations', count($pending) === 0, 'Run php artisan migrate --force.');
            $check('Active administrator', User::where('is_active', true)->whereHas('roles', fn ($q) => $q->where('slug', 'super-admin'))->exists(), 'Run php artisan hotel:create-admin if no administrator exists.');
            $settings = app(SettingsService::class)->all();
            $check('Hotel contact details', filled($settings['hotel']['phone']) && filled($settings['hotel']['email']) && filled($settings['hotel']['address']), 'Complete official phone, email and address in System Settings.');
            $check('Online booking enabled', (bool) $settings['booking']['online_booking_enabled'], 'Enable online booking in Reservation Settings when inventory and rates are ready.');
            $check('Room inventory', Schema::hasTable('rooms') && Room::where('operational_status', 'active')->exists(), 'Add real room types and physical rooms.');
            $check('Active rate plans', RatePlan::where('is_active', true)->exists(), 'Add rate plans and room type rates.');
        } catch (\Throwable) {
            $check('Database and application setup', false, 'Check database credentials and run migrations, then rerun this command.');
        }
        $this->table(['Result', 'Check', 'Action needed'], $checks);
        $this->line('Also verify: cPanel cron runs schedule:run every minute, email delivery, and a complete guest booking and staff check-in/check-out.');

        return collect($checks)->contains(fn ($row) => $row[0] === 'FAIL') ? self::FAILURE : self::SUCCESS;
    }
}
