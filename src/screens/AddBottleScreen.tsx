import React, { useState, useCallback, useRef } from 'react';
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
    Image,
    Modal,
    FlatList,
} from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { Ionicons } from '@expo/vector-icons';
import { useNavigation } from '@react-navigation/native';
import type { NativeStackNavigationProp } from '@react-navigation/native-stack';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import * as ImagePicker from 'expo-image-picker';
import * as ImageManipulator from 'expo-image-manipulator';

import { api } from '../services/api';
import { getImageUrl } from '../config/api';
import { colors, spacing, fontSize, borderRadius, shadows } from '../theme';
import type { RootStackParamList } from '../types';

type NavigationProp = NativeStackNavigationProp<RootStackParamList>;

const WINE_TYPES = ['Red', 'White', 'Rosé', 'Sparkling', 'Dessert', 'Fortified'];

interface CatalogWine {
    id: number;
    wine_id: number | null;
    name: string;
    winery: string;
    region: string;
    country: string;
    grapes: string;
    vintage: number | null;
    type: string;
    style: string;
    image_url: string;
    rating: number | null;
    price: number | null;
}

interface AIParseResult {
    ai_parsed: {
        name: string;
        winery: string;
        vintage: string;
        grapes: string;
        region: string;
        country: string;
        type: string;
        style: string;
    };
    image_path: string | null;
    catalog_matches: CatalogWine[];
}

type EntryMode = 'manual' | 'search' | 'camera';

