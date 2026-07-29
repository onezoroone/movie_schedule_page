import { env } from "cloudflare:workers";

export type StoredCalendarCache = {
  cacheKey: string;
  sourceHash: string;
  payload: string;
  updatedAt: string;
};

let initialization: Promise<void> | null = null;

function getD1() {
  if (!env.DB) {
    throw new Error("Cloudflare D1 binding `DB` is unavailable.");
  }
  return env.DB;
}

async function ensureCalendarCacheTable() {
  if (!initialization) {
    const d1 = getD1();
    initialization = d1
      .batch([
        d1.prepare(`
          CREATE TABLE IF NOT EXISTS calendar_cache (
            cache_key TEXT PRIMARY KEY NOT NULL,
            source_hash TEXT NOT NULL,
            payload TEXT NOT NULL,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
          )
        `),
        d1.prepare(`
          CREATE INDEX IF NOT EXISTS calendar_cache_updated_at_idx
          ON calendar_cache (updated_at)
        `),
      ])
      .then(() => undefined)
      .catch((error) => {
        initialization = null;
        throw error;
      });
  }
  return initialization;
}

export async function readCalendarCache(cacheKey: string) {
  await ensureCalendarCacheTable();
  const row = await getD1()
    .prepare(
      `
        SELECT
          cache_key AS cacheKey,
          source_hash AS sourceHash,
          payload,
          updated_at AS updatedAt
        FROM calendar_cache
        WHERE cache_key = ?
        LIMIT 1
      `,
    )
    .bind(cacheKey)
    .first<StoredCalendarCache>();

  return row ?? null;
}

export async function writeCalendarCache(
  cacheKey: string,
  sourceHash: string,
  payload: string,
) {
  await ensureCalendarCacheTable();
  await getD1()
    .prepare(
      `
        INSERT INTO calendar_cache (cache_key, source_hash, payload, updated_at)
        VALUES (?, ?, ?, CURRENT_TIMESTAMP)
        ON CONFLICT(cache_key) DO UPDATE SET
          source_hash = excluded.source_hash,
          payload = excluded.payload,
          updated_at = CURRENT_TIMESTAMP
      `,
    )
    .bind(cacheKey, sourceHash, payload)
    .run();
}
