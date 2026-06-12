import React, { createContext, useContext, useEffect, useMemo, useState } from 'react';
import { logout as apiLogout, postJson } from '../api/client';
import { defaultApiUrl, defaultTenantId } from '../config';
import type { Session, TokenPair, TotpPending } from '../types';
import { loginWithNativePasskey, registerWithNativePasskey, sendRecoveryLink } from './passkeyAuth';
import { clearSession, getDeviceId, loadSession, saveSession } from './tokenStore';

type AuthInput = {
  apiUrl?: string;
  tenantId?: string;
  email: string;
};

type RegisterInput = AuthInput & {
  name: string;
};

// Login passkey riuscito ma 2FA richiesto: contesto per completare
// la verifica TOTP (il pending token autorizza SOLO quella chiamata).
type TotpPendingState = {
  pendingToken: string;
  apiUrl: string;
  tenantId: string;
  deviceId: string;
  email: string;
};

type AuthContextValue = {
  loading: boolean;
  session: Session | null;
  totpPending: TotpPendingState | null;
  login: (input: AuthInput) => Promise<void>;
  register: (input: RegisterInput) => Promise<void>;
  recover: (input: AuthInput) => Promise<string>;
  verifyTotpLogin: (code: string) => Promise<void>;
  cancelTotpLogin: () => void;
  signInDemo: () => Promise<void>;
  logout: () => Promise<void>;
};

const AuthContext = createContext<AuthContextValue | null>(null);

export function AuthProvider({ children }: { children: React.ReactNode }) {
  const [loading, setLoading] = useState(true);
  const [session, setSessionState] = useState<Session | null>(null);
  const [totpPending, setTotpPending] = useState<TotpPendingState | null>(null);

  useEffect(() => {
    loadSession()
      .then(setSessionState)
      .finally(() => setLoading(false));
  }, []);

  const persistTokenPair = async (
    tokenPair: TokenPair,
    input: { apiUrl?: string; tenantId?: string; deviceId: string; email?: string },
  ) => {
    const nextSession: Session = {
      apiUrl: input.apiUrl || defaultApiUrl,
      tenantId: input.tenantId || defaultTenantId,
      accessToken: tokenPair.access_token,
      refreshToken: tokenPair.refresh_token,
      tokenType: tokenPair.token_type || 'Bearer',
      deviceId: input.deviceId,
      email: input.email,
    };

    await saveSession(nextSession);
    setSessionState(nextSession);
  };

  const value = useMemo<AuthContextValue>(
    () => ({
      loading,
      session,
      totpPending,
      login: async ({ apiUrl, tenantId, email }) => {
        const deviceId = await getDeviceId();
        const result = await loginWithNativePasskey({
          apiUrl: apiUrl || defaultApiUrl,
          tenantId: tenantId || defaultTenantId,
          email,
          deviceId,
        });

        // 2FA attivo: niente token definitivi — l'app mostra la
        // TotpChallengeScreen e completa con verifyTotpLogin(code).
        if ('totp_required' in result) {
          setTotpPending({
            pendingToken: (result as TotpPending).pending_token,
            apiUrl: apiUrl || defaultApiUrl,
            tenantId: tenantId || defaultTenantId,
            deviceId,
            email,
          });
          return;
        }

        await persistTokenPair(result, { apiUrl, tenantId, deviceId, email });
      },
      verifyTotpLogin: async (code) => {
        if (!totpPending) {
          throw new Error('Nessuna verifica TOTP in corso.');
        }

        const tokenPair = await postJson<TokenPair>(
          totpPending.apiUrl,
          '/auth/mobile/totp/verify',
          { code, device_id: totpPending.deviceId },
          {
            Authorization: `Bearer ${totpPending.pendingToken}`,
            'X-Tenant-ID': totpPending.tenantId,
            'X-Device-ID': totpPending.deviceId,
          },
        );

        await persistTokenPair(tokenPair, {
          apiUrl: totpPending.apiUrl,
          tenantId: totpPending.tenantId,
          deviceId: totpPending.deviceId,
          email: totpPending.email,
        });
        setTotpPending(null);
      },
      cancelTotpLogin: () => {
        setTotpPending(null);
      },
      register: async ({ apiUrl, tenantId, name, email }) => {
        const deviceId = await getDeviceId();
        const tokenPair = await registerWithNativePasskey({
          apiUrl: apiUrl || defaultApiUrl,
          tenantId: tenantId || defaultTenantId,
          name,
          email,
          deviceId,
        });

        await persistTokenPair(tokenPair, { apiUrl, tenantId, deviceId, email });
      },
      recover: async ({ apiUrl, email }) => {
        return sendRecoveryLink(apiUrl || defaultApiUrl, email);
      },
      signInDemo: async () => {
        const deviceId = await getDeviceId();
        const nextSession: Session = {
          apiUrl: 'demo',
          tenantId: 'demo',
          accessToken: 'demo',
          refreshToken: 'demo',
          tokenType: 'Bearer',
          deviceId,
          email: 'demo@example.com',
        };

        await saveSession(nextSession);
        setSessionState(nextSession);
      },
      logout: async () => {
        if (session) {
          await apiLogout(session).catch(() => undefined);
        }

        await clearSession();
        setSessionState(null);
      },
    }),
    [loading, session, totpPending],
  );

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}

export function useAuth(): AuthContextValue {
  const context = useContext(AuthContext);

  if (!context) {
    throw new Error('useAuth must be used inside AuthProvider');
  }

  return context;
}
