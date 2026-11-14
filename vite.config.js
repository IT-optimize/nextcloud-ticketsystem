/**
 * SPDX-FileCopyrightText: 2026 Co-Optimize
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { createAppConfig } from '@nextcloud/vite-config'
import { readdirSync, rmSync } from 'node:fs'
import { resolve } from 'node:path'

// @nextcloud/vite-config empties js/ only on the first build of a process, so a
// long-running watcher would pile up every old hashed chunk. Drop what the
// latest rebuild did not write.
const pruneStaleChunks = {
	name: 'ticketsystem-prune-stale-chunks',
	writeBundle(options, bundle) {
		if (!this.meta.watchMode) {
			return
		}
		for (const file of readdirSync(resolve('js'))) {
			if (!(`js/${file}` in bundle)) {
				rmSync(resolve('js', file))
			}
		}
	},
}

// Builds js/ticketsystem-main.mjs (agent UI), js/ticketsystem-admin.mjs (admin settings)
// and js/ticketsystem-portal.mjs (customer portal).
export default createAppConfig({
	main: 'src/main.js',
	admin: 'src/admin.js',
	portal: 'src/portal.js',
}, {
	// Inject each chunk's CSS with that chunk, so every entry point gets their styles.
	inlineCSS: { relativeCSSInjection: true },
	// Docker Desktop on Windows/macOS does not pass file-change events into
	// containers, so the dev container sets WATCH_POLLING=1 to poll instead.
	config: {
		plugins: [pruneStaleChunks],
		...(process.env.WATCH_POLLING
			? { build: { watch: { chokidar: { usePolling: true, interval: 500 } } } }
			: {}),
	},
})
