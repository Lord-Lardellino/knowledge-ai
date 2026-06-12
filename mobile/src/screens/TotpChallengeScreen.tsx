import React, { useState } from 'react';
import {
  KeyboardAvoidingView,
  Platform,
  Pressable,
  StyleSheet,
  Text,
  TextInput,
  View,
} from 'react-native';
import { useAuth } from '../auth/AuthContext';
import { colors, spacing } from '../theme';

export function TotpChallengeScreen() {
  const { totpPending, verifyTotpLogin, cancelTotpLogin } = useAuth();
  const [code, setCode] = useState('');
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const submit = async () => {
    const normalized = code.trim();
    setError(null);

    if (!normalized) {
      setError('Inserisci il codice.');
      return;
    }

    setLoading(true);

    try {
      await verifyTotpLogin(normalized);
    } catch (caught) {
      setError(caught instanceof Error ? caught.message : 'Codice non valido.');
    } finally {
      setLoading(false);
    }
  };

  return (
    <KeyboardAvoidingView
      behavior={Platform.OS === 'ios' ? 'padding' : undefined}
      style={styles.container}
    >
      <View style={styles.header}>
        <Text style={styles.eyebrow}>Verifica richiesta</Text>
        <Text style={styles.title}>Codice 2FA</Text>
        <Text style={styles.subtitle}>
          {totpPending?.email || 'Questo account'} richiede un codice authenticator o recovery.
        </Text>
      </View>

      <View style={styles.form}>
        <TextInput
          autoCapitalize="characters"
          autoCorrect={false}
          keyboardType="number-pad"
          maxLength={11}
          onChangeText={setCode}
          placeholder="123456"
          style={styles.input}
          value={code}
        />

        {error ? <Text style={styles.error}>{error}</Text> : null}

        <Pressable disabled={loading} style={styles.primaryButton} onPress={submit}>
          <Text style={styles.primaryButtonText}>{loading ? 'Verifico...' : 'Verifica codice'}</Text>
        </Pressable>

        <Pressable disabled={loading} style={styles.secondaryButton} onPress={cancelTotpLogin}>
          <Text style={styles.secondaryButtonText}>Annulla</Text>
        </Pressable>
      </View>
    </KeyboardAvoidingView>
  );
}

const styles = StyleSheet.create({
  container: {
    flex: 1,
    justifyContent: 'center',
    padding: spacing.lg,
  },
  header: {
    marginBottom: spacing.lg,
  },
  eyebrow: {
    color: colors.primary,
    fontSize: 13,
    fontWeight: '700',
    textTransform: 'uppercase',
  },
  title: {
    color: colors.text,
    fontSize: 34,
    fontWeight: '800',
    marginTop: spacing.sm,
  },
  subtitle: {
    color: colors.muted,
    fontSize: 16,
    lineHeight: 23,
    marginTop: spacing.sm,
  },
  form: {
    gap: spacing.md,
  },
  input: {
    backgroundColor: colors.surface,
    borderColor: colors.border,
    borderRadius: 8,
    borderWidth: 1,
    color: colors.text,
    fontSize: 22,
    fontWeight: '700',
    letterSpacing: 0,
    minHeight: 56,
    paddingHorizontal: spacing.md,
    textAlign: 'center',
  },
  error: {
    color: colors.danger,
    fontSize: 14,
  },
  primaryButton: {
    alignItems: 'center',
    backgroundColor: colors.primary,
    borderRadius: 8,
    minHeight: 52,
    justifyContent: 'center',
  },
  primaryButtonText: {
    color: '#ffffff',
    fontSize: 16,
    fontWeight: '700',
  },
  secondaryButton: {
    alignItems: 'center',
    borderColor: colors.border,
    borderRadius: 8,
    borderWidth: 1,
    minHeight: 52,
    justifyContent: 'center',
  },
  secondaryButtonText: {
    color: colors.text,
    fontSize: 16,
    fontWeight: '700',
  },
});
