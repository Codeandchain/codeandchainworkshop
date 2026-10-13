import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'

// Configuration Vite
// - le plugin Vue pour compiler les fichiers .vue
// - un proxy /api vers le petit serveur Express du dossier server/
//   (en dev, Vite sert l'interface sur http://localhost:5173
//    et redirige les appels API vers Express sur le port 3000)
export default defineConfig({
  plugins: [vue()],
  server: {
    port: 5173,
    proxy: {
      '/api': {
        target: `http://localhost:${process.env.PORT || 3000}`,
        changeOrigin: true,
      },
    },
  },
  build: {
    outDir: 'dist',
  },
})
