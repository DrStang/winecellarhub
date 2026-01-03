import React, { createContext, useContext, useState, useEffect, useCallback } from 'react';
import { api } from '../services/api';
import { TokenStorage } from '../services/storage';
import type { User } from '../types';

interface AuthContextType {
    user: User | null;
    isLoading: boolean;
    isAuthenticated: boolean;
    login: (username: string, password: string) => Promise<void>;
    logout: () => Promise<void>;
    refreshUser: () => Promise<void>;
}

const AuthContext = createContext<AuthContextType | undefined>(undefined);

export function AuthProvider({ children }: { children: React.ReactNode }) {
    const [user, setUser] = useState<User | null>(null);
    const [isLoading, setIsLoading] = useState(true);

    // Check for existing auth on mount
    useEffect(() => {
        checkAuth();
    }, []);

    // @ts-ignore
    const checkAuth = async () => {
        try {
            setIsLoading(true);
            const existingUser = await api.checkAuth();
            setUser(existingUser);
        } catch (error) {
            console.error('Auth check failed:', error);
            setUser(null);
        } finally {
            setIsLoading(false);
        }
    };

    const login = useCallback(async (username: string, password: string) => {
        const response = await api.login(username, password);
        setUser(response.user);
    }, []);

    const logout = useCallback(async () => {
        try {
            await api.logout();
        } finally {
            setUser(null);
        }
    }, []);

    const refreshUser = useCallback(async () => {
        try {
            const updatedUser = await api.getMe();
            setUser(updatedUser);
            await TokenStorage.setUser(updatedUser);
        } catch (error) {
            console.error('Failed to refresh user:', error);
        }
    }, []);

    const value: AuthContextType = {
        user,
        isLoading,
        isAuthenticated: user !== null,
        login,
        logout,
        refreshUser,
    };

    return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}

export function useAuth() {
    const context = useContext(AuthContext);
    if (context === undefined) {
        throw new Error('useAuth must be used within an AuthProvider');
    }
    return context;
}