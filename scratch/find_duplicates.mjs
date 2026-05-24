import { readFileSync } from 'fs';

const content = readFileSync('resources/js/routes/index.ts', 'utf8');
const lines = content.split('\n');
const foundExports = {};
const foundForms = {};

lines.forEach((line, index) => {
    const exportMatch = line.match(/export const (\w+) =/);
    if (exportMatch) {
        const name = exportMatch[1];
        if (foundExports[name]) {
            console.log(`Duplicate export: ${name} at line ${index + 1} and ${foundExports[name]}`);
        }
        foundExports[name] = index + 1;
    }

    const formMatch = line.match(/const (\w+Form) =/);
    if (formMatch) {
        const name = formMatch[1];
        if (foundForms[name]) {
            console.log(`Duplicate form: ${name} at line ${index + 1} and ${foundForms[name]}`);
        }
        foundForms[name] = index + 1;
    }
});
