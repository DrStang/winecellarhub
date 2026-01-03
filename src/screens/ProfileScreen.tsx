import React, { useCallback } from 'react';
import {
    View,
    Text,
    ScrollView,
    StyleSheet,
    TouchableOpacity,
    Alert,
} from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { Ionicons } from '@expo/vector-icons';

import { useAuth } from '../context/AuthContext';
import { colors, spacing, fontSize, borderRadius, shadows } from '../theme';

export function ProfileScreen() {
    const { user, logout } = useAuth();

    const handleLogout = useCallback(() => {
        Alert.alert(
            'Sign Out',
            'Are you sure you want to sign out?',
            [
                { text: 'Cancel', style: 'cancel' },
                {
                    text: 'Sign Out',
                    style: 'destructive',
                    onPress: logout,
                },
            ]
        );
    }, [logout]);

    const handleLogoutAll = useCallback(() => {
        Alert.alert(
            'Sign Out Everywhere',
            'This will sign you out from all devices. Continue?',
            [
                { text: 'Cancel', style: 'cancel' },
                {
                    text: 'Sign Out All',
                    style: 'destructive',
                    onPress: async () => {
                        try {
                            await logout();
                        } catch (error) {
                            console.error('Logout error:', error);
                        }
                    },
                },
            ]
        );
    }, [logout]);

    if (!user) return null;

    return (
        <SafeAreaView style={styles.container} edges={['top']}>
            <ScrollView style={styles.scrollView} showsVerticalScrollIndicator={false}>
                {/* Header */}
                <View style={styles.header}>
                    <View style={styles.avatar}>
                        <Text style={styles.avatarText}>
                            {user.username.charAt(0).toUpperCase()}
                        </Text>
                    </View>
                    <Text style={styles.username}>{user.username}</Text>
                    <Text style={styles.email}>{user.email}</Text>
                </View>

                {/* Stats */}
                {user.stats && (
                    <View style={styles.statsContainer}>
                        <View style={styles.statItem}>
                            <Text style={styles.statValue}>{user.stats.current_bottles}</Text>
                            <Text style={styles.statLabel}>In Cellar</Text>
                        </View>
                        <View style={styles.statDivider} />
                        <View style={styles.statItem}>
                            <Text style={styles.statValue}>{user.stats.past_bottles}</Text>
                            <Text style={styles.statLabel}>Consumed</Text>
                        </View>
                        <View style={styles.statDivider} />
                        <View style={styles.statItem}>
                            <Text style={styles.statValue}>{user.stats.wantlist_count}</Text>
                            <Text style={styles.statLabel}>Wantlist</Text>
                        </View>
                    </View>
                )}

                {/* Menu */}
                <View style={styles.menu}>
                    <Text style={styles.menuTitle}>Settings</Text>

                    <TouchableOpacity style={styles.menuItem}>
                        <Ionicons name="person-outline" size={22} color={colors.text} />
                        <Text style={styles.menuItemText}>Edit Profile</Text>
                        <Ionicons name="chevron-forward" size={20} color={colors.gray} />
                    </TouchableOpacity>

                    <TouchableOpacity style={styles.menuItem}>
                        <Ionicons name="notifications-outline" size={22} color={colors.text} />
                        <Text style={styles.menuItemText}>Notifications</Text>
                        <Ionicons name="chevron-forward" size={20} color={colors.gray} />
                    </TouchableOpacity>

                    <TouchableOpacity style={styles.menuItem}>
                        <Ionicons name="moon-outline" size={22} color={colors.text} />
                        <Text style={styles.menuItemText}>Appearance</Text>
                        <Ionicons name="chevron-forward" size={20} color={colors.gray} />
                    </TouchableOpacity>

                    <TouchableOpacity style={styles.menuItem}>
                        <Ionicons name="download-outline" size={22} color={colors.text} />
                        <Text style={styles.menuItemText}>Export Data</Text>
                        <Ionicons name="chevron-forward" size={20} color={colors.gray} />
                    </TouchableOpacity>
                </View>

                <View style={styles.menu}>
                    <Text style={styles.menuTitle}>Support</Text>

                    <TouchableOpacity style={styles.menuItem}>
                        <Ionicons name="help-circle-outline" size={22} color={colors.text} />
                        <Text style={styles.menuItemText}>Help & FAQ</Text>
                        <Ionicons name="chevron-forward" size={20} color={colors.gray} />
                    </TouchableOpacity>

                    <TouchableOpacity style={styles.menuItem}>
                        <Ionicons name="mail-outline" size={22} color={colors.text} />
                        <Text style={styles.menuItemText}>Contact Us</Text>
                        <Ionicons name="chevron-forward" size={20} color={colors.gray} />
                    </TouchableOpacity>

                    <TouchableOpacity style={styles.menuItem}>
                        <Ionicons name="document-text-outline" size={22} color={colors.text} />
                        <Text style={styles.menuItemText}>Privacy Policy</Text>
                        <Ionicons name="chevron-forward" size={20} color={colors.gray} />
                    </TouchableOpacity>
                </View>

                <View style={styles.menu}>
                    <Text style={styles.menuTitle}>Account</Text>

                    <TouchableOpacity style={styles.menuItem} onPress={handleLogout}>
                        <Ionicons name="log-out-outline" size={22} color={colors.error} />
                        <Text style={[styles.menuItemText, { color: colors.error }]}>
                            Sign Out
                        </Text>
                    </TouchableOpacity>

                    <TouchableOpacity style={styles.menuItem} onPress={handleLogoutAll}>
                        <Ionicons name="close-circle-outline" size={22} color={colors.error} />
                        <Text style={[styles.menuItemText, { color: colors.error }]}>
                            Sign Out Everywhere
                        </Text>
                    </TouchableOpacity>
                </View>

                {/* Version */}
                <Text style={styles.version}>WineCellarHub v1.0.0</Text>
            </ScrollView>
        </SafeAreaView>
    );
}

