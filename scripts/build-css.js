const fs = require( 'fs' );
const path = require( 'path' );
const sass = require( 'sass' );
const rtlcss = require( 'rtlcss' );

const rootDir = path.resolve( __dirname, '..' );
const srcDir = path.join( rootDir, 'src/scss' );
const distDir = path.join( rootDir, 'assets/css' );

if ( ! fs.existsSync( distDir ) ) {
	fs.mkdirSync( distDir, { recursive: true } );
}

function buildCss() {
	const startTime = Date.now();

	const entries = fs.existsSync( srcDir )
		? fs
				.readdirSync( srcDir, { withFileTypes: true } )
				.filter(
					( dirent ) =>
						dirent.isFile() &&
						dirent.name.endsWith( '.scss' ) &&
						! dirent.name.startsWith( '_' )
				)
				.map( ( dirent ) => dirent.name )
		: [];

	console.log( `🎨 Compiling ${ entries.length } SCSS stylesheet(s) with Sass & RTL...` );

	for ( const file of entries ) {
		const srcFile = path.join( srcDir, file );
		const baseName = file.replace( /\.scss$/, '' );
		const outFile = path.join( distDir, `${ baseName }.css` );
		const outRtlFile = path.join( distDir, `${ baseName }-rtl.css` );

		const result = sass.compile( srcFile, {
			style: 'compressed',
			sourceMap: false,
		} );

		fs.writeFileSync( outFile, result.css );
		console.log( `  ✓ Built assets/css/${ baseName }.css` );

		// RTL generation
		const rtlResult = rtlcss.process( result.css );
		fs.writeFileSync( outRtlFile, rtlResult );
		console.log( `  ✓ Built assets/css/${ baseName }-rtl.css` );
	}

	const elapsed = Date.now() - startTime;
	console.log( `✨ CSS Build completed successfully in ${ elapsed }ms.` );
}

buildCss();
