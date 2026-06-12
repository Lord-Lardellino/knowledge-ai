import type { Session, TokenPair } from '../types';

export async function postJson<T>(
  apiUrl: string,
  path: string,
  body: Record<string, unknown>,
  headers: Record<string, string> = {},
): Promise<T> {
  const response = await fetch(`${apiUrl}${path}`, {
    method: 'POST',
    headers: {
      Accept: 'application/json',
      'Content-Type': 'application/json',
      ...headers,
    },
    body: JSON.stringify(body),
  });

  const payload = await response.json().catch(() => ({}));

  if (!response.ok) {
    throw new Error(payload.message || `Request failed with status ${response.status}`);
  }

  return payload as T;
}

export async function getJson<T>(
  apiUrl: string,
  path: string,
  headers: Record<string, string> = {},
): Promise<T> {
  const response = await fetch(`${apiUrl}${path}`, {
    method: 'GET',
    headers: {
      Accept: 'application/json',
      ...headers,
    },
  });

  const payload = await response.json().catch(() => ({}));

  if (!response.ok) {
    throw new Error(payload.message || `Request failed with status ${response.status}`);
  }

  return payload as T;
}

// Header di autenticazione per le chiamate API della sessione corrente.
export function authHeaders(session: Session): Record<string, string> {
  return {
    Authorization: `${session.tokenType} ${session.accessToken}`,
    'X-Tenant-ID': session.tenantId,
    'X-Device-ID': session.deviceId,
  };
}

export async function refreshSession(session: Session): Promise<Session | null> {
  if (!session.refreshToken || session.apiUrl === 'demo') {
    return null;
  }

  try {
    const json = await postJson<TokenPair>(
      session.apiUrl,
      '/auth/mobile/refresh',
      {},
      {
        Authorization: `${session.tokenType} ${session.refreshToken}`,
        'X-Tenant-ID': session.tenantId,
        'X-Device-ID': session.deviceId,
      },
    );

    return {
      ...session,
      accessToken: json.access_token,
      refreshToken: json.refresh_token || session.refreshToken,
      tokenType: json.token_type || 'Bearer',
    };
  } catch {
    return null;
  }
}

export async function logout(session: Session): Promise<void> {
  if (session.apiUrl === 'demo') {
    return;
  }

  await postJson(
    session.apiUrl,
    '/auth/mobile/logout',
    {},
    {
      Authorization: `${session.tokenType} ${session.accessToken}`,
      'X-Tenant-ID': session.tenantId,
      'X-Device-ID': session.deviceId,
    },
  );
}
