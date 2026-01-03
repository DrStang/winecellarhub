// src/screens/DiscoverScreen.tsx
// Main Discover tab with AI Search, Expert Lists, Quick Discovery, and Browse by Category

import React, { useState, useEffect, useCallback } from 'react';
import {
    View,
    Text,
    StyleSheet,
    ScrollView,
    TextInput,
    TouchableOpacity,
    Image,
    ActivityIndicator,
    RefreshControl,
    FlatList,
    Alert,
    Dimensions,
    SafeAreaView,
} from 'react-native';
import { useNavigation } from '@react-navigation/native';
import { NativeStackNavigationProp } from '@react-navigation/native-stack';
import { Ionicons } from '@expo/vector-icons';
import { colors } from '@/theme';
import {
    searchNaturalLanguage,
    getExpertListTabs,
    getDiscoverStats,
    addToCellar,
    addToWantlist,
} from '@/services/discoverApi';
import type { Wine, ExpertList, CategoryCount, RootStackParamList } from '@/types';
import { getImageUrl } from '@/config/api'
const { width: SCREEN_WIDTH } = Dimensions.get('window');

type NavigationProp = NativeStackNavigationProp<RootStackParamList>;

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

// Type icons
const typeIcons: Record<string, string> = {
    red: '🍷',
    white: '🥂',
    rose: '🌸',
    rosé: '🌸',
    sparkling: '🍾',
    dessert: '🍯',
    fortified: '🥃',
};

// ============================================================================
// COMPONENTS
// ============================================================================

// Section Header
const SectionHeader: React.FC<{
    title: string;
    onSeeAll?: () => void;
}> = ({ title, onSeeAll }) => (
    <View style={styles.sectionHeader}>
        <Text style={styles.sectionTitle}>{title}</Text>
        {onSeeAll && (
            <TouchableOpacity onPress={onSeeAll}>
                <Text style={styles.seeAllText}>See All →</Text>
            </TouchableOpacity>
        )}
    </View>
);

// Horizontal Wine Card (for carousels)
const HorizontalWineCard: React.FC<{
    wine: Wine;
    onPress: () => void;
    onAddToCellar: () => void;
    onAddToWantlist: () => void;
    showReason?: boolean;
}> = ({ wine, onPress, onAddToCellar, onAddToWantlist, showReason }) => (
    <TouchableOpacity style={styles.horizontalCard} onPress={onPress}>
        <Image
            source={{
                uri: getImageUrl(wine.image_url) || 'https://winecellarhub.com/assets/placeholder-bottle.png',
            }}
            style={styles.horizontalCardImage}
            resizeMode="contain"
        />
        <View style={styles.horizontalCardContent}>
            <Text style={styles.horizontalCardName} numberOfLines={2}>
                {wine.name}
            </Text>
            {wine.winery && (
                <Text style={styles.horizontalCardWinery} numberOfLines={1}>
                    {wine.winery}
                </Text>
            )}
            <View style={styles.horizontalCardMeta}>
                {wine.vintage && (
                    <Text style={styles.metaText}>{wine.vintage}</Text>
                )}
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
            {wine.price && (
                <Text style={styles.priceText}>${Number(wine.price).toFixed(2)}</Text>
            )}
            {showReason && wine.reason && (
                <Text style={styles.reasonText} numberOfLines={2}>
                    {wine.reason}
                </Text>
            )}
            <View style={styles.cardActions}>
                <TouchableOpacity style={styles.actionButton} onPress={onAddToCellar}>
                    <Ionicons name="add-circle-outline" size={20} color={colors.primary} />
                    <Text style={styles.actionButtonText}>Cellar</Text>
                </TouchableOpacity>
                <TouchableOpacity style={styles.actionButton} onPress={onAddToWantlist}>
                    <Ionicons name="heart-outline" size={20} color="#f43f5e" />
                    <Text style={styles.actionButtonText}>Want</Text>
                </TouchableOpacity>
            </View>
        </View>
    </TouchableOpacity>
);

