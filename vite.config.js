import { defineConfig } from 'vite';

/*
 * Builds the scenario canvas into a single self-contained script.
 *
 * A package cannot ask the host to build its assets: composer install runs no
 * bundler, and requiring one would mean every application that installs this
 * also installs node, react and a build step it has no other use for. So the
 * bundle is built here and `dist/` is committed.
 *
 * The format is `iife` rather than `es` for the same reason. Filament serves a
 * registered asset as a plain <script src>, with no import map and no module
 * graph; an ES bundle would resolve nothing and fail silently — a blank canvas
 * with no error anyone can see.
 *
 * React is bundled in, not externalised. Half a megabyte is the honest cost of
 * a canvas, and Filament only loads it on the page that asks for it; expecting
 * a host to provide a matching React on a global is how a package works on the
 * developer's machine and nowhere else.
 */
export default defineConfig({
    /*
     * React reads `process.env.NODE_ENV` to decide whether to run its
     * development checks. In an application bundle Vite substitutes that for
     * you; in library mode it does not, and the browser has no `process` — so
     * the bundle throws `process is not defined` on its very first line and the
     * canvas is simply a blank rectangle, with the real error only in the
     * console.
     */
    define: {
        'process.env.NODE_ENV': JSON.stringify('production'),
    },
    esbuild: {
        // Automatic JSX, without @vitejs/plugin-react: the plugin's peer range
        // trails Vite's majors, and its only remaining job here — fast refresh —
        // is meaningless for a bundle that ships prebuilt.
        jsx: 'automatic',
    },
    build: {
        outDir: 'dist',
        emptyOutDir: false,
        cssCodeSplit: false,
        lib: {
            entry: 'resources/js/editor.jsx',
            name: 'ChatbotGraphEditor',
            formats: ['iife'],
            fileName: () => 'chatbot-graph.js',
        },
        rollupOptions: {
            output: {
                assetFileNames: 'chatbot-graph.[ext]',
            },
        },
    },
});
