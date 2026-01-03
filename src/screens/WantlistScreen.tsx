import React, { useCallback } from 'react';
import {
    View,
    Text,
    FlatList,
    StyleSheet,
    RefreshControl,
    TouchableOpacity,
    Alert,
    ActivityIndicator,
} from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { Ionicons } from '@expo/vector-icons';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';

import { api } from '../services/api';
import { colors, spacing, fontSize, borderRadius, shadows } from '../theme';
import type { WantlistItem } from '../types';

export function WantlistScreen() {
    const queryClient = useQueryClient();

    // Fetch wantlist
    const { data, isLoading, isRefetching, refetch } = useQuery({
        queryKey: ['wantlist'],
        queryFn: () => api.getWantlist(),
    });

    // Move to inventory mutation
    const moveMutation = useMutation({
        mutationFn: (id: number) => api.moveToInventory(id),
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['wantlist'] });
            queryClient.invalidateQueries({ queryKey: ['inventory'] });
            Alert.alert('Success', 'Moved to your cellar!');
        },
        onError: (error) => {
            Alert.alert('Error', error instanceof Error ? error.message : 'Failed to move');
        },
    });

    // Remove mutation
    const removeMutation = useMutation({
        mutationFn: (id: number) => api.removeFromWantlist(id),
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['wantlist'] });
        },
    });

    const handleMove = useCallback((item: WantlistItem) => {
        Alert.alert(
            'Move to Cellar',
            `Add "${item.name}" to your inventory?`,
            [
                { text: 'Cancel', style: 'cancel' },
                {
                    text: 'Move',
                    onPress: () => moveMutation.mutate(item.id),
                },
            ]
        );
    }, [moveMutation]);

    const handleRemove = useCallback((item: WantlistItem) => {
        Alert.alert(
            'Remove',
            `Remove "${item.name}" from your wantlist?`,
            [
                { text: 'Cancel', style: 'cancel' },
                {
                    text: 'Remove',
                    style: 'destructive',
                    onPress: () => removeMutation.mutate(item.id),
                },
            ]
        );
    }, [removeMutation]);

    const renderItem = useCallback(({ item }: { item: WantlistItem }) => (
        <View style={styles.card}>
            <View style={styles.cardContent}>
                <Text style={styles.cardTitle} numberOfLines={2}>
                    {item.vintage ? `${item.vintage} ` : ''}
                    {item.name || 'Untitled'}
                </Text>

                {item.winery && (
                    <Text style={styles.cardSubtitle} numberOfLines={1}>
                        {item.winery}
                    </Text>
                )}

                <View style={styles.cardMeta}>
                    {item.region && (
                        <Text style={styles.cardMetaText}>{item.region}</Text>
                    )}
                    {item.region && item.type && <Text style={styles.cardMetaDot}>•</Text>}
                    {item.type && (
                        <Text style={styles.cardMetaText}>{item.type}</Text>
                    )}
                </View>

                {item.notes && (
                    <Text style={styles.notes} numberOfLines={2}>
                        {item.notes}
                    </Text>
                )}
            </View>

            <View style={styles.cardActions}>
                <TouchableOpacity
                    style={[styles.actionBtn, styles.moveBtn]}
                    onPress={() => handleMove(item)}
                    disabled={moveMutation.isPending}
                >
                    <Ionicons name="arrow-forward" size={18} color={colors.white} />
                </TouchableOpacity>

                <TouchableOpacity
                    style={[styles.actionBtn, styles.removeBtn]}
                    onPress={() => handleRemove(item)}
                    disabled={removeMutation.isPending}
                >
                    <Ionicons name="close" size={18} color={colors.error} />
                </TouchableOpacity>
            </View>
        </View>
    ), [handleMove, handleRemove, moveMutation.isPending, removeMutation.isPending]);

    const renderEmpty = () => (
        <View style={styles.emptyContainer}>
            <Text style={styles.emptyIcon}>📝</Text>
            <Text style={styles.emptyTitle}>Your wantlist is empty</Text>
            <Text style={styles.emptySubtitle}>
                Add wines you'd like to try or buy
            </Text>
        </View>
    );

    if (isLoading && !data) {
        return (
            <SafeAreaView style={styles.container}>
                <View style={styles.loadingContainer}>
                    <ActivityIndicator size="large" color={colors.primary} />
                </View>
            </SafeAreaView>
        );
    }

    return (
        <SafeAreaView style={styles.container} edges={['top']}>
            <View style={styles.header}>
                <Text style={styles.title}>Wantlist</Text>
                {data && data.count > 0 && (
                    <Text style={styles.count}>{data.count} wines</Text>
                )}
            </View>

            <FlatList
                data={data?.items || []}
                renderItem={renderItem}
                keyExtractor={(item) => String(item.id)}
                contentContainerStyle={styles.listContent}
                ListEmptyComponent={renderEmpty}
                refreshControl={
                    <RefreshControl
                        refreshing={isRefetching}
                        onRefresh={refetch}
                        tintColor={colors.primary}
                    />
                }
                ItemSeparatorComponent={() => <View style={styles.separator} />}
            />
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
    header: {
        flexDirection: 'row',
        alignItems: 'center',
        justifyContent: 'space-between',
        paddingHorizontal: spacing.lg,
        paddingVertical: spacing.md,
        borderBottomWidth: 1,
        borderBottomColor: colors.border,
    },
    title: {
        fontSize: fontSize.xxl,
        fontWeight: '700',
        color: colors.text,
    },
    count: {
        fontSize: fontSize.sm,
        color: colors.textMuted,
    },
    listContent: {
        padding: spacing.md,
    },
    separator: {
        height: spacing.md,
    },
    card: {
        backgroundColor: colors.card,
        borderRadius: borderRadius.lg,
        flexDirection: 'row',
        overflow: 'hidden',
        ...shadows.sm,
    },
    cardContent: {
        flex: 1,
        padding: spacing.md,
    },
    cardTitle: {
        fontSize: fontSize.lg,
        fontWeight: '600',
        color: colors.text,
        marginBottom: spacing.xs,
    },
    cardSubtitle: {
        fontSize: fontSize.md,
        color: colors.textSecondary,
        marginBottom: spacing.xs,
    },
    cardMeta: {
        flexDirection: 'row',
        alignItems: 'center',
    },
    cardMetaText: {
        fontSize: fontSize.sm,
        color: colors.textMuted,
    },
    cardMetaDot: {
        fontSize: fontSize.sm,
        color: colors.textMuted,
        marginHorizontal: spacing.xs,
    },
    notes: {
        fontSize: fontSize.sm,
        color: colors.textSecondary,
        fontStyle: 'italic',
        marginTop: spacing.sm,
    },
    cardActions: {
        borderLeftWidth: 1,
        borderLeftColor: colors.border,
    },
    actionBtn: {
        flex: 1,
        width: 50,
        alignItems: 'center',
        justifyContent: 'center',
    },
    moveBtn: {
        backgroundColor: colors.primary,
    },
    removeBtn: {
        backgroundColor: colors.offWhite,
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
    },
});