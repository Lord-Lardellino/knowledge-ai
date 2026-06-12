import React, { useEffect, useState } from 'react';
import {
  Pressable,
  ScrollView,
  StyleSheet,
  Text,
  TextInput,
  View,
} from 'react-native';
import QRCode from 'qrcode';
import { authHeaders, getJson, postJson } from '../api/client';
import { useAuth } from '../auth/AuthContext';
import { colors, spacing } from '../theme';

type TotpStatus = {
  totp_enabled: boolean;
  totp_required: boolean;
};

type TotpSetup = {
  secret: string;
  qr_url: string;
};

type QrMatrix = {
  size: number;
  data: ArrayLike<boolean | number>;
};

type TotpConfirmResponse = {
  recovery_codes?: string[];
};

export function DashboardScreen() {
  const { session, logout } = useAuth();
  const [totpStatus, setTotpStatus] = useState<TotpStatus | null>(null);
  const [totpSetup, setTotpSetup] = useState<TotpSetup | null>(null);
  const [qrMatrix, setQrMatrix] = useState<QrMatrix | null>(null);
  const [recoveryCodes, setRecoveryCodes] = useState<string[]>([]);
  const [confirmCode, setConfirmCode] = useState('');
  const [disableCode, setDisableCode] = useState('');
  const [totpLoading, setTotpLoading] = useState(false);
  const [totpMessage, setTotpMessage] = useState<string | null>(null);
  const [totpError, setTotpError] = useState<string | null>(null);

  useEffect(() => {
    void loadTotpStatus();
  }, [session?.accessToken]);

  useEffect(() => {
    if (!totpSetup?.qr_url) {
      setQrMatrix(null);
      return;
    }

    try {
      const qr = QRCode.create(totpSetup.qr_url, { errorCorrectionLevel: 'M' });
      setQrMatrix({
        size: qr.modules.size,
        data: qr.modules.data,
      });
    } catch {
      setQrError();
    }
  }, [totpSetup?.qr_url]);

  const loadTotpStatus = async () => {
    if (!session || session.apiUrl === 'demo') {
      return;
    }

    try {
      const status = await getJson<TotpStatus>(
        session.apiUrl,
        '/auth/mobile/totp/status',
        authHeaders(session),
      );
      setTotpStatus(status);
    } catch (caught) {
      setTotpError(caught instanceof Error ? caught.message : 'Stato 2FA non disponibile.');
    }
  };

  const startTotpSetup = async () => {
    if (!session || session.apiUrl === 'demo') {
      return;
    }

    setTotpLoading(true);
    setTotpError(null);
    setTotpMessage(null);
    setRecoveryCodes([]);

    try {
      const setup = await getJson<TotpSetup>(
        session.apiUrl,
        '/auth/mobile/totp/setup',
        authHeaders(session),
      );
      setTotpSetup(setup);
      setConfirmCode('');
    } catch (caught) {
      setTotpError(caught instanceof Error ? caught.message : 'Setup 2FA non riuscito.');
    } finally {
      setTotpLoading(false);
    }
  };

  const confirmTotp = async () => {
    if (!session || session.apiUrl === 'demo') {
      return;
    }

    setTotpLoading(true);
    setTotpError(null);
    setTotpMessage(null);

    try {
      const response = await postJson<TotpConfirmResponse>(
        session.apiUrl,
        '/auth/mobile/totp/confirm',
        { code: confirmCode.trim() },
        authHeaders(session),
      );
      setRecoveryCodes(response.recovery_codes || []);
      setTotpSetup(null);
      setConfirmCode('');
      setTotpMessage('2FA attivato. Salva i codici di recovery.');
      await loadTotpStatus();
    } catch (caught) {
      setTotpError(caught instanceof Error ? caught.message : 'Codice 2FA non valido.');
    } finally {
      setTotpLoading(false);
    }
  };

  const disableTotp = async () => {
    if (!session || session.apiUrl === 'demo') {
      return;
    }

    setTotpLoading(true);
    setTotpError(null);
    setTotpMessage(null);

    try {
      await postJson(
        session.apiUrl,
        '/auth/mobile/totp/disable',
        { code: disableCode.trim() },
        authHeaders(session),
      );
      setTotpSetup(null);
      setRecoveryCodes([]);
      setDisableCode('');
      setTotpMessage('2FA disattivato.');
      await loadTotpStatus();
    } catch (caught) {
      setTotpError(caught instanceof Error ? caught.message : 'Disattivazione 2FA non riuscita.');
    } finally {
      setTotpLoading(false);
    }
  };

  const cancelSetup = () => {
    setTotpSetup(null);
    setQrMatrix(null);
    setConfirmCode('');
    setTotpError(null);
    setTotpMessage(null);
  };

  const setQrError = () => {
    setQrMatrix(null);
    setTotpError('QR code non generato.');
  };

  return (
    <View style={styles.container}>
      <View style={styles.topBar}>
        <View>
          <Text style={styles.brand}>SaaS</Text>
          <Text style={styles.topBarTitle}>Dashboard</Text>
        </View>
        <Pressable style={styles.logoutButton} onPress={logout}>
          <Text style={styles.logoutButtonText}>Esci</Text>
        </Pressable>
      </View>

      <ScrollView contentContainerStyle={styles.content}>
        <View style={styles.card}>
          <Text style={styles.cardTitle}>Benvenuto</Text>
          <Text style={styles.cardBody}>
            Sei autenticato come{' '}
            <Text style={styles.cardStrong}>{session?.email || 'utente'}</Text>
          </Text>
        </View>

        <View style={styles.cardRow}>
          <View style={[styles.card, styles.cardHalf]}>
            <Text style={styles.cardLabel}>Tenant</Text>
            <Text style={styles.cardValue}>{session?.tenantId || '-'}</Text>
          </View>
          <View style={[styles.card, styles.cardHalf]}>
            <Text style={styles.cardLabel}>Sessione</Text>
            <Text style={styles.cardValue}>Attiva</Text>
          </View>
        </View>

        <View style={styles.card}>
          <Text style={styles.cardLabel}>Device ID</Text>
          <Text style={styles.cardMono} numberOfLines={1}>
            {session?.deviceId || '-'}
          </Text>
        </View>

        <View style={styles.card}>
          <View style={styles.sectionHeader}>
            <View>
              <Text style={styles.cardTitle}>2FA</Text>
              <Text style={styles.cardBody}>Autenticazione a due fattori con app authenticator.</Text>
            </View>
            <View style={[styles.badge, totpStatus?.totp_enabled ? styles.badgeSuccess : null]}>
              <Text style={[styles.badgeText, totpStatus?.totp_enabled ? styles.badgeTextSuccess : null]}>
                {totpStatus?.totp_enabled ? 'Attivo' : 'Non attivo'}
              </Text>
            </View>
          </View>

          {renderTotpBody()}
        </View>
      </ScrollView>
    </View>
  );

  function renderTotpBody() {
    if (session?.apiUrl === 'demo') {
      return <Text style={styles.helperText}>Il 2FA non e disponibile nella demo locale.</Text>;
    }

    if (recoveryCodes.length > 0) {
      return (
        <View style={styles.panel}>
          <Text style={styles.warningTitle}>Salva questi codici ora</Text>
          <Text style={styles.helperText}>
            Sono mostrati una sola volta e servono se perdi accesso all app authenticator.
          </Text>
          <View style={styles.recoveryGrid}>
            {recoveryCodes.map((code) => (
              <Text selectable key={code} style={styles.recoveryCode}>
                {code}
              </Text>
            ))}
          </View>
          <Pressable style={styles.primaryButton} onPress={() => setRecoveryCodes([])}>
            <Text style={styles.primaryButtonText}>Ho salvato i codici</Text>
          </Pressable>
        </View>
      );
    }

    if (totpSetup) {
      return (
        <View style={styles.panel}>
          <Text style={styles.stepTitle}>Configura la tua app</Text>
          <Text style={styles.helperText}>
            Scansiona il QR code oppure inserisci il segreto manualmente, poi conferma il codice a 6 cifre.
          </Text>

          <View style={styles.qrBox}>
            {qrMatrix ? <QrCodeMatrix matrix={qrMatrix} /> : null}
            <Text style={styles.cardLabel}>Segreto manuale</Text>
            <Text selectable style={styles.secretText}>
              {totpSetup.secret}
            </Text>
          </View>

          <TextInput
            autoCapitalize="none"
            autoCorrect={false}
            keyboardType="number-pad"
            maxLength={6}
            onChangeText={setConfirmCode}
            placeholder="000000"
            style={styles.codeInput}
            value={confirmCode}
          />

          {totpError ? <Text style={styles.error}>{totpError}</Text> : null}

          <Pressable
            disabled={totpLoading || confirmCode.trim().length < 6}
            style={[
              styles.primaryButton,
              totpLoading || confirmCode.trim().length < 6 ? styles.buttonDisabled : null,
            ]}
            onPress={confirmTotp}
          >
            <Text style={styles.primaryButtonText}>
              {totpLoading ? 'Attendi...' : 'Conferma e attiva'}
            </Text>
          </Pressable>
          <Pressable disabled={totpLoading} style={styles.secondaryButton} onPress={cancelSetup}>
            <Text style={styles.secondaryButtonText}>Annulla</Text>
          </Pressable>
        </View>
      );
    }

    if (totpStatus?.totp_enabled) {
      return (
        <View style={styles.panel}>
          <Text style={styles.successText}>
            Il secondo fattore e attivo. Dopo ogni login con passkey ti verra richiesto il codice.
          </Text>
          <Text style={styles.stepTitle}>Disabilita 2FA</Text>
          <Text style={styles.helperText}>Inserisci il codice attuale dalla tua app authenticator.</Text>
          <TextInput
            autoCapitalize="none"
            autoCorrect={false}
            keyboardType="number-pad"
            maxLength={6}
            onChangeText={setDisableCode}
            placeholder="000000"
            style={styles.codeInput}
            value={disableCode}
          />

          {totpError ? <Text style={styles.error}>{totpError}</Text> : null}
          {totpMessage ? <Text style={styles.message}>{totpMessage}</Text> : null}

          <Pressable
            disabled={totpLoading || disableCode.trim().length < 6}
            style={[
              styles.dangerButton,
              totpLoading || disableCode.trim().length < 6 ? styles.buttonDisabled : null,
            ]}
            onPress={disableTotp}
          >
            <Text style={styles.dangerButtonText}>
              {totpLoading ? 'Attendi...' : 'Disattiva 2FA'}
            </Text>
          </Pressable>
        </View>
      );
    }

    return (
      <View style={styles.panel}>
        <Text style={styles.helperText}>
          Attiva il 2FA per richiedere un codice temporaneo dopo il login con passkey.
        </Text>
        <Text style={styles.helperText}>
          App supportate: Google Authenticator, Authy, 1Password e Microsoft Authenticator.
        </Text>

        {totpError ? <Text style={styles.error}>{totpError}</Text> : null}
        {totpMessage ? <Text style={styles.message}>{totpMessage}</Text> : null}

        <Pressable disabled={totpLoading} style={styles.primaryButton} onPress={startTotpSetup}>
          <Text style={styles.primaryButtonText}>
            {totpLoading ? 'Attendi...' : 'Attiva autenticazione a due fattori'}
          </Text>
        </Pressable>
      </View>
    );
  }
}

