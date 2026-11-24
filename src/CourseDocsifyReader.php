<?php
namespace OPG;

use Symfony\Component\Yaml\Yaml;

// Reads a Docsify course back into an Open Course Space course folder. A course this converter made goes back
// unchanged, using its hidden notes (with any edits made in Docsify); a course made in Docsify, laid out like the
// Docsify Open Course Starter Kit, is turned into a new course folder.
class CourseDocsifyReader
{
    // things worth checking, for bridge-notes.txt
    public array $notes = [];

    private string $dir;
    // each Docsify page => its Grav address, for links between pages
    private array $addresses = [];
    // the modules and link groups listed in _sidebar.md, and the pages in _navbar.md
    private array $sidebar = ['modules' => [], 'groups' => []];
    private array $navbar = [];
    // the site title in the navbar (- [Course Name](home.md ":class=site-title")), the course's name when it has no note
    private string $siteTitle = '';
    // the home page's own pages, from its cards as this bridge writes them: file => [title, description]
    private array $homeCards = [];
    // the Modules page's cards (modules.md, as this bridge writes it), for each module's description: file => [title, description]
    private array $moduleCards = [];
    // the course's address on the Grav site, e.g. '/cpt363-basic' (its pages' addresses start with it)
    private string $course = '';
    // in a site of several courses, the course's folder, e.g. 'cpt363-basic/': its links start at the site's top
    private string $top = '';
    // the original Grav site's address, kept in the home page's note (links to it go back to Grav's own form)
    private string $siteUrl = '';

    // a course made in Docsify becomes this course folder, with this title (to rename in Grav)
    private const NEW_COURSE_FOLDER = '01.my-course';
    private const NEW_COURSE_TITLE = 'My Course';

    // In a site of several courses made in Docsify (such as the Docsify-This Courses Site Template), each course's
    // folder, title and description come from the Courses page's card for it (see CourseSite::toGrav)
    public string $courseFolder = '';
    public string $courseTitle = '';
    public string $courseDescription = '';

    public function read(string $dir, string $top = ''): Site
    {
        $this->dir = rtrim($dir, '/');
        $this->top = $top;
        $this->notes = [];
        $this->sidebar = $this->parseSidebar($this->readFile('_sidebar.md'));
        $this->navbar = $this->parseNavbar($this->readFile('_navbar.md'));

        $notedPages = [];
        foreach (glob($this->dir . '/*.md') as $path) {
            $note = Helpers::readGravNote($this->readFile(basename($path)));
            if ($note !== null) {
                $notedPages[basename($path)] = $note;
            }
        }
        if (count($notedPages) > 0) {
            return $this->fromNotes($notedPages);
        }
        return $this->fromDocsify();
    }

    // ---- a course written by this converter ----

    private function fromNotes(array $notedPages): Site
    {
        $site = new Site();
        // the home page's note keeps the course's folder, settings and header image, and the original site's address
        $homeNote = [];
        if (isset($notedPages['home.md'])) {
            $homeNote = $notedPages['home.md']['where'];
        }
        $this->readCourseNote($site, $homeNote);
        $this->siteUrl = '';
        if (isset($homeNote['site'])) {
            $this->siteUrl = (string)$homeNote['site'];
        }
        $this->course = '/' . Helpers::slugFromFolder($site->courseFolder);
        // the home page's folder ('01.home'): a page in a folder inside it is one of the home page's own pages
        $homeFolder = isset($homeNote['file']) ? dirname($homeNote['file']) : '';
        // every page's Grav address first, for the links between them
        foreach ($notedPages as $file => $note) {
            $gravFile = $note['where']['file'];
            $folder = dirname($gravFile);
            if (self::isModulesListFile($gravFile)) {
                // the Modules page (its note is in the sidebar), which may have a page the converter made: modules.md
                $modulesSlug = Helpers::slugFromFolder($folder);
                $this->addresses[$modulesSlug . '.md'] = $this->course . '/' . $modulesSlug;
            }
            if ($file === 'home.md') {
                $this->addresses[$file] = $this->course . '/home';
            } elseif ($homeFolder !== '' && dirname($folder) === $homeFolder) {
                // one of the home page's own pages: '01.home/02.syllabus/course-page.md' → '/cpt363-basic/home/syllabus'
                $this->addresses[$file] = $this->course . '/home/' . Helpers::slugFromFolder(basename($folder));
            } elseif (self::isModulePageFile($gravFile)) {
                // a page inside a module: '02.modules/02.module-01/01.readings/course-page.md'
                // → '/cpt363-basic/modules/module-01/readings'
                $parts = explode('/', $folder);
                $this->addresses[$file] = $this->course . '/' . implode('/', array_map([Helpers::class, 'slugFromFolder'], $parts));
            } elseif (self::isModuleFile($gravFile)) {
                // a module: '02.modules/02.module-01/course-page.md' → '/cpt363-basic/modules/module-01'
                $moduleSlug = Helpers::slugFromFolder(basename($folder));
                $modulesSlug = Helpers::slugFromFolder(basename(dirname($folder)));
                $this->addresses[$file] = $this->course . '/' . $modulesSlug . '/' . $moduleSlug;
            } elseif (!str_contains($folder, '/') && Helpers::isNumbered($folder)) {
                // a menu page, in a numbered folder in the course: '03.schedule/course-page.md' → '/cpt363-basic/schedule'
                $this->addresses[$file] = $this->course . '/' . Helpers::slugFromFolder($folder);
            }
        }

        $modules = [];
        $modulePages = []; // the pages inside modules: the module's Grav file => [Grav file => page]
        $homePages = [];   // the home page's own pages: Grav file => page
        foreach ($notedPages as $file => $note) {
            $gravFile = $note['where']['file'];
            if (self::isModulesListFile($gravFile)) {
                // the Modules (or Weekly Units) page's note: in _sidebar.md, or in home.md when the course has no home page
                $site->modulesPage = $this->notedPage('modules', $note, '');
                $site->modulesPage->body = $note['where']['body'] ?? '';
                continue;
            }
            if ($file === 'home.md') {
                $site->courseHome = $this->readNotedHome($note, $site);
                continue;
            }
            if ($homeFolder !== '' && dirname(dirname($gravFile)) === $homeFolder) {
                $page = $this->notedPage('home-page', $note, $this->authorText($note['rest'], $note));
                $this->addNotedImages($page, $note);
                $homePages[$gravFile] = $page;
                continue;
            }
            if (self::isModulePageFile($gravFile)) {
                $page = $this->notedPage('module-page', $note, $this->authorText($note['rest'], $note));
                $this->addNotedImages($page, $note);
                $modulePages[dirname(dirname($gravFile)) . '/course-page.md'][$gravFile] = $page;
                continue;
            }
            if (self::isModuleFile($gravFile)) {
                $modules[$gravFile] = $this->readNotedModule($file, $note);
                continue;
            }
            $kind = 'menu';
            if (str_starts_with($gravFile, 'sidebar/')) {
                $kind = 'sidebar';
            } elseif (str_starts_with($gravFile, 'footer/')) {
                $kind = 'footer';
            }
            $rest = $note['rest'];
            if ($kind === 'sidebar') {
                $rest = $this->withSidebarGroups($rest);
            }
            $page = $this->notedPage($kind, $note, $this->authorText($rest, $note));
            $this->addNotedImages($page, $note);
            if ($kind === 'menu') {
                $this->updateTitle($page, $note['rest'], $this->navbar[$file] ?? '');
                $site->coursePages[] = $page;
            } elseif ($kind === 'sidebar') {
                $site->courseSidebar = $page;
            } else {
                $site->courseFooter = $page;
            }
        }
        // the sidebar's link groups without a Contact page (all of the course sidebar was links)
        if ($site->courseSidebar === null && count($this->sidebar['groups']) > 0) {
            $site->courseSidebar = $this->sidebarPage($this->groupsText(), 'sidebar');
        }
        if ($site->modulesPage === null) {
            $site->modulesPage = $this->newPage('modules', '02.modules', 'module.md', ['title' => 'Modules']);
        }
        if ($site->courseHome !== null && count($homePages) > 0) {
            ksort($homePages, SORT_NATURAL);
            $site->courseHome->children = array_merge($site->courseHome->children, array_values($homePages));
        }
        ksort($modules, SORT_NATURAL);
        foreach ($modules as $gravFile => $module) {
            if (isset($modulePages[$gravFile])) {
                ksort($modulePages[$gravFile], SORT_NATURAL);
                $module->children = array_values($modulePages[$gravFile]);
            }
        }
        $site->modulesPage->children = array_values($modules);
        // menu pages in Grav's order (05.schedule, 06.topics …)
        usort($site->coursePages, function ($a, $b) {
            return strnatcmp($a->folder, $b->folder);
        });
        $this->noteOtherPages(array_keys($notedPages));
        return $site;
    }

