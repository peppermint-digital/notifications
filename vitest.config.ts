import { defineConfig } from 'vitest/config';

// Die Umgebung steht pro Datei im Kopf (`@vitest-environment jsdom`): Die
// reinen Regeln laufen ohne DOM, nur die Komponenten brauchen einen.
export default defineConfig({});
