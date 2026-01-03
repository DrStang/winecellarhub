// src/screens/ExpertListDetailScreen.tsx
// Screen for viewing wines in a specific expert list (Decanter, Wine Spectator, etc.)

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
import { getExpertListWines, addToCellar, addToWantlist } from '@/services/discoverApi';
import type { Wine, RootStackParamList } from '@/types';
import {getImageUrl} from "@/config/api";

type RouteProps = RouteProp<RootStackParamList, 'ExpertListDetail'>;

// Medal badge component
const MedalBadge: React.FC<{ medal: string; score?: number }> = ({ medal, score }) => {
    let bgColor = colors.gray;
    let icon = '🏅';

    if (medal === 'Best in Show') {
        bgColor = colors.primary;
        icon = '🏆';
    } else if (medal === 'Platinum') {
        bgColor = '#94a3b8';
        icon = '🥇';
    } else if (medal === 'Gold') {
        bgColor = '#fbbf24';
        icon = '🥇';
    }

    return (
        <View style={[styles.medalBadge, { backgroundColor: bgColor }]}>
            <Text style={styles.medalIcon}>{icon}</Text>
            <Text style={styles.medalText}>{medal}</Text>
            {score && <Text style={styles.scoreText}>{score}pts</Text>}
        </View>
    );
};

