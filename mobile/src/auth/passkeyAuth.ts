import * as Passkeys from 'react-native-passkeys';
import { postJson } from '../api/client';
import type { TokenPair, TotpPending } from '../types';

type LoginInput = {
  apiUrl: string;
  tenantId: string;
  email: string;
  deviceId: string;
};

type RegisterInput = LoginInput & {
  name: string;
};

// Restituisce la coppia di token, OPPURE TotpPending se l'utente ha il 2FA
// attivo: in quel caso serve POST /auth/mobile/totp/verify col codice.
export async function loginWithNativePasskey(input: LoginInput): Promise<TokenPair | TotpPending> {
  ensureNativePasskeys();

  const options = await postJson<Record<string, unknown>>(
    input.apiUrl,
    '/auth/mobile/passkey/challenge',
    { email: input.email },
    { 'X-Tenant-ID': input.tenantId, 'X-Device-ID': input.deviceId },
  );

  const credential = await Passkeys.get({
    challenge: String(options.challenge),
    rpId: typeof options.rpId === 'string' ? options.rpId : undefined,
    timeout: typeof options.timeout === 'number' ? options.timeout : undefined,
    userVerification: 'required',
  });

  if (!credential) {
    throw new Error('Login annullato.');
  }

  return postJson<TokenPair | TotpPending>(input.apiUrl, '/auth/mobile/passkey/verify', {
    email: input.email,
    device_id: input.deviceId,
    response: credential,
  }, { 'X-Tenant-ID': input.tenantId, 'X-Device-ID': input.deviceId });
}

export async function registerWithNativePasskey(input: RegisterInput): Promise<TokenPair> {
  ensureNativePasskeys();

  const options = await postJson<Record<string, unknown>>(
    input.apiUrl,
    '/auth/mobile/passkey/register/options',
    {
      name: input.name,
      email: input.email,
    },
    { 'X-Tenant-ID': input.tenantId, 'X-Device-ID': input.deviceId },
  );

  const credential = await Passkeys.create(
    options as Parameters<typeof Passkeys.create>[0],
  );

  if (!credential) {
    throw new Error('Registrazione annullata.');
  }

  return postJson<TokenPair>(input.apiUrl, '/auth/mobile/passkey/register', {
    email: input.email,
    device_id: input.deviceId,
    key_name: getDeviceLabel(),
    response: credential,
  }, { 'X-Tenant-ID': input.tenantId, 'X-Device-ID': input.deviceId });
}

export async function sendRecoveryLink(apiUrl: string, email: string): Promise<string> {
  const response = await postJson<{ message: string }>(apiUrl, '/recover', { email });

  return response.message;
}

function ensureNativePasskeys(): void {
  if (!Passkeys.isSupported()) {
    throw new Error('Questo dispositivo non supporta le passkey native.');
  }
}

function getDeviceLabel(): string {
  return 'Dispositivo mobile ' + new Date().toLocaleDateString('it-IT');
}
