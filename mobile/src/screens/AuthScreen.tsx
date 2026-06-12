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
import { defaultApiUrl, defaultTenantId } from '../config';
import { colors, spacing } from '../theme';

type Mode = 'login' | 'register' | 'recovery';

export function AuthScreen() {
  const { login, register, recover, signInDemo } = useAuth();
  const [mode, setMode] = useState<Mode>('login');
  // URL e tenant arrivano dalla config: niente campi tecnici in UI.
  const [apiUrl] = useState(defaultApiUrl);
  const [tenantId] = useState(defaultTenantId);
  const [name, setName] = useState('');
  const [company, setCompany] = useState('');
  const [email, setEmail] = useState('');
  const [loading, setLoading] = useState(false);
  const [message, setMessage] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);

  const submit = async () => {
    setError(null);
    setMessage(null);

    if (!email.trim()) {
      setError('Inserisci email.');
      return;
    }

    if (mode === 'register' && !name.trim()) {
      setError('Inserisci nome.');
      return;
    }

    setLoading(true);

    try {
      if (mode === 'login') {
        await login({ apiUrl: apiUrl.trim(), tenantId: tenantId.trim(), email: email.trim() });
      } else if (mode === 'register') {
        await register({
          apiUrl: apiUrl.trim(),
          tenantId: tenantId.trim(),
          name: name.trim(),
          email: email.trim(),
          company: company.trim() || undefined,
        });
      } else {
        setMessage(await recover({ apiUrl: apiUrl.trim(), tenantId: tenantId.trim(), email: email.trim() }));
      }
    } catch (caught) {
      setError(caught instanceof Error ? caught.message : 'Operazione non completata.');
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
        <Text style={styles.eyebrow}>SaaS Core</Text>
        <Text style={styles.title}>Accesso mobile</Text>
        <Text style={styles.subtitle}>Passkey native, recovery email e token mobile Sanctum.</Text>
      </View>

      <View style={styles.tabs}>
        <Tab label="Login" active={mode === 'login'} onPress={() => setMode('login')} />
        <Tab label="Register" active={mode === 'register'} onPress={() => setMode('register')} />
        <Tab label="Recovery" active={mode === 'recovery'} onPress={() => setMode('recovery')} />
      </View>

      <View style={styles.form}>
        {mode === 'register' ? (
          <>
            <Text style={styles.label}>Nome completo</Text>
            <TextInput
              value={name}
              onChangeText={setName}
              placeholder="Mario Rossi"
              placeholderTextColor={colors.muted}
              style={styles.input}
            />
            {/* Self-signup: compilando l'azienda viene creato il tenant
                e l'utente ne diventa owner. Vuoto = registrazione senza tenant. */}
            <Text style={styles.label}>Nome azienda</Text>
            <TextInput
              value={company}
              onChangeText={setCompany}
              placeholder="La tua azienda (crea il tuo spazio)"
              placeholderTextColor={colors.muted}
              style={styles.input}
            />
          </>
        ) : null}
        <Text style={styles.label}>Email</Text>
        <TextInput
          autoCapitalize="none"
          keyboardType="email-address"
          value={email}
          onChangeText={setEmail}
          placeholder="nome@azienda.it"
          placeholderTextColor={colors.muted}
          style={styles.input}
        />

        {error ? <Text style={styles.error}>{error}</Text> : null}
        {message ? <Text style={styles.message}>{message}</Text> : null}

        <Pressable disabled={loading} style={styles.primaryButton} onPress={submit}>
          <Text style={styles.primaryButtonText}>{buttonLabel(mode, loading)}</Text>
        </Pressable>

        <Pressable style={styles.secondaryButton} onPress={signInDemo}>
          <Text style={styles.secondaryButtonText}>Apri demo sessione</Text>
        </Pressable>
      </View>
    </KeyboardAvoidingView>
  );
}

function Tab({ label, active, onPress }: { label: string; active: boolean; onPress: () => void }) {
  return (
    <Pressable onPress={onPress} style={[styles.tab, active ? styles.tabActive : null]}>
      <Text style={[styles.tabText, active ? styles.tabTextActive : null]}>{label}</Text>
    </Pressable>
  );
}

function buttonLabel(mode: Mode, loading: boolean): string {
  if (loading) {
    return 'Attendi...';
  }

  if (mode === 'register') {
    return 'Registrati con passkey';
  }

  if (mode === 'recovery') {
    return 'Invia recovery email';
  }

  return 'Accedi con passkey';
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
  tabs: {
    backgroundColor: colors.chip,
    borderRadius: 8,
    flexDirection: 'row',
    marginBottom: spacing.md,
    padding: 4,
  },
  tab: {
    alignItems: 'center',
    borderRadius: 6,
    flex: 1,
    minHeight: 40,
    justifyContent: 'center',
  },
  tabActive: {
    backgroundColor: colors.surface,
  },
  tabText: {
    color: colors.muted,
    fontSize: 13,
    fontWeight: '800',
  },
  tabTextActive: {
    color: colors.text,
  },
  form: {
    gap: spacing.md,
  },
  label: {
    color: colors.text,
    fontSize: 14,
    fontWeight: '700',
    marginBottom: -6,
  },
  input: {
    backgroundColor: colors.surface,
    borderColor: colors.border,
    borderRadius: 8,
    borderWidth: 1,
    color: colors.text,
    fontSize: 16,
    minHeight: 52,
    paddingHorizontal: spacing.md,
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
