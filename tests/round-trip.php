<?php
// Round-trip tests: converts each sample in tests/samples to the other format and back, then checks the result.
// Run from the converter's folder, before committing a change:  php tests/round-trip.php
// Every check should say "passed"; anything else lists what differs.
//
// The samples are small copies of public starter content (smaller images – the converter only copies them):
//   grav-guides    – the Grav Open Publishing Space skeleton's two guides (also tested renamed to guide.md and guide-list.md)
//   grav-extras    – a tiny guide with cases that once came back changed (Markdown-version links, banners, classes …)
//   01.cpt363-basic – a course in its own folder: the Grav Open Course Space skeleton's CPT363-1
//   03.cpt363-advanced – CPT363-3, organised by weekly units (only the pages that are converted)
//   docsify-guide  – the Docsify-This Guide Site template
//   docsify-course – the Docsify Open Course Starter Kit (its docs folder, without assets)
//   docsify-courses-site – the Docsify-This Courses Site Template: a Courses page and three course folders
// (a whole Open Course Space site of two courses is put together from these in test 5)
// To update a sample after a skeleton, template or kit changes on purpose, copy its new files over the old ones.

if (PHP_SAPI !== 'cli') {
    exit; // command line only – nothing runs if this file is ever opened in a browser
}

$samples = __DIR__ . '/samples';
$work = sys_get_temp_dir() . '/converter-round-trip-' . getmypid();
mkdir($work);

$failed = 0;

// 1. Grav guides → Docsify → Grav: the same files as the original. The Sample Guide is switched off in the skeleton
//    (and so isn't converted), so it's switched on in a copy here
copyFolder("$samples/grav-guides", "$work/guides");
switchOn("$work/guides/02.sample-guide/section-list.md");
convert('grav2docsify', "$work/guides", "$work/guides-docsify");
convert('docsify2grav', "$work/guides-docsify", "$work/guides-back");
$failed += report('Grav guides come back identical', sameFiles("$work/guides", "$work/guides-back"));

// 1a. A switched-off guide isn't converted (as on the Grav site's Guides page)
convert('grav2docsify', "$samples/grav-guides", "$work/guides-off-docsify");
$differences = [];
if (is_dir("$work/guides-off-docsify/sample-guide") || !is_dir("$work/guides-off-docsify/open-education-essentials")) {
    $differences[] = 'the switched-off Sample Guide was converted, or the other guide wasn\'t';
}
$failed += report('A switched-off guide is left out', $differences);

// 1b. A tiny guide with cases that once came back changed: the same files as the original
convert('grav2docsify', "$samples/grav-extras", "$work/extras-docsify", 'single');
convert('docsify2grav', "$work/extras-docsify", "$work/extras-back");
$failed += report('Grav test guide comes back identical', sameFiles("$samples/grav-extras", "$work/extras-back"));

// 1b2. In the Docsify version, a page in a folder links to other pages with a / (so the link also works on GitHub),
//      keeps its link to a page's Markdown version as it is, and lists that link in its hidden note
$page = (string)file_get_contents("$work/extras-docsify/links-and-images/README.md");
$differences = [];
if (!str_contains($page, "[the guide's home](/home.md)")) {
    $differences[] = 'the link to the guide home doesn\'t start with /';
}
if (!str_contains($page, '[as Markdown](/test-guide/links-and-images.md')) {
    $differences[] = 'the link to the page\'s Markdown version was changed';
}
if (!str_contains($page, '"kept":["/test-guide/links-and-images.md"]')) {
    $differences[] = 'the hidden note doesn\'t list the kept link';
}
// a page at the site's top keeps its links without a /
$guidesPage = (string)file_get_contents("$work/guides-docsify/home.md");
if (!str_contains($guidesPage, '](open-education-essentials/README.md)')) {
    $differences[] = 'a link on the Guides page starts with /';
}
$failed += report('Links from pages in folders start with /, and kept links stay as they are', $differences);

// 1b3. Glossary terms in the Docsify version: a link to the Glossary page with the definition as its title (Markdown
//      marks gone, code kept, " as &quot;); a term in other words keeps its name; a term not in the glossary stays text
$differences = [];
$markdownTerm = '[Markdown](/glossary.md?id=glossary-markdown "A simple text format for web pages – **bold**, ## Heading – with &quot;plain&quot; text you control.")';
if (!str_contains($page, $markdownTerm)) {
    $differences[] = 'the Markdown term isn\'t a glossary link with its definition';
}
if (!str_contains($page, '<!-- grav: [term name="OER"] -->[open resources](/glossary.md?id=glossary-oer "Open Educational Resources: free to use and openly licensed.")')) {
    $differences[] = 'the term in other words isn\'t a glossary link that keeps its name';
}
if (!str_contains($page, '<!-- grav: [term] -->a term not in the glossary<!-- grav: [/term] -->')) {
    $differences[] = 'the term not in the glossary was changed';
}
// each glossary entry has its anchor, which the terms' links open at
$glossaryPage = (string)file_get_contents("$work/extras-docsify/glossary.md");
if (!str_contains($glossaryPage, '<a id="glossary-markdown"></a>**Markdown**<br>')) {
    $differences[] = 'a glossary entry has no anchor';
}
$failed += report('Glossary terms link to the Glossary page, with their definitions', $differences);