    // The Modules page's file: '02.modules/module.md', a Weekly Units page ('02.weekly-units/blog.md'), or the plain
    // Modules page the Common Cartridge Converter writes for a course of one module ('30.modules/course-page.md')
    private static function isModulesListFile(string $gravFile): bool
    {
        if (in_array(basename($gravFile), ['module.md', 'blog.md'], true)) {
            return true;
        }
        $folder = dirname($gravFile);
        return basename($gravFile) === 'course-page.md' && !str_contains($folder, '/') && Helpers::isNumbered($folder)
            && Helpers::slugFromFolder($folder) === 'modules';
    }

    // A module's page is in a folder inside the Modules page's folder: '02.modules/02.module-01/course-page.md', or a
    // week's inside the Weekly Units page's folder: '02.weekly-units/week-01/item.md'
    private static function isModuleFile(string $gravFile): bool
    {
        return in_array(basename($gravFile), ['course-page.md', 'item.md'], true) && substr_count($gravFile, '/') === 2;
    }

    // A page inside a module is in a folder inside the module's: '02.modules/02.module-01/01.readings/course-page.md'
    private static function isModulePageFile(string $gravFile): bool
    {
        return basename($gravFile) === 'course-page.md' && substr_count($gravFile, '/') === 3;
    }

    // The course's folder, settings (course.md) and header image, from the home page's note. Without them (a course
    // converted before they were kept), a new course folder.
    private function readCourseNote(Site $site, array $where): void
    {
        $course = [];
        if (isset($where['course']) && is_array($where['course'])) {
            $course = $where['course'];
        }
        $folder = '';
        if (isset($course['folder'])) {
            $folder = (string)$course['folder'];
        }
        // the folder is written on the server, so only a plain folder name: letters, numbers, . _ and -
        if (preg_match('/^[A-Za-z0-9_-][A-Za-z0-9._-]*$/', $folder) !== 1 || str_contains($folder, '..')) {
            $this->newCourse($site);
            return;
        }
        $site->courseFolder = $folder;
        $site->coursePage = $this->pageFromNote('course', '', 'course.md', $course);
        // the course's footer when it only credits Grav, kept here (it isn't shown in Docsify)
        if (isset($where['footer']) && is_array($where['footer'])) {
            $site->courseFooter = $this->pageFromNote('footer', 'footer', 'default.md', $where['footer']);
        }
        if (!isset($where['headerimage']) || !is_array($where['headerimage'])) {
            return;
        }
        $header = $where['headerimage'];
        $site->headerImage = $this->pageFromNote('headerimage', 'headerimage', 'default.md', $header);
        // each image's name, and where it is in the Docsify site: ['photo.jpg' => 'images/photo.jpg']
        $images = [];
        if (isset($header['images']) && is_array($header['images'])) {
            $images = $header['images'];
        }
        foreach ($images as $name => $source) {
            $name = basename((string)$name);
            $path = Helpers::imageInside($this->dir, $this->dir . '/' . ltrim((string)$source, './'));
            if ($path !== '') {
                $site->headerImage->images[] = $name;
                $site->headerImage->imageSources[$name] = $path;
            }
        }
    }

    // A page kept in a note as its front matter and text (the course's settings, its header image)
    private function pageFromNote(string $kind, string $folder, string $file, array $kept): Page
    {
        $page = new Page($kind, $folder, $file);
        if (isset($kept['yaml'])) {
            $page->yaml = (string)$kept['yaml'];
        }
        $settings = Yaml::parse($page->yaml);
        if (is_array($settings)) {
            $page->meta = $settings;
        }
        if (isset($kept['body'])) {
            $page->body = (string)$kept['body'];
        }
        foreach (['before' => 'textBefore', 'after' => 'textAfter'] as $name => $property) {
            if (isset($kept[$name]) && trim((string)$kept[$name]) === '') {
                $page->$property = (string)$kept[$name];
            }
        }
        return $page;
    }

    // A new course folder and its settings, for a course made in Docsify
    private function newCourse(Site $site): void
    {
        $site->courseFolder = $this->courseFolder !== '' ? $this->courseFolder : self::NEW_COURSE_FOLDER;
        $title = $this->courseTitle !== '' ? $this->courseTitle : ($this->siteTitle !== '' ? $this->siteTitle : self::NEW_COURSE_TITLE);
        $settings = ['title' => $title, 'published' => true, 'hide_from_menu' => true];
        if ($this->courseDescription !== '') {
            $settings['description'] = $this->courseDescription;
        }
        $settings['course_home'] = 'home';
        $settings['page-inject'] = ['processed_content' => true];
        $site->coursePage = $this->newPage('course', '', 'course.md', $settings);
    }

    // A module: its settings, with the title from the sidebar and the description from the page's heading (both may
    // have been edited in Docsify), and its text
    private function readNotedModule(string $file, array $note): Page
    {
        $page = $this->notedPage('module', $note, $this->authorText($note['rest'], $note));
        $this->addNotedImages($page, $note);
        $changes = [];
        $label = $this->sidebar['modules'][$file] ?? '';
        if ($label !== '' && $label !== $page->title()) {
            $changes['title'] = $label;
        }
        // the page's # heading (the module's title), in the converter's generated block at the top: an edited
        // heading renames the module, unless its sidebar link was renamed
        if (preg_match('/<!-- grav:gen -->\n# (.+?)\n/', $note['rest'], $found) === 1) {
            $heading = trim($found[1]);
            if ($heading !== $page->title() && !isset($changes['title'])) {
                $changes['title'] = $heading;
            }
        }
        $this->changeSettings($page, $changes);
        return $page;
    }

