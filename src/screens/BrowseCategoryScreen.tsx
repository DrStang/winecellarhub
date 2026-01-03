// src/screens/BrowseCategoryScreen.tsx
// Screen for browsing wines by category (type, region, price, trending, etc.)

import React, { useState, useEffect, useCallback } from 'react';
import {
    View,
    Text,
    StyleSheet,
    FlatList,
    Image,
    TouchableOpacity,
    ActivityIndicator,
    Alert,
    RefreshControl,
} from 'react-native';
import { useRoute, RouteProp } from '@react-navigation/native';
import { Ionicons } from '@expo/vector-icons';
import { colors } from '@/theme';
import {
    browseByCategory,
    getTrendingWines,
    getNewArrivals,
    getStaffPicks,
    addToCellar,
    addToWantlist,
} from '@/services/discoverApi';
import type { Wine, RootStackParamList } from '@/types';
import {getImageUrl} from "@/config/api";

type RouteProps = RouteProp<RootStackParamList, 'BrowseCategory'>;

// Wine type colors
const typeColors: Record<string, string> = {
    red: '#dc2626',
    white: '#fbbf24',
    rose: '#f43f5e',
    rosé: '#f43f5e',
    sparkling: '#fbbf24',
    dessert: '#a855f7',
    fortified: '#78350f',
};

// Wine card component
const WineCard: React.FC<{
    wine: Wine;
    onAddToCellar: () => void;
    onAddToWantlist: () => void;
}> = ({ wine, onAddToCellar, onAddToWantlist }) => (
    <View style={styles.wineCard}>
        <Image
            source={{
                uri: getImageUrl(wine.image_url) || 'https://winecellarhub.com/assets/placeholder-bottle.png',
            }}
            style={styles.wineImage}
            resizeMode="contain"
        />
        <View style={styles.wineInfo}>
            <Text style={styles.wineName} numberOfLines={2}>
                {wine.name}
            </Text>

            {wine.winery && (
                <Text style={styles.wineWinery} numberOfLines={1}>
                    {wine.winery}
                </Text>
            )}

            <View style={styles.wineMeta}>
                {wine.vintage && <Text style={styles.metaText}>{wine.vintage}</Text>}
                {wine.type && (
                    <View
                        style={[
                            styles.typeBadge,
                            { backgroundColor: typeColors[wine.type.toLowerCase()] || colors.gray },
                        ]}
                    >
                        <Text style={styles.typeBadgeText}>{wine.type}</Text>
                    </View>
                )}
            </View>

            {wine.region && (
                <Text style={styles.wineRegion} numberOfLines={1}>
                    📍 {wine.region}
                    {wine.country ? `, ${wine.country}` : ''}
                </Text>
            )}

            {wine.reason && (
                <Text style={styles.wineReason} numberOfLines={2}>
                    💡 {wine.reason}
                </Text>
            )}

            <View style={styles.cardFooter}>
                {wine.price ? (
                    <Text style={styles.winePrice}>${Number(wine.price).toFixed(2)}</Text>
                ) : (
                    <View />
                )}

                <View style={styles.actionButtons}>
                    <TouchableOpacity style={styles.actionButton} onPress={onAddToCellar}>
                        <Ionicons name="add-circle" size={28} color={colors.primary} />
                    </TouchableOpacity>
                    <TouchableOpacity style={styles.actionButton} onPress={onAddToWantlist}>
                        <Ionicons name="heart" size={26} color="#f43f5e" />
                    </TouchableOpacity>
                </View>
            </View>
        </View>
    </View>
);

// Sort options
type SortOption = 'relevance' | 'price_asc' | 'price_desc' | 'name';

const sortOptions: { value: SortOption; label: string }[] = [
    { value: 'relevance', label: 'Relevance' },
    { value: 'price_asc', label: 'Price: Low to High' },
    { value: 'price_desc', label: 'Price: High to Low' },
    { value: 'name', label: 'Name A-Z' },
];