// 1b4. The same guide moved without hidden notes (clean) and back: the Glossary page's list goes back into its
//      settings, its terms become [term] again, and a term in other words gets its name back from its definition
convert('grav2docsify', "$samples/grav-extras", "$work/extras-clean", 'single clean');
convert('docsify2grav', "$work/extras-clean", "$work/extras-clean-back");
$differences = [];
$glossaryFiles = glob("$work/extras-clean-back/*/glossary/default.md");
$glossaryPage = (string)file_get_contents($glossaryFiles[0] ?? '/dev/null');
if (!str_contains($glossaryPage, "glossary:\n") || !str_contains($glossaryPage, 'term: OER')) {
    $differences[] = 'the Glossary page has no glossary: settings';
}
if (!str_contains($glossaryPage, "\n[glossary]")) {
    $differences[] = 'the Glossary page has no [glossary]';
}
$pageFiles = glob("$work/extras-clean-back/*/*links-and-images/section.md");
$sectionPage = (string)file_get_contents($pageFiles[0] ?? '/dev/null');
foreach (['[term]Markdown[/term]', '[term name="OER"]open resources[/term]'] as $term) {
    if (!str_contains($sectionPage, $term)) {
        $differences[] = $term . ' didn\'t come back';
    }
}
$failed += report('A clean Glossary page and its terms come back to Grav', $differences);

// 1c. The same guides with the newer page names (guide.md for each guide, guide-list.md for the Guides page):
//     they come back with those names
copyFolder("$work/guides", "$work/renamed");
rename("$work/renamed/00.guides/reader-list.md", "$work/renamed/00.guides/guide-list.md");
rename("$work/renamed/01.open-education-essentials/section-list.md", "$work/renamed/01.open-education-essentials/guide.md");
rename("$work/renamed/02.sample-guide/section-list.md", "$work/renamed/02.sample-guide/guide.md");
convert('grav2docsify', "$work/renamed", "$work/renamed-docsify");
convert('docsify2grav', "$work/renamed-docsify", "$work/renamed-back");
$failed += report('Grav guides with the newer page names come back identical', sameFiles("$work/renamed", "$work/renamed-back"));

// 2. An Open Course Space course → Docsify → Grav: the same course folder, file for file (its Read Me and LMS page
//    are about the Grav site, so they aren't converted and don't come back)
convert('course2docsify', "$samples/01.cpt363-basic", "$work/course-docsify");
convert('course2grav', "$work/course-docsify", "$work/course-back");
copyFolder("$samples/01.cpt363-basic", "$work/course-expected");
removeFolder("$work/course-expected/20.readme");
removeFolder("$work/course-expected/lms-home");
$failed += report('Grav course comes back identical', sameFiles("$work/course-expected", "$work/course-back/01.cpt363-basic"));

// 3. Docsify guide → Grav → Docsify: the same text for readers (the converter's hidden notes aside)
convert('docsify2grav', "$samples/docsify-guide", "$work/guide-grav");
convert('grav2docsify', "$work/guide-grav", "$work/guide-back", 'single');
$failed += report('Docsify guide comes back with the same text', sameText("$samples/docsify-guide", "$work/guide-back"));

// 4. Docsify course → Grav, then Grav → Docsify → Grav: the second Grav version is the same as the first
convert('course2grav', "$samples/docsify-course", "$work/kit-grav");
convert('course2docsify', "$work/kit-grav/01.my-course", "$work/kit-docsify");
convert('course2grav', "$work/kit-docsify", "$work/kit-grav-again");
$failed += report('Docsify course stays the same after another round trip', sameFiles("$work/kit-grav", "$work/kit-grav-again"));

// 4b. Clean pages (the clean option): no hidden notes are left in any Docsify page
convert('grav2docsify', "$work/guides", "$work/guides-clean", 'clean');
convert('course2docsify', "$samples/01.cpt363-basic", "$work/course-clean", 'clean');
$leftNotes = [];
foreach (["$work/guides-clean", "$work/course-clean"] as $folder) {
    foreach (filesIn($folder) as $file) {
        if (str_ends_with($file, '.md') && preg_match('/<!-- \/?grav/', (string)file_get_contents("$folder/$file")) === 1) {
            $leftNotes[] = "$file still has a hidden note";
        }
        // (an empty page would make Docsify keep the last page's navbar or sidebar)
        if (str_ends_with($file, '.md') && filesize("$folder/$file") === 0) {
            $leftNotes[] = "$file is empty";
        }
    }
}
$failed += report('Clean Docsify pages have no hidden notes', $leftNotes);

