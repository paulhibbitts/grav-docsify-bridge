<?php
namespace OPG;

// A whole Open Course Space site of several courses: its Courses page and a folder per course. In Docsify, the
// Courses page is the site's home page, with a card for each course, and each course is in a folder of its own,
// converted as a course on its own (see CourseDocsifyWriter and CourseDocsifyReader).
class CourseSite
{
    // things worth checking, for conversion-notes.txt
    public array $notes = [];
    // the courses converted, each as a Site
    public array $courses = [];

    // Grav → Docsify: the published courses of the pages folder, and its Courses page
    public function toDocsify(string $pagesDir, string $out, string $siteUrl = ''): void
    {
        $this->notes = [];
        $this->courses = [];
        $reader = new CourseGravReader();
        $coursesPage = null;
        $banners = [];
        foreach (self::subfolders($pagesDir) as $name) {
            $folder = $pagesDir . '/' . $name;
            if (is_file($folder . '/course-list.md') && $coursesPage === null) {
                $coursesPage = self::readCoursesPage($pagesDir, $name);
                continue;
            }
            if (!is_file($folder . '/course.md')) {
                continue;
            }
            $course = $reader->read($folder);
            // a switched-off course isn't on the Courses page, so it isn't converted
            $isSwitchedOff = isset($course->coursePage->meta['published']) && $course->coursePage->meta['published'] === false;
            if ($isSwitchedOff) {
                $this->notes[] = $course->coursePage->title() . ' (' . $name . ') is switched off, so it wasn\'t converted';
                continue;
            }
            if ($course->modulesPage === null) {
                $this->notes[] = $course->coursePage->title() . ' (' . $name . ') has no Modules or Weekly Units page, so it wasn\'t converted';
                continue;
            }
            $slug = Helpers::slugFromFolder($name);
            $writer = new CourseDocsifyWriter();
            $writer->write($course, $folder, $out . '/' . $slug, $siteUrl, $slug . '/');
            $banners[$name] = $writer->bannerImage;
            foreach (array_merge($reader->notes, $writer->warnings) as $note) {
                $this->notes[] = $course->coursePage->title() . ' – ' . $note;
            }
            $this->courses[] = $course;
        }
        // nothing to convert: no Courses page either
        if (count($this->courses) === 0) {
            return;
        }
        if ($coursesPage === null) {
            $coursesPage = new Page('courses', '00.courses', 'course-list.md');
            $coursesPage->meta = ['title' => 'Courses'];
            $coursesPage->yaml = 'title: Courses';
        }
        CourseDocsifyWriter::writeCoursesPage($coursesPage, $this->courses, $banners, $out, $siteUrl);
    }

    // Docsify → Grav: the Courses page and each course folder, from a site this converter made, or from one made in
    // Docsify (such as the Docsify-This Courses Site Template or the Docsify Open Courses Starter Kit)
    public function toGrav(string $docsifyDir, string $out): void
    {
        $this->notes = [];
        $this->courses = [];
        $coursesPage = CourseDocsifyReader::readCoursesPage($docsifyDir);
        if ($coursesPage === null) {
            $this->toGravFromDocsify(rtrim($docsifyDir, '/'), $out);
            return;
        }
        if ($coursesPage !== null) {
            (new GravWriter())->writePage($coursesPage, $out);
        }
        foreach (self::subfolders($docsifyDir) as $name) {
            if (!self::isCourseFolder($docsifyDir . '/' . $name)) {
                continue;
            }
            $reader = new CourseDocsifyReader();
            $course = $reader->read($docsifyDir . '/' . $name, $name . '/');
            (new CourseGravWriter())->write($course, $out);
            foreach ($reader->notes as $note) {
                $this->notes[] = $course->coursePage->title() . ' – ' . $note;
            }
            $this->courses[] = $course;
        }
    }

    // A Docsify site of several courses: its home page is the Courses page, either one this converter made, or one
    // made in Docsify with a card for each course folder
    public static function isDocsifySite(string $docsifyDir): bool
    {
        return CourseDocsifyReader::readCoursesPage($docsifyDir) !== null || count(self::courseCards(rtrim($docsifyDir, '/'))) > 0;
    }

    // ---- a site of several courses made in Docsify ----

    // The Courses page (home.md: its heading and text, without the cards), then each course folder, in the cards'
    // order, as a course of its own, with its title and description from its card
    private function toGravFromDocsify(string $docsifyDir, string $out): void
    {
        $cards = self::courseCards($docsifyDir);
        if (count($cards) === 0) {
            return;
        }
        (new GravWriter())->writePage(self::coursesPageFromDocsify($docsifyDir), $out);
        foreach ($cards as $index => $card) {
            $reader = new CourseDocsifyReader();
            $reader->courseFolder = sprintf('%02d', $index + 1) . '.' . $card['folder'];
            $reader->courseTitle = $card['title'];
            $reader->courseDescription = $card['description'];
            $course = $reader->read($docsifyDir . '/' . $card['folder'], $card['folder'] . '/');
            (new CourseGravWriter())->write($course, $out);
            foreach ($reader->notes as $note) {
                $this->notes[] = $card['title'] . ' – ' . $note;
            }
            $this->courses[] = $course;
        }
    }