    // The home page and its parts: each part starts with its own note and the page-inject line that shows it, and
    // ends with <!-- grav:/part --> (or, in courses converted before that note was added, at the next part or the
    // end); the home page's own text is everything else
    private function readNotedHome(array $note, Site $site): Page
    {
        $home = $this->notedPage('home', $note, '');
        $parts = [];
        // a part: its note and text, up to <!-- grav:/part --> (or the next note)
        $pattern = '/<!-- grav: \{.*?\n-->\n(.*?)(?:<!-- grav:\/part -->|(?=<!-- grav: \{)|$)/s';
        $ownText = preg_replace_callback($pattern, function ($match) use (&$parts) {
            $partNote = Helpers::readGravNote($match[0]);
            if ($partNote === null || preg_match('/<!-- grav-embed-line: (\[plugin:page-inject\]\([^)]*\)) -->/', $partNote['rest'], $inject) !== 1) {
                return $match[0];
            }
            $text = str_replace($inject[0], '', str_replace('<!-- grav:/part -->', '', $partNote['rest']));
            $part = $this->notedPage('home-part', $partNote, $this->authorText($text, $partNote));
            $this->addNotedImages($part, $partNote);
            $parts[] = $part;
            return "\n" . $inject[1] . "\n";
        }, $note['rest']);
        // the parts' blank lines are left behind where they were taken out
        $home->body = $this->authorText(Helpers::collapseBlankLines($ownText), $note);
        $this->addNotedImages($home, $note);
        // the heading shows the course's name (after its header image): a new heading renames the course
        $heading = '/<!-- grav:gen -->\n(?:!\[[^\n]*\n\n)?# (.+?)\n<!-- \/grav:gen -->/';
        if ($site->coursePage !== null && preg_match($heading, $note['rest'], $found) === 1 && trim($found[1]) !== $site->coursePage->title()) {
            $this->changeSettings($site->coursePage, ['title' => trim($found[1])]);
        }
        $home->children = $parts;
        return $home;
    }

    // A page from its note: its Grav file, its front matter exactly as it was, and its text
    private function notedPage(string $kind, array $note, string $body): Page
    {
        $gravFile = $note['where']['file'];
        $page = new Page($kind, dirname($gravFile), basename($gravFile));
        $page->yaml = $note['yaml'];
        $settings = Yaml::parse($note['yaml']);
        if (is_array($settings)) {
            $page->meta = $settings;
        }
        $page->body = $body;
        // the spacing around the text, when it wasn't the usual one
        if (isset($note['where']['before'])) {
            $page->textBefore = (string)$note['where']['before'];
        }
        if (isset($note['where']['after'])) {
            $page->textAfter = (string)$note['where']['after'];
        }
        return $page;
    }

    // The author's text: everything the converter didn't add, with callouts, embeds and links back in Grav's form
    private function authorText(string $rest, array $note): string
    {
        $text = trim(preg_replace('/<!-- grav:gen -->\n.*?\n<!-- \/grav:gen -->/s', '', $rest));
        $codeParts = [];
        $text = Helpers::hideCode($text, $codeParts);
        $text = Callouts::toGrav($text);
        $text = Embeds::toGrav($text);
        // each converted link's original form, e.g. ['module-01.md', '../modules/module-01']
        $originalLinks = [];
        if (isset($note['where']['links']) && is_array($note['where']['links'])) {
            $originalLinks = $note['where']['links'];
        }
        $text = Links::mapToGrav($text, $this->addresses, $originalLinks);
        // relative links to Grav-only pages, made full: back as they were written (../all-web-picks-of-the-week)
        foreach ($originalLinks as $link) {
            if (!str_starts_with((string)$link[0], 'http')) {
                continue;
            }
            $fullLink = '](' . $link[0];
            $at = strpos($text, $fullLink);
            if ($at !== false) {
                $text = substr_replace($text, '](' . $link[1], $at, strlen($fullLink));
            }
        }
        $site = $this->siteUrl;
        if (isset($note['where']['site'])) {
            $site = (string)$note['where']['site'];
        }
        if ($site !== '') {
            $text = str_replace('](' . rtrim($site, '/') . '/', '](/', $text);
        }
        $text = Links::newTabToGrav($text);
        return Helpers::showCode($text, $codeParts);
    }

    // A page's images (listed in its note) back from images/: images/photo.jpg, or images/module-01-photo.jpg when
    // another page had an image of the same name
    private function addNotedImages(Page $page, array $note): void
    {
        foreach ($note['where']['images'] ?? [] as $image) {
            $image = basename((string)$image);
            $candidates = ['images/' . $page->slug() . '-' . $image, 'images/' . $image];
            $chosen = '';
            foreach ($candidates as $candidate) {
                if (str_contains($page->body, '](' . $candidate)) {
                    $chosen = $candidate;
                    break;
                }
            }
            if ($chosen === '') {
                foreach ($candidates as $candidate) {
                    if (is_file($this->dir . '/' . $candidate)) {
                        $chosen = $candidate;
                        break;
                    }
                }
            }
            if ($chosen === '') {
                continue;
            }
            // only an image inside the site is copied
            $source = Helpers::imageInside($this->dir, $this->dir . '/' . $chosen);
            if ($source === '') {
                continue;
            }
            $page->images[] = $image;
            $page->imageSources[$image] = $source;
            $page->body = str_replace('](' . $chosen, '](' . $image, $page->body);
        }
    }

    // The course sidebar's Contact page with each link group (from _sidebar.md) back in its place, laid out as it was
    private function withSidebarGroups(string $rest): string
    {
        $groups = $this->sidebar['groups'];
        $rest = preg_replace_callback('/<!-- grav:sidebar-group (\{.*?\}) -->/', function ($match) use (&$groups) {
            $group = array_shift($groups);
            if ($group === null) {
                return '';
            }
            // the group's Grav layout, e.g. "## {heading}\n{link}  \n{link}" (the usual one if links were added or removed)
            $saved = json_decode($match[1], true);
            $layout = '';
            if (is_array($saved) && isset($saved['layout'])) {
                $layout = (string)$saved['layout'];
            }
            if ($layout === '' || substr_count($layout, '{link}') !== count($group['links'])) {
                return $this->groupText($group);
            }
            $layout = str_replace('{heading}', $group['heading'], $layout);
            foreach ($group['links'] as $link) {
                $at = strpos($layout, '{link}');
                // an icon kept in the layout ([fa="calendar"][/fa]{link}): the link's icon image goes; otherwise an icon
                // image becomes the icon's shortcode
                if (str_ends_with(substr($layout, 0, $at), '[/fa]')) {
                    $link = preg_replace('/!\[[^\]]*\]\(https:\/\/api\.iconify\.design\/[^)]*\)/', '', $link);
                } else {
                    $link = self::iconLinkToGrav($link);
                }
                $layout = substr($layout, 0, $at) . $link . substr($layout, $at + strlen('{link}'));
            }
            return $layout;
        }, $rest);
        // groups added in Docsify go at the end
        foreach ($groups as $group) {
            $rest .= "\n\n" . $this->groupText($group);
        }
        return $rest;
    }

