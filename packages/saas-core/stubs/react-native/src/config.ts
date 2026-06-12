import Constants from 'expo-constants';

type ExtraConfig = {
  EXPO_PUBLIC_API_URL?: string;
  EXPO_PUBLIC_TENANT_ID?: string;
};

const extra = Constants.expoConfig?.extra as ExtraConfig | undefined;

export const defaultApiUrl =
  process.env.EXPO_PUBLIC_API_URL ||
  extra?.EXPO_PUBLIC_API_URL ||
  'http://127.0.0.1:8000';

export const defaultTenantId =
  process.env.EXPO_PUBLIC_TENANT_ID ||
  extra?.EXPO_PUBLIC_TENANT_ID ||
  'acme';