    // The cards on the site's home page that open a course folder's home page:
    //   [![Alt](folder/images/photo.jpg)](folder/home.md) … <h2><a href="#/folder/home">Title</a></h2> … Description
    // Each card's folder, title and description (its first paragraph of plain text)
    private static function courseCards(string $docsifyDir): array
    {
        $text = (string)@file_get_contents($docsifyDir . '/home.md');
        $cards = [];
        foreach (preg_split('/<div class="card(?:-rounded)?">/', $text) as $index => $block) {
            if ($index === 0) {
                continue;
            }
            $block = explode('</div>', $block)[0];
            if (preg_match('/\]\(([a-z0-9_-]+)\/home(?:\.md)?\)|href="#\/([a-z0-9_-]+)\/home"/i', $block, $found) !== 1) {
                continue;
            }
            $folder = $found[1] !== '' ? $found[1] : $found[2];
            if (!is_file($docsifyDir . '/' . $folder . '/home.md') || !is_file($docsifyDir . '/' . $folder . '/_sidebar.md')) {
                continue;
            }
            $title = $folder;
            if (preg_match('/<h[2-4][^>]*>(.*?)<\/h[2-4]>|^#{2,4} ([^\n]+)$/ms', $block, $heading) === 1) {
                $title = trim(html_entity_decode(strip_tags(preg_replace('/<span class="badge">.*?<\/span>/', '', $heading[1] !== '' ? $heading[1] : $heading[2]))));
                $title = trim(preg_replace('/^\[(.*)\]\([^)]*\)$/', '$1', $title));
            }
            $description = '';
            foreach (preg_split('/\n\s*\n/', $block) as $paragraph) {
                $paragraph = trim($paragraph);
                if ($paragraph === '' || preg_match('/^(!\[|\[!\[|<|#)/', $paragraph) === 1) {
                    continue;
                }
                $description = $paragraph;
                break;
            }
            $cards[] = ['folder' => $folder, 'title' => $title, 'description' => $description];
        }
        return $cards;
    }

    // The Courses page: home.md's # heading as its title, and its text without the cards or the line that hides the
    // sidebar (Open Course Space draws the cards and has no sidebar there)
    private static function coursesPageFromDocsify(string $docsifyDir): Page
    {
        $text = str_replace("\r\n", "\n", (string)file_get_contents($docsifyDir . '/home.md'));
        $title = 'Courses';
        if (preg_match('/^# (.+)$/m', $text, $found) === 1) {
            $title = trim($found[1]);
            $text = preg_replace('/^# .+\n/m', '', $text, 1);
        }
        $text = preg_replace('/<div class="card-list[^"]*">.*<\/div>/s', '', $text);
        $text = preg_replace('/<!-- Hides the sidebar on this page -->\s*\n?/', '', $text);
        $text = preg_replace('/<style>[^<]*aside\.sidebar[^<]*<\/style>\s*/', '', $text);
        $codeParts = [];
        $text = Helpers::hideCode(trim($text), $codeParts);
        $text = Callouts::toGrav($text);
        $text = Embeds::toGrav($text);
        $text = Links::newTabToGrav($text);
        $page = new Page('courses', '00.courses', 'course-list.md');
        $page->meta = ['title' => $title, 'visible' => false, 'show_sidebar' => false, 'show_search_box' => false];
        $page->yaml = rtrim(\Symfony\Component\Yaml\Yaml::dump($page->meta));
        $page->body = "\n" . Helpers::showCode($text, $codeParts) . "\n";
        return $page;
    }

    // A course folder this converter made: its home page's note keeps the course's settings
    private static function isCourseFolder(string $folder): bool
    {
        $note = Helpers::readGravNote((string)@file_get_contents($folder . '/home.md'));
        return $note !== null && isset($note['where']['course']);
    }

    private static function readCoursesPage(string $pagesDir, string $name): Page
    {
        $page = new Page('courses', $name, 'course-list.md');
        $text = (string)file_get_contents($pagesDir . '/' . $name . '/course-list.md');
        if (preg_match('/^---\n(.*?)\n---\n(.*)$/s', $text, $found) === 1) {
            $page->yaml = $found[1];
            $settings = \Symfony\Component\Yaml\Yaml::parse($found[1]);
            if (is_array($settings)) {
                $page->meta = $settings;
            }
            $page->body = $found[2];
            // the blank space around the text, so it's written back exactly
            $trimmed = trim($found[2]);
            if ($trimmed !== '') {
                $page->textBefore = substr($found[2], 0, strpos($found[2], $trimmed));
                $page->textAfter = substr($found[2], strpos($found[2], $trimmed) + strlen($trimmed));
            }
        }
        return $page;
    }

    // Folders in name order (Grav's numbered folders sort into the site's order)
    private static function subfolders(string $dir): array
    {
        $folders = [];
        foreach (scandir($dir) as $name) {
            if ($name[0] !== '.' && is_dir($dir . '/' . $name)) {
                $folders[] = $name;
            }
        }
        natsort($folders);
        return array_values($folders);
    }
}
