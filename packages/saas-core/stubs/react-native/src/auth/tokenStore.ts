import * as Crypto from 'expo-crypto';
import * as SecureStore from 'expo-secure-store';
import type { Session } from '../types';

const SESSION_KEY = 'saas_core_mobile_session';
const DEVICE_KEY = 'saas_core_mobile_device_id';

export async function getDeviceId(): Promise<string> {
  const existing = await SecureStore.getItemAsync(DEVICE_KEY);

  if (existing) {
    return existing;
  }

  const deviceId = Crypto.randomUUID();
  await SecureStore.setItemAsync(DEVICE_KEY, deviceId);

  return deviceId;
}

export async function saveSession(session: Session): Promise<void> {
  await SecureStore.setItemAsync(SESSION_KEY, JSON.stringify(session));
}

export async function loadSession(): Promise<Session | null> {
  const raw = await SecureStore.getItemAsync(SESSION_KEY);

  if (!raw) {
    return null;
  }

  return JSON.parse(raw) as Session;
}

export async function clearSession(): Promise<void> {
  await SecureStore.deleteItemAsync(SESSION_KEY);
}
