import gulp from 'gulp';
import jshint from 'gulp-jshint';
import stylish from 'jshint-stylish';
import uglify from 'gulp-uglify';
import csso from 'gulp-csso';
import sourcemaps from 'gulp-sourcemaps';
import rev from 'gulp-rev';
import revReplace from 'gulp-rev-replace';
import filter from 'gulp-filter';
import useref from 'gulp-useref';
import path from 'path';
import through from 'through2';

/* Variables and paths
------------------------------------- */

const paths = {
  build: 'public_html/',
  index: 'src/index.html',
  scripts: 'src/js/*.js',
};
const assetVersion = Date.now().toString(36);

/* Tasks
------------------------------------- */

// 'Lints all javascript files'
export function lint () {
  return gulp.src(paths.scripts)
    .pipe(jshint())
    .pipe(jshint.reporter(stylish))
    ;
}

//  'Builds the app'
export function build () {
  var jsFilter = filter('**/*.js', { restore: true });
  var cssFilter = filter('**/*.css', { restore: true });
  var notIndexHtmlFilter = filter(['**/*', '!**/index.html'], { restore: true });
  var manifest = {};

  return gulp.src(paths.index)
    .pipe(useref({ searchPath: '.' }))
    .pipe(jsFilter)
    .pipe(uglify())             // Minify any javascript sources
    .pipe(jsFilter.restore)
    .pipe(cssFilter)
    .pipe(csso())               // Minify any CSS sources
    .pipe(cssFilter.restore)
    .pipe(notIndexHtmlFilter)
    .pipe(rev())                // Rename the concatenated files (but not index.html)
    .pipe(through.obj(function(file, enc, cb) {
      manifest[path.basename(file.revOrigPath)] = file.relative.replace(/\\/g, '/');
      cb(null, file);
    }))
    .pipe(notIndexHtmlFilter.restore)
    .pipe(revReplace())         // Substitute in new filenames
    .pipe(through.obj(function(file, enc, cb) {
      if (path.basename(file.path) === 'index.html') {
        var html = file.contents.toString();
        if (manifest['vendor.css']) {
          html = html.replace(
            /<!-- build:css css\/vendor(?:-[a-f0-9]+)?\.css -->[\s\S]*?<!-- endbuild -->/,
            '<link rel="stylesheet" href="' + manifest['vendor.css'] + '?version=' + assetVersion + '">'
          );
        }
        if (manifest['vendor.js']) {
          html = html.replace(
            /<!-- build:js js\/vendor(?:-[a-f0-9]+)?\.js -->[\s\S]*?<!-- endbuild -->/,
            '<script src="' + manifest['vendor.js'] + '"></script>'
          );
        }
        if (manifest['app.js']) {
          html = html.replace(
            /<!-- build:js js\/app(?:-[a-f0-9]+)?\.js-->\s*[\s\S]*?<!-- endbuild -->/,
            '<script src="' + manifest['app.js'] + '"></script>'
          );
        }
        html = html.replace(
          /href="(css\/app-[^"]+\.css)"/,
          'href="$1?version=' + assetVersion + '"'
        );
        html = html.replace(
          /window\.CropToolAssetVersion = 'dev';/,
          "window.CropToolAssetVersion = '" + assetVersion + "';"
        );
        file.contents = Buffer.from(html);
      }
      cb(null, file);
    }))
    .pipe(gulp.dest(paths.build))
    ;
}

// 'Re-builds the app on changes'
export function watch () {
  gulp.watch(
    ['src/js/**/*.js', 'src/css/**/*.css', 'src/index.html'],
    { ignoreInitial: false },
    build
  );
}

/*
 * Export a default task
 */
export default build;

