import js from '@eslint/js';
import reactHooks from 'eslint-plugin-react-hooks';
import globals from 'globals';
import { defineConfig, globalIgnores } from 'eslint/config';

export default defineConfig([
    globalIgnores(['public/build/**', 'vendor/**', 'node_modules/**', 'storage/**']),
    {
        files: ['resources/js/**/*.{js,jsx}'],
        extends: [js.configs.recommended],
        languageOptions: {
            globals: { ...globals.browser, route: 'readonly' },
            parserOptions: { ecmaFeatures: { jsx: true } },
        },
        plugins: { 'react-hooks': reactHooks },
        rules: {
            // Capitalised names (JSX components) count as used: no react plugin to track JSX usage.
            'no-unused-vars': ['error', { varsIgnorePattern: '^[A-Z]' }],
            'react-hooks/rules-of-hooks': 'error',
            'react-hooks/exhaustive-deps': 'warn',
        },
    },
    {
        files: ['**/*.test.{js,jsx}'],
        languageOptions: { globals: { ...globals.vitest } },
    },
]);