// Main screen
const BrowseCategoryScreen: React.FC = () => {
    const route = useRoute<RouteProps>();
    const { category, value } = route.params;

    const [wines, setWines] = useState<Wine[]>([]);
    const [total, setTotal] = useState(0);
    const [page, setPage] = useState(1);
    const [loading, setLoading] = useState(true);
    const [loadingMore, setLoadingMore] = useState(false);
    const [refreshing, setRefreshing] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [sortBy, setSortBy] = useState<SortOption>('relevance');
    const [showSortMenu, setShowSortMenu] = useState(false);

    const LIMIT = 20;

    const loadWines = useCallback(
        async (pageNum: number = 1, append: boolean = false) => {
            try {
                setError(null);

                let results: Wine[] = [];
                let totalCount = 0;

                // Handle special category types
                if (category === 'trending') {
                    results = await getTrendingWines(50);
                    totalCount = results.length;
                } else if (category === 'new_arrivals') {
                    results = await getNewArrivals(50);
                    totalCount = results.length;
                } else if (category === 'staff_picks') {
                    results = await getStaffPicks(50);
                    totalCount = results.length;
                } else {
                    // Standard browse by category
                    const data = await browseByCategory(category, value, {
                        page: pageNum,
                        limit: LIMIT,
                    });
                    results = data.wines;
                    totalCount = data.total;
                }

                // Apply client-side sorting if needed
                if (sortBy !== 'relevance') {
                    results = [...results].sort((a, b) => {
                        switch (sortBy) {
                            case 'price_asc':
                                return (a.price || 0) - (b.price || 0);
                            case 'price_desc':
                                return (b.price || 0) - (a.price || 0);
                            case 'name':
                                return (a.name || '').localeCompare(b.name || '');
                            default:
                                return 0;
                        }
                    });
                }

                if (append) {
                    setWines((prev) => [...prev, ...results]);
                } else {
                    setWines(results);
                }
                setTotal(totalCount);
                setPage(pageNum);
            } catch (err) {
                console.error('Failed to load wines:', err);
                setError('Failed to load wines. Pull to refresh.');
            } finally {
                setLoading(false);
                setLoadingMore(false);
                setRefreshing(false);
            }
        },
        [category, value, sortBy]
    );

    useEffect(() => {
        setLoading(true);
        loadWines(1, false);
    }, [loadWines]);

    const onRefresh = useCallback(() => {
        setRefreshing(true);
        loadWines(1, false);
    }, [loadWines]);

    const onLoadMore = () => {
        if (
            !loadingMore &&
            wines.length < total &&
            !['trending', 'new_arrivals', 'staff_picks'].includes(category)
        ) {
            setLoadingMore(true);
            loadWines(page + 1, true);
        }
    };

    const handleAddToCellar = async (wine: Wine) => {
        try {
            await addToCellar(wine);
            Alert.alert('Added!', `${wine.name} added to your cellar.`);
        } catch (err) {
            Alert.alert('Error', 'Could not add to cellar. Please try again.');
        }
    };

    const handleAddToWantlist = async (wine: Wine) => {
        try {
            await addToWantlist(wine);
            Alert.alert('Added!', `${wine.name} added to your wantlist.`);
        } catch (err) {
            Alert.alert('Error', 'Could not add to wantlist. Please try again.');
        }
    };

    const handleSortChange = (option: SortOption) => {
        setSortBy(option);
        setShowSortMenu(false);
        setLoading(true);
        loadWines(1, false);
    };

    if (loading) {
        return (
            <View style={styles.loadingContainer}>
                <ActivityIndicator size="large" color={colors.primary} />
                <Text style={styles.loadingText}>Loading wines...</Text>
            </View>
        );
    }

    if (error && wines.length === 0) {
        return (
            <View style={styles.errorContainer}>
                <Ionicons name="alert-circle-outline" size={48} color={colors.gray} />
                <Text style={styles.errorText}>{error}</Text>
                <TouchableOpacity style={styles.retryButton} onPress={() => loadWines(1, false)}>
                    <Text style={styles.retryButtonText}>Retry</Text>
                </TouchableOpacity>
            </View>
        );
    }

    return (
        <View style={styles.container}>
            <View style={styles.header}>
                <Text style={styles.countText}>
                    {wines.length}
                    {total > wines.length ? ` of ${total}` : ''} wines
                </Text>

                <TouchableOpacity
                    style={styles.sortButton}
                    onPress={() => setShowSortMenu(!showSortMenu)}
                >
                    <Ionicons name="swap-vertical" size={18} color={colors.text} />
                    <Text style={styles.sortButtonText}>
                        {sortOptions.find((o) => o.value === sortBy)?.label || 'Sort'}
                    </Text>
                    <Ionicons
                        name={showSortMenu ? 'chevron-up' : 'chevron-down'}
                        size={16}
                        color={colors.gray}
                    />
                </TouchableOpacity>
            </View>

            {showSortMenu && (
                <View style={styles.sortMenu}>
                    {sortOptions.map((option) => (
                        <TouchableOpacity
                            key={option.value}
                            style={[
                                styles.sortMenuItem,
                                sortBy === option.value && styles.sortMenuItemActive,
                            ]}
                            onPress={() => handleSortChange(option.value)}
                        >
                            <Text
                                style={[
                                    styles.sortMenuItemText,
                                    sortBy === option.value && styles.sortMenuItemTextActive,
                                ]}
                            >
                                {option.label}
                            </Text>
                            {sortBy === option.value && (
                                <Ionicons name="checkmark" size={18} color={colors.primary} />
                            )}
                        </TouchableOpacity>
                    ))}
                </View>
            )}

            <FlatList
                data={wines}
                keyExtractor={(item, index) => `${item.id}-${index}`}
                renderItem={({ item }) => (
                    <WineCard
                        wine={item}
                        onAddToCellar={() => handleAddToCellar(item)}
                        onAddToWantlist={() => handleAddToWantlist(item)}
                    />
                )}
                contentContainerStyle={styles.listContent}
                refreshControl={
                    <RefreshControl refreshing={refreshing} onRefresh={onRefresh} tintColor={colors.primary} />
                }
                onEndReached={onLoadMore}
                onEndReachedThreshold={0.5}
                ListFooterComponent={
                    loadingMore ? (
                        <View style={styles.loadingMore}>
                            <ActivityIndicator size="small" color={colors.primary} />
                        </View>
                    ) : null
                }
                ListEmptyComponent={
                    <View style={styles.emptyContainer}>
                        <Ionicons name="wine-outline" size={48} color={colors.gray} />
                        <Text style={styles.emptyText}>No wines found</Text>
                        <Text style={styles.emptyHint}>Try a different category or filter</Text>
                    </View>
                }
            />
        </View>
    );
};

