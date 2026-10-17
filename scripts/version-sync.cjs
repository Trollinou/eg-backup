const fs = require( 'fs' );
const path = require( 'path' );

/**
 * Synchronizes the project version across all relevant files:
 * 1. eg-backup.php (Plugin Header & EG_BACKUP_VERSION constant)
 * 2. package.json ("version")
 * 3. src/blocks/**\/block.json ("version")
 * 4. CHANGELOG.md (Converts [Unreleased] to [x.y.z] - YYYY-MM-DD)
 */

const rootDir = process.cwd();

// Get version from CLI argument, or fallback to current package.json version
const packageJsonPath = path.join( rootDir, 'package.json' );
let newVersion = process.argv[ 2 ];

if ( ! newVersion ) {
	if ( fs.existsSync( packageJsonPath ) ) {
		const pkg = JSON.parse( fs.readFileSync( packageJsonPath, 'utf8' ) );
		newVersion = pkg.version;
	}
}

if ( ! newVersion || ! /^\d+\.\d+\.\d+.*$/.test( newVersion ) ) {
	console.error( '❌ Error: Please provide a valid semantic version (e.g. node script/version-sync.cjs 1.2.0)' );
	process.exit( 1 );
}

console.log( `🔄 Synchronizing project to version: ${ newVersion }` );

let updatedCount = 0;

// 1. Update eg-backup.php
const mainPhpPath = path.join( rootDir, 'eg-backup.php' );
if ( fs.existsSync( mainPhpPath ) ) {
	let content = fs.readFileSync( mainPhpPath, 'utf8' );
	content = content.replace( /(\*\s*Version:\s*)([^\r\n]+)/, `$1${ newVersion }` );
	content = content.replace( /(define\(\s*'EG_BACKUP_VERSION',\s*')([^']+)('\s*\);)/, `$1${ newVersion }$3` );
	fs.writeFileSync( mainPhpPath, content, 'utf8' );
	console.log( `  ✓ Updated eg-backup.php (Header & EG_BACKUP_VERSION)` );
	updatedCount++;
}

// 1b. Update tests/bootstrap.php
const testBootstrapPath = path.join( rootDir, 'tests', 'bootstrap.php' );
if ( fs.existsSync( testBootstrapPath ) ) {
	let testContent = fs.readFileSync( testBootstrapPath, 'utf8' );
	testContent = testContent.replace( /(define\(\s*'EG_BACKUP_VERSION',\s*')([^']+)('\s*\);)/, `$1${ newVersion }$3` );
	fs.writeFileSync( testBootstrapPath, testContent, 'utf8' );
	console.log( `  ✓ Updated tests/bootstrap.php (EG_BACKUP_VERSION)` );
	updatedCount++;
}

// 2. Update package.json
if ( fs.existsSync( packageJsonPath ) ) {
	const pkg = JSON.parse( fs.readFileSync( packageJsonPath, 'utf8' ) );
	pkg.version = newVersion;
	fs.writeFileSync( packageJsonPath, JSON.stringify( pkg, null, 2 ) + '\n', 'utf8' );
	console.log( `  ✓ Updated package.json` );
	updatedCount++;
}

// 3. Update all block.json files in src/
function updateBlockJsonFiles( dir ) {
	if ( ! fs.existsSync( dir ) ) {
		return;
	}
	const entries = fs.readdirSync( dir, { withFileTypes: true } );
	for ( const entry of entries ) {
		const fullPath = path.join( dir, entry.name );
		if ( entry.isDirectory() ) {
			updateBlockJsonFiles( fullPath );
		} else if ( entry.name === 'block.json' ) {
			try {
				const blockData = JSON.parse( fs.readFileSync( fullPath, 'utf8' ) );
				blockData.version = newVersion;
				fs.writeFileSync( fullPath, JSON.stringify( blockData, null, 2 ) + '\n', 'utf8' );
				const relative = path.relative( rootDir, fullPath );
				console.log( `  ✓ Updated ${ relative }` );
				updatedCount++;
			} catch ( err ) {
				console.warn( `  ⚠️ Could not parse ${ fullPath }:`, err.message );
			}
		}
	}
}

updateBlockJsonFiles( path.join( rootDir, 'src' ) );

// 4. Update CHANGELOG.md
const changelogPath = path.join( rootDir, 'CHANGELOG.md' );
if ( fs.existsSync( changelogPath ) ) {
	let changelog = fs.readFileSync( changelogPath, 'utf8' );
	const today = new Date().toISOString().split( 'T' )[ 0 ];
	const releaseHeader = `## [${ newVersion }] - ${ today }`;

	// Only convert [Unreleased] if this version does not already exist in CHANGELOG.md
	if ( ! changelog.includes( `## [${ newVersion }]` ) && ! changelog.includes( `## ${ newVersion }` ) ) {
		if ( /##\s*\[Unreleased\]/i.test( changelog ) ) {
			changelog = changelog.replace(
				/##\s*\[Unreleased\]/i,
				`## [Unreleased]\n\n${ releaseHeader }`
			);
			fs.writeFileSync( changelogPath, changelog, 'utf8' );
			console.log( `  ✓ Updated CHANGELOG.md (Converted [Unreleased] to ${ releaseHeader })` );
			updatedCount++;
		}
	}
}

console.log( `\n✅ Successfully synchronized ${ updatedCount } files to version ${ newVersion }!` );
