import js from '@eslint/js';
import globals from 'globals';
import tseslint from 'typescript-eslint';
import reactHooks from 'eslint-plugin-react-hooks';
import reactRefresh from 'eslint-plugin-react-refresh';
import jsxA11y from 'eslint-plugin-jsx-a11y';

/**
 * Frontend lint rules.
 *
 * Accessibility rules are ERRORS, not warnings. The WCAG target in the brief
 * is a requirement, and a warning is a rule that gets ignored under deadline.
 */
export default tseslint.config(
    {
        ignores: ['public/**', 'vendor/**', 'node_modules/**', 'storage/**', 'bootstrap/ssr/**'],
    },

    js.configs.recommended,
    ...tseslint.configs.strictTypeChecked,
    ...tseslint.configs.stylisticTypeChecked,

    {
        files: ['resources/js/**/*.{ts,tsx}'],

        languageOptions: {
            ecmaVersion: 2023,
            globals: { ...globals.browser },
            parserOptions: {
                projectService: true,
                tsconfigRootDir: import.meta.dirname,
            },
        },

        plugins: {
            'react-hooks': reactHooks,
            'react-refresh': reactRefresh,
            'jsx-a11y': jsxA11y,
        },

        rules: {
            ...reactHooks.configs.recommended.rules,
            ...jsxA11y.configs.strict.rules,

            'react-refresh/only-export-components': ['warn', { allowConstantExport: true }],

            // The backend is authoritative and sends decimal strings. A stray
            // parseFloat on an amount is exactly the bug this codebase exists
            // to make impossible, so make it visible in review.
            'no-restricted-globals': [
                'error',
                {
                    name: 'parseFloat',
                    message:
                        'Money arrives as a decimal string and is formatted, never parsed. Use Utils/money.',
                },
            ],
            'no-restricted-properties': [
                'error',
                {
                    object: 'Number',
                    property: 'parseFloat',
                    message:
                        'Money arrives as a decimal string and is formatted, never parsed. Use Utils/money.',
                },
            ],

            // Inertia pages export a default component and, occasionally, a
            // layout — that is the framework's contract, not an anti-pattern.
            '@typescript-eslint/no-misused-promises': [
                'error',
                { checksVoidReturn: { attributes: false } },
            ],
            '@typescript-eslint/restrict-template-expressions': [
                'error',
                { allowNumber: true },
            ],
            '@typescript-eslint/no-unnecessary-condition': 'off',

            // `onClick={() => setOpen(false)}` is idiomatic React. Forcing
            // braces around every void-returning handler is noise without a
            // corresponding bug it prevents.
            '@typescript-eslint/no-confusing-void-expression': 'off',
        },
    },

    {
        // Config files run in Node, not the browser.
        files: ['*.config.{js,ts}', 'vite.config.ts'],
        languageOptions: { globals: { ...globals.node } },
        ...tseslint.configs.disableTypeChecked,
    },
);