    // A sidebar link with an icon image in front, as the converter and the Starter Kits write them → the theme's icon
    // before the link: [![Calendar Icon](https://api.iconify.design/fa7-solid/calendar.svg?…)Calendar](…) → [fa="calendar"][/fa][Calendar](…)
    private static function iconLinkToGrav(string $link): string
    {
        if (preg_match('/^\[(!\[[^\]]*\]\(https:\/\/api\.iconify\.design\/[^)]*\))\s*/', $link, $found) !== 1) {
            return $link;
        }
        $icon = trim(Embeds::iconsToGrav($found[1]));
        if (!str_starts_with($icon, '[fa=')) {
            return $link;
        }
        return $icon . '[' . substr($link, strlen($found[0]));
    }

    // A link group as Open Course Space writes it: a heading, then a link on each line
    private function groupText(array $group): string
    {
        $lines = ['## ' . $group['heading']];
        foreach ($group['links'] as $link) {
            $lines[] = self::iconLinkToGrav($link) . '  ';
        }
        return rtrim(implode("\n", $lines));
    }

    private function groupsText(): string
    {
        $texts = [];
        foreach ($this->sidebar['groups'] as $group) {
            $texts[] = $this->groupText($group);
        }
        return implode("\n\n", $texts);
    }

    // A title edited in Docsify (the page's generated # heading, or its navbar link) goes into its settings
    private function updateTitle(Page $page, string $rest, string $navbarLabel): void
    {
        $changes = [];
        if (preg_match('/<!-- grav:gen -->\n# (.+?)\n<!-- \/grav:gen -->/', $rest, $found) === 1 && trim($found[1]) !== $page->title()) {
            $changes['title'] = trim($found[1]);
        }
        if ($navbarLabel !== '' && $navbarLabel !== $page->title() && !isset($changes['title'])) {
            $changes['title'] = $navbarLabel;
        }
        $this->changeSettings($page, $changes);
    }

    private function changeSettings(Page $page, array $changes): void
    {
        if (count($changes) === 0) {
            return;
        }
        foreach ($changes as $name => $value) {
            $page->meta[$name] = $value;
        }
        $page->yaml = $this->yaml($page->meta);
    }

    // ---- a course made in Docsify ----

    private function fromDocsify(): Site
    {
        $site = new Site();
        $moduleFiles = array_keys($this->sidebar['modules']);
        // a course whose sidebar lists only weeks (week-01.md …) gets a Weekly Units page, as in Open Course Space
        $isWeekly = count($this->sidebar['modules']) > 0;
        foreach (array_keys($this->sidebar['modules']) as $file) {
            if (preg_match('/^week-?[0-9]+\.md$/i', $file) !== 1) {
                $isWeekly = false;
            }
        }
        // module pages that aren't in the sidebar yet (a course often lists only the weeks so far)
        $unlisted = [];
        foreach (glob($this->dir . '/*.md') as $path) {
            $file = basename($path);
            // module-05.md, week-5.md …
            $isModulePage = preg_match('/^(module|week)-?[0-9]+\.md$/i', $file) === 1;
            if ($isModulePage && !in_array($file, $moduleFiles, true) && !isset($this->navbar[$file])) {
                $moduleFiles[] = $file;
                $unlisted[] = $file;
            }
        }
        if ($isWeekly && count($unlisted) > 0) {
            // weeks still to come, as in Open Course Space
            $this->notes[] = implode(', ', $unlisted) . (count($unlisted) === 1 ? ' isn\'t' : ' aren\'t') . ' in the sidebar, so '
                . (count($unlisted) === 1 ? 'it\'s an unpublished week' : 'they\'re unpublished weeks') . ' (publish them in Grav when they start)';
        } elseif (count($unlisted) === 1) {
            $this->notes[] = $unlisted[0] . ' isn\'t in the sidebar, so it\'s a hidden module (it opens from links, but isn\'t listed)';
        } elseif (count($unlisted) > 1) {
            $this->notes[] = implode(', ', $unlisted) . ' aren\'t in the sidebar, so they\'re hidden modules (they open from links, but aren\'t listed)';
        }
        $menuFiles = [];
        foreach ($this->navbar as $file => $label) {
            // (modules.md is the Modules page itself, as the converter writes it, so it isn't a menu page)
            if ($file !== 'contact.md' && $file !== 'modules.md' && !in_array($file, $moduleFiles, true)) {
                $menuFiles[] = $file;
            }
        }

        $unitsSlug = $isWeekly ? 'weekly-units' : 'modules';

        $this->newCourse($site);
        $this->course = '/' . Helpers::slugFromFolder($site->courseFolder);
        // every page's Grav address first, for the links between them
        $this->addresses = ['home.md' => $this->course . '/home'];
        foreach ($moduleFiles as $file) {
            $this->addresses[$file] = $this->course . '/' . $unitsSlug . '/' . basename($file, '.md');
        }
        foreach ($menuFiles as $file) {
            $this->addresses[$file] = $this->course . '/' . basename($file, '.md');
        }
        // the pages inside modules, and the home page's own pages (as this bridge writes them)
        $pageFiles = [];
        foreach ($this->sidebar['modulePages'] ?? [] as $moduleFile => $pages) {
            foreach (array_keys($pages) as $file) {
                $this->addresses[$file] = $this->course . '/' . $unitsSlug . '/' . basename($moduleFile, '.md') . '/' . self::modulePageSlug($file);
                $pageFiles[] = $file;
            }
        }
        $this->homeCards = $this->homePageCards($this->readFile('home.md'));
        $this->moduleCards = is_file($this->dir . '/modules.md') ? $this->cardsIn($this->readFile('modules.md')) : [];
        foreach (array_keys($this->homeCards) as $file) {
            $this->addresses[$file] = $this->course . '/home/' . substr(basename($file, '.md'), strlen('home-'));
            $pageFiles[] = $file;
        }

        $site->courseHome = $this->homeFromDocsify($site);
        if ($isWeekly) {
            // the Weekly Units page lists the weeks, newest first, like Open Course Space's own
            $site->modulesPage = $this->newPage('modules', '02.weekly-units', 'blog.md', [
                'title' => 'Weekly Units', 'published' => true,
                'content' => ['items' => '@self.children', 'limit' => 7, 'order' => ['by' => 'folder', 'dir' => 'desc'], 'pagination' => '1'],
                'hide_post_summary' => true, 'hide_post_date' => true, 'hide_post_taxonomy' => true, 'child_type' => 'item',
            ]);
        } else {
            $site->modulesPage = $this->newPage('modules', '02.modules', 'module.md', ['title' => 'Modules', 'published' => true]);
        }
        foreach ($moduleFiles as $index => $file) {
            $isListed = isset($this->sidebar['modules'][$file]);
            $module = $this->moduleFromDocsify($file, sprintf('%02d', $index + 1), $isListed, $isWeekly);
            $pageNumber = 1;
            $pageCards = $this->cardsIn($this->readFile($file));
            foreach ($this->sidebar['modulePages'][$file] ?? [] as $pageFile => $label) {
                $description = $pageCards[$pageFile][1] ?? '';
                $module->children[] = $this->pageFromDocsify('module-page', $module->folder . '/' . sprintf('%02d', $pageNumber) . '.' . self::modulePageSlug($pageFile), $pageFile, $label, $description);
                $pageNumber++;
            }
            $site->modulesPage->children[] = $module;
        }
        foreach ($menuFiles as $index => $file) {
            $site->coursePages[] = $this->menuPageFromDocsify($file, sprintf('%02d', $index + 3));
        }

        // the course sidebar: the Contact page's details, then the sidebar's link groups (such as the LMS links)
        $contact = $this->readFile('contact.md');
        $contact = trim(preg_replace('/^\s*# .*\n/', '', $contact, 1));
        $sidebarText = trim($contact . "\n\n" . $this->groupsText());
        if ($sidebarText !== '') {
            $site->courseSidebar = $this->sidebarPage($sidebarText, 'contact.md');
        }
        // a rule at the top only separates Docsify's footer from the page; Grav's footer has its own section
        $footer = trim(preg_replace('/^\s*(---|\*\*\*|___)\s*\n/', '', $this->readFile('_footer.md'), 1));
        if ($footer !== '') {
            $site->courseFooter = $this->newPage('footer', 'footer', 'default.md', ['title' => 'Footer', 'routable' => false, 'visible' => false]);
            $site->courseFooter->body = $this->toGravText($footer, $site->courseFooter, '_footer.md');
        }
        $this->noteOtherPages(array_merge(['home.md', 'contact.md', 'modules.md'], $moduleFiles, $menuFiles, $pageFiles));
        Helpers::turnOnFootnotes($site);
        $this->notes = array_merge($this->notes, Helpers::docsifyWordingNotes($site));
        return $site;
    }

