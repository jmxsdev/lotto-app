import { rmSync } from 'node:fs';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';

// Resolve paths relative to this script (not process.cwd()) so the purge
// works from any working directory.
const packageRoot = fileURLToPath(new URL('..', import.meta.url));
const artifactDirs = ['dist', 'build', 'release'];

for (const dir of artifactDirs) {
  const target = join(packageRoot, dir);
  rmSync(target, { recursive: true, force: true });
  console.log(`[clean:artifacts] removed ${target}`);
}
