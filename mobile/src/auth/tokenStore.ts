import AsyncStorage from '@react-native-async-storage/async-storage';
import type { Session } from '../types';

const SESSION_KEY = 'saas_core_mobile_session';
const DEVICE_KEY = 'saas_core_mobile_device_id';

function createDeviceId(): string {
  const random = Math.random().toString(36).slice(2);
  return `rn-${Date.now().toString(36)}-${random}`;
}

export async function getDeviceId(): Promise<string> {
  const existing = await AsyncStorage.getItem(DEVICE_KEY);

  if (existing) {
    return existing;
  }

  const deviceId = createDeviceId();
  await AsyncStorage.setItem(DEVICE_KEY, deviceId);

  return deviceId;
}

export async function saveSession(session: Session): Promise<void> {
  await AsyncStorage.setItem(SESSION_KEY, JSON.stringify(session));
}

export async function loadSession(): Promise<Session | null> {
  const raw = await AsyncStorage.getItem(SESSION_KEY);

  if (!raw) {
    return null;
  }

  return JSON.parse(raw) as Session;
}

export async function clearSession(): Promise<void> {
  await AsyncStorage.removeItem(SESSION_KEY);
}
