import React, { useCallback } from 'react';
import {
    View,
    Text,
    ScrollView,
    StyleSheet,
    Image,
    TouchableOpacity,
    Alert,
    ActivityIndicator,
} from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { Ionicons } from '@expo/vector-icons';
import { useNavigation, useRoute } from '@react-navigation/native';
import type { NativeStackNavigationProp } from '@react-navigation/native-stack';
import type { RouteProp } from '@react-navigation/native';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';

import { api } from '../services/api';
import { getImageUrl } from "@/config/api";
import { colors, spacing, fontSize, borderRadius, shadows } from '../theme';
import type { RootStackParamList } from '../types';

type NavigationProp = NativeStackNavigationProp<RootStackParamList>;
type RouteProps = RouteProp<RootStackParamList, 'BottleDetail'>;

export function BottleDetailScreen() {
    const navigation = useNavigation<NavigationProp>();
    const route = useRoute<RouteProps>();
    const queryClient = useQueryClient();
    const { bottleId } = route.params;

    // Fetch bottle details
    const { data: bottle, isLoading, error } = useQuery({
        queryKey: ['bottle', bottleId],
        queryFn: () => api.getBottle(bottleId),
    });

    // Toggle past mutation
    const togglePastMutation = useMutation({
        mutationFn: () => api.toggleBottlePast(bottleId),
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['bottle', bottleId] });
            queryClient.invalidateQueries({ queryKey: ['inventory'] });
        },
    });

    // Delete mutation
    const deleteMutation = useMutation({
        mutationFn: () => api.deleteBottle(bottleId),
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['inventory'] });
            navigation.goBack();
        },
    });

    const handleTogglePast = useCallback(() => {
        const action = bottle?.past ? 'restore' : 'mark as consumed';
        Alert.alert(
            'Confirm',
            `Are you sure you want to ${action} this bottle?`,
            [
                { text: 'Cancel', style: 'cancel' },
                {
                    text: 'Yes',
                    onPress: () => togglePastMutation.mutate(),
                },
            ]
        );
    }, [bottle?.past, togglePastMutation]);

    const handleDelete = useCallback(() => {
        Alert.alert(
            'Delete Bottle',
            'Are you sure you want to delete this bottle? This cannot be undone.',
            [
                { text: 'Cancel', style: 'cancel' },
                {
                    text: 'Delete',
                    style: 'destructive',
                    onPress: () => deleteMutation.mutate(),
                },
            ]
        );
    }, [deleteMutation]);

    const handleEdit = useCallback(() => {
        navigation.navigate('EditBottle', { bottleId });
    }, [navigation, bottleId]);

    if (isLoading) {
        return (
            <SafeAreaView style={styles.container}>
                <View style={styles.loadingContainer}>
                    <ActivityIndicator size="large" color={colors.primary} />
                </View>
            </SafeAreaView>
        );
    }

    if (error || !bottle) {
        return (
            <SafeAreaView style={styles.container}>
                <View style={styles.errorContainer}>
                    <Text style={styles.errorText}>Failed to load bottle</Text>
                    <TouchableOpacity onPress={() => navigation.goBack()}>
                        <Text style={styles.errorLink}>Go back</Text>
                    </TouchableOpacity>
                </View>
            </SafeAreaView>
        );
    }

    const imageUrl = getImageUrl(bottle.thumb);

    return (
        <SafeAreaView style={styles.container} edges={['top']}>
            <ScrollView style={styles.scrollView} showsVerticalScrollIndicator={false}>
                {/* Header Image */}
                <View style={styles.imageContainer}>
                    {imageUrl ? (
                        <Image source={{ uri: imageUrl }} style={styles.image} resizeMode="contain" />
                    ) : (
                        <View style={styles.placeholder}>
                            <Text style={styles.placeholderText}>🍷</Text>
                        </View>
                    )}

                    {/* Back button */}
                    <TouchableOpacity style={styles.backButton} onPress={() => navigation.goBack()}>
                        <Ionicons name="arrow-back" size={24} color={colors.white} />
                    </TouchableOpacity>

                    {/* Past badge */}
                    {bottle.past && (
                        <View style={styles.pastBadge}>
                            <Text style={styles.pastBadgeText}>Consumed</Text>
                        </View>
                    )}
                </View>

                {/* Content */}
                <View style={styles.content}>
                    {/* Title */}
                    <Text style={styles.title}>
                        {bottle.vintage ? `${bottle.vintage} ` : ''}
                        {bottle.name || 'Untitled'}
                    </Text>

                    {bottle.winery && <Text style={styles.winery}>{bottle.winery}</Text>}

                    {/* Meta info */}
                    <View style={styles.metaRow}>
                        {bottle.region && (
                            <View style={styles.metaItem}>
                                <Ionicons name="location-outline" size={16} color={colors.textSecondary} />
                                <Text style={styles.metaText}>{bottle.region}</Text>
                            </View>
                        )}
                        {bottle.type && (
                            <View style={styles.metaItem}>
                                <Ionicons name="wine-outline" size={16} color={colors.textSecondary} />
                                <Text style={styles.metaText}>{bottle.type}</Text>
                            </View>
                        )}
                    </View>

                    {/* Rating & Price */}
                    <View style={styles.statsRow}>
                        {bottle.my_rating !== null && (
                            <View style={styles.statCard}>
                                <Ionicons name="star" size={24} color={colors.secondary} />
                                <Text style={styles.statValue}>{bottle.my_rating.toFixed(1)}</Text>
                                <Text style={styles.statLabel}>My Rating</Text>
                            </View>
                        )}
                        {bottle.price_paid !== null && (
                            <View style={styles.statCard}>
                                <Ionicons name="pricetag-outline" size={24} color={colors.primary} />
                                <Text style={styles.statValue}>${bottle.price_paid.toFixed(0)}</Text>
                                <Text style={styles.statLabel}>Price Paid</Text>
                            </View>
                        )}
                        {bottle.location && (
                            <View style={styles.statCard}>
                                <Ionicons name="grid-outline" size={24} color={colors.accent} />
                                <Text style={styles.statValue} numberOfLines={1}>{bottle.location}</Text>
                                <Text style={styles.statLabel}>Location</Text>
                            </View>
                        )}
                    </View>

                    {/* Grapes */}
                    {bottle.grapes && (
                        <View style={styles.section}>
                            <Text style={styles.sectionTitle}>Grapes</Text>
                            <Text style={styles.sectionText}>{bottle.grapes}</Text>
                        </View>
                    )}

                    {/* My Review */}
                    {bottle.my_review && (
                        <View style={styles.section}>
                            <Text style={styles.sectionTitle}>My Notes</Text>
                            <Text style={styles.sectionText}>{bottle.my_review}</Text>
                        </View>
                    )}

                    {/* AI Insights */}
                    {bottle.ai_insights && (
                        <View style={styles.aiSection}>
                            <View style={styles.aiHeader}>
                                <Ionicons name="sparkles" size={20} color={colors.secondary} />
                                <Text style={styles.aiTitle}>AI Insights</Text>
                            </View>

                            {bottle.ai_insights.notes_md && (
                                <View style={styles.aiBlock}>
                                    <Text style={styles.aiBlockTitle}>Tasting Notes</Text>
                                    <Text style={styles.aiBlockText}>{bottle.ai_insights.notes_md}</Text>
                                </View>
                            )}

                            {bottle.ai_insights.pairings && bottle.ai_insights.pairings.length > 0 && (
                                <View style={styles.aiBlock}>
                                    <Text style={styles.aiBlockTitle}>Food Pairings</Text>
                                    <View style={styles.pairingsRow}>
                                        {bottle.ai_insights.pairings.map((pairing, index) => (
                                            <View key={index} style={styles.pairingTag}>
                                                <Text style={styles.pairingText}>{pairing}</Text>
                                            </View>
                                        ))}
                                    </View>
                                </View>
                            )}

                            {(bottle.ai_insights.drink_from || bottle.ai_insights.drink_to) && (
                                <View style={styles.aiBlock}>
                                    <Text style={styles.aiBlockTitle}>Drink Window</Text>
                                    <Text style={styles.aiBlockText}>
                                        {bottle.ai_insights.drink_from || 'Now'} - {bottle.ai_insights.drink_to || 'Soon'}
                                    </Text>
                                </View>
                            )}
                        </View>
                    )}
                </View>
            </ScrollView>

            {/* Action buttons */}
            <View style={styles.actionBar}>
                <TouchableOpacity style={styles.actionButton} onPress={handleEdit}>
                    <Ionicons name="pencil-outline" size={22} color={colors.primary} />
                    <Text style={styles.actionButtonText}>Edit</Text>
                </TouchableOpacity>

                <TouchableOpacity style={styles.actionButton} onPress={handleTogglePast}>
                    <Ionicons
                        name={bottle.past ? 'refresh-outline' : 'checkmark-circle-outline'}
                        size={22}
                        color={colors.accent}
                    />
                    <Text style={styles.actionButtonText}>
                        {bottle.past ? 'Restore' : 'Consumed'}
                    </Text>
                </TouchableOpacity>

                <TouchableOpacity style={styles.actionButton} onPress={handleDelete}>
                    <Ionicons name="trash-outline" size={22} color={colors.error} />
                    <Text style={[styles.actionButtonText, { color: colors.error }]}>Delete</Text>
                </TouchableOpacity>
            </View>
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
    errorContainer: {
        flex: 1,
        alignItems: 'center',
        justifyContent: 'center',
    },
    errorText: {
        fontSize: fontSize.lg,
        color: colors.error,
        marginBottom: spacing.md,
    },
    errorLink: {
        fontSize: fontSize.md,
        color: colors.primary,
    },
    scrollView: {
        flex: 1,
    },
    imageContainer: {
        height: 300,
        position: 'relative',
    },
    image: {
        width: '100%',
        height: '100%',
    },
    placeholder: {
        width: '100%',
        height: '100%',
        backgroundColor: colors.primary,
        alignItems: 'center',
        justifyContent: 'center',
    },
    placeholderText: {
        fontSize: 80,
        opacity: 0.3,
    },
    backButton: {
        position: 'absolute',
        top: spacing.md,
        left: spacing.md,
        width: 40,
        height: 40,
        borderRadius: 20,
        backgroundColor: colors.overlay,
        alignItems: 'center',
        justifyContent: 'center',
    },
    pastBadge: {
        position: 'absolute',
        top: spacing.md,
        right: spacing.md,
        backgroundColor: colors.overlay,
        paddingHorizontal: spacing.md,
        paddingVertical: spacing.sm,
        borderRadius: borderRadius.md,
    },
    pastBadgeText: {
        color: colors.white,
        fontSize: fontSize.sm,
        fontWeight: '600',
    },
    content: {
        padding: spacing.lg,
    },
    title: {
        fontSize: fontSize.xxl,
        fontWeight: '700',
        color: colors.text,
        marginBottom: spacing.xs,
    },
    winery: {
        fontSize: fontSize.lg,
        color: colors.textSecondary,
        marginBottom: spacing.md,
    },
    metaRow: {
        flexDirection: 'row',
        flexWrap: 'wrap',
        gap: spacing.md,
        marginBottom: spacing.lg,
    },
    metaItem: {
        flexDirection: 'row',
        alignItems: 'center',
        gap: spacing.xs,
    },
    metaText: {
        fontSize: fontSize.md,
        color: colors.textSecondary,
    },
    statsRow: {
        flexDirection: 'row',
        gap: spacing.md,
        marginBottom: spacing.lg,
    },
    statCard: {
        flex: 1,
        backgroundColor: colors.offWhite,
        padding: spacing.md,
        borderRadius: borderRadius.md,
        alignItems: 'center',
    },
    statValue: {
        fontSize: fontSize.lg,
        fontWeight: '700',
        color: colors.text,
        marginTop: spacing.xs,
    },
    statLabel: {
        fontSize: fontSize.xs,
        color: colors.textMuted,
        marginTop: 2,
    },
    section: {
        marginBottom: spacing.lg,
    },
    sectionTitle: {
        fontSize: fontSize.sm,
        fontWeight: '600',
        color: colors.textMuted,
        textTransform: 'uppercase',
        letterSpacing: 0.5,
        marginBottom: spacing.sm,
    },
    sectionText: {
        fontSize: fontSize.md,
        color: colors.text,
        lineHeight: fontSize.md * 1.5,
    },
    aiSection: {
        backgroundColor: colors.offWhite,
        borderRadius: borderRadius.lg,
        padding: spacing.lg,
        marginTop: spacing.md,
    },
    aiHeader: {
        flexDirection: 'row',
        alignItems: 'center',
        gap: spacing.sm,
        marginBottom: spacing.md,
    },
    aiTitle: {
        fontSize: fontSize.lg,
        fontWeight: '600',
        color: colors.text,
    },
    aiBlock: {
        marginBottom: spacing.md,
    },
    aiBlockTitle: {
        fontSize: fontSize.sm,
        fontWeight: '600',
        color: colors.textSecondary,
        marginBottom: spacing.xs,
    },
    aiBlockText: {
        fontSize: fontSize.md,
        color: colors.text,
        lineHeight: fontSize.md * 1.5,
    },
    pairingsRow: {
        flexDirection: 'row',
        flexWrap: 'wrap',
        gap: spacing.sm,
    },
    pairingTag: {
        backgroundColor: colors.white,
        paddingHorizontal: spacing.md,
        paddingVertical: spacing.xs,
        borderRadius: borderRadius.full,
    },
    pairingText: {
        fontSize: fontSize.sm,
        color: colors.text,
    },
    actionBar: {
        flexDirection: 'row',
        borderTopWidth: 1,
        borderTopColor: colors.border,
        backgroundColor: colors.white,
        paddingVertical: spacing.sm,
        paddingHorizontal: spacing.md,
    },
    actionButton: {
        flex: 1,
        alignItems: 'center',
        paddingVertical: spacing.sm,
    },
    actionButtonText: {
        fontSize: fontSize.xs,
        color: colors.textSecondary,
        marginTop: 2,
    },
});