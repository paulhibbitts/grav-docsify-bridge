<?php
namespace OPG;

use Symfony\Component\Yaml\Yaml;

// Picks a few converted pages for the page's preview: a Guides page, a guide home, a section, a reading page and a
// menu page (and, for Docsify, a sidebar), each with its Markdown, its settings (for Grav) and its images
class Preview
{
    private const MAX_PAGES = 5;
    // larger images aren't sent with the preview (they're still in the zip)
    private const MAX_IMAGE_BYTES = 2 * 1024 * 1024;

    private string $out;

    // $out: the converted files
    public function __construct(string $out)
    {
        $this->out = rtrim($out, '/');
    }

    // $direction: 'grav' or 'docsify' (what the files were converted to); $isCourse: a course rather than guides;
    // $isCourseSite: several courses, with a Courses page
    public function samplePages(string $direction, bool $isCourse = false, bool $isCourseSite = false): array
    {
        if ($isCourseSite) {
            $paths = $this->courseSiteSamples($direction);
        } elseif ($isCourse) {
            $paths = $this->courseSamples($direction);
        } elseif ($direction === 'grav') {
            $paths = $this->gravSamples();
        } else {
            $paths = $this->docsifySamples();
        }
        $pages = [];
        foreach ($paths as $label => $path) {
            if (count($pages) >= self::MAX_PAGES) {
                break;
            }
            $pages[] = $this->page($label, $path, $direction);
        }
        return $pages;
    }

    // Several courses: the Courses page, then the first course's pages (one module, so its sidebar still fits)
    private function courseSiteSamples(string $direction): array
    {
        $coursesPage = 'home.md';
        if ($direction === 'grav') {
            $coursesPage = $this->firstMatch('pages/*/course-list.md');
        }
        // (in Docsify, each course is in a folder of its own: the first card's, as in <a href="#/cpt363-basic/home">)
        $folder = '';
        if ($direction === 'docsify' && preg_match('/<a href="#\/([A-Za-z0-9_-]+)\/home">/', $this->fileText('home.md'), $found) === 1) {
            $folder = $found[1] . '/';
        }
        $samples = array_merge(['Courses page' => $coursesPage], $this->courseSamples($direction, $folder));
        unset($samples['Module 2']);
        return $samples;
    }

    // A course: its home page, its first two modules, its first menu page and its sidebar
    // $folder: in Docsify, the course's folder when it isn't at the top (its sidebar and navbar links start from the top)
    private function courseSamples(string $direction, string $folder = ''): array
    {
        if ($direction === 'grav') {
            // the course's own folder: pages/01.my-course/…
            $course = dirname($this->firstMatch('pages/*/course.md'));
            $samples = ['Home page' => $course . '/01.home/course-page.md'];
            $modules = glob($this->out . '/' . $course . '/*/*/course-page.md');
            sort($modules, SORT_NATURAL);
            foreach (array_slice($modules, 0, 2) as $index => $module) {
                $samples['Module ' . ($index + 1)] = substr($module, strlen($this->out) + 1);
            }
            $menuPages = glob($this->out . '/' . $course . '/*/course-page.md');
            sort($menuPages, SORT_NATURAL);
            // (the first is the home page)
            if (isset($menuPages[1])) {
                $samples['Menu page'] = substr($menuPages[1], strlen($this->out) + 1);
            }
            $samples['Sidebar'] = $course . '/sidebar/default.md';
        } else {
            $samples = ['Home page' => $folder . 'home.md'];
            // the sidebar's first two modules and the navbar's first page
            $sidebar = $this->fileText($folder . '_sidebar.md');
            preg_match_all('/^- \[[^\]]+\]\(([^)\s]+\.md)\)/m', $sidebar, $links);
            foreach (array_slice($links[1], 0, 2) as $index => $module) {
                $samples['Module ' . ($index + 1)] = $module;
            }
            // (not Home, the link to the course's home page, or a link back to the Courses page)
            $navbar = $this->fileText($folder . '_navbar.md');
            if (preg_match('/\]\((?!([^)\s]*\/)?home\.md\))([^)\s]+\.md)\)/', $navbar, $found) === 1) {
                $samples['Menu page'] = $found[2];
            }
            $samples['Sidebar'] = $folder . '_sidebar.md';
        }
        // only pages that are there
        $found = [];
        foreach ($samples as $label => $path) {
            if ($path !== '' && is_file($this->out . '/' . $path)) {
                $found[$label] = $path;
            }
        }
        return $found;
    }

    // A converted file's text, or '' when there isn't one
    private function fileText(string $path): string
    {
        if (!is_file($this->out . '/' . $path)) {
            return '';
        }
        return (string)file_get_contents($this->out . '/' . $path);
    }

    // In a Grav pages folder: the Guides List page, then the first guide's Section List page, section, page and menu page
    private function gravSamples(): array
    {
        $samples = [];
        // (the newer names guide-list.md and guide.md too)
        $guidesPage = $this->firstMatch('pages/*/reader-list.md') ?: $this->firstMatch('pages/*/guide-list.md');
        if ($guidesPage !== '') {
            $samples['Guides page'] = $guidesPage;
        }
        $guideHome = $this->firstMatch('pages/*/section-list.md') ?: $this->firstMatch('pages/*/guide.md');
        if ($guideHome === '') {
            return $samples;
        }
        $samples['Guide home'] = $guideHome;
        $guideFolder = dirname($guideHome);
        $section = $this->firstMatch($guideFolder . '/*/section.md');
        if ($section !== '') {
            $samples['Section'] = $section;
            $page = $this->firstMatch(dirname($section) . '/*/*.md');
            if ($page !== '') {
                $samples['Page'] = $page;
            }
        }
        $menuPage = $this->firstMatch($guideFolder . '/*/default.md');
        if ($menuPage !== '') {
            $samples['Menu page'] = $menuPage;
        }
        return $samples;
    }