    // The course home: its text, with each announcement box (<blockquote class="announcement">) as a part of the home
    // page shown in an alert box, as Open Course Space's reminders are. A banner at the top becomes the course's
    // header image.
    private function homeFromDocsify(Site $site): Page
    {
        $home = $this->newPage('home', '01.home', 'course-page.md', ['title' => 'Home', 'published' => true, 'visible' => false, 'hide_page_title' => true]);
        $text = $this->readFile('home.md');
        // a banner at the top: ![Alt text](images/photo.jpg ':class=banner-image')
        if (preg_match('/^\s*!\[[^\]]*\]\(([^)\s]+)\s+[\'"][^\'"]*:class=banner-image[^\'"]*[\'"]\)\s*\n/', $text, $found) === 1) {
            $site->headerImage = $this->newPage('headerimage', 'headerimage', 'default.md', ['title' => 'Header Image', 'visible' => false, 'routable' => false]);
            if ($this->addImage($site->headerImage, str_replace('%20', ' ', $found[1]), 'home.md')) {
                $text = substr($text, strlen($found[0]));
            } else {
                $site->headerImage = null;
            }
        }
        $number = 0;
        $text = preg_replace_callback('/<blockquote class="announcement">\s*\n(.*?)\n\s*<\/blockquote>/s', function ($match) use ($home, &$number) {
            $slug = '_announcement' . ($number === 0 ? '' : '-' . ($number + 1));
            $part = $this->newPage('home-part', '01.home/' . sprintf('%02d', $number) . '.' . $slug, 'alert.md', [
                'title' => 'Announcement', 'alert_style' => 'alert-secondary', 'published' => true, 'visible' => false,
            ]);
            $part->body = $this->toGravText(trim($match[1]), $part, 'home.md');
            $home->children[] = $part;
            $number++;
            return '[plugin:page-inject](../home/' . $slug . ')';
        }, $text);
        // the home page's cards (this bridge's list of its own pages) and licence line are made from settings, so they go
        $text = self::withoutCardList($text, array_keys($this->homeCards));
        if (preg_match('/\n*Licensed under (?:\[([^\]]+)\]\(([^)\s]+)\)|([^\n]+?))\.\s*$/', $text, $license) === 1) {
            $site->coursePage->meta['show_oer_attribution'] = true;
            $site->coursePage->meta['license'] = ($license[1] ?? '') !== '' ? $license[1] : $license[3];
            if (($license[2] ?? '') !== '') {
                $site->coursePage->meta['license_url'] = $license[2];
            }
            $site->coursePage->yaml = $this->yaml($site->coursePage->meta);
            $text = substr($text, 0, -strlen($license[0]));
        }
        $home->body = $this->toGravText(trim($text), $home, 'home.md');
        $pageNumber = count($home->children) + 1;
        foreach ($this->homeCards as $file => [$title, $description]) {
            $slug = substr(basename($file, '.md'), strlen('home-'));
            $home->children[] = $this->pageFromDocsify('home-page', '01.home/' . sprintf('%02d', $pageNumber) . '.' . $slug, $file, $title, $description);
            $pageNumber++;
        }
        return $home;
    }

    // The cards on the home page that link to its own pages (home-expectations.md …), as this bridge writes them:
    // home-page file => [title, description]
    private function homePageCards(string $text): array
    {
        return array_filter($this->cardsIn($text), fn($file) => str_starts_with($file, 'home-'), ARRAY_FILTER_USE_KEY);
    }

    // Every card in a card list that links to a page of the site (as this bridge writes the Modules page, a module's
    // pages and the home page's pages): page file => [title, description]
    private function cardsIn(string $text): array
    {
        $cards = [];
        preg_match_all('/<div class="card">\s*\n(.*?)\n<\/div>/s', $text, $found);
        foreach ($found[1] as $card) {
            // '## [Expectations](home-expectations.md) …', or in a site of several courses '## [Expectations](/course/home-expectations.md)'
            // (or Docsify's own link, '<h2><a href="#/course/home-x">', as written before)
            if (preg_match('/^## \[([^\]]+)\]\((?:[^)\s]*\/)?([a-z0-9-]+)\.md\)|<h2><a href="#\/(?:[^"]*\/)?([a-z0-9-]+)">([^<]+)<\/a>/m', $card, $link) !== 1) {
                continue;
            }
            $file = ($link[2] ?? '') !== '' ? $link[2] . '.md' : $link[3] . '.md';
            if (!is_file($this->dir . '/' . $file)) {
                continue;
            }
            $title = ($link[1] ?? '') !== '' ? $link[1] : $link[4];
            // the card's text after its heading line is the page's description
            $description = trim(preg_replace('/^.*?(?:<\/h2>|<!--\s*\{docsify-ignore\}\s*-->)\s*/s', '', $card));
            $cards[$file] = [$title, $description === '<p></p>' ? '' : $description];
        }
        return $cards;
    }

