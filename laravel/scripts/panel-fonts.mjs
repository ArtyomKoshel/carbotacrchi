// Copies the Onest variable font (Cyrillic + Latin subsets) next to the panel theme.
// The @font-face rules are in resources/css/filament/panel/theme.css.
import { copyFileSync, mkdirSync } from 'node:fs';

const from = 'node_modules/@fontsource-variable/onest/files';
const to = 'public/fonts/onest';
const subsets = ['cyrillic', 'cyrillic-ext', 'latin', 'latin-ext'];

mkdirSync(to, { recursive: true });
for (const subset of subsets) {
    copyFileSync(`${from}/onest-${subset}-wght-normal.woff2`, `${to}/onest-${subset}-wght-normal.woff2`);
}
copyFileSync('node_modules/@fontsource-variable/onest/LICENSE', `${to}/LICENSE`);