    // In a Docsify site: home.md, the first guide's home, its first section and page (from its sidebar), and the sidebar
    private function docsifySamples(): array
    {
        $samples = ['Home page' => 'home.md'];
        $sidebar = $this->firstMatch('*/_sidebar.md');
        if ($sidebar === '') {
            // a single guide: home.md is the guide's home, and the sidebar is at the top
            $sidebar = '_sidebar.md';
        } else {
            $samples['Guide home'] = dirname($sidebar) . '/README.md';
        }
        if (!is_file($this->out . '/' . $sidebar)) {
            return $samples;
        }
        // the sidebar's first two links: the first section's page (Overview) and its first page
        preg_match_all('/\]\(([^)\s]+\.md)\)/', file_get_contents($this->out . '/' . $sidebar), $links);
        if (isset($links[1][0])) {
            $samples['Section'] = $links[1][0];
        }
        if (isset($links[1][1])) {
            $samples['Page'] = $links[1][1];
        }
        $samples['Sidebar'] = $sidebar;
        return $samples;
    }

    // One page: its path, a tab label, its Markdown (without Grav's front matter), its settings and its images
    private function page(string $label, string $path, string $direction): array
    {
        $text = file_get_contents($this->out . '/' . $path);
        $settings = [];
        $markdown = $text;
        if ($direction === 'grav' && preg_match('/^---\n(.*?)\n---\n?(.*)$/s', $text, $match) === 1) {
            $settings = $this->settingsList($match[1]);
            $markdown = $match[2];
        }
        return [
            'label' => $this->tabLabel($label, $markdown, $settings),
            'path' => $path,
            'markdown' => trim($markdown),
            'source' => $text,
            'settings' => $settings,
            'images' => $this->images($markdown, $settings, dirname($path)),
        ];
    }

    // A tab shows the page's own title, as in the other converters ("Section" or "Guide home" mean little on a
    // site that isn't a guide); the sidebar, and a page without a title, keep their kind of page
    private function tabLabel(string $kind, string $markdown, array $settings): string
    {
        if ($kind === 'Sidebar') {
            return $kind;
        }
        $title = '';
        foreach ($settings as $setting) {
            if ($setting[0] === 'title') {
                $title = $setting[1];
            }
        }
        if ($title === '' && preg_match('/^# (.+)$/m', $markdown, $found) === 1) {
            $title = trim($found[1]);
        }
        if ($title === '') {
            return $kind;
        }
        // long titles are shortened, so the tabs stay on a line or two
        if (mb_strlen($title) > 40) {
            $title = rtrim(mb_substr($title, 0, 38)) . '…';
        }
        return $title;
    }

    // Front matter as [['name', 'value'], …] for display (lists and groups shown in short)
    private function settingsList(string $yaml): array
    {
        $parsed = Yaml::parse($yaml);
        if (!is_array($parsed)) {
            return [];
        }
        $list = [];
        foreach ($parsed as $name => $value) {
            if (is_bool($value)) {
                $value = $value ? 'true' : 'false';
            } elseif (is_array($value)) {
                $value = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }
            $list[] = [(string)$name, trim((string)$value)];
        }
        return $list;
    }

    // The page's images (and a Grav page's image settings) as data: addresses, so the preview can show them
    private function images(string $markdown, array $settings, string $folder): array
    {
        $names = [];
        preg_match_all('/!\[[^\]]*\]\(([^)\s"]+)/', $markdown, $found);
        foreach ($found[1] as $name) {
            $names[] = $name;
        }
        foreach ($settings as $setting) {
            if ($setting[0] === 'image' || $setting[0] === 'cover_image') {
                $names[] = $setting[1];
            }
        }

        $images = [];
        foreach (array_unique($names) as $name) {
            if (str_contains($name, '://')) {
                continue;
            }
            // Docsify reads images from the page's own folder; a Grav page's are beside it
            $path = Helpers::imageInside($this->out, $this->out . '/' . $folder . '/' . $name);
            if ($path === '' || filesize($path) > self::MAX_IMAGE_BYTES) {
                continue;
            }
            $type = $this->imageType($path);
            $images[$name] = 'data:' . $type . ';base64,' . base64_encode(file_get_contents($path));
        }
        return $images;
    }

    private function imageType(string $path): string
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if ($extension === 'jpg' || $extension === 'jpeg') {
            return 'image/jpeg';
        }
        if ($extension === 'svg') {
            return 'image/svg+xml';
        }
        return 'image/' . $extension;
    }

    // The first file matching a pattern (folders in their natural order), from the converted files' folder
    private function firstMatch(string $pattern): string
    {
        $matches = glob($this->out . '/' . $pattern);
        if ($matches === false || count($matches) === 0) {
            return '';
        }
        natsort($matches);
        $first = reset($matches);
        return substr($first, strlen($this->out) + 1);
    }
}