    // A page this bridge wrote inside a module or the home page: its # heading is its title (the sidebar's label or
    // the card's title), then its text
    private function pageFromDocsify(string $kind, string $folder, string $file, string $title, string $description): Page
    {
        $settings = ['title' => $title, 'published' => true];
        if ($description !== '') {
            $settings['description'] = $description;
        }
        $page = $this->newPage($kind, $folder, 'course-page.md', $settings);
        $text = preg_replace('/^\s*# [^\n]*\n/', '', $this->readFile($file), 1);
        $page->body = $this->toGravText(trim($text), $page, $file);
        return $page;
    }

    // A page inside a module's folder name: 'module-01-02-a2-creating-multiple-objects.md' → 'a2-creating-multiple-objects'
    private static function modulePageSlug(string $file): string
    {
        return preg_replace('/^module-\d+-\d+-/', '', basename($file, '.md'));
    }

    // The text without a card list (<div class="card-list…">…</div>) whose cards all link to these pages – a list this
    // bridge made from the pages' settings
    private static function withoutCardList(string $text, array $files): string
    {
        if (count($files) === 0) {
            return $text;
        }
        return preg_replace_callback('/\n*<div class="card-list[^"]*">\n.*?\n<\/div>\n(?:\s*\n)*<\/div>\s*/s', function ($match) use ($files) {
            // ](page.md), ](/course/page.md) or href="#/course/page"
            preg_match_all('/(?:\]\(\/?(?:[a-z0-9_-]+\/)?|href="#\/(?:[^"]*\/)?)([a-z0-9-]+)(?:\.md)?[)"]/', $match[0], $links);
            foreach ($links[1] as $link) {
                if (!in_array($link . '.md', $files, true)) {
                    return $match[0];
                }
            }
            return "\n";
        }, $text);
    }

    // A module page: the banner image and its credit become the module's header image, the # heading its description
    // (its title is the sidebar's label), and a Learning Objectives box its learning objectives
    private function moduleFromDocsify(string $file, string $number, bool $isListed, bool $isWeek = false): Page
    {
        $slug = basename($file, '.md');
        $label = $this->sidebar['modules'][$file] ?? Helpers::titleFromSlug($slug);
        $settings = ['title' => $label];
        $lines = explode("\n", str_replace("\r\n", "\n", $this->readFile($file)));
        $i = $this->skipBlankLines($lines, 0);
        $headerImage = null;
        // a week starts with its # heading (Week 4 (May 30 - Jun 5)), then its banner – and so may a module (# Module 4,
        // then its banner): the heading is its title
        $headingFirst = isset($lines[$i]) && str_starts_with($lines[$i], '# ');
        $bannerNext = preg_match('/:class=banner-image/', $lines[$this->skipBlankLines($lines, $i + 1)] ?? '') === 1;
        if ($headingFirst && ($isWeek || $bannerNext)) {
            if (!isset($this->sidebar['modules'][$file])) {
                $settings['title'] = trim(substr($lines[$i], 2));
            }
            $i = $this->skipBlankLines($lines, $i + 1);
        }
        // a banner at the top: ![Alt text](images/photo.jpg ':class=banner-image')
        if (isset($lines[$i]) && preg_match('/^!\[([^\]]*)\]\(([^)\s]+)\s+[\'"][^\'"]*:class=banner-image[^\'"]*[\'"]\)\s*$/', $lines[$i], $found) === 1) {
            $headerImage = $found;
            $headerImage[2] = str_replace('%20', ' ', $headerImage[2]); // spaces in its file name
            $i = $this->skipBlankLines($lines, $i + 1);
        }
        // its credit: <small>Photo: [Name](https://link)</small>, or <small>Photo: Name</small>
        if ($headerImage !== null && isset($lines[$i]) && preg_match('/^<small>Photo: (?:\[(.+?)\]\((.+?)\)|(.+?))<\/small>\s*$/', $lines[$i], $found) === 1) {
            if ($found[1] !== '') {
                $settings['header_image_credit'] = $found[1];
                $settings['header_image_creditlink'] = $found[2];
            } else {
                $settings['header_image_credit'] = $found[3];
            }
            $i = $this->skipBlankLines($lines, $i + 1);
        }
        if (isset($lines[$i]) && str_starts_with($lines[$i], '# ')) {
            $heading = trim(substr($lines[$i], 2));
            if ($heading !== $label) {
                $settings['description'] = $heading;
            }
            $i = $this->skipBlankLines($lines, $i + 1);
        }
        // the description as this bridge writes it: a ## heading kept out of Docsify's sidebar with {docsify-ignore}
        if (!isset($settings['description']) && isset($lines[$i])
            && preg_match('/^## (.+?)\s*<!--\s*\{docsify-ignore\}\s*-->\s*$/', $lines[$i], $found) === 1) {
            $settings['description'] = trim($found[1]);
            $i = $this->skipBlankLines($lines, $i + 1);
        }
        // a Learning Objectives box: > [!TIP] then > **Learning Objectives**, then its lines
        if (isset($lines[$i]) && isset($lines[$i + 1]) && str_starts_with($lines[$i], '> [!') && trim($lines[$i + 1]) === '> **Learning Objectives**') {
            $objectives = [];
            $j = $i + 2;
            while ($j < count($lines) && preg_match('/^>( |$)/', $lines[$j]) === 1) {
                $objectives[] = preg_replace('/^> ?/', '', $lines[$j]);
                $j++;
            }
            $settings['learning_objectives'] = trim(implode("\n", $objectives));
            $i = $this->skipBlankLines($lines, $j);
        }
        // without one on the page, the description is its card's text on the Modules page (already the module's text, so
        // no heading is added for it)
        $descriptionFromPage = isset($settings['description']);
        if (!$descriptionFromPage && ($this->moduleCards[$file][1] ?? '') !== '') {
            $settings['description'] = $this->moduleCards[$file][1];
        }
        // a module not in the sidebar opens from links but isn't listed, as in Docsify (a week not in the sidebar is
        // one still to come: unpublished, as in Open Course Space)
        if (!$isListed && !$isWeek) {
            $settings['visible'] = false;
        }
        if ($isWeek) {
            // a week: an item in the Weekly Units page's folder (week-04/item.md), which shows its first image
            $page = $this->newPage('module', '02.weekly-units/' . $slug, 'item.md', array_merge(['published' => $isListed], $settings));
        } else {
            $page = $this->newPage('module', '02.modules/' . $number . '.' . $slug, 'course-page.md', $settings);
        }
        $body = trim(implode("\n", array_slice($lines, $i)));
        $body = trim(self::withoutCardList($body, array_keys($this->sidebar['modulePages'][$file] ?? [])));
        if ($headerImage !== null) {
            // the header image goes beside the page like its other images; Open Course Space shows it at the top
            $this->addImage($page, $headerImage[2], $file);
            $page->meta = array_merge($page->meta, $isWeek ? ['header_image_file' => basename($headerImage[2])] : ['header_image' => true, 'header_image_file' => basename($headerImage[2])]);
            if ($headerImage[1] !== '') {
                $page->meta['header_image_alt_text'] = $headerImage[1];
            }
        }
        $page->body = $this->toGravText($body, $page, $file);
        if ($isWeek) {
            // a week's summary, above ===, is what the Weekly Units page lists: its first heading (the week's question),
            // as in Open Course Space. The week's own page leaves the summary out, so the full text follows it.
            $summary = '';
            if (preg_match('/^## .+$/m', $page->body, $found) === 1 && str_starts_with(ltrim($page->body), $found[0])) {
                $summary = $found[0];
            }
            $page->body = $summary . "\n\n===\n\n" . $page->body;
        }
        // the page starts with its description, as Open Course Space's own modules do, with the page's sections a level
        // below it (## → ###), so the list of sections beside the page doesn't start with the description
        if ($descriptionFromPage) {
            $page->body = '## ' . $settings['description'] . "\n\n" . self::headingsDown($page->body);
        }
        $page->yaml = $this->yaml($page->meta);
        return $page;
    }

