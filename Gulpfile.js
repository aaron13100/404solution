// Require our dependencies
var autoprefixer = require('autoprefixer');
var cheerio = require('gulp-cheerio');
var concat = require('gulp-concat');
var cssnano = require('gulp-cssnano');
var del = require('del');
var gulp = require('gulp');
var gutil = require('gulp-util');
var imagemin = require('gulp-imagemin');
var mqpacker = require('css-mqpacker');
var notify = require('gulp-notify');
var plumber = require('gulp-plumber');
var postcss = require('gulp-postcss');
var rename = require('gulp-rename');
var sass = require('gulp-sass');
var sassLint = require('gulp-sass-lint');
var sort = require('gulp-sort');
var sourcemaps = require('gulp-sourcemaps');
var spritesmith = require('gulp.spritesmith');
var uglify = require('gulp-uglify');
var wpPot = require('gulp-wp-pot');

// Set assets paths.
var paths = {
	css: ['./*.css', '!*.min.css'],
	icons: 'images/svg-icons/*.svg',
	images: ['images/*', '!images/*.svg'],
	php: ['./*.php', './**/*.php'],
	sass: 'sass/**/*.scss',
	concat_scripts: 'js/concat/*.js',
	scripts: ['js/*.js', '!js/*.min.js', '!js/responsive-menu.js'],
	sprites: 'images/sprites/*.png'
};

/**
 * Handle errors and alert the user.
 */
function handleErrors () {
	var args = Array.prototype.slice.call(arguments);

	notify.onError({
		title: 'Task Failed [<%= error.message %>',
		message: 'See console.',
		sound: 'Sosumi' // See: https://github.com/mikaelbr/node-notifier#all-notification-options-with-their-defaults
	}).apply(this, args);

	gutil.beep(); // Beep 'sosumi' again

	// Prevent the 'watch' task from stopping
	this.emit('end');
}

/**
 * Delete style.css and style.min.css before we minify and optimize
 */
gulp.task('clean:styles', function() {
	return del(['style.css', 'style.min.css'])
});

/**
 * Compile Sass and run stylesheet through PostCSS.
 *
 * https://www.npmjs.com/package/gulp-sass
 * https://www.npmjs.com/package/gulp-postcss
 * https://www.npmjs.com/package/gulp-autoprefixer
 * https://www.npmjs.com/package/css-mqpacker
 */
gulp.task('postcss', ['clean:styles'], function() {
	return gulp.src('sass/*.scss', paths.css)

	// Deal with errors.
	.pipe(plumber({ errorHandler: handleErrors }))

	// Wrap tasks in a sourcemap.
	.pipe(sourcemaps.init())

		// Compile Sass using LibSass.
		.pipe(sass({
			includePaths: [].concat(bourbon, neat),
			errLogToConsole: true,
			outputStyle: 'expanded' // Options: nested, expanded, compact, compressed
		}))

		// Parse with PostCSS plugins.
		.pipe(postcss([
			autoprefixer({
				browsers: ['last 2 version']
			}),
			mqpacker({
				sort: true
			}),
		]))

	// Create sourcemap.
	.pipe(sourcemaps.write())

	// Create style.css.
	.pipe(gulp.dest('./'))
	.pipe(browserSync.stream());
});

/**
 * Minify and optimize style.css.
 *
 * https://www.npmjs.com/package/gulp-cssnano
 */
gulp.task('cssnano', ['postcss'], function() {
	return gulp.src('style.css')
	.pipe(plumber({ errorHandler: handleErrors }))
	.pipe(cssnano({
		safe: true // Use safe optimizations
	}))
	.pipe(rename('style.min.css'))
	.pipe(gulp.dest('./'))
	.pipe(browserSync.stream());
});

/**
 * Sass linting
 *
 * https://www.npmjs.com/package/sass-lint
 */
gulp.task('sass:lint', ['cssnano'], function() {
	gulp.src([
		'sass/**/*.scss',
		'!sass/defaults/_sprites.scss'
	])
	.pipe(sassLint())
	.pipe(sassLint.format())
	.pipe(sassLint.failOnError());
});

/**
 * Optimize images.
 *
 * https://www.npmjs.com/package/gulp-imagemin
 */
gulp.task('imagemin', function() {
	return gulp.src(paths.images)
	.pipe(plumber({ errorHandler: handleErrors }))
	.pipe(imagemin({
		optimizationLevel: 5,
		progressive: true,
		interlaced: true
	}))
	.pipe(gulp.dest('images'));
});

/**
 * Delete scripts before we concat and minify
 */
gulp.task('clean:scripts', function() {
	return del(['js/script.js', 'js/script.min.js']);
});

/**
 * Concatenate javascripts after they're clobbered.
 * https://www.npmjs.com/package/gulp-concat
 */
gulp.task('concat', ['clean:scripts'], function() {
	return gulp.src(paths.concat_scripts)
	.pipe(plumber({ errorHandler: handleErrors }))
	.pipe(sourcemaps.init())
	.pipe(concat('script.js'))
	.pipe(sourcemaps.write())
	.pipe(gulp.dest('js'))
});

 /**
  * Minify javascripts after they're concatenated.
  * https://www.npmjs.com/package/gulp-uglify
  */
gulp.task('uglify', ['concat'], function() {
	return gulp.src(paths.scripts)
	.pipe(rename({suffix: '.min'}))
	.pipe(uglify({
		mangle: false
	}))
	.pipe(gulp.dest('js'));
});

/**
 * Delete the theme's .pot before we create a new one
 */
gulp.task('clean:pot', function() {
	return del(['languages/404-solution.pot']);
});

/**
 * Scan the theme and create a POT file.
 *
 * https://www.npmjs.com/package/gulp-wp-pot
 */
gulp.task('wp-pot', ['clean:pot'], function() {
	return gulp.src(paths.php)
	.pipe(plumber({ errorHandler: handleErrors }))
	.pipe(sort())
	.pipe(wpPot({
		domain: '404-solution',
		destFile:'404-solution.pot',
		package: '404-solution',
		bugReport: 'https://github.com/aaron13100/404solution/issues/'
	}))
	.pipe(gulp.dest('languages/'));
});

/**
 * Convert the readme.txt to a README.MD file.
 *
 * https://github.com/ahoereth/gulp-readme-to-markdown
 */
var readme = require('gulp-readme-to-markdown');
gulp.task('readme', function() {
  gulp.src([ 'readme.txt' ])
  .pipe(readme({
    details: false,
    screenshot_ext: ['jpg', 'jpg', 'png'],
    extract: {
      'changelog': 'CHANGELOG'
    }
  }))
  .pipe(gulp.dest('.'));
});

/**
 * Create indivdual tasks.
 */
gulp.task('i18n', ['wp-pot']);
gulp.task('scripts', ['uglify']);
gulp.task('styles', ['cssnano']);
gulp.task('sprites', ['imagemin']);
gulp.task('default', ['i18n','icons', 'styles', 'scripts', 'sprites']);