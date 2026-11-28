// The local e2e app's env, shared by the server (host-up.js) and `php artisan` (cli.js). Real env vars
// win over .env. CI sets its own copy in .github/workflows/tests.yml: change both together.
export const e2eEnv = {
    APP_ENV: 'e2e',
    APP_DEBUG: 'true',
    APP_KEY: 'base64:S5Ec61uZh+3hQWkUe50HADhsBp22LuPDyPcRES3QM5k=', // throwaway: only protects the e2e database
    APP_URL: 'http://localhost:8010',
    DB_CONNECTION: 'mysql',
    DB_HOST: '127.0.0.1',
    DB_PORT: '3306',
    DB_DATABASE: 'point_of_sales_e2e',
    DB_USERNAME: 'root',
    DB_PASSWORD: '',
    SESSION_DRIVER: 'database',
    CACHE_STORE: 'database',
    QUEUE_CONNECTION: 'sync',
    MAIL_MAILER: 'log',
    BCRYPT_ROUNDS: '4',
    INERTIA_SSR_ENABLED: 'false',
    SECURITY_BOT_GUARD_MIN_SUBMIT_SECONDS: '0',
    AUTH_FORGOT_PASSWORD_THROTTLE: '1000,1',
    PHP_CLI_SERVER_WORKERS: '4', // ignored on Windows: PHP's built-in server cannot fork there
};
