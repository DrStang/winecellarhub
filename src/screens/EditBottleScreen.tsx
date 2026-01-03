import React, { useState, useCallback, useEffect } from 'react';
import {
    View,
    Text,
    ScrollView,
    StyleSheet,
    TextInput,
    TouchableOpacity,
    Alert,
    ActivityIndicator,
    KeyboardAvoidingView,
    Platform,
} from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { Ionicons } from '@expo/vector-icons';
import { useNavigation, useRoute } from '@react-navigation/native';
import type { NativeStackNavigationProp } from '@react-navigation/native-stack';
import type { RouteProp } from '@react-navigation/native';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';

import { api } from '../services/api';
import { colors, spacing, fontSize, borderRadius } from '../theme';
import type { RootStackParamList } from '../types';

type NavigationProp = NativeStackNavigationProp<RootStackParamList>;
type RouteProps = RouteProp<RootStackParamList, 'EditBottle'>;

const WINE_TYPES = ['Red', 'White', 'Rosé', 'Sparkling', 'Dessert', 'Fortified'];

export function EditBottleScreen() {
    const navigation = useNavigation<NavigationProp>();
    const route = useRoute<RouteProps>();
    const queryClient = useQueryClient();
    const { bottleId } = route.params;

    // Form state
    const [name, setName] = useState('');
    const [winery, setWinery] = useState('');
    const [vintage, setVintage] = useState('');
    const [region, setRegion] = useState('');
    const [country, setCountry] = useState('');
    const [grapes, setGrapes] = useState('');
    const [type, setType] = useState('');
    const [style, setStyle] = useState('');
    const [pricePaid, setPricePaid] = useState('');
    const [myRating, setMyRating] = useState('');
    const [myReview, setMyReview] = useState('');
    const [location, setLocation] = useState('');
    const [drinkFrom, setDrinkFrom] = useState('');
    const [drinkTo, setDrinkTo] = useState('');

    // Fetch existing bottle data
    const { data: bottle, isLoading: isLoadingBottle } = useQuery({
        queryKey: ['bottle', bottleId],
        queryFn: () => api.getBottle(bottleId),
    });

    // Populate form when bottle data loads
    useEffect(() => {
        if (bottle) {
            setName(bottle.name || '');
            setWinery(bottle.winery || '');
            setVintage(bottle.vintage ? String(bottle.vintage) : '');
            setRegion(bottle.region || '');
            setCountry(bottle.country || '');
            setGrapes(bottle.grapes || '');
            setType(bottle.type || '');
            setStyle(bottle.style || '');
            setPricePaid(bottle.price_paid !== null ? String(bottle.price_paid) : '');
            setMyRating(bottle.my_rating !== null ? String(bottle.my_rating) : '');
            setMyReview(bottle.my_review || '');
            setLocation(bottle.location || '');
            setDrinkFrom(bottle.drink_from || '');
            setDrinkTo(bottle.drink_to || '');
        }
    }, [bottle]);

    // Update mutation
    const updateMutation = useMutation({
        mutationFn: (updates: Record<string, any>) => api.updateBottle(bottleId, updates),
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['bottle', bottleId] });
            queryClient.invalidateQueries({ queryKey: ['inventory'] });
            Alert.alert('Success', 'Bottle updated!', [
                { text: 'OK', onPress: () => navigation.goBack() },
            ]);
        },
        onError: (error) => {
            Alert.alert('Error', error instanceof Error ? error.message : 'Failed to update bottle');
        },
    });

    const handleSave = useCallback(() => {
        if (!name.trim()) {
            Alert.alert('Required', 'Please enter a wine name');
            return;
        }

        const updates: Record<string, any> = {
            name: name.trim(),
            winery: winery.trim(),
            vintage: vintage ? parseInt(vintage, 10) : null,
            region: region.trim(),
            country: country.trim(),
            grapes: grapes.trim(),
            type: type,
            style: style.trim(),
            price_paid: pricePaid ? parseFloat(pricePaid) : null,
            my_rating: myRating ? parseFloat(myRating) : null,
            my_review: myReview.trim(),
            location: location.trim(),
            drink_from: drinkFrom.trim() || null,
            drink_to: drinkTo.trim() || null,
        };

        updateMutation.mutate(updates);
    }, [
        name, winery, vintage, region, country, grapes, type, style,
        pricePaid, myRating, myReview, location, drinkFrom, drinkTo,
        updateMutation,
    ]);

    const renderInput = (
        label: string,
        value: string,
        onChangeText: (text: string) => void,
        options?: {
            placeholder?: string;
            keyboardType?: 'default' | 'numeric' | 'decimal-pad';
            multiline?: boolean;
        }
    ) => (
        <View style={styles.inputGroup}>
            <Text style={styles.label}>{label}</Text>
            <TextInput
                style={[styles.input, options?.multiline && styles.inputMultiline]}
                value={value}
                onChangeText={onChangeText}
                placeholder={options?.placeholder || `Enter ${label.toLowerCase()}`}
                placeholderTextColor={colors.gray}
                keyboardType={options?.keyboardType || 'default'}
                multiline={options?.multiline}
                editable={!updateMutation.isPending}
            />
        </View>
    );

    if (isLoadingBottle) {
        return (
            <SafeAreaView style={styles.container}>
                <View style={styles.loadingContainer}>
                    <ActivityIndicator size="large" color={colors.primary} />
                    <Text style={styles.loadingText}>Loading bottle...</Text>
                </View>
            </SafeAreaView>
        );
    }

    return (
        <SafeAreaView style={styles.container} edges={['top']}>
            <KeyboardAvoidingView
                style={styles.flex}
                behavior={Platform.OS === 'ios' ? 'padding' : undefined}
            >
                {/* Header */}
                <View style={styles.header}>
                    <TouchableOpacity onPress={() => navigation.goBack()}>
                        <Ionicons name="close" size={28} color={colors.text} />
                    </TouchableOpacity>
                    <Text style={styles.headerTitle}>Edit Bottle</Text>
                    <TouchableOpacity
                        onPress={handleSave}
                        disabled={updateMutation.isPending}
                    >
                        {updateMutation.isPending ? (
                            <ActivityIndicator color={colors.primary} />
                        ) : (
                            <Text style={styles.saveButton}>Save</Text>
                        )}
                    </TouchableOpacity>
                </View>

                <ScrollView
                    style={styles.scrollView}
                    contentContainerStyle={styles.scrollContent}
                    showsVerticalScrollIndicator={false}
                >
                    {/* Basic Info */}
                    <Text style={styles.sectionTitle}>Wine Details</Text>

                    {renderInput('Name *', name, setName, { placeholder: 'e.g., Opus One' })}
                    {renderInput('Winery', winery, setWinery, { placeholder: 'e.g., Opus One Winery' })}

                    <View style={styles.row}>
                        <View style={[styles.inputGroup, styles.flex]}>
                            <Text style={styles.label}>Vintage</Text>
                            <TextInput
                                style={styles.input}
                                value={vintage}
                                onChangeText={setVintage}
                                placeholder="e.g., 2019"
                                placeholderTextColor={colors.gray}
                                keyboardType="numeric"
                                maxLength={4}
                                editable={!updateMutation.isPending}
                            />
                        </View>
                        <View style={styles.rowSpacer} />
                        <View style={[styles.inputGroup, styles.flex]}>
                            <Text style={styles.label}>Style</Text>
                            <TextInput
                                style={styles.input}
                                value={style}
                                onChangeText={setStyle}
                                placeholder="e.g., Full-bodied"
                                placeholderTextColor={colors.gray}
                                editable={!updateMutation.isPending}
                            />
                        </View>
                    </View>

                    {/* Wine Type */}
                    <View style={styles.inputGroup}>
                        <Text style={styles.label}>Type</Text>
                        <View style={styles.typeRow}>
                            {WINE_TYPES.map((t) => (
                                <TouchableOpacity
                                    key={t}
                                    style={[styles.typeButton, type === t && styles.typeButtonActive]}
                                    onPress={() => setType(type === t ? '' : t)}
                                    disabled={updateMutation.isPending}
                                >
                                    <Text
                                        style={[styles.typeButtonText, type === t && styles.typeButtonTextActive]}
                                    >
                                        {t}
                                    </Text>
                                </TouchableOpacity>
                            ))}
                        </View>
                    </View>

                    {renderInput('Grapes', grapes, setGrapes, {
                        placeholder: 'e.g., Cabernet Sauvignon, Merlot',
                    })}

                    <Text style={styles.sectionTitle}>Origin</Text>

                    {renderInput('Region', region, setRegion, { placeholder: 'e.g., Napa Valley' })}
                    {renderInput('Country', country, setCountry, { placeholder: 'e.g., USA' })}

                    <Text style={styles.sectionTitle}>Your Collection</Text>

                    <View style={styles.row}>
                        <View style={[styles.inputGroup, styles.flex]}>
                            <Text style={styles.label}>Price Paid ($)</Text>
                            <TextInput
                                style={styles.input}
                                value={pricePaid}
                                onChangeText={setPricePaid}
                                placeholder="0.00"
                                placeholderTextColor={colors.gray}
                                keyboardType="decimal-pad"
                                editable={!updateMutation.isPending}
                            />
                        </View>
                        <View style={styles.rowSpacer} />
                        <View style={[styles.inputGroup, styles.flex]}>
                            <Text style={styles.label}>My Rating (0-5)</Text>
                            <TextInput
                                style={styles.input}
                                value={myRating}
                                onChangeText={setMyRating}
                                placeholder="e.g., 4.5"
                                placeholderTextColor={colors.gray}
                                keyboardType="decimal-pad"
                                editable={!updateMutation.isPending}
                            />
                        </View>
                    </View>

                    {renderInput('Location', location, setLocation, { placeholder: 'e.g., Rack A, Slot 3' })}

                    <Text style={styles.sectionTitle}>Drink Window</Text>

                    <View style={styles.row}>
                        <View style={[styles.inputGroup, styles.flex]}>
                            <Text style={styles.label}>Drink From</Text>
                            <TextInput
                                style={styles.input}
                                value={drinkFrom}
                                onChangeText={setDrinkFrom}
                                placeholder="e.g., 2024"
                                placeholderTextColor={colors.gray}
                                editable={!updateMutation.isPending}
                            />
                        </View>
                        <View style={styles.rowSpacer} />
                        <View style={[styles.inputGroup, styles.flex]}>
                            <Text style={styles.label}>Drink To</Text>
                            <TextInput
                                style={styles.input}
                                value={drinkTo}
                                onChangeText={setDrinkTo}
                                placeholder="e.g., 2035"
                                placeholderTextColor={colors.gray}
                                editable={!updateMutation.isPending}
                            />
                        </View>
                    </View>

                    <Text style={styles.sectionTitle}>Tasting Notes</Text>

                    {renderInput('My Review', myReview, setMyReview, {
                        placeholder: 'Your personal tasting notes...',
                        multiline: true,
                    })}

                    {/* Spacer at bottom */}
                    <View style={{ height: spacing.xxl }} />
                </ScrollView>
            </KeyboardAvoidingView>
        </SafeAreaView>
    );
}

