export type Session = {
  apiUrl: string;
  tenantId: string;
  accessToken: string;
  refreshToken?: string;
  tokenType: 'Bearer';
  deviceId: string;
  // Email dell'utente autenticato — mostrata nella dashboard.
  // Salvata al login/register (il backend mobile non ha un endpoint /me).
  email?: string;
};

export type TokenPair = {
  access_token: string;
  refresh_token?: string;
  token_type?: 'Bearer';
  expires_in?: number;
};

// Risposta di /auth/mobile/passkey/verify quando l'utente ha il 2FA attivo:
// niente access/refresh — solo un token "pending" valido 10 minuti che
// autorizza esclusivamente POST /auth/mobile/totp/verify.
export type TotpPending = {
  totp_required: true;
  pending_token: string;
  token_type?: 'Bearer';
  expires_in?: number;
};