// Wine list item component
const WineListItem: React.FC<{
    wine: Wine;
    rank?: number;
    onAddToCellar: () => void;
    onAddToWantlist: () => void;
}> = ({ wine, rank, onAddToCellar, onAddToWantlist }) => (
    <View style={styles.wineCard}>
        {rank && (
            <View style={styles.rankBadge}>
                <Text style={styles.rankText}>#{rank}</Text>
            </View>
        )}

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

            <Text style={styles.wineMeta} numberOfLines={1}>
                {[wine.region, wine.country, wine.vintage].filter(Boolean).join(' • ')}
            </Text>

            {wine.medal && <MedalBadge medal={wine.medal} score={wine.score} />}

            {wine.notes && (
                <Text style={styles.wineNotes} numberOfLines={2}>
                    {wine.notes}
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

// Filter chips
const FilterChip: React.FC<{
    label: string;
    active: boolean;
    onPress: () => void;
}> = ({ label, active, onPress }) => (
    <TouchableOpacity
        style={[styles.filterChip, active && styles.filterChipActive]}
        onPress={onPress}
    >
        <Text style={[styles.filterChipText, active && styles.filterChipTextActive]}>
            {label}
        </Text>
    </TouchableOpacity>
);

// Main screen
const ExpertListDetailScreen: React.FC = () => {
    const route = useRoute<RouteProps>();
    const { listKey } = route.params;

    const [wines, setWines] = useState<Wine[]>([]);
    const [subtitle, setSubtitle] = useState('');
    const [loading, setLoading] = useState(true);
    const [refreshing, setRefreshing] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [typeFilter, setTypeFilter] = useState<string | null>(null);

    const typeFilters = ['All', 'Red', 'White', 'Rosé', 'Sparkling'];

    const loadWines = useCallback(async () => {
        try {
            setError(null);
            const data = await getExpertListWines(listKey, {
                type: typeFilter && typeFilter !== 'All' ? typeFilter : undefined,
            });
            setWines(data.wines);
            setSubtitle(data.subtitle);
        } catch (err) {
            console.error('Failed to load expert list wines:', err);
            setError('Failed to load wines. Pull to refresh.');
        } finally {
            setLoading(false);
            setRefreshing(false);
        }
    }, [listKey, typeFilter]);

    useEffect(() => {
        loadWines();
    }, [loadWines]);

    const onRefresh = useCallback(() => {
        setRefreshing(true);
        loadWines();
    }, [loadWines]);

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
                <TouchableOpacity style={styles.retryButton} onPress={loadWines}>
                    <Text style={styles.retryButtonText}>Retry</Text>
                </TouchableOpacity>
            </View>
        );
    }

    return (
        <View style={styles.container}>
            <View style={styles.header}>
                <Text style={styles.subtitle}>{subtitle}</Text>
                <Text style={styles.countText}>{wines.length} wines</Text>
            </View>

            <View style={styles.filtersContainer}>
                <FlatList
                    horizontal
                    data={typeFilters}
                    keyExtractor={(item) => item}
                    showsHorizontalScrollIndicator={false}
                    contentContainerStyle={styles.filtersList}
                    renderItem={({ item }) => (
                        <FilterChip
                            label={item}
                            active={(item === 'All' && !typeFilter) || typeFilter === item}
                            onPress={() => setTypeFilter(item === 'All' ? null : item)}
                        />
                    )}
                />
            </View>

            <FlatList
                data={wines}
                keyExtractor={(item) => item.id.toString()}
                renderItem={({ item, index }) => (
                    <WineListItem
                        wine={item}
                        rank={item.rank || index + 1}
                        onAddToCellar={() => handleAddToCellar(item)}
                        onAddToWantlist={() => handleAddToWantlist(item)}
                    />
                )}
                contentContainerStyle={styles.listContent}
                refreshControl={
                    <RefreshControl refreshing={refreshing} onRefresh={onRefresh} tintColor={colors.primary} />
                }
                ListEmptyComponent={
                    <View style={styles.emptyContainer}>
                        <Ionicons name="wine-outline" size={48} color={colors.gray} />
                        <Text style={styles.emptyText}>No wines found</Text>
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
        backgroundColor: colors.white,
        paddingHorizontal: 16,
        paddingVertical: 12,
        borderBottomWidth: 1,
        borderBottomColor: colors.border,
    },
    subtitle: {
        fontSize: 16,
        fontWeight: '600',
        color: colors.text,
    },
    countText: {
        fontSize: 14,
        color: colors.gray,
        marginTop: 2,
    },
    filtersContainer: {
        backgroundColor: colors.white,
        paddingVertical: 12,
        borderBottomWidth: 1,
        borderBottomColor: colors.border,
    },
    filtersList: {
        paddingHorizontal: 16,
    },
    filterChip: {
        paddingHorizontal: 16,
        paddingVertical: 8,
        borderRadius: 20,
        backgroundColor: colors.background,
        marginRight: 8,
        borderWidth: 1,
        borderColor: colors.border,
    },
    filterChipActive: {
        backgroundColor: colors.primary,
        borderColor: colors.primary,
    },
    filterChipText: {
        fontSize: 14,
        color: colors.text,
        fontWeight: '500',
    },
    filterChipTextActive: {
        color: colors.white,
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
    rankBadge: {
        position: 'absolute',
        top: 8,
        left: 8,
        backgroundColor: colors.primary,
        paddingHorizontal: 8,
        paddingVertical: 4,
        borderRadius: 8,
        zIndex: 1,
    },
    rankText: {
        fontSize: 12,
        fontWeight: '700',
        color: colors.white,
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
        marginBottom: 2,
    },
    wineMeta: {
        fontSize: 13,
        color: colors.gray,
        marginBottom: 6,
    },
    medalBadge: {
        flexDirection: 'row',
        alignItems: 'center',
        alignSelf: 'flex-start',
        paddingHorizontal: 8,
        paddingVertical: 4,
        borderRadius: 6,
        marginBottom: 6,
    },
    medalIcon: {
        fontSize: 14,
        marginRight: 4,
    },
    medalText: {
        fontSize: 12,
        fontWeight: '600',
        color: colors.white,
    },
    scoreText: {
        fontSize: 11,
        color: colors.white,
        marginLeft: 4,
        opacity: 0.9,
    },
    wineNotes: {
        fontSize: 12,
        color: colors.gray,
        fontStyle: 'italic',
        marginBottom: 6,
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
    emptyContainer: {
        flex: 1,
        justifyContent: 'center',
        alignItems: 'center',
        paddingVertical: 48,
    },
    emptyText: {
        marginTop: 12,
        fontSize: 16,
        color: colors.gray,
    },
});

export default ExpertListDetailScreen;