import { API_CONFIG } from '../config/api';
import { TokenStorage } from './storage';
import type {
    LoginResponse,
    User,
    InventoryResponse,
    Bottle,
    WantlistResponse,
    WantlistItem,
    InventoryFilters,
    ApiResponse, Wine, ExpertList,
} from '../types';

/**
 * Catalog wine type for search results
 */
interface CatalogWine {
    id: number;
    wine_id: number | null;
    name: string;
    winery: string;
    region: string;
    country: string;
    grapes: string;
    vintage: number | null;
    type: string;
    style: string;
    image_url: string;
    rating: number | null;
    price: number | null;
}

interface CatalogSearchResponse {
    ok: boolean;
    count: number;
    wines: CatalogWine[];
}

interface LabelAnalysisResponse {
    ok: boolean;
    ai_parsed: {
        name: string;
        winery: string;
        vintage: string;
        grapes: string;
        region: string;
        country: string;
        type: string;
        style: string;
        barcode: string;
    };
    image_path: string | null;
    catalog_matches: CatalogWine[];
}

/**
 * API Service for WineCellarHub
 * Handles all API calls with automatic token refresh
 */
class ApiService {
    private baseUrl: string;
    private isRefreshing = false;
    private refreshPromise: Promise<boolean> | null = null;

    constructor() {
        this.baseUrl = API_CONFIG.BASE_URL;
    }

    /**
     * Set the base URL (useful for testing or environment switching)
     */
    setBaseUrl(url: string): void {
        this.baseUrl = url;
    }

    /**
     * Make an authenticated request with automatic token refresh
     */
    private async request<T>(
        endpoint: string,
        options: RequestInit = {}
    ): Promise<T> {
        const accessToken = await TokenStorage.getAccessToken();

        const headers: HeadersInit = {
            'Content-Type': 'application/json',
            ...(options.headers || {}),
        };

        if (accessToken) {
            (headers as Record<string, string>)['Authorization'] = `Bearer ${accessToken}`;
        }

        const response = await fetch(`${this.baseUrl}${endpoint}`, {
            ...options,
            headers,
        });

        // Handle 401 - try to refresh token
        if (response.status === 401 && accessToken) {
            const refreshed = await this.refreshAccessToken();
            if (refreshed) {
                // Retry the request with new token
                const newToken = await TokenStorage.getAccessToken();
                (headers as Record<string, string>)['Authorization'] = `Bearer ${newToken}`;

                const retryResponse = await fetch(`${this.baseUrl}${endpoint}`, {
                    ...options,
                    headers,
                });

                if (!retryResponse.ok) {
                    const error = await retryResponse.json().catch(() => ({ error: 'Request failed' }));
                    throw new Error(error.message || error.error || 'Request failed');
                }

                return retryResponse.json();
            } else {
                // Refresh failed - clear tokens and throw
                await TokenStorage.clear();
                throw new Error('Session expired. Please login again.');
            }
        }

        if (!response.ok) {
            const error = await response.json().catch(() => ({ error: 'Request failed' }));
            throw new Error(error.message || error.error || `HTTP ${response.status}`);
        }

        return response.json();
    }

    /**
     * Refresh the access token using the refresh token
     */
    private async refreshAccessToken(): Promise<boolean> {
        // Prevent multiple simultaneous refresh attempts
        if (this.isRefreshing) {
            return this.refreshPromise!;
        }

        this.isRefreshing = true;
        this.refreshPromise = this.doRefresh();

        try {
            return await this.refreshPromise;
        } finally {
            this.isRefreshing = false;
            this.refreshPromise = null;
        }
    }

    private async doRefresh(): Promise<boolean> {
        try {
            const refreshToken = await TokenStorage.getRefreshToken();
            if (!refreshToken) {
                return false;
            }

            const response = await fetch(
                `${this.baseUrl}${API_CONFIG.ENDPOINTS.REFRESH}`,
                {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ refresh_token: refreshToken, rotate: true }),
                }
            );

            if (!response.ok) {
                return false;
            }

            const data = await response.json();
            if (data.ok && data.access_token) {
                await TokenStorage.setAccessToken(data.access_token);
                if (data.refresh_token) {
                    await TokenStorage.setRefreshToken(data.refresh_token);
                }
                return true;
            }

