import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

const rootDir = path.resolve(fileURLToPath(new URL('..', import.meta.url)));
const pluginFile = path.join(rootDir, 'thumbnail-remover.php');
const readmeFile = path.join(rootDir, 'readme.txt');
const markdownReadmeFile = path.join(rootDir, 'README.md');
const svnDir = path.join(rootDir, 'svn');
const trunkDir = path.join(svnDir, 'trunk');
const tagsDir = path.join(svnDir, 'tags');
const managedAssetCopies = [
	{
		source: path.join(rootDir, 'assets', 'banner-1544x500.png'),
		target: path.join(svnDir, 'assets', 'banner-1544x500.png'),
	},
	{
		source: path.join(rootDir, 'assets', 'screenshots', 'screenshot-1.png'),
		target: path.join(svnDir, 'assets', 'screenshot-1.png'),
	},
	{
		source: path.join(rootDir, 'assets', 'screenshots', 'screenshot-2.png'),
		target: path.join(svnDir, 'assets', 'screenshot-2.png'),
	},
	{
		source: path.join(rootDir, 'assets', 'screenshots', 'screenshot-3.png'),
		target: path.join(svnDir, 'assets', 'screenshot-3.png'),
	},
	{
		source: path.join(rootDir, 'assets', 'screenshots', 'screenshot-4.png'),
		target: path.join(svnDir, 'assets', 'screenshot-4.png'),
	},
	{
		source: path.join(rootDir, 'assets', 'screenshots', 'screenshot-5.png'),
		target: path.join(svnDir, 'assets', 'screenshot-5.png'),
	},
	{
		source: path.join(rootDir, 'assets', 'screenshots', 'screenshot-6.png'),
		target: path.join(svnDir, 'assets', 'screenshot-6.png'),
	},
	{
		source: path.join(rootDir, 'assets', 'screenshots', 'screenshot-7.png'),
		target: path.join(svnDir, 'assets', 'screenshot-7.png'),
	},
];

const [, , command = 'verify', ...args] = process.argv;

main().catch((error) => {
	console.error(error.message);
	process.exit(1);
});

async function main() {
	switch (command) {
		case 'verify':
			verifyVersionConsistency();
			console.log(`Version is in sync at ${readVersionMetadata().pluginVersion}.`);
			break;

		case 'prepare-push': {
			const releaseType = firstNonFlag(args) || process.env.TRPL_RELEASE_TYPE || 'patch';
			preparePushRelease(releaseType);
			break;
		}

		case 'bump': {
			const nextVersion = resolveNextVersion(firstNonFlag(args));
			updateProjectVersion(nextVersion);
			console.log(`Updated plugin version to ${nextVersion}.`);
			break;
		}

		case 'deploy': {
			const dryRun = hasFlag(args, '--dry-run');
			const message = stripFlags(args).join(' ').trim() || defaultSvnMessage();
			verifyVersionConsistency();
			deployToWordPressOrg(message, { dryRun });
			break;
		}

		case 'notes':
			process.stdout.write(generateReleaseNotes());
			break;

		case 'ship': {
			const dryRun = hasFlag(args, '--dry-run');
			const positionalArgs = stripFlags(args);
			const requestedVersion = positionalArgs[0];
			const commitMessage = positionalArgs.slice(1).join(' ').trim();
			if (!requestedVersion) {
				throw new Error('Provide a release type or explicit version. Example: npm run ship -- patch');
			}

			assertGitBranchIsMain();
			const nextVersion = resolveNextVersion(requestedVersion);
			updateProjectVersion(nextVersion);
			verifyVersionConsistency();
			deployToWordPressOrg(`Release ${nextVersion}`, { dryRun });
			if (!dryRun) {
				commitAndPushRelease(nextVersion, commitMessage);
			}
			console.log(`Release ${nextVersion} shipped to WordPress.org SVN and origin/main.`);
			break;
		}

		default:
			throw new Error(`Unknown command "${command}". Use verify, prepare-push, bump, deploy, notes, or ship.`);
	}
}

