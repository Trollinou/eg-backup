const fs = require( 'fs' );
const path = require( 'path' );
const { execSync } = require( 'child_process' );
const AdmZip = require( 'adm-zip' );
const { Minimatch } = require( 'minimatch' );

const rootDir = path.resolve( __dirname, '..' );
const mainFile = path.join( rootDir, 'eg-backup.php' );
const distIgnorePath = path.join( rootDir, '.distignore' );
const buildDir = path.join( rootDir, 'dist-temp' );
const pluginSlug = 'eg-backup';

console.log( '🔍 Extraction de la version du plugin...' );
if ( ! fs.existsSync( mainFile ) ) {
	console.error( `❌ Erreur : Fichier principal ${ mainFile } introuvable.` );
	process.exit( 1 );
}

const mainContent = fs.readFileSync( mainFile, 'utf8' );
const versionMatch = mainContent.match( /Version:\s*([^\s\r\n]+)/ );
if ( ! versionMatch ) {
	console.error(
		`❌ Erreur : Impossible de trouver la version dans ${ mainFile }`
	);
	process.exit( 1 );
}
const version = versionMatch[ 1 ].trim();
console.log( `ℹ️  Version détectée : ${ version }` );

const zipName = `${ pluginSlug }-v${ version }.zip`;
const tempDestDir = path.join( buildDir, pluginSlug );

// Compilation optionnelle des assets locaux (Production)
const packageJsonPath = path.join( rootDir, 'package.json' );
let hasBuildScript = false;
if ( fs.existsSync( packageJsonPath ) ) {
	try {
		const pkg = JSON.parse( fs.readFileSync( packageJsonPath, 'utf8' ) );
		if ( pkg.scripts && pkg.scripts.build ) {
			hasBuildScript = true;
		}
	} catch ( e ) {
		console.warn( '⚠️  Impossible de lire ou parser package.json.' );
	}
}

if ( hasBuildScript ) {
	console.log( '🏗️  Compilation des assets locaux (Production)...' );
	try {
		execSync( 'npm run build', { cwd: rootDir, stdio: 'inherit' } );
	} catch ( error ) {
		console.error( '❌ Erreur : Le build a échoué. Packaging annulé.' );
		process.exit( 1 );
	}
} else {
	console.log(
		'ℹ️  Aucun script de build détecté dans package.json, étape ignorée.'
	);
}

// Nettoyage préalable
if ( fs.existsSync( path.join( rootDir, zipName ) ) ) {
	fs.unlinkSync( path.join( rootDir, zipName ) );
}
if ( fs.existsSync( buildDir ) ) {
	fs.rmSync( buildDir, { recursive: true, force: true } );
}
fs.mkdirSync( tempDestDir, { recursive: true } );

// Chargement des règles .distignore
const ignoreRules = [];
if ( fs.existsSync( distIgnorePath ) ) {
	fs.readFileSync( distIgnorePath, 'utf8' )
		.split( /\r?\n/ )
		.map( ( line ) => line.trim() )
		.filter( ( line ) => line && ! line.startsWith( '#' ) )
		.forEach( ( pattern ) => {
			let matchPath = pattern;
			let isRootRelative = false;
			if ( pattern.startsWith( '/' ) ) {
				matchPath = pattern.slice( 1 );
				isRootRelative = true;
			}

			// Si le motif se termine par '/', c'est un dossier.
			// On exclut le dossier lui-même et tout son contenu
			if ( matchPath.endsWith( '/' ) ) {
				const dirPath = matchPath.slice( 0, -1 );
				ignoreRules.push( {
					mm: new Minimatch( dirPath, {
						dot: true,
						matchBase: ! isRootRelative,
						nocomment: true,
					} ),
				} );
				ignoreRules.push( {
					mm: new Minimatch( dirPath + '/**', {
						dot: true,
						matchBase: ! isRootRelative,
						nocomment: true,
					} ),
				} );
			} else {
				ignoreRules.push( {
					mm: new Minimatch( matchPath, {
						dot: true,
						matchBase: ! isRootRelative,
						nocomment: true,
					} ),
				} );
				ignoreRules.push( {
					mm: new Minimatch( matchPath + '/**', {
						dot: true,
						matchBase: ! isRootRelative,
						nocomment: true,
					} ),
				} );
			}
		} );
}

