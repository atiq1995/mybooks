/// <reference types="vite/client" />

/**
 * Environment variables exposed to the browser bundle.
 *
 * Only VITE_-prefixed variables reach the client. Declaring them here turns
 * `import.meta.env.VITE_APP_NAME` from `any` into `string`, so a typo in the
 * name is a compile error rather than an `undefined` in the page title.
 */
interface ImportMetaEnv {
    readonly VITE_APP_NAME: string;
    readonly VITE_DEV_SERVER_URL?: string;
}

interface ImportMeta {
    readonly env: ImportMetaEnv;
}
