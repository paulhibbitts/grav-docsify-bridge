<?php
namespace OPG;

use Symfony\Component\Yaml\Yaml;

// Reads a Docsify course back into an Open Course Space course folder. A course this converter made goes back
// unchanged, using its hidden notes (with any edits made in Docsify); a course made in Docsify, laid out like the
// Docsify Open Course Starter Kit, is turned into a new course folder.
class CourseDocsifyReader
{
    // things worth checking, for conversion-notes.txt
    public array $notes = [];

    private string $dir;
    // each Docsify page => its Grav address, for links between pages
    private array $addresses = [];
    // the modules and link groups listed in _sidebar.md, and the pages in _navbar.md
    private array $sidebar = ['modules' => [], 'groups' => []];
    private array $navbar = [];
    // the course's address on the Grav site, e.g. '/cpt363-basic' (its pages' addresses start with it)
    private string $course = '';
    // in a site of several courses, the course's folder, e.g. 'cpt363-basic/': its links start at the site's top
    private string $top = '';
    // the original Grav site's address, kept in the home page's note (links to it go back to Grav's own form)
    private string $siteUrl = '';

    // a course made in Docsify becomes this course folder, with this title (to rename in Grav)
    private const NEW_COURSE_FOLDER = '01.my-course';
    private const NEW_COURSE_TITLE = 'My Course';

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
        // every page's Grav address first, for the links between them
        foreach ($notedPages as $file => $note) {
            $gravFile = $note['where']['file'];
            $folder = dirname($gravFile);
            if ($file === 'home.md') {
                $this->addresses[$file] = $this->course . '/home';
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
        foreach ($notedPages as $file => $note) {
            $gravFile = $note['where']['file'];
            if (in_array(basename($gravFile), ['module.md', 'blog.md'], true)) {
                // the Modules (or Weekly Units) page's note: in _sidebar.md, or in home.md when the course has no home page
                $site->modulesPage = $this->notedPage('modules', $note, '');
                $site->modulesPage->body = $note['where']['body'] ?? '';
                continue;
            }
            if ($file === 'home.md') {
                $site->courseHome = $this->readNotedHome($note, $site);
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
        ksort($modules, SORT_NATURAL);
        $site->modulesPage->children = array_values($modules);
        // menu pages in Grav's order (05.schedule, 06.topics …)
        usort($site->coursePages, function ($a, $b) {
            return strnatcmp($a->folder, $b->folder);
        });
        $this->noteOtherPages(array_keys($notedPages));
        return $site;
    }

    // A module's page is in a folder inside the Modules page's folder: '02.modules/02.module-01/course-page.md', or a
    // week's inside the Weekly Units page's folder: '02.weekly-units/week-01/item.md'
    private static function isModuleFile(string $gravFile): bool
    {
        return in_array(basename($gravFile), ['course-page.md', 'item.md'], true) && substr_count($gravFile, '/') === 2;
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
        $site->courseFolder = self::NEW_COURSE_FOLDER;
        $site->coursePage = $this->newPage('course', '', 'course.md', [
            'title' => self::NEW_COURSE_TITLE, 'published' => true, 'hide_from_menu' => true, 'course_home' => 'home',
            'page-inject' => ['processed_content' => true],
        ]);
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
        // the page's # heading, in the converter's generated block at the top
        if (preg_match('/<!-- grav:gen -->\n.*?^# (.+?)$.*?<!-- \/grav:gen -->/sm', $note['rest'], $found) === 1) {
            $heading = trim($found[1]);
            // the heading shows the description, or the title when there's none
            $hasDescription = $page->setting('description') !== '';
            $shown = $page->title();
            if ($hasDescription) {
                $shown = $page->setting('description');
            }
            if ($heading !== $shown) {
                if ($hasDescription) {
                    $changes['description'] = $heading;
                } elseif (!isset($changes['title'])) {
                    $changes['title'] = $heading;
                }
                $page->body = $this->withNewFirstHeading($page->body, $shown, $heading);
            }
        }
        $this->changeSettings($page, $changes);
        return $page;
    }

    // A first heading that repeated the old text gets the new text: '## **Old**' → '## **New**'
    private function withNewFirstHeading(string $body, string $old, string $new): string
    {
        $lines = explode("\n", $body);
        // the first line with text
        $first = 0;
        while ($first < count($lines) && trim($lines[$first]) === '') {
            $first++;
        }
        if ($first < count($lines) && str_starts_with(ltrim($lines[$first]), '#') && str_contains($lines[$first], $old)) {
            $lines[$first] = str_replace($old, $new, $lines[$first]);
        }
        return implode("\n", $lines);
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
        $home->body = $this->authorText(preg_replace("/\n{3,}/", "\n\n", $ownText), $note);
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
    // before the link: [![Calendar Icon](https://api.iconify.design/fa/calendar.svg?…)Calendar](…) → [fa="calendar"][/fa][Calendar](…)
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
        if (count($unlisted) === 1) {
            $this->notes[] = $unlisted[0] . ' isn\'t in the sidebar, so it\'s a hidden module (it opens from links, but isn\'t listed)';
        } elseif (count($unlisted) > 1) {
            $this->notes[] = implode(', ', $unlisted) . ' aren\'t in the sidebar, so they\'re hidden modules (they open from links, but aren\'t listed)';
        }
        $menuFiles = [];
        foreach ($this->navbar as $file => $label) {
            if ($file !== 'contact.md' && !in_array($file, $moduleFiles, true)) {
                $menuFiles[] = $file;
            }
        }

        $this->newCourse($site);
        $this->course = '/' . Helpers::slugFromFolder($site->courseFolder);
        // every page's Grav address first, for the links between them
        $this->addresses = ['home.md' => $this->course . '/home'];
        foreach ($moduleFiles as $file) {
            $this->addresses[$file] = $this->course . '/modules/' . basename($file, '.md');
        }
        foreach ($menuFiles as $file) {
            $this->addresses[$file] = $this->course . '/' . basename($file, '.md');
        }

        $site->courseHome = $this->homeFromDocsify($site);
        $site->modulesPage = $this->newPage('modules', '02.modules', 'module.md', ['title' => 'Modules', 'published' => true]);
        foreach ($moduleFiles as $index => $file) {
            $isListed = isset($this->sidebar['modules'][$file]);
            $site->modulesPage->children[] = $this->moduleFromDocsify($file, sprintf('%02d', $index + 1), $isListed);
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
        $footer = trim($this->readFile('_footer.md'));
        if ($footer !== '') {
            $site->courseFooter = $this->newPage('footer', 'footer', 'default.md', ['title' => 'Footer', 'routable' => false, 'visible' => false]);
            $site->courseFooter->body = $this->toGravText($footer, $site->courseFooter, '_footer.md');
        }
        $this->noteOtherPages(array_merge(['home.md', 'contact.md'], $moduleFiles, $menuFiles));
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
            if ($this->addImage($site->headerImage, $found[1], 'home.md')) {
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
        $home->body = $this->toGravText(trim($text), $home, 'home.md');
        return $home;
    }

    // A module page: the banner image and its credit become the module's header image, the # heading its description
    // (its title is the sidebar's label), and a Learning Objectives box its learning objectives
    private function moduleFromDocsify(string $file, string $number, bool $isListed): Page
    {
        $slug = basename($file, '.md');
        $label = $this->sidebar['modules'][$file] ?? Helpers::titleFromSlug($slug);
        $settings = ['title' => $label];
        $lines = explode("\n", str_replace("\r\n", "\n", $this->readFile($file)));
        $i = $this->skipBlankLines($lines, 0);
        $headerImage = null;
        // a banner at the top: ![Alt text](images/photo.jpg ':class=banner-image')
        if (isset($lines[$i]) && preg_match('/^!\[([^\]]*)\]\(([^)\s]+)\s+[\'"][^\'"]*:class=banner-image[^\'"]*[\'"]\)\s*$/', $lines[$i], $found) === 1) {
            $headerImage = $found;
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
        // a module not in the sidebar opens from links but isn't listed, as in Docsify
        if (!$isListed) {
            $settings['visible'] = false;
        }
        $page = $this->newPage('module', '02.modules/' . $number . '.' . $slug, 'course-page.md', $settings);
        $body = trim(implode("\n", array_slice($lines, $i)));
        if ($headerImage !== null) {
            // the header image goes beside the page like its other images; Open Course Space shows it at the top
            $this->addImage($page, $headerImage[2], $file);
            $page->meta = array_merge($page->meta, ['header_image' => true, 'header_image_file' => basename($headerImage[2])]);
            if ($headerImage[1] !== '') {
                $page->meta['header_image_alt_text'] = $headerImage[1];
            }
        }
        $page->body = $this->toGravText($body, $page, $file);
        // the page starts with its description, as Open Course Space's own modules do
        if (isset($settings['description'])) {
            $page->body = '## **' . $settings['description'] . "**\n\n" . $page->body;
        }
        $page->yaml = $this->yaml($page->meta);
        return $page;
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
                $this->addNote('a sidebar has HTML (such as a form or button), which wasn\'t converted');
                continue;
            }
            // a module: - [Week 1](module-01)
            if (preg_match('/^[-*] \[([^\]]+)\]\(([^)\s]+)\)\s*$/', $line, $found) === 1) {
                $file = $this->siteFile($found[2]);
                if ($file !== '') {
                    $sidebar['modules'][$file] = $found[1];
                } else {
                    $this->addNote('the sidebar link to "' . $found[1] . '" isn\'t a page of the course, so it wasn\'t converted');
                }
                $group = null;
            } elseif (preg_match('/^[-*] (?:\*\*)?([^*\[]+?)(?:\*\*)?\s*$/', $line, $found) === 1) {
                // a group's heading: - **LMS Links**
                $sidebar['groups'][] = ['heading' => $found[1], 'links' => []];
                $group = count($sidebar['groups']) - 1;
            } elseif ($group !== null && preg_match('/^\s+[-*] (\[.+\]\([^)]+\))\s*$/', $line, $found) === 1) {
                // a link in the group, indented:   - [Calendar](https://…)
                $sidebar['groups'][$group]['links'][] = $found[1];
            }
        }
        // a group without any links isn't kept
        $groupsWithLinks = [];
        foreach ($sidebar['groups'] as $group) {
            if (count($group['links']) > 0) {
                $groupsWithLinks[] = $group;
            }
        }
        $sidebar['groups'] = $groupsWithLinks;
        return $sidebar;
    }

    // _navbar.md: a link to a page of the site => its label (links to other sites are noted)
    private function parseNavbar(string $text): array
    {
        $navbar = [];
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
                $this->addNote('the navbar link to "' . $match[1] . '" isn\'t a page of the course, so it wasn\'t converted');
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
            if (in_array($file, ['_coverpage.md', 'coverpage.md'], true)) {
                $this->addNote($file . ' (Docsify\'s cover page) isn\'t used in Grav, so it wasn\'t converted');
                continue;
            }
            // a Starter Kit's copy of a page for an LMS (lms-schedule.md for schedule.md): Grav embeds the page itself
            $original = substr($file, strlen('lms-'));
            if (str_starts_with($file, 'lms-') && in_array($original, $converted, true)) {
                $this->addNote($file . ' (a copy of ' . $original . ' for an LMS) wasn\'t converted – in Grav, embed that page '
                    . 'itself by adding ?embedded=true to its address');
                continue;
            }
            $this->addNote($file . ' isn\'t in the sidebar or navbar, so it wasn\'t converted');
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