// Search Result Card (vertical layout)
const SearchResultCard: React.FC<{
    wine: Wine;
    onPress: () => void;
    onAddToCellar: () => void;
    onAddToWantlist: () => void;
}> = ({ wine, onPress, onAddToCellar, onAddToWantlist }) => (
    <TouchableOpacity style={styles.searchResultCard} onPress={onPress}>
        <Image
            source={{
                uri: getImageUrl(wine.image_url) || 'https://winecellarhub.com/assets/placeholder-bottle.png',
            }}
            style={styles.searchResultImage}
            resizeMode="contain"
        />
        <View style={styles.searchResultContent}>
            <Text style={styles.searchResultName} numberOfLines={2}>
                {wine.name}
            </Text>
            {wine.winery && (
                <Text style={styles.searchResultWinery} numberOfLines={1}>
                    {wine.winery}
                </Text>
            )}
            <Text style={styles.searchResultMeta} numberOfLines={1}>
                {[wine.region, wine.vintage, wine.type].filter(Boolean).join(' • ')}
            </Text>
            {wine.reason && (
                <Text style={styles.searchResultReason} numberOfLines={2}>
                    💡 {wine.reason}
                </Text>
            )}
            <View style={styles.searchResultFooter}>
                {wine.price ? (
                    <Text style={styles.searchResultPrice}>${Number(wine.price).toFixed(2)}</Text>
                ) : (
                    <View />
                )}
                <View style={styles.searchResultActions}>
                    <TouchableOpacity
                        style={styles.smallActionButton}
                        onPress={(e) => { e.stopPropagation(); onAddToCellar(); }}
                    >
                        <Ionicons name="add" size={18} color={colors.white} />
                    </TouchableOpacity>
                    <TouchableOpacity
                        style={[styles.smallActionButton, { backgroundColor: '#f43f5e' }]}
                        onPress={(e) => { e.stopPropagation(); onAddToWantlist(); }}
                    >
                        <Ionicons name="heart" size={18} color={colors.white} />
                    </TouchableOpacity>
                </View>
            </View>
        </View>
    </TouchableOpacity>
);

// Expert List Card
const ExpertListCard: React.FC<{
    list: ExpertList;
    onPress: () => void;
}> = ({ list, onPress }) => {
    const getListIcon = (label: string): string => {
        if (label.includes('Decanter')) return '🏆';
        if (label.includes('Spectator')) return '📊';
        if (label.includes('Enthusiast')) return '⭐';
        return '🍷';
    };

    return (
        <TouchableOpacity style={styles.expertListCard} onPress={onPress}>
            <Text style={styles.expertListIcon}>{getListIcon(list.label)}</Text>
            <Text style={styles.expertListLabel} numberOfLines={2}>
                {list.label}
            </Text>
            <Text style={styles.expertListCount}>{list.count} wines</Text>
        </TouchableOpacity>
    );
};

// Category Tile
const CategoryTile: React.FC<{
    icon: string;
    label: string;
    count?: number;
    color?: string;
    onPress: () => void;
}> = ({ icon, label, count, color, onPress }) => (
    <TouchableOpacity
        style={[styles.categoryTile, color ? { borderColor: color } : null]}
        onPress={onPress}
    >
        <Text style={styles.categoryIcon}>{icon}</Text>
        <Text style={styles.categoryLabel}>{label}</Text>
        {count !== undefined && (
            <Text style={styles.categoryCount}>{count.toLocaleString()}</Text>
        )}
    </TouchableOpacity>
);

// ============================================================================
// MAIN SCREEN
// ============================================================================

