// User types
export interface User {
    id: number;
    username: string;
    email: string;
    is_admin: boolean;
    created_at?: string;
    stats?: UserStats;
}

export interface UserStats {
    total_bottles: number;
    current_bottles: number;
    past_bottles: number;
    wantlist_count: number;
}

// Auth types
export interface AuthTokens {
    access_token: string;
    refresh_token: string;
    expires_in: number;
}

export interface LoginResponse {
    ok: boolean;
    access_token: string;
    refresh_token: string;
    token_type: string;
    expires_in: number;
    user: User;
}

// Bottle types
export interface Bottle {
    bottle_id: number;
    wine_id: number | null;
    name: string;
    winery: string;
    region: string;
    country: string;
    grapes: string;
    vintage: number | null;
    type: string;
    style?: string;
    thumb: string;
    price_paid: number | null;
    my_rating: number | null;
    my_review?: string;
    location: string;
    purchase_date?: string;
    drink_from?: string | null;
    drink_to?: string | null;
    past: boolean;
    created_at?: string;
    updated_at?: string;
    catalog_rating?: number;
    catalog_price?: number;
    ai_insights?: AIInsights;
}

export interface AIInsights {
    notes_md: string | null;
    pairings: string[];
    drink_from: string | null;
    drink_to: string | null;
    investability_score: number | null;
}

export interface InventoryResponse {
    ok: boolean;
    page: number;
    pageSize: number;
    total: number;
    totalPages: number;
    items: Bottle[];
}

// Wantlist types
export interface WantlistItem {
    id: number;
    wine_id: number | null;
    name: string;
    winery: string;
    region: string;
    type: string;
    vintage: number | null;
    notes: string;
    created_at: string;
}

export interface WantlistResponse {
    ok: boolean;
    count: number;
    items: WantlistItem[];
}

// API response types
export interface ApiResponse<T = any> {
    ok: boolean;
    error?: string;
    message?: string;
    data?: T;
}

export interface ApiError {
    ok: false;
    error: string;
    message: string;
}
export interface ExpertList{
    key: string;
    label: string;
    count: number;
}
export interface CategoryCount {
    name: string;
    count: number;
}
export interface PriceRange {
    label: string;
    min: number;
    max: number;
    count: number;
}
/**
 * Wine from catalog (for Discover, Expert Lists, Search results)
 * Different from Bottle which is a wine in a user's cellar
 */
export interface Wine {
    id: number;
    wine_id?: number | null;
    name: string;
    winery?: string;
    region?: string;
    country?: string;
    type?: string;
    grapes?: string;
    vintage?: string | number | null;
    price?: number | null;
    image_url?: string;
    // Discover-specific fields
    reason?: string;      // AI recommendation reason
    score?: number;       // Expert list score
    medal?: string;       // Expert list medal (Best in Show, Platinum, etc.)
    rank?: number;        // Expert list ranking
    notes?: string;       // Tasting notes
}
export interface DiscoverStats {
    trending: Wine[];
    newArrivals: Wine[];
    staffPicks: Wine[];
    types: CategoryCount[];
    regions: CategoryCount[];
    priceRanges: PriceRange[];
}
// Navigation types
export type RootStackParamList = {
    Auth: undefined;
    Main: undefined;
    Login: undefined;
    Register: undefined;
    ExpertListDetail: { listKey: string; title: string };
    BrowseCategory: {
        category: 'type' | 'region' | 'price' | 'trending' | 'new_arrivals' | 'staff_picks';
        value: string;
        title: string;
    };
    BottleDetail: { bottleId: number };
    AddBottle: { wineId?: number } | undefined;
    EditBottle: { bottleId: number };
    Search: undefined;
};

export type MainTabParamList = {
    Cellar: undefined;
    Wantlist: undefined;
    Discover: undefined;
    Profile: undefined;
};

// Filter/sort types
export type InventoryStatus = 'current' | 'past' | 'all';
export type SortField = 'vintage' | 'name' | 'added' | 'rating' | 'winery' | 'region';
export type SortOrder = 'asc' | 'desc';

export interface InventoryFilters {
    status: InventoryStatus;
    search: string;
    type: string;
    sort: SortField;
    order: SortOrder;
}