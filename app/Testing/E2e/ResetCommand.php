<?php

namespace App\Testing\E2e;

use Database\Seeders\E2eSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ResetCommand extends Command
{
    protected $signature = 'e2e:reset';

    protected $description = 'Rebuild the e2e database from scratch (e2e environment only)';

    public function handle(): int
    {
        if ($reason = E2eGuard::check()) {
            $this->error($reason);

            return self::FAILURE;
        }

        $this->createDatabaseIfMissing();

        $this->callSilently('migrate:fresh', ['--force' => true]);
        $this->callSilently('db:seed', ['--force' => true]);
        $this->callSilently('db:seed', ['--class' => E2eSeeder::class, '--force' => true]);

        $this->info('e2e database reset: '.config('database.connections.'.config('database.default').'.database'));

        return self::SUCCESS;
    }

    /**
     * CI creates the database through the MySQL service; locally it does not exist on first run.
     * The name was validated by the guard, so interpolating it is safe.
     */
    private function createDatabaseIfMissing(): void
    {
        $connection = config('database.default');

        if (config("database.connections.{$connection}.driver") !== 'mysql') {
            return;
        }

        $name = config("database.connections.{$connection}.database");

        config(["database.connections.{$connection}.database" => null]);
        DB::purge($connection);
        DB::connection($connection)->statement("CREATE DATABASE IF NOT EXISTS `{$name}`");

        config(["database.connections.{$connection}.database" => $name]);
        DB::purge($connection);
    }
}
