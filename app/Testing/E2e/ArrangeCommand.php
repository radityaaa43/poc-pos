<?php

namespace App\Testing\E2e;

use Illuminate\Console\Command;

class ArrangeCommand extends Command
{
    protected $signature = 'e2e:arrange {scenario : Scenario name} {--arg=* : key=value pairs for the scenario}';

    protected $description = 'Create test data for one e2e step and print the created ids as JSON (e2e environment only)';

    public function handle(): int
    {
        if ($reason = E2eGuard::check()) {
            $this->error($reason);

            return self::FAILURE;
        }

        $registry = Scenarios::registry();
        $name = $this->argument('scenario');

        if (! isset($registry[$name])) {
            $this->error("Unknown scenario '{$name}'. Known: ".implode(', ', array_keys($registry)));

            return self::FAILURE;
        }

        $options = [];
        foreach ($this->option('arg') as $pair) {
            [$key, $value] = array_pad(explode('=', $pair, 2), 2, '');
            $options[$key] = $value;
        }

        $this->line(json_encode($registry[$name]($options), JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