const styles = StyleSheet.create({
    container: {
        flex: 1,
        backgroundColor: colors.background,
    },
    flex: {
        flex: 1,
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
    header: {
        flexDirection: 'row',
        alignItems: 'center',
        justifyContent: 'space-between',
        paddingHorizontal: spacing.lg,
        paddingVertical: spacing.md,
        borderBottomWidth: 1,
        borderBottomColor: colors.border,
    },
    headerTitle: {
        fontSize: fontSize.lg,
        fontWeight: '600',
        color: colors.text,
    },
    saveButton: {
        fontSize: fontSize.md,
        fontWeight: '600',
        color: colors.primary,
    },
    scrollView: {
        flex: 1,
    },
    scrollContent: {
        padding: spacing.lg,
        paddingBottom: spacing.xxl,
    },
    sectionTitle: {
        fontSize: fontSize.sm,
        fontWeight: '600',
        color: colors.textMuted,
        textTransform: 'uppercase',
        letterSpacing: 0.5,
        marginTop: spacing.lg,
        marginBottom: spacing.md,
    },
    inputGroup: {
        marginBottom: spacing.md,
    },
    label: {
        fontSize: fontSize.sm,
        fontWeight: '600',
        color: colors.text,
        marginBottom: spacing.xs,
    },
    input: {
        backgroundColor: colors.offWhite,
        borderWidth: 1,
        borderColor: colors.border,
        borderRadius: borderRadius.md,
        padding: spacing.md,
        fontSize: fontSize.md,
        color: colors.text,
    },
    inputMultiline: {
        height: 120,
        textAlignVertical: 'top',
    },
    row: {
        flexDirection: 'row',
    },
    rowSpacer: {
        width: spacing.md,
    },
    typeRow: {
        flexDirection: 'row',
        flexWrap: 'wrap',
        gap: spacing.sm,
    },
    typeButton: {
        paddingHorizontal: spacing.md,
        paddingVertical: spacing.sm,
        borderRadius: borderRadius.full,
        backgroundColor: colors.offWhite,
        borderWidth: 1,
        borderColor: colors.border,
    },
    typeButtonActive: {
        backgroundColor: colors.primary,
        borderColor: colors.primary,
    },
    typeButtonText: {
        fontSize: fontSize.sm,
        color: colors.text,
    },
    typeButtonTextActive: {
        color: colors.white,
        fontWeight: '600',
    },
});