    // Each heading from ## to ##### one level down (## Handouts → ### Handouts), leaving code as it is. Sections that
    // already start at ### (as in the Open Courses Starter Kit) stay as they are, so no heading level is skipped.
    private static function headingsDown(string $markdown): string
    {
        $codeParts = [];
        $markdown = Helpers::hideCode($markdown, $codeParts);
        if (preg_match('/^##\s/m', $markdown) === 1) {
            $markdown = preg_replace('/^(#{2,5})(\s)/m', '#$1$2', $markdown);
        }
        return Helpers::showCode($markdown, $codeParts);
    }

    // A menu page (Schedule, Topics …): its navbar label as its title, and a # heading that repeats it left out (Grav
    // shows the title). A different heading becomes the title, with the label as its menu name.
    private function menuPageFromDocsify(string $file, string $number): Page
    {
        $label = $this->navbar[$file];
        $settings = ['title' => $label];
        $text = trim(str_replace("\r\n", "\n", $this->readFile($file)));
        if (preg_match('/^# (.+)\n?/', $text, $found) === 1) {
            if (trim($found[1]) !== $label) {
                $settings = ['title' => trim($found[1]), 'menu' => $label];
            }
            $text = trim(substr($text, strlen($found[0])));
        }
        $page = $this->newPage('menu', $number . '.' . basename($file, '.md'), 'course-page.md', $settings);
        $page->body = $this->toGravText($text, $page, $file);
        return $page;
    }

    private function sidebarPage(string $text, string $from): Page
    {
        $page = $this->newPage('sidebar', 'sidebar', 'default.md', ['title' => 'Sidebar', 'routable' => false, 'visible' => false]);
        $page->body = $this->toGravText($text, $page, $from);
        return $page;
    }

    // Docsify Markdown → Grav Markdown: callouts, embeds and icons, image and link options, links between the course's
    // pages, and the page's images (copied beside it)
    private function toGravText(string $markdown, Page $page, string $docsifyFile): string
    {
        $codeParts = [];
        $markdown = Helpers::hideCode($markdown, $codeParts);
        $markdown = Callouts::toGrav($markdown);
        $markdown = Embeds::toGrav($markdown);
        $markdown = Embeds::iconsToGrav($markdown);
        $markdown = Embeds::iframesToGrav($markdown);
        $markdown = Embeds::embedlyCardsToGrav($markdown);
        $markdown = Embeds::emailLinksToGrav($markdown);
        $markdown = Links::imageOptionsToGrav($markdown);
        $markdown = Links::mapToGrav($markdown, $this->addresses);
        $markdown = Links::newTabToGrav($markdown);
        $markdown = Helpers::showCode($markdown, $codeParts);
        preg_match_all('/!\[[^\]]*\]\(([^)\s"\'?]+)/', $markdown, $found);
        foreach (array_unique($found[1]) as $source) {
            if (str_contains($source, '://') || str_starts_with($source, '//')) {
                continue;
            }
            if ($this->addImage($page, $source, $docsifyFile)) {
                $markdown = str_replace('](' . $source, '](' . basename($source), $markdown);
            }
        }
        return $markdown;
    }

    // Copies an image of the site beside the page; false (and a note) when it isn't in the site
    private function addImage(Page $page, string $source, string $docsifyFile): bool
    {
        $path = Helpers::imageInside($this->dir, $this->dir . '/' . ltrim($source, './'));
        if ($path === '') {
            $this->notes[] = $docsifyFile . ': image "' . $source . '" wasn\'t found in the site';
            return false;
        }
        $name = basename($source);
        if (!in_array($name, $page->images, true)) {
            $page->images[] = $name;
            $page->imageSources[$name] = $path;
        }
        return true;
    }

    private function newPage(string $kind, string $folder, string $file, array $settings): Page
    {
        $page = new Page($kind, $folder, $file);
        $page->meta = $settings;
        $page->yaml = $this->yaml($settings);
        return $page;
    }

    // ---- both ----

    // _sidebar.md: the modules (a link to a page of the site => its label) and the link groups (a bold or plain
    // heading with links under it, such as the LMS links). Links to other sites and HTML are noted.
    private function parseSidebar(string $text): array
    {
        $sidebar = ['modules' => [], 'groups' => []];
        $group = null;
        foreach (explode("\n", str_replace("\r\n", "\n", $text)) as $line) {
            if (preg_match('/^\s*<(?!!--)[a-z]/i', $line) === 1) {
                $this->addNote('a sidebar has HTML (such as a form or button), which wasn\'t moved');
                continue;
            }
            // a module: - [Week 1](module-01)
            if (preg_match('/^[-*] \[([^\]]+)\]\(([^)\s]+)\)\s*$/', $line, $found) === 1) {
                $file = $this->siteFile($found[2]);
                if ($file === 'home.md') {
                    // the course's home page (a Home item at the top of a course's sidebar), not a module
                    $group = null;
                    continue;
                }
                if ($file !== '') {
                    $sidebar['modules'][$file] = $found[1];
                } else {
                    $this->addNote('the sidebar link to "' . $found[1] . '" isn\'t a page of the course, so it wasn\'t moved');
                }
                $group = null;
            } elseif (preg_match('/^[-*] (?:\*\*)?([^*\[]+?)(?:\*\*)?\s*$/', $line, $found) === 1) {
                // a group's heading: - **LMS Links**
                $sidebar['groups'][] = ['heading' => $found[1], 'links' => []];
                $group = count($sidebar['groups']) - 1;
            } elseif ($group !== null && preg_match('/^\s+[-*] \[([^\]]+)\]\(([^)\s]+)\)\s*$/', $line, $link) === 1
                && $this->isModuleGroup($sidebar['groups'][$group], $link[1], $link[2])) {
                // a module with pages inside it: the group's heading is the module's title, and its first link
                // (Overview) is the module's own page
                if (!isset($sidebar['groups'][$group]['module'])) {
                    $sidebar['groups'][$group]['module'] = $this->siteFile($link[2]);
                    $sidebar['modules'][$this->siteFile($link[2])] = $sidebar['groups'][$group]['heading'];
                } elseif ($this->siteFile($link[2]) !== '') {
                    // the links after it are the module's pages: module file => [page file => sidebar label]
                    $sidebar['modulePages'][$sidebar['groups'][$group]['module']][$this->siteFile($link[2])] = $link[1];
                }
            } elseif ($group !== null && preg_match('/^\s+[-*] (\[.+\]\([^)]+\))\s*$/', $line, $found) === 1) {
                // a link in the group, indented:   - [Calendar](https://…)
                $sidebar['groups'][$group]['links'][] = $found[1];
            }
        }
        // a group without any links isn't kept, nor a module's group (read above)
        $groupsWithLinks = [];
        foreach ($sidebar['groups'] as $group) {
            if (count($group['links']) > 0 && !isset($group['module'])) {
                $groupsWithLinks[] = $group;
            }
        }
        $sidebar['groups'] = $groupsWithLinks;
        return $sidebar;
    }

