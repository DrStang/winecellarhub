import React from 'react';
import { ActivityIndicator, View, StyleSheet } from 'react-native';
import { NavigationContainer } from '@react-navigation/native';
import { createNativeStackNavigator } from '@react-navigation/native-stack';
import { createBottomTabNavigator } from '@react-navigation/bottom-tabs';
import { Ionicons } from '@expo/vector-icons';

import { useAuth } from '@/context/AuthContext';
import { LoginScreen } from '../screens/LoginScreen';
import { CellarScreen } from '../screens/CellarScreen';
import { BottleDetailScreen } from '../screens/BottleDetailScreen';
import { WantlistScreen } from '../screens/WantlistScreen';
import { ProfileScreen } from '../screens/ProfileScreen';
import { AddBottleScreen } from '../screens/AddBottleScreen';
import { EditBottleScreen } from '../screens/EditBottleScreen';
import DiscoverScreen from '../screens/DiscoverScreen';
import ExpertListDetailScreen from '@/screens/ExpertListDetailScreen';
import BrowseCategoryScreen from '@/screens/BrowseCategoryScreen';


import { colors } from '@/theme';
import type { RootStackParamList, MainTabParamList } from '@/types';

const Stack = createNativeStackNavigator<RootStackParamList>();
const Tab = createBottomTabNavigator<MainTabParamList>();

// Bottom tab navigator for main app
function MainTabs() {
    return (
        <Tab.Navigator
            screenOptions={({ route }) => ({
                tabBarIcon: ({ focused, color, size }) => {
                    let iconName: keyof typeof Ionicons.glyphMap = 'wine';

                    switch (route.name) {
                        case 'Cellar':
                            iconName = focused ? 'wine' : 'wine-outline';
                            break;
                        case 'Wantlist':
                            iconName = focused ? 'heart' : 'heart-outline';
                            break;
                        case 'Discover':
                            iconName = focused ? 'compass' : 'compass-outline';
                            break;
                        case 'Profile':
                            iconName = focused ? 'person' : 'person-outline';
                            break;
                    }

                    return <Ionicons name={iconName} size={size} color={color} />;
                },
                tabBarActiveTintColor: colors.primary,
                tabBarInactiveTintColor: colors.gray,
                tabBarStyle: {
                    backgroundColor: colors.white,
                    borderTopColor: colors.border,
                },
                headerShown: false,
            })}
        >
            <Tab.Screen name="Cellar" component={CellarScreen} />
            <Tab.Screen name="Wantlist" component={WantlistScreen} />
            <Tab.Screen
                name="Discover"
                component={DiscoverScreen}
                options={{ tabBarLabel: 'Discover' }}
            />
            <Tab.Screen name="Profile" component={ProfileScreen} />
        </Tab.Navigator>
    );
}


// Main navigation container
export function Navigation() {
    const { isLoading, isAuthenticated } = useAuth();

    if (isLoading) {
        return (
            <View style={styles.loading}>
                <ActivityIndicator size="large" color={colors.primary} />
            </View>
        );
    }

    return (
        <NavigationContainer>
            <Stack.Navigator screenOptions={{ headerShown: false }}>
                {isAuthenticated ? (
                    // Authenticated screens
                    <>
                        <Stack.Screen name="Main" component={MainTabs} />
                        <Stack.Screen
                            name="BottleDetail"
                            component={BottleDetailScreen}
                            options={{ animation: 'slide_from_right' }}
                        />
                        <Stack.Screen
                            name="AddBottle"
                            component={AddBottleScreen}
                            options={{ animation: 'slide_from_bottom', presentation: 'modal' }}
                        />
                        <Stack.Screen
                            name="EditBottle"
                            component={EditBottleScreen}
                            options={{ animation: 'slide_from_bottom', presentation: 'modal'}}
                        />
                        <Stack.Screen
                            name="ExpertListDetail"
                            component={ExpertListDetailScreen}
                            options={{animation: 'slide_from_right',
                            headerShown: true,
                            headerBackTitleVisible: false,
                            headerTintColor: colors.primary,
                            headerStyle: { backgroundColor: colors.white },
                            }}
                        />
                        <Stack.Screen
                            name="BrowseCategory"
                            component={BrowseCategoryScreen}
                            options={{
                                animation: 'slide_from_right',
                                headerShown: true,
                                headerBackTitleVisible: false,
                                headerTintColor: colors.primary,
                                headerStyle: { backgroundColor: colors.white },
                            }}
                        />
                    </>
                ) : (
                    // Auth screens
                    <Stack.Screen name="Login" component={LoginScreen} />
                )}
            </Stack.Navigator>
        </NavigationContainer>
    );
}

const styles = StyleSheet.create({
    loading: {
        flex: 1,
        alignItems: 'center',
        justifyContent: 'center',
        backgroundColor: colors.background,
    },
    placeholder: {
        flex: 1,
        alignItems: 'center',
        justifyContent: 'center',
        backgroundColor: colors.background,
    },
});