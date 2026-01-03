// API Configuration
// Change this to your production URL when deploying

//const DEV_API_URL = 'http://192.168.4.15:8080';
const DEV_API_URL = 'https://winecellarhub.com';
const PROD_API_URL = 'https://winecellarhub.com'; // Your production domain

const IMAGE_BASE_URL = 'https://winecellarhub.com';
// Automatically detect environment
const isDev = __DEV__;

export const API_CONFIG = {
    BASE_URL: isDev ? DEV_API_URL : PROD_API_URL,
    IMAGE_BASE_URL: IMAGE_BASE_URL,

    // Token settings (should match PHP backend)
    ACCESS_TOKEN_TTL: 900,      // 15 minutes
    REFRESH_TOKEN_TTL: 2592000, // 30 days

    // Request settings
    TIMEOUT: 30000, // 30 seconds

    // Endpoints
    ENDPOINTS: {
        // Auth
        LOGIN: '/api/auth/login.php',
        REFRESH: '/api/auth/refresh.php',
        LOGOUT: '/api/auth/logout.php',
        ME: '/api/auth/me.php',

        // Inventory
        INVENTORY: '/api/v2/inventory.php',
        BOTTLE: '/api/bottle.php',
        ADD_BOTTLE: '/api/v2/add_bottle.php',

        // Wantlist
        WANTLIST: '/api/v2/wantlist.php',
    },
} as const;
export function getImageUrl(path: string | null | undefined): string | null {
    if (!path) return null;

    if (path.startsWith('http://') || path.startsWith('https://')) {
        return path;
    }
    return `${IMAGE_BASE_URL}/${path}`;

}

export type Endpoint = keyof typeof API_CONFIG.ENDPOINTS;