const DiscoverScreen: React.FC = () => {
    const navigation = useNavigation<NavigationProp>();

    // State
    const [searchQuery, setSearchQuery] = useState('');
    const [searchResults, setSearchResults] = useState<Wine[]>([]);
    const [isSearching, setIsSearching] = useState(false);
    const [isSearchMode, setIsSearchMode] = useState(false);

    const [expertLists, setExpertLists] = useState<ExpertList[]>([]);
    const [trending, setTrending] = useState<Wine[]>([]);
    const [newArrivals, setNewArrivals] = useState<Wine[]>([]);
    const [staffPicks, setStaffPicks] = useState<Wine[]>([]);
    const [types, setTypes] = useState<CategoryCount[]>([]);
    const [regions, setRegions] = useState<CategoryCount[]>([]);

    const [loading, setLoading] = useState(true);
    const [refreshing, setRefreshing] = useState(false);
    const [error, setError] = useState<string | null>(null);

    // Load initial data
    const loadData = useCallback(async () => {
        try {
            setError(null);
            const [tabs, stats] = await Promise.all([
                getExpertListTabs().catch(() => []),
                getDiscoverStats().catch(() => ({
                    trending: [],
                    newArrivals: [],
                    staffPicks: [],
                    types: [],
                    regions: [],
                    priceRanges: [],
                })),
            ]);

            setExpertLists(tabs);
            setTrending(stats.trending);
            setNewArrivals(stats.newArrivals);
            setStaffPicks(stats.staffPicks);
            setTypes(stats.types);
            setRegions(stats.regions);
        } catch (err) {
            console.error('Failed to load discover data:', err);
            setError('Failed to load. Pull to refresh.');
        } finally {
            setLoading(false);
            setRefreshing(false);
        }
    }, []);

    useEffect(() => {
        loadData();
    }, [loadData]);

    const onRefresh = useCallback(() => {
        setRefreshing(true);
        loadData();
    }, [loadData]);

    // Search handler
    const handleSearch = async () => {
        if (!searchQuery.trim()) return;

        setIsSearching(true);
        setIsSearchMode(true);

        try {
            const results = await searchNaturalLanguage(searchQuery);
            setSearchResults(results);
        } catch (err) {
            console.error('Search error:', err);
            Alert.alert('Search Failed', 'Please try again.');
            setSearchResults([]);
        } finally {
            setIsSearching(false);
        }
    };

    const clearSearch = () => {
        setSearchQuery('');
        setSearchResults([]);
        setIsSearchMode(false);
    };

    // Action handlers
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

    const handleExpertListPress = (list: ExpertList) => {
        navigation.navigate('ExpertListDetail', { listKey: list.key, title: list.label });
    };

    const handleCategoryPress = (
        category: 'type' | 'region' | 'price' | 'trending' | 'new_arrivals' | 'staff_picks',
        value: string,
        label: string
    ) => {
        navigation.navigate('BrowseCategory', { category, value, title: label });
    };

    // Loading state
    if (loading) {
        return (
            <View style={styles.loadingContainer}>
                <ActivityIndicator size="large" color={colors.primary} />
                <Text style={styles.loadingText}>Loading discoveries...</Text>
            </View>
        );
    }

    // Error state
    if (error && !trending.length && !newArrivals.length) {
        return (
            <View style={styles.errorContainer}>
                <Ionicons name="alert-circle-outline" size={48} color={colors.error} />
                <Text style={styles.errorText}>{error}</Text>
                <TouchableOpacity style={styles.retryButton} onPress={loadData}>
                    <Text style={styles.retryButtonText}>Retry</Text>
                </TouchableOpacity>
            </View>
        );
    }

    // Search results view
    if (isSearchMode) {
        return (
            <SafeAreaView style={styles.container}>
                <View style={styles.searchContainer}>
                    <View style={styles.searchInputContainer}>
                        <Ionicons name="search" size={20} color={colors.gray} />
                        <TextInput
                            style={styles.searchInput}
                            placeholder="Describe what you're looking for..."
                            placeholderTextColor={colors.gray}
                            value={searchQuery}
                            onChangeText={setSearchQuery}
                            onSubmitEditing={handleSearch}
                            returnKeyType="search"
                            autoFocus
                        />
                        {searchQuery.length > 0 && (
                            <TouchableOpacity onPress={clearSearch}>
                                <Ionicons name="close-circle" size={20} color={colors.gray} />
                            </TouchableOpacity>
                        )}
                    </View>
                    <TouchableOpacity style={styles.cancelButton} onPress={clearSearch}>
                        <Text style={styles.cancelButtonText}>Cancel</Text>
                    </TouchableOpacity>
                </View>

                {isSearching ? (
                    <View style={styles.searchingContainer}>
                        <ActivityIndicator size="large" color={colors.primary} />
                        <Text style={styles.searchingText}>Searching...</Text>
                    </View>
                ) : searchResults.length > 0 ? (
                    <FlatList
                        data={searchResults}
                        keyExtractor={(item) => item.id.toString()}
                        renderItem={({ item }) => (
                            <SearchResultCard
                                wine={item}
                                onPress={() => {}}
                                onAddToCellar={() => handleAddToCellar(item)}
                                onAddToWantlist={() => handleAddToWantlist(item)}
                            />
                        )}
                        contentContainerStyle={styles.searchResultsList}
                    />
                ) : (
                    <View style={styles.noResultsContainer}>
                        <Ionicons name="wine-outline" size={48} color={colors.gray} />
                        <Text style={styles.noResultsText}>No wines found</Text>
                        <Text style={styles.noResultsHint}>
                            Try a different search like "bold red under $50" or "crisp white for seafood"
                        </Text>
                    </View>
                )}
            </SafeAreaView>
        );
    }

    // Main discover view
    return (
        <SafeAreaView style={styles.container}>
            <ScrollView
                contentContainerStyle={styles.scrollContent}
                refreshControl={
                    <RefreshControl refreshing={refreshing} onRefresh={onRefresh} tintColor={colors.primary} />
                }
                showsVerticalScrollIndicator={false}
            >
                {/* 1. AI NATURAL LANGUAGE SEARCH */}
                <View style={styles.searchSection}>
                    <Text style={styles.searchTitle}>🔎 AI-Powered Search</Text>
                    <Text style={styles.searchSubtitle}>
                        Describe what you're looking for in plain English
                    </Text>
                    <TouchableOpacity
                        style={styles.searchInputContainer}
                        onPress={() => setIsSearchMode(true)}
                        activeOpacity={0.8}
                    >
                        <Ionicons name="search" size={20} color={colors.gray} />
                        <Text style={styles.searchPlaceholder}>
                            "Peppery syrah under $30 to drink this fall"
                        </Text>
                    </TouchableOpacity>
                    <View style={styles.searchExamples}>
                        <Text style={styles.searchExamplesLabel}>Try:</Text>
                        <ScrollView horizontal showsHorizontalScrollIndicator={false}>
                            {[
                                'Bold red for steak dinner',
                                'Crisp white under $25',
                                'Celebratory sparkling',
                                'Earthy pinot noir',
                            ].map((example) => (
                                <TouchableOpacity
                                    key={example}
                                    style={styles.exampleChip}
                                    onPress={() => {
                                        setSearchQuery(example);
                                        setIsSearchMode(true);
                                    }}
                                >
                                    <Text style={styles.exampleChipText}>{example}</Text>
                                </TouchableOpacity>
                            ))}
                        </ScrollView>
                    </View>
                </View>

                {/* 2. EXPERT LISTS */}
                {expertLists.length > 0 && (
                    <View style={styles.section}>
                        <SectionHeader title="🏆 Expert Lists" />
                        <ScrollView
                            horizontal
                            showsHorizontalScrollIndicator={false}
                            contentContainerStyle={styles.horizontalScroll}
                        >
                            {expertLists.map((list) => (
                                <ExpertListCard
                                    key={list.key}
                                    list={list}
                                    onPress={() => handleExpertListPress(list)}
                                />
                            ))}
                        </ScrollView>
                    </View>
                )}

                {/* 3. QUICK DISCOVERY - Trending */}
                {trending.length > 0 && (
                    <View style={styles.section}>
                        <SectionHeader
                            title="🔥 Trending"
                            onSeeAll={() => handleCategoryPress('trending', '', 'Trending Wines')}
                        />
                        <ScrollView
                            horizontal
                            showsHorizontalScrollIndicator={false}
                            contentContainerStyle={styles.horizontalScroll}
                        >
                            {trending.map((wine) => (
                                <HorizontalWineCard
                                    key={wine.id}
                                    wine={wine}
                                    onPress={() => {}}
                                    onAddToCellar={() => handleAddToCellar(wine)}
                                    onAddToWantlist={() => handleAddToWantlist(wine)}
                                />
                            ))}
                        </ScrollView>
                    </View>
                )}

                {/* New Arrivals */}
                {newArrivals.length > 0 && (
                    <View style={styles.section}>
                        <SectionHeader
                            title="✨ New Arrivals"
                            onSeeAll={() => handleCategoryPress('new_arrivals', '', 'New Arrivals')}
                        />
                        <ScrollView
                            horizontal
                            showsHorizontalScrollIndicator={false}
                            contentContainerStyle={styles.horizontalScroll}
                        >
                            {newArrivals.map((wine) => (
                                <HorizontalWineCard
                                    key={wine.id}
                                    wine={wine}
                                    onPress={() => {}}
                                    onAddToCellar={() => handleAddToCellar(wine)}
                                    onAddToWantlist={() => handleAddToWantlist(wine)}
                                />
                            ))}
                        </ScrollView>
                    </View>
                )}

                {/* Staff Picks */}
                {staffPicks.length > 0 && (
                    <View style={styles.section}>
                        <SectionHeader
                            title="👨‍🍳 Staff Picks"
                            onSeeAll={() => handleCategoryPress('staff_picks', '', 'Staff Picks')}
                        />
                        <ScrollView
                            horizontal
                            showsHorizontalScrollIndicator={false}
                            contentContainerStyle={styles.horizontalScroll}
                        >
                            {staffPicks.map((wine) => (
                                <HorizontalWineCard
                                    key={wine.id}
                                    wine={wine}
                                    onPress={() => {}}
                                    onAddToCellar={() => handleAddToCellar(wine)}
                                    onAddToWantlist={() => handleAddToWantlist(wine)}
                                    showReason
                                />
                            ))}
                        </ScrollView>
                    </View>
                )}

                {/* 4. BROWSE BY TYPE */}
                {types.length > 0 && (
                    <View style={styles.section}>
                        <SectionHeader title="🗂️ Browse by Type" />
                        <View style={styles.categoryGrid}>
                            {types.map((type) => (
                                <CategoryTile
                                    key={type.name}
                                    icon={typeIcons[type.name.toLowerCase()] || '🍷'}
                                    label={type.name}
                                    count={type.count}
                                    color={typeColors[type.name.toLowerCase()]}
                                    onPress={() => handleCategoryPress('type', type.name, type.name)}
                                />
                            ))}
                        </View>
                    </View>
                )}

                {/* Browse by Region */}
                {regions.length > 0 && (
                    <View style={styles.section}>
                        <SectionHeader title="🌍 Popular Regions" />
                        <View style={styles.categoryGrid}>
                            {regions.slice(0, 8).map((region) => (
                                <CategoryTile
                                    key={region.name}
                                    icon="📍"
                                    label={region.name}
                                    count={region.count}
                                    onPress={() => handleCategoryPress('region', region.name, region.name)}
                                />
                            ))}
                        </View>
                    </View>
                )}

                {/* Browse by Price */}
                <View style={styles.section}>
                    <SectionHeader title="💰 By Price Range" />
                    <View style={styles.categoryGrid}>
                        <CategoryTile
                            icon="💵"
                            label="Under $20"
                            onPress={() => handleCategoryPress('price', '0-20', 'Under $20')}
                        />
                        <CategoryTile
                            icon="💵"
                            label="$20 - $50"
                            onPress={() => handleCategoryPress('price', '20-50', '$20 - $50')}
                        />
                        <CategoryTile
                            icon="💎"
                            label="$50 - $100"
                            onPress={() => handleCategoryPress('price', '50-100', '$50 - $100')}
                        />
                        <CategoryTile
                            icon="👑"
                            label="$100+"
                            onPress={() => handleCategoryPress('price', '100-99999', '$100+')}
                        />
                    </View>
                </View>

                <View style={{ height: 32 }} />
            </ScrollView>
        </SafeAreaView>
    );
};