export function AddBottleScreen() {
    const navigation = useNavigation<NavigationProp>();
    const queryClient = useQueryClient();
    const searchDebounceRef = useRef<NodeJS.Timeout>();

    // Entry mode
    const [entryMode, setEntryMode] = useState<EntryMode>('manual');

    // Form state
    const [name, setName] = useState('');
    const [winery, setWinery] = useState('');
    const [vintage, setVintage] = useState('');
    const [region, setRegion] = useState('');
    const [country, setCountry] = useState('');
    const [grapes, setGrapes] = useState('');
    const [type, setType] = useState('');
    const [pricePaid, setPricePaid] = useState('');
    const [location, setLocation] = useState('');
    const [quantity, setQuantity] = useState('1');
    const [catalogWineId, setCatalogWineId] = useState<number | null>(null);
    const [imageUrl, setImageUrl] = useState('');

    // Search state
    const [searchQuery, setSearchQuery] = useState('');
    const [searchResults, setSearchResults] = useState<CatalogWine[]>([]);
    const [isSearching, setIsSearching] = useState(false);
    const [showSearchResults, setShowSearchResults] = useState(false);

    // AI analysis state
    const [selectedImage, setSelectedImage] = useState<string | null>(null);
    const [isAnalyzing, setIsAnalyzing] = useState(false);
    const [aiMatches, setAiMatches] = useState<CatalogWine[]>([]);
    const [showAiResults, setShowAiResults] = useState(false);

    // Add bottle mutation
    const addMutation = useMutation({
        mutationFn: () =>
            api.addBottle({
                name: name.trim(),
                winery: winery.trim(),
                vintage: vintage ? parseInt(vintage, 10) : undefined,
                region: region.trim(),
                country: country.trim(),
                grapes: grapes.trim(),
                type: type,
                price_paid: pricePaid ? parseFloat(pricePaid) : undefined,
                location: location.trim(),
                quantity: parseInt(quantity, 10) || 1,
                wine_id: catalogWineId || undefined,
                image_url: imageUrl || undefined,
            }),
        onSuccess: (data) => {
            queryClient.invalidateQueries({ queryKey: ['inventory'] });
            const count = data.bottle_ids?.length || 1;
            Alert.alert(
                'Success',
                count > 1 ? `Added ${count} bottles to your cellar!` : 'Bottle added to your cellar!',
                [{ text: 'OK', onPress: () => navigation.goBack() }]
            );
        },
        onError: (error) => {
            Alert.alert('Error', error instanceof Error ? error.message : 'Failed to add bottle');
        },
    });

    // Prefill form from catalog wine
    const prefillFromCatalog = useCallback((wine: CatalogWine) => {
        setName(wine.name || '');
        setWinery(wine.winery || '');
        setVintage(wine.vintage ? String(wine.vintage) : '');
        setRegion(wine.region || '');
        setCountry(wine.country || '');
        setGrapes(wine.grapes || '');
        setType(wine.type || '');
        setCatalogWineId(wine.id);
        setImageUrl(wine.image_url || '');
        setShowSearchResults(false);
        setShowAiResults(false);
        setEntryMode('manual');
    }, []);

    // Search catalog
    const searchCatalog = useCallback(async (query: string) => {
        if (query.trim().length < 2) {
            setSearchResults([]);
            return;
        }

        setIsSearching(true);
        try {
            const response = await api.searchCatalog(query.trim());
            setSearchResults(response.wines || []);
            setShowSearchResults(true);
        } catch (error) {
            console.error('Search error:', error);
            setSearchResults([]);
        } finally {
            setIsSearching(false);
        }
    }, []);

    // Debounced search
    const handleSearchChange = useCallback((text: string) => {
        setSearchQuery(text);
        if (searchDebounceRef.current) {
            clearTimeout(searchDebounceRef.current);
        }
        searchDebounceRef.current = setTimeout(() => {
            searchCatalog(text);
        }, 300);
    }, [searchCatalog]);

    /**
     * Convert image to JPEG format using ImageManipulator
     * This handles HEIC and other formats that may not be supported by the backend
     */
    const convertImageToJpeg = async (uri: string): Promise<{ uri: string; base64: string }> => {
        try {
            const manipResult = await ImageManipulator.manipulateAsync(
                uri,
                [{ resize: { width: 1200 } }], // Resize to reasonable size
                {
                    compress: 0.8,
                    format: ImageManipulator.SaveFormat.JPEG,
                    base64: true,
                }
            );

            return {
                uri: manipResult.uri,
                base64: manipResult.base64 || '',
            };
        } catch (error) {
            console.error('Image conversion error:', error);
            throw new Error('Failed to process image');
        }
    };

    // Pick image from library
    const pickImage = useCallback(async () => {
        const permission = await ImagePicker.requestMediaLibraryPermissionsAsync();
        if (!permission.granted) {
            Alert.alert('Permission needed', 'Please allow access to your photo library');
            return;
        }

        const result = await ImagePicker.launchImageLibraryAsync({
            mediaTypes: ImagePicker.MediaTypeOptions.Images,
            quality: 0.8,
            // Don't request base64 here - we'll convert with ImageManipulator
        });

        if (!result.canceled && result.assets[0]) {
            setSelectedImage(result.assets[0].uri);

            // Convert to JPEG and get base64
            setIsAnalyzing(true);
            try {
                const converted = await convertImageToJpeg(result.assets[0].uri);
                await analyzeImage(converted.base64);
            } catch (error) {
                Alert.alert('Error', 'Failed to process image');
                setIsAnalyzing(false);
            }
        }
    }, []);

    // Take photo with camera
    const takePhoto = useCallback(async () => {
        const permission = await ImagePicker.requestCameraPermissionsAsync();
        if (!permission.granted) {
            Alert.alert('Permission needed', 'Please allow camera access');
            return;
        }

        const result = await ImagePicker.launchCameraAsync({
            quality: 0.8,
            // Don't request base64 here - we'll convert with ImageManipulator
        });

        if (!result.canceled && result.assets[0]) {
            setSelectedImage(result.assets[0].uri);

            // Convert to JPEG and get base64
            setIsAnalyzing(true);
            try {
                const converted = await convertImageToJpeg(result.assets[0].uri);
                await analyzeImage(converted.base64);
            } catch (error) {
                Alert.alert('Error', 'Failed to process image');
                setIsAnalyzing(false);
            }
        }
    }, []);

    // Analyze image with AI
    const analyzeImage = useCallback(async (base64: string) => {
        setShowAiResults(false);

        try {
            // Send with proper JPEG data URL prefix
            const response = await api.analyzeLabel(`data:image/jpeg;base64,${base64}`);

            // Prefill form with AI results
            const ai = response.ai_parsed;
            setName(ai.name || '');
            setWinery(ai.winery || '');
            setVintage(ai.vintage || '');
            setRegion(ai.region || '');
            setCountry(ai.country || '');
            setGrapes(ai.grapes || '');
            setType(ai.type || '');

            if (response.image_path) {
                setImageUrl(response.image_path);
            }

            // Show catalog matches if any
            if (response.catalog_matches && response.catalog_matches.length > 0) {
                setAiMatches(response.catalog_matches);
                setShowAiResults(true);
            } else {
                setEntryMode('manual');
            }
        } catch (error) {
            console.error('Analysis error:', error);
            Alert.alert('Analysis Failed', error instanceof Error ? error.message : 'Could not analyze image');
            setEntryMode('manual');
        } finally {
            setIsAnalyzing(false);
        }
    }, []);

    const handleSave = useCallback(() => {
        if (!name.trim()) {
            Alert.alert('Required', 'Please enter a wine name');
            return;
        }
        addMutation.mutate();
    }, [name, addMutation]);

    // Render catalog search result item
    const renderSearchResult = useCallback(({ item }: { item: CatalogWine }) => (
        <TouchableOpacity
            style={styles.searchResultItem}
            onPress={() => prefillFromCatalog(item)}
        >
            {item.image_url ? (
                <Image
                    source={{ uri: getImageUrl(item.image_url) || undefined }}
                    style={styles.searchResultImage}
                    resizeMode="cover"
                />
            ) : (
                <View style={[styles.searchResultImage, styles.searchResultPlaceholder]}>
                    <Text style={styles.searchResultPlaceholderText}>🍷</Text>
                </View>
            )}
            <View style={styles.searchResultContent}>
                <Text style={styles.searchResultName} numberOfLines={1}>
                    {item.vintage ? `${item.vintage} ` : ''}{item.name}
                </Text>
                <Text style={styles.searchResultWinery} numberOfLines={1}>
                    {item.winery}
                </Text>
                <Text style={styles.searchResultMeta} numberOfLines={1}>
                    {[item.region, item.type].filter(Boolean).join(' • ')}
                </Text>
            </View>
            <Ionicons name="chevron-forward" size={20} color={colors.gray} />
        </TouchableOpacity>
    ), [prefillFromCatalog]);

    // Mode selector buttons
    const renderModeSelector = () => (
        <View style={styles.modeSelector}>
            <TouchableOpacity
                style={[styles.modeButton, entryMode === 'manual' && styles.modeButtonActive]}
                onPress={() => setEntryMode('manual')}
            >
                <Ionicons
                    name="create-outline"
                    size={20}
                    color={entryMode === 'manual' ? colors.white : colors.text}
                />
                <Text style={[styles.modeButtonText, entryMode === 'manual' && styles.modeButtonTextActive]}>
                    Manual
                </Text>
            </TouchableOpacity>

            <TouchableOpacity
                style={[styles.modeButton, entryMode === 'search' && styles.modeButtonActive]}
                onPress={() => setEntryMode('search')}
            >
                <Ionicons
                    name="search-outline"
                    size={20}
                    color={entryMode === 'search' ? colors.white : colors.text}
                />
                <Text style={[styles.modeButtonText, entryMode === 'search' && styles.modeButtonTextActive]}>
                    Search
                </Text>
            </TouchableOpacity>

            <TouchableOpacity
                style={[styles.modeButton, entryMode === 'camera' && styles.modeButtonActive]}
                onPress={() => setEntryMode('camera')}
            >
                <Ionicons
                    name="camera-outline"
                    size={20}
                    color={entryMode === 'camera' ? colors.white : colors.text}
                />
                <Text style={[styles.modeButtonText, entryMode === 'camera' && styles.modeButtonTextActive]}>
                    Scan
                </Text>
            </TouchableOpacity>
        </View>
    );

    // Search mode content
    const renderSearchMode = () => (
        <View style={styles.searchContainer}>
            <View style={styles.searchInputContainer}>
                <Ionicons name="search" size={20} color={colors.gray} style={styles.searchIcon} />
                <TextInput
                    style={styles.searchInput}
                    value={searchQuery}
                    onChangeText={handleSearchChange}
                    placeholder="Search wines by name, winery, region..."
                    placeholderTextColor={colors.gray}
                    autoFocus
                />
                {isSearching && <ActivityIndicator size="small" color={colors.primary} />}
            </View>

            {showSearchResults && searchResults.length > 0 && (
                <FlatList
                    data={searchResults}
                    renderItem={renderSearchResult}
                    keyExtractor={(item) => String(item.id)}
                    style={styles.searchResultsList}
                    ItemSeparatorComponent={() => <View style={styles.separator} />}
                />
            )}

            {showSearchResults && searchResults.length === 0 && searchQuery.length >= 2 && !isSearching && (
                <View style={styles.noResults}>
                    <Text style={styles.noResultsText}>No wines found</Text>
                    <TouchableOpacity onPress={() => setEntryMode('manual')}>
                        <Text style={styles.noResultsLink}>Add manually instead</Text>
                    </TouchableOpacity>
                </View>
            )}
        </View>
    );

    // Camera/scan mode content
    const renderCameraMode = () => (
        <View style={styles.cameraContainer}>
            {selectedImage ? (
                <View style={styles.previewContainer}>
                    <Image source={{ uri: selectedImage }} style={styles.previewImage} resizeMode="cover" />
                    {isAnalyzing && (
                        <View style={styles.analyzingOverlay}>
                            <ActivityIndicator size="large" color={colors.white} />
                            <Text style={styles.analyzingText}>Analyzing label...</Text>
                        </View>
                    )}
                </View>
            ) : (
                <View style={styles.cameraPlaceholder}>
                    <Ionicons name="wine" size={64} color={colors.gray} />
                    <Text style={styles.cameraPlaceholderText}>
                        Take a photo of a wine label or select from your library
                    </Text>
                </View>
            )}

            <View style={styles.cameraButtons}>
                <TouchableOpacity style={styles.cameraButton} onPress={takePhoto}>
                    <Ionicons name="camera" size={24} color={colors.white} />
                    <Text style={styles.cameraButtonText}>Take Photo</Text>
                </TouchableOpacity>

                <TouchableOpacity style={[styles.cameraButton, styles.cameraButtonSecondary]} onPress={pickImage}>
                    <Ionicons name="images" size={24} color={colors.primary} />
                    <Text style={[styles.cameraButtonText, styles.cameraButtonTextSecondary]}>Library</Text>
                </TouchableOpacity>
            </View>
        </View>
    );

    // Manual entry form
    const renderManualForm = () => (
        <ScrollView style={styles.formScroll} showsVerticalScrollIndicator={false}>
            {/* Selected wine indicator */}
            {catalogWineId && (
                <View style={styles.selectedWineBar}>
                    <Ionicons name="checkmark-circle" size={18} color={colors.success} />
                    <Text style={styles.selectedWineText}>Linked to catalog</Text>
                    <TouchableOpacity onPress={() => setCatalogWineId(null)}>
                        <Ionicons name="close-circle" size={18} color={colors.gray} />
                    </TouchableOpacity>
                </View>
            )}

            <Text style={styles.sectionTitle}>Wine Details</Text>

            <View style={styles.inputGroup}>
                <Text style={styles.label}>Name *</Text>
                <TextInput
                    style={styles.input}
                    value={name}
                    onChangeText={setName}
                    placeholder="e.g., Opus One"
                    placeholderTextColor={colors.gray}
                />
            </View>

            <View style={styles.inputGroup}>
                <Text style={styles.label}>Winery</Text>
                <TextInput
                    style={styles.input}
                    value={winery}
                    onChangeText={setWinery}
                    placeholder="e.g., Opus One Winery"
                    placeholderTextColor={colors.gray}
                />
            </View>

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
                    />
                </View>
                <View style={styles.rowSpacer} />
                <View style={[styles.inputGroup, styles.flex]}>
                    <Text style={styles.label}>Quantity</Text>
                    <TextInput
                        style={styles.input}
                        value={quantity}
                        onChangeText={setQuantity}
                        placeholder="1"
                        placeholderTextColor={colors.gray}
                        keyboardType="numeric"
                        maxLength={2}
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
                        >
                            <Text style={[styles.typeButtonText, type === t && styles.typeButtonTextActive]}>
                                {t}
                            </Text>
                        </TouchableOpacity>
                    ))}
                </View>
            </View>

            <View style={styles.inputGroup}>
                <Text style={styles.label}>Grapes</Text>
                <TextInput
                    style={styles.input}
                    value={grapes}
                    onChangeText={setGrapes}
                    placeholder="e.g., Cabernet Sauvignon, Merlot"
                    placeholderTextColor={colors.gray}
                />
            </View>

            <Text style={styles.sectionTitle}>Origin</Text>

            <View style={styles.inputGroup}>
                <Text style={styles.label}>Region</Text>
                <TextInput
                    style={styles.input}
                    value={region}
                    onChangeText={setRegion}
                    placeholder="e.g., Napa Valley"
                    placeholderTextColor={colors.gray}
                />
            </View>

            <View style={styles.inputGroup}>
                <Text style={styles.label}>Country</Text>
                <TextInput
                    style={styles.input}
                    value={country}
                    onChangeText={setCountry}
                    placeholder="e.g., USA"
                    placeholderTextColor={colors.gray}
                />
            </View>

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
                    />
                </View>
                <View style={styles.rowSpacer} />
                <View style={[styles.inputGroup, styles.flex]}>
                    <Text style={styles.label}>Location</Text>
                    <TextInput
                        style={styles.input}
                        value={location}
                        onChangeText={setLocation}
                        placeholder="e.g., Rack A"
                        placeholderTextColor={colors.gray}
                    />
                </View>
            </View>

            {/* Save button at bottom of form */}
            <TouchableOpacity
                style={[styles.saveButton, addMutation.isPending && styles.saveButtonDisabled]}
                onPress={handleSave}
                disabled={addMutation.isPending}
            >
                {addMutation.isPending ? (
                    <ActivityIndicator color={colors.white} />
                ) : (
                    <Text style={styles.saveButtonText}>Add to Cellar</Text>
                )}
            </TouchableOpacity>

            <View style={{ height: spacing.xxl }} />
        </ScrollView>
    );

    // AI matches modal
    const renderAiMatchesModal = () => (
        <Modal visible={showAiResults} animationType="slide" transparent>
            <View style={styles.modalOverlay}>
                <View style={styles.modalContent}>
                    <View style={styles.modalHeader}>
                        <Text style={styles.modalTitle}>We found matches!</Text>
                        <TouchableOpacity onPress={() => { setShowAiResults(false); setEntryMode('manual'); }}>
                            <Ionicons name="close" size={24} color={colors.text} />
                        </TouchableOpacity>
                    </View>

                    <Text style={styles.modalSubtitle}>
                        Select a wine from our catalog or continue with AI results
                    </Text>

                    <FlatList
                        data={aiMatches}
                        renderItem={renderSearchResult}
                        keyExtractor={(item) => String(item.id)}
                        style={styles.modalList}
                        ItemSeparatorComponent={() => <View style={styles.separator} />}
                    />

                    <TouchableOpacity
                        style={styles.modalSkipButton}
                        onPress={() => { setShowAiResults(false); setEntryMode('manual'); }}
                    >
                        <Text style={styles.modalSkipText}>Use AI results without linking</Text>
                    </TouchableOpacity>
                </View>
            </View>
        </Modal>
    );

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
                    <Text style={styles.headerTitle}>Add Bottle</Text>
                    <View style={{ width: 28 }} />
                </View>

                {/* Mode selector */}
                {renderModeSelector()}

                {/* Content based on mode */}
                <View style={styles.content}>
                    {entryMode === 'manual' && renderManualForm()}
                    {entryMode === 'search' && renderSearchMode()}
                    {entryMode === 'camera' && renderCameraMode()}
                </View>

                {/* AI matches modal */}
                {renderAiMatchesModal()}
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
    modeSelector: {
        flexDirection: 'row',
        padding: spacing.md,
        gap: spacing.sm,
    },
    modeButton: {
        flex: 1,
        flexDirection: 'row',
        alignItems: 'center',
        justifyContent: 'center',
        gap: spacing.xs,
        paddingVertical: spacing.sm,
        borderRadius: borderRadius.md,
        backgroundColor: colors.offWhite,
        borderWidth: 1,
        borderColor: colors.border,
    },
    modeButtonActive: {
        backgroundColor: colors.primary,
        borderColor: colors.primary,
    },
    modeButtonText: {
        fontSize: fontSize.sm,
        fontWeight: '600',
        color: colors.text,
    },
    modeButtonTextActive: {
        color: colors.white,
    },
    content: {
        flex: 1,
    },
    // Form styles
    formScroll: {
        flex: 1,
        padding: spacing.lg,
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
    selectedWineBar: {
        flexDirection: 'row',
        alignItems: 'center',
        gap: spacing.sm,
        padding: spacing.sm,
        backgroundColor: colors.offWhite,
        borderRadius: borderRadius.md,
        marginBottom: spacing.md,
    },
    selectedWineText: {
        flex: 1,
        fontSize: fontSize.sm,
        color: colors.success,
    },
    saveButton: {
        backgroundColor: colors.primary,
        borderRadius: borderRadius.md,
        padding: spacing.md,
        alignItems: 'center',
        marginTop: spacing.lg,
    },
    saveButtonDisabled: {
        opacity: 0.7,
    },
    saveButtonText: {
        color: colors.white,
        fontSize: fontSize.lg,
        fontWeight: '600',
    },
    // Search styles
    searchContainer: {
        flex: 1,
        padding: spacing.md,
    },
    searchInputContainer: {
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
    searchResultsList: {
        flex: 1,
    },
    searchResultItem: {
        flexDirection: 'row',
        alignItems: 'center',
        padding: spacing.md,
        backgroundColor: colors.card,
        borderRadius: borderRadius.md,
    },
    searchResultImage: {
        width: 50,
        height: 70,
        borderRadius: borderRadius.sm,
        marginRight: spacing.md,
    },
    searchResultPlaceholder: {
        backgroundColor: colors.primary,
        alignItems: 'center',
        justifyContent: 'center',
    },
    searchResultPlaceholderText: {
        fontSize: 24,
    },
    searchResultContent: {
        flex: 1,
    },
    searchResultName: {
        fontSize: fontSize.md,
        fontWeight: '600',
        color: colors.text,
    },
    searchResultWinery: {
        fontSize: fontSize.sm,
        color: colors.textSecondary,
    },
    searchResultMeta: {
        fontSize: fontSize.sm,
        color: colors.textMuted,
    },
    separator: {
        height: spacing.sm,
    },
    noResults: {
        alignItems: 'center',
        padding: spacing.xl,
    },
    noResultsText: {
        fontSize: fontSize.md,
        color: colors.textSecondary,
        marginBottom: spacing.sm,
    },
    noResultsLink: {
        fontSize: fontSize.md,
        color: colors.primary,
        fontWeight: '600',
    },
    // Camera styles
    cameraContainer: {
        flex: 1,
        padding: spacing.lg,
    },
    previewContainer: {
        flex: 1,
        borderRadius: borderRadius.lg,
        overflow: 'hidden',
        marginBottom: spacing.lg,
    },
    previewImage: {
        width: '100%',
        height: '100%',
    },
    analyzingOverlay: {
        ...StyleSheet.absoluteFillObject,
        backgroundColor: colors.overlay,
        alignItems: 'center',
        justifyContent: 'center',
    },
    analyzingText: {
        marginTop: spacing.md,
        fontSize: fontSize.md,
        color: colors.white,
        fontWeight: '600',
    },
    cameraPlaceholder: {
        flex: 1,
        alignItems: 'center',
        justifyContent: 'center',
        backgroundColor: colors.offWhite,
        borderRadius: borderRadius.lg,
        marginBottom: spacing.lg,
    },
    cameraPlaceholderText: {
        marginTop: spacing.md,
        fontSize: fontSize.md,
        color: colors.textSecondary,
        textAlign: 'center',
        paddingHorizontal: spacing.xl,
    },
    cameraButtons: {
        flexDirection: 'row',
        gap: spacing.md,
    },
    cameraButton: {
        flex: 1,
        flexDirection: 'row',
        alignItems: 'center',
        justifyContent: 'center',
        gap: spacing.sm,
        backgroundColor: colors.primary,
        borderRadius: borderRadius.md,
        padding: spacing.md,
    },
    cameraButtonSecondary: {
        backgroundColor: colors.white,
        borderWidth: 1,
        borderColor: colors.primary,
    },
    cameraButtonText: {
        fontSize: fontSize.md,
        fontWeight: '600',
        color: colors.white,
    },
    cameraButtonTextSecondary: {
        color: colors.primary,
    },
    // Modal styles
    modalOverlay: {
        flex: 1,
        backgroundColor: colors.overlay,
        justifyContent: 'flex-end',
    },
    modalContent: {
        backgroundColor: colors.background,
        borderTopLeftRadius: borderRadius.xl,
        borderTopRightRadius: borderRadius.xl,
        padding: spacing.lg,
        maxHeight: '80%',
    },
    modalHeader: {
        flexDirection: 'row',
        alignItems: 'center',
        justifyContent: 'space-between',
        marginBottom: spacing.sm,
    },
    modalTitle: {
        fontSize: fontSize.xl,
        fontWeight: '700',
        color: colors.text,
    },
    modalSubtitle: {
        fontSize: fontSize.sm,
        color: colors.textSecondary,
        marginBottom: spacing.md,
    },
    modalList: {
        maxHeight: 300,
    },
    modalSkipButton: {
        alignItems: 'center',
        padding: spacing.md,
        marginTop: spacing.md,
    },
    modalSkipText: {
        fontSize: fontSize.md,
        color: colors.primary,
        fontWeight: '600',
    },
});