import { sql } from "drizzle-orm";
import { index, sqliteTable, text } from "drizzle-orm/sqlite-core";

export const calendarCache = sqliteTable(
  "calendar_cache",
  {
    cacheKey: text("cache_key").primaryKey(),
    sourceHash: text("source_hash").notNull(),
    payload: text("payload").notNull(),
    updatedAt: text("updated_at").notNull().default(sql`CURRENT_TIMESTAMP`),
  },
  (table) => [
    index("calendar_cache_updated_at_idx").on(table.updatedAt),
  ],
);