// ============================================================================
// STYLES
// ============================================================================

const styles = StyleSheet.create({
    container: {
        flex: 1,
        backgroundColor: colors.background,
    },
    scrollContent: {
        paddingBottom: 24,
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

    // Search Section
    searchSection: {
        backgroundColor: colors.white,
        padding: 16,
        borderBottomWidth: 1,
        borderBottomColor: colors.border,
    },
    searchTitle: {
        fontSize: 20,
        fontWeight: '700',
        color: colors.text,
        marginBottom: 4,
    },
    searchSubtitle: {
        fontSize: 14,
        color: colors.gray,
        marginBottom: 12,
    },
    searchContainer: {
        flexDirection: 'row',
        alignItems: 'center',
        paddingHorizontal: 16,
        paddingVertical: 12,
        backgroundColor: colors.white,
        borderBottomWidth: 1,
        borderBottomColor: colors.border,
    },
    searchInputContainer: {
        flex: 1,
        flexDirection: 'row',
        alignItems: 'center',
        backgroundColor: colors.background,
        borderRadius: 12,
        paddingHorizontal: 12,
        paddingVertical: 12,
        borderWidth: 1,
        borderColor: colors.border,
    },
    searchInput: {
        flex: 1,
        marginLeft: 8,
        fontSize: 16,
        color: colors.text,
    },
    searchPlaceholder: {
        flex: 1,
        marginLeft: 8,
        fontSize: 15,
        color: colors.gray,
        fontStyle: 'italic',
    },
    cancelButton: {
        marginLeft: 12,
        paddingVertical: 8,
    },
    cancelButtonText: {
        fontSize: 16,
        color: colors.primary,
        fontWeight: '500',
    },
    searchExamples: {
        flexDirection: 'row',
        alignItems: 'center',
        marginTop: 12,
    },
    searchExamplesLabel: {
        fontSize: 13,
        color: colors.gray,
        marginRight: 8,
    },
    exampleChip: {
        backgroundColor: colors.background,
        paddingHorizontal: 12,
        paddingVertical: 6,
        borderRadius: 16,
        marginRight: 8,
        borderWidth: 1,
        borderColor: colors.border,
    },
    exampleChipText: {
        fontSize: 13,
        color: colors.text,
    },
    searchingContainer: {
        flex: 1,
        justifyContent: 'center',
        alignItems: 'center',
    },
    searchingText: {
        marginTop: 12,
        fontSize: 16,
        color: colors.gray,
    },
    noResultsContainer: {
        flex: 1,
        justifyContent: 'center',
        alignItems: 'center',
        padding: 24,
    },
    noResultsText: {
        marginTop: 12,
        fontSize: 18,
        fontWeight: '600',
        color: colors.text,
    },
    noResultsHint: {
        marginTop: 8,
        fontSize: 14,
        color: colors.gray,
        textAlign: 'center',
    },
    searchResultsList: {
        padding: 16,
    },

    // Sections
    section: {
        marginTop: 24,
    },
    sectionHeader: {
        flexDirection: 'row',
        justifyContent: 'space-between',
        alignItems: 'center',
        paddingHorizontal: 16,
        marginBottom: 12,
    },
    sectionTitle: {
        fontSize: 18,
        fontWeight: '700',
        color: colors.text,
    },
    seeAllText: {
        fontSize: 14,
        color: colors.primary,
        fontWeight: '500',
    },
    horizontalScroll: {
        paddingLeft: 16,
        paddingRight: 8,
    },

    // Horizontal Wine Card
    horizontalCard: {
        width: 160,
        backgroundColor: colors.white,
        borderRadius: 12,
        marginRight: 12,
        shadowColor: '#000',
        shadowOffset: { width: 0, height: 2 },
        shadowOpacity: 0.08,
        shadowRadius: 8,
        elevation: 3,
        overflow: 'hidden',
    },
    horizontalCardImage: {
        width: '100%',
        height: 120,
        backgroundColor: colors.background,
    },
    horizontalCardContent: {
        padding: 10,
    },
    horizontalCardName: {
        fontSize: 14,
        fontWeight: '600',
        color: colors.text,
        marginBottom: 2,
    },
    horizontalCardWinery: {
        fontSize: 12,
        color: colors.gray,
        marginBottom: 4,
    },
    horizontalCardMeta: {
        flexDirection: 'row',
        alignItems: 'center',
        marginBottom: 4,
    },
    metaText: {
        fontSize: 12,
        color: colors.gray,
        marginRight: 6,
    },
    typeBadge: {
        paddingHorizontal: 6,
        paddingVertical: 2,
        borderRadius: 4,
    },
    typeBadgeText: {
        fontSize: 10,
        color: colors.white,
        fontWeight: '600',
        textTransform: 'capitalize',
    },
    priceText: {
        fontSize: 14,
        fontWeight: '700',
        color: colors.primary,
        marginBottom: 4,
    },
    reasonText: {
        fontSize: 11,
        color: colors.gray,
        fontStyle: 'italic',
        marginBottom: 4,
    },
    cardActions: {
        flexDirection: 'row',
        justifyContent: 'space-between',
        marginTop: 4,
    },
    actionButton: {
        flexDirection: 'row',
        alignItems: 'center',
        paddingVertical: 4,
    },
    actionButtonText: {
        fontSize: 12,
        color: colors.gray,
        marginLeft: 4,
    },

    // Search Result Card
    searchResultCard: {
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
    searchResultImage: {
        width: 80,
        height: 120,
        backgroundColor: colors.background,
    },
    searchResultContent: {
        flex: 1,
        padding: 12,
    },
    searchResultName: {
        fontSize: 16,
        fontWeight: '600',
        color: colors.text,
        marginBottom: 2,
    },
    searchResultWinery: {
        fontSize: 13,
        color: colors.gray,
        marginBottom: 2,
    },
    searchResultMeta: {
        fontSize: 12,
        color: colors.gray,
        marginBottom: 4,
    },
    searchResultReason: {
        fontSize: 12,
        color: colors.primary,
        marginBottom: 4,
    },
    searchResultFooter: {
        flexDirection: 'row',
        justifyContent: 'space-between',
        alignItems: 'center',
        marginTop: 'auto',
    },
    searchResultPrice: {
        fontSize: 16,
        fontWeight: '700',
        color: colors.text,
    },
    searchResultActions: {
        flexDirection: 'row',
        gap: 8,
    },
    smallActionButton: {
        width: 32,
        height: 32,
        borderRadius: 16,
        backgroundColor: colors.primary,
        justifyContent: 'center',
        alignItems: 'center',
    },

    // Expert List Card
    expertListCard: {
        width: 140,
        backgroundColor: colors.white,
        borderRadius: 12,
        padding: 16,
        marginRight: 12,
        alignItems: 'center',
        shadowColor: '#000',
        shadowOffset: { width: 0, height: 2 },
        shadowOpacity: 0.08,
        shadowRadius: 8,
        elevation: 3,
    },
    expertListIcon: {
        fontSize: 32,
        marginBottom: 8,
    },
    expertListLabel: {
        fontSize: 13,
        fontWeight: '600',
        color: colors.text,
        textAlign: 'center',
        marginBottom: 4,
    },
    expertListCount: {
        fontSize: 12,
        color: colors.gray,
    },

    // Category Grid
    categoryGrid: {
        flexDirection: 'row',
        flexWrap: 'wrap',
        paddingHorizontal: 12,
    },
    categoryTile: {
        width: (SCREEN_WIDTH - 48) / 2,
        backgroundColor: colors.white,
        borderRadius: 12,
        padding: 16,
        margin: 4,
        alignItems: 'center',
        borderWidth: 2,
        borderColor: colors.border,
    },
    categoryIcon: {
        fontSize: 28,
        marginBottom: 8,
    },
    categoryLabel: {
        fontSize: 14,
        fontWeight: '600',
        color: colors.text,
        textAlign: 'center',
    },
    categoryCount: {
        fontSize: 12,
        color: colors.gray,
        marginTop: 2,
    },
});

export default DiscoverScreen;