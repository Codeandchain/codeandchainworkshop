import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'

// Configuration Vite
// - le plugin Vue pour compiler les fichiers .vue
// - en dev, Vite sert l'interface sur http://localhost:5173 et redirige
//   les appels /api vers l'API Laravel (php artisan serve, port 8000)
// - le build part dans backend/public/spa : Laravel sert la page, et
//   c'est ce seul dossier backend/ qu'on envoie chez l'hébergeur
export default defineConfig({
  plugins: [vue()],
  base: '/spa/',
  server: {
    port: 5173,
    proxy: {
      '/api': {
        target: `http://127.0.0.1:${process.env.API_PORT || 8000}`,
        changeOrigin: true,
      },
    },
  },
  build: {
    outDir: 'backend/public/spa',
    emptyOutDir: true,
  },
})