const styles = StyleSheet.create({
    container: {
        flex: 1,
        backgroundColor: colors.background,
    },
    scrollView: {
        flex: 1,
    },
    header: {
        alignItems: 'center',
        paddingVertical: spacing.xl,
        borderBottomWidth: 1,
        borderBottomColor: colors.border,
    },
    avatar: {
        width: 80,
        height: 80,
        borderRadius: 40,
        backgroundColor: colors.primary,
        alignItems: 'center',
        justifyContent: 'center',
        marginBottom: spacing.md,
    },
    avatarText: {
        fontSize: 32,
        fontWeight: '700',
        color: colors.white,
    },
    username: {
        fontSize: fontSize.xl,
        fontWeight: '700',
        color: colors.text,
        marginBottom: spacing.xs,
    },
    email: {
        fontSize: fontSize.md,
        color: colors.textSecondary,
    },
    statsContainer: {
        flexDirection: 'row',
        backgroundColor: colors.offWhite,
        marginHorizontal: spacing.lg,
        marginTop: spacing.lg,
        borderRadius: borderRadius.lg,
        padding: spacing.lg,
    },
    statItem: {
        flex: 1,
        alignItems: 'center',
    },
    statValue: {
        fontSize: fontSize.xxl,
        fontWeight: '700',
        color: colors.primary,
    },
    statLabel: {
        fontSize: fontSize.sm,
        color: colors.textMuted,
        marginTop: spacing.xs,
    },
    statDivider: {
        width: 1,
        backgroundColor: colors.border,
        marginHorizontal: spacing.md,
    },
    menu: {
        marginTop: spacing.lg,
        paddingHorizontal: spacing.lg,
    },
    menuTitle: {
        fontSize: fontSize.sm,
        fontWeight: '600',
        color: colors.textMuted,
        textTransform: 'uppercase',
        letterSpacing: 0.5,
        marginBottom: spacing.sm,
        marginLeft: spacing.xs,
    },
    menuItem: {
        flexDirection: 'row',
        alignItems: 'center',
        backgroundColor: colors.card,
        padding: spacing.md,
        borderRadius: borderRadius.md,
        marginBottom: spacing.sm,
        ...shadows.sm,
    },
    menuItemText: {
        flex: 1,
        fontSize: fontSize.md,
        color: colors.text,
        marginLeft: spacing.md,
    },
    version: {
        textAlign: 'center',
        fontSize: fontSize.sm,
        color: colors.textMuted,
        marginVertical: spacing.xl,
    },
});