            return false;
        } catch (error) {
            console.error('Token refresh failed:', error);
            return false;
        }
    }

    // ============================================
    // Auth endpoints
    // ============================================

    /**
     * Login with username/email and password
     */
    async login(login: string, password: string): Promise<LoginResponse> {
        const response = await fetch(
            `${this.baseUrl}${API_CONFIG.ENDPOINTS.LOGIN}`,
            {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ login, password }),
            }
        );

        const data = await response.json();

        if (!response.ok || !data.ok) {
            throw new Error(data.message || data.error || 'Login failed');
        }

        // Store tokens and user
        await TokenStorage.setTokens(data.access_token, data.refresh_token);
        await TokenStorage.setUser(data.user);

        return data;
    }

    /**
     * Logout - revoke tokens
     */
    async logout(revokeAll = false): Promise<void> {
        try {
            const refreshToken = await TokenStorage.getRefreshToken();
            await this.request(API_CONFIG.ENDPOINTS.LOGOUT, {
                method: 'POST',
                body: JSON.stringify({
                    all: revokeAll,
                    refresh_token: refreshToken,
                }),
            });
        } catch (error) {
            // Ignore logout errors - we're clearing tokens anyway
            console.warn('Logout request failed:', error);
        } finally {
            await TokenStorage.clear();
        }
    }

    /**
     * Get current user profile
     */
    async getMe(): Promise<User> {
        const response = await this.request<{ ok: boolean; user: User }>(
            API_CONFIG.ENDPOINTS.ME
        );
        return response.user;
    }

    /**
     * Check if we have valid stored credentials
     */
    async checkAuth(): Promise<User | null> {
        const hasTokens = await TokenStorage.hasTokens();
        if (!hasTokens) {
            return null;
        }

        try {
            return await this.getMe();
        } catch (error) {
            // Auth check failed - clear tokens
            await TokenStorage.clear();
            return null;
        }
    }

    // ============================================
    // Inventory endpoints
    // ============================================

    /**
     * Get inventory list with pagination and filters
     */
    async getInventory(
        page = 1,
        filters: Partial<InventoryFilters> = {}
    ): Promise<InventoryResponse> {
        const params = new URLSearchParams({
            page: String(page),
            pageSize: '24',
        });

        if (filters.status) params.set('status', filters.status);
        if (filters.search) params.set('q', filters.search);
        if (filters.type) params.set('type', filters.type);
        if (filters.sort) params.set('sort', filters.sort);
        if (filters.order) params.set('order', filters.order);

        return this.request<InventoryResponse>(
            `${API_CONFIG.ENDPOINTS.INVENTORY}?${params.toString()}`
        );
    }

    /**
     * Get single bottle details
     */
    async getBottle(bottleId: number): Promise<Bottle> {
        const response = await this.request<{ ok: boolean; bottle: Bottle }>(
            `${API_CONFIG.ENDPOINTS.BOTTLE}?id=${bottleId}`
        );
        return response.bottle;
    }

    /**
     * Add a new bottle
     */
    async addBottle(bottle: Partial<Bottle> & { quantity?: number; wine_id?: number; image_url?: string }): Promise<{ bottle_id: number; bottle_ids?: number[] }> {
        const response = await this.request<ApiResponse & { bottle_id: number; bottle_ids?: number[] }>(
            API_CONFIG.ENDPOINTS.ADD_BOTTLE,
            {
                method: 'POST',
                body: JSON.stringify(bottle),
            }
        );
        return { bottle_id: response.bottle_id, bottle_ids: response.bottle_ids };
    }

    /**
     * Update a bottle
     */
    async updateBottle(bottleId: number, updates: Partial<Bottle>): Promise<void> {
        await this.request(`${API_CONFIG.ENDPOINTS.BOTTLE}?id=${bottleId}`, {
            method: 'PUT',
            body: JSON.stringify(updates),
        });
    }

    /**
     * Delete a bottle
     */
    async deleteBottle(bottleId: number): Promise<void> {
        await this.request(`${API_CONFIG.ENDPOINTS.BOTTLE}?id=${bottleId}`, {
            method: 'DELETE',
        });
    }

    /**
     * Toggle bottle past/current status
     */
    async toggleBottlePast(bottleId: number): Promise<void> {
        await this.request(`${API_CONFIG.ENDPOINTS.BOTTLE}?id=${bottleId}`, {
            method: 'POST',
            body: JSON.stringify({ action: 'toggle_past' }),
        });
    }

    // ============================================
    // Wantlist endpoints
    // ============================================

    /**
     * Get wantlist
     */
    async getWantlist(): Promise<WantlistResponse> {
        return this.request<WantlistResponse>(API_CONFIG.ENDPOINTS.WANTLIST);
    }

    /**
     * Add to wantlist
     */
    async addToWantlist(item: Partial<WantlistItem>): Promise<{ id: number }> {
        const response = await this.request<ApiResponse & { id: number }>(
            API_CONFIG.ENDPOINTS.WANTLIST,
            {
                method: 'POST',
                body: JSON.stringify({ action: 'add', ...item }),
            }
        );
        return { id: response.id };
    }

    /**
     * Remove from wantlist
     */
    async removeFromWantlist(id: number): Promise<void> {
        await this.request(`${API_CONFIG.ENDPOINTS.WANTLIST}?id=${id}`, {
            method: 'DELETE',
        });
    }

    /**
     * Move wantlist item to inventory
     */
    async moveToInventory(wantlistId: number): Promise<{ bottle_id: number }> {
        const response = await this.request<ApiResponse & { bottle_id: number }>(
            API_CONFIG.ENDPOINTS.WANTLIST,
            {
                method: 'POST',
                body: JSON.stringify({ action: 'move_to_inventory', id: wantlistId }),
            }
        );
        return { bottle_id: response.bottle_id };
    }
    // ============================================
    // Discover endpoints
    // ============================================

    /**
     * AI Natural Language Search
     */
    async searchNaturalLanguage(query: string): Promise<{ results: Wine[] }> {
        return this.request<{ results: Wine[] }>(
            `/api/v2/search_nlq.php?q=${encodeURIComponent(query)}`
        );
    }

    /**
     * Get expert list tabs
     */
    async getExpertListTabs(): Promise<{ tabs: ExpertList[] }> {
        return this.request<{ tabs: ExpertList[] }>(
            '/api/expert_lists.php?action=tabs'
        );
    }

    /**
     * Get wines from a specific expert list
     */
    async getExpertListWines(
        listKey: string,
        options?: { type?: string; limit?: number }
    ): Promise<{ wines: Wine[]; subtitle: string }> {
        const params = new URLSearchParams({ t: listKey });
        if (options?.type) params.append('type', options.type);
        if (options?.limit) params.append('limit', options.limit.toString());

        return this.request<{ wines: Wine[]; subtitle: string }>(
            `/api/expert_lists.php?${params.toString()}`
        );
    }

    /**
     * Get all discover stats (trending, new arrivals, staff picks, categories)
     */
    async getDiscoverStats(): Promise<{
        trending: Wine[];
        new_arrivals: Wine[];
        staff_picks: Wine[];
        types: { name: string; count: number }[];
        regions: { name: string; count: number }[];
        price_ranges: { label: string; min: number; max: number; count: number }[];
    }> {
        return this.request('/api/discover.php?action=stats');
    }

    /**
     * Get trending wines
     */
    async getTrendingWines(limit: number = 10): Promise<{ wines: Wine[] }> {
        return this.request<{ wines: Wine[] }>(
            `/api/discover.php?action=trending&limit=${limit}`
        );
    }

    /**
     * Get new arrivals
     */
    async getNewArrivals(limit: number = 10): Promise<{ wines: Wine[] }> {
        return this.request<{ wines: Wine[] }>(
            `/api/discover.php?action=new_arrivals&limit=${limit}`
        );
    }

    /**
     * Get staff picks
     */
    async getStaffPicks(limit: number = 10): Promise<{ wines: Wine[] }> {
        return this.request<{ wines: Wine[] }>(
            `/api/discover.php?action=staff_picks&limit=${limit}`
        );
    }

    /**
     * Browse wines by category
     */
    async browseByCategory(
        category: 'type' | 'region' | 'price',
        value: string,
        options?: { page?: number; limit?: number }
    ): Promise<{ wines: Wine[]; total: number }> {
        const params = new URLSearchParams({
            action: 'browse',
            category,
            value,
        });
        if (options?.page) params.append('page', options.page.toString());
        if (options?.limit) params.append('limit', options.limit.toString());

        return this.request<{ wines: Wine[]; total: number }>(
            `/api/discover.php?${params.toString()}`
        );
    }


    // ============================================
    // Catalog search endpoints
    // ============================================

    /**
     * Search the wine catalog
     */
    async searchCatalog(query: string, limit = 25): Promise<CatalogSearchResponse> {
        const params = new URLSearchParams({
            q: query,
            limit: String(limit),
        });

        return this.request<CatalogSearchResponse>(
            `/api/v2/catalog_search.php?${params.toString()}`
        );
    }

    /**
     * Search catalog with multiple fields
     */
    async searchCatalogAdvanced(fields: {
        name?: string;
        winery?: string;
        vintage?: string;
        region?: string;
        grapes?: string;
        type?: string;
    }, limit = 25): Promise<CatalogSearchResponse> {
        const params = new URLSearchParams();

        if (fields.name) params.set('name', fields.name);
        if (fields.winery) params.set('winery', fields.winery);
        if (fields.vintage) params.set('vintage', fields.vintage);
        if (fields.region) params.set('region', fields.region);
        if (fields.grapes) params.set('grapes', fields.grapes);
        if (fields.type) params.set('type', fields.type);
        params.set('limit', String(limit));

        return this.request<CatalogSearchResponse>(
            `/api/v2/catalog_search.php?${params.toString()}`
        );
    }

    /**
     * Analyze a wine label image with AI
     */
    async analyzeLabel(imageBase64: string): Promise<LabelAnalysisResponse> {
        return this.request<LabelAnalysisResponse>(
            '/api/v2/analyze_label.php',
            {
                method: 'POST',
                body: JSON.stringify({ image_base64: imageBase64 }),
            }
        );
    }
}

// Export singleton instance
export const api = new ApiService();