function QrCodeMatrix({ matrix }: { matrix: QrMatrix }) {
  const qrSize = 196;
  const cellSize = qrSize / matrix.size;
  const cells = Array.from(matrix.data).map((active, index) => (
    <View
      key={index}
      style={[
        styles.qrCell,
        {
          backgroundColor: active === true || active === 1 ? '#111827' : '#ffffff',
          height: cellSize,
          width: cellSize,
        },
      ]}
    />
  ));

  return <View style={styles.qrMatrix}>{cells}</View>;
}

const styles = StyleSheet.create({
  container: {
    flex: 1,
    backgroundColor: colors.background,
  },
  topBar: {
    alignItems: 'center',
    backgroundColor: colors.surface,
    borderBottomColor: colors.border,
    borderBottomWidth: 1,
    flexDirection: 'row',
    justifyContent: 'space-between',
    paddingHorizontal: spacing.lg,
    paddingVertical: spacing.md,
  },
  brand: {
    color: colors.primary,
    fontSize: 12,
    fontWeight: '800',
    textTransform: 'uppercase',
  },
  topBarTitle: {
    color: colors.text,
    fontSize: 22,
    fontWeight: '800',
  },
  logoutButton: {
    borderColor: colors.border,
    borderRadius: 8,
    borderWidth: 1,
    paddingHorizontal: spacing.md,
    paddingVertical: spacing.sm,
  },
  logoutButtonText: {
    color: colors.text,
    fontSize: 14,
    fontWeight: '700',
  },
  content: {
    gap: spacing.md,
    padding: spacing.lg,
  },
  card: {
    backgroundColor: colors.surface,
    borderColor: colors.border,
    borderRadius: 12,
    borderWidth: 1,
    padding: spacing.lg,
  },
  cardRow: {
    flexDirection: 'row',
    gap: spacing.md,
  },
  cardHalf: {
    flex: 1,
  },
  cardTitle: {
    color: colors.text,
    fontSize: 18,
    fontWeight: '800',
    marginBottom: spacing.xs,
  },
  cardBody: {
    color: colors.muted,
    fontSize: 15,
    lineHeight: 22,
  },
  cardStrong: {
    color: colors.text,
    fontWeight: '700',
  },
  cardLabel: {
    color: colors.muted,
    fontSize: 13,
    fontWeight: '700',
    marginBottom: spacing.xs,
    textTransform: 'uppercase',
  },
  cardValue: {
    color: colors.text,
    fontSize: 20,
    fontWeight: '800',
  },
  cardMono: {
    color: colors.text,
    fontFamily: 'monospace',
    fontSize: 13,
  },
  sectionHeader: {
    alignItems: 'flex-start',
    flexDirection: 'row',
    gap: spacing.md,
    justifyContent: 'space-between',
  },
  badge: {
    backgroundColor: colors.chip,
    borderRadius: 999,
    paddingHorizontal: spacing.sm,
    paddingVertical: spacing.xs,
  },
  badgeSuccess: {
    backgroundColor: '#dcfce7',
  },
  badgeText: {
    color: colors.muted,
    fontSize: 12,
    fontWeight: '800',
  },
  badgeTextSuccess: {
    color: colors.success,
  },
  panel: {
    marginTop: spacing.md,
    gap: spacing.md,
  },
  stepTitle: {
    color: colors.text,
    fontSize: 16,
    fontWeight: '800',
  },
  helperText: {
    color: colors.muted,
    fontSize: 14,
    lineHeight: 20,
  },
  warningTitle: {
    color: colors.warning,
    fontSize: 16,
    fontWeight: '800',
  },
  successText: {
    backgroundColor: '#dcfce7',
    borderRadius: 8,
    color: colors.success,
    fontSize: 14,
    lineHeight: 20,
    padding: spacing.md,
  },
  qrBox: {
    alignItems: 'center',
    backgroundColor: colors.chip,
    borderColor: colors.border,
    borderRadius: 8,
    borderWidth: 1,
    padding: spacing.md,
  },
  qrMatrix: {
    backgroundColor: '#ffffff',
    borderRadius: 6,
    height: 220,
    marginBottom: spacing.md,
    padding: 12,
    width: 220,
    flexDirection: 'row',
    flexWrap: 'wrap',
  },
  qrCell: {
    flexGrow: 0,
    flexShrink: 0,
  },
  secretText: {
    color: colors.text,
    fontFamily: 'monospace',
    fontSize: 15,
    lineHeight: 22,
    textAlign: 'center',
  },
  codeInput: {
    backgroundColor: colors.surface,
    borderColor: colors.border,
    borderRadius: 8,
    borderWidth: 1,
    color: colors.text,
    fontSize: 22,
    fontWeight: '700',
    letterSpacing: 0,
    minHeight: 52,
    paddingHorizontal: spacing.md,
    textAlign: 'center',
  },
  recoveryGrid: {
    backgroundColor: colors.chip,
    borderColor: colors.border,
    borderRadius: 8,
    borderWidth: 1,
    flexDirection: 'row',
    flexWrap: 'wrap',
    gap: spacing.sm,
    padding: spacing.md,
  },
  recoveryCode: {
    color: colors.text,
    fontFamily: 'monospace',
    fontSize: 14,
    width: '47%',
  },
  error: {
    color: colors.danger,
    fontSize: 14,
  },
  message: {
    color: colors.success,
    fontSize: 14,
  },
  primaryButton: {
    alignItems: 'center',
    backgroundColor: colors.primary,
    borderRadius: 8,
    minHeight: 50,
    justifyContent: 'center',
    paddingHorizontal: spacing.md,
  },
  primaryButtonText: {
    color: '#ffffff',
    fontSize: 15,
    fontWeight: '700',
    textAlign: 'center',
  },
  secondaryButton: {
    alignItems: 'center',
    borderColor: colors.border,
    borderRadius: 8,
    borderWidth: 1,
    minHeight: 50,
    justifyContent: 'center',
  },
  secondaryButtonText: {
    color: colors.text,
    fontSize: 15,
    fontWeight: '700',
  },
  dangerButton: {
    alignItems: 'center',
    borderColor: colors.danger,
    borderRadius: 8,
    borderWidth: 1,
    minHeight: 50,
    justifyContent: 'center',
  },
  dangerButtonText: {
    color: colors.danger,
    fontSize: 15,
    fontWeight: '700',
  },
  buttonDisabled: {
    opacity: 0.55,
  },
});