function readVersionMetadata() {
	const pluginContents = fs.readFileSync(pluginFile, 'utf8');
	const readmeContents = fs.readFileSync(readmeFile, 'utf8');
	const markdownReadmeContents = fs.existsSync(markdownReadmeFile)
		? fs.readFileSync(markdownReadmeFile, 'utf8')
		: '';

	const pluginVersion = extractMatch(pluginContents, /^Version:\s*(.+)$/m, 'plugin header version');
	const constantVersion = extractMatch(pluginContents, /define\(\s*['"]TRPL_VERSION['"]\s*,\s*['"]([^'"]+)['"]\s*\);/, 'TRPL_VERSION');
	const stableTag = extractMatch(readmeContents, /^Stable tag:\s*(.+)$/m, 'readme stable tag');
	const markdownStableTag = markdownReadmeContents
		? extractMatch(markdownReadmeContents, /^\*\*Stable tag:\*\*\s*(.+?)\s*$/m, 'README stable tag')
		: stableTag;

	return {
		pluginVersion,
		constantVersion,
		stableTag,
		markdownStableTag,
	};
}

function verifyVersionConsistency() {
	const metadata = readVersionMetadata();
	const versions = [
		metadata.pluginVersion,
		metadata.constantVersion,
		metadata.stableTag,
		metadata.markdownStableTag,
	];

	if (new Set(versions).size !== 1) {
		throw new Error(
			`Version mismatch detected:\n` +
			`thumbnail-remover.php header: ${metadata.pluginVersion}\n` +
			`TRPL_VERSION: ${metadata.constantVersion}\n` +
			`readme.txt Stable tag: ${metadata.stableTag}\n` +
			`README.md Stable tag: ${metadata.markdownStableTag}`
		);
	}
}

function resolveNextVersion(input) {
	if (!input) {
		throw new Error('Missing release type or version. Use patch, minor, major, or x.y.z.');
	}

	const currentVersion = readVersionMetadata().pluginVersion;
	if (/^\d+\.\d+\.\d+$/.test(input)) {
		return input;
	}

	const parts = currentVersion.split('.').map(Number);
	if (parts.length !== 3 || parts.some(Number.isNaN)) {
		throw new Error(`Current version "${currentVersion}" is not a semantic version.`);
	}

	switch (input) {
		case 'patch':
			return `${parts[0]}.${parts[1]}.${parts[2] + 1}`;
		case 'minor':
			return `${parts[0]}.${parts[1] + 1}.0`;
		case 'major':
			return `${parts[0] + 1}.0.0`;
		default:
			throw new Error(`Unsupported release type "${input}". Use patch, minor, major, or x.y.z.`);
	}
}

function updateProjectVersion(nextVersion) {
	const metadata = readVersionMetadata();
	const currentVersion = metadata.pluginVersion;

	writeFileWithCheck(
		pluginFile,
		fs
			.readFileSync(pluginFile, 'utf8')
			.replace(/^Version:\s*.+$/m, `Version: ${nextVersion}`)
			.replace(/define\(\s*['"]TRPL_VERSION['"]\s*,\s*['"][^'"]+['"]\s*\);/, `define("TRPL_VERSION", "${nextVersion}");`)
	);

	writeFileWithCheck(
		readmeFile,
		fs
			.readFileSync(readmeFile, 'utf8')
			.replace(/^Stable tag:\s*.+$/m, `Stable tag: ${nextVersion}`)
	);

	if (fs.existsSync(markdownReadmeFile)) {
		writeFileWithCheck(
			markdownReadmeFile,
			fs
				.readFileSync(markdownReadmeFile, 'utf8')
				.replace(/^\*\*Stable tag:\*\*\s*.+$/m, `**Stable tag:** ${nextVersion}`)
		);
	}

	const packageJsonPath = path.join(rootDir, 'package.json');
	const packageJson = JSON.parse(fs.readFileSync(packageJsonPath, 'utf8'));
	packageJson.version = nextVersion;
	fs.writeFileSync(packageJsonPath, `${JSON.stringify(packageJson, null, 2)}\n`);

	const packageLockPath = path.join(rootDir, 'package-lock.json');
	if (fs.existsSync(packageLockPath)) {
		const packageLock = JSON.parse(fs.readFileSync(packageLockPath, 'utf8'));
		packageLock.version = nextVersion;
		if (packageLock.packages?.['']) {
			packageLock.packages[''].version = nextVersion;
		}
		fs.writeFileSync(packageLockPath, `${JSON.stringify(packageLock, null, 2)}\n`);
	}

	console.log(`Version bumped from ${currentVersion} to ${nextVersion}.`);
}

function preparePushRelease(requestedVersion) {
	assertCommandAvailable('git');
	assertGitBranchIsMain();
	verifyVersionConsistency();

	const currentVersion = readVersionMetadata().pluginVersion;
	const releaseCommitSubject = `chore(release): v${currentVersion}`;
	const headSubject = runCapture('git', ['log', '-1', '--pretty=%s'], rootDir).trim();

	if (headSubject === releaseCommitSubject) {
		console.log(`Release commit already prepared for v${currentVersion}.`);
		return;
	}

	const nextVersion = resolveNextVersion(requestedVersion);
	updateProjectVersion(nextVersion);
	run('git', ['add', 'thumbnail-remover.php', 'readme.txt', 'README.md', 'package.json', 'package-lock.json'], rootDir);
	run('git', ['commit', '-m', `chore(release): v${nextVersion}`], rootDir);
	console.log(`Prepared release version v${nextVersion}. Re-run the push so the new release commit is included.`);
	process.exit(10);
}

function deployToWordPressOrg(message, options = {}) {
	assertCommandAvailable('svn');
	assertCommandAvailable('rsync');
	verifySvnCheckout();
	const { dryRun = false } = options;

	const tempDistDir = buildDistribution();

	try {
		run('svn', ['update', svnDir]);
		syncDistributionToSvn(tempDistDir);
		applyManagedAssets();
		finalizeSvnVersionTag(readVersionMetadata().pluginVersion);
		stageSvnWorkingCopy();

		if (!hasSvnChanges()) {
			console.log('No SVN changes detected. Nothing to commit.');
			return;
		}

		if (dryRun) {
			console.log('Dry run enabled. SVN changes prepared but not committed.');
			return;
		}

		run('svn', ['commit', svnDir, '-m', message]);
	} finally {
		fs.rmSync(tempDistDir, { recursive: true, force: true });
	}
}

function buildDistribution() {
	const tempDistDir = fs.mkdtempSync(path.join(os.tmpdir(), 'thumbnail-remover-release-'));
	fs.mkdirSync(tempDistDir, { recursive: true });
	run('rsync', ['-a', './', `${tempDistDir}/`, '--exclude-from=.distignore'], rootDir);
	return tempDistDir;
}

function syncDistributionToSvn(tempDistDir) {
	run(
		'rsync',
		['-a', '--delete', '--exclude', '.svn/', `${tempDistDir}/`, `${trunkDir}/`],
		rootDir
	);
}

function applyManagedAssets() {
	for (const asset of managedAssetCopies) {
		if (!fs.existsSync(asset.source)) {
			continue;
		}

		fs.mkdirSync(path.dirname(asset.target), { recursive: true });
		fs.copyFileSync(asset.source, asset.target);
	}
}

function finalizeSvnVersionTag(version) {
	const targetTagDir = path.join(tagsDir, version);
	fs.rmSync(targetTagDir, { recursive: true, force: true });
	fs.mkdirSync(targetTagDir, { recursive: true });
	run('rsync', ['-a', '--delete', '--exclude', '.svn/', `${trunkDir}/`, `${targetTagDir}/`], rootDir);
}

function stageSvnWorkingCopy() {
	const statusLines = getSvnStatusLines();
	for (const line of statusLines) {
		const status = line.slice(0, 1);
		const filePath = line.slice(8).trim();
		if (!filePath) {
			continue;
		}

		if (status === '?') {
			run('svn', ['add', '--force', filePath], rootDir);
		}

		if (status === '!') {
			run('svn', ['rm', '--force', filePath], rootDir);
		}
	}
}

function hasSvnChanges() {
	return getSvnStatusLines().length > 0;
}

function getSvnStatusLines() {
	const output = runCapture('svn', ['status', svnDir]);
	return output
		.split('\n')
		.map((line) => line.trimEnd())
		.filter(Boolean);
}

function commitAndPushRelease(version, customMessage) {
	assertCommandAvailable('git');
	const tagName = `v${version}`;

	run('git', ['add', '-A'], rootDir);

	const hasChangesToCommit = runCapture('git', ['status', '--porcelain'], rootDir).trim().length > 0;
	if (hasChangesToCommit) {
		run('git', ['commit', '-m', customMessage || `chore(release): v${version}`], rootDir);
	} else {
		console.log('No Git changes detected. Skipping release commit.');
	}

	const tagExists = runSilently('git', ['rev-parse', '--verify', tagName], rootDir);
	if (!tagExists) {
		run('git', ['tag', '-a', tagName, '-m', `Release ${tagName}`], rootDir);
	}

	run('git', ['push', 'origin', 'main', '--follow-tags'], rootDir, {
		env: { ...process.env, SKIP_WPORG_PUSH_GUARD: '1' },
	});
}

function assertGitBranchIsMain() {
	const currentBranch = runCapture('git', ['branch', '--show-current'], rootDir).trim();
	if (currentBranch !== 'main') {
		throw new Error(`Release shipping only runs from main. Current branch: ${currentBranch || '(detached)'}`);
	}
}

function verifySvnCheckout() {
	if (!fs.existsSync(path.join(svnDir, '.svn'))) {
		throw new Error(`Missing SVN checkout at ${svnDir}.`);
	}
}

function defaultSvnMessage() {
	return `Release ${readVersionMetadata().pluginVersion}`;
}

function generateReleaseNotes() {
	const { pluginVersion } = readVersionMetadata();
	const currentTag = `v${pluginVersion}`;
	const previousTag = getPreviousReleaseTag(currentTag);
	const commitRange = previousTag ? `${previousTag}..HEAD` : 'HEAD';
	const commits = getCommitsForRange(commitRange);
	const groups = groupCommits(commits);
	const lines = [`## What's Changed`, ''];

	if (previousTag) {
		lines.push(`Based on commits since \`${previousTag}\`.`, '');
	}

	const orderedGroups = [
		['Features', groups.features],
		['Fixes', groups.fixes],
		['Maintenance', groups.maintenance],
		['Docs', groups.docs],
		['Other', groups.other],
	];

	let hasEntries = false;
	for (const [title, entries] of orderedGroups) {
		if (!entries.length) {
			continue;
		}

		hasEntries = true;
		lines.push(`### ${title}`);
		for (const entry of entries) {
			lines.push(`- ${entry.summary} (${entry.shortHash})`);
		}
		lines.push('');
	}

	if (!hasEntries) {
		lines.push('- Maintenance release.', '');
	}

	lines.push('## Package', '', '- Built automatically from the `main` branch.', '');
	return `${lines.join('\n').trim()}\n`;
}

function getPreviousReleaseTag(currentTag) {
	const output = runCapture('git', ['tag', '--list', 'v*', '--sort=-version:refname'], rootDir).trim();
	if (!output) {
		return null;
	}

	const tags = output
		.split('\n')
		.map((tag) => tag.trim())
		.filter(Boolean)
		.filter((tag) => tag !== currentTag);

	return tags[0] || null;
}

function getCommitsForRange(range) {
	const separator = '--TRPL-COMMIT--';
	const output = runCapture(
		'git',
		['log', '--no-merges', '--pretty=format:%H%x1f%s%x1e', range],
		rootDir
	).trim();

	if (!output) {
		return [];
	}

	return output
		.split('\x1e')
		.map((entry) => entry.trim())
		.filter(Boolean)
		.map((entry) => {
			const [hash = '', subject = ''] = entry.split('\x1f');
			return {
				hash: hash.trim(),
				shortHash: hash.trim().slice(0, 7),
				subject: subject.trim(),
			};
		})
		.filter((entry) => entry.subject && !/^chore\(release\):/i.test(entry.subject));
}

function groupCommits(commits) {
	const groups = {
		features: [],
		fixes: [],
		maintenance: [],
		docs: [],
		other: [],
	};

	for (const commit of commits) {
		const parsed = parseCommitSubject(commit.subject);
		const entry = { ...commit, summary: parsed.summary };

		switch (parsed.type) {
			case 'feat':
				groups.features.push(entry);
				break;
			case 'fix':
				groups.fixes.push(entry);
				break;
			case 'docs':
				groups.docs.push(entry);
				break;
			case 'build':
			case 'ci':
			case 'chore':
			case 'perf':
			case 'refactor':
			case 'style':
			case 'test':
				groups.maintenance.push(entry);
				break;
			default:
				groups.other.push(entry);
				break;
		}
	}

	return groups;
}

function parseCommitSubject(subject) {
	const match = subject.match(/^([a-z]+)(?:\([^)]+\))?!?:\s*(.+)$/i);
	if (!match) {
		return {
			type: 'other',
			summary: subject,
		};
	}

	return {
		type: match[1].toLowerCase(),
		summary: match[2].trim(),
	};
}

function assertCommandAvailable(command) {
	if (!runSilently('which', [command], rootDir)) {
		throw new Error(`Required command "${command}" is not available on this system.`);
	}
}

function extractMatch(contents, pattern, label) {
	const match = contents.match(pattern);
	if (!match || !match[1]) {
		throw new Error(`Could not find ${label}.`);
	}

	return match[1].trim();
}

function writeFileWithCheck(filePath, nextContents) {
	const normalized = nextContents.replace(/\r\n/g, '\n');
	fs.writeFileSync(filePath, normalized);
}

function run(command, args, cwd = rootDir, options = {}) {
	execFileSync(command, args, {
		cwd,
		stdio: 'inherit',
		env: { ...process.env, ...(options.env || {}) },
	});
}

function runCapture(command, args, cwd = rootDir) {
	return execFileSync(command, args, {
		cwd,
		encoding: 'utf8',
		stdio: ['ignore', 'pipe', 'pipe'],
	});
}

function runSilently(command, args, cwd = rootDir) {
	try {
		execFileSync(command, args, {
			cwd,
			stdio: 'ignore',
		});
		return true;
	} catch {
		return false;
	}
}

function hasFlag(values, flag) {
	return values.includes(flag);
}

function stripFlags(values) {
	return values.filter((value) => !value.startsWith('--'));
}

function firstNonFlag(values) {
	return stripFlags(values)[0];
}
