import React, { useState } from 'react';
import {
    View,
    Text,
    TextInput,
    TouchableOpacity,
    StyleSheet,
    KeyboardAvoidingView,
    Platform,
    ActivityIndicator,
    Alert,
} from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { useAuth } from '../context/AuthContext';
import { colors, spacing, fontSize, borderRadius } from '../theme';

export function LoginScreen() {
    const { login } = useAuth();
    const [email, setEmail] = useState('');
    const [password, setPassword] = useState('');
    const [isLoading, setIsLoading] = useState(false);
    const [showPassword, setShowPassword] = useState(false);

    const handleLogin = async () => {
        if (!email.trim() || !password.trim()) {
            Alert.alert('Error', 'Please enter your email and password');
            return;
        }

        setIsLoading(true);
        try {
            await login(email.trim(), password);
        } catch (error) {
            Alert.alert(
                'Login Failed',
                error instanceof Error ? error.message : 'Invalid credentials'
            );
        } finally {
            setIsLoading(false);
        }
    };

    return (
        <SafeAreaView style={styles.container}>
            <KeyboardAvoidingView
                behavior={Platform.OS === 'ios' ? 'padding' : 'height'}
                style={styles.keyboardView}
            >
                <View style={styles.content}>
                    {/* Logo/Header */}
                    <View style={styles.header}>
                        <Text style={styles.logoText}>🍷</Text>
                        <Text style={styles.title}>WineCellarHub</Text>
                        <Text style={styles.subtitle}>Your cellar, organized</Text>
                    </View>

                    {/* Form */}
                    <View style={styles.form}>
                        <View style={styles.inputContainer}>
                            <Text style={styles.label}>Email or Username</Text>
                            <TextInput
                                style={styles.input}
                                value={email}
                                onChangeText={setEmail}
                                placeholder="Enter your email or username"
                                placeholderTextColor={colors.gray}
                                autoCapitalize="none"
                                autoCorrect={false}
                                keyboardType="email-address"
                                editable={!isLoading}
                            />
                        </View>

                        <View style={styles.inputContainer}>
                            <Text style={styles.label}>Password</Text>
                            <View style={styles.passwordContainer}>
                                <TextInput
                                    style={[styles.input, styles.passwordInput]}
                                    value={password}
                                    onChangeText={setPassword}
                                    placeholder="Enter your password"
                                    placeholderTextColor={colors.gray}
                                    secureTextEntry={!showPassword}
                                    autoCapitalize="none"
                                    editable={!isLoading}
                                />
                                <TouchableOpacity
                                    style={styles.showPasswordButton}
                                    onPress={() => setShowPassword(!showPassword)}
                                >
                                    <Text style={styles.showPasswordText}>
                                        {showPassword ? 'Hide' : 'Show'}
                                    </Text>
                                </TouchableOpacity>
                            </View>
                        </View>

                        <TouchableOpacity
                            style={[styles.loginButton, isLoading && styles.loginButtonDisabled]}
                            onPress={handleLogin}
                            disabled={isLoading}
                        >
                            {isLoading ? (
                                <ActivityIndicator color={colors.white} />
                            ) : (
                                <Text style={styles.loginButtonText}>Sign In</Text>
                            )}
                        </TouchableOpacity>

                        <TouchableOpacity style={styles.forgotPassword}>
                            <Text style={styles.forgotPasswordText}>Forgot password?</Text>
                        </TouchableOpacity>
                    </View>

                    {/* Footer */}
                    <View style={styles.footer}>
                        <Text style={styles.footerText}>
                            Don't have an account?{' '}
                            <Text style={styles.footerLink}>Sign up</Text>
                        </Text>
                    </View>
                </View>
            </KeyboardAvoidingView>
        </SafeAreaView>
    );
}

const styles = StyleSheet.create({
    container: {
        flex: 1,
        backgroundColor: colors.background,
    },
    keyboardView: {
        flex: 1,
    },
    content: {
        flex: 1,
        padding: spacing.lg,
        justifyContent: 'center',
    },
    header: {
        alignItems: 'center',
        marginBottom: spacing.xxl,
    },
    logoText: {
        fontSize: 64,
        marginBottom: spacing.md,
    },
    title: {
        fontSize: fontSize.xxxl,
        fontWeight: '700',
        color: colors.primary,
        marginBottom: spacing.xs,
    },
    subtitle: {
        fontSize: fontSize.lg,
        color: colors.textSecondary,
    },
    form: {
        marginBottom: spacing.xl,
    },
    inputContainer: {
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
        fontSize: fontSize.lg,
        color: colors.text,
    },
    passwordContainer: {
        position: 'relative',
    },
    passwordInput: {
        paddingRight: 70,
    },
    showPasswordButton: {
        position: 'absolute',
        right: spacing.md,
        top: 0,
        bottom: 0,
        justifyContent: 'center',
    },
    showPasswordText: {
        color: colors.primary,
        fontSize: fontSize.sm,
        fontWeight: '600',
    },
    loginButton: {
        backgroundColor: colors.primary,
        borderRadius: borderRadius.md,
        padding: spacing.md,
        alignItems: 'center',
        marginTop: spacing.md,
    },
    loginButtonDisabled: {
        opacity: 0.7,
    },
    loginButtonText: {
        color: colors.white,
        fontSize: fontSize.lg,
        fontWeight: '600',
    },
    forgotPassword: {
        alignItems: 'center',
        marginTop: spacing.md,
    },
    forgotPasswordText: {
        color: colors.primary,
        fontSize: fontSize.md,
    },
    footer: {
        alignItems: 'center',
    },
    footerText: {
        fontSize: fontSize.md,
        color: colors.textSecondary,
    },
    footerLink: {
        color: colors.primary,
        fontWeight: '600',
    },
});