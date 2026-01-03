import { Appearance } from 'react-native';

// Wine-themed color palette
export const colors = {
    // Primary - Deep wine red
    primary: '#722F37',
    primaryLight: '#8B3A44',
    primaryDark: '#5A252C',

    // Secondary - Warm gold
    secondary: '#D4AF37',
    secondaryLight: '#E5C158',
    secondaryDark: '#B8962E',

    // Accent - Sage green (for success/freshness)
    accent: '#7D8471',
    accentLight: '#9BA38F',
    accentDark: '#656B5A',

    // Neutrals
    white: '#FFFFFF',
    offWhite: '#F8F7F5',
    cream: '#FAF9F6',
    lightGray: '#E8E6E3',
    gray: '#9A9A9A',
    darkGray: '#4A4A4A',
    charcoal: '#2D2D2D',
    black: '#1A1A1A',

    // Semantic colors
    success: '#4CAF50',
    warning: '#FFC107',
    error: '#D32F2F',
    info: '#2196F3',

    // Wine type colors
    redWine: '#722F37',
    whiteWine: '#F5E6C8',
    roseWine: '#E8B4B8',
    sparklingWine: '#FFE4B5',
    dessertWine: '#C19A6B',

    // Backgrounds
    background: '#FFFFFF',
    backgroundSecondary: '#F8F7F5',
    card: '#FFFFFF',

    // Text
    text: '#1A1A1A',
    textSecondary: '#666666',
    textMuted: '#9A9A9A',
    textInverse: '#FFFFFF',

    // Borders
    border: '#E8E6E3',
    borderLight: '#F0EFED',

    // Overlay
    overlay: 'rgba(0, 0, 0, 0.5)',
} as const;

// Dark mode colors
export const darkColors = {
    ...colors,

    // Override for dark mode
    background: '#1A1A1A',
    backgroundSecondary: '#242424',
    card: '#2D2D2D',

    text: '#F8F7F5',
    textSecondary: '#B0B0B0',
    textMuted: '#808080',

    border: '#404040',
    borderLight: '#333333',

    lightGray: '#404040',
} as const;

// Spacing scale
export const spacing = {
    xs: 4,
    sm: 8,
    md: 16,
    lg: 24,
    xl: 32,
    xxl: 48,
} as const;

// Font sizes
export const fontSize = {
    xs: 10,
    sm: 12,
    md: 14,
    lg: 16,
    xl: 18,
    xxl: 24,
    xxxl: 32,
} as const;

// Font weights
export const fontWeight = {
    normal: '400' as const,
    medium: '500' as const,
    semibold: '600' as const,
    bold: '700' as const,
};

// Border radius
export const borderRadius = {
    sm: 4,
    md: 8,
    lg: 12,
    xl: 16,
    full: 9999,
} as const;

// Shadows
export const shadows = {
    sm: {
        shadowColor: '#000',
        shadowOffset: { width: 0, height: 1 },
        shadowOpacity: 0.05,
        shadowRadius: 2,
        elevation: 1,
    },
    md: {
        shadowColor: '#000',
        shadowOffset: { width: 0, height: 2 },
        shadowOpacity: 0.1,
        shadowRadius: 4,
        elevation: 3,
    },
    lg: {
        shadowColor: '#000',
        shadowOffset: { width: 0, height: 4 },
        shadowOpacity: 0.15,
        shadowRadius: 8,
        elevation: 5,
    },
} as const;

// Get current theme colors based on color scheme
export function getThemeColors(scheme: 'light' | 'dark' = 'light') {
    return scheme === 'dark' ? darkColors : colors;
}

// Hook to get current theme (can be expanded with React context)
export function useTheme() {
    const colorScheme = Appearance.getColorScheme() ?? 'light';
    return {
        colors: getThemeColors(colorScheme),
        spacing,
        fontSize,
        fontWeight,
        borderRadius,
        shadows,
        isDark: colorScheme === 'dark',
    };
}

export type Theme = ReturnType<typeof useTheme>;