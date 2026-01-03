import React from 'react';
import { View, Text, StyleSheet, TouchableOpacity, Image } from 'react-native';
import { Ionicons } from '@expo/vector-icons';
import { colors, spacing, fontSize, borderRadius, shadows } from '../theme';
import { getImageUrl } from '../config/api';
import type { Bottle } from '../types';

interface BottleCardProps {
    bottle: Bottle;
    onPress: () => void;
    onLongPress?: () => void;
}

export function BottleCard({ bottle, onPress, onLongPress }: BottleCardProps) {
    const imageUrl = getImageUrl(bottle.thumb);

    // Determine wine type color
    const getTypeColor = (type: string) => {
        const typeLower = type?.toLowerCase() || '';
        if (typeLower.includes('red')) return colors.redWine;
        if (typeLower.includes('white')) return colors.whiteWine;
        if (typeLower.includes('rose') || typeLower.includes('rosé')) return colors.roseWine;
        if (typeLower.includes('sparkling') || typeLower.includes('champagne')) return colors.sparklingWine;
        return colors.gray;
    };

    // Format price
    const formatPrice = (price: number | null) => {
        if (price === null) return null;
        return `$${price.toFixed(0)}`;
    };

    return (
        <TouchableOpacity
            style={styles.container}
            onPress={onPress}
            onLongPress={onLongPress}
            activeOpacity={0.7}
        >
            {/* Image */}
            <View style={styles.imageContainer}>
                {imageUrl ? (
                    <Image
                        source={{ uri: imageUrl }}
                        style={styles.image}
                        resizeMode="contain"
                    />
                ) : (
                    <View style={[styles.placeholder, { backgroundColor: getTypeColor(bottle.type) }]}>
                        <Text style={styles.placeholderText}>🍷</Text>
                    </View>
                )}

                {/* Past indicator */}
                {bottle.past && (
                    <View style={styles.pastBadge}>
                        <Text style={styles.pastBadgeText}>Consumed</Text>
                    </View>
                )}
            </View>
            {/* Content */}
            <View style={styles.content}>
                {/* Vintage & Name */}
                <Text style={styles.name} numberOfLines={2}>
                    {bottle.vintage ? `${bottle.vintage} ` : ''}
                    {bottle.name || 'Untitled'}
                </Text>

                {/* Winery */}
                {bottle.winery && (
                    <Text style={styles.winery} numberOfLines={1}>
                        {bottle.winery}
                    </Text>
                )}

                {/* Region */}
                {bottle.region && (
                    <Text style={styles.region} numberOfLines={1}>
                        {bottle.region}
                    </Text>
                )}

                {/* Bottom row: Type, Rating, Price */}
                <View style={styles.bottomRow}>
                    {bottle.type && (
                        <View style={[styles.typeBadge, { backgroundColor: getTypeColor(bottle.type) }]}>
                            <Text style={[
                                styles.typeText,
                                { color: bottle.type.toLowerCase().includes('white') ? colors.text : colors.white }
                            ]}>
                                {bottle.type}
                            </Text>
                        </View>
                    )}

                    <View style={styles.metaRow}>
                        {bottle.my_rating && (
                            <View style={styles.ratingContainer}>
                                <Ionicons name="star" size={12} color={colors.secondary} />
                                <Text style={styles.ratingText}>{bottle.my_rating.toFixed(1)}</Text>
                            </View>
                        )}

                        {bottle.price_paid !== null && (
                            <Text style={styles.price}>{formatPrice(bottle.price_paid)}</Text>
                        )}
                    </View>
                </View>
            </View>
        </TouchableOpacity>
    );
}

const styles = StyleSheet.create({
    container: {
        backgroundColor: colors.card,
        borderRadius: borderRadius.lg,
        overflow: 'hidden',
        ...shadows.md,
    },
    imageContainer: {
        height: 160,
        position: 'relative',
    },
    image: {
        width: '100%',
        height: '100%',
    },
    placeholder: {
        width: '100%',
        height: '100%',
        alignItems: 'center',
        justifyContent: 'center',
    },
    placeholderText: {
        fontSize: 48,
        opacity: 0.5,
    },
    pastBadge: {
        position: 'absolute',
        top: spacing.sm,
        right: spacing.sm,
        backgroundColor: colors.overlay,
        paddingHorizontal: spacing.sm,
        paddingVertical: spacing.xs,
        borderRadius: borderRadius.sm,
    },
    pastBadgeText: {
        color: colors.white,
        fontSize: fontSize.xs,
        fontWeight: '600',
    },
    content: {
        padding: spacing.md,
    },
    name: {
        fontSize: fontSize.md,
        fontWeight: '600',
        color: colors.text,
        marginBottom: spacing.xs,
        lineHeight: fontSize.md * 1.3,
    },
    winery: {
        fontSize: fontSize.sm,
        color: colors.textSecondary,
        marginBottom: 2,
    },
    region: {
        fontSize: fontSize.sm,
        color: colors.textMuted,
        marginBottom: spacing.sm,
    },
    bottomRow: {
        flexDirection: 'row',
        alignItems: 'center',
        justifyContent: 'space-between',
        marginTop: spacing.xs,
    },
    typeBadge: {
        paddingHorizontal: spacing.sm,
        paddingVertical: 2,
        borderRadius: borderRadius.sm,
    },
    typeText: {
        fontSize: fontSize.xs,
        fontWeight: '600',
    },
    metaRow: {
        flexDirection: 'row',
        alignItems: 'center',
        gap: spacing.sm,
    },
    ratingContainer: {
        flexDirection: 'row',
        alignItems: 'center',
        gap: 2,
    },
    ratingText: {
        fontSize: fontSize.sm,
        fontWeight: '600',
        color: colors.text,
    },
    price: {
        fontSize: fontSize.sm,
        fontWeight: '600',
        color: colors.primary,
    },
});