import React, { useState, useCallback } from 'react';
import {
    View,
    Text,
    FlatList,
    StyleSheet,
    RefreshControl,
    TextInput,
    TouchableOpacity,
    ActivityIndicator,
} from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { Ionicons } from '@expo/vector-icons';
import { useNavigation } from '@react-navigation/native';
import type { NativeStackNavigationProp } from '@react-navigation/native-stack';
import { useQuery } from '@tanstack/react-query';

import { api } from '../services/api';
import { BottleCard } from '../components/BottleCard';
import { colors, spacing, fontSize, borderRadius } from '../theme';
import type { RootStackParamList, Bottle, InventoryStatus } from '../types';

type NavigationProp = NativeStackNavigationProp<RootStackParamList>;

const STATUS_OPTIONS: { key: InventoryStatus; label: string }[] = [
    { key: 'current', label: 'Current' },
    { key: 'past', label: 'Past' },
    { key: 'all', label: 'All' },
];

export function CellarScreen() {
    const navigation = useNavigation<NavigationProp>();
    const [search, setSearch] = useState('');
    const [status, setStatus] = useState<InventoryStatus>('current');
    const [page, setPage] = useState(1);

    // Fetch inventory
    const {
        data,
        isLoading,
        isRefetching,
        refetch,
        isFetchingNextPage,
    } = useQuery({
        queryKey: ['inventory', page, status, search],
        queryFn: () => api.getInventory(page, { status, search: search.trim() }),
        staleTime: 1000 * 60, // 1 minute
    });

    const handleRefresh = useCallback(() => {
        setPage(1);
        refetch();
    }, [refetch]);

    const handleBottlePress = useCallback((bottle: Bottle) => {
        navigation.navigate('BottleDetail', { bottleId: bottle.bottle_id });
    }, [navigation]);

    const handleAddPress = useCallback(() => {
        navigation.navigate('AddBottle');
    }, [navigation]);

    const renderBottle = useCallback(({ item }: { item: Bottle }) => (
        <View style={styles.cardContainer}>
            <BottleCard
                bottle={item}
                onPress={() => handleBottlePress(item)}
            />
        </View>
    ), [handleBottlePress]);

    const renderHeader = () => (
        <View style={styles.header}>
            {/* Search bar */}
            <View style={styles.searchContainer}>
                <Ionicons name="search" size={20} color={colors.gray} style={styles.searchIcon} />
                <TextInput
                    style={styles.searchInput}
                    placeholder="Search wines..."
                    placeholderTextColor={colors.gray}
                    value={search}
                    onChangeText={setSearch}
                    returnKeyType="search"
                    onSubmitEditing={handleRefresh}
                />
                {search.length > 0 && (
                    <TouchableOpacity onPress={() => { setSearch(''); handleRefresh(); }}>
                        <Ionicons name="close-circle" size={20} color={colors.gray} />
                    </TouchableOpacity>
                )}
            </View>

            {/* Status filter tabs */}
            <View style={styles.tabsContainer}>
                {STATUS_OPTIONS.map((option) => (
                    <TouchableOpacity
                        key={option.key}
                        style={[
                            styles.tab,
                            status === option.key && styles.tabActive,
                        ]}
                        onPress={() => {
                            setStatus(option.key);
                            setPage(1);
                        }}
                    >
                        <Text style={[
                            styles.tabText,
                            status === option.key && styles.tabTextActive,
                        ]}>
                            {option.label}
                        </Text>
                    </TouchableOpacity>
                ))}
            </View>

            {/* Stats */}
            {data && (
                <Text style={styles.statsText}>
                    {data.total} {data.total === 1 ? 'bottle' : 'bottles'}
                </Text>
            )}
        </View>
    );

    const renderEmpty = () => (
        <View style={styles.emptyContainer}>
            <Text style={styles.emptyIcon}>🍷</Text>
            <Text style={styles.emptyTitle}>
                {search ? 'No wines found' : 'Your cellar is empty'}
            </Text>
            <Text style={styles.emptySubtitle}>
                {search
                    ? 'Try a different search term'
                    : 'Add your first bottle to get started'}
            </Text>
            {!search && (
                <TouchableOpacity style={styles.emptyButton} onPress={handleAddPress}>
                    <Text style={styles.emptyButtonText}>Add Bottle</Text>
                </TouchableOpacity>
            )}
        </View>
    );

    const renderFooter = () => {
        if (!isFetchingNextPage) return null;
        return (
            <View style={styles.footer}>
                <ActivityIndicator color={colors.primary} />
            </View>
        );
    };

    if (isLoading && !data) {
        return (
            <SafeAreaView style={styles.container}>
                <View style={styles.loadingContainer}>
                    <ActivityIndicator size="large" color={colors.primary} />
                    <Text style={styles.loadingText}>Loading your cellar...</Text>
                </View>
            </SafeAreaView>
        );
    }

    return (
        <SafeAreaView style={styles.container} edges={['top']}>
            <FlatList
                data={data?.items || []}
                renderItem={renderBottle}
                keyExtractor={(item) => String(item.bottle_id)}
                numColumns={2}
                contentContainerStyle={styles.listContent}
                columnWrapperStyle={styles.row}
                ListHeaderComponent={renderHeader}
                ListEmptyComponent={renderEmpty}
                ListFooterComponent={renderFooter}
                refreshControl={
                    <RefreshControl
                        refreshing={isRefetching}
                        onRefresh={handleRefresh}
                        tintColor={colors.primary}
                    />
                }
                onEndReached={() => {
                    if (data && page < data.totalPages) {
                        setPage(page + 1);
                    }
                }}
                onEndReachedThreshold={0.5}
            />

            {/* FAB */}
            <TouchableOpacity style={styles.fab} onPress={handleAddPress}>
                <Ionicons name="add" size={28} color={colors.white} />
            </TouchableOpacity>
        </SafeAreaView>
    );
}

