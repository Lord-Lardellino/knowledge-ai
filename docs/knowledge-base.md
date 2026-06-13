# Knowledge Base — come funziona indicizzazione e ricerca semantica

> Feature specifica di **knowledge-ai (Sapio)**. NON fa parte di `saas/core`
> (che gestisce solo auth, passkey, TOTP, multi-tenancy, audit).

Questo documento spiega, dall'inizio, cosa significa "indicizzare" un documento
e "cercare per vettore", e come è implementato nel codice.

---

## 1. Il problema che risolviamo

Una ricerca classica trova solo le **parole esatte**. Se l'utente cerca
"come chiedo le ferie?" ma il documento dice "richieste di congedo", la ricerca
per parole **non trova nulla**.

Vogliamo una ricerca che capisca il **significato**, non le parole letterali.
Si chiama **ricerca semantica**.

---

## 2. L'embedding (il vettore)

Un **embedding** è la traduzione di un testo in una lista di numeri (nel nostro
caso **1536 numeri**) che ne rappresenta il significato.

Proprietà chiave: **testi con significato simile producono vettori "vicini"**.

```
"come chiedo le ferie?"        → [0.21, -0.04, 0.88, ...]
"procedura richiesta congedo"  → [0.19, -0.06, 0.85, ...]   ← VICINO
"fattura cliente di marzo"     → [-0.7,  0.30,  0.02, ...]  ← LONTANO
```

Immaginalo come una **mappa di significati**: ogni testo è un punto. Testi che
parlano della stessa cosa finiscono vicini, anche se usano parole diverse.

Gli embedding li genera **Gemini Embedding 2** via API Gemini. Il vettore nativo
è a 3072 dimensioni, ma lo **tronchiamo a 1536** perché:
- gli indici di pgvector supportano al massimo 2000 dimensioni;
- 1536 occupa metà spazio con qualità praticamente identica (tecnica Matryoshka).

---

## 3. Indicizzare = preparare il documento per la ricerca

Quando un documento passa da `pending` → `indexed`, il job
[`ProcessDocument`](../app/Jobs/ProcessDocument.php) esegue:

1. **Estrazione testo** — [`TextExtractor`](../app/Services/Knowledge/TextExtractor.php)
   tira fuori il testo grezzo da PDF / DOCX / XLSX / TXT.
2. **Chunking** — [`TextChunker`](../app/Services/Knowledge/TextChunker.php)
   spezza il testo in pezzi (chunk) da ~1500 caratteri con un piccolo overlap.
   *Perché a pezzi?* Un documento intero è troppo lungo e generico: cercando vuoi
   trovare **il passaggio** pertinente, non tutto il file. Chunk più piccoli =
   risposte più precise. (E c'è un limite di input per chiamata all'API.)
3. **Embedding** — [`GeminiEmbedder`](../app/Services/Knowledge/GeminiEmbedder.php)
   trasforma ogni chunk nel suo vettore (in batch).
4. **Salvataggio** — i vettori finiscono in Postgres, tabella `document_chunks`,
   colonna `embedding vector(1536)` (estensione **pgvector**).

"Indicizzare" = questo intero processo. Alla fine il documento è una collezione
di punti sulla mappa dei significati, pronti per essere confrontati.

---

## 4. Cercare per vettore

Quando l'utente fa una domanda
([`SemanticSearch`](../app/Services/Knowledge/SemanticSearch.php)):

1. La **domanda** viene trasformata nel suo vettore (stesso modello).
2. Si chiede a Postgres: *quali chunk hanno il vettore più vicino a questo?*
   usando la **distanza coseno** — l'operatore `<=>` di pgvector — che misura
   quanto due punti sono vicini sulla mappa.
3. Si restituiscono i chunk più vicini = i passaggi più pertinenti alla domanda.

```sql
SELECT content, 1 - (embedding <=> :query_vector) AS similarity
FROM document_chunks
WHERE tenant_id = :tenant
ORDER BY embedding <=> :query_vector
LIMIT 5;
```

### L'indice HNSW

Confrontare la domanda con migliaia di chunk uno per uno sarebbe lento.
L'**indice HNSW** organizza i vettori in modo da trovare "i più vicini" quasi
istantaneamente, senza controllarli tutti — come l'indice analitico di un libro,
ma per punti su una mappa.

---

## 5. Isolamento multi-tenant

Ogni `document` e `document_chunk` ha un `tenant_id`. La ricerca filtra **sempre**
per tenant: un'azienda non può vedere i chunk di un'altra. In lettura il filtro è
garantito dal `TenantScope` di saas-core agganciato via il trait
[`HasTenant`](../app/Models/Concerns/HasTenant.php).

---

## 6. Costo / quota (Gemini free tier)

Il contatore Gemini conta **ogni testo trasformato in vettore**, non ogni chiamata
HTTP. Quindi:

- indicizzare 1 documento da 10 chunk = **10 unità** di quota embedding;
- ogni ricerca utente = **1 unità** (l'embedding della domanda).

Limiti free tier rilevanti: ~1.000 embedding/giorno. Per questo l'indicizzazione
gira in **coda asincrona** (`ProcessDocument` su `queue:work`), con batch e retry
sui rate limit, così l'esperienza utente non si blocca mai.

---

## 7. Modelli usati

| Ruolo | Modello | Dove |
|-------|---------|------|
| Embedding (indicizzazione + query) | Gemini Embedding 2 (troncato a 1536) | `GeminiEmbedder` |
| Generazione risposte semplici | Gemma 4 26B | (chat/template, prossimi step) |
| Generazione risposte complesse | Gemma 4 31B | (chat/template, prossimi step) |

Configurazione in [`config/knowledge.php`](../config/knowledge.php),
chiave API in `.env` (`GEMINI_API_KEY`).
