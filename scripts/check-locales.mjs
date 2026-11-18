// Compiles every message in the given locale directory (default
// resources/js/locales) with the vue-i18n message compiler and exits 1 if any
// fail. A bad message (e.g. a bare "@" or stray "{") only throws when a page
// renders it, blanking the screen, so catch it before shipping.
import { baseCompile } from '@intlify/message-compiler';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const dir = path.resolve(process.argv[2] ?? path.join(root, 'resources/js/locales'));
let bad = 0;

const walk = (file, obj, prefix) => {
    for (const [key, value] of Object.entries(obj)) {
        const keyPath = prefix ? `${prefix}.${key}` : key;
        if (value && typeof value === 'object') {
            walk(file, value, keyPath);
        } else if (typeof value === 'string') {
            let error = null;
            baseCompile(value, { onError: (e) => { error ??= e; } });
            if (error) {
                bad++;
                console.error(`${file} ${keyPath}: ${error.message} (code ${error.code})`);
            }
        }
    }
};

for (const file of fs.readdirSync(dir).filter((f) => f.endsWith('.json'))) {
    walk(file, JSON.parse(fs.readFileSync(path.join(dir, file), 'utf8')), '');
}

if (bad) {
    console.error(`${bad} invalid locale message(s). Escape literal specials, e.g. {'@'}, {'{'}, {'|'}.`);
    process.exit(1);
}