// 4c. A clean course moved back to Grav keeps its name, its modules and its home page's own pages (rebuilt from the
// sidebar, the navbar's site title and the home page's cards)
convert('course2grav', "$work/course-clean", "$work/course-clean-back");
$differences = [];
$originalCourse = (string)file_get_contents("$samples/01.cpt363-basic/course.md");
$backCourse = glob("$work/course-clean-back/*/course.md")[0] ?? '';
preg_match("/^title: (.*)$/m", $originalCourse, $originalTitle);
if ($backCourse === '' || !str_contains((string)file_get_contents($backCourse), $originalTitle[1] ?? '-')) {
    $differences[] = 'the course name didn\'t come back';
}
$countPages = fn(string $pattern) => count(glob($pattern));
if ($backCourse !== '') {
    $backFolder = dirname($backCourse);
    if ($countPages("$samples/01.cpt363-basic/02.modules/*/course-page.md") !== $countPages("$backFolder/02.modules/*/course-page.md")) {
        $differences[] = 'not every module came back';
    }
    if ($countPages("$samples/01.cpt363-basic/01.home/[0-9]*.[a-z]*/course-page.md") !== $countPages("$backFolder/01.home/[0-9]*.[a-z]*/course-page.md")) {
        $differences[] = 'not every page of the home page came back';
    }
}
$failed += report('A clean course moved back keeps its name, modules and home pages', $differences);

// 5. A whole Open Course Space site → Docsify → Grav: its Courses page and both courses, file for file (the course
// from test 2, and the kit's course from test 4)
mkdir("$work/site/00.courses", 0700, true);
file_put_contents("$work/site/00.courses/course-list.md", "---\ntitle: Courses\nvisible: false\n---\n\nTwo **example** courses.\n");
copyFolder("$work/course-expected", "$work/site/01.cpt363-basic");
copyFolder("$work/kit-grav/01.my-course", "$work/site/02.my-course");
convert('courses2docsify', "$work/site", "$work/site-docsify");
convert('courses2grav', "$work/site-docsify", "$work/site-back");
$failed += report('Open Course Space site comes back identical', sameFiles("$work/site", "$work/site-back"));

// 6. A course organised by weekly units → Docsify → Grav: the same course folder, file for file
convert('course2docsify', "$samples/03.cpt363-advanced", "$work/weeks-docsify");
convert('course2grav', "$work/weeks-docsify", "$work/weeks-back");
$failed += report('Grav course of weekly units comes back identical', sameFiles("$samples/03.cpt363-advanced", "$work/weeks-back/03.cpt363-advanced"));

// 7. A site of several courses made in Docsify (the Docsify-This Courses Site Template, without the converter's
//    notes) → Grav: its Courses page and all three courses; then Grav → Docsify → Grav gives the same Grav files
convert('courses2grav', "$samples/docsify-courses-site", "$work/courses-grav");
$missing = [];
foreach (['00.courses/course-list.md', '01.cpt363-basic/course.md', '02.cpt363-intermediate/course.md', '03.cpt363-advanced/course.md', '01.cpt363-basic/02.modules/05.module-04/course-page.md', '03.cpt363-advanced/02.weekly-units/blog.md', '03.cpt363-advanced/02.weekly-units/week-04/item.md'] as $file) {
    if (!is_file("$work/courses-grav/$file")) {
        $missing[] = "missing: $file";
    }
}
$failed += report('Docsify site of several courses converts to Open Course Space', $missing);
convert('courses2docsify', "$work/courses-grav", "$work/courses-docsify");
convert('courses2grav', "$work/courses-docsify", "$work/courses-grav-again");
$failed += report('Docsify site of several courses stays the same after another round trip', sameFiles("$work/courses-grav", "$work/courses-grav-again"));

removeFolder($work);
if ($failed === 0) {
    echo "\nAll checks passed.\n";
    exit(0);
}
echo "\n$failed check(s) failed.\n";
exit(1);

// ---- helpers ----

// Turns on a page's Published setting (published: false → published: true)
function switchOn(string $file): void
{
    $text = (string)file_get_contents($file);
    file_put_contents($file, str_replace("\npublished: false\n", "\npublished: true\n", $text));
}