function isIgnored( relPath ) {
	const normalizedPath = relPath.replace( /\\/g, '/' );
	if ( ! normalizedPath ) {
		return false;
	}

	// Règles d'exclusion strictes de package.sh + le dossier script/ lui-même
	if (
		normalizedPath === 'dist-temp' ||
		normalizedPath.startsWith( 'dist-temp/' )
	) {
		return true;
	}
	if ( normalizedPath.endsWith( '.sh' ) ) {
		return true;
	}
	if ( normalizedPath.endsWith( '.zip' ) ) {
		return true;
	}
	if (
		normalizedPath === 'vendor' ||
		normalizedPath.startsWith( 'vendor/' )
	) {
		return true;
	}
	if (
		normalizedPath === 'scripts' ||
		normalizedPath.startsWith( 'scripts/' )
	) {
		return true;
	}

	for ( const rule of ignoreRules ) {
		if ( rule.mm.match( normalizedPath ) ) {
			return true;
		}
	}
	return false;
}

function copyFiltered( src, dest ) {
	if ( ! fs.existsSync( src ) ) {
		return;
	}
	const stats = fs.statSync( src );
	const relPath = path.relative( rootDir, src );

	if ( isIgnored( relPath ) ) {
		return;
	}

	if ( stats.isDirectory() ) {
		fs.mkdirSync( dest, { recursive: true } );
		const entries = fs.readdirSync( src );
		for ( const entry of entries ) {
			copyFiltered( path.join( src, entry ), path.join( dest, entry ) );
		}
	} else {
		const destDir = path.dirname( dest );
		if ( ! fs.existsSync( destDir ) ) {
			fs.mkdirSync( destDir, { recursive: true } );
		}
		fs.copyFileSync( src, dest );
	}
}

console.log(
	'📦 Préparation du répertoire temporaire et copie des fichiers...'
);
const rootEntries = fs.readdirSync( rootDir );
for ( const entry of rootEntries ) {
	copyFiltered(
		path.join( rootDir, entry ),
		path.join( tempDestDir, entry )
	);
}

