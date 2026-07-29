CREATE TABLE `calendar_cache` (
	`cache_key` text PRIMARY KEY NOT NULL,
	`source_hash` text NOT NULL,
	`payload` text NOT NULL,
	`updated_at` text DEFAULT CURRENT_TIMESTAMP NOT NULL
);
