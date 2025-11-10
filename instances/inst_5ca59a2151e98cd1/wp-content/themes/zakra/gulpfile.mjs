/**
 * Gulp workflow for Zakra.
 *
 * Contains the gulp commands to run repetitive tasks.
 */

import browserSync from 'browser-sync';
import { globbySync } from 'globby';
import gulp from 'gulp';
import autoprefixer from 'gulp-autoprefixer';
import eslint from 'gulp-eslint';
import imagemin from 'gulp-imagemin';
import lec from 'gulp-line-ending-corrector';
import notify from 'gulp-notify';
import phpcs from 'gulp-phpcs';
import rename from 'gulp-rename';
import rtlcss from 'gulp-rtlcss';
import gulpSass from 'gulp-sass';
import stylelint from 'gulp-stylelint';
import _uglify from 'gulp-uglify-es';
import uglifycss from 'gulp-uglifycss';
import wpPot from 'gulp-wp-pot';
import zip from 'gulp-zip';
import _sass from 'node-sass';
import webfontgenerator from 'webfonts-generator';

let uglify = _uglify.default;
let sass = gulpSass(_sass);

// Project information.
let info = {
	name: 'zakra',
	slug: 'zakra',
	url: 'https://zakratheme.com/',
	author: 'ThemeGrill',
	authorUrl: 'https://themegrill.com/',
	authorEmail: 'team@zakratheme.com',
	teamEmail: 'team@zakratheme.com',
	localUrl: 'tg.io/zakra',
};

// Defines paths.
let paths = {
	scss: {
		src: ['./assets/sass/**/*.scss'],
		dest: './',
	},

	adminscss: {
		src: ['./inc/admin/sass/*.scss'],
		dest: './inc/admin/css/',
	},

	gbscss: {
		src: ['./assets/sass/style-editor-block.scss'],
		dest: './',
	},

	iconfont: {
		svg: getFileUrls('assets/svg/*.svg'),
		font: './assets/fonts',
		scss: './assets/sass/elements/_icons.scss',
		scssTemp: './automate/webfont-scss.hbs',
		cssFontPath: 'assets/fonts',
	},

	controls: {
		scss: {
			src: './inc/customizer/controls/scss/**/*.scss',
			dest: './inc/customizer/controls/css',
		},
	},

	metabox: {
		scss: {
			src: './inc/meta-boxes/assets/sass/**/*.scss',
			dest: './inc/meta-boxes/assets/css',
		},
	},

	css: {
		src: ['./assets/css/*.css', '!./assets/css/*.min.css'],
		dest: './assets/css',
	},

	rtlcss: {
		src: ['./style.css'],
		dest: './',
	},

	prefixStyles: {
		src: './*.css',
		dest: './',
	},

	lintFiles: {
		php: [
			'./*.php',
			'./inc/**/*.php',
			'!./inc/**/webfonts.php',
			'./template-parts/**/*.php',
		],
		styles: ['./assets/sass/**/*.scss'],
		js: ['./assets/js/*-custom.js', '!./assets/js/*.min.js'],
	},

	js: {
		src: [
			'./assets/js/*.js',
			'!./assets/js/*.min.js',
			'!./assets/js/build/**',
			'!./assets/js/meta/**',
		],
		dest: './assets/js/',
	},

	zakraCustomizePreviewJS: {
		src: [
			'./inc/customizer/assets/js/zakra-customize-preview.js',
			'!./inc/customizer/assets/js/zakra-customize-preview.min.js',
		],
		dest: './inc/customizer/assets/js/',
	},

	customizePreviewJS: {
		src: [
			'./inc/customizer/core/assets/js/customize-preview.js',
			'!./inc/customizer/core/assets/js/customize-preview.min.js',
		],
		dest: './inc/customizer/core/assets/js/',
	},

	php: {
		src: ['./*.php', './inc/**/*.php'],
	},

	img: {
		src: ['./assets/img/**'],
		dest: './assets/img',
	},

	zip: {
		src: [
			'**',
			'!vendor',
			'!vendor/**',
			'!node_modules',
			'!node_modules/**',
			'!assets/sass',
			'!inc/admin/sass',
			'!inc/admin/sass/**',
			'!inc/meta-boxes/assets/sass',
			'!inc/meta-boxes/assets/sass/**',
			'!assets/sass/**',
			'!automate',
			'!automate/**',
			'!dest.xml',
			'!dist',
			'!dist/**',
			'!package.json',
			'!package-lock.json',
			'!composer.json',
			'!*.md',
			'!gulpfile.js',
			'!composer.lock',
			'!phpcs.xml',
			'!.gitlab',
			'!.gitlab/**',
		],
		dest: './dist',
	},

	rename: {
		src: [
			'./languages/zakra.pot',
			'./assets/js/zakra-custom.js',
			'./inc/class-zakra-css-classes.php',
			'./inc/customizer/class-zakra-customizer.php',
			'./inc/customizer/class-zakra-customizer-partials.php',
			'./inc/customizer/class-zakra-customizer-sanitize.php',
			'./inc/customizer/controls/php/class-zakra-customize-background-control.php',
			'./inc/customizer/controls/php/class-zakra-customize-base-control.php',
			'./inc/customizer/controls/php/class-zakra-customize-color-control.php',
			'./inc/customizer/controls/php/class-zakra-customize-dimensions-control.php',
			'./inc/customizer/controls/php/class-zakra-customize-editor-control.php',
			'./inc/customizer/controls/php/class-zakra-customize-radio-buttonset-control.php',
			'./inc/customizer/controls/php/class-zakra-customize-radio-image-control.php',
			'./inc/customizer/controls/php/class-zakra-customize-slider-control.php',
			'./inc/customizer/controls/php/class-zakra-customize-sortable-control.php',
			'./inc/customizer/controls/php/class-zakra-customize-text-control.php',
			'./inc/customizer/controls/php/class-zakra-customize-toggle-control.php',
			'./inc/customizer/controls/php/class-zakra-customize-typography-control.php',
			'./inc/customizer/controls/php/class-zakra-fonts.php',
			'./inc/customizer/extend-customizer/class-zakra-customize-panel.php',
			'./inc/customizer/extend-customizer/class-zakra-customize-section.php',
		],
	},

	theme: {
		src: [
			'**',
			'!gulpfile.js',
			'!vendor',
			'!vendor/**',
			'!node_modules',
			'!node_modules/**',
			'!dest.xml',
			'!dist',
			'!dist/**',
			'!package-lock.json',
		],
	},
};