// On s'assure d'avoir composer.json et composer.lock pour l'installation isolée si présent
const compJson = path.join( rootDir, 'composer.json' );
const compLock = path.join( rootDir, 'composer.lock' );
if ( fs.existsSync( compJson ) ) {
	fs.copyFileSync( compJson, path.join( tempDestDir, 'composer.json' ) );
	if ( fs.existsSync( compLock ) ) {
		fs.copyFileSync( compLock, path.join( tempDestDir, 'composer.lock' ) );
	}

	// Fonction de résolution intelligente de PHP/Composer (notamment pour l'environnement LocalWP)
	function resolveComposerCommand() {
		// 1. Essai de Composer global
		try {
			execSync( 'composer --version', { stdio: 'ignore' } );
			return { cmd: 'composer', options: { shell: true } };
		} catch ( e ) {
			// Non trouvé, on cherche LocalWP
		}

		// 2. Recherche spécifique pour l'application "Local" sur Windows
		if ( process.platform === 'win32' ) {
			const userProfile =
				process.env.USERPROFILE || process.env.HOMEPATH || '';
			const appData =
				process.env.APPDATA ||
				path.join( userProfile, 'AppData/Roaming' );
			const localAppData =
				process.env.LOCALAPPDATA ||
				path.join( userProfile, 'AppData/Local' );

			const localComposerPhar = path.join(
				localAppData,
				'Programs/Local/resources/extraResources/bin/composer/composer.phar'
			);
			const lightningServicesDir = path.join(
				appData,
				'Local/lightning-services'
			);
			let phpExePath = null;
			let phpExtPath = null;

			if ( fs.existsSync( lightningServicesDir ) ) {
				const dirs = fs.readdirSync( lightningServicesDir );
				const phpDirs = dirs
					.filter( ( d ) => d.startsWith( 'php-' ) )
					.sort()
					.reverse();
				const php84Dir = phpDirs.find( ( d ) =>
					d.startsWith( 'php-8.4' )
				);
				const chosenPhpDir = php84Dir || phpDirs[ 0 ];

				if ( chosenPhpDir ) {
					const testPath = path.join(
						lightningServicesDir,
						chosenPhpDir,
						'bin/win64/php.exe'
					);
					const extPath = path.join(
						lightningServicesDir,
						chosenPhpDir,
						'bin/win64/ext'
					);
					if ( fs.existsSync( testPath ) ) {
						phpExePath = testPath;
						if ( fs.existsSync( extPath ) ) {
							phpExtPath = extPath;
						}
					}
				}
			}

			if ( fs.existsSync( localComposerPhar ) && phpExePath ) {
				console.log( `💡 Environnement LocalWP détecté !` );
				console.log( `   - PHP : ${ phpExePath }` );
				console.log( `   - Composer : ${ localComposerPhar }` );

				let cliArgs = '';
				if ( phpExtPath ) {
					cliArgs = ` -d extension_dir="${ phpExtPath }" -d extension=openssl -d extension=curl -d extension=mbstring`;
				}

				return {
					cmd: `"${ phpExePath }"${ cliArgs } "${ localComposerPhar }"`,
					options: { shell: true },
				};
			}
		}

		// 3. Recherche spécifique pour l'application "Local" sur macOS
		if ( process.platform === 'darwin' ) {
			const homeDir = process.env.HOME || '';
			const localComposerPhar =
				'/Applications/Local.app/Contents/Resources/extraResources/bin/composer/composer.phar';
			const lightningServicesDir = path.join(
				homeDir,
				'Library/Application Support/Local/lightning-services'
			);
			let phpBinPath = null;

			if ( fs.existsSync( lightningServicesDir ) ) {
				const dirs = fs.readdirSync( lightningServicesDir );
				const phpDirs = dirs
					.filter( ( d ) => d.startsWith( 'php-' ) )
					.sort()
					.reverse();
				const php84Dir = phpDirs.find( ( d ) =>
					d.startsWith( 'php-8.4' )
				);
				const chosenPhpDir = php84Dir || phpDirs[ 0 ];

				if ( chosenPhpDir ) {
					const possiblePaths = [
						path.join(
							lightningServicesDir,
							chosenPhpDir,
							'bin/sbin/php'
						),
						path.join(
							lightningServicesDir,
							chosenPhpDir,
							'bin/bin/php'
						),
						path.join(
							lightningServicesDir,
							chosenPhpDir,
							'bin/php'
						),
					];
					for ( const p of possiblePaths ) {
						if ( fs.existsSync( p ) ) {
							phpBinPath = p;
							break;
						}
					}
				}
			}

			if ( fs.existsSync( localComposerPhar ) && phpBinPath ) {
				console.log( `💡 Environnement LocalWP détecté !` );
				console.log( `   - PHP : ${ phpBinPath }` );
				console.log( `   - Composer : ${ localComposerPhar }` );
				return {
					cmd: `"${ phpBinPath }" "${ localComposerPhar }"`,
					options: { shell: true },
				};
			}
		}

		return null;
	}

	console.log(
		'📦 Installation isolée des dépendances Composer (Production)...'
	);
	const resolver = resolveComposerCommand();

	if ( ! resolver ) {
		console.error(
			'\n❌ Erreur : Composer est introuvable sur le système.'
		);
		fs.rmSync( buildDir, { recursive: true, force: true } );
		process.exit( 1 );
	}

	try {
		execSync(
			`${ resolver.cmd } install --no-dev --optimize-autoloader --no-interaction --quiet --ignore-platform-reqs`,
			{
				cwd: tempDestDir,
				stdio: 'inherit',
				shell: resolver.options.shell,
			}
		);
	} catch ( error ) {
		console.error(
			"\n❌ Erreur : L'installation Composer a échoué dans le dossier temporaire."
		);
		fs.rmSync( buildDir, { recursive: true, force: true } );
		process.exit( 1 );
	}

	// Nettoyage de composer.json/lock dans la destination avant de zipper
	const destCompJson = path.join( tempDestDir, 'composer.json' );
	const destCompLock = path.join( tempDestDir, 'composer.lock' );
	if ( fs.existsSync( destCompJson ) ) {
		fs.unlinkSync( destCompJson );
	}
	if ( fs.existsSync( destCompLock ) ) {
		fs.unlinkSync( destCompLock );
	}
}

console.log( '🤐 Création du ZIP...' );
try {
	const zip = new AdmZip();
	zip.addLocalFolder( tempDestDir, pluginSlug );
	zip.writeZip( path.join( rootDir, zipName ) );
} catch ( error ) {
	console.error( '❌ Erreur lors de la création du ZIP :', error );
	fs.rmSync( buildDir, { recursive: true, force: true } );
	process.exit( 1 );
}

// Nettoyage final avec tentative de suppression progressive pour Windows (gestion des verrous)
console.log( '🧹 Nettoyage des fichiers temporaires...' );
let cleaned = false;
for ( let i = 0; i < 5; i++ ) {
	try {
		if ( fs.existsSync( buildDir ) ) {
			fs.rmSync( buildDir, { recursive: true, force: true } );
		}
		cleaned = true;
		break;
	} catch ( e ) {
		// Attendre 1 seconde avant de réessayer (1000ms)
		Atomics.wait(
			new Int32Array( new SharedArrayBuffer( 4 ) ),
			0,
			0,
			1000
		);
	}
}

if ( cleaned ) {
	console.log( `✅ Package créé avec succès : ${ zipName }` );
} else {
	console.warn(
		`⚠️  Package créé : ${ zipName }, mais le dossier temporaire "dist-temp" n'a pas pu être nettoyé automatiquement.`
	);
}
