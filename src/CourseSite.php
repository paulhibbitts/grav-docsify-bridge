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
            if (($course->coursePage->meta['published'] ?? true) === false) {
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
        if ($coursesPage === null) {
            $coursesPage = new Page('courses', '00.courses', 'course-list.md');
            $coursesPage->meta = ['title' => 'Courses'];
            $coursesPage->yaml = 'title: Courses';
        }
        CourseDocsifyWriter::writeCoursesPage($coursesPage, $this->courses, $banners, $out, $siteUrl);
    }

    // Docsify → Grav: the Courses page and each course folder, from a site this converter made
    public function toGrav(string $docsifyDir, string $out): void
    {
        $this->notes = [];
        $this->courses = [];
        $coursesPage = CourseDocsifyReader::readCoursesPage($docsifyDir);
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

    // A Docsify site of several courses this converter made: its home page is the Courses page
    public static function isDocsifySite(string $docsifyDir): bool
    {
        return CourseDocsifyReader::readCoursesPage($docsifyDir) !== null;
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