// Runs one conversion with the converter's own command-line tool
function convert(string $direction, string $from, string $to, string $option = ''): void
{
    $command = 'php -d display_errors=0 ' . escapeshellarg(dirname(__DIR__) . '/cli-test.php') . ' ' . $direction . ' '
        . escapeshellarg($from) . ' ' . escapeshellarg($to);
    if ($option !== '') {
        $command .= ' ' . $option;
    }
    $command .= ' 2>&1';
    exec($command, $output, $status);
    if ($status !== 0 && !is_dir($to)) {
        echo "Conversion failed: $direction $from\n" . implode("\n", $output) . "\n";
    }
}

// Prints one check's result, with up to 10 differences; returns 1 if it failed
function report(string $name, array $differences): int
{
    if (count($differences) === 0) {
        echo "passed  $name\n";
        return 0;
    }
    echo "FAILED  $name (" . count($differences) . " differences)\n";
    foreach (array_slice($differences, 0, 10) as $difference) {
        echo "          $difference\n";
    }
    return 1;
}

// Every file in both folders, compared byte for byte (a missing last line break doesn't count)
function sameFiles(string $original, string $back): array
{
    $differences = [];
    $a = filesIn($original);
    $b = filesIn($back);
    foreach (array_diff($a, $b) as $file) {
        $differences[] = "missing: $file";
    }
    foreach (array_diff($b, $a) as $file) {
        $differences[] = "added: $file";
    }
    foreach (array_intersect($a, $b) as $file) {
        if (rtrim(file_get_contents("$original/$file")) !== rtrim(file_get_contents("$back/$file"))) {
            $differences[] = "changed: $file";
        }
    }
    return $differences;
}

// Every file that came back, compared with the original (a missing last line break doesn't count), and the files
// that must come back; files that weren't converted aren't checked
function sameReturnedFiles(string $original, string $back, array $mustReturn): array
{
    $differences = [];
    $returned = filesIn($back);
    foreach (array_diff($mustReturn, $returned) as $file) {
        $differences[] = "missing: $file";
    }
    foreach ($returned as $file) {
        if (!is_file("$original/$file")) {
            $differences[] = "added: $file";
        } elseif (rtrim(file_get_contents("$original/$file")) !== rtrim(file_get_contents("$back/$file"))) {
            $differences[] = "changed: $file";
        }
    }
    return $differences;
}

// Each Markdown page's text, without the converter's hidden notes (<!-- grav… -->) and blank lines
function sameText(string $original, string $back): array
{
    $differences = [];
    foreach (filesIn($original) as $file) {
        if (!str_ends_with($file, '.md')) {
            continue;
        }
        if (!is_file("$back/$file")) {
            $differences[] = "missing: $file";
        } elseif (visibleText("$original/$file") !== visibleText("$back/$file")) {
            $differences[] = "changed: $file";
        }
    }
    return $differences;
}

function visibleText(string $path): array
{
    $text = preg_replace('/<!-- \/?grav.*?-->/s', '', str_replace("\r\n", "\n", file_get_contents($path)));
    // a card title as a Markdown link (which works on GitHub too) reads the same as Docsify's own link:
    // ## [Title](page.md) <!-- {docsify-ignore} --> → <h2><a href="#/page">Title</a></h2>, with or without a leading /
    $text = preg_replace('/^## \[([^\]]+)\]\(\/?([^)\s]+)\.md\)(.*?) <!-- \{docsify-ignore\} -->$/m', '<h2><a href="#/$2">$1</a>$3</h2>', $text);
    $lines = [];
    foreach (explode("\n", $text) as $line) {
        if (trim($line) !== '') {
            $lines[] = rtrim($line);
        }
    }
    return $lines;
}

// A folder's files, as paths inside it (the converter's notes and hidden system files left out)
function filesIn(string $folder): array
{
    $files = [];
    $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($folder, FilesystemIterator::SKIP_DOTS));
    foreach ($items as $item) {
        $name = $item->getFilename();
        if ($name === 'bridge-notes.txt' || str_starts_with($name, '.')) {
            continue;
        }
        $files[] = substr($item->getPathname(), strlen($folder) + 1);
    }
    sort($files);
    return $files;
}

// Deletes the test's own temporary folder (and only a folder it made)
function removeFolder(string $folder): void
{
    if (!str_contains($folder, 'converter-round-trip-')) {
        return;
    }
    $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($folder, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($items as $item) {
        if ($item->isDir()) {
            rmdir($item->getPathname());
        } else {
            unlink($item->getPathname());
        }
    }
    rmdir($folder);
}

// A copy of a folder and everything in it
function copyFolder(string $from, string $to): void
{
    mkdir($to, 0777, true);
    $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($from, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
    foreach ($items as $item) {
        $target = $to . '/' . substr($item->getPathname(), strlen($from) + 1);
        if ($item->isDir()) {
            mkdir($target, 0777, true);
        } else {
            copy($item->getPathname(), $target);
        }
    }
}
