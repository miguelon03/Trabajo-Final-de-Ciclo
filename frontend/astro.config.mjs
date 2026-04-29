// @ts-check
import { defineConfig } from 'astro/config';

// https://astro.build/config
export default defineConfig({
	vite: {
		server: {
			proxy: {
				'/backend': {
					target: 'http://tfc.local',
					changeOrigin: true,
					secure: false,
				},
			},
		},
	},
});
