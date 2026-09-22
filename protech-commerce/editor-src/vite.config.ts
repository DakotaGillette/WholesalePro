import { defineConfig } from 'vite';
import preact from '@preact/preset-vite';

// Builds one self-contained IIFE, no hashed names, no code splitting: the
// output has to land at a fixed path (../assets/editor/editor.js) that
// Plugin::enqueue_admin_assets() enqueues directly, and the built files are
// committed to the repo so a deploy never needs a Node build step.
export default defineConfig( {
	plugins: [ preact() ],
	test: {
		environment: 'node',
		globals: true,
	},
	build: {
		outDir: '../assets/editor',
		emptyOutDir: false,
		sourcemap: false,
		lib: {
			entry: 'src/editor.tsx',
			name: 'ProtechEditor',
			formats: [ 'iife' ],
			fileName: () => 'editor.js',
		},
		rollupOptions: {
			output: {
				assetFileNames: 'editor.[ext]',
			},
		},
	},
} );
