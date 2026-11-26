import { defineConfig } from 'tsup';

export default defineConfig({
    entry: ['resources/js/src/index.ts', 'resources/js/src/anmerken.ts'],
    format: ['esm'],
    dts: true,
    clean: true,
    // React bleibt beim Produkt, sobald Bausteine dazukommen: zwei React-Instanzen
    // im selben Baum enden in „Invalid hook call".
    external: ['react', 'react-dom', '@inertiajs/react', 'pdfjs-dist'],
});
