const fs = require( 'fs' );
const path = require( 'path' );
const esbuild = require( 'esbuild' );

const rootDir = path.resolve( __dirname, '..' );
const srcDir = path.join( rootDir, 'src/ts' );
const distDir = path.join( rootDir, 'assets/js' );

if ( ! fs.existsSync( distDir ) ) {
	fs.mkdirSync( distDir, { recursive: true } );
}

async function buildAll() {
	const startTime = Date.now();

	const entries = fs.existsSync( srcDir )
		? fs
				.readdirSync( srcDir, { withFileTypes: true } )
				.filter(
					( dirent ) =>
						dirent.isFile() &&
						( dirent.name.endsWith( '.ts' ) || dirent.name.endsWith( '.js' ) ) &&
						! dirent.name.endsWith( '.d.ts' )
				)
				.map( ( dirent ) => path.join( srcDir, dirent.name ) )
		: [];

	console.log( `⚡ Building ${ entries.length } standalone script(s) with esbuild...` );

	const promises = entries.map( async ( entry ) => {
		const filename = path.basename( entry ).replace( /\.(ts|js)$/, '.js' );
		const outfile = path.join( distDir, filename );

		await esbuild.build( {
			entryPoints: [ entry ],
			outfile,
			bundle: true,
			minify: true,
			target: 'es2022',
			sourcemap: false,
			platform: 'browser',
			format: 'iife',
		} );
		console.log( `  ✓ Built assets/js/${ filename }` );
	} );

	try {
		await Promise.all( promises );
		const elapsed = Date.now() - startTime;
		console.log( `✨ JS Build completed successfully in ${ elapsed }ms.` );
	} catch ( error ) {
		console.error( '✗ JS Build failed:', error.message );
		process.exit( 1 );
	}
}

buildAll();