const styles = StyleSheet.create({
    container: {
        flex: 1,
        backgroundColor: colors.background,
    },
    loadingContainer: {
        flex: 1,
        alignItems: 'center',
        justifyContent: 'center',
    },
    loadingText: {
        marginTop: spacing.md,
        fontSize: fontSize.md,
        color: colors.textSecondary,
    },
    listContent: {
        padding: spacing.md,
        paddingBottom: 100,
    },
    row: {
        justifyContent: 'space-between',
    },
    cardContainer: {
        width: '48%',
        marginBottom: spacing.md,
    },
    header: {
        marginBottom: spacing.md,
    },
    searchContainer: {
        flexDirection: 'row',
        alignItems: 'center',
        backgroundColor: colors.offWhite,
        borderRadius: borderRadius.lg,
        paddingHorizontal: spacing.md,
        marginBottom: spacing.md,
    },
    searchIcon: {
        marginRight: spacing.sm,
    },
    searchInput: {
        flex: 1,
        paddingVertical: spacing.md,
        fontSize: fontSize.md,
        color: colors.text,
    },
    tabsContainer: {
        flexDirection: 'row',
        backgroundColor: colors.offWhite,
        borderRadius: borderRadius.lg,
        padding: spacing.xs,
        marginBottom: spacing.md,
    },
    tab: {
        flex: 1,
        paddingVertical: spacing.sm,
        alignItems: 'center',
        borderRadius: borderRadius.md,
    },
    tabActive: {
        backgroundColor: colors.white,
    },
    tabText: {
        fontSize: fontSize.sm,
        fontWeight: '600',
        color: colors.textSecondary,
    },
    tabTextActive: {
        color: colors.primary,
    },
    statsText: {
        fontSize: fontSize.sm,
        color: colors.textMuted,
    },
    emptyContainer: {
        alignItems: 'center',
        paddingVertical: spacing.xxl,
    },
    emptyIcon: {
        fontSize: 64,
        marginBottom: spacing.md,
    },
    emptyTitle: {
        fontSize: fontSize.xl,
        fontWeight: '600',
        color: colors.text,
        marginBottom: spacing.xs,
    },
    emptySubtitle: {
        fontSize: fontSize.md,
        color: colors.textSecondary,
        marginBottom: spacing.lg,
    },
    emptyButton: {
        backgroundColor: colors.primary,
        paddingHorizontal: spacing.lg,
        paddingVertical: spacing.md,
        borderRadius: borderRadius.md,
    },
    emptyButtonText: {
        color: colors.white,
        fontSize: fontSize.md,
        fontWeight: '600',
    },
    footer: {
        paddingVertical: spacing.lg,
    },
    fab: {
        position: 'absolute',
        bottom: spacing.lg,
        right: spacing.lg,
        width: 56,
        height: 56,
        borderRadius: 28,
        backgroundColor: colors.primary,
        alignItems: 'center',
        justifyContent: 'center',
        elevation: 4,
        shadowColor: '#000',
        shadowOffset: { width: 0, height: 2 },
        shadowOpacity: 0.25,
        shadowRadius: 4,
    },
});