// src/services/discoverApi.ts
// Wrapper functions for Discover tab features
// These call the api service methods and simplify the return types

import { api } from './api';

// Re-export types for convenience
export type { Wine } from '../types';

// Types used by Discover screens
export interface ExpertList {
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

export interface DiscoverStats {
    trending: Wine[];
    newArrivals: Wine[];
    staffPicks: Wine[];
    types: CategoryCount[];
    regions: CategoryCount[];
    priceRanges: PriceRange[];
}

// Import Wine type
import type { Wine } from '../types';

// ============================================================================
// DISCOVER API FUNCTIONS
// ============================================================================

/**
 * AI Natural Language Search
 */
export async function searchNaturalLanguage(query: string): Promise<Wine[]> {
    const data = await api.searchNaturalLanguage(query);
    return data.results || [];
}

/**
 * Get available expert lists (tabs)
 */
export async function getExpertListTabs(): Promise<ExpertList[]> {
    const data = await api.getExpertListTabs();
    return data.tabs || [];
}

/**
 * Get wines from a specific expert list
 */
export async function getExpertListWines(
    listKey: string,
    options?: { type?: string; limit?: number }
): Promise<{ wines: Wine[]; subtitle: string }> {
    return api.getExpertListWines(listKey, options);
}

/**
 * Get trending wines
 */
export async function getTrendingWines(limit: number = 10): Promise<Wine[]> {
    const data = await api.getTrendingWines(limit);
    return data.wines || [];
}

/**
 * Get new arrivals
 */
export async function getNewArrivals(limit: number = 10): Promise<Wine[]> {
    const data = await api.getNewArrivals(limit);
    return data.wines || [];
}

/**
 * Get staff picks
 */
export async function getStaffPicks(limit: number = 10): Promise<Wine[]> {
    const data = await api.getStaffPicks(limit);
    return data.wines || [];
}

/**
 * Browse wines by category
 */
export async function browseByCategory(
    category: 'type' | 'region' | 'price',
    value: string,
    options?: { page?: number; limit?: number }
): Promise<{ wines: Wine[]; total: number }> {
    return api.browseByCategory(category, value, options);
}

/**
 * Get all discover stats in one call
 */
export async function getDiscoverStats(): Promise<DiscoverStats> {
    const data = await api.getDiscoverStats();
    return {
        trending: data.trending || [],
        newArrivals: data.new_arrivals || [],
        staffPicks: data.staff_picks || [],
        types: data.types || [],
        regions: data.regions || [],
        priceRanges: data.price_ranges || [],
    };
}

/**
 * Add wine to cellar (uses existing api.addBottle)
 */
export async function addToCellar(wine: Wine): Promise<{ ok: boolean; bottle_id?: number }> {
    const result = await api.addBottle({
        wine_id: wine.id,
        price: wine.price ?? undefined,
        name: wine.name,
        winery: wine.winery,
        region: wine.region,
        grapes: wine.grapes,
        image_url: wine.image_url,
        type: wine.type,
        vintage: wine.vintage ? String(wine.vintage) : undefined,
    });
    return { ok: true, bottle_id: result.bottle_id };
}

/**
 * Add wine to wantlist (uses existing api.addToWantlist)
 */
export async function addToWantlist(wine: Wine): Promise<{ ok: boolean; id?: number }> {
    const result = await api.addToWantlist({
        wine_id: wine.id,
        name: wine.name,
        winery: wine.winery,
        region: wine.region,
        type: wine.type,
        vintage: wine.vintage ? String(wine.vintage) : undefined,
        image_url: wine.image_url,
    });
    return { ok: true, id: result.id };
}