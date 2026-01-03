import * as SecureStore from 'expo-secure-store';

const KEYS = {
    ACCESS_TOKEN: 'wch_access_token',
    REFRESH_TOKEN: 'wch_refresh_token',
    USER: 'wch_user',
} as const;

/**
 * Secure storage service using expo-secure-store
 * Data is encrypted and stored in the device's keychain (iOS) or keystore (Android)
 */
export const TokenStorage = {
    /**
     * Store access token
     */
    async setAccessToken(token: string): Promise<void> {
        await SecureStore.setItemAsync(KEYS.ACCESS_TOKEN, token);
    },

    /**
     * Get access token
     */
    async getAccessToken(): Promise<string | null> {
        return SecureStore.getItemAsync(KEYS.ACCESS_TOKEN);
    },

    /**
     * Store refresh token
     */
    async setRefreshToken(token: string): Promise<void> {
        await SecureStore.setItemAsync(KEYS.REFRESH_TOKEN, token);
    },

    /**
     * Get refresh token
     */
    async getRefreshToken(): Promise<string | null> {
        return SecureStore.getItemAsync(KEYS.REFRESH_TOKEN);
    },

    /**
     * Store both tokens at once
     */
    async setTokens(accessToken: string, refreshToken: string): Promise<void> {
        await Promise.all([
            SecureStore.setItemAsync(KEYS.ACCESS_TOKEN, accessToken),
            SecureStore.setItemAsync(KEYS.REFRESH_TOKEN, refreshToken),
        ]);
    },

    /**
     * Get both tokens
     */
    async getTokens(): Promise<{ accessToken: string | null; refreshToken: string | null }> {
        const [accessToken, refreshToken] = await Promise.all([
            SecureStore.getItemAsync(KEYS.ACCESS_TOKEN),
            SecureStore.getItemAsync(KEYS.REFRESH_TOKEN),
        ]);
        return { accessToken, refreshToken };
    },

    /**
     * Store user data (JSON serialized)
     */
    async setUser(user: object): Promise<void> {
        await SecureStore.setItemAsync(KEYS.USER, JSON.stringify(user));
    },

    /**
     * Get user data
     */
    async getUser<T = object>(): Promise<T | null> {
        const data = await SecureStore.getItemAsync(KEYS.USER);
        if (!data) return null;
        try {
            return JSON.parse(data) as T;
        } catch {
            return null;
        }
    },

    /**
     * Clear all stored auth data (logout)
     */
    async clear(): Promise<void> {
        await Promise.all([
            SecureStore.deleteItemAsync(KEYS.ACCESS_TOKEN),
            SecureStore.deleteItemAsync(KEYS.REFRESH_TOKEN),
            SecureStore.deleteItemAsync(KEYS.USER),
        ]);
    },

    /**
     * Check if we have stored tokens
     */
    async hasTokens(): Promise<boolean> {
        const accessToken = await SecureStore.getItemAsync(KEYS.ACCESS_TOKEN);
        return accessToken !== null;
    },
};