/**
 * Gulp Series Tasks.
 */

//  Style tasks.
let styles = gulp.series(compileSass, prefixStyles);

// Start a front-end development server.
let server = gulp.series(browserSyncStart, watch);

// Test code.
let test = gulp.series(lintPHP, lintJS, lintStyle);

// Build.
let build = gulp.series(
	styles,
	generateRTLCSS,

	// Minify scripts,
	minifyCSS,
	minifyJs,
	minifyZakraCustomizePreviewJs,

	// Create zip,
	generatePotFile,
	compressZip,
);

/**
 * Function definitions.
 */

// BrowserSync.
function browserSyncStart(cb) {
	browserSync.init(
		{
			proxy: info.localUrl,
		},
		cb,
	);
}

// Reload the browser.
function browserSyncReload(cb) {
	browserSync.reload();
	cb();
}

// Compile SCSS into CSS.
function compileSass() {
	return gulp
		.src(paths.scss.src)
		.pipe(
			sass({
				indentType: 'tab',
				indentWidth: 1,
				outputStyle: 'expanded',
			}),
		)
		.pipe(lec({ verbose: true, eolc: 'LF', encoding: 'utf8' }))
		.pipe(gulp.dest(paths.scss.dest))
		.pipe(browserSync.stream())
		.on('error', notify.onError());
}

// Compile admin SCSS into CSS.
function compileAdminSass() {
	return gulp
		.src(paths.adminscss.src)
		.pipe(
			sass({
				indentType: 'tab',
				indentWidth: 1,
				outputStyle: 'expanded',
			}),
		)
		.pipe(lec({ verbose: true, eolc: 'LF', encoding: 'utf8' }))
		.pipe(gulp.dest(paths.adminscss.dest))
		.pipe(browserSync.stream())
		.on('error', notify.onError());
}

// Compile customizer controls SCSS into CSS.
function compileControlSass() {
	return gulp
		.src(paths.controls.scss.src)
		.pipe(
			sass({
				indentType: 'tab',
				indentWidth: 1,
				outputStyle: 'expanded',
			}),
		)
		.pipe(
			autoprefixer({
				cascade: false,
			}),
		)
		.pipe(gulp.dest(paths.controls.scss.dest))
		.on('error', notify.onError());
}

// Compile meta box SCSS into CSS.
function compileMetaboxSass() {
	return gulp
		.src(paths.metabox.scss.src)
		.pipe(
			sass({
				indentType: 'tab',
				indentWidth: 1,
				outputStyle: 'expanded',
			}),
		)
		.pipe(
			autoprefixer({
				cascade: false,
			}),
		)
		.pipe(gulp.dest(paths.metabox.scss.dest))
		.pipe(browserSync.stream())
		.on('error', notify.onError());
}

// Compile Gutenberg SCSS into CSS.
function compilegbsass() {
	return gulp
		.src(paths.gbscss.src)
		.pipe(
			sass({
				indentType: 'tab',
				indentWidth: 1,
				outputStyle: 'expanded',
			}),
		)
		.pipe(lec({ verbose: true, eolc: 'LF', encoding: 'utf8' }))
		.pipe(gulp.dest(paths.gbscss.dest))
		.pipe(browserSync.stream())
		.on('error', notify.onError());
}

// Prefix CSS.
function prefixStyles() {
	return gulp
		.src(paths.prefixStyles.src)
		.pipe(
			autoprefixer({
				cascade: false,
			}),
		)
		.pipe(gulp.dest(paths.prefixStyles.dest))
		.on('error', notify.onError());
}

