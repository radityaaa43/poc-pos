<?php

namespace App\Testing\E2e;

/**
 * Last line of defence for destructive e2e commands: they must never touch a real database.
 */
class E2eGuard
{
    /**
     * Returns why the command must refuse to run, or null when it is safe.
     */
    public static function refusal(string $environment, ?string $database): ?string
    {
        if ($environment !== 'e2e') {
            return "Refusing: APP_ENV is '{$environment}', not 'e2e'.";
        }

        if (! is_string($database) || ! preg_match('/^[A-Za-z0-9_]+_e2e$/', $database)) {
            return "Refusing: database '{$database}' does not end in '_e2e'.";
        }

        return null;
    }

    /**
     * Guard for the current app: environment and default connection database.
     */
    public static function check(): ?string
    {
        $connection = config('database.default');

        return self::refusal(app()->environment(), config("database.connections.{$connection}.database"));
    }
}
