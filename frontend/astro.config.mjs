// @ts-check
import { defineConfig } from 'astro/config';

// https://astro.build/config
export default defineConfig({
	vite: {
		server: {
			proxy: {
				'/backend': {
					target: 'http://127.0.0.1',
					changeOrigin: true,
					secure: false,
				},
			},
		},
	},
});
