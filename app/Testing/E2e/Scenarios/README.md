# Group scenarios

One class per feature group, e.g. `Purchasing.php` → `App\Testing\E2e\Scenarios\Purchasing` with
`public static function registry(): array` returning `'name' => fn (array $options): array`, like
`../Scenarios.php`. `Scenarios::registry()` loads every class here; names must be unique across
groups (prefix them with the group, e.g. `purchasing.pending-po`).