    // A sidebar group that's a module with pages inside it, as the converter writes one: its first link goes to a page
    // of the course and is labelled "Overview" (or "Module 2 Overview"); the links after it are the module's pages
    private function isModuleGroup(array $group, string $label, string $target): bool
    {
        if (isset($group['module'])) {
            return true; // a later link in a module's group
        }
        $isFirstLink = count($group['links']) === 0;
        $isOverview = preg_match('/(^| )Overview$/', $label) === 1;
        return $isFirstLink && $isOverview && $this->siteFile($target) !== '';
    }

    // _navbar.md: a link to a page of the site => its label (links to other sites are noted)
    private function parseNavbar(string $text): array
    {
        $navbar = [];
        if (preg_match('/^\s*[-*] \[([^\]]+)\]\(home\.md\s+["\']:class=site-title["\']\)/m', $text, $title) === 1) {
            $this->siteTitle = trim($title[1]);
        }
        preg_match_all('/^\s*[-*] \[([^\]]+)\]\(([^)\s]+)\)/m', $text, $found, PREG_SET_ORDER);
        foreach ($found as $match) {
            // in a site of several courses, the way back to the Courses page (home.md at the site's top; #/ before)
            if ($this->top !== '' && in_array($match[2], ['home.md', '#/'], true)) {
                continue;
            }
            $file = $this->siteFile($match[2]);
            if ($file !== '') {
                $navbar[$file] = $match[1];
            } else {
                $this->addNote('the navbar link to "' . $match[1] . '" isn\'t a page of the course, so it wasn\'t moved');
            }
        }
        return $navbar;
    }

    // 'module-01', './module-01.md' or 'module-01.md?id=x' → 'module-01.md' when that page is in the site, else ''
    private function siteFile(string $target): string
    {
        if (preg_match('/^([a-z]+:|\/\/|#)/i', $target) === 1) {
            return '';
        }
        $file = ltrim(preg_replace('/[?#].*$/', '', $target), '/');
        $file = preg_replace('/^\.\//', '', $file);
        if ($file === '' || str_contains($file, '/')) {
            return '';
        }
        if (!str_ends_with($file, '.md')) {
            $file .= '.md';
        }
        if (!is_file($this->dir . '/' . $file)) {
            return '';
        }
        return $file;
    }

    // Pages of the site that weren't converted (pages for the LMS, a cover page …)
    private function noteOtherPages(array $converted): void
    {
        $special = ['_sidebar.md', '_navbar.md', '_footer.md', 'README.md'];
        foreach (glob($this->dir . '/*.md') as $path) {
            $file = basename($path);
            if (in_array($file, $converted, true) || in_array($file, $special, true)) {
                continue;
            }
            // the Modules page the converter makes (its settings are in the sidebar's note)
            if (str_starts_with($this->readFile($file), CourseDocsifyWriter::MODULES_PAGE_MARKER)) {
                continue;
            }
            if (in_array($file, ['_coverpage.md', 'coverpage.md'], true)) {
                $this->addNote($file . ' (Docsify\'s cover page) isn\'t used in Grav, so it wasn\'t moved');
                continue;
            }
            // a Starter Kit's copy of a page for an LMS (lms-schedule.md for schedule.md): Grav embeds the page itself
            $original = substr($file, strlen('lms-'));
            if (str_starts_with($file, 'lms-') && in_array($original, $converted, true)) {
                $this->addNote($file . ' (a copy of ' . $original . ' for an LMS) wasn\'t moved – in Grav, embed that page '
                    . 'itself by adding ?embedded=true to its address');
                continue;
            }
            $this->addNote($file . ' isn\'t in the sidebar or navbar, so it wasn\'t moved');
        }
    }

    private function addNote(string $note): void
    {
        if (!in_array($note, $this->notes, true)) {
            $this->notes[] = $note;
        }
    }

    // A page of the course; in a site of several courses, with its links from the site's top read as links in the
    // course's folder: (cpt363-basic/module-01.md) → (module-01.md)
    private function readFile(string $file): string
    {
        $path = $this->dir . '/' . $file;
        if (!is_file($path)) {
            return '';
        }
        $text = (string)file_get_contents($path);
        if ($this->top !== '') {
            // links to the course's own pages, which are flat files: (/cpt363-basic/module-01.md) → (module-01.md).
            // A longer path, such as a kept link to a page's Markdown version, stays as it is.
            $text = preg_replace('#\]\(/' . preg_quote($this->top, '#') . '([A-Za-z0-9_-]+\.md)#', '](\1', $text);
            // written before: (cpt363-basic/module-01.md)
            $text = str_replace('](' . $this->top, '](', $text);
        }
        return $text;
    }

    // A site of several courses: the Courses page, from the site's home.md (null when it isn't one this converter
    // made). Its text goes back with any edits made in Docsify.
    public static function readCoursesPage(string $dir): ?Page
    {
        $note = Helpers::readGravNote((string)@file_get_contents(rtrim($dir, '/') . '/home.md'));
        if ($note === null || basename($note['where']['file']) !== 'course-list.md') {
            return null;
        }
        $gravFile = $note['where']['file'];
        $page = new Page('courses', dirname($gravFile), basename($gravFile));
        $page->yaml = $note['yaml'];
        $settings = Yaml::parse($note['yaml']);
        if (is_array($settings)) {
            $page->meta = $settings;
        }
        $text = trim(preg_replace('/<!-- grav:gen -->\n.*?\n<!-- \/grav:gen -->/s', '', $note['rest']));
        $codeParts = [];
        $text = Helpers::hideCode($text, $codeParts);
        $text = Callouts::toGrav($text);
        $text = Embeds::toGrav($text);
        $text = Links::newTabToGrav($text);
        $page->body = Helpers::showCode($text, $codeParts);
        foreach (['before' => 'textBefore', 'after' => 'textAfter'] as $name => $property) {
            if (isset($note['where'][$name])) {
                $page->$property = (string)$note['where'][$name];
            }
        }
        return $page;
    }

    private function skipBlankLines(array $lines, int $i): int
    {
        while ($i < count($lines) && trim($lines[$i]) === '') {
            $i++;
        }
        return $i;
    }

    private function yaml(array $settings): string
    {
        return rtrim(Yaml::dump($settings, 4, 4, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK));
    }
}