// Generate RTL CSS file.
function generateRTLCSS() {
	return gulp
		.src(paths.rtlcss.src)
		.pipe(rtlcss())
		.pipe(rename({ suffix: '-rtl' }))
		.pipe(gulp.dest(paths.rtlcss.dest))
		.pipe(browserSync.stream())
		.on('error', notify.onError());
}

// Minify css file.
function minifyCSS() {
	return gulp
		.src(paths.css.src)
		.pipe(uglifycss())
		.pipe(rename({ suffix: '.min' }))
		.pipe(gulp.dest(paths.css.dest));
}

// Lint php through phpcs and PHPCompatibility.
function lintPHP() {
	return gulp
		.src(paths.lintFiles.php)
		.pipe(
			phpcs({
				bin: 'vendor/bin/phpcs',
				standard: 'phpcs.xml',
				warningSeverity: 0,
			}),
		)
		.pipe(phpcs.reporter('log'))
		.pipe(phpcs.reporter('fail', { failOnFirst: false }))
		.on('error', notify.onError());
}

// Lint scss,css file through stylelint.
function lintStyle() {
	return gulp
		.src(paths.lintFiles.styles)
		.pipe(
			stylelint({
				failAfterError: true,
				reporters: [{ formatter: 'string', console: true }],
			}),
		)
		.on('error', notify.onError());
}

// Lint js files through eslint.
function lintJS() {
	return gulp
		.src(paths.lintFiles.js)
		.pipe(eslint())
		.pipe(eslint.format())
		.on('error', notify.onError());
}

// Minify image files.
function minifyImg() {
	return gulp
		.src(paths.img.src)
		.pipe(imagemin())
		.pipe(gulp.dest(paths.img.dest))
		.on('error', notify.onError());
}

// Minify zakra customizer preview js.
function minifyZakraCustomizePreviewJs() {
	return gulp
		.src(paths.zakraCustomizePreviewJS.src)
		.pipe(uglify())
		.pipe(rename({ suffix: '.min' }))
		.pipe(gulp.dest(paths.zakraCustomizePreviewJS.dest))
		.on('error', notify.onError());
}

// Minify the js files.
function minifyJs() {
	return gulp
		.src(paths.js.src)
		.pipe(uglify())
		.pipe(rename({ suffix: '.min' }))
		.pipe(gulp.dest(paths.js.dest))
		.on('error', notify.onError());
}

// Generate iconfont from svgs.
function generateWebfonts(cb) {
	webfontgenerator(
		{
			fontName: 'themegrill-icons',
			files: paths.iconfont.svg,
			dest: paths.iconfont.font,
			cssDest: paths.iconfont.scss,
			cssTemplate: paths.iconfont.scssTemp,
			cssFontsUrl: paths.iconfont.cssFontPath,
		},
		cb,
	);
}

// Generate translation file.
function generatePotFile() {
	return gulp
		.src(paths.php.src)
		.pipe(
			wpPot({
				domain: info.slug,
				package: info.name,
				bugReport: info.authorEmail,
				team: info.teamEmail,
			}),
		)
		.pipe(gulp.dest('languages/' + info.slug + '.pot'))
		.on('error', notify.onError());
}

// Compress theme into a zip file.
function compressZip() {
	return gulp
		.src(paths.zip.src, {
			encoding: false
		})
		.pipe(
			rename(function (path) {
				path.dirname = info.slug + '/' + path.dirname;
			}),
		)
		.pipe(zip(info.slug + '.zip'))
		.pipe(gulp.dest(paths.zip.dest))
		.on('error', notify.onError())
		.pipe(
			notify({
				message: 'Great! Package is ready',
				title: 'Build successful',
			}),
		);
}

// Get URLs of files.
function getFileUrls(path) {
	let out = [];

	out = out.concat(globbySync(path));

	return out;
}

// Watch for file changes.
function watch() {
	gulp.watch(paths.scss.src, styles);
	gulp.watch(paths.customizePreviewJS.src, minifyJs);
	gulp.watch(paths.zakraCustomizePreviewJS.src, minifyJs);
	gulp.watch(paths.adminscss.src, compileAdminSass);
	gulp.watch(paths.controls.scss.src, compileControlSass);
	gulp.watch(paths.metabox.scss.src, compileMetaboxSass);
	gulp.watch(paths.js.src, browserSyncReload);
	gulp.watch(paths.php.src, browserSyncReload);
}

/**
 * Define tasks.
 */
export {
	browserSyncReload,
	browserSyncStart,
	build,
	compileAdminSass,
	compileControlSass,
	compileMetaboxSass,
	compileSass,
	compilegbsass,
	compressZip,
	generatePotFile,
	generateRTLCSS,
	generateWebfonts,
	lintJS,
	lintPHP,
	lintStyle,
	minifyCSS,
	minifyImg,
	minifyJs,
	minifyZakraCustomizePreviewJs,
	prefixStyles,
	server,
	styles,
	test,
	watch,
};
