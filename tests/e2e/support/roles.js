// Mirrors App\Testing\E2e users seeded by database/seeders/E2eSeeder.php.
export const PASSWORD = 'password';

// Landing page after login depends on the role's permissions (AuthenticatedSessionController).
export const roles = [
    { key: 'super-admin', email: 'superadmin@e2e.test', landing: '/dashboard/transactions' },
    { key: 'manager', email: 'manager@e2e.test', landing: '/dashboard/transactions' },
    { key: 'cashier', email: 'cashier@e2e.test', landing: '/dashboard/transactions' },
    { key: 'stock-opname', email: 'opname@e2e.test', landing: '/dashboard/access' },
    { key: 'dine-orders', email: 'dineorders@e2e.test', landing: '/dashboard/access' },
];

export const authFile = (role) => `tests/e2e/.auth/${role.key}.json`;
