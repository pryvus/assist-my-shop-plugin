#!/usr/bin/env node

const fs = require('fs');
const path = require('path');

const pluginRoot = path.resolve(__dirname, '..');

const jsFiles = [
    'assets/chat.js',
    'assets/admin/js/media-uploader.js',
    'assets/admin/js/ams-admin.js',
    'assets/admin/js/ams-styling-tools.js',
];

const cssFiles = [
    'assets/chat.css',
    'assets/admin/css/ams-styling-tools.css',
];

function minifyJs(content) {
    return content
        .split('\n')
        .map((line) => line.trim())
        .filter((line) => line.length > 0 && !line.startsWith('//'))
        .join('\n');
}

function minifyCss(content) {
    return content
        .replace(/\/\*[\s\S]*?\*\//g, '')
        .replace(/\s+/g, ' ')
        .replace(/\s*([{}:;,>])\s*/g, '$1')
        .replace(/;}/g, '}')
        .trim();
}

function toMinPath(relativePath) {
    return relativePath.replace(/\.(js|css)$/i, '.min.$1');
}

function writeMinified(relativePath, minified) {
    const outRelative = toMinPath(relativePath);
    const outPath = path.join(pluginRoot, outRelative);
    fs.writeFileSync(outPath, minified, 'utf8');
    return outRelative;
}

for (const relativePath of jsFiles) {
    const fullPath = path.join(pluginRoot, relativePath);
    const content = fs.readFileSync(fullPath, 'utf8');
    writeMinified(relativePath, minifyJs(content));
}

for (const relativePath of cssFiles) {
    const fullPath = path.join(pluginRoot, relativePath);
    const content = fs.readFileSync(fullPath, 'utf8');
    writeMinified(relativePath, minifyCss(content));
}

console.log('Minified assets updated.');
