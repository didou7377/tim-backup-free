import fs from 'node:fs';
import path from 'node:path';
import { createRequire } from 'node:module';

const require = createRequire(import.meta.url);
const PhpParser = require('php-parser');
const parser = new PhpParser.Engine({
	ast: { withPositions: true },
	parser: { extractDoc: true, php7: true },
});

const roots = ['tim-backup.php', 'uninstall.php', 'includes'];
const files = [];

function collect(target) {
	const stat = fs.statSync(target);

	if (stat.isDirectory()) {
		for (const entry of fs.readdirSync(target)) {
			collect(path.join(target, entry));
		}
	} else if (target.endsWith('.php')) {
		files.push(target);
	}
}

for (const root of roots) {
	collect(root);
}

let failed = false;

for (const file of files.sort()) {
	try {
		parser.parseCode(fs.readFileSync(file, 'utf8'), file);
		console.log(`PASS ${file}`);
	} catch (error) {
		failed = true;
		console.error(`FAIL ${file}: ${error.message}`);
	}
}

if (failed) {
	process.exitCode = 1;
}