const styles = StyleSheet.create({
    container: {
        flex: 1,
        backgroundColor: colors.background,
    },
    loadingContainer: {
        flex: 1,
        justifyContent: 'center',
        alignItems: 'center',
        backgroundColor: colors.background,
    },
    loadingText: {
        marginTop: 12,
        fontSize: 16,
        color: colors.gray,
    },
    errorContainer: {
        flex: 1,
        justifyContent: 'center',
        alignItems: 'center',
        backgroundColor: colors.background,
        padding: 24,
    },
    errorText: {
        marginTop: 12,
        fontSize: 16,
        color: colors.text,
        textAlign: 'center',
    },
    retryButton: {
        marginTop: 16,
        paddingHorizontal: 24,
        paddingVertical: 12,
        backgroundColor: colors.primary,
        borderRadius: 8,
    },
    retryButtonText: {
        color: colors.white,
        fontSize: 16,
        fontWeight: '600',
    },
    header: {
        flexDirection: 'row',
        justifyContent: 'space-between',
        alignItems: 'center',
        backgroundColor: colors.white,
        paddingHorizontal: 16,
        paddingVertical: 12,
        borderBottomWidth: 1,
        borderBottomColor: colors.border,
    },
    countText: {
        fontSize: 14,
        color: colors.gray,
    },
    sortButton: {
        flexDirection: 'row',
        alignItems: 'center',
        paddingHorizontal: 12,
        paddingVertical: 8,
        backgroundColor: colors.background,
        borderRadius: 8,
        borderWidth: 1,
        borderColor: colors.border,
    },
    sortButtonText: {
        fontSize: 14,
        color: colors.text,
        marginHorizontal: 6,
    },
    sortMenu: {
        position: 'absolute',
        top: 52,
        right: 16,
        backgroundColor: colors.white,
        borderRadius: 12,
        shadowColor: '#000',
        shadowOffset: { width: 0, height: 4 },
        shadowOpacity: 0.15,
        shadowRadius: 12,
        elevation: 8,
        zIndex: 100,
        minWidth: 180,
    },
    sortMenuItem: {
        flexDirection: 'row',
        justifyContent: 'space-between',
        alignItems: 'center',
        paddingHorizontal: 16,
        paddingVertical: 14,
        borderBottomWidth: 1,
        borderBottomColor: colors.border,
    },
    sortMenuItemActive: {
        backgroundColor: colors.background,
    },
    sortMenuItemText: {
        fontSize: 15,
        color: colors.text,
    },
    sortMenuItemTextActive: {
        color: colors.primary,
        fontWeight: '600',
    },
    listContent: {
        padding: 16,
    },
    wineCard: {
        flexDirection: 'row',
        backgroundColor: colors.white,
        borderRadius: 12,
        marginBottom: 12,
        shadowColor: '#000',
        shadowOffset: { width: 0, height: 2 },
        shadowOpacity: 0.08,
        shadowRadius: 8,
        elevation: 3,
        overflow: 'hidden',
    },
    wineImage: {
        width: 90,
        height: 140,
        backgroundColor: colors.background,
    },
    wineInfo: {
        flex: 1,
        padding: 12,
    },
    wineName: {
        fontSize: 16,
        fontWeight: '600',
        color: colors.text,
        marginBottom: 2,
    },
    wineWinery: {
        fontSize: 14,
        color: colors.gray,
        marginBottom: 4,
    },
    wineMeta: {
        flexDirection: 'row',
        alignItems: 'center',
        marginBottom: 4,
    },
    metaText: {
        fontSize: 13,
        color: colors.gray,
        marginRight: 8,
    },
    typeBadge: {
        paddingHorizontal: 8,
        paddingVertical: 3,
        borderRadius: 4,
    },
    typeBadgeText: {
        fontSize: 11,
        color: colors.white,
        fontWeight: '600',
        textTransform: 'capitalize',
    },
    wineRegion: {
        fontSize: 13,
        color: colors.gray,
        marginBottom: 4,
    },
    wineReason: {
        fontSize: 12,
        color: colors.primary,
        marginBottom: 4,
        fontStyle: 'italic',
    },
    cardFooter: {
        flexDirection: 'row',
        justifyContent: 'space-between',
        alignItems: 'center',
        marginTop: 'auto',
    },
    winePrice: {
        fontSize: 16,
        fontWeight: '700',
        color: colors.text,
    },
    actionButtons: {
        flexDirection: 'row',
        gap: 8,
    },
    actionButton: {
        padding: 4,
    },
    loadingMore: {
        paddingVertical: 20,
        alignItems: 'center',
    },
    emptyContainer: {
        flex: 1,
        justifyContent: 'center',
        alignItems: 'center',
        paddingVertical: 48,
    },
    emptyText: {
        marginTop: 12,
        fontSize: 18,
        fontWeight: '600',
        color: colors.text,
    },
    emptyHint: {
        marginTop: 4,
        fontSize: 14,
        color: colors.gray,
    },
});

export default BrowseCategoryScreen;